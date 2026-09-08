<?php
declare(strict_types=1);

final class SwipeService
{
    public function __construct(private PDO $pdo) {}

    public function nextCard(int $userId): ?array
    {
        $deck=$this->pdo->query("SELECT id FROM swipe_decks WHERE slug='vacation-brain-feed-v1' AND status='published' LIMIT 1")->fetchColumn();
        if (!$deck) return null;
        $sql='SELECT ci.id,ci.content_type,ci.title,ci.body,ci.short_body,ci.metadata_json
              FROM swipe_deck_items sdi JOIN content_items ci ON ci.id=sdi.content_id
              WHERE sdi.deck_id=? AND ci.status="published"
              AND NOT EXISTS (SELECT 1 FROM user_events ue WHERE ue.user_id=? AND ue.event_type="choice_selected" AND ue.content_id=ci.id)
              ORDER BY RAND() LIMIT 1';
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$deck,$userId]);$card=$stmt->fetch();
        if (!$card) {
            $stmt=$this->pdo->prepare('SELECT ci.id,ci.content_type,ci.title,ci.body,ci.short_body,ci.metadata_json FROM swipe_deck_items sdi JOIN content_items ci ON ci.id=sdi.content_id WHERE sdi.deck_id=? AND ci.status="published" ORDER BY RAND() LIMIT 1');
            $stmt->execute([$deck]);$card=$stmt->fetch();
        }
        if (!$card) return null;
        $c=$this->pdo->prepare('SELECT id,choice_key,label,description,metadata_json FROM content_choices WHERE content_id=? ORDER BY sort_order,id');
        $c->execute([$card['id']]);$card['choices']=$c->fetchAll();
        return $card;
    }

    public function answer(int $userId, int $contentId, int $choiceId): array
    {
        $stmt=$this->pdo->prepare('SELECT cc.id,cc.content_id,cc.choice_key,cc.label FROM content_choices cc JOIN content_items ci ON ci.id=cc.content_id WHERE cc.id=? AND cc.content_id=? AND ci.status="published" LIMIT 1');
        $stmt->execute([$choiceId,$contentId]);$choice=$stmt->fetch();
        if (!$choice) throw new InvalidArgumentException('That swipe choice is not available.');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,content_id,choice_id,value_text) VALUES (? ,"choice_selected",?,?,?)')->execute([$userId,$contentId,$choiceId,$choice['choice_key']]);
            $effects=$this->pdo->prepare('SELECT cte.*,t.slug FROM choice_trait_effects cte JOIN traits t ON t.id=cte.trait_id WHERE cte.choice_id=?');
            $effects->execute([$choiceId]);
            foreach($effects->fetchAll() as $effect){
                $existing=$this->pdo->prepare('SELECT score,confidence,interaction_count FROM user_traits WHERE user_id=? AND trait_id=? FOR UPDATE');
                $existing->execute([$userId,$effect['trait_id']]);$row=$existing->fetch();
                if($row){
                    $score=max(0,min(100,(float)$row['score']+(float)$effect['score_delta']));
                    $confidence=max(0,min(100,(float)$row['confidence']+(float)$effect['confidence_delta']));
                    $this->pdo->prepare('UPDATE user_traits SET score=?,confidence=?,interaction_count=interaction_count+1,last_updated_at=NOW() WHERE user_id=? AND trait_id=?')->execute([$score,$confidence,$userId,$effect['trait_id']]);
                } else {
                    $score=max(0,min(100,50+(float)$effect['score_delta']));
                    $confidence=max(0,min(100,8+(float)$effect['confidence_delta']));
                    $this->pdo->prepare('INSERT INTO user_traits (user_id,trait_id,score,confidence,interaction_count,last_updated_at) VALUES (?,?,?,?,1,NOW())')->execute([$userId,$effect['trait_id'],$score,$confidence]);
                }
            }
            $points=(new ScoreService($this->pdo))->award($userId,'choice_selected',2);
            $unlocked=(new AchievementService($this->pdo))->evaluate($userId);
            $this->pdo->commit();
            return ['points'=>$points,'unlocked'=>$unlocked];
        } catch(Throwable $e){ if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function stats(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM user_events WHERE user_id=? AND event_type="choice_selected"');$stmt->execute([$userId]);
        $answered=(int)$stmt->fetchColumn();
        $total=(int)$this->pdo->query("SELECT COUNT(*) FROM swipe_deck_items sdi JOIN swipe_decks sd ON sd.id=sdi.deck_id WHERE sd.slug='vacation-brain-feed-v1'")->fetchColumn();
        return ['answered'=>$answered,'total'=>$total];
    }
}
