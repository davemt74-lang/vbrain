<?php
declare(strict_types=1);

final class TripMemoryService
{
    private const SIGNALS = [
        'relaxation'=>'Relaxation','adventure'=>'Adventure','food'=>'Food & dining','nightlife'=>'Nightlife',
        'culture'=>'Culture & history','beach'=>'Beach','pool'=>'Pool','luxury'=>'Luxury','budget_sensitivity'=>'Value / budget',
        'resort_preference'=>'Resorts','city_preference'=>'City energy','romance'=>'Romance','activity_level'=>'Activities',
        'spontaneity'=>'Spontaneity','convenience'=>'Convenience','morning_tolerance'=>'Early mornings',
        'direct_flight_preference'=>'Direct flights',
    ];

    private const SIGNAL_TERMS = [
        'relaxation'=>['relax','relaxed','spa','beach','resort','slow','quiet'],
        'adventure'=>['adventure','hiking','outdoor','excursion','mountain','nature','rafting','diving'],
        'food'=>['food','dining','restaurant','culinary','market','chef'],
        'nightlife'=>['nightlife','night','bars','clubs','social','late','music'],
        'culture'=>['culture','history','historic','museum','architecture','art'],
        'beach'=>['beach','ocean','coast','coastal','island','seaside','tropical'],
        'pool'=>['pool','cabana','resort'],
        'luxury'=>['luxury','premium','upscale','resort','spa'],
        'budget_sensitivity'=>['budget','value','affordable','deal','low-cost'],
        'resort_preference'=>['resort','all-inclusive','spa','pool'],
        'city_preference'=>['city','urban','neighborhood','downtown','nightlife','culture'],
        'romance'=>['romantic','romance','couples','sunset','wine'],
        'activity_level'=>['activity','adventure','hiking','tour','excursion','sports'],
        'spontaneity'=>['flexible','spontaneous','easy','neighborhood','open-ended'],
        'convenience'=>['easy','direct','walkable','convenient','central'],
        'morning_tolerance'=>['sunrise','morning','early'],
        'direct_flight_preference'=>['direct flight','nonstop','non-stop'],
    ];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_memories')
            && db_table_exists('trip_memory_signals')
            && db_table_exists('trip_memory_items');
    }

    public function signalCatalog(): array
    {
        return self::SIGNALS;
    }

    public function snapshot(int $userId,int $tripId): array
    {
        if($userId<1||$tripId<1)throw new InvalidArgumentException('Trip is required.');
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);
        if(!$trip)throw new OutOfBoundsException('Trip not found.');
        $eligible=$this->eligible($trip);
        $stats=$this->tripStats($userId,$trip);
        $memory=$this->memoryRow($userId,$tripId);
        $signals=[];$feedback=[];
        if($this->ready()){
            $stmt=$this->pdo->prepare('SELECT signal_key,signal_value FROM trip_memory_signals WHERE user_id=? AND dream_trip_id=?');
            $stmt->execute([$userId,$tripId]);foreach($stmt->fetchAll()?:[] as $row){$signals[(string)$row['signal_key']]=(int)$row['signal_value'];}
            $stmt=$this->pdo->prepare('SELECT source_kind,source_id,rating,sentiment,note FROM trip_memory_items WHERE user_id=? AND dream_trip_id=?');
            $stmt->execute([$userId,$tripId]);foreach($stmt->fetchAll()?:[] as $row){$feedback[(string)$row['source_kind'].':'.(int)$row['source_id']]=$row;}
        }
        $items=$this->itemCandidates($userId,$trip,$feedback);
        return [
            'ready'=>$this->ready(),'eligible'=>$eligible,'trip'=>$trip,'memory'=>$memory,'stats'=>$stats,'signals'=>$signals,
            'signal_catalog'=>$this->signalCatalog(),'items'=>$items,'learning'=>$this->learningSummary($userId),
            'privacy_note'=>'Private notes stay inside Trip Memory. Only structured ratings and preference signals can influence recommendations when learning is enabled.',
        ];
    }

    public function save(int $userId,int $tripId,array $input,bool $complete=false): array
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade for Post-Trip Memory.');
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);if(!$trip)throw new OutOfBoundsException('Trip not found.');
        if(!$this->eligible($trip))throw new InvalidArgumentException('Trip Memory opens after the trip is completed.');

        $before=$this->memoryRow($userId,$tripId);$stats=$this->tripStats($userId,$trip);
        $overall=$this->rating($input['overall_rating']??null);$value=$this->rating($input['value_rating']??null);
        $pace=in_array((string)($input['pace_fit']??''),['too_slow','just_right','too_packed'],true)?(string)$input['pace_fit']:null;
        $wouldReturn=in_array((string)($input['would_return']??''),['yes','maybe','no'],true)?(string)$input['would_return']:null;
        $actualSpend=$this->money($input['actual_spend']??null);$currency=$this->currency((string)($input['currency']??($trip['currency']??'USD')));
        $summary=$this->clip((string)($input['summary_text']??''),1400);$privateNotes=$this->clip((string)($input['private_notes']??''),8000);
        $favorite=$this->clip((string)($input['favorite_moment']??''),700);$miss=$this->clip((string)($input['biggest_miss']??''),700);
        $learningEnabled=!empty($input['learning_enabled'])?1:0;$status=$complete?'complete':'draft';
        if($complete&&$overall===null)throw new InvalidArgumentException('Add an overall trip rating before completing Trip Memory.');

        $bookedSnapshot=$stats['booked_spend_by_currency'][$currency]??null;
        $this->pdo->beginTransaction();
        try{
            $sql='INSERT INTO trip_memories (user_id,dream_trip_id,destination_catalog_id,status,overall_rating,value_rating,pace_fit,would_return,target_budget_snapshot,booked_spend_snapshot,actual_spend,currency,summary_text,private_notes,favorite_moment,biggest_miss,learning_enabled,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?="complete",NOW(),NULL)) ON DUPLICATE KEY UPDATE destination_catalog_id=VALUES(destination_catalog_id),status=VALUES(status),overall_rating=VALUES(overall_rating),value_rating=VALUES(value_rating),pace_fit=VALUES(pace_fit),would_return=VALUES(would_return),target_budget_snapshot=VALUES(target_budget_snapshot),booked_spend_snapshot=VALUES(booked_spend_snapshot),actual_spend=VALUES(actual_spend),currency=VALUES(currency),summary_text=VALUES(summary_text),private_notes=VALUES(private_notes),favorite_moment=VALUES(favorite_moment),biggest_miss=VALUES(biggest_miss),learning_enabled=VALUES(learning_enabled),completed_at=IF(VALUES(status)="complete",COALESCE(completed_at,NOW()),completed_at),updated_at=NOW()';
            $this->pdo->prepare($sql)->execute([$userId,$tripId,$trip['destination_catalog_id']??null,$status,$overall,$value,$pace,$wouldReturn,$trip['target_budget']??null,$bookedSnapshot,$actualSpend,$currency,$summary?:null,$privateNotes?:null,$favorite?:null,$miss?:null,$learningEnabled,$status]);

            $signalStmt=$this->pdo->prepare('INSERT INTO trip_memory_signals (user_id,dream_trip_id,signal_key,signal_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE signal_value=VALUES(signal_value),updated_at=NOW()');
            foreach(self::SIGNALS as $key=>$label){
                if(!array_key_exists('signal_'.$key,$input))continue;$valueInt=max(-2,min(2,(int)$input['signal_'.$key]));$signalStmt->execute([$userId,$tripId,$key,$valueInt]);
            }

            $candidates=$this->itemCandidates($userId,$trip,[]);$allowed=[];foreach($candidates as $item){$allowed[(string)$item['source_key']]=$item;}
            $sentiments=is_array($input['item_sentiment']??null)?$input['item_sentiment']:[];$ratings=is_array($input['item_rating']??null)?$input['item_rating']:[];$notes=is_array($input['item_note']??null)?$input['item_note']:[];
            $itemStmt=$this->pdo->prepare('INSERT INTO trip_memory_items (user_id,dream_trip_id,source_kind,source_id,item_type,title,rating,sentiment,note) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE item_type=VALUES(item_type),title=VALUES(title),rating=VALUES(rating),sentiment=VALUES(sentiment),note=VALUES(note),updated_at=NOW()');
            foreach($allowed as $key=>$item){
                $sentiment=(string)($sentiments[$key]??'neutral');if(!in_array($sentiment,['favorite','good','neutral','skip'],true))$sentiment='neutral';
                $rating=$this->rating($ratings[$key]??null);$note=$this->clip((string)($notes[$key]??''),500);
                if($sentiment==='neutral'&&$rating===null&&$note==='')continue;
                $itemStmt->execute([$userId,$tripId,$item['source_kind'],$item['source_id'],$item['item_type'],$item['title'],$rating,$sentiment,$note?:null]);
            }

            if($complete){
                if(db_column_exists('dream_trips','operational_state'))$this->pdo->prepare("UPDATE dream_trips SET status='completed',operational_state='completed',travel_mode_completed_at=COALESCE(travel_mode_completed_at,NOW()),updated_at=NOW() WHERE id=? AND user_id=?")->execute([$tripId,$userId]);
                else $this->pdo->prepare("UPDATE dream_trips SET status='completed',updated_at=NOW() WHERE id=? AND user_id=?")->execute([$tripId,$userId]);
                if(($before['status']??'')!=='complete')$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,dream_trip_id,value_numeric,value_text) VALUES (?,"trip_memory_completed",?,?,?)')->execute([$userId,$tripId,$overall,(string)($trip['destination_name']??$trip['name'])]);
            }
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->snapshot($userId,$tripId);
    }

    public function learningSignals(int $userId): array
    {
        if(!$this->ready())return [];
        $stmt=$this->pdo->prepare('SELECT s.signal_key,AVG(s.signal_value) AS avg_value,COUNT(*) AS samples FROM trip_memory_signals s JOIN trip_memories m ON m.user_id=s.user_id AND m.dream_trip_id=s.dream_trip_id WHERE s.user_id=? AND m.status="complete" AND m.learning_enabled=1 GROUP BY s.signal_key');
        $stmt->execute([$userId]);$out=[];foreach($stmt->fetchAll()?:[] as $row){$key=(string)$row['signal_key'];if(!isset(self::SIGNALS[$key]))continue;$out[$key]=['key'=>$key,'label'=>self::SIGNALS[$key],'value'=>(float)$row['avg_value'],'samples'=>(int)$row['samples']];}return $out;
    }

    public function augmentTraits(int $userId,array $traits): array
    {
        foreach($this->learningSignals($userId) as $key=>$signal){
            $delta=max(-15.0,min(15.0,(float)$signal['value']*7.5));
            if(isset($traits[$key])){
                $traits[$key]['score']=max(0,min(100,(float)($traits[$key]['score']??50)+$delta));
                $traits[$key]['confidence']=max((float)($traits[$key]['confidence']??0),min(90,45+($signal['samples']*10)));
                $traits[$key]['trip_learning_delta']=$delta;$traits[$key]['trip_learning_count']=$signal['samples'];
            }else{
                $traits[$key]=['slug'=>$key,'name'=>$signal['label'],'trait_group'=>'post_trip','score'=>max(0,min(100,50+$delta)),'confidence'=>min(90,45+($signal['samples']*10)),'interaction_count'=>$signal['samples'],'trip_learning_delta'=>$delta,'trip_learning_count'=>$signal['samples']];
            }
        }
        return $traits;
    }

    public function rerankDestinations(int $userId,array $rows): array
    {
        $signals=$this->learningSignals($userId);if(!$signals||!$rows)return $rows;
        foreach($rows as &$row){
            $haystack=strtolower(implode(' ',[(string)($row['name']??''),(string)($row['short_description']??''),(string)($row['description']??''),(string)($row['best_for']??''),(string)($row['vibe']??''),(string)($row['prompt_summary']??''),(string)($row['visual_keywords_json']??''),(string)($row['activity_keywords_json']??''),(string)($row['default_vibes_json']??'')]));
            $memoryScore=0.0;$positive=[];$cautions=[];
            foreach($signals as $key=>$signal){$value=(float)$signal['value'];if(abs($value)<0.5)continue;$matches=0;foreach(self::SIGNAL_TERMS[$key]??[] as $term){if($term!==''&&str_contains($haystack,$term))$matches++;}if(!$matches)continue;$delta=$value*(1.0+min(2,$matches)*0.45);$memoryScore+=$delta;if($value>=0.75)$positive[]=$signal['label'];elseif($value<=-0.75)$cautions[]=$signal['label'];}
            $row['_memory_score']=round($memoryScore,3);$row['_recommendation_score']=(float)($row['_recommendation_score']??0)+$memoryScore;
            if($positive)$row['_recommendation_reasons']=array_values(array_unique(array_merge((array)($row['_recommendation_reasons']??[]),array_map(fn($x)=>'Trip memory: '.$x,$positive))));
            if($cautions)$row['_memory_cautions']=array_values(array_unique($cautions));
        }unset($row);
        usort($rows,fn($a,$b)=>((float)($b['_recommendation_score']??0)<=> (float)($a['_recommendation_score']??0))?:((int)($b['featured']??0)<=>(int)($a['featured']??0))?:((int)($a['sort_order']??0)<=>(int)($b['sort_order']??0)));
        return $rows;
    }

    public function learningSummary(int $userId): array
    {
        if(!$this->ready())return ['completed_trips'=>0,'average_rating'=>null,'positive'=>[],'negative'=>[],'favorite_types'=>[]];
        $stmt=$this->pdo->prepare('SELECT COUNT(*) AS total,AVG(overall_rating) AS avg_rating FROM trip_memories WHERE user_id=? AND status="complete"');$stmt->execute([$userId]);$base=$stmt->fetch()?:[];
        $signals=$this->learningSignals($userId);$positive=[];$negative=[];foreach($signals as $signal){if($signal['value']>=0.75)$positive[]=$signal;elseif($signal['value']<=-0.75)$negative[]=$signal;}
        usort($positive,fn($a,$b)=>$b['value']<=>$a['value']);usort($negative,fn($a,$b)=>$a['value']<=>$b['value']);
        $types=$this->pdo->prepare("SELECT item_type,COUNT(*) AS total,AVG(COALESCE(rating,CASE sentiment WHEN 'favorite' THEN 5 WHEN 'good' THEN 4 WHEN 'skip' THEN 1 ELSE 3 END)) AS avg_rating FROM trip_memory_items i JOIN trip_memories m ON m.user_id=i.user_id AND m.dream_trip_id=i.dream_trip_id WHERE i.user_id=? AND m.status='complete' AND m.learning_enabled=1 AND i.sentiment IN ('favorite','good') GROUP BY item_type ORDER BY total DESC,avg_rating DESC LIMIT 5");$types->execute([$userId]);
        return ['completed_trips'=>(int)($base['total']??0),'average_rating'=>$base['avg_rating']!==null?round((float)$base['avg_rating'],1):null,'positive'=>array_slice($positive,0,6),'negative'=>array_slice($negative,0,4),'favorite_types'=>$types->fetchAll()?:[]];
    }

    public function agentContext(int $userId): string
    {
        $summary=$this->learningSummary($userId);if(($summary['completed_trips']??0)<1)return '';
        $likes=array_map(fn($x)=>$x['label'],(array)$summary['positive']);$avoids=array_map(fn($x)=>$x['label'],(array)$summary['negative']);$types=array_map(fn($x)=>ucwords(str_replace('_',' ',(string)$x['item_type'])),(array)$summary['favorite_types']);
        $parts=['Post-trip learning from '.$summary['completed_trips'].' completed trip recap'.($summary['completed_trips']===1?'':'s')];
        if($summary['average_rating']!==null)$parts[]='average trip rating '.$summary['average_rating'].'/5';if($likes)$parts[]='learned preferences: '.implode(', ',$likes);if($avoids)$parts[]='learned avoid/less-of signals: '.implode(', ',$avoids);if($types)$parts[]='favorite experience types: '.implode(', ',$types);
        return implode('; ',$parts).'. Structured preference learning only; private trip notes are excluded.';
    }

    private function memoryRow(int $userId,int $tripId): array
    {
        if(!$this->ready())return ['status'=>'none','overall_rating'=>null,'value_rating'=>null,'pace_fit'=>null,'would_return'=>null,'actual_spend'=>null,'currency'=>'USD','summary_text'=>'','private_notes'=>'','favorite_moment'=>'','biggest_miss'=>'','learning_enabled'=>1];
        $stmt=$this->pdo->prepare('SELECT * FROM trip_memories WHERE user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$userId,$tripId]);return $stmt->fetch()?:['status'=>'none','overall_rating'=>null,'value_rating'=>null,'pace_fit'=>null,'would_return'=>null,'actual_spend'=>null,'currency'=>'USD','summary_text'=>'','private_notes'=>'','favorite_moment'=>'','biggest_miss'=>'','learning_enabled'=>1];
    }

    private function eligible(array $trip): bool
    {
        if(($trip['status']??'')==='completed'||($trip['operational_state']??'')==='completed')return true;$end=trim((string)($trip['end_date']??''));return $end!==''&&$end<date('Y-m-d');
    }

    private function tripStats(int $userId,array $trip): array
    {
        $plannedItems=count(is_array($trip['items']??null)?$trip['items']:[]);$bookings=0;$confirmed=0;$spend=[];
        if(db_table_exists('trip_bookings')){
            $stmt=$this->pdo->prepare("SELECT status,payment_status,amount,currency FROM trip_bookings WHERE user_id=? AND dream_trip_id=?");$stmt->execute([$userId,(int)$trip['id']]);
            foreach($stmt->fetchAll()?:[] as $row){$bookings++;if(in_array((string)$row['status'],['booked','confirmed','changed'],true))$confirmed++;if($row['amount']!==null&&in_array((string)$row['status'],['booked','confirmed','changed'],true)&&(string)$row['payment_status']!=='refunded'){$cur=$this->currency((string)($row['currency']??'USD'));$spend[$cur]=($spend[$cur]??0)+(float)$row['amount'];}}
        }
        foreach($spend as $cur=>$amount)$spend[$cur]=round($amount,2);
        return ['planned_items'=>$plannedItems,'booking_records'=>$bookings,'confirmed_bookings'=>$confirmed,'planned_budget'=>$trip['target_budget']!==null?(float)$trip['target_budget']:null,'booked_spend_by_currency'=>$spend,'days'=>$this->tripDays($trip)];
    }

    private function itemCandidates(int $userId,array $trip,array $feedback): array
    {
        $items=[];foreach(is_array($trip['items']??null)?$trip['items']:[] as $row){$id=(int)($row['id']??0);if($id<1)continue;$key='itinerary:'.$id;$items[]=['source_key'=>$key,'source_kind'=>'itinerary','source_id'=>$id,'item_type'=>(string)($row['item_type']??'idea'),'title'=>(string)($row['title']??'Trip item'),'date'=>$row['scheduled_date']??null,'feedback'=>$feedback[$key]??null];}
        if(db_table_exists('trip_bookings')){$stmt=$this->pdo->prepare("SELECT id,booking_type,title,starts_at,status FROM trip_bookings WHERE user_id=? AND dream_trip_id=? AND status IN ('booked','confirmed','changed') ORDER BY starts_at IS NULL,starts_at,id");$stmt->execute([$userId,(int)$trip['id']]);foreach($stmt->fetchAll()?:[] as $row){$id=(int)$row['id'];$key='booking:'.$id;$items[]=['source_key'=>$key,'source_kind'=>'booking','source_id'=>$id,'item_type'=>(string)$row['booking_type'],'title'=>(string)$row['title'],'date'=>$row['starts_at']??null,'feedback'=>$feedback[$key]??null];}}
        return array_slice($items,0,40);
    }

    private function tripDays(array $trip): ?int
    {
        $start=strtotime((string)($trip['start_date']??''));$end=strtotime((string)($trip['end_date']??''));if(!$start||!$end)return null;return max(1,(int)floor(($end-$start)/86400)+1);
    }

    private function rating(mixed $value): ?int
    {
        if($value===null||$value==='')return null;$n=(int)$value;if($n<1||$n>5)throw new InvalidArgumentException('Ratings must be from 1 to 5.');return $n;
    }

    private function money(mixed $value): ?float
    {
        if($value===null||trim((string)$value)==='')return null;if(!is_numeric($value))throw new InvalidArgumentException('Actual spend must be a number.');$n=(float)$value;if($n<0||$n>99999999)throw new InvalidArgumentException('Actual spend is outside the supported range.');return round($n,2);
    }

    private function currency(string $value): string
    {
        $value=strtoupper(trim($value));return preg_match('/^[A-Z]{3}$/',$value)?$value:'USD';
    }

    private function clip(string $value,int $max): string
    {
        $value=trim($value);if($value==='')return '';return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
