<?php
declare(strict_types=1);

final class TravelMessageService
{
    public function __construct(private PDO $pdo) {}

    public function match(int $matchId,int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT tm.*,CASE WHEN tm.user_a_id=? THEN tm.user_b_id ELSE tm.user_a_id END partner_id FROM travel_matches tm WHERE tm.id=? AND (tm.user_a_id=? OR tm.user_b_id=?) LIMIT 1');
        $stmt->execute([$userId,$matchId,$userId,$userId]);$row=$stmt->fetch();return $row?:null;
    }

    public function contacts(int $userId,string $search='',bool $archived=false): array
    {
        $search=trim($search);$params=[$userId,$userId,$userId,$userId,$userId];
        $sql='SELECT tm.*,CASE WHEN tm.user_a_id=? THEN tm.user_b_id ELSE tm.user_a_id END partner_id,
            COALESCE(pref.pinned,0) pinned,COALESCE(pref.archived,0) archived,
            (SELECT m.body FROM travel_match_messages m WHERE m.match_id=tm.id ORDER BY m.id DESC LIMIT 1) latest_message,
            (SELECT m.sender_user_id FROM travel_match_messages m WHERE m.match_id=tm.id ORDER BY m.id DESC LIMIT 1) latest_sender_id,
            (SELECT m.created_at FROM travel_match_messages m WHERE m.match_id=tm.id ORDER BY m.id DESC LIMIT 1) latest_message_at,
            (SELECT COUNT(*) FROM travel_match_messages m WHERE m.match_id=tm.id AND m.sender_user_id<>? AND m.read_at IS NULL) unread_count
            FROM travel_matches tm
            LEFT JOIN travel_match_contact_preferences pref ON pref.match_id=tm.id AND pref.user_id=?
            WHERE (tm.user_a_id=? OR tm.user_b_id=?) AND tm.status="active" AND COALESCE(pref.archived,0)=?';
        $params[]=$archived?1:0;
        if($search!==''){
            $sql.=' AND EXISTS (SELECT 1 FROM users su WHERE su.id=CASE WHEN tm.user_a_id=? THEN tm.user_b_id ELSE tm.user_a_id END AND su.display_name LIKE ?)';
            $params[]=$userId;$params[]='%'.$search.'%';
        }
        $sql.=' ORDER BY COALESCE(pref.pinned,0) DESC,COALESCE(tm.last_message_at,tm.matched_at) DESC';
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();$matchService=new TravelMatchService($this->pdo);
        foreach($rows as &$row){$row['partner']=$matchService->profile((int)$row['partner_id']);$row['compatibility']=['score'=>(int)($row['compatibility_score']??50)];}
        unset($row);return $rows;
    }

    public function setContactPreference(int $userId,int $matchId,string $action): void
    {
        $this->requireActiveMatch($matchId,$userId);if(!in_array($action,['pin','unpin','archive','unarchive'],true))throw new InvalidArgumentException('Unknown inbox action.');
        $this->pdo->prepare('INSERT INTO travel_match_contact_preferences (user_id,match_id) VALUES (?,?) ON DUPLICATE KEY UPDATE updated_at=NOW()')->execute([$userId,$matchId]);
        if($action==='pin')$this->pdo->prepare('UPDATE travel_match_contact_preferences SET pinned=1 WHERE user_id=? AND match_id=?')->execute([$userId,$matchId]);
        if($action==='unpin')$this->pdo->prepare('UPDATE travel_match_contact_preferences SET pinned=0 WHERE user_id=? AND match_id=?')->execute([$userId,$matchId]);
        if($action==='archive')$this->pdo->prepare('UPDATE travel_match_contact_preferences SET archived=1,pinned=0 WHERE user_id=? AND match_id=?')->execute([$userId,$matchId]);
        if($action==='unarchive')$this->pdo->prepare('UPDATE travel_match_contact_preferences SET archived=0 WHERE user_id=? AND match_id=?')->execute([$userId,$matchId]);
    }

    public function messages(int $matchId,int $userId,int $afterId=0,int $limit=100): array
    {
        $this->requireActiveMatch($matchId,$userId);$limit=max(1,min(200,$limit));
        $sql='SELECT m.id,m.match_id,m.sender_user_id,m.parent_message_id,m.message_type,m.body,m.metadata_json,m.read_at,m.created_at,u.display_name,u.avatar_url,pm.body parent_body,pu.display_name parent_sender_name FROM travel_match_messages m JOIN users u ON u.id=m.sender_user_id LEFT JOIN travel_match_messages pm ON pm.id=m.parent_message_id LEFT JOIN users pu ON pu.id=pm.sender_user_id WHERE m.match_id=? AND m.id>? ORDER BY m.id ASC LIMIT '.$limit;
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$matchId,max(0,$afterId)]);$rows=$stmt->fetchAll();$this->markRead($matchId,$userId);$this->attachReactions($rows,$userId);
        foreach($rows as &$row){$row['mine']=(int)$row['sender_user_id']===$userId;$row['metadata']=$this->decode((string)($row['metadata_json']??''));unset($row['metadata_json']);}unset($row);return $rows;
    }

    public function send(int $matchId,int $senderId,string $body,int $parentMessageId=0,string $messageType='text',array $metadata=[],bool $notify=true): array
    {
        $match=$this->requireActiveMatch($matchId,$senderId);$body=trim(preg_replace('/\r\n?/',"\n",$body)??$body);if($body==='')throw new InvalidArgumentException('Write a message first.');if(strlen($body)>2000)throw new InvalidArgumentException('Keep Travel Match messages under 2,000 characters.');
        $allowedTypes=['text','profile_reaction','shared_question','dream','system'];if(!in_array($messageType,$allowedTypes,true))$messageType='text';
        if($parentMessageId>0){$q=$this->pdo->prepare('SELECT id FROM travel_match_messages WHERE id=? AND match_id=?');$q->execute([$parentMessageId,$matchId]);if(!$q->fetchColumn())throw new InvalidArgumentException('That reply target is not in this conversation.');}else{$parentMessageId=0;}
        $rate=$this->pdo->prepare('SELECT COUNT(*) FROM travel_match_messages WHERE match_id=? AND sender_user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)');$rate->execute([$matchId,$senderId]);if((int)$rate->fetchColumn()>=30)throw new RuntimeException('You have sent a lot of messages very quickly. Give the conversation a minute.');
        $first=$this->pdo->prepare('SELECT COUNT(*) FROM travel_match_messages WHERE match_id=? AND sender_user_id=?');$first->execute([$matchId,$senderId]);$isFirst=(int)$first->fetchColumn()===0;
        $stmt=$this->pdo->prepare('INSERT INTO travel_match_messages (match_id,sender_user_id,parent_message_id,message_type,body,metadata_json) VALUES (?,?,?,?,?,?)');$stmt->execute([$matchId,$senderId,$parentMessageId?:null,$messageType,$body,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);$messageId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE travel_matches SET first_message_at=COALESCE(first_message_at,NOW()),last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$matchId]);$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric,value_text) VALUES (? ,"travel_match_message_sent",?,?)')->execute([$senderId,$matchId,substr($body,0,255)]);
        if($isFirst){$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric) VALUES (? ,"travel_match_first_message",?)')->execute([$senderId,$matchId]);(new AchievementService($this->pdo))->evaluate($senderId);}
        try{$engagement=new MatchEngagementService($this->pdo);$engagement->recordActivity($matchId,$senderId,'message');$engagement->refreshInsights($matchId,$senderId);}catch(Throwable $e){}
        $partner=(int)$match['partner_id'];if($notify){$sender=current_user();$name=(string)($sender['display_name']??'Your Travel Match');(new NotificationService($this->pdo))->create($partner,'match_message','New message from '.$name,substr($body,0,180),'match-chat.php?match='.$matchId,$senderId,$matchId);}
        $rows=$this->messages($matchId,$senderId,$messageId-1,1);return $rows[0]??['id'=>$messageId,'match_id'=>$matchId,'sender_user_id'=>$senderId,'body'=>$body,'created_at'=>date('Y-m-d H:i:s'),'mine'=>true];
    }

    public function reactToMessage(int $matchId,int $userId,int $messageId,string $reaction): array
    {
        $this->requireActiveMatch($matchId,$userId);$allowed=['same','laugh','love','red_flag'];if(!in_array($reaction,$allowed,true))throw new InvalidArgumentException('Unknown reaction.');
        $s=$this->pdo->prepare('SELECT sender_user_id,body FROM travel_match_messages WHERE id=? AND match_id=?');$s->execute([$messageId,$matchId]);$m=$s->fetch();if(!$m)throw new InvalidArgumentException('Message not found.');
        $exists=$this->pdo->prepare('SELECT 1 FROM travel_match_message_reactions WHERE message_id=? AND user_id=? AND reaction_type=?');$exists->execute([$messageId,$userId,$reaction]);
        if($exists->fetchColumn())$this->pdo->prepare('DELETE FROM travel_match_message_reactions WHERE message_id=? AND user_id=? AND reaction_type=?')->execute([$messageId,$userId,$reaction]);else{$this->pdo->prepare('INSERT INTO travel_match_message_reactions (message_id,user_id,reaction_type) VALUES (?,?,?)')->execute([$messageId,$userId,$reaction]);if((int)$m['sender_user_id']!==$userId)(new NotificationService($this->pdo))->create((int)$m['sender_user_id'],'message_reaction','Your Travel Match reacted to a message',ucwords(str_replace('_',' ',$reaction)).': '.substr((string)$m['body'],0,140),'match-chat.php?match='.$matchId,$userId,$matchId);}
        $rows=$this->messages($matchId,$userId,$messageId-1,1);return $rows[0]??[];
    }

    public function reactToProfileResponse(int $matchId,int $reactorId,int $targetId,int $contentId,int $choiceId,string $reaction): int
    {
        $match=$this->requireActiveMatch($matchId,$reactorId);if((int)$match['partner_id']!==$targetId)throw new InvalidArgumentException('That profile is not this Travel Match.');$labels=['same'=>'Same','explain'=>'Explain yourself','red_flag'=>'This is a red flag','argue'=>'We are going to argue about this'];if(!isset($labels[$reaction]))throw new InvalidArgumentException('Unknown profile reaction.');
        $q=$this->pdo->prepare('SELECT ci.body,ci.title,cc.label FROM user_events ue JOIN content_choices cc ON cc.id=ue.choice_id JOIN content_items ci ON ci.id=cc.content_id WHERE ue.user_id=? AND ue.event_type="choice_selected" AND cc.content_id=? AND cc.id=? ORDER BY ue.id DESC LIMIT 1');$q->execute([$targetId,$contentId,$choiceId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('That Vacation Brain response is no longer available.');
        $this->pdo->prepare('INSERT INTO travel_match_profile_reactions (match_id,reactor_user_id,target_user_id,content_id,target_choice_id,reaction_type) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE target_choice_id=VALUES(target_choice_id),reaction_type=VALUES(reaction_type),updated_at=NOW()')->execute([$matchId,$reactorId,$targetId,$contentId,$choiceId,$reaction]);
        $question=trim((string)($r['body']?:$r['title']));$answer=(string)$r['label'];$body=$labels[$reaction]." — “".$question."”\nTheir answer: “".$answer."”";
        $message=$this->send($matchId,$reactorId,$body,0,'profile_reaction',['content_id'=>$contentId,'choice_id'=>$choiceId,'reaction'=>$reaction,'question'=>$question,'answer'=>$answer],false);
        $name=(string)(current_user()['display_name']??'Your Travel Match');(new NotificationService($this->pdo))->create($targetId,'profile_response_reaction',$name.' reacted to your Vacation Brain answer',$labels[$reaction].': '.substr($question,0,160),'match-chat.php?match='.$matchId,$reactorId,$matchId);
        return (int)($message['id']??0);
    }

    public function unreadCount(int $userId): int
    { $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM travel_match_messages m JOIN travel_matches tm ON tm.id=m.match_id WHERE tm.status="active" AND (tm.user_a_id=? OR tm.user_b_id=?) AND m.sender_user_id<>? AND m.read_at IS NULL');$stmt->execute([$userId,$userId,$userId]);return (int)$stmt->fetchColumn(); }

    public function markRead(int $matchId,int $userId): void
    { $this->pdo->prepare('UPDATE travel_match_messages SET read_at=NOW() WHERE match_id=? AND sender_user_id<>? AND read_at IS NULL')->execute([$matchId,$userId]); }

    public function reportMessage(int $reporter,int $matchId,int $messageId,string $reason,string $details=''): void
    {
        $this->requireActiveMatch($matchId,$reporter);$stmt=$this->pdo->prepare('SELECT sender_user_id,body FROM travel_match_messages WHERE id=? AND match_id=?');$stmt->execute([$messageId,$matchId]);$message=$stmt->fetch();if(!$message||(int)$message['sender_user_id']===$reporter)throw new InvalidArgumentException('That message cannot be reported.');$reported=(int)$message['sender_user_id'];$allowed=['harassment','spam','fake_profile','unsafe_behavior','underage_concern','other'];if(!in_array($reason,$allowed,true))$reason='other';$details=trim($details);if($details==='')$details='Reported from Travel Match conversation.';if(strlen($details)>1000)$details=substr($details,0,1000);$this->pdo->prepare('INSERT INTO travel_match_reports (reporter_user_id,reported_user_id,reported_message_id,reason,details) VALUES (?,?,?,?,?)')->execute([$reporter,$reported,$messageId,$reason,$details]);(new TravelMatchService($this->pdo))->act($reporter,$reported,'block');
    }

    private function attachReactions(array &$rows,int $userId): void
    {
        $ids=array_values(array_filter(array_map(fn($r)=>(int)$r['id'],$rows)));if(!$ids)return;$ph=implode(',',array_fill(0,count($ids),'?'));$s=$this->pdo->prepare('SELECT message_id,reaction_type,user_id FROM travel_match_message_reactions WHERE message_id IN ('.$ph.') ORDER BY created_at');$s->execute($ids);$by=[];foreach($s->fetchAll() as $r){$mid=(int)$r['message_id'];$type=(string)$r['reaction_type'];$by[$mid][$type]['count']=($by[$mid][$type]['count']??0)+1;if((int)$r['user_id']===$userId)$by[$mid][$type]['mine']=true;}foreach($rows as &$row)$row['reactions']=$by[(int)$row['id']]??[];unset($row);
    }

    private function requireActiveMatch(int $matchId,int $userId): array
    { $match=$this->match($matchId,$userId);if(!$match)throw new RuntimeException('Travel Match conversation not found.');if(($match['status']??'')!=='active')throw new RuntimeException('This Travel Match conversation is no longer active.');return $match; }
    private function decode(string $json): array { if($json==='')return[];$v=json_decode($json,true);return is_array($v)?$v:[]; }
}
