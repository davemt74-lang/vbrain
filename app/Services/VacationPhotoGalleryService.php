<?php
declare(strict_types=1);

final class VacationPhotoGalleryService
{
    public function __construct(private PDO $pdo,private string $rootDir) {}

    public function setOrigin(int $userId,int $generationId,string $origin,?int $originId=null): void
    {
        $origin=in_array($origin,['manual','diagnosis','destination','dream','gallery_regenerate'],true)?$origin:'manual';
        $this->pdo->prepare('UPDATE vacation_photo_generations SET origin=?,origin_id=? WHERE id=? AND user_id=?')->execute([$origin,$originId?:null,$generationId,$userId]);
    }

    public function item(int $userId,int $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT g.*,d.name AS catalog_destination_name,dt.name AS dream_trip_name FROM vacation_photo_generations g LEFT JOIN destination_catalog d ON d.id=g.destination_catalog_id LEFT JOIN dream_trips dt ON dt.id=g.dream_trip_id WHERE g.id=? AND g.user_id=? LIMIT 1');$stmt->execute([$id,$userId]);return $stmt->fetch()?:null;
    }

    public function gallery(int $userId,array $filters=[]): array
    {
        if(!site_setting_bool('vacation_photos.gallery_enabled',true))return [];
        $sql='SELECT g.*,d.name AS catalog_destination_name,dt.name AS dream_trip_name FROM vacation_photo_generations g LEFT JOIN destination_catalog d ON d.id=g.destination_catalog_id LEFT JOIN dream_trips dt ON dt.id=g.dream_trip_id WHERE g.user_id=? AND g.status="completed" AND g.image_url IS NOT NULL AND g.deleted_at IS NULL';$args=[$userId];
        if(empty($filters['archived']))$sql.=' AND g.archived=0';else $sql.=' AND g.archived=1';
        if(!empty($filters['favorites']))$sql.=' AND g.favorite=1';
        if(!empty($filters['destination_id'])){$sql.=' AND g.destination_catalog_id=?';$args[]=(int)$filters['destination_id'];}
        $sql.=' ORDER BY g.favorite DESC,g.created_at DESC,g.id DESC LIMIT 240';$stmt=$this->pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll()?:[];
    }

    public function destinations(int $userId): array
    {
        if(!site_setting_bool('vacation_photos.gallery_enabled',true))return [];
        $stmt=$this->pdo->prepare('SELECT COALESCE(destination_catalog_id,0) destination_id,destination,COUNT(*) image_count,SUM(favorite=1) favorite_count,MAX(created_at) last_created FROM vacation_photo_generations WHERE user_id=? AND status="completed" AND image_url IS NOT NULL AND deleted_at IS NULL AND archived=0 GROUP BY COALESCE(destination_catalog_id,0),destination ORDER BY last_created DESC');$stmt->execute([$userId]);return $stmt->fetchAll()?:[];
    }

    public function toggleFavorite(int $userId,int $id): bool
    {
        $row=$this->item($userId,$id);if(!$row||!empty($row['deleted_at']))throw new RuntimeException('Vacation photo not found.');$next=empty($row['favorite'])?1:0;$this->pdo->prepare('UPDATE vacation_photo_generations SET favorite=? WHERE id=? AND user_id=?')->execute([$next,$id,$userId]);return (bool)$next;
    }

    public function setArchived(int $userId,int $id,bool $archived): void
    {
        $this->pdo->prepare('UPDATE vacation_photo_generations SET archived=? WHERE id=? AND user_id=? AND deleted_at IS NULL')->execute([$archived?1:0,$id,$userId]);
    }

    public function delete(int $userId,int $id): void
    {
        $row=$this->item($userId,$id);if(!$row||!empty($row['deleted_at']))return;
        if(!empty($row['image_url']))(new UploadService($this->rootDir))->deleteLocal((string)$row['image_url']);
        $this->pdo->prepare('UPDATE vacation_photo_generations SET image_url=NULL,favorite=0,archived=1,share_enabled=0,share_token=NULL,deleted_at=NOW() WHERE id=? AND user_id=?')->execute([$id,$userId]);
    }

    public function enableShare(int $userId,int $id): string
    {
        if(!site_setting_bool('vacation_photos.sharing_enabled',true))throw new RuntimeException('Vacation photo sharing is currently disabled.');
        $row=$this->item($userId,$id);if(!$row||empty($row['image_url'])||!empty($row['deleted_at']))throw new RuntimeException('Vacation photo not found.');
        $token=(string)($row['share_token']??'');if($token==='')$token=bin2hex(random_bytes(20));
        $this->pdo->prepare('UPDATE vacation_photo_generations SET share_token=?,share_enabled=1 WHERE id=? AND user_id=?')->execute([$token,$id,$userId]);return $token;
    }

    public function disableShare(int $userId,int $id): void
    {
        $this->pdo->prepare('UPDATE vacation_photo_generations SET share_enabled=0 WHERE id=? AND user_id=?')->execute([$id,$userId]);
    }

    public function publicShared(string $token): ?array
    {
        if(!site_setting_bool('vacation_photos.sharing_enabled',true))return null;
        if(!preg_match('/^[a-f0-9]{40}$/',$token))return null;
        $stmt=$this->pdo->prepare('SELECT id,destination,scene,vibe,over_the_top_strength,image_url,origin,user_profile_snapshot_json,created_at FROM vacation_photo_generations WHERE share_token=? AND share_enabled=1 AND status="completed" AND image_url IS NOT NULL AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$token]);$row=$stmt->fetch();if(!$row)return null;
        $presentation=$this->sharePresentation($row);
        unset($row['user_profile_snapshot_json']);
        return array_merge($row,$presentation);
    }

    public function setDreamCover(int $userId,int $generationId,int $dreamTripId): void
    {
        $photo=$this->item($userId,$generationId);if(!$photo||empty($photo['image_url'])||!empty($photo['deleted_at']))throw new RuntimeException('Vacation photo not found.');
        $stmt=$this->pdo->prepare('SELECT id FROM dream_trips WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$dreamTripId,$userId]);if(!$stmt->fetchColumn())throw new RuntimeException('Dream Trip not found.');
        $this->pdo->prepare('UPDATE dream_trips SET cover_image_url=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$photo['image_url'],$dreamTripId,$userId]);
        $this->pdo->prepare('UPDATE vacation_photo_generations SET dream_trip_id=? WHERE id=? AND user_id=?')->execute([$dreamTripId,$generationId,$userId]);
    }

    public function regenerate(int $userId,int $id,array $overrides=[]): array
    {
        $row=$this->item($userId,$id);if(!$row)throw new RuntimeException('Vacation photo not found.');$refs=json_decode((string)($row['source_refs_json']??'[]'),true)?:[];$keys=[];foreach($refs as $ref){if(!empty($ref['key']))$keys[]=(string)$ref['key'];}
        $input=[
            'destination_id'=>(int)($overrides['destination_id']??$row['destination_catalog_id']??0),
            'destination'=>(string)($overrides['destination']??$row['destination']),
            'scene'=>(string)($overrides['scene']??$row['scene']??''),
            'vibe'=>(string)($overrides['vibe']??$row['vibe']??'realistic'),
            'over_the_top_strength'=>(int)($overrides['over_the_top_strength']??$row['over_the_top_strength']??35),
            'size'=>(string)($overrides['size']??$row['size']??'1024x1024'),
            'quality'=>(string)($overrides['quality']??$row['quality']??'medium'),
            'reference_keys'=>$keys,
        ];
        $created=(new VacationPhotoService($this->pdo,$this->rootDir))->generate($userId,$input);$this->setOrigin($userId,(int)$created['id'],'gallery_regenerate',$id);return $created;
    }

    private function sharePresentation(array $row): array
    {
        $snapshot=json_decode((string)($row['user_profile_snapshot_json']??''),true);$snapshot=is_array($snapshot)?$snapshot:[];
        $diagnosis=$snapshot['diagnosis']??[];$vacationBrain=$snapshot['vacation_brain']??[];
        $diagnosisTitle=$this->shareText((string)($diagnosis['title']??''),180);
        $archetype=$this->shareText((string)($vacationBrain['archetype']??''),160);
        $destination=$this->shareText((string)($row['destination']??''),180)?:'somewhere else';
        $diagnosisHeadline=$diagnosisTitle!==''?'Vacation Brain diagnosed me with '.$diagnosisTitle.'.':'Vacation Brain diagnosed me with a serious need for '.$destination.'.';
        $postcardHeadline='Wish I were here.';
        $postcardSubhead='Vacation Brain prescribed '.$destination.'.';
        $shareText=$diagnosisHeadline.' '.$postcardSubhead;
        return [
            'diagnosis_title'=>$diagnosisTitle,
            'archetype'=>$archetype,
            'diagnosis_headline'=>$diagnosisHeadline,
            'postcard_headline'=>$postcardHeadline,
            'postcard_subhead'=>$postcardSubhead,
            'share_text'=>$shareText,
        ];
    }

    private function shareText(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
