<?php
declare(strict_types=1);

final class VacationPhotoAlbumService
{
    public function __construct(private PDO $pdo) {}

    public function albums(int $userId): array
    {
        if(!db_table_exists('vacation_photo_albums')) return [];
        $stmt=$this->pdo->prepare('SELECT a.*,COUNT(i.generation_id) item_count,g.image_url cover_image_url FROM vacation_photo_albums a LEFT JOIN vacation_photo_album_items i ON i.album_id=a.id LEFT JOIN vacation_photo_generations g ON g.id=a.cover_generation_id WHERE a.user_id=? GROUP BY a.id ORDER BY a.updated_at DESC,a.id DESC');
        $stmt->execute([$userId]); return $stmt->fetchAll()?:[];
    }

    public function create(int $userId,string $name,string $description=''): array
    {
        if(!db_table_exists('vacation_photo_albums')) throw new RuntimeException('Run System Upgrade before creating albums.');
        $name=$this->clean($name,120); $description=$this->clean($description,500);
        if($name==='') throw new InvalidArgumentException('Album name is required.');
        $stmt=$this->pdo->prepare('INSERT INTO vacation_photo_albums (user_id,name,description) VALUES (?,?,?)');
        $stmt->execute([$userId,$name,$description?:null]);
        return ['id'=>(int)$this->pdo->lastInsertId(),'name'=>$name,'description'=>$description];
    }

    public function add(int $userId,int $albumId,int $generationId): void
    {
        $album=$this->pdo->prepare('SELECT id FROM vacation_photo_albums WHERE id=? AND user_id=?');$album->execute([$albumId,$userId]);
        if(!$album->fetchColumn()) throw new RuntimeException('Album not found.');
        $photo=$this->pdo->prepare("SELECT id FROM vacation_photo_generations WHERE id=? AND user_id=? AND status='completed' AND image_url IS NOT NULL AND deleted_at IS NULL");$photo->execute([$generationId,$userId]);
        if(!$photo->fetchColumn()) throw new RuntimeException('Photo not found.');
        $stmt=$this->pdo->prepare('INSERT IGNORE INTO vacation_photo_album_items (album_id,generation_id,user_id) VALUES (?,?,?)');$stmt->execute([$albumId,$generationId,$userId]);
        $this->pdo->prepare('UPDATE vacation_photo_albums SET cover_generation_id=COALESCE(cover_generation_id,?),updated_at=NOW() WHERE id=? AND user_id=?')->execute([$generationId,$albumId,$userId]);
    }

    public function items(int $userId,int $albumId): array
    {
        $stmt=$this->pdo->prepare("SELECT g.* FROM vacation_photo_album_items i JOIN vacation_photo_generations g ON g.id=i.generation_id JOIN vacation_photo_albums a ON a.id=i.album_id WHERE i.album_id=? AND i.user_id=? AND a.user_id=? AND g.deleted_at IS NULL ORDER BY i.created_at DESC");
        $stmt->execute([$albumId,$userId,$userId]); return $stmt->fetchAll()?:[];
    }

    private function clean(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
