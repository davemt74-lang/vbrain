<?php
declare(strict_types=1);

final class TripAgentService
{
    private const AGENTS=['overview','weather','flights','events','local','itinerary','budget'];
    private TripLiveIntelligenceService $live;

    public function __construct(private PDO $pdo)
    {
        $this->live=new TripLiveIntelligenceService($pdo);
    }

    public function history(int $userId,int $tripId,string $agentType,int $limit=30): array
    {
        $agentType=$this->agent($agentType);if(!db_table_exists('trip_agent_messages'))return [];$this->assertTrip($userId,$tripId);$limit=max(1,min(100,$limit));$stmt=$this->pdo->prepare("SELECT id,agent_type,role,body,metadata_json,created_at FROM trip_agent_messages WHERE user_id=? AND dream_trip_id=? AND agent_type=? ORDER BY id DESC LIMIT $limit");$stmt->execute([$userId,$tripId,$agentType]);$rows=array_reverse($stmt->fetchAll()?:[]);foreach($rows as &$row){$row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];unset($row['metadata_json']);}unset($row);return $rows;
    }

    public function send(int $userId,int $tripId,string $agentType,string $message,?string $idempotencyKey=null): array
    {
        $agentType=$this->agent($agentType);$message=trim($message);if($message==='')throw new InvalidArgumentException('Ask the trip agent something first.');if(!db_table_exists('trip_agent_messages'))throw new RuntimeException('Run System Upgrade before using trip agents.');$trip=$this->assertTrip($userId,$tripId);
        $userFingerprint=null;$assistantFingerprint=null;
        if($idempotencyKey!==null&&trim($idempotencyKey)!==''){
            $base=hash('sha256',trim($idempotencyKey));$userFingerprint=hash('sha256',$base.'|user');$assistantFingerprint=hash('sha256',$base.'|assistant');
            $existing=$this->byFingerprint($userId,$tripId,$agentType,$assistantFingerprint);
            if($existing){$meta=json_decode((string)($existing['metadata_json']??''),true)?:[];return ['agent_type'=>$agentType,'message'=>(string)$existing['body'],'data_health'=>$meta['data_health']??[],'reused'=>true];}
        }
        $dashboard=(new TripIntelligenceService($this->pdo))->dashboard($userId,$tripId);
        $this->insert($userId,$tripId,$agentType,'user',$message,$userFingerprint,['kind'=>'user','job_key'=>$idempotencyKey]);$system=$this->systemPrompt($agentType,$dashboard);$context=$this->datasetContext($agentType,$dashboard);$recent=$this->history($userId,$tripId,$agentType,12);$transcript=[];foreach($recent as $row){if(($row['metadata']['kind']??'')==='proactive')continue;$transcript[]=strtoupper((string)$row['role']).': '.(string)$row['body'];}$input="TRIP DATA\n".$context."\n\nRECENT ".strtoupper($agentType)." AGENT CONVERSATION\n".implode("\n",array_slice($transcript,-10))."\n\nUSER REQUEST\n".$message;
        $reply=(new AiProviderService($this->pdo))->generateText($system,$input,$userId,'trip_agent_'.$agentType,1100);if(!$reply)$reply=$this->fallback($agentType,$dashboard);
        $health=$this->live->scopedHealth($agentType,$dashboard);$this->insert($userId,$tripId,$agentType,'assistant',$reply,$assistantFingerprint,['kind'=>'agent_result','data_scope'=>$this->scope($agentType),'data_health'=>$health,'job_key'=>$idempotencyKey]);return ['agent_type'=>$agentType,'message'=>$reply,'data_health'=>$health,'reused'=>false];
    }

    public function recordProactive(int $userId,int $tripId,array $dashboard): void
    {
        (new TripSupervisorService($this->pdo))->recordProactive($userId,$tripId,$dashboard);
    }

    public function label(string $agentType): string
    {
        return match($this->agent($agentType)){'overview'=>'Overview Agent','weather'=>'Weather Agent','flights'=>'Flights Agent','events'=>'Events Agent','local'=>'Local Agent','itinerary'=>'Itinerary Agent','budget'=>'Budget Agent'};
    }

    public function scope(string $agentType): array
    {
        return match($this->agent($agentType)){
            'overview'=>['weather','flights','events','places','itinerary','budget','updates','freshness'],
            'weather'=>['weather','freshness','changes'],
            'flights'=>['flights','freshness','changes'],
            'events'=>['events','weather_fit','freshness','changes'],
            'local'=>['places','weather_fit','freshness','changes'],
            'itinerary'=>['itinerary','weather','saved_events','saved_places','freshness'],
            'budget'=>['budget','flights','saved_items','freshness','changes'],
        };
    }

    private function systemPrompt(string $agentType,array $dashboard): string
    {
        $label=$this->label($agentType);$scope=implode(', ',$this->scope($agentType));return "You are Vacation Brain's $label for one specific planned trip. Your allowed data scope is: $scope. Be concise, practical and proactive. Use the supplied trip/provider data as facts. Never invent live weather, fares, events, ratings, availability, prices or booking inventory. For every time-sensitive recommendation, respect the supplied provider, freshness state, expiry state and latest-change signal. If data is stale, historical, indicative, limited, unavailable or unconfigured, say that plainly before giving advice. Skyscanner indicative fares are cached market intelligence and are not guaranteed bookable prices. Lodging is a paid-partnership surface: do not fabricate hotel inventory. Suggest concrete next actions and scheduling improvements when supported by the data. The Overview Agent may reason across all supplied datasets and prioritize booking/planning actions. Specialist agents should stay focused on their named dataset except for weather-fit context explicitly supplied to Events/Local/Itinerary.";
    }

    private function datasetContext(string $agentType,array $dashboard): string
    {
        $trip=$dashboard['trip']??[];$base=['trip'=>['id'=>$trip['id']??null,'name'=>$trip['name']??'','destination'=>$trip['destination_name']??'','origin'=>$trip['origin_name']??'','origin_iata'=>$trip['origin_iata']??'','destination_iata'=>$trip['destination_iata']??'','start_date'=>$trip['start_date']??null,'end_date'=>$trip['end_date']??null,'travelers'=>$trip['travelers']??1,'target_budget'=>$trip['target_budget']??null,'status'=>$trip['status']??'','booking_readiness'=>$trip['booking_readiness']??0]];$snap=$dashboard['snapshots']??[];$data=$base;$userId=(int)($trip['user_id']??0);$tripId=(int)($trip['id']??0);
        $data['live_status']=$this->live->scopedHealth($agentType,$dashboard);
        $data['latest_changes']=$userId>0&&$tripId>0?$this->scopedChanges($agentType,$this->live->allChanges($userId,$tripId)):[];
        if($agentType==='overview'){$super=(new TripSupervisorService($this->pdo))->overview((int)$trip['user_id'],(int)$trip['id'],$dashboard);$data+=['weather'=>$this->payload($snap,'weather'),'flights'=>$this->payload($snap,'flights'),'events'=>$this->payload($snap,'events'),'places'=>$this->payload($snap,'places'),'opportunities'=>$dashboard['opportunities']??[],'budget'=>$dashboard['budget']??[],'itinerary'=>$this->compactItems($trip['items']??[]),'updates'=>$super['updates'],'proactive_suggestions'=>$super['suggestions'],'agent_states'=>$super['agent_states']??[]];}
        elseif($agentType==='weather')$data['weather']=$this->payload($snap,'weather');
        elseif($agentType==='flights')$data['flights']=$this->payload($snap,'flights');
        elseif($agentType==='events'){$data['events']=$this->payload($snap,'events');$data['weather_fit']=$this->weatherFit($this->payload($snap,'weather'));}
        elseif($agentType==='local'){$data['places']=$this->payload($snap,'places');$data['weather_fit']=$this->weatherFit($this->payload($snap,'weather'));}
        elseif($agentType==='itinerary'){$data['itinerary']=$this->compactItems($trip['items']??[]);$data['weather']=$this->payload($snap,'weather');$data['events']=$this->payload($snap,'events');$data['places']=$this->payload($snap,'places');}
        elseif($agentType==='budget'){$data['budget']=$dashboard['budget']??[];$data['flights']=$this->payload($snap,'flights');$data['saved_items']=$this->compactItems($trip['items']??[]);}
        $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return $json!==false?$this->clip($json,36000):'{}';
    }

    private function fallback(string $agentType,array $dashboard): string
    {
        $result=(new TripSupervisorService($this->pdo))->activeResult($agentType,$dashboard);$body=trim((string)($result['body']??''));$next=trim((string)($result['next_action']??''));$source=trim((string)($result['source']??''));$fresh=trim((string)($result['freshness']??''));$parts=[];if($body!=='')$parts[]=$body;if($source!==''&&$source!=='Vacation Brain')$parts[]='Source: '.$source.($fresh!==''?' · '.$fresh:'').'.';if($next!=='')$parts[]='Next move: '.$next;$parts[]='The configured LLM is unavailable right now, but the trip intelligence and planning calculations above are still active.';return implode(' ',$parts);
    }

    private function scopedChanges(string $agentType,array $changes): array
    {
        $types=match($agentType){'weather'=>['weather'],'flights'=>['flights'],'events'=>['events','weather'],'local'=>['places','weather'],'itinerary'=>['weather','events','places'],'budget'=>['flights'],default=>['weather','flights','events','places']};return array_intersect_key($changes,array_flip($types));
    }

    private function assertTrip(int $userId,int $tripId): array
    {
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);if(!$trip)throw new RuntimeException('Trip not found.');return $trip;
    }

    private function byFingerprint(int $userId,int $tripId,string $agentType,string $fingerprint): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM trip_agent_messages WHERE user_id=? AND dream_trip_id=? AND agent_type=? AND fingerprint=? LIMIT 1');$stmt->execute([$userId,$tripId,$agentType,$fingerprint]);return $stmt->fetch()?:null;
    }

    private function insert(int $userId,int $tripId,string $agentType,string $role,string $body,?string $fingerprint,array $metadata): void
    {
        $verb=$fingerprint!==null?'INSERT IGNORE':'INSERT';$stmt=$this->pdo->prepare($verb.' INTO trip_agent_messages (dream_trip_id,user_id,agent_type,role,body,fingerprint,metadata_json) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$tripId,$userId,$agentType,$role,$this->clip($body,12000),$fingerprint,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }

    private function agent(string $value): string
    {
        $value=strtolower(trim($value));return in_array($value,self::AGENTS,true)?$value:'overview';
    }

    private function payload(array $snapshots,string $type): array
    {
        $p=$snapshots[$type]['payload']??[];return is_array($p)?$p:[];
    }

    private function compactItems(array $items): array
    {
        $out=[];foreach(array_slice($items,0,80) as $item)$out[]=['id'=>$item['id']??null,'type'=>$item['item_type']??'','title'=>$item['title']??'','price'=>$item['price']??null,'date'=>$item['scheduled_date']??null,'daypart'=>$item['daypart']??null,'notes'=>$item['notes']??''];return $out;
    }

    private function weatherFit(array $weather): array
    {
        $out=[];foreach(array_slice($weather['days']??[],0,15) as $d)$out[]=['date'=>$d['date']??'','high'=>$d['high']??null,'low'=>$d['low']??null,'rain'=>$d['precip_probability']??null,'conditions'=>$d['conditions']??'','wind'=>$d['wind']??null];return $out;
    }

    private function clip(string $value,int $max): string
    {
        return function_exists('mb_substr')?mb_substr(trim($value),0,$max):substr(trim($value),0,$max);
    }
}
