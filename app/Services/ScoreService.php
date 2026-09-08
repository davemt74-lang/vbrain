<?php
declare(strict_types=1);

final class ScoreService
{
    public function __construct(private PDO $pdo) {}

    public function award(int $userId, string $eventType, ?int $fallbackPoints = null): int
    {
        $stmt=$this->pdo->prepare('SELECT points,daily_limit,cooldown_minutes FROM score_rules WHERE event_type=? AND active=1 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$eventType]);$rule=$stmt->fetch();
        $points=$rule ? (int)$rule['points'] : (int)($fallbackPoints ?? 0);
        if($points===0)return 0;

        if($rule && $rule['daily_limit']!==null){
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM user_events WHERE user_id=? AND event_type=? AND occurred_at>=CURDATE()');$q->execute([$userId,$eventType]);
            if((int)$q->fetchColumn()>(int)$rule['daily_limit'])return 0; // current event is already recorded
        }
        if($rule && (int)($rule['cooldown_minutes']??0)>0){
            $q=$this->pdo->prepare('SELECT occurred_at FROM user_events WHERE user_id=? AND event_type=? ORDER BY id DESC LIMIT 2');$q->execute([$userId,$eventType]);$times=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($times)>=2){$previous=strtotime((string)$times[1]);if($previous && (time()-$previous)<((int)$rule['cooldown_minutes']*60))return 0;}
        }

        $this->pdo->prepare('INSERT INTO user_score_summary (user_id,vacation_brain_score,score_level) VALUES (?,0,"thinking_about_it") ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)')->execute([$userId]);
        $q=$this->pdo->prepare('SELECT vacation_brain_score FROM user_score_summary WHERE user_id=? FOR UPDATE');$q->execute([$userId]);
        $score=(int)($q->fetchColumn() ?: 0)+$points;
        $this->pdo->prepare('UPDATE user_score_summary SET vacation_brain_score=?,score_level=?,updated_at=NOW() WHERE user_id=?')->execute([$score,$this->scoreLevel($score),$userId]);
        return $points;
    }

    private function scoreLevel(int $score): string
    {
        return match (true) {
            $score < 300 => 'thinking_about_it',
            $score < 500 => 'needs_a_break',
            $score < 700 => 'mentally_packing',
            $score < 900 => 'vacation_brain_activated',
            $score < 1200 => 'checked_out',
            default => 'basically_at_the_airport',
        };
    }
}
