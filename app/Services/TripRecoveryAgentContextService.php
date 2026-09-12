<?php
declare(strict_types=1);

/** Read-only, public-safe Recovery & Rebooking Intelligence for Vacation Brain agents. */
final class TripRecoveryAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return class_exists('TripRecoveryIntelligenceService')&&(new TripRecoveryIntelligenceService($this->pdo))->ready();
    }

    public function context(int $userId,int $limitTrips=3): string
    {
        if(!$this->ready())return '';$rows=(new TripRecoveryIntelligenceService($this->pdo))->safeAgentContext($userId,$limitTrips);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$inc=[];foreach((array)$row['incidents'] as $i)$inc[]=ucwords(str_replace('_',' ',(string)$i['type'])).' / '.(string)$i['severity'].' / '.(string)$i['title'];$parts[]=(string)$row['trip_name'].' · role '.(string)$row['role'].' · '.(int)$row['open_incidents'].' open · '.implode('; ',$inc);}
        return 'RECOVERY & REBOOKING INTELLIGENCE (saved public-safe recovery state only; no provider, mailbox, payment, or device-location refresh): '.implode(' | ',$parts).'. Recovery options are research and approval preparation, not completed transactions. Never claim a flight, hotel, transport, reservation, cancellation, refund, purchase, checkout, or provider change happened unless the canonical Booking & Action ledger says it completed. Indicative airfare is planning data, not guaranteed inventory. Provider-hosted recovery checkout still requires explicit transaction approval and completion on the provider site.';
    }
}
