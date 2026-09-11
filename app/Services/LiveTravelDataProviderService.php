<?php
declare(strict_types=1);

/**
 * Provider-neutral bridge for Vacation Brain live travel data.
 * Existing weather/events/places/indicative-airfare behavior remains delegated to
 * TravelDataProviderService; admin-managed encrypted credentials are injected only
 * into its private config snapshot. New lodging and booked-flight status paths live here.
 */
final class LiveTravelDataProviderService
{
    private TravelProviderSettingsService $settings;
    private TravelDataProviderService $base;

    public function __construct(private PDO $pdo)
    {
        $this->settings=new TravelProviderSettingsService($pdo);
        global $config;$original=is_array($config??null)?$config:[];$effective=$original;$effective['travel']=is_array($effective['travel']??null)?$effective['travel']:[];
        foreach(['visual_crossing'=>'visual_crossing_key','ticketmaster'=>'ticketmaster_key','google_places'=>'google_places_key','skyscanner'=>'skyscanner_key'] as $provider=>$key){$secret=$this->settings->effectiveKey($provider);if($secret!=='')$effective['travel'][$key]=$secret;}
        $config=$effective;try{$this->base=new TravelDataProviderService($pdo);}finally{$config=$original;}
    }

    public function providerStatus(): array
    {
        $status=$this->base->providerStatus();$aviation=$this->settings->effectiveKey('aviationstack')!=='';$sky=$this->settings->effectiveKey('skyscanner')!=='';$booking=$this->settings->effectiveKey('booking_com')!==''&&$this->settings->setting('booking_com','affiliate_id')!=='';
        $status['flights']=['label'=>'Flights','provider'=>$aviation&&$sky?'Aviationstack + Skyscanner':($aviation?'Aviationstack':($sky?'Skyscanner':'Flight providers')),'configured'=>$aviation||$sky,'note'=>$aviation?($sky?'Booked-flight status plus indicative airfare':'Booked-flight status enabled; add Skyscanner for airfare intelligence'):'Add Aviationstack for booked-flight status; Skyscanner remains indicative airfare only','components'=>['status'=>$aviation,'fares'=>$sky]];
        $status['lodging']=['label'=>'Lodging','provider'=>'Booking.com Demand API','configured'=>$booking,'note'=>$booking?'Live accommodation search and pricing enabled':'Add Booking.com Demand API token + Affiliate ID','components'=>['availability'=>$booking]];
        return ['weather'=>$status['weather'],'flights'=>$status['flights'],'lodging'=>$status['lodging'],'events'=>$status['events'],'places'=>$status['places']];
    }

    public function weather(array $trip): array{$r=$this->base->weather($trip);$this->logResult($trip,'weather',$r);return $r;}
    public function events(array $trip): array{$r=$this->base->events($trip);$this->logResult($trip,'events',$r);return $r;}
    public function places(array $trip): array{$r=$this->base->places($trip);$this->logResult($trip,'places',$r);return $r;}

    public function flights(array $trip): array
    {
        $fares=$this->base->flights($trip);$this->logResult($trip,'flights',$fares);
        $statuses=$this->bookedFlightStatuses($trip);$fareOk=!empty($fares['ok']);
        if(!$fareOk&&!$statuses)return $fares;
        $out=$fareOk?$fares:['ok'=>true,'data_type'=>'flights','provider'=>'Aviationstack','source'=>'aviationstack','observed_at'=>gmdate('c'),'expires_in'=>900,'origin_iata'=>strtoupper((string)($trip['origin_iata']??'')),'destination_iata'=>strtoupper((string)($trip['destination_iata']??'')),'currency'=>(string)($trip['currency']??'USD'),'min_price'=>null,'max_price'=>null,'quote_count'=>0,'direct_available'=>false,'quotes'=>[]];
        $out['booked_statuses']=$statuses;$out['live_status_count']=count($statuses);$out['status_provider']=$statuses?'Aviationstack':null;$out['fare_provider']=$fareOk?'Skyscanner Indicative Prices':null;$out['provider']=$statuses&&$fareOk?'Aviationstack + Skyscanner':($statuses?'Aviationstack':(string)($fares['provider']??'Skyscanner'));
        $out['source']=$statuses&&$fareOk?'aviationstack+skyscanner':($statuses?'aviationstack':(string)($fares['source']??'skyscanner'));$out['expires_in']=$statuses?900:(int)($fares['expires_in']??21600);
        $out['accuracy_note']=$statuses?'Booked-flight operational status is live provider data. Gate, terminal and estimated times can still change; confirm critical details with the airline. '.($fareOk?'Indicative Skyscanner fares are planning estimates, not guaranteed bookable prices.':''):(string)($fares['accuracy_note']??'');
        return $out;
    }

    public function lodging(array $trip): array
    {
        $key=$this->settings->effectiveKey('booking_com');$affiliate=$this->settings->setting('booking_com','affiliate_id');if($key===''||$affiliate==='')return $this->failure('lodging','Booking.com Demand API','Booking.com Demand API token and Affiliate ID are not configured.');
        $checkin=$this->date((string)($trip['start_date']??''));$checkout=$this->date((string)($trip['end_date']??''));$today=new DateTimeImmutable('today');if(!$checkin||!$checkout||$checkout<=$checkin||$checkin<$today)return $this->failure('lodging','Booking.com Demand API','Future trip check-in and checkout dates are required for live lodging search.');
        $lat=$this->floatOrNull($trip['destination_latitude']??null);$lng=$this->floatOrNull($trip['destination_longitude']??null);$airport=strtoupper(trim((string)($trip['destination_iata']??'')));if($lat===null||$lng===null){if(!preg_match('/^[A-Z]{3}$/',$airport))return $this->failure('lodging','Booking.com Demand API','Destination coordinates or a destination airport code are required for live lodging search.');}
        $env=$this->settings->setting('booking_com','environment','production');$base=$env==='sandbox'?'https://demandapi-sandbox.booking.com/3.2':'https://demandapi.booking.com/3.2';$headers=['Authorization: Bearer '.$key,'X-Affiliate-Id: '.$affiliate,'Accept: application/json'];$travelers=max(1,min(30,(int)($trip['travelers']??1)));$currency=strtoupper((string)($trip['currency']??'USD'));if(!preg_match('/^[A-Z]{3}$/',$currency))$currency='USD';
        $payload=['booker'=>['country'=>$this->settings->setting('booking_com','booker_country','us'),'platform'=>$this->settings->setting('booking_com','platform','desktop')],'checkin'=>$checkin->format('Y-m-d'),'checkout'=>$checkout->format('Y-m-d'),'currency'=>$currency,'guests'=>['number_of_adults'=>$travelers,'number_of_rooms'=>max(1,(int)ceil($travelers/2))],'rows'=>20,'extras'=>['products']];
        if($lat!==null&&$lng!==null)$payload['coordinates']=['latitude'=>$lat,'longitude'=>$lng,'radius'=>20];else$payload['airport']=$airport;
        $started=microtime(true);[$status,$body,$transport]=$this->httpJson($base.'/accommodations/search',$headers,$payload,30);$latency=(int)round((microtime(true)-$started)*1000);$ok=$status>=200&&$status<300;
        if(!$ok){$error=$transport?:$this->apiError($body)?:'Booking.com returned HTTP '.$status.'.';$this->settings->recordUsage('booking_com','lodging',$this->userId($trip),$this->tripId($trip),false,$status,$latency,$error);return $this->failure('lodging','Booking.com Demand API',$error);}
        $json=json_decode($body,true)?:[];$searchRows=is_array($json['data']??null)?$json['data']:[];$ids=[];foreach(array_slice($searchRows,0,20) as $r)if(isset($r['id']))$ids[]=(int)$r['id'];$details=$ids?$this->bookingDetails($base,$headers,$ids):[];$items=[];$prices=[];
        foreach(array_slice($searchRows,0,20) as $r){if(!is_array($r))continue;$id=(int)($r['id']??0);$detail=$details[$id]??[];$display=$this->moneyValue($r['price']['display']??null);$total=$this->moneyValue($r['price']['total']??null);$shown=$display??$total;if($shown!==null)$prices[]=$shown;$currencyOut=(string)($r['currency']['booker']??$r['currency']['accommodation']??$currency);$name=$this->localized($detail['name']??null)?:('Booking.com property #'.$id);$address=$this->localized($detail['location']['address']??null);$url=$this->urlValue($r['url']??($detail['url']??null));
            $items[]=['id'=>(string)$id,'name'=>$name,'address'=>$address,'price_display'=>$display,'price_total'=>$total,'currency'=>$currencyOut,'url'=>$url,'available'=>true,'checkin'=>$checkin->format('Y-m-d'),'checkout'=>$checkout->format('Y-m-d'),'source'=>'booking_com'];
        }
        sort($prices,SORT_NUMERIC);$this->settings->recordUsage('booking_com','lodging',$this->userId($trip),$this->tripId($trip),true,$status,$latency,null);
        return ['ok'=>true,'data_type'=>'lodging','provider'=>'Booking.com Demand API','source'=>'booking_com','observed_at'=>gmdate('c'),'expires_in'=>1800,'items'=>$items,'count'=>count($items),'currency'=>$currency,'min_price'=>$prices[0]??null,'max_price'=>$prices?end($prices):null,'request_id'=>(string)($json['request_id']??''),'checkin'=>$checkin->format('Y-m-d'),'checkout'=>$checkout->format('Y-m-d'),'accuracy_note'=>'Availability and prices are a live provider snapshot and can change before checkout. Open the provider result to confirm the final room, taxes, policies and total price.'];
    }

    private function bookedFlightStatuses(array $trip): array
    {
        $key=$this->settings->effectiveKey('aviationstack');if($key===''||!db_table_exists('trip_bookings')||!db_column_exists('trip_bookings','flight_number'))return [];$tripId=$this->tripId($trip);$userId=$this->userId($trip);if($tripId<1||$userId<1)return [];
        $q=$this->pdo->prepare("SELECT id,title,provider_name,flight_number,departure_iata,arrival_iata,starts_at FROM trip_bookings WHERE user_id=? AND dream_trip_id=? AND booking_type='flight' AND status IN ('booked','confirmed','changed') AND COALESCE(flight_number,'')<>'' ORDER BY COALESCE(starts_at,'9999-12-31'),id LIMIT 8");$q->execute([$userId,$tripId]);$out=[];
        foreach($q->fetchAll()?:[] as $booking){$flight=preg_replace('/\s+/','',strtoupper((string)$booking['flight_number']))??'';if($flight==='')continue;$params=['access_key'=>$key,'flight_number'=>$flight,'limit'=>5];$date='';if(!empty($booking['starts_at'])){$ts=strtotime((string)$booking['starts_at']);if($ts)$date=date('Y-m-d',$ts);}if($date==='')$date=(string)($trip['start_date']??'');if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$params['flight_date']=$date;$dep=strtoupper(trim((string)($booking['departure_iata']??'')));$arr=strtoupper(trim((string)($booking['arrival_iata']??'')));if(preg_match('/^[A-Z]{3}$/',$dep))$params['dep_iata']=$dep;if(preg_match('/^[A-Z]{3}$/',$arr))$params['arr_iata']=$arr;
            $started=microtime(true);[$status,$body,$transport]=$this->httpGet('https://api.aviationstack.com/v1/flights?'.http_build_query($params),['Accept: application/json'],20);$latency=(int)round((microtime(true)-$started)*1000);$ok=$status>=200&&$status<300;$json=json_decode($body,true)?:[];$row=is_array($json['data'][0]??null)?$json['data'][0]:null;
            if(!$ok||!$row){$error=$transport?:$this->apiError($body)?:($ok?'No matching live flight was returned.':'Aviationstack returned HTTP '.$status.'.');$this->settings->recordUsage('aviationstack','flights',$userId,$tripId,false,$status,$latency,$error);continue;}
            $this->settings->recordUsage('aviationstack','flights',$userId,$tripId,true,$status,$latency,null);$departure=is_array($row['departure']??null)?$row['departure']:[];$arrival=is_array($row['arrival']??null)?$row['arrival']:[];$flightData=is_array($row['flight']??null)?$row['flight']:[];$airline=is_array($row['airline']??null)?$row['airline']:[];
            $out[]=['booking_id'=>(int)$booking['id'],'title'=>(string)$booking['title'],'flight_number'=>(string)($flightData['iata']??$flightData['number']??$flight),'airline'=>(string)($airline['name']??$booking['provider_name']??''),'status'=>(string)($row['flight_status']??'unknown'),'flight_date'=>(string)($row['flight_date']??$date),'departure'=>$this->flightEndpoint($departure),'arrival'=>$this->flightEndpoint($arrival),'live'=>(array)($row['live']??[]),'observed_at'=>gmdate('c')];
        }
        return $out;
    }

    private function bookingDetails(string $base,array $headers,array $ids): array
    {
        [$status,$body]=$this->httpJson($base.'/accommodations/details',$headers,['accommodations'=>array_values(array_unique(array_map('intval',$ids))),'languages'=>['en-gb']],25);if($status<200||$status>=300)return [];$json=json_decode($body,true)?:[];$out=[];foreach(($json['data']??[]) as $row)if(is_array($row)&&isset($row['id']))$out[(int)$row['id']]=$row;return $out;
    }
    private function flightEndpoint(array $row): array{return ['airport'=>(string)($row['airport']??''),'iata'=>(string)($row['iata']??''),'timezone'=>(string)($row['timezone']??''),'terminal'=>(string)($row['terminal']??''),'gate'=>(string)($row['gate']??''),'delay'=>isset($row['delay'])&&is_numeric($row['delay'])?(int)$row['delay']:null,'scheduled'=>(string)($row['scheduled']??''),'estimated'=>(string)($row['estimated']??''),'actual'=>(string)($row['actual']??'')];}
    private function logResult(array $trip,string $type,array $result): void{$provider=(string)($result['source']??$result['provider']??'legacy');$this->settings->recordUsage($provider,$type,$this->userId($trip),$this->tripId($trip),!empty($result['ok']),null,null,!empty($result['ok'])?null:(string)($result['error']??'Provider unavailable.'));}
    private function userId(array $trip): ?int{$v=(int)($trip['user_id']??0);return $v>0?$v:null;}
    private function tripId(array $trip): ?int{$v=(int)($trip['id']??0);return $v>0?$v:null;}
    private function date(string $value): ?DateTimeImmutable{$value=trim($value);if($value==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value?$d:null;}
    private function floatOrNull(mixed $value): ?float{return $value!==null&&$value!==''&&is_numeric($value)?(float)$value:null;}
    private function moneyValue(mixed $value): ?float{if(is_numeric($value))return round((float)$value,2);if(is_array($value)){foreach(['value','amount','display','book','total'] as $key)if(array_key_exists($key,$value)){$_=$this->moneyValue($value[$key]);if($_!==null)return $_;}}return null;}
    private function localized(mixed $value): string{if(is_string($value))return trim($value);if(!is_array($value))return '';foreach(['en-gb','en-us','en'] as $key)if(isset($value[$key])&&is_string($value[$key]))return trim($value[$key]);foreach($value as $v)if(is_string($v)&&trim($v)!=='')return trim($v);return '';}
    private function urlValue(mixed $value): string{if(is_string($value))return preg_match('#^https://#i',$value)?$value:'';if(is_array($value)){foreach(['web','app'] as $key)if(isset($value[$key])&&is_string($value[$key])&&preg_match('#^https://#i',$value[$key]))return $value[$key];}return '';}
    private function failure(string $type,string $provider,string $message): array{return ['ok'=>false,'data_type'=>$type,'provider'=>$provider,'source_status'=>'unavailable','observed_at'=>gmdate('c'),'expires_in'=>900,'error'=>substr(trim($message),0,500)];}
    private function apiError(string $body): string{$json=json_decode($body,true);if(!is_array($json))return '';foreach([['error','message'],['status','message'],['detail','message']] as $path){$v=$json[$path[0]][$path[1]]??null;if(is_string($v)&&$v!=='')return $v;}if(is_string($json['error']??null))return (string)$json['error'];return '';}
    private function httpGet(string $url,array $headers=[],int $timeout=20): array{if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.38']);$body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,is_string($body)?$body:'',$error];}$context=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.38",'timeout'=>$timeout,'ignore_errors'=>true]]);$body=@file_get_contents($url,false,$context);$status=0;foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return [$status,is_string($body)?$body:'',$body===false?'HTTP request failed.':''];}
    private function httpJson(string $url,array $headers,array $payload,int $timeout=25): array{$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.38']);$response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,is_string($response)?$response:'',$error];}$context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.38",'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);$response=@file_get_contents($url,false,$context);$status=0;foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return [$status,is_string($response)?$response:'',$response===false?'HTTP request failed.':''];}
}
