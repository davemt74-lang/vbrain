<?php
declare(strict_types=1);

final class VacationBreakService
{
    public function __construct(private PDO $pdo) {}

    public function published(int $limit=12): array
    {
        $limit=max(1,min(100,$limit));
        return $this->pdo->query('SELECT ci.*,vbd.duration_seconds,vbd.activity_text,vbd.ending_text,vbd.image_url,d.city AS destination_city,d.country AS destination_country FROM content_items ci JOIN vacation_break_details vbd ON vbd.content_id=ci.id LEFT JOIN destinations d ON d.id=vbd.destination_id WHERE ci.content_type="vacation_break" AND ci.status="published" ORDER BY ci.featured DESC,RAND() LIMIT '.$limit)->fetchAll();
    }

    public function one(int $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT ci.*,vbd.duration_seconds,vbd.activity_text,vbd.ending_text,vbd.image_url,vbd.audio_url,vbd.ambient_audio_url,d.city AS destination_city,d.country AS destination_country FROM content_items ci JOIN vacation_break_details vbd ON vbd.content_id=ci.id LEFT JOIN destinations d ON d.id=vbd.destination_id WHERE ci.id=? AND ci.content_type="vacation_break" AND ci.status="published" LIMIT 1');
        $stmt->execute([$id]);$row=$stmt->fetch();return $row?:null;
    }

    public function complete(int $userId,array $break): array
    {
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,content_id,value_numeric,value_text) VALUES (? ,"vacation_break_completed",?,?,?)')->execute([$userId,$break['id'],$break['duration_seconds'],$break['slug']??null]);
            $points=(new ScoreService($this->pdo))->award($userId,'vacation_break_completed',5);
            $unlocked=(new AchievementService($this->pdo))->evaluate($userId);
            $this->pdo->commit();return ['points'=>$points,'unlocked'=>$unlocked];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
