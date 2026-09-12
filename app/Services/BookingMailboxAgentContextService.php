<?php
declare(strict_types=1);

/** Read-only normalized reservation-change context. Never reads mailbox source, OAuth tokens, sender/account identity, or confirmation codes. */
final class BookingMailboxAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool{return db_table_exists('booking_change_proposals')&&db_table_exists('trip_bookings')&&db_table_exists('dream_trips');}

    public function context(int $userId,int $limit=8): string
    {
        if(!$this->ready())return '';$rows=(new BookingMailboxService($this->pdo))->safeAgentContext($userId,$limit);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$bits=[];$bits[]=(string)$row['trip_name'];$bits[]=(string)$row['booking_title'];$bits[]=ucwords(str_replace('_',' ',(string)$row['change_type']));$bits[]='review '.str_replace('_',' ',(string)$row['status']);$changes=[];foreach((array)($row['diff']??[]) as $field=>$change){$label=ucwords(str_replace('_',' ',$field));$to=is_array($change)?($change['to']??null):null;if($to!==null&&$to!=='')$changes[]=$label.' → '.$to;}if($changes)$bits[]=implode(', ',array_slice($changes,0,5));$parts[]=implode(' · ',$bits);}
        return 'CONNECTED BOOKING INBOX STATE (saved normalized change ledger only; no mailbox/provider refresh): '.implode('; ',$parts).'. Needs review means Vacation Brain has not changed the canonical reservation yet. Applied means the user accepted the provider-reported factual update. This context excludes OAuth tokens, mailbox source, account/sender identity, confirmation codes, traveler names, booking notes, and payment data. Chat never scans Gmail or applies a reservation change.';
    }
}
