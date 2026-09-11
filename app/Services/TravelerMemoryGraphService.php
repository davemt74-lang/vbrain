<?php
declare(strict_types=1);

final class TravelerMemoryGraphService
{
    private const TERMS=[
        'relaxation'=>['relax','spa','beach','resort','slow','quiet'],'adventure'=>['adventure','hiking','outdoor','excursion','mountain','nature'],
        'food'=>['food','dining','restaurant','culinary','market'],'nightlife'=>['nightlife','night','bars','clubs','late','music'],
        'culture'=>['culture','history','museum','architecture','art'],'beach'=>['beach','ocean','coast','island','seaside','tropical'],
        'pool'=>['pool','cabana','resort'],'luxury'=>['luxury','premium','upscale','resort','spa'],'budget_sensitivity'=>['budget','value','affordable','deal'],
        'resort_preference'=>['resort','all-inclusive','spa','pool'],'city_preference'=>['city','urban','neighborhood','downtown','nightlife','culture'],
        'romance'=>['romantic','romance','sunset','wine'],'activity_level'=>['activity','adventure','hiking','tour','excursion'],
        'spontaneity'=>['flexible','spontaneous','easy','open-ended'],'convenience'=>['easy','direct','walkable','convenient','central'],
        'morning_tolerance'=>['sunrise','morning','early'],'direct_flight_preference'=>['direct flight','nonstop','non-stop'],
    ];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        $memory=new TripMemoryService($this->pdo);
        return $memory->ready() && db_table_exists('traveler_preference_controls');
    }

    public function snapshot(int $userId): array
    {
        $history=$this->history($userId,100);$raw=$this->rawSignals($userId);$controls=$this->controlMap($userId);$effective=$this->filterSignals($raw,$controls);
        return [
            'ready'=>$this->ready(),'history'=>$history,'destinations'=>$this->destinations($history),'spending'=>$this->spending($history),
            'dna'=>$this->dna($userId,$raw,$controls),'timeline'=>$this->timeline($userId,$controls),'controls'=>$controls,
            'summary'=>$this->summaryFrom($history,$effective),'privacy_note'=>'Traveler Memory uses completed Trip Memory ratings and structured preference signals. Private trip notes are never included in the travel graph, recommendations, or agent context.',
        ];
    }

    public function dashboardSummary(int $userId): array
    {
        if(!$this->ready())return ['ready'=>false,'completed_trips'=>0,'signals'=>[],'recent'=>null,'spending'=>[]];
        $history=$this->history($userId,3);$signals=$this->filterSignals($this->rawSignals($userId),$this->controlMap($userId));
        uasort($signals,fn($a,$b)=>abs((float)$b['value'])<=>abs((float)$a['value']));
        return ['ready'=>true,'completed_trips'=>$this->completedCount($userId),'signals'=>array_slice(array_values($signals),0,4),'recent'=>$history[0]??null,'spending'=>$this->spending($history)];
    }

    public function setSignalControl(int $userId,string $signalKey,string $state,string $note=''): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade for Traveler Memory.');
        $catalog=(new TripMemoryService($this->pdo))->signalCatalog();if(!isset($catalog[$signalKey]))throw new InvalidArgumentException('Unknown traveler preference signal.');
        if(!in_array($state,['learn','ignore'],true))throw new InvalidArgumentException('Unknown learning state.');
        $note=trim($note);if(strlen($note)>500)$note=substr($note,0,500);
        $sql='INSERT INTO traveler_preference_controls (user_id,signal_key,learning_state,correction_note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE learning_state=VALUES(learning_state),correction_note=VALUES(correction_note),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([$userId,$signalKey,$state,$note!==''?$note:null]);
        $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_text) VALUES (?,"traveler_memory_correction",?)')->execute([$userId,$signalKey.':'.$state]);
    }

    public function setTripLearning(int $userId,int $tripId,bool $enabled): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade for Traveler Memory.');
        $q=$this->pdo->prepare('SELECT id FROM trip_memories WHERE user_id=? AND dream_trip_id=? LIMIT 1');$q->execute([$userId,$tripId]);if(!$q->fetchColumn())throw new OutOfBoundsException('Trip Memory not found.');
        $this->pdo->prepare('UPDATE trip_memories SET learning_enabled=?,updated_at=NOW() WHERE user_id=? AND dream_trip_id=?')->execute([$enabled?1:0,$userId,$tripId]);
        $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,dream_trip_id,value_numeric) VALUES (?,"trip_memory_learning_changed",?,?)')->execute([$userId,$tripId,$enabled?1:0]);
    }

    public function augmentTraits(int $userId,array $traits): array
    {
        foreach($this->filterSignals($this->rawSignals($userId),$this->controlMap($userId)) as $key=>$s){
            $delta=max(-15,min(15,(float)$s['value']*7.5));
            if(isset($traits[$key])){$traits[$key]['score']=max(0,min(100,(float)($traits[$key]['score']??50)+$delta));$traits[$key]['confidence']=max((float)($traits[$key]['confidence']??0),$this->signalConfidence($s));}
            else $traits[$key]=['slug'=>$key,'name'=>$s['label'],'trait_group'=>'post_trip','score'=>max(0,min(100,50+$delta)),'confidence'=>$this->signalConfidence($s),'interaction_count'=>$s['samples']];
            $traits[$key]['trip_learning_delta']=$delta;$traits[$key]['trip_learning_count']=$s['samples'];
        }
        return $traits;
    }

    public function rerankDestinations(int $userId,array $rows): array
    {
        $signals=$this->filterSignals($this->rawSignals($userId),$this->controlMap($userId));if(!$signals||!$rows)return $rows;
        foreach($rows as &$r){$hay=strtolower(implode(' ',[(string)($r['name']??''),(string)($r['short_description']??''),(string)($r['description']??''),(string)($r['best_for']??''),(string)($r['vibe']??''),(string)($r['prompt_summary']??''),(string)($r['visual_keywords_json']??''),(string)($r['activity_keywords_json']??'')]));$memoryScore=0;$why=[];$cautions=[];
            foreach($signals as $key=>$s){$v=(float)$s['value'];if(abs($v)<.5)continue;$hits=0;foreach(self::TERMS[$key]??[] as $term)if(str_contains($hay,$term))$hits++;if(!$hits)continue;$memoryScore+=$v*(1+min(2,$hits)*.45);if($v>=.75)$why[]='Trip memory: '.$s['label'];elseif($v<=-.75)$cautions[]=$s['label'];}
            $r['_memory_score']=round($memoryScore,3);$r['_recommendation_score']=(float)($r['_recommendation_score']??0)+$memoryScore;$r['_recommendation_reasons']=array_values(array_unique(array_merge((array)($r['_recommendation_reasons']??[]),$why)));if($cautions)$r['_memory_cautions']=array_values(array_unique($cautions));
        }unset($r);usort($rows,fn($a,$b)=>((float)($b['_recommendation_score']??0)<=>(float)($a['_recommendation_score']??0))?:((int)($b['featured']??0)<=>(int)($a['featured']??0)));return $rows;
    }

    public function nextTripSeed(int $userId,int $tripId): array
    {
        $memory=$this->historyRow($userId,$tripId);if(!$memory)throw new OutOfBoundsException('Completed Trip Memory not found.');
        $controls=$this->controlMap($userId);$q=$this->pdo->prepare('SELECT signal_key,signal_value FROM trip_memory_signals WHERE user_id=? AND dream_trip_id=? ORDER BY ABS(signal_value) DESC,signal_key');$q->execute([$userId,$tripId]);$catalog=(new TripMemoryService($this->pdo))->signalCatalog();$keep=[];$change=[];
        foreach($q->fetchAll()?:[] as $row){$key=(string)$row['signal_key'];if(($controls[$key]['state']??'learn')==='ignore')continue;$v=(int)$row['signal_value'];if($v>=1)$keep[]=$catalog[$key]??$key;elseif($v<=-1)$change[]=$catalog[$key]??$key;}
        $parts=['Based on your '.$memory['destination'].' trip.'];if($keep)$parts[]='Keep more of: '.implode(', ',array_slice($keep,0,5)).'.';if($change)$parts[]='Do less of: '.implode(', ',array_slice($change,0,4)).'.';if(!empty($memory['pace_fit']))$parts[]='Previous pace: '.str_replace('_',' ',(string)$memory['pace_fit']).'.';
        return ['destination'=>$memory['destination'],'name'=>$memory['destination'].' — Better This Time','travelers'=>$memory['travelers'],'target_budget'=>$memory['actual_spend']??$memory['booked_spend_snapshot']??$memory['target_budget_snapshot'],'currency'=>$memory['currency'],'description'=>implode(' ',$parts),'source_trip_id'=>$tripId];
    }

    public function agentContext(int $userId,int $limit=5): string
    {
        if(!$this->ready())return '';$history=array_values(array_filter($this->history($userId,$limit),fn($h)=>(int)$h['learning_enabled']===1));if(!$history)return '';
        $signals=$this->filterSignals($this->rawSignals($userId),$this->controlMap($userId));uasort($signals,fn($a,$b)=>abs((float)$b['value'])<=>abs((float)$a['value']));$learned=[];foreach(array_slice($signals,0,6,true) as $s)$learned[]=$s['label'].' '.($s['value']>0?'+':'').round((float)$s['value'],1);
        $trips=[];foreach($history as $h){$line=$h['destination'].' rated '.($h['overall_rating']??'?').'/5';if($h['would_return'])$line.=', return '.$h['would_return'];if($h['favorite_moment'])$line.=', favorite: '.$h['favorite_moment'];if($h['biggest_miss'])$line.=', less of: '.$h['biggest_miss'];$trips[]=$line;}
        return 'Traveler Memory: '.count($history).' recent learning-enabled completed trip'.(count($history)===1?'':'s').' — '.implode(' | ',$trips).'. Learned structured signals: '.implode(', ',$learned).'. Private trip notes are excluded.';
    }

    private function history(int $userId,int $limit): array
    {
        if(!(new TripMemoryService($this->pdo))->ready())return [];$limit=max(1,min(100,$limit));
        $sql='SELECT m.*,dt.name trip_name,dt.start_date,dt.end_date,dt.travelers,dt.metadata_json,dt.destination_catalog_id,d.name catalog_name FROM trip_memories m JOIN dream_trips dt ON dt.id=m.dream_trip_id AND dt.user_id=m.user_id LEFT JOIN destination_catalog d ON d.id=dt.destination_catalog_id WHERE m.user_id=? AND m.status="complete" ORDER BY COALESCE(m.completed_at,dt.end_date,dt.updated_at) DESC,m.id DESC LIMIT '.$limit;
        $q=$this->pdo->prepare($sql);$q->execute([$userId]);$out=[];foreach($q->fetchAll()?:[] as $row)$out[]=$this->publicHistoryRow($row);return $out;
    }

    private function historyRow(int $userId,int $tripId): ?array
    {
        foreach($this->history($userId,100) as $row)if((int)$row['trip_id']===$tripId)return $row;return null;
    }

    private function publicHistoryRow(array $row): array
    {
        $meta=json_decode((string)($row['metadata_json']??''),true)?:[];$destination=trim((string)($row['catalog_name']??''));if($destination==='')$destination=trim((string)($meta['destination_name']??''));if($destination==='')$destination=(string)$row['trip_name'];
        return ['memory_id'=>(int)$row['id'],'trip_id'=>(int)$row['dream_trip_id'],'trip_name'=>(string)$row['trip_name'],'destination'=>$destination,'start_date'=>$row['start_date']??null,'end_date'=>$row['end_date']??null,'travelers'=>(int)($row['travelers']??1),'overall_rating'=>$row['overall_rating']!==null?(int)$row['overall_rating']:null,'value_rating'=>$row['value_rating']!==null?(int)$row['value_rating']:null,'pace_fit'=>$row['pace_fit']??null,'would_return'=>$row['would_return']??null,'target_budget_snapshot'=>$row['target_budget_snapshot']!==null?(float)$row['target_budget_snapshot']:null,'booked_spend_snapshot'=>$row['booked_spend_snapshot']!==null?(float)$row['booked_spend_snapshot']:null,'actual_spend'=>$row['actual_spend']!==null?(float)$row['actual_spend']:null,'currency'=>(string)($row['currency']??'USD'),'summary_text'=>(string)($row['summary_text']??''),'favorite_moment'=>(string)($row['favorite_moment']??''),'biggest_miss'=>(string)($row['biggest_miss']??''),'learning_enabled'=>(int)($row['learning_enabled']??0),'completed_at'=>$row['completed_at']??null,'memory_url'=>app_url('trip-memory.php?id='.(int)$row['dream_trip_id']),'repeat_url'=>app_url('dream-new.php?memory_id='.(int)$row['dream_trip_id'])];
    }

    private function rawSignals(int $userId): array
    {
        if(!(new TripMemoryService($this->pdo))->ready())return [];$catalog=(new TripMemoryService($this->pdo))->signalCatalog();$q=$this->pdo->prepare('SELECT s.signal_key,AVG(s.signal_value) avg_value,COUNT(*) samples,MIN(s.signal_value) min_value,MAX(s.signal_value) max_value FROM trip_memory_signals s JOIN trip_memories m ON m.user_id=s.user_id AND m.dream_trip_id=s.dream_trip_id WHERE s.user_id=? AND m.status="complete" AND m.learning_enabled=1 GROUP BY s.signal_key');$q->execute([$userId]);$out=[];foreach($q->fetchAll()?:[] as $r){$key=(string)$r['signal_key'];if(!isset($catalog[$key]))continue;$out[$key]=['key'=>$key,'label'=>$catalog[$key],'value'=>(float)$r['avg_value'],'samples'=>(int)$r['samples'],'min'=>(int)$r['min_value'],'max'=>(int)$r['max_value']];}return $out;
    }

    private function controlMap(int $userId): array
    {
        if(!db_table_exists('traveler_preference_controls'))return [];$q=$this->pdo->prepare('SELECT signal_key,learning_state,correction_note,updated_at FROM traveler_preference_controls WHERE user_id=?');$q->execute([$userId]);$out=[];foreach($q->fetchAll()?:[] as $r)$out[(string)$r['signal_key']]=['state'=>(string)$r['learning_state'],'note'=>(string)($r['correction_note']??''),'updated_at'=>$r['updated_at']??null];return $out;
    }

    private function filterSignals(array $signals,array $controls): array
    {
        foreach($signals as $key=>$signal)if(($controls[$key]['state']??'learn')==='ignore')unset($signals[$key]);return $signals;
    }

    private function dna(int $userId,array $raw,array $controls): array
    {
        $profile=(new VacationProfileService($this->pdo))->snapshot($userId);$traits=$profile['traits']??[];$catalog=(new TripMemoryService($this->pdo))->signalCatalog();$effective=$this->augmentTraits($userId,$traits);$keys=array_values(array_unique(array_merge(array_keys($traits),array_keys($raw))));$rows=[];
        foreach($keys as $key){if(!isset($catalog[$key])&&!isset($traits[$key]))continue;$learn=$raw[$key]??null;$ignored=($controls[$key]['state']??'learn')==='ignore';$diagnosis=$traits[$key]??null;$learnedValue=$learn?(float)$learn['value']:null;$rows[]=['key'=>$key,'label'=>$catalog[$key]??(string)($diagnosis['name']??ucwords(str_replace('_',' ',$key))),'diagnosis_score'=>$diagnosis!==null?(float)($diagnosis['score']??50):null,'effective_score'=>(float)($effective[$key]['score']??($diagnosis['score']??50)),'learned_value'=>$learnedValue,'samples'=>(int)($learn['samples']??0),'confidence'=>max((float)($diagnosis['confidence']??0),$learn?$this->signalConfidence($learn):0),'provenance'=>$learn&&$diagnosis?'Diagnosis + completed trips':($learn?'Completed trips':'Diagnosis'),'ignored'=>$ignored,'correction_note'=>$controls[$key]['note']??''];}
        usort($rows,fn($a,$b)=>(int)$b['samples']<=>(int)$a['samples']?:$b['confidence']<=>$a['confidence']);return $rows;
    }

    private function timeline(int $userId,array $controls): array
    {
        if(!(new TripMemoryService($this->pdo))->ready())return [];$catalog=(new TripMemoryService($this->pdo))->signalCatalog();$q=$this->pdo->prepare('SELECT s.dream_trip_id,s.signal_key,s.signal_value,m.completed_at,dt.name trip_name,dt.metadata_json,d.name catalog_name FROM trip_memory_signals s JOIN trip_memories m ON m.user_id=s.user_id AND m.dream_trip_id=s.dream_trip_id JOIN dream_trips dt ON dt.id=s.dream_trip_id LEFT JOIN destination_catalog d ON d.id=dt.destination_catalog_id WHERE s.user_id=? AND m.status="complete" ORDER BY COALESCE(m.completed_at,dt.end_date,dt.updated_at) DESC,ABS(s.signal_value) DESC,s.id DESC');$q->execute([$userId]);$group=[];
        foreach($q->fetchAll()?:[] as $r){$key=(string)$r['signal_key'];if(($controls[$key]['state']??'learn')==='ignore')continue;$trip=(int)$r['dream_trip_id'];if(!isset($group[$trip])){$meta=json_decode((string)($r['metadata_json']??''),true)?:[];$dest=trim((string)($r['catalog_name']??''))?:trim((string)($meta['destination_name']??''))?:$r['trip_name'];$group[$trip]=['trip_id'=>$trip,'destination'=>$dest,'completed_at'=>$r['completed_at'],'signals'=>[]];}if(count($group[$trip]['signals'])<5)$group[$trip]['signals'][]=['key'=>$key,'label'=>$catalog[$key]??$key,'value'=>(int)$r['signal_value']];}
        return array_slice(array_values($group),0,20);
    }

    private function destinations(array $history): array
    {
        $out=[];foreach($history as $h){$key=strtolower(trim($h['destination']));if($key==='')continue;if(!isset($out[$key]))$out[$key]=['destination'=>$h['destination'],'visits'=>0,'ratings'=>[],'return_yes'=>0,'return_maybe'=>0,'return_no'=>0,'latest_completed'=>$h['completed_at'],'trip_ids'=>[]];$out[$key]['visits']++;$out[$key]['trip_ids'][]=$h['trip_id'];if($h['overall_rating']!==null)$out[$key]['ratings'][]=$h['overall_rating'];$r=(string)($h['would_return']??'');if($r==='yes')$out[$key]['return_yes']++;elseif($r==='maybe')$out[$key]['return_maybe']++;elseif($r==='no')$out[$key]['return_no']++;}
        foreach($out as &$d){$d['average_rating']=$d['ratings']?round(array_sum($d['ratings'])/count($d['ratings']),1):null;unset($d['ratings']);$d['return_label']=$d['return_yes']>0?'Would return':($d['return_maybe']>0?'Maybe return':($d['return_no']>0?'Probably not again':'No return signal'));}unset($d);usort($out,fn($a,$b)=>$b['visits']<=>$a['visits']?:($b['average_rating']??0)<=>($a['average_rating']??0));return array_values($out);
    }

    private function spending(array $history): array
    {
        $out=[];foreach($history as $h){$c=$h['currency']?:'USD';if(!isset($out[$c]))$out[$c]=['currency'=>$c,'trips'=>0,'target_total'=>0.0,'booked_total'=>0.0,'actual_total'=>0.0,'actual_known'=>0];$out[$c]['trips']++;if($h['target_budget_snapshot']!==null)$out[$c]['target_total']+=$h['target_budget_snapshot'];if($h['booked_spend_snapshot']!==null)$out[$c]['booked_total']+=$h['booked_spend_snapshot'];if($h['actual_spend']!==null){$out[$c]['actual_total']+=$h['actual_spend'];$out[$c]['actual_known']++;}}
        foreach($out as &$r){$r['variance_to_target']=$r['actual_known']>0?$r['actual_total']-$r['target_total']:null;$r['average_actual']=$r['actual_known']>0?$r['actual_total']/$r['actual_known']:null;}unset($r);return array_values($out);
    }

    private function summaryFrom(array $history,array $signals): array
    {
        uasort($signals,fn($a,$b)=>abs((float)$b['value'])<=>abs((float)$a['value']));$likes=[];$less=[];foreach($signals as $s){if($s['value']>=.75)$likes[]=$s;elseif($s['value']<=-.75)$less[]=$s;}return ['completed_trips'=>count($history),'average_rating'=>$history?round(array_sum(array_map(fn($h)=>(float)($h['overall_rating']??0),array_filter($history,fn($h)=>$h['overall_rating']!==null)))/max(1,count(array_filter($history,fn($h)=>$h['overall_rating']!==null))),1):null,'likes'=>array_slice($likes,0,6),'less_of'=>array_slice($less,0,4)];
    }

    private function completedCount(int $userId): int{$q=$this->pdo->prepare('SELECT COUNT(*) FROM trip_memories WHERE user_id=? AND status="complete"');$q->execute([$userId]);return (int)$q->fetchColumn();}
    private function signalConfidence(array $s): float{return min(95,45+((int)($s['samples']??0)*12)+(abs((float)($s['value']??0))*8));}
}
