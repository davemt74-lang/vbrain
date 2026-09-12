<?php
declare(strict_types=1);

/** Safe read-only context from normalized Trip Inbox projections. */
final class TripUnifiedInboxAgentContextService
{
    public function __construct(private PDO $pdo) {}
    public function ready(): bool{return db_table_exists('trip_inbox_items')&&db_table_exists('trip_inbox_trip_preferences');}

    public function context(int $userId,int $limit=8): string
    {
        if(!$this->ready())return '';$rows=(new TripUnifiedInboxService($this->pdo))->safeAgentContext($userId,$limit);if(!$rows)return '';$parts=[];
        foreach($rows as $r){$bits=[];$trip=trim((string)($r['trip_name']??''));if($trip!=='')$bits[]=$trip;$bits[]=ucwords(str_replace('_',' ',(string)$r['item_type']));$bits[]='urgency '.str_replace('_',' ',(string)$r['urgency']);$bits[]=(string)$r['title'];if(!empty($r['requires_action']))$bits[]='needs user attention';if(!empty($r['due_at']))$bits[]='due '.(string)$r['due_at'];$parts[]=implode(' · ',$bits);}
        return 'UNIFIED TRIP INBOX STATE (saved normalized projection only; no provider, mailbox, device-location, or external refresh): '.implode('; ',$parts).'. Inbox Resolve/Snooze/Read state is local presentation state and does not dismiss, approve, execute, cancel, purchase, modify, or otherwise mutate the underlying source system. Open the canonical source flow for approvals and booking changes. Raw Gmail content, OAuth tokens, confirmation codes, traveler names from private documents, provider operational references, payment data, private booking notes, exact device coordinates, and private Traveler Memory are excluded.';
    }

    public function fallback(int $userId,int $limit=8): array
    {
        if(!$this->ready())return [];return (new TripUnifiedInboxService($this->pdo))->safeAgentContext($userId,$limit);
    }
}
