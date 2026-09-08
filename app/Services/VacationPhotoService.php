<?php
declare(strict_types=1);

final class VacationPhotoService
{
    private const ALLOWED_VIBES = ['realistic','luxury','adventure','relaxed','funny','touristy'];
    private const ALLOWED_QUALITY = ['low','medium','high'];
    private const ALLOWED_SIZE = ['1024x1024','1536x1024','1024x1536'];

    public function __construct(private PDO $pdo, private string $rootDir) {}

    public function preference(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM vacation_photo_preferences WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: ['user_id'=>$userId,'ai_photo_consent'=>0,'consented_at'=>null,'revoked_at'=>null];
    }

    public function setConsent(int $userId, bool $enabled): void
    {
        $stmt=$this->pdo->prepare('INSERT INTO vacation_photo_preferences (user_id,ai_photo_consent,consented_at,revoked_at) VALUES (?,?,IF(?=1,NOW(),NULL),IF(?=0,NOW(),NULL)) ON DUPLICATE KEY UPDATE ai_photo_consent=VALUES(ai_photo_consent),consented_at=IF(VALUES(ai_photo_consent)=1,NOW(),consented_at),revoked_at=IF(VALUES(ai_photo_consent)=0,NOW(),NULL),updated_at=NOW()');
        $value=$enabled?1:0;
        $stmt->execute([$userId,$value,$value,$value]);
    }

    public function referenceCandidates(int $userId): array
    {
        $out=[];$seen=[];
        $stmt=$this->pdo->prepare('SELECT avatar_url FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$userId]);
        $avatar=trim((string)($stmt->fetchColumn() ?: ''));
        if($avatar!==''){$out[]=['key'=>'account','url'=>$avatar,'label'=>'Account photo','source'=>'account'];$seen[$avatar]=true;}

        if(db_table_exists('travel_match_profile_photos')){
            $stmt=$this->pdo->prepare('SELECT photo_url,sort_order FROM travel_match_profile_photos WHERE user_id=? ORDER BY sort_order,id');
            $stmt->execute([$userId]);
            foreach($stmt->fetchAll() as $row){$url=trim((string)$row['photo_url']);if($url===''||isset($seen[$url]))continue;$out[]=['key'=>'match:'.(int)$row['sort_order'],'url'=>$url,'label'=>'Travel Match photo '.((int)$row['sort_order']+1),'source'=>'matching'];$seen[$url]=true;}
        }

        $stmt=$this->pdo->prepare('SELECT id,photo_url,label FROM vacation_photo_references WHERE user_id=? AND active=1 ORDER BY id DESC');
        $stmt->execute([$userId]);
        foreach($stmt->fetchAll() as $row){$url=trim((string)$row['photo_url']);if($url===''||isset($seen[$url]))continue;$out[]=['key'=>'extra:'.(int)$row['id'],'url'=>$url,'label'=>trim((string)$row['label']) ?: 'Vacation Yourself reference','source'=>'extra','id'=>(int)$row['id']];$seen[$url]=true;}
        return $out;
    }

    public function addReference(int $userId, array $file, ?string $label = null): string
    {
        $upload=new UploadService($this->rootDir);
        $url=$upload->storeImage($file,$userId,'vacation-references',360,360);
        if(!$url) throw new InvalidArgumentException('Choose a reference photo first.');
        $stmt=$this->pdo->prepare('INSERT INTO vacation_photo_references (user_id,photo_url,label) VALUES (?,?,?)');
        $stmt->execute([$userId,$url,$this->cleanText((string)$label,120) ?: null]);
        return $url;
    }

    public function removeReference(int $userId, int $referenceId): void
    {
        $stmt=$this->pdo->prepare('SELECT photo_url FROM vacation_photo_references WHERE id=? AND user_id=? AND active=1 LIMIT 1');
        $stmt->execute([$referenceId,$userId]);$url=$stmt->fetchColumn();
        if(!$url) return;
        $this->pdo->prepare('UPDATE vacation_photo_references SET active=0,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$referenceId,$userId]);
        (new UploadService($this->rootDir))->deleteLocal((string)$url);
    }

    public function history(int $userId, int $limit=30): array
    {
        $limit=max(1,min(100,$limit));
        $stmt=$this->pdo->prepare('SELECT id,destination,scene,vibe,over_the_top_strength,size,quality,provider,model_name,status,image_url,error_message,created_at,completed_at FROM vacation_photo_generations WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function generate(int $userId, array $input): array
    {
        if(!site_setting_bool('vacation_photos.enabled',true)) throw new RuntimeException('Vacation Yourself is currently disabled.');
        $pref=$this->preference($userId);
        if(empty($pref['ai_photo_consent'])) throw new InvalidArgumentException('Turn on AI photo consent before generating a Vacation Yourself image.');

        $destination=$this->cleanText((string)($input['destination']??''),255);
        $scene=$this->cleanText((string)($input['scene']??''),500);
        $vibe=(string)($input['vibe']??'realistic');if(!in_array($vibe,self::ALLOWED_VIBES,true))$vibe='realistic';
        $quality=(string)($input['quality']??site_setting('vacation_photos.default_quality','medium'));if(!in_array($quality,self::ALLOWED_QUALITY,true))$quality='medium';
        $size=(string)($input['size']??'1024x1024');if(!in_array($size,self::ALLOWED_SIZE,true))$size='1024x1024';
        $overTheTop=max(0,min(100,(int)($input['over_the_top_strength']??site_setting('vacation_photos.default_over_the_top','35'))));
        if($destination==='') throw new InvalidArgumentException('Enter a destination first.');

        $candidates=$this->referenceCandidates($userId);$byKey=[];foreach($candidates as $candidate)$byKey[$candidate['key']]=$candidate;
        $requested=$input['reference_keys']??[];if(!is_array($requested))$requested=[];
        $max=max(1,min(4,(int)site_setting('vacation_photos.max_reference_images','4')));
        $selected=[];foreach($requested as $key){$key=(string)$key;if(isset($byKey[$key]))$selected[$key]=$byKey[$key];if(count($selected)>=$max)break;}
        if(!$selected && $candidates)$selected[$candidates[0]['key']]=$candidates[0];
        if(!$selected) throw new InvalidArgumentException('Add at least one clear photo of yourself first.');

        $provider=(new AiProviderService($this->pdo))->get('openai',true);
        if(!$provider || empty($provider['enabled']) || empty($provider['api_key'])) throw new RuntimeException('Vacation Yourself needs an enabled OpenAI API key in Admin → AI / API Keys.');
        $model=trim((string)site_setting('vacation_photos.model','gpt-image-2')) ?: 'gpt-image-2';
        $profile=(new VacationImageProfileService($this->pdo))->build($userId);
        $prompt=$this->buildPrompt($destination,$scene,$vibe,$overTheTop,count($selected),(string)$profile['prompt']);
        $refs=array_map(fn($r)=>['key'=>$r['key'],'url'=>$r['url'],'source'=>$r['source']],array_values($selected));
        $profileJson=json_encode($profile['profile'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

        $stmt=$this->pdo->prepare('INSERT INTO vacation_photo_generations (user_id,destination,scene,vibe,over_the_top_strength,size,quality,provider,model_name,source_refs_json,prompt_profile_hash,user_profile_snapshot_json,prompt_text,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"pending")');
        $stmt->execute([$userId,$destination,$scene?:null,$vibe,$overTheTop,$size,$quality,'openai',$model,json_encode($refs,JSON_UNESCAPED_SLASHES),$profile['hash'],$profileJson,$prompt]);
        $generationId=(int)$this->pdo->lastInsertId();

        try{
            $paths=[];foreach($selected as $row){$path=$this->localPath((string)$row['url']);if($path)$paths[]=$path;}
            if(!$paths) throw new RuntimeException('Vacation Brain could not access the selected reference photos on this server.');
            $result=$this->callOpenAi($userId,(string)$provider['api_key'],$model,$prompt,$paths,$size,$quality);
            $imageUrl=$this->saveGeneratedImage($userId,$generationId,$result['bytes']);
            $this->pdo->prepare('UPDATE vacation_photo_generations SET status="completed",image_url=?,api_request_id=?,completed_at=NOW() WHERE id=? AND user_id=?')->execute([$imageUrl,$result['request_id']?:null,$generationId,$userId]);
            return ['id'=>$generationId,'image_url'=>$imageUrl,'destination'=>$destination,'vibe'=>$vibe,'over_the_top_strength'=>$overTheTop];
        }catch(Throwable $e){
            $message=$this->cleanText($e->getMessage(),1000);
            $this->pdo->prepare('UPDATE vacation_photo_generations SET status="failed",error_message=?,completed_at=NOW() WHERE id=? AND user_id=?')->execute([$message,$generationId,$userId]);
            try{$this->pdo->prepare('INSERT INTO ai_provider_usage_log (provider,user_id,purpose,model_name,success,error_message) VALUES (?,?,?,?,0,?)')->execute(['openai',$userId,'vacation_photo',$model,substr($message,0,500)]);}catch(Throwable){}
            throw $e;
        }
    }

    private function callOpenAi(int $userId,string $apiKey,string $model,string $prompt,array $paths,string $size,string $quality): array
    {
        if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for image generation.');
        [$multipart,$boundary]=$this->multipartBody([
            'model'=>$model,
            'prompt'=>$prompt,
            'size'=>$size,
            'quality'=>$quality,
            'output_format'=>'png',
        ],$paths);
        $headers=[];$ch=curl_init('https://api.openai.com/v1/images/edits');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>[
                'Authorization: Bearer '.$apiKey,
                'Content-Type: multipart/form-data; boundary='.$boundary,
                'Content-Length: '.strlen($multipart),
            ],
            CURLOPT_POSTFIELDS=>$multipart,
            CURLOPT_TIMEOUT=>120,
            CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headers){$len=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return $len;},
        ]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$transport=curl_error($ch);curl_close($ch);
        if($body===false||$transport!=='') throw new RuntimeException('OpenAI image request failed: '.$transport);
        $json=json_decode((string)$body,true) ?: [];
        if($status<200||$status>=300){$message=(string)($json['error']['message']??('OpenAI returned HTTP '.$status.'.'));throw new RuntimeException($message);}
        $b64=(string)($json['data'][0]['b64_json']??'');if($b64==='') throw new RuntimeException('OpenAI did not return image data.');
        $bytes=base64_decode($b64,true);if($bytes===false||$bytes==='') throw new RuntimeException('Vacation Brain could not decode the generated image.');
        try{$this->pdo->prepare('INSERT INTO ai_provider_usage_log (provider,user_id,purpose,model_name,success) VALUES (?,?,?,?,1)')->execute(['openai',$userId,'vacation_photo',$model]);}catch(Throwable){}
        return ['bytes'=>$bytes,'request_id'=>$headers['x-request-id']??''];
    }

    private function multipartBody(array $fields,array $paths): array
    {
        $boundary='--------------------------'.bin2hex(random_bytes(12));$eol="\r\n";$body='';
        foreach($fields as $name=>$value){
            $body.='--'.$boundary.$eol;
            $body.='Content-Disposition: form-data; name="'.$name.'"'.$eol.$eol;
            $body.=(string)$value.$eol;
        }
        $finfo=new finfo(FILEINFO_MIME_TYPE);
        foreach($paths as $path){
            $bytes=file_get_contents($path);if($bytes===false)throw new RuntimeException('Vacation Brain could not read a selected reference photo.');
            $filename=preg_replace('/[^A-Za-z0-9._-]/','_',basename($path)) ?: 'reference.jpg';
            $mime=(string)($finfo->file($path) ?: 'image/jpeg');
            $body.='--'.$boundary.$eol;
            $body.='Content-Disposition: form-data; name="image[]"; filename="'.$filename.'"'.$eol;
            $body.='Content-Type: '.$mime.$eol.$eol;
            $body.=$bytes.$eol;
        }
        $body.='--'.$boundary.'--'.$eol;
        return [$body,$boundary];
    }

    private function saveGeneratedImage(int $userId,int $generationId,string $bytes): string
    {
        $relative='uploads/vacation-generated/'.$userId;$absolute=rtrim($this->rootDir,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        if(!is_dir($absolute)&&!mkdir($absolute,0775,true)&&!is_dir($absolute)) throw new RuntimeException('Vacation Brain could not create the generated-image folder.');
        $filename='vacation-'.$generationId.'-'.bin2hex(random_bytes(8)).'.png';$path=$absolute.DIRECTORY_SEPARATOR.$filename;
        if(file_put_contents($path,$bytes,LOCK_EX)===false) throw new RuntimeException('Vacation Brain could not save the generated image.');@chmod($path,0644);
        return app_url($relative.'/'.$filename);
    }

    private function localPath(string $url): ?string
    {
        $path=(string)(parse_url($url,PHP_URL_PATH)??'');$base=(string)(parse_url(app_url(''),PHP_URL_PATH)??'');
        if($base!==''&&$base!=='/'&&str_starts_with($path,rtrim($base,'/').'/'))$path=substr($path,strlen(rtrim($base,'/')));
        $relative=ltrim($path,'/');if($relative===''||str_contains($relative,'..'))return null;
        $full=rtrim($this->rootDir,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $real=realpath($full);$root=realpath($this->rootDir);if(!$real||!$root||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)||!is_file($real))return null;
        return $real;
    }

    private function buildPrompt(string $destination,string $scene,string $vibe,int $overTheTop,int $referenceCount,string $profilePrompt): string
    {
        $vibes=[
            'realistic'=>'natural candid travel photography',
            'luxury'=>'polished luxury travel editorial photography',
            'adventure'=>'energetic outdoor adventure photography',
            'relaxed'=>'warm relaxed vacation photography',
            'funny'=>'photorealistic but playful vacation comedy',
            'touristy'=>'enthusiastic, unmistakably tourist-on-vacation photography with destination-specific props and activities',
        ];
        $sceneText=$scene!==''?'Requested scene: '.$scene.'.':'Choose a scene that best fits the destination and the user preference profile.';
        $profilePrompt=$this->cleanText($profilePrompt,14000);
        return implode("\n\n",[
            'Create a fictional vacation photograph of the same adult shown in the '.$referenceCount.' supplied reference image(s). The reference photos are authoritative for physical identity. Preserve recognizable facial identity, approximate age, skin tone, hair, body proportions, and distinguishing appearance. Do not beautify, slim, age, de-age, or otherwise transform the person into someone else.',
            'Destination: '.$destination.'. Style: '.$vibes[$vibe].'. '.$sceneText,
            $profilePrompt,
            $this->overTheTopInstruction($overTheTop),
            'Translate the Vacation Brain profile into visible vacation choices: setting, activity, time of day, pace, comfort level, food/drink context, props, clothing context, and tourist behavior. Prefer explicit user answers and recent choices over generic assumptions. Keep the location geographically plausible. Make lighting, hands, camera perspective, clothing, and background believable even when the concept is exaggerated.',
            'Do not add text, logos, watermarks, or unrelated foreground people. Do not imply this photograph documents a real trip. This is an AI-generated entertainment image representing an imagined vacation.',
        ]);
    }

    private function overTheTopInstruction(int $strength): string
    {
        $strength=max(0,min(100,$strength));
        if($strength<=10)$direction='Keep the production completely natural, subtle, plausible, and candid. Avoid conspicuous tourist props or luxury exaggeration.';
        elseif($strength<=30)$direction='Add a lightly polished vacation feel: one or two recognizable tourist details, flattering travel atmosphere, and modest aspirational touches.';
        elseif($strength<=50)$direction='Make the vacation fantasy clearly noticeable: stronger destination cues, fun tourist props, better-than-normal scenery, and a little social-media vacation energy.';
        elseif($strength<=70)$direction='Push the vacation fantasy substantially: dramatic destination scenery, unmistakable tourist behavior, premium details, playful excess, and a scene that feels enviably over-produced while remaining photorealistic.';
        elseif($strength<=90)$direction='Make it extravagantly touristy and knowingly excessive: iconic destination cues, dramatic views, upgraded experiences, oversized vacation energy, conspicuous props, and comedic luxury or adventure appropriate to the user profile.';
        else $direction='Take the vacation fantasy to maximum believable absurdity: spectacular destination staging, comically excessive tourist energy, outrageous-but-photorealistic luxury/adventure details, and a visual punchline that still looks like a real camera captured the same person.';
        return 'OVER THE TOP STRENGTH: '.$strength.'/100. '.$direction.' The strength controls only the vacation scene and production value; never exaggerate or distort the person’s identity, face, body, age, skin tone, or other physical traits.';
    }

    private function cleanText(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
