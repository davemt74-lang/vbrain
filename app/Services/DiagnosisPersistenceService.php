<?php
declare(strict_types=1);

final class DiagnosisPersistenceService
{
    public function __construct(private PDO $pdo) {}

    public function record(int $userId,array $diagnosis): int
    {
        $this->pdo->beginTransaction();
        try{
            $traitId=$this->pdo->prepare('SELECT id FROM traits WHERE slug=?');
            $traitUpsert=$this->pdo->prepare('INSERT INTO user_traits (user_id,trait_id,score,confidence,interaction_count,last_updated_at) VALUES (?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE score=VALUES(score),confidence=GREATEST(confidence,VALUES(confidence)),interaction_count=interaction_count+1,last_updated_at=NOW()');
            foreach(($diagnosis['traits']??[]) as $slug=>$trait){$traitId->execute([(string)$slug]);$id=$traitId->fetchColumn();if($id)$traitUpsert->execute([$userId,(int)$id,(float)($trait['score']??50),(float)($trait['confidence']??0)]);}

            $event=$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,choice_id,value_numeric) VALUES (?,"choice_selected",?,2)');
            foreach(($diagnosis['answers']??[]) as $choiceId)$event->execute([$userId,(int)$choiceId]);
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric,value_text) VALUES (?,"diagnosis_completed",?,?)')->execute([$userId,(int)($diagnosis['diagnosis_score']??0),(string)($diagnosis['level']??'')]);

            $result=$this->pdo->prepare('INSERT INTO diagnosis_results (user_id,diagnosis_score,vacation_brain_score,diagnosis_level,diagnosis_title,summary_text,prescription_json,answer_snapshot_json,trait_snapshot_json) VALUES (?,?,?,?,?,?,?,?,?)');
            $result->execute([
                $userId,(int)($diagnosis['diagnosis_score']??0),(int)($diagnosis['vacation_brain_score']??0),(string)($diagnosis['level']??''),(string)($diagnosis['title']??''),(string)($diagnosis['summary']??''),
                json_encode($diagnosis['prescription']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                json_encode($diagnosis['answers']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                json_encode($diagnosis['traits']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            ]);
            $resultId=(int)$this->pdo->lastInsertId();

            $scoreStmt=$this->pdo->prepare('SELECT vacation_brain_score FROM user_score_summary WHERE user_id=? FOR UPDATE');$scoreStmt->execute([$userId]);$existing=(int)($scoreStmt->fetchColumn()?:0);$newDiagnosis=(int)($diagnosis['vacation_brain_score']??0);
            if($newDiagnosis>$existing)$this->pdo->prepare('UPDATE user_score_summary SET vacation_brain_score=?,updated_at=NOW() WHERE user_id=?')->execute([$newDiagnosis,$userId]);
            try{(new AchievementService($this->pdo))->evaluate($userId);}catch(Throwable){}
            $this->pdo->commit();
            return $resultId;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
