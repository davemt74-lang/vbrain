<?php
declare(strict_types=1);

/** Privacy-safe aggregate live-spend context for the main Vacation Brain agent. */
final class TripSpendExecutionAgentContextService
{
    public function __construct(private PDO $pdo) {}
    public function ready(): bool{return class_exists('TripSpendExecutionService')&&(new TripSpendExecutionService($this->pdo))->ready();}
    public function context(int $userId,int $limit=3): string
    {
        if(!$this->ready())return '';$rows=(new TripSpendExecutionService($this->pdo))->safeAgentContext($userId,$limit);if(!$rows)return '';$parts=[];
        foreach($rows as $r){$bits=[(string)$r['trip_name'],'recorded actual '.(string)$r['currency'].' '.number_format((float)$r['actual_spend'],2),'pace '.str_replace('_',' ',(string)$r['pace_status'])];if($r['target_budget']!==null)$bits[]='target '.(string)$r['currency'].' '.number_format((float)$r['target_budget'],2);if($r['remaining_budget']!==null)$bits[]='remaining '.(string)$r['currency'].' '.number_format((float)$r['remaining_budget'],2);if($r['remaining_daily_allowance']!==null)$bits[]='remaining daily allowance '.(string)$r['currency'].' '.number_format((float)$r['remaining_daily_allowance'],2);if($r['planning_expected_final']!==null)$bits[]='planning expected final '.(string)$r['currency'].' '.number_format((float)$r['planning_expected_final'],2);$parts[]=implode(' · ',$bits);}
        return 'LIVE TRIP SPEND + BUDGET EXECUTION (owner-only aggregate ledger context): '.implode('; ',$parts).'. Actual spend means owner-entered non-void ledger entries, not bank settlement. Merchant names, notes, receipt references, booking IDs and traveler attribution identities are excluded. Planning expected final remains a forecast and must not be described as settled spend. Currencies are never converted implicitly. Suggest only non-destructive budget adjustments; never claim to charge, refund, cancel, rebook, submit a claim or move money. Use canonical explicit approval flows for any supported provider action.';
    }
}
