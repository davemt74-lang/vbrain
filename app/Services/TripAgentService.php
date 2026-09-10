<?php
declare(strict_types=1);

final class TripAgentService
{
    private const AGENTS=['overview','weather','flights','events','local','itinerary','budget'];

    public function __construct(private PDO $pdo) {}

    public function history(int $userId,int $tripId,string $agentType,int $limit=30): array
    {
        $agentType=$this->agent($agentType);if(!db_table_exists('trip_agent_messages'))return [];$this->assertTrip($userId,$tripId);$limit=max(1,min(100,$limit));$stmt=$this->pdo->prepare("SELECT id,agent_type,role,body,metadata_json,created_at FROM trip_agent_messages WHERE user_id=? AND dream_trip_id=? AND agent_type=? ORDER BY id DESC LIMIT $limit");$stmt->execute([$userId,$tripId,$agentType]);$rows=array_reverse($stmt->fetchAll()?:[]);foreach($rows as &$row){$row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];unset($row['metadata_json']);}unset($row);return $rows;
    }

    public function send(int $userId,int $tripId,string $agentType,string $message): array
    {
        $agentType=$this->agent($agentType);$message=trim($message);if($message==='')throw new InvalidArgumentException('Ask the trip agent something first.');if(!db_table_exists('trip_agent_messages'))throw new RuntimeException('Run System Upgrade before using trip agents.');$trip=$this->assertTrip($userId,$tripId);$dashboard=(new TripIntelligenceService($this->pdo))->dashboard($userId,$tripId);
        $this->insert($userId,$tripId,$agentType,'user',$message,null,['kind'=>'user']);$system=$this->systemPrompt($agentType,$dashboard);$context=$this->datasetContext($agentType,$dashboard);$recent=$this->history($userId,$tripId,$agentType,12);$transcript=[];foreach($recent as $row){if(($row['metadata']['kind']??'')==='proactive')continue;$transcript[]=strtoupper((string)$row['role']).': '.(string)$row['body'];}$input="TRIP DATA\n".$context."\n\nRECENT ".strtoupper($agentType)." AGENT CONVERSATION\n".implode("\n",array_slice($transcript,-10))."\n\nUSER REQUEST\n".$message;
        $reply=(new AiProviderService($this->pdo))->generateText($system,$input,$userId,'trip_agent_'.$agentType,1100);if(!$reply)$reply=$this->fallback($agentType,$dashboard);
        $this->insert($userId,$tripId,$agentType,'assistant',$reply,null,['kind'=>'agent_result','data_scope'=>$this->scope($agentType)]);return ['agent_type'=>$agentType,'message'=>$reply];
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
            'overview'=>['weather','flights','events','places','itinerary','budget','updates'],
            'weather'=>['weather'],
            'flights'=>['flights'],
            'events'=>['events','weather_fit'],
            'local'=>['places','weather_fit'],
            'itinerary'=>['itinerary','weather','saved_events','saved_places'],
            'budget'=>['budget','flights','saved_items'],
        };
    }

    private function systemPrompt(string $agentType,array $dashboard): string
    {
        $label=$this->label($agentType);$scope=implode(', ',$this->scope($agentType));return "You are Vacation Brain's $label for one specific planned trip. Your allowed data scope is: $scope. Be concise, practical and proactive. Use the supplied trip/provider data as facts. Never invent live weather, fares, events, ratings, availability, prices or booking inventory. If a provider is unavailable or a field is missing, say so plainly. Skyscanner indicative fares are cached market intelligence and are not guaranteed bookable prices. Lodging is a paid-partnership surface: do not fabricate hotel inventory. Suggest concrete next actions and scheduling improvements when supported by the data. The Overview Agent may reason across all supplied datasets and prioritize booking/planning actions. Specialist agents should stay focused on their named dataset except for weather-fit context explicitly supplied to Events/Local/Itinerary.";
    }

    private function datasetContext(string $agentType,array $dashboard): string
    {
        $trip=$dashboard['trip']??[];$base=['trip'=>['id'=>$trip['id']??null,'name'=>$trip['name']??'','destination'=>$trip['destination_name']??'','origin'=>$trip['origin_name']??'','origin_iata'=>$trip['origin_iata']??'','destination_iata'=>$trip['destination_iata']??'','start_date'=>$trip['start_date']??null,'end_date'=>$trip['end_date']??null,'travelers'=>$trip['travelers']??1,'target_budget'=>$trip['target_budget']??null,'status'=>$trip['status']??'','booking_readiness'=>$trip['booking_readiness']??0]];$snap=$dashboard['snapshots']??[];$data=$base;
        if($agentType==='overview'){$super=(new TripSupervisorService($this->pdo))->overview((int)$trip['user_id'],(int)$trip['id'],$dashboard);$data+=['weather'=>$this->payload($snap,'weather'),'flights'=>$this->payload($snap,'flights'),'events'=>$this->payload($snap,'events'),'places'=>$this->payload($snap,'places'),'opportunities'=>$dashboard['opportunities']??[],'budget'=>$dashboard['budget']??[],'itinerary'=>$this->compactItems($trip['items']??[]),'updates'=>$super['updates'],'proactive_suggestions'=>$super['suggestions']];}
        elseif($agentType==='weather')$data['weather']=$this->payload($snap,'weather');
        elseif($agentType==='flights')$data['flights']=$this->payload($snap,'flights');
        elseif($agentType==='events'){$data['events']=$this->payload($snap,'events');$data['weather_fit']=$this->weatherFit($this->payload($snap,'weather'));}
        elseif($agentType==='local'){$data['places']=$this->payload($snap,'places');$data['weather_fit']=$this->weatherFit($this->payload($snap,'weather'));}
        elseif($agentType==='itinerary'){$data['itinerary']=$this->compactItems($trip['items']??[]);$data['weather']=$this->payload($snap,'weather');$data['events']=$this->payload($snap,'events');$data['places']=$this->payload($snap,'places');}
        elseif($agentType==='budget'){$data['budget']=$dashboard['budget']??[];$data['flights']=$this->payload($snap,'flights');$data['saved_items']=$this->compactItems($trip['items']??[]);}
        $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return $json!==false?$this->clip($json,30000):'{}';
    }

    private function fallback(string $agentType,array $dashboard): string
    {
        $snap=$dashboard['snapshots']??[];if($agentType==='weather'){$w=$this->payload($snap,'weather');return !empty($w['ok'])?'Weather data is loaded. I can reason over the forecast and historical context, but the configured LLM is unavailable right now.':'Weather data is not available yet. Refresh the Weather tab or configure a weather provider.';}if($agentType==='flights'){$f=$this->payload($snap,'flights');return !empty($f['ok'])&&is_numeric($f['min_price']??null)?'Indicative airfare currently starts around $'.number_format((float)$f['min_price'],0).'. The LLM is unavailable, but the fare snapshot remains active.':'Flight data is not available yet. Add route codes or configure Skyscanner.';}if($agentType==='events'){$e=$this->payload($snap,'events');return 'The current event snapshot contains '.count($e['items']??[]).' matching events. The LLM is unavailable, but the live event results remain visible in this tab.';}if($agentType==='local'){$p=$this->payload($snap,'places');return 'The current local snapshot contains '.count($p['items']??[]).' restaurants, bars or attractions. The LLM is unavailable, but you can still add them to the trip.';}if($agentType==='budget'){$b=$dashboard['budget']??[];return 'Current projected trip cost is $'.number_format((float)($b['projected']??0),0).'. The LLM is unavailable, but the budget calculation remains active.';}if($agentType==='itinerary')return 'Your itinerary currently has '.count($dashboard['trip']['items']??[]).' saved items. The LLM is unavailable, but scheduling controls remain active.';$s=(new TripSupervisorService($this->pdo))->overview((int)$dashboard['trip']['user_id'],(int)$dashboard['trip']['id'],$dashboard)['suggestions'];return $s?'Top current action: '.$s[0]['title'].' — '.$s[0]['body']:'Trip data is loaded, but the configured LLM is unavailable right now.';
    }

    private function assertTrip(int $userId,int $tripId): array
    {
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);if(!$trip)throw new RuntimeException('Trip not found.');return $trip;
    }

    private function insert(int $userId,int $tripId,string $agentType,string $role,string $body,?string $fingerprint,array $metadata): void
    {
        $stmt=$this->pdo->prepare('INSERT INTO trip_agent_messages (dream_trip_id,user_id,agent_type,role,body,fingerprint,metadata_json) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$tripId,$userId,$agentType,$role,$this->clip($body,12000),$fingerprint,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
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
