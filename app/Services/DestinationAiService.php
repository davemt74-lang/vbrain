<?php
declare(strict_types=1);

final class DestinationAiService
{
    private const IMAGE_ROLES=['hero','card','landmark','lifestyle'];

    public function __construct(private PDO $pdo,private string $rootDir) {}

    public function create(int $adminId,array $input): array
    {
        $name=$this->clean((string)($input['destination_name']??''),255);
        $locationHint=$this->clean((string)($input['location_hint']??''),500);
        $notes=$this->clean((string)($input['admin_notes']??''),4000);
        $generateImages=!array_key_exists('generate_images',$input)||!empty($input['generate_images']);
        $publish=!empty($input['publish'])||site_setting_bool('destination_ai.auto_publish',false);
        if($name==='')throw new InvalidArgumentException('Enter a destination name.');

        $job=$this->pdo->prepare('INSERT INTO destination_ai_jobs (requested_by,destination_name,location_hint,admin_notes,job_type,status,input_json,images_requested) VALUES (?,?,?,?,"all","running",?,?)');
        $job->execute([$adminId,$name,$locationHint?:null,$notes?:null,json_encode(['name'=>$name,'location_hint'=>$locationHint,'notes'=>$notes],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$generateImages?count(self::IMAGE_ROLES):0]);
        $jobId=(int)$this->pdo->lastInsertId();

        try{
            $ai=new AiProviderService($this->pdo);
            $result=$ai->generateResearchJson(
                'You are the Vacation Brain destination catalog editor. Research the named destination and return only one JSON object matching the requested schema. Use stable, destination-specific visual and travel facts. Keep copy concise, useful, playful, and suitable for a consumer travel-discovery product. Do not invent named hotels, restaurants, events, awards, prices, or claims. Prompt vocabulary should describe authentic visual cues and common travel experiences, not fake factual claims.',
                $this->researchPrompt($name,$locationHint,$notes),
                $adminId,'destination_ai_create',5000
            );
            if(!$result||empty($result['data']))throw new RuntimeException('The configured AI provider could not build the destination profile.');
            $data=$this->normalizeProfile((array)$result['data'],$name,$locationHint);
            $destinationId=$this->insertDestination($data,$publish);
            $promptService=new DestinationPromptService($this->pdo);
            $profile=$promptService->save($destinationId,$data,$adminId,'ai',(string)($result['provider']??''),(string)($result['model']??''));
            $this->pdo->prepare('UPDATE destination_ai_jobs SET destination_catalog_id=?,provider=?,model_name=?,output_json=? WHERE id=?')->execute([$destinationId,$result['provider']??null,$result['model']??null,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$jobId]);

            $images=[];$imageError=null;
            if($generateImages){
                try{$images=$this->generateImages($destinationId,$adminId,self::IMAGE_ROLES);}catch(Throwable $e){$imageError=$this->clean($e->getMessage(),1500);}
            }
            $status=$imageError?'partial':'completed';
            $this->pdo->prepare('UPDATE destination_ai_jobs SET status=?,images_created=?,error_message=?,completed_at=NOW() WHERE id=?')->execute([$status,count($images),$imageError,$jobId]);
            return ['job_id'=>$jobId,'destination_id'=>$destinationId,'profile'=>$profile,'images'=>$images,'status'=>$status,'warning'=>$imageError];
        }catch(Throwable $e){
            $message=$this->clean($e->getMessage(),1500);
            $this->pdo->prepare('UPDATE destination_ai_jobs SET status="failed",error_message=?,completed_at=NOW() WHERE id=?')->execute([$message,$jobId]);
            throw $e;
        }
    }

    public function generateImages(int $destinationId,int $adminId,?array $roles=null): array
    {
        $promptService=new DestinationPromptService($this->pdo);$profile=$promptService->get($destinationId);$destination=$profile['destination'];
        $provider=(new AiProviderService($this->pdo))->get('openai',true);
        if(!$provider||empty($provider['enabled'])||empty($provider['api_key']))throw new RuntimeException('Destination image generation needs an enabled OpenAI API key in Admin → AI / API Keys.');
        $model=trim((string)site_setting('destination_ai.image_model','gpt-image-2'))?:'gpt-image-2';
        $quality=(string)site_setting('destination_ai.default_image_quality','medium');if(!in_array($quality,['low','medium','high'],true))$quality='medium';
        $roles=$roles?:self::IMAGE_ROLES;$roles=array_values(array_intersect(self::IMAGE_ROLES,array_map('strval',$roles)));if(!$roles)$roles=self::IMAGE_ROLES;
        $created=[];$sort=0;
        foreach($roles as $role){
            $size=$role==='card'?'1024x1024':'1536x1024';
            $prompt=$this->imagePrompt($profile,$promptService->promptText($profile),$role);
            $result=$this->callOpenAiImage((string)$provider['api_key'],$model,$prompt,$size,$quality);
            $url=$this->saveImage($destinationId,$role,$result['bytes']);
            $this->replaceGeneratedRole($destinationId,$role);
            $stmt=$this->pdo->prepare('INSERT INTO destination_gallery_images (destination_catalog_id,report_id,image_role,image_url,caption,source_name,is_sample,is_ai_generated,prompt_text,model_name,created_by,sort_order) VALUES (?,NULL,?,?,?,"Vacation Brain AI",0,1,?,?,?,?)');
            $caption='AI-generated '.($role==='hero'?'hero':str_replace('_',' ',$role)).' concept for '.(string)$destination['name'];
            $stmt->execute([$destinationId,$role,$url,$caption,$prompt,$model,$adminId,$sort]);
            if($role==='hero')$this->pdo->prepare('UPDATE destination_catalog SET hero_image_url=? WHERE id=?')->execute([$url,$destinationId]);
            $created[]=['role'=>$role,'url'=>$url,'prompt'=>$prompt,'model'=>$model];$sort+=10;
            try{$this->pdo->prepare('INSERT INTO ai_provider_usage_log (provider,user_id,purpose,model_name,success) VALUES (?,?,?,?,1)')->execute(['openai',$adminId,'destination_image',$model]);}catch(Throwable){}
        }
        return $created;
    }

    private function researchPrompt(string $name,string $locationHint,string $notes): string
    {
        return 'Create a Vacation Brain destination profile for: '.$name.'.'.($locationHint!==''?' Location hint: '.$locationHint.'.':'').($notes!==''?' Admin direction: '.$notes.'.':'')."\nReturn JSON with exactly these keys:\n".
            '{"name":"","city":"","region":"","country":"","short_description":"","description":"","best_for":"","vibe":"","prompt_summary":"","visual_keywords":[],"landmark_keywords":[],"environment_keywords":[],"activity_keywords":[],"wardrobe_keywords":[],"tourist_boost_keywords":[],"humor_keywords":[],"avoid_keywords":[],"default_scenes":[],"default_vibes":[]}'."\n".
            'Use 5–12 concise items per keyword array when appropriate. default_scenes should contain 6–10 image-ready scene ideas. tourist_boost_keywords should be playful visual exaggerations specific to this destination. humor_keywords should be visual comedy ideas, not jokes written on the image. avoid_keywords should prevent generic, geographically wrong, stereotyped, unsafe, or misleading imagery. best_for and vibe should be short comma-separated phrases.';
    }

    private function normalizeProfile(array $data,string $fallbackName,string $locationHint): array
    {
        $out=[];$out['name']=$this->clean((string)($data['name']??$fallbackName),255)?:$fallbackName;
        foreach(['city'=>160,'region'=>160,'country'=>160,'short_description'=>500,'description'=>4000,'best_for'=>500,'vibe'=>500,'prompt_summary'=>4000] as $field=>$max)$out[$field]=$this->clean((string)($data[$field]??''),$max);
        if($out['short_description']===''&&$out['prompt_summary']!=='')$out['short_description']=$this->clean($out['prompt_summary'],500);
        if($out['description']==='')$out['description']=$out['short_description'];
        foreach(['visual_keywords','landmark_keywords','environment_keywords','activity_keywords','wardrobe_keywords','tourist_boost_keywords','humor_keywords','avoid_keywords','default_scenes','default_vibes'] as $field)$out[$field]=$this->cleanList($data[$field]??[]);
        if($out['country']===''&&$locationHint!=='')$out['prompt_summary']=trim($out['prompt_summary'].' Location hint supplied by Admin: '.$locationHint.'.');
        return $out;
    }

    private function insertDestination(array $data,bool $publish): int
    {
        $slug=$this->uniqueSlug($data['name']);$status=$publish?'active':'draft';
        $stmt=$this->pdo->prepare('INSERT INTO destination_catalog (slug,name,city,region,country,short_description,description,hero_image_url,best_for,vibe,status,featured,sort_order,metadata_json) VALUES (?,?,?,?,?,?,?,NULL,?,?,?,0,0,?)');
        $stmt->execute([$slug,$data['name'],$data['city']?:null,$data['region']?:null,$data['country']?:null,$data['short_description']?:null,$data['description']?:null,$data['best_for']?:null,$data['vibe']?:null,$status,json_encode(['created_by'=>'destination_ai','prompt_profile_version'=>1],JSON_UNESCAPED_SLASHES)]);
        return (int)$this->pdo->lastInsertId();
    }

    private function uniqueSlug(string $name): string
    {
        $base=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$name),'-'));if($base==='')$base='destination';$slug=$base;$i=2;
        $stmt=$this->pdo->prepare('SELECT 1 FROM destination_catalog WHERE slug=? LIMIT 1');
        while(true){$stmt->execute([$slug]);if(!$stmt->fetchColumn())return $slug;$slug=$base.'-'.$i++;if($i>999)throw new RuntimeException('Could not create a unique destination slug.');}
    }

    private function imagePrompt(array $profile,string $profileText,string $role): string
    {
        $d=$profile['destination']??[];$name=(string)($d['name']??'destination');
        $roleDirection=match($role){
            'hero'=>'Wide cinematic destination hero image with a strong sense of place, generous negative space for website layout, no text.',
            'card'=>'Square travel-discovery card image with one immediately recognizable destination idea and clean mobile-friendly composition.',
            'landmark'=>'Travel-editorial scene centered on a geographically appropriate landmark, landscape, or signature place cue without fake signage.',
            'lifestyle'=>'Aspirational vacation lifestyle scene showing common visitor activity and atmosphere; people may appear incidentally but no identifiable real person is the subject.',
            default=>'Polished travel editorial destination image.',
        };
        return 'Create a photorealistic promotional concept image for the Vacation Brain destination profile “'.$name.'”. '.$roleDirection."\n".$profileText."\n".'Use only geographically plausible visual cues. Do not fabricate branded hotels, restaurants, venue names, signs, event posters, prices, or documentary claims. Do not add text, logos, or watermarks. This is AI-generated destination concept art for a travel discovery catalog, not evidence of a particular real property or event.';
    }

    private function callOpenAiImage(string $apiKey,string $model,string $prompt,string $size,string $quality): array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for destination image generation.');
        $payload=json_encode(['model'=>$model,'prompt'=>$prompt,'size'=>$size,'quality'=>$quality,'output_format'=>'png'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $headers=[];$ch=curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>120,CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headers){$len=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return $len;}]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$transport=curl_error($ch);curl_close($ch);
        if($body===false||$transport!=='')throw new RuntimeException('OpenAI destination image request failed: '.$transport);
        $json=json_decode((string)$body,true)?:[];if($status<200||$status>=300)throw new RuntimeException((string)($json['error']['message']??('OpenAI returned HTTP '.$status.'.')));
        $b64=(string)($json['data'][0]['b64_json']??'');if($b64==='')throw new RuntimeException('OpenAI did not return destination image data.');
        $bytes=base64_decode($b64,true);if($bytes===false||$bytes==='')throw new RuntimeException('Vacation Brain could not decode the generated destination image.');
        return ['bytes'=>$bytes,'request_id'=>$headers['x-request-id']??''];
    }

    private function saveImage(int $destinationId,string $role,string $bytes): string
    {
        $relative='uploads/destination-generated/'.$destinationId;$absolute=rtrim($this->rootDir,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        if(!is_dir($absolute)&&!mkdir($absolute,0775,true)&&!is_dir($absolute))throw new RuntimeException('Vacation Brain could not create the destination image folder.');
        $file=$role.'-'.bin2hex(random_bytes(8)).'.png';$path=$absolute.DIRECTORY_SEPARATOR.$file;if(file_put_contents($path,$bytes,LOCK_EX)===false)throw new RuntimeException('Vacation Brain could not save a generated destination image.');@chmod($path,0644);
        return app_url($relative.'/'.$file);
    }

    private function replaceGeneratedRole(int $destinationId,string $role): void
    {
        $stmt=$this->pdo->prepare('SELECT id,image_url FROM destination_gallery_images WHERE destination_catalog_id=? AND image_role=? AND is_ai_generated=1');$stmt->execute([$destinationId,$role]);$upload=new UploadService($this->rootDir);
        foreach($stmt->fetchAll() as $row){$upload->deleteLocal((string)$row['image_url']);$this->pdo->prepare('DELETE FROM destination_gallery_images WHERE id=?')->execute([(int)$row['id']]);}
    }

    private function cleanList(mixed $value): array
    {
        if(!is_array($value))$value=preg_split('/[\r\n,]+/',(string)$value)?:[];$out=[];$seen=[];
        foreach($value as $item){$item=$this->clean((string)$item,240);$key=strtolower($item);if($item===''||isset($seen[$key]))continue;$seen[$key]=true;$out[]=$item;if(count($out)>=40)break;}
        return $out;
    }

    private function clean(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
