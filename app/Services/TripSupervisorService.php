<?php
declare(strict_types=1);

final class TripSupervisorService
{
    public function __construct(private PDO $pdo) {}

    public function overview(int $userId,int $tripId,array $dashboard): array
    {
        return [
            'updates'=>$this->updates($userId,$tripId),
            'suggestions'=>$this->suggestions($dashboard),
        ];
    }

    public function activeResult(string $agentType,array $dashboard): array
    {
        $agentType=strtolower(trim($agentType));$trip=$dashboard['trip']??[];$snap=$dashboard['snapshots']??[];$weather=$snap['weather']['payload']??[];$flights=$snap['flights']['payload']??[];$events=$snap['events']['payload']??[];$places=$snap['places']['payload']??[];$budget=$dashboard['budget']??[];
        if($agentType==='weather'){
            if(empty($weather['ok']))return ['title'=>'Weather Agent is waiting for data','body'=>(string)($weather['error']??'Refresh weather intelligence to activate this agent.'),'status'=>'waiting'];$day=$weather['days'][0]??[];$parts=[];if(isset($day['high'])&&$day['high']!==null)$parts[]='high '.round((float)$day['high']).'°';if(isset($day['precip_probability'])&&$day['precip_probability']!==null)$parts[]='rain '.round((float)$day['precip_probability']).'%';if(!empty($day['conditions']))$parts[]=(string)$day['conditions'];$history=$weather['history']??[];$historyText=!empty($history['available'])?' Historical average high: '.round((float)($history['avg_high']??0)).'°.':'';return ['title'=>'Weather Agent is active','body'=>(string)($weather['resolved_address']??$trip['destination_name']??'Destination').' · '.implode(' · ',$parts).'.'.$historyText,'status'=>'active'];
        }
        if($agentType==='flights'){
            if(empty($flights['ok']))return ['title'=>'Flights Agent is waiting for partner data','body'=>(string)($flights['error']??'Add route codes and connect Skyscanner partner access.'),'status'=>'waiting'];return ['title'=>'Flights Agent is watching the route','body'=>(string)($flights['origin_iata']??'').' → '.(string)($flights['destination_iata']??'').' · lowest indicative fare '.($flights['min_price']!==null?'$'.number_format((float)$flights['min_price'],0):'not available').' · '.(int)($flights['quote_count']??0).' cached quotes.','status'=>'active'];
        }
        if($agentType==='events'){
            if(empty($events['ok']))return ['title'=>'Events Agent is waiting for data','body'=>(string)($events['error']??'Connect Ticketmaster Discovery and refresh this tab.'),'status'=>'waiting'];$first=$events['items'][0]??null;return ['title'=>'Events Agent is active','body'=>(int)($events['count']??count($events['items']??[])).' matching events in the current trip window'.($first?' · Next strong result: '.(string)$first['name'].' on '.(string)$first['date']:'.'),'status'=>'active'];
        }
        if($agentType==='local'){
            if(empty($places['ok']))return ['title'=>'Local Agent is waiting for data','body'=>(string)($places['error']??'Connect Google Places and refresh this tab.'),'status'=>'waiting'];$first=$places['items'][0]??null;return ['title'=>'Local Agent is active','body'=>count($places['items']??[]).' current restaurants, bars and attractions'.($first?' · Top current result: '.(string)$first['name'].(($first['rating']??null)!==null?' '.number_format((float)$first['rating'],1).'★':''):'.'),'status'=>'active'];
        }
        if($agentType==='itinerary'){$items=$trip['items']??[];$scheduled=0;foreach($items as $item)if(!empty($item['scheduled_date']))$scheduled++;return ['title'=>'Itinerary Agent is active','body'=>count($items).' saved trip items · '.$scheduled.' scheduled · '.(count($items)-$scheduled).' still flexible.','status'=>'active'];}
        if($agentType==='budget'){$target=$budget['target']??null;$projected=(float)($budget['projected']??0);$body='Projected trip cost $'.number_format($projected,0);if($target!==null)$body.=' against a $'.number_format((float)$target,0).' target · '.(($budget['remaining']??0)>=0?'$'.number_format((float)$budget['remaining'],0).' remaining':'$'.number_format(abs((float)$budget['remaining']),0).' over target');return ['title'=>'Budget Agent is active','body'=>$body.'.','status'=>'active'];}
        $overview=$this->overview((int)($trip['user_id']??0),(int)($trip['id']??0),$dashboard);$top=$overview['suggestions'][0]??null;return ['title'=>'Overview Agent is supervising the trip','body'=>$top?((string)$top['title'].' — '.(string)$top['body']):'All current trip data is being tracked. Refresh intelligence to surface new booking and planning opportunities.','status'=>'active'];
    }

    public function recordProactive(int $userId,int $tripId,array $dashboard): void
    {
        if(!db_table_exists('trip_agent_messages'))return;
        $overview=$this->overview($userId,$tripId,$dashboard);$suggestions=$overview['suggestions'];if(!$suggestions)return;
        $snapshotIds=[];foreach(($dashboard['snapshots']??[]) as $snapshot)if(is_array($snapshot)&&!empty($snapshot['id']))$snapshotIds[]=(int)$snapshot['id'];sort($snapshotIds);
        $fingerprint=hash('sha256',json_encode(['snapshots'=>$snapshotIds,'suggestions'=>array_column($suggestions,'key')],JSON_UNESCAPED_SLASHES));
        $lines=[];foreach(array_slice($suggestions,0,4) as $s)$lines[]='• '.$s['title'].' — '.$s['body'];$body="Trip update\n".implode("\n",$lines);
        $stmt=$this->pdo->prepare('INSERT IGNORE INTO trip_agent_messages (dream_trip_id,user_id,agent_type,role,body,fingerprint,metadata_json) VALUES (?,?,\'overview\',\'assistant\',?,?,?)');
        $stmt->execute([$tripId,$userId,$body,$fingerprint,json_encode(['kind'=>'proactive','suggestions'=>$suggestions,'snapshot_ids'=>$snapshotIds],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }

    private function updates(int $userId,int $tripId): array
    {
        if(!db_table_exists('trip_intelligence_snapshots'))return [];
        $stmt=$this->pdo->prepare('SELECT id,provider,data_type,source_status,error_message,observed_at,payload_json FROM trip_intelligence_snapshots WHERE dream_trip_id=? AND user_id=? ORDER BY observed_at DESC,id DESC LIMIT 24');$stmt->execute([$tripId,$userId]);$rows=$stmt->fetchAll()?:[];$byType=[];
        foreach($rows as $row){$type=(string)$row['data_type'];$row['payload']=json_decode((string)($row['payload_json']??''),true)?:[];unset($row['payload_json']);$byType[$type][]=$row;}
        $out=[];
        foreach(['weather','flights','events','places'] as $type){$items=$byType[$type]??[];if(!$items)continue;$latest=$items[0];$previous=$items[1]??null;$detail=$this->changeDetail($type,$latest['payload'],$previous['payload']??null);$out[]=['type'=>$type,'provider'=>(string)$latest['provider'],'status'=>(string)$latest['source_status'],'at'=>(string)$latest['observed_at'],'detail'=>$detail?:((string)$latest['source_status']==='success'?'Fresh data received.':((string)$latest['error_message']?:'Provider refresh failed.'))];}
        usort($out,static fn($a,$b)=>strcmp((string)$b['at'],(string)$a['at']));return $out;
    }

    private function changeDetail(string $type,array $latest,?array $previous): string
    {
        if(!$previous)return 'First '.$type.' snapshot saved for this trip.';
        if($type==='flights'){$now=$latest['min_price']??null;$before=$previous['min_price']??null;if(is_numeric($now)&&is_numeric($before)&&abs((float)$now-(float)$before)>=1){$delta=(float)$now-(float)$before;return 'Lowest indicative fare '.($delta<0?'dropped ':'rose ').'$'.number_format(abs($delta),0).' to $'.number_format((float)$now,0).'.';}}
        if($type==='events'){$now=(int)($latest['count']??count($latest['items']??[]));$before=(int)($previous['count']??count($previous['items']??[]));if($now!==$before)return 'Matching event count changed from '.$before.' to '.$now.'.';}
        if($type==='places'){$now=(int)($latest['count']??count($latest['items']??[]));$before=(int)($previous['count']??count($previous['items']??[]));if($now!==$before)return 'Local place results changed from '.$before.' to '.$now.'.';}
        if($type==='weather'){$a=$latest['days'][0]??[];$b=$previous['days'][0]??[];$rainA=$a['precip_probability']??null;$rainB=$b['precip_probability']??null;$highA=$a['high']??null;$highB=$b['high']??null;if(is_numeric($rainA)&&is_numeric($rainB)&&abs((float)$rainA-(float)$rainB)>=15)return 'Near-term rain probability shifted from '.round((float)$rainB).'% to '.round((float)$rainA).'%. ';if(is_numeric($highA)&&is_numeric($highB)&&abs((float)$highA-(float)$highB)>=5)return 'Near-term high changed from '.round((float)$highB).'° to '.round((float)$highA).'°.';}
        return 'Refreshed with no major change detected.';
    }

    private function suggestions(array $dashboard): array
    {
        $trip=$dashboard['trip']??[];$weather=$dashboard['snapshots']['weather']['payload']??[];$flights=$dashboard['snapshots']['flights']['payload']??[];$events=$dashboard['snapshots']['events']['payload']??[];$places=$dashboard['snapshots']['places']['payload']??[];$budget=$dashboard['budget']??[];$opportunities=$dashboard['opportunities']??[];$out=[];
        $start=(string)($trip['start_date']??'');$daysAway=null;if($start!==''){$ts=strtotime($start);if($ts)$daysAway=(int)floor(($ts-strtotime('today'))/86400);}
        if($start===''||empty($trip['end_date']))$out[]=$this->s('dates','Set real travel dates','Weather, events and flight pricing become much more useful once departure and return dates are set.','planning',94,'overview');
        if(trim((string)($trip['origin_name']??''))===''&&trim((string)($trip['origin_iata']??''))==='')$out[]=$this->s('origin','Add your starting city','Flight intelligence needs an origin before Vacation Brain can watch airfare.','flight',90,'flights');
        if(!empty($weather['alerts'][0])){$alert=$weather['alerts'][0];$out[]=$this->s('weather-alert','Recheck the outdoor plan',(string)($alert['event']??'A weather alert').' is active for the destination.','weather',99,'weather');}
        if($daysAway!==null&&$daysAway>=0&&$daysAway<=45){if(!empty($flights['ok'])&&is_numeric($flights['min_price']??null))$out[]=$this->s('fare-window','Flight decision window is active','Your trip is '.$daysAway.' days away and indicative fares currently start around $'.number_format((float)$flights['min_price'],0).' per traveler.','booking',96,'flights');else $out[]=$this->s('flight-search','Get flight pricing into the trip','You are '.$daysAway.' days out. Add airport codes or connect flight data so the Flights Agent can track the route.','flight',91,'flights');}
        if(!empty($opportunities[0])){$o=$opportunities[0];$out[]=$this->s('best-opportunity','Use the strongest current opportunity',(string)$o['title'].': '.(string)$o['reason'],'itinerary',88,(string)($o['kind']==='event'?'events':($o['kind']==='place'?'local':'weather')));}
        if(!empty($events['items'][0])){$e=$events['items'][0];$out[]=$this->s('event-fit','A live event can anchor the itinerary',(string)($e['name']??'A local event').' is showing for '.(string)($e['date']??'your travel window').'. Review it before inventory or dates change.','event',83,'events');}
        if(!empty($places['items'][0])){$p=$places['items'][0];$rating=$p['rating']??null;$out[]=$this->s('local-pick','Save a strong local option',(string)($p['name']??'A local place').($rating!==null?' is currently rated '.number_format((float)$rating,1).'★.':'.'),'local',78,'local');}
        if(($budget['target']??null)!==null&&($budget['remaining']??0)<0)$out[]=$this->s('budget-over','The trip is over target','Current saved costs and indicative airfare are about $'.number_format(abs((float)$budget['remaining']),0).' over the target budget.','budget',95,'budget');
        $hasHotel=false;foreach(($trip['items']??[]) as $item)if(($item['item_type']??'')==='hotel'){$hasHotel=true;break;}if(!$hasHotel&&$start!=='')$out[]=$this->s('lodging-partner','Lodging is still open','No stay is attached to the trip yet. Keep this slot open for a lodging partner offer matched to your dates and budget.','partner',72,'overview');
        usort($out,static fn($a,$b)=>$b['priority']<=>$a['priority']);return array_slice($out,0,8);
    }

    private function s(string $key,string $title,string $body,string $kind,int $priority,string $targetTab): array
    {
        return compact('key','title','body','kind','priority','targetTab');
    }
}
