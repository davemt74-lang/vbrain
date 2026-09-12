<?php
declare(strict_types=1);

/** Read-only aggregate affordability context. Never refreshes providers or exposes private ledger text. */
final class TripAffordabilityAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return class_exists('TripAffordabilityService') && (new TripAffordabilityService($this->pdo))->ready();
    }

    public function context(int $userId,int $limit=3): string
    {
        if(!$this->ready())return '';$rows=(new TripAffordabilityService($this->pdo))->safeAgentContext($userId,$limit);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$bits=[];$bits[]=(string)$row['trip_name'];$bits[]='role '.str_replace('_',' ',(string)$row['role']);$bits[]='risk '.str_replace('_',' ',(string)$row['risk_status']);$currency=(string)($row['currency']??'USD');if($row['target_budget']!==null)$bits[]='target '.$currency.' '.number_format((float)$row['target_budget'],0);$bits[]='saved booking value '.$currency.' '.number_format((float)$row['saved_booking_value'],0);$bits[]='forecast '.$currency.' '.number_format((float)$row['expected_final'],0);if($row['headroom']!==null)$bits[]='headroom '.$currency.' '.number_format((float)$row['headroom'],0);$bits[]='confidence '.(string)$row['confidence'];$drivers=[];foreach((array)($row['top_drivers']??[]) as $d)$drivers[]=str_replace('_',' ',(string)$d['category']).' '.$currency.' '.number_format((float)$d['expected'],0);if($drivers)$bits[]='drivers '.implode(', ',$drivers);$fits=[];foreach((array)($row['fit_options']??[]) as $f)$fits[]=(string)$f['title'].' (~'.$currency.' '.number_format((float)$f['estimated_savings'],0).' estimated savings)';if($fits)$bits[]='non-destructive fit options '.implode(', ',$fits);$parts[]=implode(' · ',$bits);}
        return 'AFFORDABILITY INTELLIGENCE (saved planning state only; no provider refresh): '.implode('; ',$parts).'. Forecasts are planning estimates, not quotes or verified bank settlement. Currencies are never converted implicitly. Do not expose merchant names, expense notes, Trip Memory narrative, confirmation codes, payment data, claims evidence, or private disruption detail. You may explain tradeoffs and suggest non-destructive ways to fit the target budget, but booking, cancellation, payment, refund, claim, credit redemption, checkout and provider mutation remain outside this context and require their canonical explicit approval flows.';
    }
}
