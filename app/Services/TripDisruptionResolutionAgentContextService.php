<?php
declare(strict_types=1);

/** Saved, privacy-safe resolution context for Vacation Brain. No provider or mailbox refresh. */
final class TripDisruptionResolutionAgentContextService
{
    public function __construct(private PDO $pdo) {}
    public function ready(): bool{return class_exists('TripDisruptionResolutionService')&&(new TripDisruptionResolutionService($this->pdo))->ready();}

    public function context(int $userId,int $limitTrips=3): string
    {
        if(!$this->ready())return '';$rows=(new TripDisruptionResolutionService($this->pdo))->safeAgentContext($userId,$limitTrips);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$bits=[];$bits[]=(string)$row['trip_name'];$bits[]=(int)$row['open_cases'].' open resolution case'.((int)$row['open_cases']===1?'':'s');$bits[]='role '.str_replace('_',' ',(string)$row['role']);
            if(($row['role']??'')==='owner'){
                $attention=(array)($row['attention']??[]);if(array_sum(array_map('intval',$attention))>0)$bits[]='attention: '.implode(', ',array_filter([!empty($attention['deadline_overdue'])?(int)$attention['deadline_overdue'].' overdue deadline'.((int)$attention['deadline_overdue']===1?'':'s'):null,!empty($attention['deadline_soon'])?(int)$attention['deadline_soon'].' deadline'.((int)$attention['deadline_soon']===1?'':'s').' soon':null,!empty($attention['followup_due'])?(int)$attention['followup_due'].' follow-up'.((int)$attention['followup_due']===1?'':'s').' due':null,!empty($attention['claim_needed'])?(int)$attention['claim_needed'].' claim decision'.((int)$attention['claim_needed']===1?'':'s'):null]));
                $money=[];foreach((array)($row['by_currency']??[]) as $currency=>$m){$money[]=$currency.' costs '.number_format((float)($m['costs']??0),2).', cash recovered '.number_format((float)($m['cash_received']??0),2).', cash outstanding '.number_format((float)($m['cash_outstanding']??0),2).', credits received '.number_format((float)($m['credits_received']??0),2);}if($money)$bits[]='aggregate recovery: '.implode(' / ',$money);
            }
            $parts[]=implode(' · ',$bits);
        }
        return 'DISRUPTION RESOLUTION + REFUND/CREDIT INTELLIGENCE (saved ledger only; no provider, mailbox, payment or claim refresh): '.implode('; ',$parts).'. Refunds, credits and reimbursements shown here are user-recorded expectations/receipts, not provider-verified money movement unless separately verified by the user. Any provider transaction still requires the existing explicit transaction approval flow. Vacation Brain must not claim, request, submit, refund, credit, pay, rebook, cancel or mutate a provider from this context. Financial detail is owner-only; collaborators receive status only. Private evidence notes/URLs, resolution notes, booking confirmation codes, payment data, provider credentials and mailbox tokens are excluded.';
    }
}
