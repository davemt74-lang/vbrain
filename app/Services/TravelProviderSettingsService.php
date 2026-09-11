<?php
declare(strict_types=1);

final class TravelProviderSettingsService
{
    private const CATALOG=[
        'visual_crossing'=>['name'=>'Visual Crossing','capability'=>'weather','config_key'=>'visual_crossing_key','env'=>'VISUAL_CROSSING_API_KEY'],
        'ticketmaster'=>['name'=>'Ticketmaster Discovery','capability'=>'events','config_key'=>'ticketmaster_key','env'=>'TICKETMASTER_API_KEY'],
        'google_places'=>['name'=>'Google Places','capability'=>'places','config_key'=>'google_places_key','env'=>'GOOGLE_PLACES_API_KEY'],
        'skyscanner'=>['name'=>'Skyscanner','capability'=>'flights','config_key'=>'skyscanner_key','env'=>'SKYSCANNER_API_KEY'],
        'aviationstack'=>['name'=>'Aviationstack','capability'=>'flights','config_key'=>'aviationstack_key','env'=>'AVIATIONSTACK_API_KEY'],
        'booking_com'=>['name'=>'Booking.com Demand API','capability'=>'lodging','config_key'=>'booking_com_token','env'=>'BOOKING_COM_API_TOKEN'],
    ];

    private array $config;
    public function __construct(private PDO $pdo)
    {
        global $config;$this->config=is_array($config??null)?$config:[];
    }

    public function ready(): bool{return db_table_exists('travel_provider_settings')&&db_table_exists('travel_provider_usage_log');}
    public function catalog(): array{return self::CATALOG;}

    public function all(): array
    {
        $rows=[];$stored=[];
        if($this->ready()){
            $q=$this->pdo->query('SELECT provider,display_name,capability,enabled,priority,settings_json,last_test_status,last_tested_at,last_error,updated_at,api_key_encrypted FROM travel_provider_settings ORDER BY capability,priority,provider');
            foreach($q->fetchAll()?:[] as $row)$stored[(string)$row['provider']]=$row;
        }
        foreach(self::CATALOG as $provider=>$meta){
            $row=$stored[$provider]??['provider'=>$provider,'display_name'=>$meta['name'],'capability'=>$meta['capability'],'enabled'=>0,'priority'=>100,'settings_json'=>'{}','last_test_status'=>'never','last_tested_at'=>null,'last_error'=>null,'updated_at'=>null,'api_key_encrypted'=>null];
            $settings=json_decode((string)($row['settings_json']??''),true);$row['settings']=is_array($settings)?$settings:[];
            $row['has_saved_key']=!empty($row['api_key_encrypted']);$row['masked_key']=$row['has_saved_key']?$this->maskedKey((string)$row['api_key_encrypted']):'';
            $fallback=$this->fallbackKey($provider);$row['has_config_key']=$fallback!=='';$row['configured']=$this->effectiveKey($provider)!=='';
            $row['credential_source']=$row['has_saved_key']&&((int)$row['enabled']===1)?'admin':($fallback!==''?'config':'none');
            unset($row['api_key_encrypted'],$row['settings_json']);$rows[]=$row;
        }
        return $rows;
    }

    public function get(string $provider,bool $withSecret=false): ?array
    {
        $this->assertProvider($provider);$row=null;
        if($this->ready()){$q=$this->pdo->prepare('SELECT * FROM travel_provider_settings WHERE provider=? LIMIT 1');$q->execute([$provider]);$row=$q->fetch()?:null;}
        $meta=self::CATALOG[$provider];
        if(!$row)$row=['provider'=>$provider,'display_name'=>$meta['name'],'capability'=>$meta['capability'],'enabled'=>0,'priority'=>100,'settings_json'=>'{}','api_key_encrypted'=>null,'last_test_status'=>'never','last_tested_at'=>null,'last_error'=>null];
        $settings=json_decode((string)($row['settings_json']??''),true);$row['settings']=is_array($settings)?$settings:[];$row['has_saved_key']=!empty($row['api_key_encrypted']);
        if($withSecret)$row['api_key']=$this->effectiveKey($provider);
        unset($row['api_key_encrypted'],$row['settings_json']);return $row;
    }

    public function effectiveKey(string $provider): string
    {
        $this->assertProvider($provider);
        if($this->ready()){
            $q=$this->pdo->prepare('SELECT api_key_encrypted,enabled FROM travel_provider_settings WHERE provider=? LIMIT 1');$q->execute([$provider]);$row=$q->fetch();
            if($row&&!empty($row['api_key_encrypted'])&&(int)$row['enabled']===1){$plain=$this->decrypt((string)$row['api_key_encrypted']);if($plain!=='')return $plain;}
        }
        return $this->fallbackKey($provider);
    }

    public function setting(string $provider,string $name,string $default=''): string
    {
        $this->assertProvider($provider);$value='';
        if($this->ready()){$q=$this->pdo->prepare('SELECT settings_json FROM travel_provider_settings WHERE provider=? LIMIT 1');$q->execute([$provider]);$settings=json_decode((string)($q->fetchColumn()?:''),true);if(is_array($settings))$value=trim((string)($settings[$name]??''));}
        if($value!=='')return $value;
        $travel=is_array($this->config['travel']??null)?$this->config['travel']:[];$aliases=['booking_com'=>['affiliate_id'=>'booking_com_affiliate_id','booker_country'=>'booking_com_booker_country','platform'=>'booking_com_platform','environment'=>'booking_com_environment']];$key=$aliases[$provider][$name]??'';
        if($key!=='')$value=trim((string)($travel[$key]??''));return $value!==''?$value:$default;
    }

    public function save(string $provider,array $input,int $adminId): void
    {
        $this->assertProvider($provider);if(!$this->ready())throw new RuntimeException('Run System Upgrade for Live Travel Providers.');$existing=$this->raw($provider);
        $key=trim((string)($input['api_key']??''));$encrypted=$key!==''?$this->encrypt($key):($existing['api_key_encrypted']??null);
        $enabled=!empty($input['enabled'])?1:0;$priority=max(1,min(999,(int)($input['priority']??($existing['priority']??100))));$settings=json_decode((string)($existing['settings_json']??''),true);if(!is_array($settings))$settings=[];
        if($provider==='booking_com'){
            $settings['affiliate_id']=$this->clip((string)($input['affiliate_id']??($settings['affiliate_id']??'')),40);
            $country=strtolower(trim((string)($input['booker_country']??($settings['booker_country']??'us'))));$settings['booker_country']=preg_match('/^[a-z]{2}$/',$country)?$country:'us';
            $platform=strtolower(trim((string)($input['platform']??($settings['platform']??'desktop'))));$settings['platform']=in_array($platform,['desktop','mobile','tablet'],true)?$platform:'desktop';
            $environment=strtolower(trim((string)($input['environment']??($settings['environment']??'production'))));$settings['environment']=$environment==='sandbox'?'sandbox':'production';
        }
        $meta=self::CATALOG[$provider];$sql='INSERT INTO travel_provider_settings (provider,display_name,capability,api_key_encrypted,enabled,priority,settings_json,updated_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),capability=VALUES(capability),api_key_encrypted=VALUES(api_key_encrypted),enabled=VALUES(enabled),priority=VALUES(priority),settings_json=VALUES(settings_json),updated_by=VALUES(updated_by),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([$provider,$meta['name'],$meta['capability'],$encrypted,$enabled,$priority,json_encode($settings,JSON_UNESCAPED_SLASHES),$adminId]);
    }

    public function clearKey(string $provider,int $adminId): void
    {
        $this->assertProvider($provider);if(!$this->ready())return;$this->pdo->prepare('UPDATE travel_provider_settings SET api_key_encrypted=NULL,enabled=0,last_test_status="never",last_tested_at=NULL,last_error=NULL,updated_by=? WHERE provider=?')->execute([$adminId,$provider]);
    }

    public function test(string $provider,int $adminId): array
    {
        $this->assertProvider($provider);$key=$this->effectiveKey($provider);if($key==='')throw new RuntimeException('Save or configure a provider credential before testing.');$status=0;$body='';$transport='';$headers=['Accept: application/json'];$started=microtime(true);
        if($provider==='visual_crossing')[$status,$body,$transport]=$this->httpGet('https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/Phoenix,AZ/today?unitGroup=us&include=current&contentType=json&key='.rawurlencode($key),$headers);
        elseif($provider==='ticketmaster')[$status,$body,$transport]=$this->httpGet('https://app.ticketmaster.com/discovery/v2/events.json?size=1&apikey='.rawurlencode($key),$headers);
        elseif($provider==='aviationstack')[$status,$body,$transport]=$this->httpGet('https://api.aviationstack.com/v1/flights?limit=1&access_key='.rawurlencode($key),$headers);
        elseif($provider==='google_places')[$status,$body,$transport]=$this->httpJson('https://places.googleapis.com/v1/places:searchText',['X-Goog-Api-Key: '.$key,'X-Goog-FieldMask: places.id'],['textQuery'=>'Phoenix Arizona','pageSize'=>1]);
        elseif($provider==='skyscanner')[$status,$body,$transport]=$this->httpJson('https://partners.api.skyscanner.net/apiservices/v3/autosuggest/flights',['x-api-key: '.$key],['query'=>['market'=>'US','locale'=>'en-US','searchTerm'=>'Phoenix']]);
        else{
            $affiliate=$this->setting('booking_com','affiliate_id');if($affiliate==='')throw new RuntimeException('Booking.com Affiliate ID is required before testing.');$base=$this->setting('booking_com','environment','production')==='sandbox'?'https://demandapi-sandbox.booking.com/3.2':'https://demandapi.booking.com/3.2';$in=(new DateTimeImmutable('today'))->modify('+30 days');$out=$in->modify('+1 day');
            [$status,$body,$transport]=$this->httpJson($base.'/accommodations/search',['Authorization: Bearer '.$key,'X-Affiliate-Id: '.$affiliate],['booker'=>['country'=>$this->setting('booking_com','booker_country','us'),'platform'=>$this->setting('booking_com','platform','desktop')],'checkin'=>$in->format('Y-m-d'),'checkout'=>$out->format('Y-m-d'),'city'=>-2140479,'guests'=>['number_of_rooms'=>1,'number_of_adults'=>1],'rows'=>10]);
        }
        $ok=$status>=200&&$status<300;$message=$ok?'Connection verified.':($transport?:$this->apiError($body)?:'Provider returned HTTP '.$status.'.');$message=$this->clip($message,500);$latency=(int)round((microtime(true)-$started)*1000);
        if($this->ready()){$this->pdo->prepare('UPDATE travel_provider_settings SET last_test_status=?,last_tested_at=NOW(),last_error=?,updated_by=? WHERE provider=?')->execute([$ok?'success':'failed',$ok?null:$message,$adminId,$provider]);$this->recordUsage($provider,self::CATALOG[$provider]['capability'],null,null,$ok,$status,$latency,$ok?null:$message);}
        return ['success'=>$ok,'status'=>$status,'message'=>$message];
    }

    public function recordUsage(string $provider,string $dataType,?int $userId,?int $tripId,bool $success,?int $httpStatus=null,?int $latencyMs=null,?string $error=null): void
    {
        if(!$this->ready())return;try{$this->pdo->prepare('INSERT INTO travel_provider_usage_log (provider,data_type,user_id,dream_trip_id,success,http_status,latency_ms,error_message) VALUES (?,?,?,?,?,?,?,?)')->execute([$provider,$dataType,$userId,$tripId,$success?1:0,$httpStatus?:null,$latencyMs,$error!==null?$this->clip($error,500):null]);}catch(Throwable){}
    }

    private function raw(string $provider): array{$q=$this->pdo->prepare('SELECT * FROM travel_provider_settings WHERE provider=? LIMIT 1');$q->execute([$provider]);return $q->fetch()?:[];}
    private function fallbackKey(string $provider): string{$meta=self::CATALOG[$provider];$travel=is_array($this->config['travel']??null)?$this->config['travel']:[];$value=trim((string)($travel[$meta['config_key']]??''));if($value==='')$value=trim((string)(getenv($meta['env'])?:''));return $value;}
    private function assertProvider(string $provider): void{if(!isset(self::CATALOG[$provider]))throw new InvalidArgumentException('Unknown travel provider.');}
    private function cryptoKey(): string{$material=(string)($this->config['app']['internal_key']??'');if($material===''){$db=$this->config['db']??[];$material=implode('|',[(string)($db['host']??''),(string)($db['name']??''),(string)($db['user']??''),(string)($db['pass']??''),(string)($this->config['app']['base_url']??'')]);}return hash('sha256',$material,true);}
    private function encrypt(string $plain): string{if(!function_exists('openssl_encrypt'))throw new RuntimeException('PHP OpenSSL is required to securely store API keys.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Could not encrypt the provider credential.');return 'v1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($cipher);}
    private function decrypt(string $payload): string{if(!function_exists('openssl_decrypt')||!str_starts_with($payload,'v1.'))return '';$parts=explode('.',$payload,4);if(count($parts)!==4)return '';$plain=openssl_decrypt((string)base64_decode($parts[3],true),'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,(string)base64_decode($parts[1],true),(string)base64_decode($parts[2],true));return $plain===false?'':$plain;}
    private function maskedKey(string $encrypted): string{$key=$this->decrypt($encrypted);return $key===''?'Saved':'••••••••'.substr($key,-4);}
    private function clip(string $value,int $max): string{$value=trim($value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);}
    private function apiError(string $body): string{$json=json_decode($body,true);if(!is_array($json))return '';foreach([['error','message'],['detail','message'],['status','message']] as $path){$v=$json[$path[0]][$path[1]]??null;if(is_string($v)&&$v!=='')return $v;}if(is_string($json['error']??null))return (string)$json['error'];return '';}
    private function httpGet(string $url,array $headers=[],int $timeout=15): array{if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.38']);$body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,is_string($body)?$body:'',$error];}$context=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.38",'timeout'=>$timeout,'ignore_errors'=>true]]);$body=@file_get_contents($url,false,$context);$status=0;foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return [$status,is_string($body)?$body:'',$body===false?'HTTP request failed.':''];}
    private function httpJson(string $url,array $headers,array $payload,int $timeout=20): array{$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.38']);$response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,is_string($response)?$response:'',$error];}$context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.38",'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);$response=@file_get_contents($url,false,$context);$status=0;foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return [$status,is_string($response)?$response:'',$response===false?'HTTP request failed.':''];}
}
