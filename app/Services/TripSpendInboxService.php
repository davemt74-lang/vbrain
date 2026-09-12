<?php
declare(strict_types=1);

/** Owner-only live-spend warnings projected into Unified Trip Inbox. */
final class TripSpendInboxService
{
    public function __construct(private PDO $pdo) {}
    public function ready(): bool{return db_table_exists('trip_inbox_items')&&class_exists('TripSpendExecutionService')&&(new TripSpendExecutionService($this->pdo))->ready();}
    public function syncUser(int $userId): array
    {
        if(!$this->ready())return ['trips'=>0,'items'=>0,'upgrade_required'=>true];$q=$this->pdo->prepare("SELECT id FROM dream_trips WHERE user_id=? AND status<>'abandoned' ORDER BY id");$q->execute([$userId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);$service=new TripSpendExecutionService($this->pdo);$items=0;
        foreach($ids as $tripId){try{$p=$service->inboxProjection($userId,$tripId);if($p===null){$this->resolve($userId,$tripId);continue;}$this->upsert($userId,$tripId,$p);$items++;}catch(Throwable $e){error_log('Live Spend Inbox sync failed for trip '.$tripId.': '.$e->getMessage());}}
        return ['trips'=>count($ids),'items'=>$items];
    }
    private function upsert(int $userId,int $tripId,array $p): void
    {
        $sourceKey=(string)($p['source_key']??$tripId);$thread=(string)($p['thread_key']??('spend-execution:'.$tripId));$type=(string)($p['item_type']??'risk');$priority=max(0,min(100,(int)($p['priority']??82)));$urgency=in_array((string)($p['urgency']??''),['now','today','before_trip','optional'],true)?(string)$p['urgency']:'before_trip';$title=$this->clip((string)($p['title']??'Trip spend needs attention'),180);$body=$this->clip((string)($p['body']??''),700);$label=$this->clip((string)($p['action_label']??'Review live spend'),80);$url=(string)($p['action_url']??app_url('trip-spend.php?id='.$tripId));$status=$this->clip((string)($p['source_status']??'spend_threshold'),48);$requires=!empty($p['requires_action'])?1:0;$occurred=(string)($p['occurred_at']??date('Y-m-d H:i:s'));if(!strtotime($occurred))$occurred=date('Y-m-d H:i:s');$fp=hash('sha256',json_encode([$tripId,$type,$priority,$urgency,$title,$body,$label,$url,$status,$requires],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $sql="INSERT INTO trip_inbox_items (user_id,dream_trip_id,source_type,source_key,thread_key,item_type,priority,urgency,title,body,action_label,action_url,source_status,requires_action,source_fingerprint,occurred_at) VALUES (?,?,'spend_execution',?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE dream_trip_id=VALUES(dream_trip_id),thread_key=VALUES(thread_key),item_type=VALUES(item_type),priority=VALUES(priority),urgency=VALUES(urgency),title=VALUES(title),body=VALUES(body),action_label=VALUES(action_label),action_url=VALUES(action_url),source_status=VALUES(source_status),requires_action=VALUES(requires_action),occurred_at=VALUES(occurred_at),read_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,read_at),resolved_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,resolved_at),snoozed_until=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,snoozed_until),source_fingerprint=VALUES(source_fingerprint),updated_at=NOW()";$this->pdo->prepare($sql)->execute([$userId,$tripId,$sourceKey,$thread,$type,$priority,$urgency,$title,$body,$label,$url,$status,$requires,$fp,$occurred]);
    }
    private function resolve(int $userId,int $tripId): void{$this->pdo->prepare("UPDATE trip_inbox_items SET resolved_at=COALESCE(resolved_at,NOW()),requires_action=0,source_status='within_pace',source_fingerprint=SHA2(CONCAT('spend-execution-within-pace:',dream_trip_id),256),snoozed_until=NULL,updated_at=NOW() WHERE user_id=? AND dream_trip_id=? AND source_type='spend_execution' AND source_key=? AND resolved_at IS NULL")->execute([$userId,$tripId,(string)$tripId]);}
    private function clip(string $v,int $max): string{$v=trim(preg_replace('/\s+/u',' ',$v)??$v);return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);}
}
