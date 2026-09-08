<?php
declare(strict_types=1);

final class SubstitutionService
{
    public function __construct(private PDO $pdo) {}

    public function published(): array
    {
        $stmt = $this->pdo->query('SELECT st.*, d.city AS destination_city, d.country AS destination_country FROM substitution_templates st LEFT JOIN destinations d ON d.id=st.destination_id WHERE st.status="published" ORDER BY st.id');
        return $stmt->fetchAll();
    }

    public function bySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT st.*, d.city AS destination_city, d.country AS destination_country FROM substitution_templates st LEFT JOIN destinations d ON d.id=st.destination_id WHERE st.slug=? AND st.status="published" LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $steps = $this->pdo->prepare('SELECT * FROM substitution_steps WHERE substitution_id=? ORDER BY step_number');
        $steps->execute([$row['id']]);
        $row['steps'] = $steps->fetchAll();
        return $row;
    }

    public function localMatches(array $substitution, string $city): array
    {
        $city = trim(explode(',', $city)[0] ?? '');
        if ($city === '') return [];
        $out = [];
        foreach (($substitution['steps'] ?? []) as $step) {
            $tags = json_decode((string)($step['required_tags_json'] ?? ''), true) ?: [];
            $tags = array_values(array_filter(array_map(fn($v)=>preg_replace('/[^a-z0-9_-]+/','',strtolower((string)$v)), $tags)));
            if (!$tags) { $out[(int)$step['id']] = []; continue; }
            $ph = implode(',', array_fill(0,count($tags),'?'));
            $sampleClause=(db_column_exists('places','is_sample')&&!sample_data_enabled())?' AND p.is_sample=0':'';
            $sql = 'SELECT p.id,p.name,p.place_type,p.description,p.address,p.city,p.region,p.website_url,p.booking_url,p.price_level,COUNT(pt.tag_id) AS tag_matches
                 FROM places p JOIN place_tags pt ON pt.place_id=p.id JOIN tags t ON t.id=pt.tag_id
                 WHERE p.active=1'.$sampleClause.' AND LOWER(p.city)=LOWER(?) AND t.slug IN ('.$ph.')
                 GROUP BY p.id ORDER BY tag_matches DESC,p.name LIMIT 3';
            $q=$this->pdo->prepare($sql);
            $q->execute(array_merge([$city],$tags));
            $out[(int)$step['id']]=$q->fetchAll();
        }
        return $out;
    }

    public function recordView(int $userId, array $substitution, ?string $city = null): void
    {
        $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_text,metadata_json) VALUES (? ,"substitution_viewed", ?, ?)')
            ->execute([$userId, $substitution['slug'], json_encode(['substitution_id'=>(int)$substitution['id'],'city'=>$city], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }

    public function complete(int $userId, array $substitution, ?string $city = null): array
    {
        $todayStart = date('Y-m-d 00:00:00');
        $check = $this->pdo->prepare('SELECT id FROM user_events WHERE user_id=? AND event_type="substitution_completed" AND value_text=? AND occurred_at>=? LIMIT 1');
        $check->execute([$userId,$substitution['slug'],$todayStart]);
        if ($check->fetch()) {
            return ['created'=>false,'message'=>'Already counted today. Recreating the same fake vacation twice is dedication, not extra credit.'];
        }

        $pointsStmt = $this->pdo->query('SELECT points FROM score_rules WHERE event_type="substitution_completed" AND active=1 ORDER BY id DESC LIMIT 1');
        $points = (int)($pointsStmt->fetchColumn() ?: 15);
        $this->pdo->beginTransaction();
        try {
            $meta = json_encode(['substitution_id'=>(int)$substitution['id'],'city'=>$city], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric,value_text,metadata_json) VALUES (? ,"substitution_completed", ?, ?, ?)')
                ->execute([$userId,$points,$substitution['slug'],$meta]);
            $this->pdo->prepare('INSERT INTO user_score_summary (user_id,vacation_brain_score,score_level) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vacation_brain_score=vacation_brain_score+VALUES(vacation_brain_score),updated_at=NOW()')
                ->execute([$userId,$points,'thinking_about_it']);
            $scoreStmt=$this->pdo->prepare('SELECT vacation_brain_score FROM user_score_summary WHERE user_id=?');
            $scoreStmt->execute([$userId]);
            $score=(int)$scoreStmt->fetchColumn();
            $level=match(true){$score<300=>'thinking_about_it',$score<500=>'needs_a_break',$score<700=>'mentally_packing',$score<900=>'vacation_brain_activated',$score<1200=>'checked_out',default=>'basically_at_the_airport'};
            $this->pdo->prepare('UPDATE user_score_summary SET score_level=? WHERE user_id=?')->execute([$level,$userId]);
            (new AchievementService($this->pdo))->evaluate($userId);
            $this->pdo->commit();
            return ['created'=>true,'points'=>$points,'message'=>"Vacation substitution completed. +{$points} Vacation Brain points. Not a real flight was boarded."];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
