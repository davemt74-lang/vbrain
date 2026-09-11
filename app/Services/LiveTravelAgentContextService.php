<?php
declare(strict_types=1);

/**
 * Gives the main Vacation Brain agent read-only context from the nearest active
 * trip's already-saved intelligence snapshots plus the persisted proactive and
 * local-concierge ledgers. It never refreshes a provider and never includes booking confirmation codes,
 * booking notes, payment data, private Trip Memory notes, or exact device coordinates.
 */
final class LiveTravelAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function context(int $userId): string
    {
        $local='';
        try{if(class_exists('LocalConciergeService')){$service=new LocalConciergeService($this->pdo);if($service->ready())$local=$service->agentContext($userId,1);}}catch(Throwable){}
        $trip=$this->nearestTrip($userId);if(!$trip)return $local;$tripId=(int)$trip['id'];$facts=[];$health=[];
        if(db_table_exists('trip_intelligence_snapshots')){
            $dashboard=(new TripIntelligenceService($this->pdo))->dashboard($userId,$tripId);$health=(new TripLiveIntelligenceService($this->pdo))->health($dashboard);$snap=$dashboard['snapshots']??[];
            $weather=$this->payload($snap,'weather');if(!empty($weather['ok'])){$day=$weather['days'][0]??[];$parts=[];if(isset($day['high']))$parts[]='high '.round((float)$day['high']).'°';if(isset($day['precip_probability']))$parts[]='rain '.round((float)$day['precip_probability']).'%';if(!empty($day['conditions']))$parts[]=(string)$day['conditions'];if($parts)$facts[]='weather: '.implode(', ',$parts).' ('.($health['weather']['freshness']??'unknown freshness').')';}
            $flights=$this->payload($snap,'flights');if(!empty($flights['booked_statuses'])){foreach(array_slice((array)$flights['booked_statuses'],0,3) as $row){if(!is_array($row))continue;$line='flight '.(string)($row['flight_number']??'').' status '.(string)($row['status']??'unknown');$dep=(array)($row['departure']??[]);if(!empty($dep['gate']))$line.=', departure gate '.(string)$dep['gate'];if(isset($dep['delay'])&&$dep['delay']!==null)$line.=', departure delay '.(int)$dep['delay'].'m';$facts[]=$line.' ('.($health['flights']['freshness']??'unknown freshness').')';}}
            elseif(!empty($flights['ok'])&&isset($flights['min_price'])&&$flights['min_price']!==null)$facts[]='indicative airfare from '.(string)($flights['currency']??'USD').' '.number_format((float)$flights['min_price'],0).' — not a bookable fare claim ('.($health['flights']['freshness']??'unknown freshness').')';
            $lodging=$this->payload($snap,'lodging');if(!empty($lodging['ok'])){$line='lodging snapshot '.count((array)($lodging['items']??[])).' results';if(isset($lodging['min_price'])&&$lodging['min_price']!==null)$line.=', from '.(string)($lodging['currency']??'USD').' '.number_format((float)$lodging['min_price'],0);$facts[]=$line.' ('.($health['lodging']['freshness']??'unknown freshness').')';}
            $events=$this->payload($snap,'events');if(!empty($events['items']))$facts[]='events snapshot has '.count((array)$events['items']).' matching events ('.($health['events']['freshness']??'unknown freshness').')';$places=$this->payload($snap,'places');if(!empty($places['items']))$facts[]='local places snapshot has '.count((array)$places['items']).' results ('.($health['places']['freshness']??'unknown freshness').')';
        }
        $proactive='';try{if(class_exists('ProactiveTravelService')){$service=new ProactiveTravelService($this->pdo);if($service->ready())$proactive=$service->agentContext($userId,$tripId,6);}}catch(Throwable){}
        if(!$facts&&$proactive===''&&$local==='')return '';$destination=trim((string)($trip['destination_name']??''))?:trim((string)$trip['name']);$dates=trim((string)($trip['date_label']??''));$sections=[];
        if($facts)$sections[]='LIVE TRIP CONTEXT (saved snapshots only; no provider refresh was triggered): nearest active trip "'.(string)$trip['name'].'" to '.$destination.($dates!==''?' · '.$dates:'').'. '.implode('; ',$facts).'. Never treat indicative airfare or lodging search results as confirmed bookings. Booking confirmation codes, booking notes, payment data, and private trip-memory notes are excluded.';
        if($proactive!=='')$sections[]=$proactive;
        if($local!=='')$sections[]=$local;
        return implode("\n\n",$sections);
    }

    public function fallbackSummary(int $userId): ?array
    {
        $concierge=null;try{if(class_exists('LocalConciergeService')){$service=new LocalConciergeService($this->pdo);if($service->ready())$concierge=$service->fallback($userId);}}catch(Throwable){}
        $trip=$this->nearestTrip($userId);if(!$trip){return $concierge?['trip'=>['name'=>'your shared trip'],'health'=>[],'weather'=>[],'flights'=>[],'lodging'=>[],'events'=>[],'places'=>[],'proactive'=>[],'concierge'=>$concierge]:null;}
        $dashboard=(new TripIntelligenceService($this->pdo))->dashboard($userId,(int)$trip['id']);$snap=$dashboard['snapshots']??[];$health=(new TripLiveIntelligenceService($this->pdo))->health($dashboard);$proactive=[];try{if(class_exists('ProactiveTravelService')){$service=new ProactiveTravelService($this->pdo);if($service->ready())$proactive=$service->issuesForTrip($userId,(int)$trip['id'],6,true);}}catch(Throwable){}return ['trip'=>$trip,'health'=>$health,'weather'=>$this->payload($snap,'weather'),'flights'=>$this->payload($snap,'flights'),'lodging'=>$this->payload($snap,'lodging'),'events'=>$this->payload($snap,'events'),'places'=>$this->payload($snap,'places'),'proactive'=>$proactive,'concierge'=>$concierge];
    }

    private function nearestTrip(int $userId): ?array
    {
        if(!db_table_exists('dream_trips'))return null;$sql="SELECT * FROM dream_trips WHERE user_id=? AND status NOT IN ('completed','abandoned') ORDER BY CASE WHEN start_date IS NULL THEN 2 WHEN start_date>=CURDATE() THEN 0 ELSE 1 END, CASE WHEN start_date>=CURDATE() THEN start_date END ASC, updated_at DESC,id DESC LIMIT 1";$q=$this->pdo->prepare($sql);$q->execute([$userId]);$row=$q->fetch();if(!$row)return null;$meta=json_decode((string)($row['metadata_json']??''),true)?:[];$row['destination_name']=(string)($meta['destination_name']??'');$start=(string)($row['start_date']??'');$end=(string)($row['end_date']??'');$row['date_label']=$this->dateLabel($start,$end);return $row;
    }
    private function payload(array $snapshots,string $type): array{$p=$snapshots[$type]['payload']??[];return is_array($p)?$p:[];}
    private function dateLabel(string $start,string $end): string{if($start==='')return '';$s=strtotime($start);if(!$s)return '';$e=$end!==''?strtotime($end):false;return $e?date('M j',$s).' – '.date('M j, Y',$e):date('M j, Y',$s);}
}
