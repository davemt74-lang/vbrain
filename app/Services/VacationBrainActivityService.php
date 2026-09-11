<?php
declare(strict_types=1);

final class VacationBrainActivityService
{
    private const CHANNELS=['overview','weather','flights','events','local','itinerary','budget'];
    private const SOURCE_WEIGHT=['agent'=>12,'intelligence'=>9,'watch_alert'=>15,'user_event'=>4,'trip'=>6,'destination'=>5];

    public function __construct(private PDO $pdo) {}

    public function snapshot(int $userId,?int $tripId=null): array
    {
        $tripId=$tripId!==null&&$tripId>0?$tripId:null;
        if($tripId!==null&&!$this->ownsTrip($userId,$tripId))throw new RuntimeException('Trip not found.');

        $trips=$this->tripStats($userId,$tripId);
        $destinations=$this->destinationStats($userId);
        $watches=$this->watchStats($userId,$tripId);
        $agents=$this->agentStats($userId,$tripId);
        $intel=$this->intelligenceStats($userId,$tripId);
        $items=$this->itemStats($userId,$tripId);
        $events=$this->userEventStats($userId,$tripId);
        $signals=$this->recentSignals($userId,$tripId);

        $watchSignals=$watches['signals'];
        $channels=[];
        foreach(self::CHANNELS as $channel){
            $agent24=(int)($agents['by_channel'][$channel]['day']??0);
            $agent7=(int)($agents['by_channel'][$channel]['week']??0);
            $intelKey=$channel==='local'?'places':$channel;
            $intel24=(int)($intel['by_type'][$intelKey]['day']??0);
            $intel7=(int)($intel['by_type'][$intelKey]['week']??0);
            $watchCount=match($channel){
                'weather'=>(int)($watchSignals['weather']??0),
                'flights'=>(int)($watchSignals['flights']??0),
                'events'=>(int)($watchSignals['events']??0),
                'local'=>(int)($watchSignals['places']??0),
                default=>0,
            };
            $alertCount=match($channel){
                'weather'=>(int)($watches['alerts_by_type']['weather']??0),
                'flights'=>(int)($watches['alerts_by_type']['flights']??0),
                'events'=>(int)($watches['alerts_by_type']['events']??0),
                'local'=>(int)($watches['alerts_by_type']['places']??0),
                default=>(int)$watches['alerts_day'],
            };
            $persistent=match($channel){
                'overview'=>min(32,(int)$trips['active']*7+(int)$destinations['selected']*3+(int)$watches['active']*5),
                'weather','flights','events','local'=>min(25,$watchCount*6),
                'itinerary'=>min(30,(int)$items['total']*2+(int)$items['scheduled']*3),
                'budget'=>min(30,(int)$trips['with_budget']*8),
                default=>0,
            };
            if($channel==='overview'){
                $score=8+$persistent+min(24,$agent24*7)+min(16,$intel['day_total']*2)+min(16,(int)$watches['alerts_day']*6)+min(10,(int)$events['day']);
            }elseif($channel==='itinerary'){
                $score=5+$persistent+min(35,$agent24*9)+min(15,$agent7*2)+min(10,(int)$events['trip_actions_day']*3);
            }elseif($channel==='budget'){
                $score=4+$persistent+min(38,$agent24*10)+min(12,$agent7*2)+min(15,(int)($intel['by_type']['flights']['day']??0)*3);
            }else{
                $score=4+$persistent+min(34,$agent24*9)+min(28,$intel24*7)+min(20,$alertCount*8)+min(8,$intel7);
            }
            $score=max(0,min(100,(int)$score));
            $last=$this->lastActivityForChannel($channel,$agents,$intel,$watches,$trips,$items);
            $channels[$channel]=[
                'key'=>$channel,
                'label'=>$this->channelLabel($channel),
                'intensity'=>$score,
                'state'=>$this->channelState($score),
                'reason'=>$this->channelReason($channel,$score,$agent24,$intel24,$watchCount,$alertCount,$trips,$items),
                'last_activity'=>$last,
                'url'=>$this->channelUrl($channel,$tripId??$trips['focus_trip_id']),
                'series'=>$this->seriesForChannel($channel,$signals),
                'metrics'=>[
                    'agent_messages_24h'=>$agent24,
                    'provider_refreshes_24h'=>$intel24,
                    'active_watches'=>$watchCount,
                    'watch_alerts_24h'=>$alertCount,
                ],
            ];
        }

        $activityMean=(int)round(array_sum(array_column($channels,'intensity'))/max(1,count($channels)));
        $overall=max(0,min(100,(int)round(
            $activityMean*.62
            +min(18,(int)$trips['active']*4)
            +min(12,(int)$watches['active']*3)
            +min(8,(int)$destinations['selected']*2)
        )));
        if($tripId!==null)$overall=max($overall,(int)round(($channels['overview']['intensity']+$channels['weather']['intensity']+$channels['flights']['intensity']+$channels['events']['intensity']+$channels['local']['intensity']+$channels['itinerary']['intensity']+$channels['budget']['intensity'])/7));

        $overallSeries=[];
        for($i=0;$i<24;$i++){
            $sum=0;foreach($channels as $channel)$sum+=(int)($channel['series'][$i]??0);
            $overallSeries[]=(int)round($sum/count($channels));
        }

        return [
            'score'=>$overall,
            'status'=>$this->overallStatus($overall,$trips),
            'summary'=>$this->summary($overall,$trips,$destinations,$watches,$agents,$intel),
            'scope'=>$tripId!==null?'trip':'account',
            'trip_id'=>$tripId,
            'focus_trip_id'=>$tripId??$trips['focus_trip_id'],
            'updated_at'=>gmdate('c'),
            'series'=>$overallSeries,
            'channels'=>array_values($channels),
            'stats'=>[
                'trips_planned'=>(int)$trips['active'],
                'destinations_selected'=>(int)$destinations['selected'],
                'active_watches'=>(int)$watches['active'],
                'watch_alerts_24h'=>(int)$watches['alerts_day'],
                'agent_actions_24h'=>(int)$agents['day_total'],
                'provider_refreshes_24h'=>(int)$intel['day_total'],
                'itinerary_items'=>(int)$items['total'],
            ],
            'recent_activity'=>array_slice(array_map(fn(array $row)=>$this->publicSignal($row),$signals),0,12),
        ];
    }

    private function tripStats(int $userId,?int $tripId): array
    {
        if(!db_table_exists('dream_trips'))return ['active'=>0,'with_budget'=>0,'focus_trip_id'=>null,'last_at'=>null,'statuses'=>[]];
        $where='user_id=? AND status<>\'abandoned\'';$params=[$userId];if($tripId!==null){$where.=' AND id=?';$params[]=$tripId;}
        $stmt=$this->pdo->prepare("SELECT id,status,target_budget,booking_readiness,updated_at FROM dream_trips WHERE $where ORDER BY updated_at DESC,id DESC");$stmt->execute($params);$rows=$stmt->fetchAll()?:[];$withBudget=0;$statuses=[];
        foreach($rows as $row){if($row['target_budget']!==null)$withBudget++;$status=(string)$row['status'];$statuses[$status]=($statuses[$status]??0)+1;}
        return ['active'=>count($rows),'with_budget'=>$withBudget,'focus_trip_id'=>$rows?(int)$rows[0]['id']:null,'last_at'=>$rows?(string)$rows[0]['updated_at']:null,'statuses'=>$statuses];
    }

    private function destinationStats(int $userId): array
    {
        if(!db_table_exists('dashboard_destination_context'))return ['selected'=>0,'watching_legacy'=>0,'last_at'=>null];
        $stmt=$this->pdo->prepare('SELECT COUNT(*) selected_count,SUM(is_watching=1) watching_count,MAX(updated_at) last_at FROM dashboard_destination_context WHERE user_id=? AND is_selected=1');$stmt->execute([$userId]);$row=$stmt->fetch()?:[];
        return ['selected'=>(int)($row['selected_count']??0),'watching_legacy'=>(int)($row['watching_count']??0),'last_at'=>$row['last_at']??null];
    }

    private function watchStats(int $userId,?int $tripId): array
    {
        $out=['active'=>0,'alerts_day'=>0,'signals'=>['weather'=>0,'flights'=>0,'events'=>0,'places'=>0],'alerts_by_type'=>[],'last_by_type'=>[],'last_at'=>null];
        if(!db_table_exists('travel_watches'))return $out;
        $where='user_id=?';$params=[$userId];if($tripId!==null){$where.=' AND dream_trip_id=?';$params[]=$tripId;}
        $stmt=$this->pdo->prepare("SELECT * FROM travel_watches WHERE $where AND is_active=1");$stmt->execute($params);$rows=$stmt->fetchAll()?:[];$out['active']=count($rows);
        foreach($rows as $row){foreach(['weather','flights','events','places'] as $type){$col='watch_'.$type;if(!empty($row[$col]))$out['signals'][$type]++;}$at=(string)($row['last_checked_at']??'');if($at!==''&&($out['last_at']===null||$at>$out['last_at']))$out['last_at']=$at;}
        if(db_table_exists('travel_watch_events')){
            $sql='SELECT e.data_type,COUNT(*) c,MAX(e.created_at) last_at FROM travel_watch_events e';$eventParams=[$userId];
            if($tripId!==null){$sql.=' JOIN travel_watches w ON w.id=e.watch_id WHERE e.user_id=? AND w.dream_trip_id=? AND e.created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) GROUP BY e.data_type';$eventParams[]=$tripId;}
            else{$sql.=' WHERE e.user_id=? AND e.created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) GROUP BY e.data_type';}
            $stmt=$this->pdo->prepare($sql);$stmt->execute($eventParams);foreach($stmt->fetchAll()?:[] as $row){$type=(string)$row['data_type'];$out['alerts_by_type'][$type]=(int)$row['c'];$out['alerts_day']+=(int)$row['c'];$out['last_by_type'][$type]=(string)$row['last_at'];if($out['last_at']===null||(string)$row['last_at']>$out['last_at'])$out['last_at']=(string)$row['last_at'];}
        }
        return $out;
    }

    private function agentStats(int $userId,?int $tripId): array
    {
        $out=['day_total'=>0,'week_total'=>0,'by_channel'=>[],'last_by_channel'=>[],'last_at'=>null];if(!db_table_exists('trip_agent_messages'))return $out;
        $where='user_id=? AND role=\'assistant\' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)';$params=[$userId];if($tripId!==null){$where.=' AND dream_trip_id=?';$params[]=$tripId;}
        $stmt=$this->pdo->prepare("SELECT agent_type,SUM(created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)) day_count,COUNT(*) week_count,MAX(created_at) last_at FROM trip_agent_messages WHERE $where GROUP BY agent_type");$stmt->execute($params);
        foreach($stmt->fetchAll()?:[] as $row){$channel=$this->normalizeChannel((string)$row['agent_type']);$day=(int)$row['day_count'];$week=(int)$row['week_count'];$out['by_channel'][$channel]=['day'=>$day,'week'=>$week];$out['day_total']+=$day;$out['week_total']+=$week;$out['last_by_channel'][$channel]=(string)$row['last_at'];if($out['last_at']===null||(string)$row['last_at']>$out['last_at'])$out['last_at']=(string)$row['last_at'];}
        return $out;
    }

    private function intelligenceStats(int $userId,?int $tripId): array
    {
        $out=['day_total'=>0,'week_total'=>0,'by_type'=>[],'last_by_type'=>[],'last_at'=>null];if(!db_table_exists('trip_intelligence_snapshots'))return $out;
        $where='user_id=? AND observed_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)';$params=[$userId];if($tripId!==null){$where.=' AND dream_trip_id=?';$params[]=$tripId;}
        $stmt=$this->pdo->prepare("SELECT data_type,SUM(observed_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)) day_count,COUNT(*) week_count,MAX(observed_at) last_at FROM trip_intelligence_snapshots WHERE $where GROUP BY data_type");$stmt->execute($params);
        foreach($stmt->fetchAll()?:[] as $row){$type=(string)$row['data_type'];$day=(int)$row['day_count'];$week=(int)$row['week_count'];$out['by_type'][$type]=['day'=>$day,'week'=>$week];$out['day_total']+=$day;$out['week_total']+=$week;$out['last_by_type'][$type]=(string)$row['last_at'];if($out['last_at']===null||(string)$row['last_at']>$out['last_at'])$out['last_at']=(string)$row['last_at'];}
        return $out;
    }

    private function itemStats(int $userId,?int $tripId): array
    {
        $out=['total'=>0,'scheduled'=>0,'last_at'=>null];if(!db_table_exists('dream_trip_items')||!db_table_exists('dream_trips'))return $out;
        $where='dt.user_id=? AND dt.status<>\'abandoned\'';$params=[$userId];if($tripId!==null){$where.=' AND dt.id=?';$params[]=$tripId;}
        $scheduled=db_column_exists('dream_trip_items','scheduled_date')?'SUM(dti.scheduled_date IS NOT NULL)':'0';$last=db_column_exists('dream_trip_items','updated_at')?'MAX(dti.updated_at)':'MAX(dt.updated_at)';
        $stmt=$this->pdo->prepare("SELECT COUNT(dti.id) total,$scheduled scheduled,$last last_at FROM dream_trips dt LEFT JOIN dream_trip_items dti ON dti.dream_trip_id=dt.id WHERE $where");$stmt->execute($params);$row=$stmt->fetch()?:[];return ['total'=>(int)($row['total']??0),'scheduled'=>(int)($row['scheduled']??0),'last_at'=>$row['last_at']??null];
    }

    private function userEventStats(int $userId,?int $tripId): array
    {
        $out=['day'=>0,'week'=>0,'trip_actions_day'=>0,'last_at'=>null];if(!db_table_exists('user_events')||!db_column_exists('user_events','created_at'))return $out;
        $where='user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)';$params=[$userId];if($tripId!==null&&db_column_exists('user_events','dream_trip_id')){$where.=' AND dream_trip_id=?';$params[]=$tripId;}
        $stmt=$this->pdo->prepare("SELECT SUM(created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)) day_count,COUNT(*) week_count,SUM(created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) AND event_type IN ('dream_trip_created','dream_trip_viewed','dream_item_added')) trip_actions,MAX(created_at) last_at FROM user_events WHERE $where");$stmt->execute($params);$row=$stmt->fetch()?:[];
        return ['day'=>(int)($row['day_count']??0),'week'=>(int)($row['week_count']??0),'trip_actions_day'=>(int)($row['trip_actions']??0),'last_at'=>$row['last_at']??null];
    }

    private function recentSignals(int $userId,?int $tripId): array
    {
        $rows=[];$cutoff=(new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
        if(db_table_exists('trip_agent_messages')){
            $where='user_id=? AND role=\'assistant\' AND created_at>=?';$params=[$userId,$cutoff];if($tripId!==null){$where.=' AND dream_trip_id=?';$params[]=$tripId;}
            $stmt=$this->pdo->prepare("SELECT agent_type channel,created_at at,body detail,dream_trip_id trip_id FROM trip_agent_messages WHERE $where ORDER BY created_at DESC LIMIT 120");$stmt->execute($params);foreach($stmt->fetchAll()?:[] as $row){$channel=$this->normalizeChannel((string)$row['channel']);$rows[]=['channel'=>$channel,'at'=>(string)$row['at'],'kind'=>'agent','weight'=>self::SOURCE_WEIGHT['agent'],'title'=>$this->channelLabel($channel).' worked','detail'=>$this->clip((string)$row['detail'],180),'trip_id'=>(int)$row['trip_id']];}
        }
        if(db_table_exists('trip_intelligence_snapshots')){
            $where='user_id=? AND observed_at>=?';$params=[$userId,$cutoff];if($tripId!==null){$where.=' AND dream_trip_id=?';$params[]=$tripId;}
            $stmt=$this->pdo->prepare("SELECT data_type,provider,source_status,observed_at at,dream_trip_id trip_id FROM trip_intelligence_snapshots WHERE $where ORDER BY observed_at DESC LIMIT 120");$stmt->execute($params);foreach($stmt->fetchAll()?:[] as $row){$channel=$this->normalizeChannel((string)$row['data_type']);$rows[]=['channel'=>$channel,'at'=>(string)$row['at'],'kind'=>'intelligence','weight'=>self::SOURCE_WEIGHT['intelligence'],'title'=>$this->channelLabel($channel).' refreshed','detail'=>trim((string)$row['provider']).' · '.((string)$row['source_status']==='success'?'fresh data':'provider issue'),'trip_id'=>(int)$row['trip_id']];}
        }
        if(db_table_exists('travel_watch_events')){
            $sql='SELECT e.data_type,e.title,e.body,e.created_at at,w.dream_trip_id trip_id FROM travel_watch_events e JOIN travel_watches w ON w.id=e.watch_id WHERE e.user_id=? AND e.created_at>=?';$params=[$userId,$cutoff];if($tripId!==null){$sql.=' AND w.dream_trip_id=?';$params[]=$tripId;}$sql.=' ORDER BY e.created_at DESC LIMIT 80';$stmt=$this->pdo->prepare($sql);$stmt->execute($params);foreach($stmt->fetchAll()?:[] as $row){$channel=$this->normalizeChannel((string)$row['data_type']);$rows[]=['channel'=>$channel,'at'=>(string)$row['at'],'kind'=>'watch_alert','weight'=>self::SOURCE_WEIGHT['watch_alert'],'title'=>(string)$row['title'],'detail'=>$this->clip((string)$row['body'],180),'trip_id'=>(int)($row['trip_id']??0)];}
        }
        if($tripId===null&&db_table_exists('dashboard_destination_context')){$stmt=$this->pdo->prepare('SELECT destination_name,updated_at at FROM dashboard_destination_context WHERE user_id=? AND is_selected=1 AND updated_at>=? ORDER BY updated_at DESC LIMIT 40');$stmt->execute([$userId,$cutoff]);foreach($stmt->fetchAll()?:[] as $row)$rows[]=['channel'=>'overview','at'=>(string)$row['at'],'kind'=>'destination','weight'=>self::SOURCE_WEIGHT['destination'],'title'=>'Destination selected','detail'=>(string)$row['destination_name'],'trip_id'=>0];}
        usort($rows,static fn($a,$b)=>strcmp((string)$b['at'],(string)$a['at']));return $rows;
    }

    private function seriesForChannel(string $channel,array $signals): array
    {
        $now=time();$buckets=array_fill(0,24,0);foreach($signals as $signal){if(($signal['channel']??'')!==$channel&&$channel!=='overview')continue;$ts=strtotime((string)($signal['at']??''));if(!$ts)continue;$age=$now-$ts;if($age<0||$age>=86400)continue;$index=23-(int)floor($age/3600);if($index<0||$index>23)continue;$weight=(int)($signal['weight']??5);if($channel==='overview'&&($signal['channel']??'')!=='overview')$weight=(int)round($weight*.45);$buckets[$index]=min(100,$buckets[$index]+$weight);}
        $carry=0;for($i=0;$i<24;$i++){if($buckets[$i]>0)$carry=max($carry,$buckets[$i]);else$carry=(int)floor($carry*.62);$buckets[$i]=min(100,$buckets[$i]+(int)floor($carry*.22));}return $buckets;
    }

    private function lastActivityForChannel(string $channel,array $agents,array $intel,array $watches,array $trips,array $items): ?string
    {
        $candidates=[];if(!empty($agents['last_by_channel'][$channel]))$candidates[]=$agents['last_by_channel'][$channel];$intelKey=$channel==='local'?'places':$channel;if(!empty($intel['last_by_type'][$intelKey]))$candidates[]=$intel['last_by_type'][$intelKey];$watchKey=$channel==='local'?'places':$channel;if(!empty($watches['last_by_type'][$watchKey]))$candidates[]=$watches['last_by_type'][$watchKey];if($channel==='overview'){foreach([$trips['last_at']??null,$watches['last_at']??null] as $at)if($at)$candidates[]=$at;}if($channel==='itinerary'&&!empty($items['last_at']))$candidates[]=$items['last_at'];if(!$candidates)return null;rsort($candidates);return (string)$candidates[0];
    }

    private function channelReason(string $channel,int $score,int $agent24,int $intel24,int $watchCount,int $alertCount,array $trips,array $items): string
    {
        if($channel==='overview')return $score<20?'Waiting for a trip, destination, watch, or agent task.':(int)$alertCount>0?'Supervising fresh watch alerts and trip activity.':'Coordinating '.(int)$trips['active'].' active trip'.((int)$trips['active']===1?'':'s').' and recent agent work.';
        if($channel==='itinerary')return (int)$items['total']>0?(int)$items['scheduled'].' of '.(int)$items['total'].' saved items are scheduled.':'No itinerary items are scheduled yet.';
        if($channel==='budget')return (int)$trips['with_budget']>0?'Budget targets are attached to active trip planning.':'Add a trip budget to give this agent more to monitor.';
        if($alertCount>0)return $alertCount.' meaningful watch change'.($alertCount===1?'':'s').' detected in the last 24 hours.';
        if($intel24>0)return $intel24.' provider refresh'.($intel24===1?'':'es').' in the last 24 hours.';
        if($agent24>0)return $agent24.' active agent result'.($agent24===1?'':'s').' in the last 24 hours.';
        if($watchCount>0)return 'Standing by on '.$watchCount.' active watch'.($watchCount===1?'':'es').'.';
        return 'Quiet right now — no recent data or agent activity.';
    }

    private function summary(int $score,array $trips,array $destinations,array $watches,array $agents,array $intel): string
    {
        if($score<18)return 'Vacation Brain is quiet. Save a destination or start a trip to wake up the planning agents.';
        $parts=[];if((int)$trips['active']>0)$parts[]=(int)$trips['active'].' active trip'.((int)$trips['active']===1?'':'s');if((int)$watches['active']>0)$parts[]=(int)$watches['active'].' watch'.((int)$watches['active']===1?'':'es');if((int)$agents['day_total']>0)$parts[]=(int)$agents['day_total'].' agent result'.((int)$agents['day_total']===1?'':'s').' today';if((int)$intel['day_total']>0)$parts[]=(int)$intel['day_total'].' live-data refresh'.((int)$intel['day_total']===1?'':'es');if(!$parts&&$destinations['selected'])$parts[]=(int)$destinations['selected'].' selected destination'.((int)$destinations['selected']===1?'':'s');return 'The brain is processing '.implode(', ',$parts).'.';
    }

    private function overallStatus(int $score,array $trips): string
    {
        if(($trips['statuses']['booked']??0)>0&&$score>=55)return 'Trip Locked In';if($score>=86)return 'High Activity';if($score>=68)return 'Planning';if($score>=48)return 'Researching';if($score>=28)return 'Scouting';if($score>=12)return 'Dreaming';return 'Idle';
    }

    private function channelState(int $score): string
    {
        return $score>=75?'working':($score>=45?'active':($score>=18?'listening':'idle'));
    }

    private function channelLabel(string $channel): string
    {
        return match($channel){'overview'=>'Overview','weather'=>'Weather','flights'=>'Flights','events'=>'Events','local'=>'Local','itinerary'=>'Itinerary','budget'=>'Budget',default=>ucfirst($channel)};
    }

    private function normalizeChannel(string $channel): string
    {
        $channel=strtolower(trim($channel));if($channel==='places')return 'local';return in_array($channel,self::CHANNELS,true)?$channel:'overview';
    }

    private function channelUrl(string $channel,?int $tripId): string
    {
        if($tripId&&$tripId>0)return app_url('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($channel));return $channel==='overview'?app_url('dream.php'):app_url('dream.php');
    }

    private function publicSignal(array $row): array
    {
        return ['channel'=>$row['channel'],'kind'=>$row['kind'],'title'=>$row['title'],'detail'=>$row['detail'],'at'=>$row['at'],'url'=>$this->channelUrl((string)$row['channel'],(int)($row['trip_id']??0)?:null)];
    }

    private function ownsTrip(int $userId,int $tripId): bool
    {
        if(!db_table_exists('dream_trips'))return false;$stmt=$this->pdo->prepare('SELECT 1 FROM dream_trips WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$tripId,$userId]);return (bool)$stmt->fetchColumn();
    }

    private function clip(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
