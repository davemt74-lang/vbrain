<?php
declare(strict_types=1);

/** Read-only aggregate Trip Cost Intelligence context. Never refreshes providers or exposes private ledger text. */
final class TripCostIntelligenceAgentContextService
{
    public function __construct(private PDO $pdo) {}
    public function ready(): bool{return class_exists('TripCostIntelligenceService')&&(new TripCostIntelligenceService($this->pdo))->ready();}

    public function context(int $userId,int $limitTrips=3): string
    {
        if(!$this->ready())return '';$service=new TripCostIntelligenceService($this->pdo);$rows=$service->safeAgentContext($userId,$limitTrips);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$bits=[];$bits[]=(string)$row['trip_name'];$bits[]='role '.str_replace('_',' ',(string)$row['role']);if(array_key_exists('target_budget',$row)&&$row['target_budget']!==null)$bits[]='shared target '.(string)$row['currency'].' '.number_format((float)$row['target_budget'],2);foreach((array)($row['booking_totals']??[]) as $currency=>$amount)$bits[]='saved booking value '.$currency.' '.number_format((float)$amount,2);
            if(($row['role']??'')==='owner')foreach((array)($row['economics']??[]) as $currency=>$e){$line=$currency.' structured gross cost '.number_format((float)($e['structured_gross_cost']??0),2).', cash recovered '.number_format((float)($e['cash_recovered']??0),2).', structured net cost '.number_format((float)($e['structured_net_cost']??0),2).', provider credits received '.number_format((float)($e['credits_received']??0),2);if(($e['reported_actual']??null)!==null)$line.=', traveler-reported actual '.number_format((float)$e['reported_actual'],2);if(($e['budget_variance']??null)!==null)$line.=', budget variance '.number_format((float)$e['budget_variance'],2);$bits[]=$line;}
            $parts[]=implode(' · ',$bits);
        }
        return 'TRIP COST INTELLIGENCE (saved aggregate ledger only; no provider, payment, mailbox, refund or FX refresh): '.implode('; ',$parts).'. Each currency is independent; never imply conversion between currencies. Saved booking amounts are booking values, not verified bank-settled cash. Provider credits are non-cash and must not be described as cash recovered. Traveler-reported actual spend and private manual-expense details are owner-only. Manual expense text, merchant identity, Trip Memory private text, resolution evidence, confirmation codes, payment data and provider credentials are excluded. Cost analysis never authorizes a booking, cancellation, payment, refund, credit redemption or provider mutation; those require the existing explicit transaction approval path.';
    }
}
