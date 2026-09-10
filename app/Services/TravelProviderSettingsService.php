<?php
declare(strict_types=1);

final class TravelProviderSettingsService
{
    private const PROVIDERS=['visual_crossing','ticketmaster','google_places','skyscanner'];
    private array $config;

    public function __construct(private PDO $pdo)
    {
        global $config;$this->config=is_array($config??null)?$config:[];
    }

    public function ready(): bool{return db_table_exists('travel_provider_settings');}

    public function all(): array
    {
        if(!$this->ready())return [];$rows=$this->pdo->query('SELECT * FROM travel_provider_settings ORDER BY FIELD(provider,"visual_crossing","ticketmaster","google_places","skyscanner"),provider')->fetchAll()?:[];foreach($rows as &$row){$row=$this->decorate($row,false);}unset($row);return $rows;
    }

    public function get(string $provider,bool $withSecret=false): ?array
    {
        if(!$this->ready()||!in_array($provider,self::PROVIDERS,true))return null;$stmt=$this->pdo->prepare('SELECT * FROM travel_provider_settings WHERE provider=? LIMIT 1');$stmt->execute([$provider]);$row=$stmt->fetch();return $row?$this->decorate($row,$withSecret):null;
    }

    public function save(string $provider,array $input,int $adminId): void
    {
        if(!in_array($provider,self::PROVIDERS,true))throw new InvalidArgumentException('Unknown travel provider.');if(!$this->ready())throw new RuntimeException('Run System Upgrade first.');$existing=$this->get($provider,true);if(!$existing)throw new RuntimeException('Travel provider settings are missing.');$key=trim((string)($input['api_key']??''));$encrypted=null;if($key!=='')$encrypted=$this->encrypt($key);elseif(!empty($existing['api_key'])){$stmt=$this->pdo->prepare('SELECT api_key_encrypted FROM travel_provider_settings WHERE provider=?');$stmt->execute([$provider]);$encrypted=$stmt->fetchColumn()?:null;}$settings=$existing['settings']??[];foreach(['market','locale','currency'] as $name)if(array_key_exists($name,$input))$settings[$name]=trim((string)$input[$name]);$enabled=!empty($input['enabled'])?1:0;$stmt=$this->pdo->prepare('UPDATE travel_provider_settings SET api_key_encrypted=?,enabled=?,settings_json=?,updated_by=?,updated_at=NOW() WHERE provider=?');$stmt->execute([$encrypted,$enabled,json_encode($settings,JSON_UNESCAPED_SLASHES),$adminId,$provider]);
    }

    public function clear(string $provider,int $adminId): void
    {
        if(!in_array($provider,self::PROVIDERS,true))throw new InvalidArgumentException('Unknown travel provider.');$stmt=$this->pdo->prepare('UPDATE travel_provider_settings SET api_key_encrypted=NULL,enabled=0,last_test_status="never",last_tested_at=NULL,last_error=NULL,updated_by=? WHERE provider=?');$stmt->execute([$adminId,$provider]);
    }

    public function test(string $provider,int $adminId): array
    {
        $row=$this->get($provider,true);if(!$row||empty($row['api_key']))throw new RuntimeException('Save an API key before testing.');$key=(string)$row['api_key'];$status=0;$body='';$transport='';
        if($provider==='visual_crossing')[$status,$body,$transport]=$this->httpGet('https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/Phoenix?unitGroup=us&include=current&contentType=json&key='.rawurlencode($key),['Accept: application/json']);
        elseif($provider==='ticketmaster')[$status,$body,$transport]=$this->httpGet('https://app.ticketmaster.com/discovery/v2/events.json?apikey='.rawurlencode($key).'&size=1',['Accept: application/json']);
        elseif($provider==='google_places')[$status,$body,$transport]=$this->httpJson('https://places.googleapis.com/v1/places:searchText',['X-Goog-Api-Key: '.$key,'X-Goog-FieldMask: places.id'],['textQuery'=>'Phoenix Arizona','pageSize'=>1]);
        elseif($provider==='skyscanner')[$status,$body,$transport]=$this->httpJson('https://partners.api.skyscanner.net/apiservices/v3/autosuggest/flights',['x-api-key: '.$key],['query'=>['market'=>(string)($row['settings']['market']??'US'),'locale'=>(string)($row['settings']['locale']??'en-US'),'searchTerm'=>'Phoenix']]);
        $ok=$status>=200&&$status<300;$message=$ok?'Connection verified.':($transport?:$this->apiError($body)?:'Provider returned HTTP '.$status.'.');$message=$this->clip($message,500);$stmt=$this->pdo->prepare('UPDATE travel_provider_settings SET last_test_status=?,last_tested_at=NOW(),last_error=?,updated_by=? WHERE provider=?');$stmt->execute([$ok?'success':'failed',$ok?null:$message,$adminId,$provider]);return ['success'=>$ok,'message'=>$message,'status'=>$status];
    }

    private function decorate(array $row,bool $withSecret): array
    {
        $settings=json_decode((string)($row['settings_json']??''),true);$row['settings']=is_array($settings)?$settings:[];$row['has_key']=!empty($row['api_key_encrypted']);if($withSecret)$row['api_key']=$row['has_key']?$this->decrypt((string)$row['api_key_encrypted']):'';$row['masked_key']=$row['has_key']?$this->mask($this->decrypt((string)$row['api_key_encrypted'])):'';unset($row['api_key_encrypted'],$row['settings_json']);return $row;
    }

    private function cryptoKey(): string{$material=(string)($this->config['app']['internal_key']??'');if($material===''){$db=$this->config['db']??[];$material=implode('|',[(string)($db['host']??''),(string)($db['name']??''),(string)($db['user']??''),(string)($db['pass']??''),(string)($this->config['app']['base_url']??'')]);}return hash('sha256',$material,true);}
    private function encrypt(string $plain): string{if(!function_exists('openssl_encrypt'))throw new RuntimeException('PHP OpenSSL is required to securely store API keys.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Could not encrypt API key.');return 'v1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($cipher);}
    private function decrypt(string $payload): string{if(!function_exists('openssl_decrypt')||!str_starts_with($payload,'v1.'))return '';$parts=explode('.',$payload,4);if(count($parts)!==4)return '';$plain=openssl_decrypt((string)base64_decode($parts[3],true),'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,(string)base64_decode($parts[1],true),(string)base64_decode($parts[2],true));return $plain===false?'':$plain;}
    private function mask(string $key): string{return $key===''?'Saved':'••••••••'.substr($key,-4);}
    private function apiError(string $body): string{$json=json_decode($body,true);if(!is_array($json))return '';foreach([['error','message'],['status','message'],['fault','faultstring']] as $path){$v=$json[$path[0]][$path[1]]??null;if(is_string($v)&&$v!=='')return $v;}return '';}
    private function clip(string $value,int $max): string{return function_exists('mb_substr')?mb_substr(trim($value),0,$max):substr(trim($value),0,$max);}
    private function httpGet(string $url,array $headers): array{if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.27']);$body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return[$status,is_string($body)?$body:'',$error];}$ctx=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers),'timeout'=>15,'ignore_errors'=>true]]);$body=@file_get_contents($url,false,$ctx);$status=0;foreach(($http_response_header??[])as$line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return[$status,is_string($body)?$body:'',$body===false?'HTTP request failed.':''];}
    private function httpJson(string $url,array $headers,array $payload): array{$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.27']);$response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return[$status,is_string($response)?$response:'',$error];}$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>15,'ignore_errors'=>true]]);$response=@file_get_contents($url,false,$ctx);$status=0;foreach(($http_response_header??[])as$line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}return[$status,is_string($response)?$response:'',$response===false?'HTTP request failed.':''];}
}
