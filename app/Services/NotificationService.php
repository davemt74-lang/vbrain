<?php
declare(strict_types=1);

final class NotificationService
{
    public function __construct(private PDO $pdo) {}

    public function create(int $userId,string $type,string $title,string $body='',?string $url=null,?int $actorId=null,?int $matchId=null,?string $uniqueKey=null): void
    {
        if($userId<=0 || !$this->allows($userId,$type))return;
        $title=trim($title);$body=trim($body);
        if(strlen($title)>180)$title=substr($title,0,180);if(strlen($body)>600)$body=substr($body,0,600);
        if($uniqueKey!==null && strlen($uniqueKey)>180)$uniqueKey=substr($uniqueKey,0,180);
        $sql='INSERT INTO user_notifications (user_id,notification_type,actor_user_id,match_id,title,body,action_url,unique_key) VALUES (?,?,?,?,?,?,?,?)';
        if($uniqueKey!==null)$sql.=' ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),action_url=VALUES(action_url)';
        $this->pdo->prepare($sql)->execute([$userId,$type,$actorId,$matchId,$title,$body?:null,$url,$uniqueKey]);
    }

    public function preferences(int $userId): array
    {
        $defaults=['new_matches'=>1,'new_messages'=>1,'daily_match_question'=>1,'match_discoveries'=>1,'streak_reminders'=>1,'daily_checkin'=>1,'achievements_merch'=>1,'profile_reactions'=>1];
        $s=$this->pdo->prepare('SELECT * FROM user_notification_preferences WHERE user_id=?');$s->execute([$userId]);$row=$s->fetch();return $row?array_merge($defaults,$row):$defaults;
    }

    public function savePreferences(int $userId,array $input): array
    {
        $keys=['new_matches','new_messages','daily_match_question','match_discoveries','streak_reminders','daily_checkin','achievements_merch','profile_reactions'];$v=[];foreach($keys as $k)$v[$k]=!empty($input[$k])?1:0;
        $this->pdo->prepare('INSERT INTO user_notification_preferences (user_id,new_matches,new_messages,daily_match_question,match_discoveries,streak_reminders,daily_checkin,achievements_merch,profile_reactions) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE new_matches=VALUES(new_matches),new_messages=VALUES(new_messages),daily_match_question=VALUES(daily_match_question),match_discoveries=VALUES(match_discoveries),streak_reminders=VALUES(streak_reminders),daily_checkin=VALUES(daily_checkin),achievements_merch=VALUES(achievements_merch),profile_reactions=VALUES(profile_reactions)')->execute([$userId,$v['new_matches'],$v['new_messages'],$v['daily_match_question'],$v['match_discoveries'],$v['streak_reminders'],$v['daily_checkin'],$v['achievements_merch'],$v['profile_reactions']]);return$v;
    }

    public function allows(int $userId,string $type): bool
    {
        $map=['travel_match'=>'new_matches','match_message'=>'new_messages','message_reaction'=>'profile_reactions','profile_response_reaction'=>'profile_reactions','match_discovery'=>'match_discoveries','match_achievement'=>'achievements_merch','daily_match_question'=>'daily_match_question','streak_reminder'=>'streak_reminders','daily_checkin'=>'daily_checkin','achievement'=>'achievements_merch','merch_unlock'=>'achievements_merch'];
        $key=$map[$type]??null;if($key===null)return true;$p=$this->preferences($userId);return !empty($p[$key]);
    }

    public function unreadCount(int $userId): int
    {
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND read_at IS NULL');$s->execute([$userId]);return (int)$s->fetchColumn();
    }

    public function recent(int $userId,int $limit=60,bool $unreadOnly=false): array
    {
        $limit=max(1,min(100,$limit));$sql='SELECT n.*,u.display_name actor_name,u.avatar_url actor_avatar FROM user_notifications n LEFT JOIN users u ON u.id=n.actor_user_id WHERE n.user_id=?'.($unreadOnly?' AND n.read_at IS NULL':'').' ORDER BY n.created_at DESC,n.id DESC LIMIT '.$limit;
        $s=$this->pdo->prepare($sql);$s->execute([$userId]);return $s->fetchAll();
    }

    public function markRead(int $userId,int $id): ?string
    {
        $s=$this->pdo->prepare('SELECT action_url FROM user_notifications WHERE id=? AND user_id=?');$s->execute([$id,$userId]);$url=$s->fetchColumn();
        if($url===false)return null;$this->pdo->prepare('UPDATE user_notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$id,$userId]);return $url? (string)$url:null;
    }

    public function markAllRead(int $userId): void
    { $this->pdo->prepare('UPDATE user_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$userId]); }
}
