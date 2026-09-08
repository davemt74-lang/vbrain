<?php
declare(strict_types=1);

final class FunContentService
{
    public function __construct(private PDO $pdo) {}

    public function random(string $type, ?int $userId = null): ?array
    {
        $allowed=['vacation_excuse','out_of_office','agent_comment','challenge','prop_bet','personality_result','merch_slogan'];
        if(!in_array($type,$allowed,true)) throw new InvalidArgumentException('Unknown content type.');
        if($userId){
            $stmt=$this->pdo->prepare('SELECT ci.* FROM content_items ci WHERE ci.content_type=? AND ci.status="published" AND NOT EXISTS (SELECT 1 FROM user_events ue WHERE ue.user_id=? AND ue.content_id=ci.id AND ue.occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) ORDER BY RAND() LIMIT 1');
            $stmt->execute([$type,$userId]);$row=$stmt->fetch();
            if(!$row){$stmt=$this->pdo->prepare('SELECT * FROM content_items WHERE content_type=? AND status="published" ORDER BY RAND() LIMIT 1');$stmt->execute([$type]);$row=$stmt->fetch();}
        } else {$stmt=$this->pdo->prepare('SELECT * FROM content_items WHERE content_type=? AND status="published" ORDER BY RAND() LIMIT 1');$stmt->execute([$type]);$row=$stmt->fetch();}
        return $row ?: null;
    }

    public function record(int $userId,string $eventType,array $content): int
    {
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,content_id,value_text) VALUES (?,?,?,?)')->execute([$userId,$eventType,$content['id'],$content['slug']??null]);
            $points=(new ScoreService($this->pdo))->award($userId,$eventType,1);
            (new AchievementService($this->pdo))->evaluate($userId);
            $this->pdo->commit();return $points;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
