<?php
declare(strict_types=1);

final class AiProviderService
{
    private PDO $pdo;
    private array $config;

    public function __construct(?PDO $pdo = null)
    {
        global $config;
        $this->pdo = $pdo ?: db();
        $this->config = is_array($config ?? null) ? $config : [];
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT provider,display_name,model_name,enabled,is_default_chat,is_default_voice,settings_json,last_test_status,last_tested_at,last_error,updated_at,api_key_encrypted FROM ai_provider_settings ORDER BY FIELD(provider,"openai","anthropic","elevenlabs"),provider')->fetchAll();
        foreach ($rows as &$row) {
            $row['has_key'] = !empty($row['api_key_encrypted']);
            $row['masked_key'] = $row['has_key'] ? $this->maskedKey((string)$row['api_key_encrypted']) : '';
            unset($row['api_key_encrypted']);
            $decoded = json_decode((string)($row['settings_json'] ?? ''), true);
            $row['settings'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    }

    public function get(string $provider, bool $withSecret = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_provider_settings WHERE provider=? LIMIT 1');
        $stmt->execute([$provider]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $decoded = json_decode((string)($row['settings_json'] ?? ''), true);
        $row['settings'] = is_array($decoded) ? $decoded : [];
        $row['has_key'] = !empty($row['api_key_encrypted']);
        if ($withSecret) {
            $row['api_key'] = $row['has_key'] ? $this->decrypt((string)$row['api_key_encrypted']) : '';
        }
        unset($row['api_key_encrypted']);
        return $row;
    }

    public function save(string $provider, array $input, int $adminId): void
    {
        if (!in_array($provider, ['openai','anthropic','elevenlabs'], true)) throw new InvalidArgumentException('Unknown AI provider.');
        $existing = $this->get($provider, true);
        if (!$existing) throw new RuntimeException('Provider settings are missing.');

        $key = trim((string)($input['api_key'] ?? ''));
        $encrypted = null;
        if ($key !== '') {
            $encrypted = $this->encrypt($key);
        } elseif (!empty($existing['api_key'])) {
            $stmt = $this->pdo->prepare('SELECT api_key_encrypted FROM ai_provider_settings WHERE provider=?');
            $stmt->execute([$provider]);
            $encrypted = $stmt->fetchColumn() ?: null;
        }

        $model = trim((string)($input['model_name'] ?? ''));
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $isDefaultChat = !empty($input['is_default_chat']) && $provider !== 'elevenlabs' ? 1 : 0;
        $isDefaultVoice = !empty($input['is_default_voice']) && $provider === 'elevenlabs' ? 1 : 0;
        $settings = $existing['settings'] ?? [];
        if ($provider === 'elevenlabs') {
            $settings['voice_id'] = trim((string)($input['voice_id'] ?? ($settings['voice_id'] ?? '')));
        }

        $this->pdo->beginTransaction();
        try {
            if ($isDefaultChat) $this->pdo->exec('UPDATE ai_provider_settings SET is_default_chat=0 WHERE provider IN ("openai","anthropic")');
            if ($isDefaultVoice) $this->pdo->exec('UPDATE ai_provider_settings SET is_default_voice=0 WHERE provider="elevenlabs"');
            $stmt = $this->pdo->prepare('UPDATE ai_provider_settings SET api_key_encrypted=?,model_name=?,enabled=?,is_default_chat=?,is_default_voice=?,settings_json=?,updated_by=? WHERE provider=?');
            $stmt->execute([$encrypted,$model,$enabled,$isDefaultChat,$isDefaultVoice,json_encode($settings, JSON_UNESCAPED_SLASHES),$adminId,$provider]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function clearKey(string $provider, int $adminId): void
    {
        $stmt = $this->pdo->prepare('UPDATE ai_provider_settings SET api_key_encrypted=NULL,enabled=0,last_test_status="never",last_tested_at=NULL,last_error=NULL,updated_by=? WHERE provider=?');
        $stmt->execute([$adminId,$provider]);
    }

    public function test(string $provider, int $adminId): array
    {
        $row = $this->get($provider, true);
        if (!$row || empty($row['api_key'])) throw new RuntimeException('Save an API key before testing this provider.');
        $key = (string)$row['api_key'];
        $headers = ['Accept: application/json'];
        $url = '';
        if ($provider === 'openai') {
            $url = 'https://api.openai.com/v1/models';
            $headers[] = 'Authorization: Bearer '.$key;
        } elseif ($provider === 'anthropic') {
            $url = 'https://api.anthropic.com/v1/models?limit=1';
            $headers[] = 'x-api-key: '.$key;
            $headers[] = 'anthropic-version: 2023-06-01';
        } elseif ($provider === 'elevenlabs') {
            $url = 'https://api.elevenlabs.io/v1/user';
            $headers[] = 'xi-api-key: '.$key;
        } else {
            throw new InvalidArgumentException('Unknown provider.');
        }

        [$status,$body,$transportError] = $this->httpGet($url,$headers);
        $ok = $status >= 200 && $status < 300;
        $message = $ok ? 'Connection verified.' : ($transportError ?: $this->extractApiError($body) ?: ('Provider returned HTTP '.$status.'.'));
        $message = function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
        $state = $ok ? 'success' : 'failed';

        $stmt = $this->pdo->prepare('UPDATE ai_provider_settings SET last_test_status=?,last_tested_at=NOW(),last_error=?,updated_by=? WHERE provider=?');
        $stmt->execute([$state,$ok?null:$message,$adminId,$provider]);
        $stmt = $this->pdo->prepare('INSERT INTO ai_provider_test_log (provider,tested_by,status,http_status,message) VALUES (?,?,?,?,?)');
        $stmt->execute([$provider,$adminId,$state,$status ?: null,$message]);
        return ['success'=>$ok,'status'=>$status,'message'=>$message];
    }

    public function generateText(string $system, string $input, ?int $userId = null, string $purpose = 'agent_chat', int $maxOutputTokens = 500): ?string
    {
        $provider = $this->defaultChatProvider();
        if (!$provider || empty($provider['api_key']) || empty($provider['model_name'])) return null;
        $name = (string)$provider['provider'];
        $model = (string)$provider['model_name'];
        $key = (string)$provider['api_key'];
        $status = 0; $body = ''; $transport = ''; $text = null; $inputUnits = null; $outputUnits = null;
        try {
            if ($name === 'openai') {
                [$status,$body,$transport] = $this->httpJson('https://api.openai.com/v1/responses',[
                    'Authorization: Bearer '.$key,
                ],[
                    'model'=>$model,
                    'instructions'=>$system,
                    'input'=>$input,
                    'max_output_tokens'=>max(64,min(2000,$maxOutputTokens)),
                    'store'=>false,
                ]);
                if ($status >= 200 && $status < 300) {
                    $json=json_decode($body,true) ?: [];
                    $text=is_string($json['output_text']??null)?trim($json['output_text']):$this->openAiOutputText($json);
                    $inputUnits=isset($json['usage']['input_tokens'])?(int)$json['usage']['input_tokens']:null;
                    $outputUnits=isset($json['usage']['output_tokens'])?(int)$json['usage']['output_tokens']:null;
                }
            } elseif ($name === 'anthropic') {
                [$status,$body,$transport] = $this->httpJson('https://api.anthropic.com/v1/messages',[
                    'x-api-key: '.$key,
                    'anthropic-version: 2023-06-01',
                ],[
                    'model'=>$model,
                    'max_tokens'=>max(64,min(2000,$maxOutputTokens)),
                    'system'=>$system,
                    'messages'=>[['role'=>'user','content'=>$input]],
                ]);
                if ($status >= 200 && $status < 300) {
                    $json=json_decode($body,true) ?: [];
                    $parts=[]; foreach(($json['content']??[]) as $block) if(is_array($block)&&($block['type']??'')==='text'&&isset($block['text'])) $parts[]=(string)$block['text'];
                    $text=trim(implode("\n",$parts));
                    $inputUnits=isset($json['usage']['input_tokens'])?(int)$json['usage']['input_tokens']:null;
                    $outputUnits=isset($json['usage']['output_tokens'])?(int)$json['usage']['output_tokens']:null;
                }
            }
            if (!$text) {
                $error=$transport ?: $this->extractApiError($body) ?: ('Provider returned HTTP '.$status.'.');
                $this->logUsage($name,$userId,$purpose,$model,$inputUnits,$outputUnits,false,$error);
                return null;
            }
            $this->logUsage($name,$userId,$purpose,$model,$inputUnits,$outputUnits,true,null);
            return $text;
        } catch (Throwable $e) {
            $this->logUsage($name,$userId,$purpose,$model,$inputUnits,$outputUnits,false,$e->getMessage());
            return null;
        }
    }

    /**
     * Run a destination/web research request through the configured default LLM.
     * OpenAI uses the Responses API web_search tool; Anthropic uses its server-side
     * web search tool. The model is instructed to return one JSON object so the
     * result can be saved and normalized into Vacation Brain's destination catalog.
     */
    public function generateResearchJson(string $system, string $input, ?int $userId = null, string $purpose = 'destination_research', int $maxOutputTokens = 6000): ?array
    {
        $provider = $this->defaultChatProvider();
        if (!$provider || empty($provider['api_key']) || empty($provider['model_name'])) return null;
        $name=(string)$provider['provider'];$model=(string)$provider['model_name'];$key=(string)$provider['api_key'];
        $status=0;$body='';$transport='';$text=null;$inputUnits=null;$outputUnits=null;
        try {
            if ($name==='openai') {
                [$status,$body,$transport]=$this->httpJson('https://api.openai.com/v1/responses',[
                    'Authorization: Bearer '.$key,
                ],[
                    'model'=>$model,
                    'instructions'=>$system,
                    'input'=>$input,
                    'tools'=>[['type'=>'web_search']],
                    'max_output_tokens'=>max(1200,min(12000,$maxOutputTokens)),
                    'store'=>false,
                ],75);
                if($status>=200&&$status<300){$json=json_decode($body,true)?:[];$text=is_string($json['output_text']??null)?trim($json['output_text']):$this->openAiOutputText($json);$inputUnits=isset($json['usage']['input_tokens'])?(int)$json['usage']['input_tokens']:null;$outputUnits=isset($json['usage']['output_tokens'])?(int)$json['usage']['output_tokens']:null;}
            } elseif ($name==='anthropic') {
                [$status,$body,$transport]=$this->httpJson('https://api.anthropic.com/v1/messages',[
                    'x-api-key: '.$key,
                    'anthropic-version: 2023-06-01',
                ],[
                    'model'=>$model,
                    'max_tokens'=>max(1200,min(12000,$maxOutputTokens)),
                    'system'=>$system,
                    'messages'=>[['role'=>'user','content'=>$input]],
                    'tools'=>[['type'=>'web_search_20250305','name'=>'web_search','max_uses'=>10]],
                ],75);
                if($status>=200&&$status<300){$json=json_decode($body,true)?:[];$parts=[];foreach(($json['content']??[]) as $block){if(is_array($block)&&($block['type']??'')==='text'&&isset($block['text']))$parts[]=(string)$block['text'];}$text=trim(implode("\n",$parts));$inputUnits=isset($json['usage']['input_tokens'])?(int)$json['usage']['input_tokens']:null;$outputUnits=isset($json['usage']['output_tokens'])?(int)$json['usage']['output_tokens']:null;}
            }
            if(!$text){$error=$transport?:$this->extractApiError($body)?:('Provider returned HTTP '.$status.'.');$this->logUsage($name,$userId,$purpose,$model,$inputUnits,$outputUnits,false,$error);return null;}
            $decoded=$this->extractJsonObject($text);
            if(!is_array($decoded)){$this->logUsage($name,$userId,$purpose,$model,$inputUnits,$outputUnits,false,'The research provider did not return valid JSON.');return null;}
            $this->logUsage($name,$userId,$purpose,$model,$inputUnits,$outputUnits,true,null);
            return ['data'=>$decoded,'provider'=>$name,'model'=>$model,'raw_text'=>$text];
        } catch(Throwable $e){$this->logUsage($name,$userId,$purpose,$model,$inputUnits,$outputUnits,false,$e->getMessage());return null;}
    }

    private function extractJsonObject(string $text): ?array
    {
        $text=trim($text);
        if(str_starts_with($text,'```')){$text=preg_replace('/^```(?:json)?\s*/i','',$text)??$text;$text=preg_replace('/\s*```$/','',$text)??$text;}
        $decoded=json_decode($text,true);if(is_array($decoded))return $decoded;
        $start=strpos($text,'{');$end=strrpos($text,'}');
        if($start===false||$end===false||$end<=$start)return null;
        $decoded=json_decode(substr($text,$start,$end-$start+1),true);
        return is_array($decoded)?$decoded:null;
    }

    private function openAiOutputText(array $json): ?string
    {
        $parts=[];
        foreach(($json['output']??[]) as $item){
            if(!is_array($item)) continue;
            foreach(($item['content']??[]) as $block){
                if(is_array($block)&&in_array(($block['type']??''),['output_text','text'],true)&&isset($block['text'])) $parts[]=(string)$block['text'];
            }
        }
        $text=trim(implode("\n",$parts));
        return $text!==''?$text:null;
    }

    private function logUsage(string $provider, ?int $userId, string $purpose, string $model, ?int $inputUnits, ?int $outputUnits, bool $success, ?string $error): void
    {
        try{
            $stmt=$this->pdo->prepare('INSERT INTO ai_provider_usage_log (provider,user_id,purpose,model_name,input_units,output_units,success,error_message) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$provider,$userId,$purpose,$model,$inputUnits,$outputUnits,$success?1:0,$error?substr($error,0,500):null]);
        }catch(Throwable){/* Never break the user experience because usage logging failed. */}
    }

    private function httpJson(string $url, array $headers, array $payload, int $timeout = 30): array
    {
        $headers[]='Content-Type: application/json';
        $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(function_exists('curl_init')){
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.16']);
            $response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
            return [$status,is_string($response)?$response:'',$error];
        }
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.16",'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);
        $response=@file_get_contents($url,false,$context);$status=0;
        foreach(($http_response_header??[]) as $line) if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}
        return [$status,is_string($response)?$response:'',$response===false?'HTTP request failed.':''];
    }

    public function defaultChatProvider(): ?array
    {
        $stmt = $this->pdo->query('SELECT provider FROM ai_provider_settings WHERE enabled=1 AND is_default_chat=1 AND api_key_encrypted IS NOT NULL LIMIT 1');
        $provider = $stmt->fetchColumn();
        return $provider ? $this->get((string)$provider, true) : null;
    }

    public function voiceProvider(): ?array
    {
        $stmt = $this->pdo->query('SELECT provider FROM ai_provider_settings WHERE provider="elevenlabs" AND enabled=1 AND api_key_encrypted IS NOT NULL LIMIT 1');
        return $stmt->fetchColumn() ? $this->get('elevenlabs', true) : null;
    }

    private function cryptoKey(): string
    {
        $material = (string)($this->config['app']['internal_key'] ?? '');
        if ($material === '') {
            $db = $this->config['db'] ?? [];
            $material = implode('|', [(string)($db['host']??''),(string)($db['name']??''),(string)($db['user']??''),(string)($db['pass']??''),(string)($this->config['app']['base_url']??'')]);
        }
        return hash('sha256', $material, true);
    }

    private function encrypt(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL is required to securely store API keys.');
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Could not encrypt the API key.');
        return 'v1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($cipher);
    }

    private function decrypt(string $payload): string
    {
        if (!function_exists('openssl_decrypt')) return '';
        if (!str_starts_with($payload, 'v1.')) return '';
        $parts = explode('.', $payload, 4);
        if (count($parts) !== 4) return '';
        [$v,$iv64,$tag64,$cipher64] = $parts;
        $plain = openssl_decrypt((string)base64_decode($cipher64,true), 'aes-256-gcm', $this->cryptoKey(), OPENSSL_RAW_DATA, (string)base64_decode($iv64,true), (string)base64_decode($tag64,true));
        return $plain === false ? '' : $plain;
    }

    private function maskedKey(string $encrypted): string
    {
        $key = $this->decrypt($encrypted);
        if ($key === '') return 'Saved';
        $tail = substr($key, -4);
        return '••••••••'.$tail;
    }

    private function httpGet(string $url, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.16']);
            $body = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
            return [$status,is_string($body)?$body:'',$error];
        }
        $context = stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.16",'timeout'=>12,'ignore_errors'=>true]]);
        $body = @file_get_contents($url,false,$context); $status=0;
        foreach (($http_response_header ?? []) as $line) if (preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)) {$status=(int)$m[1];break;}
        return [$status,is_string($body)?$body:'',$body===false?'HTTP request failed.':''];
    }

    private function extractApiError(string $body): string
    {
        $json = json_decode($body,true);
        if (!is_array($json)) return '';
        foreach ([['error','message'],['detail','message']] as $path) {
            if (isset($json[$path[0]][$path[1]]) && is_string($json[$path[0]][$path[1]])) return $json[$path[0]][$path[1]];
        }
        if (isset($json['detail']) && is_string($json['detail'])) return $json['detail'];
        return '';
    }
}
