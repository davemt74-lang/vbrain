<?php
declare(strict_types=1);

final class VacationPhotoAgentService
{
    private const ACTIONS = [
        'generate_vacation_photo',
        'remix_vacation_photo',
        'list_fake_vacations',
        'share_vacation_photo',
        'set_dream_trip_cover',
        'suggest_destination_from_profile',
        'favorite_vacation_photo',
        'select_vacation_photo',
    ];

    public function __construct(private PDO $pdo, private string $rootDir) {}

    public function handle(int $userId, string $message, array $input=[]): ?array
    {
        $message=$this->clean($message,500);
        $explicit=$this->clean((string)($input['action']??''),80);
        $action=$explicit!==''?$explicit:$this->intent($message);
        if($action===null || !in_array($action,self::ACTIONS,true)) return null;
        $input['request']=$message;
        return $this->execute($userId,$action,$input);
    }

    public function actionLabel(string $action,array $input=[]): string
    {
        return match($action){
            'generate_vacation_photo'=>'Generate a Vacation Yourself image.',
            'remix_vacation_photo'=>'Remix this fake vacation.',
            'list_fake_vacations'=>'Show my fake vacations.',
            'share_vacation_photo'=>'Share this fake vacation.',
            'set_dream_trip_cover'=>'Use this as my Dream Trip cover.',
            'suggest_destination_from_profile'=>'What would my Vacation Brain prescribe?',
            'favorite_vacation_photo'=>'Favorite this fake vacation.',
            'select_vacation_photo'=>'Use this fake vacation as the current chat context.',
            default=>'Vacation Yourself action.',
        };
    }

    public function context(int $userId): array
    {
        $photo=$this->selectedPhoto($userId) ?? $this->latestPhoto($userId);
        $share=$this->latestEventValue($userId,'agent_share_context');
        if(!$share && $photo && !empty($photo['share_enabled']) && !empty($photo['share_token'])){
            $share=app_url('vacation-photo-share.php?token='.urlencode((string)$photo['share_token']).'&style=diagnosis');
        }
        return [
            'photo'=>$photo?$this->publicPhoto($photo):null,
            'share_url'=>$share,
            'destination'=>$this->safeDestinationContext($userId),
        ];
    }

    private function execute(int $userId,string $action,array $input): array
    {
        return match($action){
            'generate_vacation_photo'=>$this->generate($userId,$input),
            'remix_vacation_photo'=>$this->remix($userId,$input),
            'list_fake_vacations'=>$this->listGallery($userId),
            'share_vacation_photo'=>$this->share($userId,$input),
            'set_dream_trip_cover'=>$this->dreamCover($userId,$input),
            'suggest_destination_from_profile'=>$this->suggest($userId),
            'favorite_vacation_photo'=>$this->favorite($userId,$input),
            'select_vacation_photo'=>$this->selectPhoto($userId,$input),
            default=>throw new InvalidArgumentException('Unsupported Vacation Yourself action.'),
        };
    }

    private function intent(string $message): ?string
    {
        if($message==='') return null;
        $q=strtolower($this->clean($message,500));

        if($this->hasAny($q,['show my fake vacations','my fake vacations','show my vacation photos','list my vacation','open my fake vacations'])) return 'list_fake_vacations';
        if($this->hasAny($q,['favorite the latest','favourite the latest','favorite this','favourite this'])) return 'favorite_vacation_photo';
        if($this->hasAny($q,['dream trip cover','use this as my dream','make this my dream'])) return 'set_dream_trip_cover';
        if($this->hasAny($q,['turn this into a postcard','make this a postcard','share this','share the latest','share latest','clean photo share'])) return 'share_vacation_photo';

        $diagnosisRequest=$this->hasAny($q,['vacation brain prescribe','diagnosis would prescribe','based on my diagnosis','diagnosis destination']);
        if($diagnosisRequest && $this->hasAny($q,['show me','make me','generate','put me'])) return 'generate_vacation_photo';
        if($diagnosisRequest || $this->hasAny($q,['suggest a destination','recommend a destination','where should i go'])) return 'suggest_destination_from_profile';

        if($this->hasAny($q,['show me in ','show me at ','show me on ','show me what i\'d look like in ','show me what i would look like in ','put me in ','put me on ','make me look like i\'m in ','make me look like im in ','generate a vacation','generate vacation','generate it','vacation photo of me','fake vacation'])) return 'generate_vacation_photo';

        if($this->hasAny($q,['same one','same one but','that one','this one','remix','more ridiculous','more over the top','more luxury','make it luxury','make this luxury','make this one more luxury','make it funny','make this funny','funny version','change the vibe','at sunset','at night'])){
            return 'remix_vacation_photo';
        }

        return null;
    }

    private function generate(int $userId,array $input): array
    {
        $request=$this->clean((string)($input['request']??''),500);
        $useProfile=!empty($input['use_profile_destination']) || $this->hasAny(strtolower($request),['vacation brain prescribe','diagnosis would prescribe','based on my diagnosis','diagnosis destination']);

        $destination=$this->destinationFromInput($input);
        $recommended=null;
        if(!$destination) $destination=$this->findDestination($request);
        if(!$destination){
            $rawDestination=$this->extractDestinationName($request);
            if($rawDestination!=='')$destination=['id'=>0,'name'=>$rawDestination];
        }
        if(!$destination && !$useProfile){
            $remembered=$this->safeDestinationContext($userId);
            if($remembered && !empty($remembered['name']))$destination=$remembered;
        }
        if($useProfile || !$destination){
            $recommended=(new DestinationRecommendationService($this->pdo))->primary($userId);
            if($recommended) $destination=$recommended;
        }

        $destinationId=(int)($destination['id']??0);
        $destinationName=$this->clean((string)($destination['name']??''),255);
        if($destinationName==='') throw new InvalidArgumentException('Tell Vacation Brain where to put you, or use the diagnosis destination button.');

        $scene=$this->sceneFromRequest($request,(string)($input['scene']??''));
        $vibe=$this->vibeFromRequest($request,(string)($input['vibe']??''));
        $strength=$this->strengthFromRequest($request,(int)($input['over_the_top_strength']??-1),null);
        $photoService=new VacationPhotoService($this->pdo,$this->rootDir);
        $created=$photoService->generate($userId,[
            'destination_id'=>$destinationId,
            'destination'=>$destinationName,
            'scene'=>$scene,
            'vibe'=>$vibe,
            'over_the_top_strength'=>$strength,
            'reference_keys'=>$this->stringArray($input['reference_keys']??[]),
        ]);

        $gallery=new VacationPhotoGalleryService($this->pdo,$this->rootDir);
        $origin=$useProfile?'diagnosis':($destinationId>0?'destination':'manual');
        $gallery->setOrigin($userId,(int)$created['id'],$origin,$origin==='destination'&&$destinationId>0?$destinationId:null);
        $row=$gallery->item($userId,(int)$created['id']);
        if(!$row) throw new RuntimeException('Vacation Brain generated the image but could not reload it.');
        $this->rememberPhoto($userId,$row);

        $why=$this->why($recommended ?: $destination);
        return [
            'intent'=>'generate_vacation_photo',
            'type'=>'photo',
            'message'=>'I put you in '.$destinationName.'. '.$why,
            'photo'=>$this->publicPhoto($row),
            'why'=>$why,
        ];
    }

    private function remix(int $userId,array $input): array
    {
        $source=$this->photoFromInput($userId,$input) ?? $this->selectedPhoto($userId) ?? $this->latestPhoto($userId);
        if(!$source) throw new InvalidArgumentException('Generate a fake vacation first, then I can remix it.');

        $request=$this->clean((string)($input['request']??''),500);
        $overrides=[];
        $destination=$this->destinationFromInput($input) ?: $this->findDestination($request);
        if($destination){
            $overrides['destination_id']=(int)($destination['id']??0);
            $overrides['destination']=$this->clean((string)($destination['name']??''),255);
        }else{
            $raw=$this->extractDestinationName($request,true);
            if($raw!==''){$overrides['destination_id']=0;$overrides['destination']=$raw;}
        }

        $scene=$this->sceneFromRequest($request,(string)($input['scene']??''),true);
        if($scene!=='')$overrides['scene']=$scene;

        $vibeInput=$this->clean((string)($input['vibe']??''),40);
        $parsedVibe=$this->vibeFromRequest($request,$vibeInput,true);
        if($parsedVibe!=='')$overrides['vibe']=$parsedVibe;

        $explicitStrength=array_key_exists('over_the_top_strength',$input)?(int)$input['over_the_top_strength']:-1;
        $delta=(int)($input['strength_delta']??0);
        $strength=$this->strengthFromRequest($request,$explicitStrength,(int)($source['over_the_top_strength']??35),$delta,true);
        if($strength!==null)$overrides['over_the_top_strength']=$strength;

        if(!$overrides){
            $overrides['over_the_top_strength']=min(100,(int)($source['over_the_top_strength']??35)+10);
        }

        $gallery=new VacationPhotoGalleryService($this->pdo,$this->rootDir);
        $created=$gallery->regenerate($userId,(int)$source['id'],$overrides);
        $row=$gallery->item($userId,(int)$created['id']);
        if(!$row) throw new RuntimeException('Vacation Brain remixed the image but could not reload it.');
        $this->rememberPhoto($userId,$row);

        return [
            'intent'=>'remix_vacation_photo',
            'type'=>'photo',
            'message'=>'Remixed. Same fake-vacation evidence, revised bad decisions.',
            'photo'=>$this->publicPhoto($row),
            'why'=>'The remix keeps your identity references and profile context while changing only the destination, scene, vibe, or intensity you asked for.',
        ];
    }

    private function listGallery(int $userId): array
    {
        $rows=(new VacationPhotoGalleryService($this->pdo,$this->rootDir))->gallery($userId);
        $items=array_map(fn(array $row)=>$this->publicPhoto($row),array_slice($rows,0,8));
        if($items) $this->rememberPhotoId($userId,(int)$items[0]['id']);
        return [
            'intent'=>'list_fake_vacations',
            'type'=>'gallery',
            'message'=>$items?'Here are your latest fake vacations. Pick one to remix, share, favorite, or use as a Dream Trip cover.':'Your fake-vacation gallery is suspiciously empty. We should manufacture some evidence.',
            'items'=>$items,
        ];
    }

    private function share(int $userId,array $input): array
    {
        $photo=$this->photoFromInput($userId,$input) ?? $this->selectedPhoto($userId) ?? $this->latestPhoto($userId);
        if(!$photo) throw new InvalidArgumentException('There is no fake vacation to share yet.');

        $request=strtolower($this->clean((string)($input['request']??''),500));
        $style=strtolower($this->clean((string)($input['share_style']??''),20));
        if(!in_array($style,['diagnosis','postcard','clean'],true)){
            $style=str_contains($request,'postcard')?'postcard':(str_contains($request,'clean')?'clean':'diagnosis');
        }

        $gallery=new VacationPhotoGalleryService($this->pdo,$this->rootDir);
        $token=$gallery->enableShare($userId,(int)$photo['id']);
        $url=app_url('vacation-photo-share.php?token='.urlencode($token).'&style='.$style);
        $this->rememberPhoto($userId,$photo);
        $this->rememberEvent($userId,'agent_share_context',$url);

        return [
            'intent'=>'share_vacation_photo',
            'type'=>'share',
            'message'=>'Public sharing is on for this image. You can revoke it anytime from My Fake Vacations.',
            'photo'=>$this->publicPhoto($photo),
            'share_url'=>$url,
            'share_style'=>$style,
        ];
    }

    private function dreamCover(int $userId,array $input): array
    {
        $photo=$this->photoFromInput($userId,$input) ?? $this->selectedPhoto($userId) ?? $this->latestPhoto($userId);
        if(!$photo) throw new InvalidArgumentException('Generate a fake vacation before assigning a Dream Trip cover.');

        $dreamId=max(0,(int)($input['dream_trip_id']??0));
        if($dreamId<1){
            $stmt=$this->pdo->prepare('SELECT id,name FROM dream_trips WHERE user_id=? AND status<>"abandoned" ORDER BY updated_at DESC,id DESC LIMIT 1');
            $stmt->execute([$userId]);$dream=$stmt->fetch()?:null;
        }else{
            $stmt=$this->pdo->prepare('SELECT id,name FROM dream_trips WHERE id=? AND user_id=? AND status<>"abandoned" LIMIT 1');
            $stmt->execute([$dreamId,$userId]);$dream=$stmt->fetch()?:null;
        }
        if(!$dream) throw new InvalidArgumentException('Create a Dream Trip first, then I can use this image as its cover.');

        (new VacationPhotoGalleryService($this->pdo,$this->rootDir))->setDreamCover($userId,(int)$photo['id'],(int)$dream['id']);
        $this->rememberPhoto($userId,$photo);
        return [
            'intent'=>'set_dream_trip_cover',
            'type'=>'action',
            'message'=>'Done. This is now the cover for “'.(string)$dream['name'].'.”',
            'photo'=>$this->publicPhoto($photo),
            'dream_trip'=>['id'=>(int)$dream['id'],'name'=>(string)$dream['name']],
        ];
    }

    private function suggest(int $userId): array
    {
        $destination=(new DestinationRecommendationService($this->pdo))->primary($userId);
        if(!$destination){
            return ['intent'=>'suggest_destination_from_profile','type'=>'suggestion','message'=>'I need a little more profile signal before I can prescribe a destination. Try a few swipes or finish your diagnosis.','destination'=>null];
        }
        $why=$this->why($destination);
        $this->rememberDestination($userId,$destination);
        return [
            'intent'=>'suggest_destination_from_profile',
            'type'=>'suggestion',
            'message'=>'Vacation Brain prescription: '.$destination['name'].'. '.$why,
            'destination'=>$this->publicDestination($destination),
            'why'=>$why,
        ];
    }

    private function favorite(int $userId,array $input): array
    {
        $photo=$this->photoFromInput($userId,$input) ?? $this->selectedPhoto($userId) ?? $this->latestPhoto($userId);
        if(!$photo) throw new InvalidArgumentException('There is no fake vacation to favorite yet.');
        $gallery=new VacationPhotoGalleryService($this->pdo,$this->rootDir);
        $favorite=$gallery->toggleFavorite($userId,(int)$photo['id']);
        $row=$gallery->item($userId,(int)$photo['id']) ?: $photo;
        $this->rememberPhoto($userId,$row);
        return [
            'intent'=>'favorite_vacation_photo',
            'type'=>'action',
            'message'=>$favorite?'Favorited. Future you has been formally warned.':'Removed from favorites.',
            'photo'=>$this->publicPhoto($row),
            'favorite'=>$favorite,
        ];
    }

    private function selectPhoto(int $userId,array $input): array
    {
        $photo=$this->photoFromInput($userId,$input);
        if(!$photo) throw new InvalidArgumentException('Choose a fake vacation first.');
        $this->rememberPhoto($userId,$photo);
        return [
            'intent'=>'select_vacation_photo',
            'type'=>'action',
            'message'=>'Selected. I will treat this as “this one” or “that one” in your next Vacation Yourself request.',
            'photo'=>$this->publicPhoto($photo),
        ];
    }

    private function photoFromInput(int $userId,array $input): ?array
    {
        $id=max(0,(int)($input['generation_id']??0));
        if($id<1)return null;
        $row=(new VacationPhotoGalleryService($this->pdo,$this->rootDir))->item($userId,$id);
        return $this->usablePhoto($row);
    }

    private function latestPhoto(int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM vacation_photo_generations WHERE user_id=? AND status="completed" AND image_url IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);return $stmt->fetch()?:null;
    }

    private function selectedPhoto(int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT value_text FROM user_events WHERE user_id=? AND event_type="agent_photo_context" ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);$id=(int)($stmt->fetchColumn()?:0);
        if($id<1)return null;
        $row=(new VacationPhotoGalleryService($this->pdo,$this->rootDir))->item($userId,$id);
        return $this->usablePhoto($row);
    }

    private function safeDestinationContext(int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT value_text FROM user_events WHERE user_id=? AND event_type="agent_destination_context" ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);$raw=(string)($stmt->fetchColumn()?:'');
        $data=json_decode($raw,true);
        return is_array($data)?$data:null;
    }

    private function usablePhoto(?array $row): ?array
    {
        if(!$row || !empty($row['deleted_at']) || empty($row['image_url']) || (string)($row['status']??'')!=='completed')return null;
        return $row;
    }

    private function latestEventValue(int $userId,string $type): string
    {
        try{
            $stmt=$this->pdo->prepare('SELECT value_text FROM user_events WHERE user_id=? AND event_type=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$userId,$type]);
            return $this->clean((string)($stmt->fetchColumn()?:''),1500);
        }catch(Throwable){return '';}
    }

    private function destinationFromInput(array $input): ?array
    {
        $id=max(0,(int)($input['destination_id']??0));
        $name=$this->clean((string)($input['destination']??''),255);
        if($id>0 && db_table_exists('destination_catalog')){
            $stmt=$this->pdo->prepare('SELECT * FROM destination_catalog WHERE id=? AND status="active" LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();if($row)return $row;
        }
        return $name!==''?['id'=>0,'name'=>$name]:null;
    }

    private function findDestination(string $request): ?array
    {
        if($request==='' || !db_table_exists('destination_catalog'))return null;
        $q=strtolower($request);
        $sampleClause=(db_column_exists('destination_catalog','is_sample')&&!sample_data_enabled())?' AND is_sample=0':'';
        $rows=$this->pdo->query('SELECT * FROM destination_catalog WHERE status="active"'.$sampleClause.' ORDER BY featured DESC,sort_order,name LIMIT 500')->fetchAll();
        $best=null;$bestScore=0;
        foreach($rows as $row){
            $score=0;
            foreach(['name'=>100,'city'=>45,'region'=>25,'country'=>20] as $field=>$weight){
                $value=strtolower(trim((string)($row[$field]??'')));
                if($value!=='' && str_contains($q,$value))$score+=$weight;
            }
            $name=strtolower(trim((string)($row['name']??'')));
            foreach(preg_split('/[^a-z0-9]+/',$name)?:[] as $token){
                if(strlen($token)>=4 && str_contains($q,$token))$score+=8;
            }
            if($score>$bestScore){$bestScore=$score;$best=$row;}
        }
        return $bestScore>=8?$best:null;
    }

    private function extractDestinationName(string $request,bool $remix=false): string
    {
        $text=$this->clean($request,500);
        if($text==='')return '';
        $patterns=[
            '/\b(?:show me|put me|show me what i(?:\'d| would) look like|make me look like i(?:\'m| am)?|make me look like im)\s+(?:in|at)\s+([^,.!?]+?)(?:\s+(?:at|on|with|during)\s+(?:night|sunset|sunrise|a beach|the beach|a pool|the pool|a rooftop)|$)/i',
            '/\b(?:show me|put me)\s+on\s+(?:a\s+)?([^,.!?]+?)\s+vacation\b/i',
            '/\b(?:same one|that one|this one)\s+(?:but\s+)?in\s+([^,.!?]+?)(?:\s+(?:at|on|with|during)\s+(?:night|sunset|sunrise)|$)/i',
        ];
        foreach($patterns as $pattern){
            if(preg_match($pattern,$text,$m)){
                $candidate=$this->clean((string)$m[1],120);
                if(!$this->looksLikeSceneOnly($candidate))return $candidate;
            }
        }
        return $remix?'':'';
    }

    private function sceneFromRequest(string $request,string $explicit='',bool $onlyIfDetected=false): string
    {
        $explicit=$this->clean($explicit,500);if($explicit!=='')return $explicit;
        $q=strtolower($request);
        $map=[
            'night market'=>'at a lively night market with destination-specific details',
            'at night'=>'at night with destination-specific evening atmosphere and recognizable context',
            'night'=>'at night with destination-specific evening atmosphere and recognizable context',
            'sunset'=>'at golden-hour sunset',
            'sunrise'=>'at sunrise',
            'rooftop'=>'on a rooftop with a recognizable destination backdrop',
            'pool'=>'at a destination-appropriate resort pool or cabana',
            'beach'=>'on a destination-appropriate beach',
            'spa'=>'in an upscale destination-appropriate spa setting',
            'restaurant'=>'at a distinctive local restaurant',
            'food'=>'exploring local food and dining',
            'market'=>'exploring a distinctive local market',
            'street'=>'on a recognizable local street',
            'snow'=>'in a snowy destination-specific setting',
            'hiking'=>'on a scenic destination-specific hike',
        ];
        foreach($map as $needle=>$scene)if(str_contains($q,$needle))return $scene;
        return $onlyIfDetected?'':'a destination-authentic vacation moment';
    }

    private function vibeFromRequest(string $request,string $explicit='',bool $onlyIfDetected=false): string
    {
        $explicit=strtolower($this->clean($explicit,40));
        $allowed=['realistic','luxury','adventure','relaxed','funny','touristy'];
        if(in_array($explicit,$allowed,true))return $explicit;
        $q=strtolower($request);
        foreach([
            'luxury'=>'luxury','upscale'=>'luxury','fancy'=>'luxury',
            'funny'=>'funny','ridiculous'=>'funny','absurd'=>'funny','chaotic'=>'funny',
            'adventure'=>'adventure','adventurous'=>'adventure',
            'relaxed'=>'relaxed','relaxing'=>'relaxed','chill'=>'relaxed',
            'touristy'=>'touristy','tourist'=>'touristy',
            'realistic'=>'realistic','subtle'=>'realistic',
        ] as $needle=>$vibe)if(str_contains($q,$needle))return $vibe;
        return $onlyIfDetected?'':'realistic';
    }

    private function strengthFromRequest(string $request,int $explicit,?int $current=null,int $delta=0,bool $nullable=false): ?int
    {
        if($explicit>=0)return max(0,min(100,$explicit));
        if($delta!==0 && $current!==null)return max(0,min(100,$current+$delta));
        $q=strtolower($request);
        if(str_contains($q,'subtle'))return 15;
        if(str_contains($q,'absurd'))return 95;
        if(str_contains($q,'chaotic'))return 80;
        if(str_contains($q,'more ridiculous')||str_contains($q,'more over the top'))return max(0,min(100,($current??50)+20));
        if(str_contains($q,'ridiculous')||str_contains($q,'over the top'))return 70;
        if(str_contains($q,'fun'))return 45;
        if($nullable && $current!==null)return null;
        return max(0,min(100,(int)site_setting('vacation_photos.default_over_the_top','35')));
    }

    private function why(array $destination): string
    {
        $reasons=array_values(array_filter(array_map(fn($v)=>$this->clean((string)$v,60),(array)($destination['_recommendation_reasons']??[]))));
        if($reasons)return 'It lines up with your stronger '.$this->humanList(array_slice($reasons,0,3)).' signals.';
        return 'It fits the travel profile Vacation Brain has built from your diagnosis, swipes, and saved preferences.';
    }

    private function publicPhoto(array $row): array
    {
        return [
            'id'=>(int)($row['id']??0),
            'destination_id'=>(int)($row['destination_catalog_id']??0),
            'destination'=>$this->clean((string)($row['catalog_destination_name']??$row['destination']??''),255),
            'scene'=>$this->clean((string)($row['scene']??''),500),
            'vibe'=>$this->clean((string)($row['vibe']??'realistic'),40),
            'over_the_top_strength'=>max(0,min(100,(int)($row['over_the_top_strength']??35))),
            'image_url'=>$this->clean((string)($row['image_url']??''),1000),
            'favorite'=>!empty($row['favorite']),
            'share_enabled'=>!empty($row['share_enabled']),
            'created_at'=>(string)($row['created_at']??''),
        ];
    }

    private function publicDestination(array $row): array
    {
        return [
            'id'=>(int)($row['id']??0),
            'name'=>$this->clean((string)($row['name']??''),255),
            'city'=>$this->clean((string)($row['city']??''),160),
            'country'=>$this->clean((string)($row['country']??''),160),
            'vibe'=>$this->clean((string)($row['vibe']??''),160),
            'short_description'=>$this->clean((string)($row['short_description']??''),300),
        ];
    }

    private function rememberPhoto(int $userId,array $photo): void
    {
        $this->rememberPhotoId($userId,(int)($photo['id']??0));
        $this->rememberDestination($userId,[
            'id'=>(int)($photo['destination_catalog_id']??0),
            'name'=>(string)($photo['catalog_destination_name']??$photo['destination']??''),
        ]);
    }

    private function rememberPhotoId(int $userId,int $photoId): void
    {
        if($photoId>0)$this->rememberEvent($userId,'agent_photo_context',(string)$photoId);
    }

    private function rememberDestination(int $userId,array $destination): void
    {
        $safe=['id'=>(int)($destination['id']??$destination['destination_catalog_id']??0),'name'=>$this->clean((string)($destination['name']??$destination['destination']??''),255)];
        $this->rememberEvent($userId,'agent_destination_context',json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}');
    }

    private function rememberEvent(int $userId,string $type,string $value): void
    {
        try{$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_text) VALUES (?,?,?)')->execute([$userId,$type,$this->clean($value,1500)]);}catch(Throwable){}
    }

    private function hasAny(string $haystack,array $needles): bool
    {
        foreach($needles as $needle)if(str_contains($haystack,$needle))return true;
        return false;
    }

    private function looksLikeSceneOnly(string $value): bool
    {
        $q=strtolower(trim($value));
        return $q==='' || in_array($q,['night','sunset','sunrise','beach','the beach','a beach','pool','the pool','a pool','a rooftop','rooftop'],true);
    }

    private function clean(string $value,int $max): string
    {
        $value=str_replace(["’","‘","“","”"],["'","'",'"','"'],$value);
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }

    private function stringArray(mixed $value): array
    {
        if(!is_array($value))return [];
        return array_values(array_filter(array_map(fn($v)=>$this->clean((string)$v,120),$value),fn($v)=>$v!==''));
    }

    private function humanList(array $items): string
    {
        $items=array_values($items);
        if(count($items)<=1)return $items[0]??'travel';
        if(count($items)===2)return $items[0].' and '.$items[1];
        $last=array_pop($items);return implode(', ',$items).', and '.$last;
    }
}
