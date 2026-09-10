<?php
declare(strict_types=1);

final class TravelDataProviderService
{
    private array $config;

    public function __construct(private PDO $pdo)
    {
        global $config;
        $this->config=is_array($config??null)?$config:[];
    }

    public function providerStatus(): array
    {
        return [
            'weather'=>[
                'label'=>'Weather',
                'provider'=>$this->key('visual_crossing')!==''?'Visual Crossing':'National Weather Service',
                'configured'=>$this->key('visual_crossing')!=='' || true,
                'note'=>$this->key('visual_crossing')!==''?'Global 15-day forecast + history':'Free U.S. forecast fallback; add Visual Crossing for global weather + history',
            ],
            'events'=>[
                'label'=>'Events','provider'=>'Ticketmaster Discovery','configured'=>$this->key('ticketmaster')!=='',
                'note'=>$this->key('ticketmaster')!==''?'Live destination events enabled':'Add a Ticketmaster API key to enable live events',
            ],
            'places'=>[
                'label'=>'Local places','provider'=>'Google Places','configured'=>$this->key('google_places')!=='',
                'note'=>$this->key('google_places')!==''?'Restaurants, bars and attractions enabled':'Add a Google Places key to enable local businesses',
            ],
            'flights'=>[
                'label'=>'Flights','provider'=>'Skyscanner','configured'=>$this->key('skyscanner')!=='',
                'note'=>$this->key('skyscanner')!==''?'Indicative airfare intelligence enabled':'Connect a Skyscanner partner key for airfare intelligence',
            ],
        ];
    }

    public function weather(array $trip): array
    {
        $key=$this->key('visual_crossing');
        if($key!==''){
            $result=$this->visualCrossingWeather($trip,$key);
            if(!empty($result['ok'])) return $result;
        }
        $lat=$this->floatOrNull($trip['destination_latitude']??null);
        $lng=$this->floatOrNull($trip['destination_longitude']??null);
        if($lat!==null&&$lng!==null){
            $fallback=$this->nwsWeather($lat,$lng);
            if(!empty($fallback['ok'])) return $fallback;
        }
        return $this->failure('weather',$key!==''?'Visual Crossing':'National Weather Service','Weather could not be loaded for this destination. Add destination coordinates or configure a Visual Crossing API key.');
    }

    public function events(array $trip): array
    {
        $key=$this->key('ticketmaster');
        if($key==='') return $this->failure('events','Ticketmaster Discovery','Ticketmaster is not configured yet.');
        $params=['apikey'=>$key,'size'=>24,'sort'=>'date,asc'];
        $lat=$this->floatOrNull($trip['destination_latitude']??null);$lng=$this->floatOrNull($trip['destination_longitude']??null);
        if($lat!==null&&$lng!==null){$params['latlong']=$lat.','.$lng;$params['radius']='75';$params['unit']='miles';}
        else{$params['keyword']=$this->destinationName($trip);}
        [$start,$end]=$this->eventWindow($trip);
        $params['startDateTime']=$start.'T00:00:00Z';$params['endDateTime']=$end.'T23:59:59Z';
        [$status,$body,$transport]=$this->httpGet('https://app.ticketmaster.com/discovery/v2/events.json?'.http_build_query($params),['Accept: application/json'],20);
        if($status<200||$status>=300){return $this->failure('events','Ticketmaster Discovery',$transport?:$this->apiError($body)?:'Ticketmaster returned HTTP '.$status.'.');}
        $json=json_decode($body,true)?:[];$rows=[];
        foreach(($json['_embedded']['events']??[]) as $event){
            if(!is_array($event))continue;
            $venue=$event['_embedded']['venues'][0]??[];$classification=$event['classifications'][0]??[];
            $price=$event['priceRanges'][0]??[];$image='';$bestWidth=0;
            foreach(($event['images']??[]) as $img){$w=(int)($img['width']??0);if($w>$bestWidth&&!empty($img['url'])){$bestWidth=$w;$image=(string)$img['url'];}}
            $rows[]=[
                'id'=>(string)($event['id']??sha1((string)($event['name']??'event'))),
                'name'=>(string)($event['name']??'Event'),
                'date'=>(string)($event['dates']['start']['localDate']??''),
                'time'=>(string)($event['dates']['start']['localTime']??''),
                'venue'=>(string)($venue['name']??''),
                'address'=>(string)($venue['address']['line1']??''),
                'city'=>(string)($venue['city']['name']??''),
                'category'=>(string)($classification['segment']['name']??$classification['genre']['name']??'Event'),
                'url'=>(string)($event['url']??''),
                'image'=>$image,
                'price_min'=>isset($price['min'])?(float)$price['min']:null,
                'price_max'=>isset($price['max'])?(float)$price['max']:null,
                'currency'=>(string)($price['currency']??'USD'),
            ];
        }
        return ['ok'=>true,'data_type'=>'events','provider'=>'Ticketmaster Discovery','source'=>'ticketmaster','observed_at'=>gmdate('c'),'expires_in'=>21600,'items'=>$rows,'window'=>['start'=>$start,'end'=>$end],'count'=>count($rows)];
    }

    public function places(array $trip): array
    {
        $key=$this->key('google_places');
        if($key==='') return $this->failure('places','Google Places','Google Places is not configured yet.');
        $headers=['X-Goog-Api-Key: '.$key,'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.primaryType,places.types,places.rating,places.userRatingCount,places.priceLevel,places.googleMapsUri,places.websiteUri'];
        $lat=$this->floatOrNull($trip['destination_latitude']??null);$lng=$this->floatOrNull($trip['destination_longitude']??null);
        if($lat!==null&&$lng!==null){
            $payload=['includedTypes'=>['restaurant','bar','tourist_attraction','museum','park','night_club'],'maxResultCount'=>20,'locationRestriction'=>['circle'=>['center'=>['latitude'=>$lat,'longitude'=>$lng],'radius'=>25000.0]]];
            [$status,$body,$transport]=$this->httpJson('https://places.googleapis.com/v1/places:searchNearby',$headers,$payload,20);
        }else{
            $payload=['textQuery'=>'restaurants bars attractions in '.$this->destinationName($trip),'pageSize'=>20];
            [$status,$body,$transport]=$this->httpJson('https://places.googleapis.com/v1/places:searchText',$headers,$payload,20);
        }
        if($status<200||$status>=300)return $this->failure('places','Google Places',$transport?:$this->apiError($body)?:'Google Places returned HTTP '.$status.'.');
        $json=json_decode($body,true)?:[];$rows=[];
        foreach(($json['places']??[]) as $place){
            if(!is_array($place))continue;$primary=(string)($place['primaryType']??'place');
            $rows[]=[
                'id'=>(string)($place['id']??sha1((string)($place['displayName']['text']??'place'))),
                'name'=>(string)($place['displayName']['text']??'Local place'),
                'type'=>$primary,
                'category'=>$this->placeCategory($primary,(array)($place['types']??[])),
                'address'=>(string)($place['formattedAddress']??''),
                'rating'=>isset($place['rating'])?(float)$place['rating']:null,
                'review_count'=>(int)($place['userRatingCount']??0),
                'price_level'=>(string)($place['priceLevel']??''),
                'maps_url'=>(string)($place['googleMapsUri']??''),
                'website_url'=>(string)($place['websiteUri']??''),
            ];
        }
        usort($rows,static fn($a,$b)=>(($b['rating']??0)<=>($a['rating']??0))?:($b['review_count']<=>$a['review_count']));
        return ['ok'=>true,'data_type'=>'places','provider'=>'Google Places','source'=>'google_places','observed_at'=>gmdate('c'),'expires_in'=>86400,'items'=>$rows,'count'=>count($rows)];
    }

    public function flights(array $trip): array
    {
        $key=$this->key('skyscanner');
        $origin=trim(strtoupper((string)($trip['origin_iata']??'')));$destination=trim(strtoupper((string)($trip['destination_iata']??'')));
        if($key==='') return $this->failure('flights','Skyscanner','Skyscanner partner access is not configured yet.');
        if(!preg_match('/^[A-Z]{3}$/',$origin)){$origin=$this->skyscannerIata((string)($trip['origin_name']??''),false,$key);}
        if(!preg_match('/^[A-Z]{3}$/',$destination)){$destination=$this->skyscannerIata($this->destinationName($trip),true,$key);}
        if(!preg_match('/^[A-Z]{3}$/',$origin)||!preg_match('/^[A-Z]{3}$/',$destination))return $this->failure('flights','Skyscanner','Add an origin and destination airport/city code so airfare can be compared.');
        $query=['market'=>$this->travelSetting('market','US'),'locale'=>$this->travelSetting('locale','en-US'),'currency'=>$this->travelSetting('currency',(string)($trip['currency']??'USD')?:'USD'),'queryLegs'=>[]];
        $query['queryLegs'][]=$this->flightLeg($origin,$destination,(string)($trip['start_date']??''));
        if(!empty($trip['end_date']))$query['queryLegs'][]=$this->flightLeg($destination,$origin,(string)$trip['end_date']);
        [$status,$body,$transport]=$this->httpJson('https://partners.api.skyscanner.net/apiservices/v3/flights/indicative/search',['x-api-key: '.$key],['query'=>$query],30);
        if($status<200||$status>=300)return $this->failure('flights','Skyscanner',$transport?:$this->apiError($body)?:'Skyscanner returned HTTP '.$status.'.');
        $json=json_decode($body,true)?:[];$quotes=$json['content']['results']['quotes']??$json['quotes']??[];$rows=[];$prices=[];$direct=false;
        foreach($quotes as $quoteId=>$quote){
            if(!is_array($quote))continue;$amount=$this->priceAmount($quote);if($amount!==null)$prices[]=$amount;
            $isDirect=!empty($quote['isDirect'])||(($quote['direct']??null)===true);$direct=$direct||$isDirect;
            $rows[]=['id'=>(string)$quoteId,'price'=>$amount,'direct'=>$isDirect,'raw_label'=>$isDirect?'Direct':'Connecting'];
        }
        sort($prices,SORT_NUMERIC);$min=$prices[0]??null;$max=$prices?end($prices):null;
        return ['ok'=>true,'data_type'=>'flights','provider'=>'Skyscanner Indicative Prices','source'=>'skyscanner','observed_at'=>gmdate('c'),'expires_in'=>21600,'origin_iata'=>$origin,'destination_iata'=>$destination,'currency'=>$query['currency'],'min_price'=>$min,'max_price'=>$max,'quote_count'=>count($rows),'direct_available'=>$direct,'quotes'=>array_slice($rows,0,12),'accuracy_note'=>'Indicative fares are cached market intelligence, not a guaranteed bookable fare.'];
    }

    private function visualCrossingWeather(array $trip,string $key): array
    {
        $location=$this->destinationName($trip);$lat=$this->floatOrNull($trip['destination_latitude']??null);$lng=$this->floatOrNull($trip['destination_longitude']??null);
        if($lat!==null&&$lng!==null)$location=$lat.','.$lng;
        if($location==='')return $this->failure('weather','Visual Crossing','Add a destination first.');
        [$forecastStart,$forecastEnd,$mode]=$this->weatherWindow($trip);
        $url='https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/'.rawurlencode($location).'/'.$forecastStart.'/'.$forecastEnd.'?'.http_build_query(['unitGroup'=>'us','include'=>'days,current,alerts','key'=>$key,'contentType'=>'json']);
        [$status,$body,$transport]=$this->httpGet($url,['Accept: application/json'],25);
        if($status<200||$status>=300)return $this->failure('weather','Visual Crossing',$transport?:$this->apiError($body)?:'Visual Crossing returned HTTP '.$status.'.');
        $json=json_decode($body,true)?:[];$days=[];
        foreach(($json['days']??[]) as $day){if(!is_array($day))continue;$days[]=$this->normalizeVcDay($day);}
        $history=$this->visualCrossingHistory($trip,$location,$key);
        $alerts=[];foreach(($json['alerts']??[]) as $alert){if(is_array($alert))$alerts[]=['event'=>(string)($alert['event']??'Weather alert'),'headline'=>(string)($alert['headline']??''),'description'=>(string)($alert['description']??'')];}
        return ['ok'=>true,'data_type'=>'weather','provider'=>'Visual Crossing','source'=>'visual_crossing','observed_at'=>gmdate('c'),'expires_in'=>$mode==='live_forecast'?10800:604800,'mode'=>$mode,'resolved_address'=>(string)($json['resolvedAddress']??$this->destinationName($trip)),'latitude'=>$json['latitude']??$lat,'longitude'=>$json['longitude']??$lng,'timezone'=>(string)($json['timezone']??''),'days'=>$days,'current'=>$json['currentConditions']??null,'alerts'=>$alerts,'history'=>$history];
    }

    private function visualCrossingHistory(array $trip,string $location,string $key): array
    {
        $reference=(string)($trip['start_date']??'');if($reference==='')$reference=date('Y-m-d');
        try{$start=new DateTimeImmutable($reference);}catch(Throwable){$start=new DateTimeImmutable('today');}
        $endRaw=(string)($trip['end_date']??'');try{$end=$endRaw!==''?new DateTimeImmutable($endRaw):$start;}catch(Throwable){$end=$start;}
        $days=max(0,min(6,(int)$start->diff($end)->format('%a')));$samples=[];$years=[];
        for($back=1;$back<=3;$back++){
            $year=(int)date('Y')-$back;$month=(int)$start->format('m');$day=min((int)$start->format('d'),cal_days_in_month(CAL_GREGORIAN,$month,$year));$hs=(new DateTimeImmutable(sprintf('%04d-%02d-%02d',$year,$month,$day)));$he=$hs->modify('+'.$days.' days');
            $url='https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/'.rawurlencode($location).'/'.$hs->format('Y-m-d').'/'.$he->format('Y-m-d').'?'.http_build_query(['unitGroup'=>'us','include'=>'days','key'=>$key,'contentType'=>'json']);
            [$status,$body]=$this->httpGet($url,['Accept: application/json'],20);if($status<200||$status>=300)continue;$json=json_decode($body,true)?:[];$years[]=$year;
            foreach(($json['days']??[]) as $row)if(is_array($row))$samples[]=$this->normalizeVcDay($row);
        }
        if(!$samples)return ['available'=>false,'years'=>[]];
        $avg=static function(array $values): ?float{$values=array_values(array_filter($values,static fn($v)=>$v!==null));return $values?round(array_sum($values)/count($values),1):null;};
        return ['available'=>true,'years'=>$years,'sample_days'=>count($samples),'avg_high'=>$avg(array_column($samples,'high')),'avg_low'=>$avg(array_column($samples,'low')),'avg_precip_probability'=>$avg(array_column($samples,'precip_probability')),'avg_humidity'=>$avg(array_column($samples,'humidity'))];
    }

    private function nwsWeather(float $lat,float $lng): array
    {
        $headers=['Accept: application/geo+json','User-Agent: VacationBrain/1.27 (https://vacationbrain.com)'];
        [$status,$body,$transport]=$this->httpGet('https://api.weather.gov/points/'.round($lat,4).','.round($lng,4),$headers,15);
        if($status<200||$status>=300)return $this->failure('weather','National Weather Service',$transport?:'NWS forecast is unavailable for this location.');
        $point=json_decode($body,true)?:[];$forecastUrl=(string)($point['properties']['forecast']??'');if($forecastUrl==='')return $this->failure('weather','National Weather Service','NWS did not return a forecast endpoint.');
        [$status,$body,$transport]=$this->httpGet($forecastUrl,$headers,15);if($status<200||$status>=300)return $this->failure('weather','National Weather Service',$transport?:'NWS forecast request failed.');
        $json=json_decode($body,true)?:[];$group=[];
        foreach(($json['properties']['periods']??[]) as $period){if(!is_array($period))continue;$date=substr((string)($period['startTime']??''),0,10);if($date==='')continue;$group[$date]??=['date'=>$date,'high'=>null,'low'=>null,'conditions'=>'','precip_probability'=>null,'wind'=>(string)($period['windSpeed']??''),'humidity'=>null,'uv'=>null];if(!empty($period['isDaytime'])){$group[$date]['high']=$period['temperature']??null;$group[$date]['conditions']=(string)($period['shortForecast']??'');$group[$date]['precip_probability']=$period['probabilityOfPrecipitation']['value']??null;}else{$group[$date]['low']=$period['temperature']??null;}}
        return ['ok'=>true,'data_type'=>'weather','provider'=>'National Weather Service','source'=>'nws','observed_at'=>gmdate('c'),'expires_in'=>7200,'mode'=>'live_forecast','resolved_address'=>(string)($point['properties']['relativeLocation']['properties']['city']??''),'latitude'=>$lat,'longitude'=>$lng,'timezone'=>(string)($point['properties']['timeZone']??''),'days'=>array_values(array_slice($group,0,8,true)),'alerts'=>[],'history'=>['available'=>false,'years'=>[]],'note'=>'NWS is the free U.S. forecast fallback. Historical comparison requires Visual Crossing.'];
    }

    private function skyscannerIata(string $term,bool $destination,string $key): string
    {
        $term=trim($term);if($term==='')return '';
        $payload=['query'=>['market'=>$this->travelSetting('market','US'),'locale'=>$this->travelSetting('locale','en-US'),'searchTerm'=>$term,'includedEntityTypes'=>['PLACE_TYPE_CITY','PLACE_TYPE_AIRPORT']],'limit'=>5,'isDestination'=>$destination];
        [$status,$body]=$this->httpJson('https://partners.api.skyscanner.net/apiservices/v3/autosuggest/flights',['x-api-key: '.$key],$payload,20);if($status<200||$status>=300)return '';$json=json_decode($body,true)?:[];
        foreach(($json['places']??[]) as $place){$iata=strtoupper(trim((string)($place['iataCode']??$place['airportInformation']['iataCode']??'')));if(preg_match('/^[A-Z]{3}$/',$iata))return $iata;}
        return '';
    }

    private function flightLeg(string $origin,string $destination,string $date): array
    {
        $leg=['originPlace'=>['queryPlace'=>['iata'=>$origin]],'destinationPlace'=>['queryPlace'=>['iata'=>$destination]]];
        if($date!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)&&$date>=date('Y-m-d')){$d=new DateTimeImmutable($date);$leg['fixedDate']=['year'=>(int)$d->format('Y'),'month'=>(int)$d->format('n'),'day'=>(int)$d->format('j')];}else{$leg['anytime']=true;}
        return $leg;
    }

    private function weatherWindow(array $trip): array
    {
        $today=new DateTimeImmutable('today');$max=$today->modify('+14 days');$startRaw=(string)($trip['start_date']??'');$endRaw=(string)($trip['end_date']??'');
        try{$start=$startRaw!==''?new DateTimeImmutable($startRaw):$today;}catch(Throwable){$start=$today;}
        try{$end=$endRaw!==''?new DateTimeImmutable($endRaw):$start->modify('+7 days');}catch(Throwable){$end=$start->modify('+7 days');}
        if($start<$today)$start=$today;if($start>$max)return [$today->format('Y-m-d'),$max->format('Y-m-d'),'historical_outlook'];if($end>$max)$end=$max;if($end<$start)$end=$start;
        return [$start->format('Y-m-d'),$end->format('Y-m-d'),'live_forecast'];
    }

    private function eventWindow(array $trip): array
    {
        $today=new DateTimeImmutable('today');$startRaw=(string)($trip['start_date']??'');$endRaw=(string)($trip['end_date']??'');try{$start=$startRaw!==''?new DateTimeImmutable($startRaw):$today;}catch(Throwable){$start=$today;}try{$end=$endRaw!==''?new DateTimeImmutable($endRaw):$start->modify('+30 days');}catch(Throwable){$end=$start->modify('+30 days');}if($end<$start)$end=$start->modify('+7 days');return [$start->format('Y-m-d'),$end->format('Y-m-d')];
    }

    private function normalizeVcDay(array $day): array
    {
        return ['date'=>(string)($day['datetime']??''),'high'=>isset($day['tempmax'])?(float)$day['tempmax']:null,'low'=>isset($day['tempmin'])?(float)$day['tempmin']:null,'conditions'=>(string)($day['conditions']??''),'description'=>(string)($day['description']??''),'precip_probability'=>isset($day['precipprob'])?(float)$day['precipprob']:null,'precip'=>isset($day['precip'])?(float)$day['precip']:null,'wind'=>isset($day['windspeed'])?(float)$day['windspeed']:null,'humidity'=>isset($day['humidity'])?(float)$day['humidity']:null,'uv'=>isset($day['uvindex'])?(float)$day['uvindex']:null,'sunrise'=>(string)($day['sunrise']??''),'sunset'=>(string)($day['sunset']??'')];
    }

    private function priceAmount(array $node): ?float
    {
        if(isset($node['price']['amount'])&&is_numeric($node['price']['amount']))return (float)$node['price']['amount'];if(isset($node['minPrice']['amount'])&&is_numeric($node['minPrice']['amount']))return (float)$node['minPrice']['amount'];foreach($node as $value){if(is_array($value)){$found=$this->priceAmount($value);if($found!==null)return $found;}}return null;
    }

    private function placeCategory(string $primary,array $types): string
    {
        $all=array_unique(array_merge([$primary],$types));if(array_intersect($all,['restaurant','cafe','bakery']))return 'Eat';if(array_intersect($all,['bar','night_club']))return 'Drink & Nightlife';if(array_intersect($all,['museum','art_gallery']))return 'Culture';if(array_intersect($all,['park','tourist_attraction','amusement_park','zoo']))return 'Attractions';return 'Local';
    }

    private function destinationName(array $trip): string
    {
        return trim((string)($trip['destination_name']??$trip['destination']??''));
    }

    private function travelSetting(string $name,string $default=''): string
    {
        $travel=is_array($this->config['travel']??null)?$this->config['travel']:[];$value=trim((string)($travel[$name]??''));return $value!==''?$value:$default;
    }

    private function key(string $provider): string
    {
        $travel=is_array($this->config['travel']??null)?$this->config['travel']:[];$map=['visual_crossing'=>'visual_crossing_key','ticketmaster'=>'ticketmaster_key','google_places'=>'google_places_key','skyscanner'=>'skyscanner_key'];$env=['visual_crossing'=>'VISUAL_CROSSING_API_KEY','ticketmaster'=>'TICKETMASTER_API_KEY','google_places'=>'GOOGLE_PLACES_API_KEY','skyscanner'=>'SKYSCANNER_API_KEY'];$value=trim((string)($travel[$map[$provider]??'']??''));if($value==='')$value=trim((string)(getenv($env[$provider]??'')?:''));return $value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if($value===null||$value===''||!is_numeric($value))return null;return (float)$value;
    }

    private function failure(string $type,string $provider,string $message): array
    {
        return ['ok'=>false,'data_type'=>$type,'provider'=>$provider,'source_status'=>'unavailable','observed_at'=>gmdate('c'),'expires_in'=>900,'error'=>$this->clip($message,500)];
    }

    private function apiError(string $body): string
    {
        $json=json_decode($body,true);if(!is_array($json))return '';foreach([['error','message'],['status','message'],['fault','faultstring']] as $path){$v=$json[$path[0]][$path[1]]??null;if(is_string($v)&&$v!=='')return $v;}return '';
    }

    private function clip(string $value,int $max): string
    {
        $value=trim($value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }

    private function httpGet(string $url,array $headers=[],int $timeout=20): array
    {
        if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.27']);$body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,is_string($body)?$body:'',$error];}
        $context=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.27",'timeout'=>$timeout,'ignore_errors'=>true]]);$body=@file_get_contents($url,false,$context);$status=0;foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return [$status,is_string($body)?$body:'',$body===false?'HTTP request failed.':''];
    }

    private function httpJson(string $url,array $headers,array $payload,int $timeout=20): array
    {
        $headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.27']);$response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,is_string($response)?$response:'',$error];}
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.27",'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);$response=@file_get_contents($url,false,$context);$status=0;foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return [$status,is_string($response)?$response:'',$response===false?'HTTP request failed.':''];
    }
}
