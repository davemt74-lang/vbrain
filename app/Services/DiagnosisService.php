<?php
declare(strict_types=1);

final class DiagnosisService
{
    public const MIN_ANSWERS = 10;

    public function __construct(private PDO $pdo) {}

    public function deck(): array
    {
        $stmt = $this->pdo->query("SELECT id,name,description FROM swipe_decks WHERE slug='self-diagnosis-v1' AND status='published' LIMIT 1");
        $deck = $stmt->fetch();
        if (!$deck) throw new RuntimeException('Self-diagnosis deck is not installed.');
        return $deck;
    }

    public function questions(): array
    {
        $deck = $this->deck();
        $stmt = $this->pdo->prepare(
            'SELECT ci.id,ci.slug,ci.title,ci.body,ci.short_body,sdi.sort_order
             FROM swipe_deck_items sdi
             JOIN content_items ci ON ci.id=sdi.content_id
             WHERE sdi.deck_id=? AND ci.status="published"
             ORDER BY sdi.sort_order,ci.id'
        );
        $stmt->execute([$deck['id']]);
        $questions = $stmt->fetchAll();
        $choiceStmt = $this->pdo->prepare('SELECT id,choice_key,label,description,metadata_json FROM content_choices WHERE content_id=? ORDER BY sort_order,id');
        foreach ($questions as &$question) {
            $choiceStmt->execute([$question['id']]);
            $question['choices'] = $choiceStmt->fetchAll();
        }
        return $questions;
    }

    public function calculate(array $answerChoiceIds): array
    {
        $questions = $this->questions();
        $valid = [];
        $questionByChoice = [];
        $ranges = [];

        foreach ($questions as $question) {
            $qid = (int)$question['id'];
            $points = [];
            foreach ($question['choices'] as $choice) {
                $choiceId = (int)$choice['id'];
                $valid[$choiceId] = $choice;
                $questionByChoice[$choiceId] = $qid;
                $meta = json_decode((string)$choice['metadata_json'], true) ?: [];
                $points[] = (int)($meta['brain_points'] ?? 0);
            }
            $ranges[$qid] = [
                'min' => min($points ?: [0]),
                'max' => max($points ?: [0]),
            ];
        }

        $ids = array_values(array_unique(array_map('intval', $answerChoiceIds)));
        $answeredQuestions = [];
        $acceptedIds = [];
        $raw = 0;

        foreach ($ids as $choiceId) {
            if (!isset($valid[$choiceId])) continue;
            $qid = $questionByChoice[$choiceId];
            if (isset($answeredQuestions[$qid])) continue;

            $answeredQuestions[$qid] = true;
            $acceptedIds[] = $choiceId;
            $meta = json_decode((string)$valid[$choiceId]['metadata_json'], true) ?: [];
            $raw += (int)($meta['brain_points'] ?? 0);
        }

        $answeredCount = count($answeredQuestions);
        $totalQuestions = count($questions);
        if ($answeredCount < self::MIN_ANSWERS) {
            throw new InvalidArgumentException('Answer at least '.self::MIN_ANSWERS.' diagnosis cards before submitting.');
        }

        $minPoints = 0;
        $maxPoints = 0;
        foreach (array_keys($answeredQuestions) as $qid) {
            $range = $ranges[(int)$qid] ?? ['min' => 0, 'max' => 0];
            $minPoints += (int)$range['min'];
            $maxPoints += (int)$range['max'];
        }

        $range = max(1, $maxPoints - $minPoints);
        $ratio = max(0, min(1, ($raw - $minPoints) / $range));
        $diagnosisScore = (int)round(20 + ($ratio * 75));
        $brainScore = 100 + ($diagnosisScore * 8);
        $traits = $this->traitSnapshot($acceptedIds);
        $level = $this->level($diagnosisScore);
        $prescription = $this->prescription($traits);
        $confidence = $totalQuestions > 0 ? (int)round(($answeredCount / $totalQuestions) * 100) : 100;

        return [
            'diagnosis_score' => $diagnosisScore,
            'vacation_brain_score' => $brainScore,
            'level' => $level['slug'],
            'title' => $level['title'],
            'summary' => $level['summary'],
            'prescription' => $prescription,
            'traits' => $traits,
            'answers' => $acceptedIds,
            'raw_points' => $raw,
            'answered_count' => $answeredCount,
            'question_count' => $totalQuestions,
            'diagnosis_confidence' => max(1, min(100, $confidence)),
        ];
    }

    private function traitSnapshot(array $choiceIds): array
    {
        if (!$choiceIds) return [];
        $placeholders = implode(',', array_fill(0, count($choiceIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT t.slug,t.name,SUM(cte.score_delta) AS delta,SUM(cte.confidence_delta) AS confidence_delta
             FROM choice_trait_effects cte
             JOIN traits t ON t.id=cte.trait_id
             WHERE cte.choice_id IN ($placeholders)
             GROUP BY t.id,t.slug,t.name"
        );
        $stmt->execute($choiceIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $delta = (float)$row['delta'];
            $out[$row['slug']] = [
                'name' => $row['name'],
                'score' => max(0, min(100, 50 + $delta)),
                'delta' => $delta,
                'confidence' => min(100, (float)$row['confidence_delta'] * 8),
            ];
        }
        uasort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private function level(int $score): array
    {
        if ($score < 35) return ['slug'=>'early_signs','title'=>'Early Signs of Vacation Brain','summary'=>'You are still technically functioning, but the symptoms have started. A strategically placed long weekend may prevent escalation.'];
        if ($score < 55) return ['slug'=>'active','title'=>'Active Vacation Brain','summary'=>'Vacation thoughts are now interrupting normal operations. You can still discuss non-travel subjects, but only briefly.'];
        if ($score < 70) return ['slug'=>'advanced','title'=>'Advanced Vacation Brain','summary'=>'You are mentally packing. Destination research is no longer casual and your weather app may contain evidence.'];
        if ($score < 85) return ['slug'=>'severe','title'=>'Severe Vacation Brain','summary'=>'You are functionally one good package deal away from disappearing for five to seven business days.'];
        return ['slug'=>'fully_checked_out','title'=>'Fully Checked Out','summary'=>'Your body may still be here, but your brain has already requested a late checkout somewhere warmer.'];
    }

    private function prescription(array $traits): array
    {
        $score = fn(string $slug): float => (float)($traits[$slug]['score'] ?? 50);
        $items = ['4–7 nights somewhere that makes your weather app jealous.'];
        if ($score('morning_tolerance') < 46) $items[] = 'No scheduled activities before 10 AM.';
        if ($score('pool') > 56 || $score('resort_preference') > 56) $items[] = 'A pool is strongly recommended.';
        if ($score('direct_flight_preference') > 56) $items[] = 'Prefer the direct flight when the math is remotely defensible.';
        if ($score('food') > 56) $items[] = 'Plan at least one meal worth talking about afterward.';
        if ($score('adventure') > 57) $items[] = 'Include one activity that creates a good story.';
        if ($score('luxury') > 57) $items[] = 'One unnecessary upgrade is authorized.';
        return array_slice(array_values(array_unique($items)), 0, 4);
    }
}
