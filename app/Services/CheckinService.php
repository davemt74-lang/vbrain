<?php
declare(strict_types=1);

final class CheckinService
{
    public function __construct(private PDO $pdo) {}

    public function checkIn(int $userId, string $response): array
    {
        $today = date('Y-m-d');
        $existing = $this->pdo->prepare('SELECT id FROM daily_checkins WHERE user_id=? AND checkin_date=?');
        $existing->execute([$userId,$today]);
        if ($existing->fetch()) return ['created'=>false,'message'=>'Already counted. Apparently you are still thinking about vacation.'];

        $pointsStmt = $this->pdo->prepare('SELECT points FROM score_rules WHERE event_type="daily_checkin" AND active=1 ORDER BY id DESC LIMIT 1');
        $pointsStmt->execute();
        $points = (int)($pointsStmt->fetchColumn() ?: 10);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $prev = $this->pdo->prepare('SELECT id FROM daily_checkins WHERE user_id=? AND checkin_date=?');
        $prev->execute([$userId,$yesterday]);
        $hadYesterday = (bool)$prev->fetch();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO daily_checkins (user_id,checkin_date,response,score_awarded) VALUES (?,?,?,?)')->execute([$userId,$today,$response,$points]);
            $summaryStmt = $this->pdo->prepare('SELECT vacation_brain_score,current_streak,longest_streak,lifetime_checkins FROM user_score_summary WHERE user_id=? FOR UPDATE');
            $summaryStmt->execute([$userId]);
            $summary = $summaryStmt->fetch() ?: ['vacation_brain_score'=>0,'current_streak'=>0,'longest_streak'=>0,'lifetime_checkins'=>0];
            $streak = $hadYesterday ? ((int)$summary['current_streak'] + 1) : 1;
            $longest = max($streak,(int)$summary['longest_streak']);
            $score = (int)$summary['vacation_brain_score'] + $points;
            $level = $this->scoreLevel($score);
            $this->pdo->prepare('UPDATE user_score_summary SET vacation_brain_score=?,current_streak=?,longest_streak=?,lifetime_checkins=lifetime_checkins+1,score_level=? WHERE user_id=?')->execute([$score,$streak,$longest,$level,$userId]);
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric,value_text) VALUES (?,"daily_checkin",?,?)')->execute([$userId,$points,$response]);
            (new AchievementService($this->pdo))->evaluate($userId);
            $this->pdo->commit();
            return ['created'=>true,'score'=>$score,'streak'=>$streak,'message'=>'Counted. Your Vacation Brain continues to progress exactly as expected.'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function scoreLevel(int $score): string
    {
        return match (true) {
            $score < 300 => 'thinking_about_it', $score < 500 => 'needs_a_break', $score < 700 => 'mentally_packing',
            $score < 900 => 'vacation_brain_activated', $score < 1200 => 'checked_out', default => 'basically_at_the_airport',
        };
    }
}
