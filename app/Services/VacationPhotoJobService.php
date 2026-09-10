<?php
declare(strict_types=1);

final class VacationPhotoJobService
{
    public function __construct(private PDO $pdo, private string $rootDir) {}

    public function create(int $userId,array $file,array $input): array
    {
        if(!db_table_exists('vacation_photo_jobs')) throw new RuntimeException('Run System Upgrade before using Vacation Brain Photos.');
        $sourceType=in_array((string)($input['source_type']??'upload'),['upload','camera'],true)?(string)$input['source_type']:'upload';
        $photoService=new VacationPhotoService($this->pdo,$this->rootDir);
        if(!empty($input['ai_photo_consent'])) $photoService->setConsent($userId,true);
        if(empty($photoService->preference($userId)['ai_photo_consent'])) throw new InvalidArgumentException('AI photo consent is required before generating a vacation photo.');
        $sourceUrl=$photoService->addReference($userId,$file,$sourceType==='camera'?'Camera capture':'Photos upload');
        $stmt=$this->pdo->prepare('SELECT id FROM vacation_photo_references WHERE user_id=? AND photo_url=? AND active=1 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId,$sourceUrl]); $referenceId=(int)$stmt->fetchColumn();
        if($referenceId<1) throw new RuntimeException('Vacation Brain could not register the source photo.');

        $destinationId=max(0,(int)($input['destination_id']??0));
        $destination=$this->clean((string)($input['destination']??''),255);
        if($destinationId>0){
            $destinationRow=(new DestinationPromptService($this->pdo))->destination($destinationId);
            if(!$destinationRow||((string)($destinationRow['status']??'draft')!=='active'&&!is_admin())) throw new InvalidArgumentException('Choose an active destination.');
            $destination=$this->clean((string)$destinationRow['name'],255);
        }
        if($destination==='') throw new InvalidArgumentException('Choose a destination first.');
        $options=[
            'destination_id'=>$destinationId,
            'destination'=>$destination,
            'scene'=>$this->clean((string)($input['scene']??''),500),
            'vibe'=>$this->clean((string)($input['vibe']??'realistic'),80),
            'over_the_top_strength'=>max(0,min(100,(int)($input['over_the_top_strength']??45))),
            'size'=>$this->clean((string)($input['size']??'1024x1024'),32),
            'quality'=>$this->clean((string)($input['quality']??site_setting('vacation_photos.default_quality','medium')),20),
            'reference_keys'=>['extra:'.$referenceId],
        ];
        $stmt=$this->pdo->prepare('INSERT INTO vacation_photo_jobs (user_id,source_type,source_reference_id,source_image_url,destination_catalog_id,destination_name,generation_options_json,status) VALUES (?,?,?,?,?,?,?,"queued")');
        $stmt->execute([$userId,$sourceType,$referenceId,$sourceUrl,$destinationId?:null,$destination,json_encode($options,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        return $this->job($userId,(int)$this->pdo->lastInsertId())??[];
    }

    public function process(int $userId,int $jobId): array
    {
        $job=$this->job($userId,$jobId); if(!$job) throw new RuntimeException('Photo job not found.');
        if($job['status']==='completed') return $job;
        if($job['status']==='processing') throw new RuntimeException('This photo is already being generated.');
        $this->pdo->prepare('UPDATE vacation_photo_jobs SET status="processing",started_at=NOW(),error_message=NULL WHERE id=? AND user_id=?')->execute([$jobId,$userId]);
        try{
            $options=json_decode((string)$job['generation_options_json'],true)?:[];
            $generated=(new VacationPhotoService($this->pdo,$this->rootDir))->generate($userId,$options);
            (new VacationPhotoGalleryService($this->pdo,$this->rootDir))->setOrigin($userId,(int)$generated['id'],'manual',$jobId);
            $this->pdo->prepare('UPDATE vacation_photo_jobs SET status="completed",generation_id=?,completed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?')->execute([(int)$generated['id'],$jobId,$userId]);
            return $this->job($userId,$jobId)??[];
        }catch(Throwable $e){
            $message=$this->clean($e->getMessage(),1000);
            $this->pdo->prepare('UPDATE vacation_photo_jobs SET status="failed",error_message=?,completed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?')->execute([$message,$jobId,$userId]);
            throw $e;
        }
    }

    public function job(int $userId,int $jobId): ?array
    {
        if(!db_table_exists('vacation_photo_jobs')) return null;
        $stmt=$this->pdo->prepare('SELECT j.*,g.image_url generated_image_url,g.destination,g.scene,g.vibe,g.favorite FROM vacation_photo_jobs j LEFT JOIN vacation_photo_generations g ON g.id=j.generation_id WHERE j.id=? AND j.user_id=? LIMIT 1');
        $stmt->execute([$jobId,$userId]); return $stmt->fetch()?:null;
    }

    public function recent(int $userId,int $limit=20): array
    {
        if(!db_table_exists('vacation_photo_jobs')) return [];
        $limit=max(1,min(50,$limit));
        $stmt=$this->pdo->prepare('SELECT j.*,g.image_url generated_image_url FROM vacation_photo_jobs j LEFT JOIN vacation_photo_generations g ON g.id=j.generation_id WHERE j.user_id=? ORDER BY j.id DESC LIMIT '.$limit);
        $stmt->execute([$userId]); return $stmt->fetchAll()?:[];
    }

    private function clean(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
