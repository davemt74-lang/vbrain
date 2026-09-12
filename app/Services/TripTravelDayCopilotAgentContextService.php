<?php
declare(strict_types=1);

/** Read-only, normalized Travel Day Copilot context for the main Vacation Brain agent. */
final class TripTravelDayCopilotAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return class_exists('TripTravelDayCopilotService') && (new TripTravelDayCopilotService($this->pdo))->ready();
    }

    public function context(int $userId,int $limitTrips=3): string
    {
        if(!$this->ready())return '';$rows=(new TripTravelDayCopilotService($this->pdo))->safeAgentContext($userId,$limitTrips);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$bits=[];$bits[]='trip '.$row['trip_id'].' · '.$row['date'].' · '.str_replace('_',' ',(string)$row['role']);
            $focus=$row['focus']??null;if($focus){$bits[]='focus '.$focus['title'].' at '.$focus['time'];if(!empty($focus['countdown']))$bits[]=$focus['countdown'];if(($focus['traveler_state']??'on_time')!=='on_time')$bits[]='traveler '.str_replace('_',' ',(string)$focus['traveler_state']);}
            if((int)$row['high_ripples']>0)$bits[]=(int)$row['high_ripples'].' high disruption/timing issue'.((int)$row['high_ripples']===1?'':'s');
            if((int)$row['required_checklist_open']>0)$bits[]=(int)$row['required_checklist_open'].' required checklist item'.((int)$row['required_checklist_open']===1?'':'s').' open';$parts[]=implode(' · ',$bits);
        }
        return 'TRAVEL DAY COPILOT (saved itinerary + traveler-declared state only; no provider, mailbox, or device-location refresh): '.implode('; ',$parts).'. You may explain what is next, saved leave-by timing, checklist state and derived disruption risk. Do not claim live traffic/security conditions, expose confirmation/payment/private provider data, or execute booking, cancellation, purchase, refund, checkout or provider changes from Copilot context.';
    }
}
