<?php
declare(strict_types=1);

/**
 * Normalizes provider freshness/quality and detects meaningful changes between
 * historical trip-intelligence snapshots. It never calls third-party APIs;
 * TripIntelligenceService remains the single refresh/orchestration path.
 */
final class TripLiveIntelligenceService
{
    private const DATA_TYPES=['weather','flights','lodging','events','places'];

    public function __construct(private PDO $pdo) {}

    public function health(array $dashboard): array
    {
        $providers=is_array($dashboard['providers']??null)?$dashboard['providers']:[];$snapshots=is_array($dashboard['snapshots']??null)?$dashboard['snapshots']:[];$out=[];
        foreach(self::DATA_TYPES as $type){$providerConfig=is_array($providers[$type]??null)?$providers[$type]:[];$snapshot=is_array($snapshots[$type]??null)?$snapshots[$type]:null;$payload=is_array($snapshot['payload']??null)?$snapshot['payload']:[];$configured=(bool)($providerConfig['configured']??($type==='weather'));$source=(string)($payload['provider']??$providerConfig['provider']??ucfirst($type));$sourceStatus=(string)($snapshot['source_status']??($payload['source_status']??''));$observed=(string)($snapshot['observed_at']??$payload['observed_at']??'');$expires=(string)($snapshot['expires_at']??'');$expired=$expires!==''&&strtotime($expires)!==false&&strtotime($expires)<=time();$ok=!empty($payload['ok'])&&$sourceStatus!=='failed';
            if(!$snapshot){$state=$configured?'waiting':'setup';$label=$configured?'Waiting':'Setup needed';}elseif(!$ok){$state=$configured?'error':'setup';$label=$configured?'Refresh failed':'Setup needed';}elseif($expired){$state='stale';$label='Stale';}else{[$state,$label]=$this->deliveryState($type,$payload);}
            $out[$type]=['type'=>$type,'state'=>$state,'label'=>$label,'configured'=>$configured,'provider'=>$source,'provider_note'=>(string)($providerConfig['note']??''),'observed_at'=>$observed,'expires_at'=>$expires,'freshness'=>$this->ageLabel($observed),'expires_in'=>$this->expiryLabel($expires),'age_seconds'=>$this->ageSeconds($observed),'stale'=>$expired,'ok'=>$ok,'error'=>(string)($snapshot['error_message']??$payload['error']??''),'accuracy_note'=>$this->accuracyNote($type,$payload,$providerConfig),'source_status'=>$sourceStatus];
        }
        return $out;
    }

    public function allChanges(int $userId,int $tripId): array{$out=[];foreach(self::DATA_TYPES as $type)$out[$type]=$this->latestChange($userId,$tripId,$type);return $out;}

    public function latestChange(int $userId,int $tripId,string $type): ?array
    {
        if(!in_array($type,self::DATA_TYPES,true)||!db_table_exists('trip_intelligence_snapshots'))return null;$stmt=$this->pdo->prepare('SELECT id,provider,data_type,source_status,observed_at,payload_json FROM trip_intelligence_snapshots WHERE dream_trip_id=? AND user_id=? AND data_type=? AND source_status=\'success\' ORDER BY observed_at DESC,id DESC LIMIT 2');$stmt->execute([$tripId,$userId,$type]);$rows=$stmt->fetchAll()?:[];if(count($rows)<2)return null;$latest=json_decode((string)($rows[0]['payload_json']??''),true)?:[];$previous=json_decode((string)($rows[1]['payload_json']??''),true)?:[];$change=$this->compare($type,$latest,$previous);
        if($change===null)return ['type'=>$type,'material'=>false,'direction'=>'flat','detail'=>'Refreshed with no major change detected.','observed_at'=>(string)$rows[0]['observed_at'],'previous_at'=>(string)$rows[1]['observed_at']];$change['type']=$type;$change['material']=true;$change['observed_at']=(string)$rows[0]['observed_at'];$change['previous_at']=(string)$rows[1]['observed_at'];return $change;
    }

    public function scopedHealth(string $agentType,array $dashboard): array
    {
        $health=$this->health($dashboard);$agentType=strtolower(trim($agentType));$types=match($agentType){'weather'=>['weather'],'flights'=>['flights'],'events'=>['events','weather'],'local'=>['places','weather'],'itinerary'=>['weather','lodging','events','places'],'budget'=>['flights','lodging'],default=>self::DATA_TYPES};return array_intersect_key($health,array_flip($types));
    }

    private function deliveryState(string $type,array $payload): array
    {
        if($type==='flights')return !empty($payload['live_status_count'])?['live','Live']:['indicative','Indicative'];
        if($type==='weather'){$mode=(string)($payload['mode']??'');if($mode==='historical_outlook')return ['historical','Historical'];if($mode==='near_term_only')return ['limited','Near-term only'];}
        return ['live','Live'];
    }

    private function accuracyNote(string $type,array $payload,array $provider): string
    {
        $explicit=trim((string)($payload['accuracy_note']??$payload['note']??''));if($explicit!=='')return $explicit;
        return match($type){'weather'=>'Forecasts are time-sensitive and should be refreshed as the trip approaches.','flights'=>!empty($payload['live_status_count'])?'Booked-flight status is live provider data; confirm critical terminal/gate changes with the airline.':'Indicative fares are planning estimates, not guaranteed bookable prices.','lodging'=>'Live lodging availability and prices can change before checkout; confirm final taxes, policies and room details with the provider.','events'=>'Event schedules can change; use the provider link before making a purchase decision.','places'=>'Ratings and business details reflect the provider snapshot and can change.',default=>(string)($provider['note']??'')};
    }

    private function compare(string $type,array $latest,array $previous): ?array
    {
        if($type==='flights'){
            $statusChange=$this->flightStatusChange((array)($latest['booked_statuses']??[]),(array)($previous['booked_statuses']??[]));if($statusChange)return $statusChange;
            $now=$latest['min_price']??null;$before=$previous['min_price']??null;if(is_numeric($now)&&is_numeric($before)&&abs((float)$now-(float)$before)>=1){$delta=(float)$now-(float)$before;return ['direction'=>$delta<0?'down':'up','detail'=>'Lowest indicative fare '.($delta<0?'dropped ':'rose ').'$'.number_format(abs($delta),0).' to $'.number_format((float)$now,0).'.','value_now'=>(float)$now,'value_before'=>(float)$before];}$directNow=!empty($latest['direct_available']);$directBefore=!empty($previous['direct_available']);if($directNow!==$directBefore)return ['direction'=>$directNow?'up':'down','detail'=>$directNow?'A direct-flight quote now appears in the current snapshot.':'The latest snapshot no longer shows a direct-flight quote.'];return null;
        }
        if($type==='lodging'){$now=$latest['min_price']??null;$before=$previous['min_price']??null;if(is_numeric($now)&&is_numeric($before)&&abs((float)$now-(float)$before)>=10){$delta=(float)$now-(float)$before;return ['direction'=>$delta<0?'down':'up','detail'=>'Lowest live lodging result '.($delta<0?'dropped ':'rose ').(string)($latest['currency']??'USD').' '.number_format(abs($delta),0).' to '.number_format((float)$now,0).'.','value_now'=>(float)$now,'value_before'=>(float)$before];}$nowIds=$this->ids($latest['items']??[]);$beforeIds=$this->ids($previous['items']??[]);$added=array_values(array_diff($nowIds,$beforeIds));$removed=array_values(array_diff($beforeIds,$nowIds));if($added||$removed)return ['direction'=>$added?'up':'down','detail'=>count($added).' lodging result'.(count($added)===1?'':'s').' added and '.count($removed).' removed from current availability.','added'=>count($added),'removed'=>count($removed)];return null;}
        if($type==='events'){$nowIds=$this->ids($latest['items']??[]);$beforeIds=$this->ids($previous['items']??[]);$added=array_values(array_diff($nowIds,$beforeIds));$removed=array_values(array_diff($beforeIds,$nowIds));if($added||$removed)return ['direction'=>$added?'up':'down','detail'=>count($added).' new matching event'.(count($added)===1?'':'s').' and '.count($removed).' no longer in the current result set.','added'=>count($added),'removed'=>count($removed)];return null;}
        if($type==='places'){$nowIds=$this->ids(array_slice((array)($latest['items']??[]),0,10));$beforeIds=$this->ids(array_slice((array)($previous['items']??[]),0,10));$added=array_values(array_diff($nowIds,$beforeIds));if($added)return ['direction'=>'up','detail'=>count($added).' new place'.(count($added)===1?'':'s').' entered the top local results.','added'=>count($added)];return null;}
        if($type==='weather'){$now=$latest['days'][0]??[];$before=$previous['days'][0]??[];$rainNow=$now['precip_probability']??null;$rainBefore=$before['precip_probability']??null;if(is_numeric($rainNow)&&is_numeric($rainBefore)&&abs((float)$rainNow-(float)$rainBefore)>=15){$delta=(float)$rainNow-(float)$rainBefore;return ['direction'=>$delta>0?'risk_up':'risk_down','detail'=>'Near-term rain probability shifted from '.round((float)$rainBefore).'% to '.round((float)$rainNow).'%.','value_now'=>(float)$rainNow,'value_before'=>(float)$rainBefore];}$highNow=$now['high']??null;$highBefore=$before['high']??null;if(is_numeric($highNow)&&is_numeric($highBefore)&&abs((float)$highNow-(float)$highBefore)>=5){$delta=(float)$highNow-(float)$highBefore;return ['direction'=>$delta>0?'warmer':'cooler','detail'=>'Near-term high changed from '.round((float)$highBefore).'° to '.round((float)$highNow).'°.','value_now'=>(float)$highNow,'value_before'=>(float)$highBefore];}}
        return null;
    }

    private function flightStatusChange(array $latest,array $previous): ?array
    {
        $prev=[];foreach($previous as $row)if(is_array($row))$prev[(string)($row['booking_id']??$row['flight_number']??'')]=$row;
        foreach($latest as $row){if(!is_array($row))continue;$key=(string)($row['booking_id']??$row['flight_number']??'');if($key===''||!isset($prev[$key]))continue;$old=$prev[$key];$flight=(string)($row['flight_number']??'Flight');$status=(string)($row['status']??'unknown');$oldStatus=(string)($old['status']??'unknown');if($status!==$oldStatus)return ['direction'=>in_array($status,['cancelled','diverted','incident'],true)?'risk_up':'changed','detail'=>$flight.' status changed from '.ucwords(str_replace('_',' ',$oldStatus)).' to '.ucwords(str_replace('_',' ',$status)).'.','flight_number'=>$flight];
            foreach(['departure','arrival'] as $side){$gate=(string)($row[$side]['gate']??'');$oldGate=(string)($old[$side]['gate']??'');if($gate!==''&&$oldGate!==''&&$gate!==$oldGate)return ['direction'=>'changed','detail'=>$flight.' '.ucfirst($side).' gate changed from '.$oldGate.' to '.$gate.'.','flight_number'=>$flight];$estimated=(string)($row[$side]['estimated']??'');$oldEstimated=(string)($old[$side]['estimated']??'');if($estimated!==''&&$oldEstimated!==''&&$estimated!==$oldEstimated){$a=strtotime($estimated);$b=strtotime($oldEstimated);if($a&&$b&&abs($a-$b)>=900)return ['direction'=>$a>$b?'risk_up':'changed','detail'=>$flight.' '.ucfirst($side).' estimate moved by '.round(abs($a-$b)/60).' minutes.','flight_number'=>$flight];}}
        }
        return null;
    }
    private function ids(array $items): array{$ids=[];foreach($items as $item){if(!is_array($item))continue;$id=trim((string)($item['id']??''));if($id!=='')$ids[]=$id;}return array_values(array_unique($ids));}
    private function ageSeconds(string $value): ?int{if($value==='')return null;$ts=strtotime($value);if($ts===false)return null;return max(0,time()-$ts);}
    private function ageLabel(string $value): string{$seconds=$this->ageSeconds($value);if($seconds===null)return 'No snapshot';if($seconds<60)return 'Just now';if($seconds<3600)return (int)floor($seconds/60).'m old';if($seconds<86400)return (int)floor($seconds/3600).'h old';return (int)floor($seconds/86400).'d old';}
    private function expiryLabel(string $value): string{if($value==='')return 'No expiry';$ts=strtotime($value);if($ts===false)return 'Unknown';$delta=$ts-time();if($delta<=0)return 'Refresh due';if($delta<3600)return 'Fresh for '.max(1,(int)ceil($delta/60)).'m';if($delta<86400)return 'Fresh for '.max(1,(int)ceil($delta/3600)).'h';return 'Fresh for '.max(1,(int)ceil($delta/86400)).'d';}
}
