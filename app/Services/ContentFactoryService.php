<?php
declare(strict_types=1);

final class ContentFactoryService
{
    public function __construct(private PDO $pdo) {}

    public function createJob(int $adminId, string $contentType, int $count, array $input): int
    {
        $count = max(1, min(250, $count));
        $prompt = $this->pdo->query('SELECT id FROM ai_prompt_templates WHERE active=1 ORDER BY CASE WHEN slug="vacation-brain-universal-v1" THEN 0 ELSE 1 END,id LIMIT 1')->fetchColumn();
        if (!$prompt) throw new RuntimeException('AI prompt template is not installed.');
        $stmt = $this->pdo->prepare('INSERT INTO ai_generation_jobs (requested_by,prompt_template_id,content_type,requested_count,input_json,status) VALUES (?,?,?,?,?,"queued")');
        $stmt->execute([$adminId,$prompt,$contentType,$count,json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function job(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT j.*,p.name AS prompt_name,p.system_prompt,p.prompt_template,p.output_schema_json FROM ai_generation_jobs j JOIN ai_prompt_templates p ON p.id=j.prompt_template_id WHERE j.id=?');
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }

    public function renderPrompt(array $job): string
    {
        $input = json_decode((string)($job['input_json'] ?? ''), true) ?: [];
        $replace = [
            '{{content_type}}' => (string)$job['content_type'],
            '{{count}}' => (string)$job['requested_count'],
            '{{theme}}' => (string)($input['theme'] ?? 'general vacation daydreaming'),
            '{{audience}}' => (string)($input['audience'] ?? 'general adult travelers'),
            '{{tone}}' => (string)($input['tone'] ?? 'playful and sarcastic'),
            '{{notes}}' => (string)($input['notes'] ?? ''),
        ];
        $body = strtr((string)$job['prompt_template'], $replace);
        return trim((string)$job['system_prompt'])."\n\n".trim($body)."\n\nReturn ONLY valid JSON matching this output contract:\n".(string)$job['output_schema_json'];
    }

    public function importCandidates(int $jobId, string $json): int
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $items = array_is_list($decoded) ? $decoded : ($decoded['items'] ?? $decoded['candidates'] ?? null);
        if (!is_array($items) || !$items) throw new InvalidArgumentException('Paste a JSON array or an object with an items array.');
        $job = $this->job($jobId);
        if (!$job) throw new RuntimeException('Generation job not found.');

        $numberStmt = $this->pdo->prepare('SELECT COALESCE(MAX(candidate_number),0) FROM ai_generated_candidates WHERE job_id=?');
        $numberStmt->execute([$jobId]);
        $number = (int)$numberStmt->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO ai_generated_candidates (job_id,candidate_number,content_type,generated_json,quality_score,originality_score,humor_score,brand_fit_score,clarity_score,duplicate_score,safety_score,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,"needs_review")');
        $fingerprintFind = $this->pdo->prepare('SELECT content_id FROM content_fingerprints WHERE normalized_hash=? LIMIT 1');
        $created = 0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $number++;
            $scores = $item['scores'] ?? [];
            $hash = $this->contentHash($item);
            $fingerprintFind->execute([$hash]);
            $exactDuplicate = (bool)$fingerprintFind->fetchColumn();
            $duplicateScore = $exactDuplicate ? 100 : ($scores['duplicate'] ?? null);
            $insert->execute([
                $jobId,$number,$job['content_type'],json_encode($item,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                $scores['quality'] ?? null,$scores['originality'] ?? null,$scores['humor'] ?? null,$scores['brand_fit'] ?? null,$scores['clarity'] ?? null,$duplicateScore,$scores['safety'] ?? null,
            ]);
            $created++;
        }
        $this->pdo->prepare('UPDATE ai_generation_jobs SET status="completed",started_at=COALESCE(started_at,NOW()),completed_at=NOW() WHERE id=?')->execute([$jobId]);
        return $created;
    }

    public function review(int $candidateId, int $reviewerId, string $decision, string $notes = '', ?string $editedJson = null): void
    {
        $allowed = ['approve','reject','edit'];
        if (!in_array($decision,$allowed,true)) throw new InvalidArgumentException('Invalid review decision.');
        if ($editedJson !== null && trim($editedJson) !== '') {
            $decoded = json_decode($editedJson,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new InvalidArgumentException('Edited candidate must be a JSON object.');
            $this->pdo->prepare('UPDATE ai_generated_candidates SET generated_json=? WHERE id=?')->execute([json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$candidateId]);
        }
        $status = $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'needs_review');
        $this->pdo->prepare('UPDATE ai_generated_candidates SET status=?,review_notes=? WHERE id=?')->execute([$status,$notes,$candidateId]);
        $this->pdo->prepare('INSERT INTO content_reviews (candidate_id,reviewer_id,decision,notes) VALUES (?,?,?,?)')->execute([$candidateId,$reviewerId,$decision,$notes]);
    }

    public function publish(int $candidateId, int $reviewerId): int
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_generated_candidates WHERE id=? FOR UPDATE');
        $this->pdo->beginTransaction();
        try {
            $stmt->execute([$candidateId]);
            $candidate = $stmt->fetch();
            if (!$candidate) throw new RuntimeException('Candidate not found.');
            if ($candidate['published_content_id']) {
                $this->pdo->commit();
                return (int)$candidate['published_content_id'];
            }
            $data = json_decode((string)$candidate['generated_json'],true,512,JSON_THROW_ON_ERROR);
            $title = trim((string)($data['title'] ?? $data['name'] ?? 'Vacation Brain Content'));
            $body = trim((string)($data['body'] ?? $data['question'] ?? $data['text'] ?? $data['description'] ?? $title));
            if ($body === '') throw new InvalidArgumentException('Candidate needs usable content before publishing.');
            $slug = $this->uniqueSlug((string)($data['slug'] ?? $title));
            $short = trim((string)($data['short_body'] ?? $data['caption'] ?? '')) ?: null;
            $humor = max(0,min(5,(int)($data['humor_level'] ?? 3)));
            $sarcasm = max(0,min(5,(int)($data['sarcasm_level'] ?? 2)));
            $meta = $data;
            unset($meta['choices'],$meta['tags'],$meta['steps']);
            $insert = $this->pdo->prepare('INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,metadata_json,created_by,published_at) VALUES (?,?,?,?,?,"published",?,?,?,?,NOW())');
            $insert->execute([$candidate['content_type'],$slug,$title,$body,$short,$humor,$sarcasm,json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$reviewerId]);
            $contentId = (int)$this->pdo->lastInsertId();
            $hash=$this->contentHash($data);
            $semanticKey=$this->slugify((string)($data['theme'] ?? $candidate['content_type']));
            $this->pdo->prepare('INSERT INTO content_fingerprints (content_id,normalized_hash,semantic_key) VALUES (?,?,?)')->execute([$contentId,$hash,$semanticKey?:null]);

            $this->publishTags($contentId,$data['tags'] ?? []);
            $this->publishChoices($contentId,$data['choices'] ?? []);
            if ($candidate['content_type'] === 'substitution_prompt' || !empty($data['steps'])) {
                $this->publishSubstitution($contentId,$slug,$title,$body,$data);
            }
            if ($candidate['content_type'] === 'vacation_break' && !empty($data['duration_seconds'])) {
                $this->pdo->prepare('INSERT INTO vacation_break_details (content_id,duration_seconds,activity_text,ending_text,metadata_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE duration_seconds=VALUES(duration_seconds),activity_text=VALUES(activity_text),ending_text=VALUES(ending_text),metadata_json=VALUES(metadata_json)')
                    ->execute([$contentId,(int)$data['duration_seconds'],$data['activity_text']??null,$data['ending_text']??null,json_encode($data['break_details']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
            }

            $this->pdo->prepare('UPDATE ai_generated_candidates SET status="published",published_content_id=? WHERE id=?')->execute([$contentId,$candidateId]);
            $this->pdo->prepare('INSERT INTO content_reviews (candidate_id,reviewer_id,decision,notes) VALUES (?,?,"publish","Published to master content library")')->execute([$candidateId,$reviewerId]);
            $this->syncManifest((string)$candidate['content_type']);
            $this->pdo->commit();
            return $contentId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function publishTags(int $contentId, array $tags): void
    {
        $find = $this->pdo->prepare('SELECT id FROM tags WHERE slug=?');
        $create = $this->pdo->prepare('INSERT INTO tags (slug,name,tag_group) VALUES (?,? ,"generated")');
        $link = $this->pdo->prepare('INSERT IGNORE INTO content_tags (content_id,tag_id,weight) VALUES (?,?,?)');
        foreach ($tags as $tag) {
            $slug = is_array($tag) ? (string)($tag['slug'] ?? $tag['name'] ?? '') : (string)$tag;
            $weight = is_array($tag) ? (float)($tag['weight'] ?? 1) : 1.0;
            $slug = $this->slugify($slug);
            if ($slug === '') continue;
            $find->execute([$slug]);
            $id = $find->fetchColumn();
            if (!$id) { $create->execute([$slug,ucwords(str_replace('-',' ',$slug))]); $id=$this->pdo->lastInsertId(); }
            $link->execute([$contentId,$id,$weight]);
        }
    }

    private function publishChoices(int $contentId, array $choices): void
    {
        $choiceInsert = $this->pdo->prepare('INSERT INTO content_choices (content_id,choice_key,label,description,sort_order,metadata_json) VALUES (?,?,?,?,?,?)');
        $traitFind = $this->pdo->prepare('SELECT id FROM traits WHERE slug=?');
        $effect = $this->pdo->prepare('INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta)');
        foreach (array_values($choices) as $i=>$choice) {
            if (!is_array($choice)) continue;
            $label = trim((string)($choice['label'] ?? ''));
            if ($label === '') continue;
            $key = (string)($choice['choice_key'] ?? $choice['key'] ?? chr(65+$i));
            $meta = ['brain_points'=>(int)($choice['brain_points'] ?? 5)];
            $choiceInsert->execute([$contentId,$key,$label,$choice['description']??null,$i+1,json_encode($meta)]);
            $choiceId=(int)$this->pdo->lastInsertId();
            $effects = $choice['trait_effects'] ?? [];
            foreach ($effects as $traitKey=>$delta) {
                if (is_array($delta) && isset($delta['trait'])) {
                    $trait=(string)$delta['trait']; $score=$delta['score_delta']??0; $confidence=$delta['confidence_delta']??1;
                } else {
                    $trait=is_string($traitKey)?$traitKey:'';
                    if (is_array($delta)) { $score=$delta['score_delta']??0; $confidence=$delta['confidence_delta']??1; }
                    else { $score=$delta; $confidence=1; }
                }
                $trait=$this->traitSlug($trait);
                if ($trait==='') continue;
                $traitFind->execute([$trait]);
                $traitId=$traitFind->fetchColumn();
                if ($traitId) $effect->execute([$choiceId,$traitId,(float)$score,(float)$confidence]);
            }
        }
    }

    private function publishSubstitution(int $contentId, string $slug, string $title, string $body, array $data): void
    {
        $theme = trim((string)($data['theme'] ?? 'local escape')) ?: 'local escape';
        $duration = isset($data['duration_minutes']) ? (int)$data['duration_minutes'] : null;
        $budget = isset($data['budget_level']) ? max(1,min(5,(int)$data['budget_level'])) : null;
        $s = $this->pdo->prepare('INSERT INTO substitution_templates (slug,name,description,theme,duration_minutes,budget_level,content_id,status,metadata_json) VALUES (?,?,?,?,?,?,?,"published",?)');
        $s->execute([$slug,$title,$body,$theme,$duration,$budget,$contentId,json_encode($data['substitution_metadata']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        $sid=(int)$this->pdo->lastInsertId();
        $step=$this->pdo->prepare('INSERT INTO substitution_steps (substitution_id,step_number,activity_type,title,description,duration_minutes,recommended_time,required_tags_json,optional_tags_json) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach (array_values($data['steps']??[]) as $i=>$row) {
            if (!is_array($row)) continue;
            $step->execute([$sid,$i+1,$row['activity_type']??'activity',$row['title']??('Step '.($i+1)),$row['description']??null,$row['duration_minutes']??null,$row['recommended_time']??null,json_encode($row['required_tags']??[]),json_encode($row['optional_tags']??[])]);
        }
    }

    private function syncManifest(string $type): void
    {
        $stmt=$this->pdo->prepare('UPDATE seed_generation_manifest m SET approved_count=(SELECT COUNT(*) FROM ai_generated_candidates WHERE content_type=? AND status IN ("approved","published")),published_count=(SELECT COUNT(*) FROM ai_generated_candidates WHERE content_type=? AND status="published") WHERE m.content_type=?');
        $stmt->execute([$type,$type,$type]);
    }

    private function uniqueSlug(string $value): string
    {
        $base=$this->slugify($value) ?: 'vacation-brain-content';
        $slug=$base; $n=2;
        $stmt=$this->pdo->prepare('SELECT id FROM content_items WHERE slug=? LIMIT 1');
        while (true) { $stmt->execute([$slug]); if (!$stmt->fetch()) return $slug; $slug=$base.'-'.$n++; }
    }

    private function contentHash(array $data): string
    {
        $parts=[
            (string)($data['title'] ?? $data['name'] ?? ''),
            (string)($data['body'] ?? $data['question'] ?? $data['text'] ?? $data['description'] ?? ''),
        ];
        $normalized=strtolower(trim(implode(' ',array_filter($parts))));
        $normalized=preg_replace('/\s+/',' ',$normalized) ?? $normalized;
        $normalized=preg_replace('/[^a-z0-9 ]+/','',$normalized) ?? $normalized;
        return hash('sha256',$normalized);
    }

    private function traitSlug(string $value): string
    {
        $value=strtolower(trim($value));
        $value=preg_replace('/[^a-z0-9_]+/','_',$value) ?? '';
        return trim($value,'_');
    }

    private function slugify(string $value): string
    {
        $value=strtolower(trim($value));
        $value=preg_replace('/[^a-z0-9]+/','-',$value) ?? '';
        return trim($value,'-');
    }
}
