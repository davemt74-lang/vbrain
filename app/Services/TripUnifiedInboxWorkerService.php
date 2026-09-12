<?php
declare(strict_types=1);

/** Fair background scheduler for Unified Trip Inbox projections. Reuses the existing travel worker. */
final class TripUnifiedInboxWorkerService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_inbox_preferences')
            && db_column_exists('trip_inbox_preferences','last_synced_at')
            && (new TripUnifiedInboxService($this->pdo))->ready();
    }

    public function runDue(int $limit=20): array
    {
        if(!$this->ready())return ['users'=>0,'items'=>0,'errors'=>0,'upgrade_required'=>true];$limit=max(1,min(100,$limit));
        $sql="SELECT c.user_id
            FROM (
              SELECT DISTINCT user_id FROM dream_trips WHERE status<>'abandoned'
              UNION
              SELECT DISTINCT user_id FROM trip_collaborators WHERE status='active'
            ) c
            LEFT JOIN trip_inbox_preferences p ON p.user_id=c.user_id
            ORDER BY COALESCE(p.last_synced_at,'1970-01-01 00:00:00') ASC,c.user_id ASC
            LIMIT {$limit}";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[];$out=['users'=>0,'items'=>0,'errors'=>0];$inbox=new TripUnifiedInboxService($this->pdo);$affordability=class_exists('TripAffordabilityInboxService')?new TripAffordabilityInboxService($this->pdo):null;$spend=null;
        try{if(!class_exists('TripSpendInboxService')){require_once __DIR__.'/TripSpendExecutionService.php';require_once __DIR__.'/TripSpendInboxService.php';}if(class_exists('TripSpendInboxService'))$spend=new TripSpendInboxService($this->pdo);}catch(Throwable){}
        foreach($rows as $uid){$uid=(int)$uid;$out['users']++;try{$r=$inbox->syncUser($uid);$out['items']+=(int)($r['items']??0);if($affordability&&$affordability->ready()){$a=$affordability->syncUser($uid);$out['items']+=(int)($a['items']??0);}if($spend&&$spend->ready()){$s=$spend->syncUser($uid);$out['items']+=(int)($s['items']??0);}}catch(Throwable $e){$out['errors']++;error_log('Unified Trip Inbox worker sync failed for user '.$uid.': '.$e->getMessage());}finally{$this->markSynced($uid);}}
        return $out;
    }

    private function markSynced(int $userId): void
    {
        try{$this->pdo->prepare("INSERT INTO trip_inbox_preferences (user_id,last_synced_at) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE last_synced_at=NOW()")->execute([$userId]);}catch(Throwable $e){error_log('Unified Trip Inbox worker cursor update failed: '.$e->getMessage());}
    }
}
