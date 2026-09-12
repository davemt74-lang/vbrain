<?php
declare(strict_types=1);

/** Read-only, normalized itinerary context for the main Vacation Brain agent. */
final class TripItineraryAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return class_exists('TripItineraryIntelligenceService') && (new TripItineraryIntelligenceService($this->pdo))->ready();
    }

    public function context(int $userId,int $limitTrips=3): string
    {
        if(!$this->ready())return '';$rows=(new TripItineraryIntelligenceService($this->pdo))->safeAgentContext($userId,$limitTrips);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$bits=[];$bits[]=(string)$row['trip_name'].' ('.ucwords(str_replace('_',' ',(string)$row['role'])).')';$bits[]='day '.(string)$row['selected_date'];$issues=[];if((int)$row['high_issues']>0)$issues[]=(int)$row['high_issues'].' high';if((int)$row['medium_issues']>0)$issues[]=(int)$row['medium_issues'].' medium';if($issues)$bits[]='timing issues '.implode('/',$issues);$next=[];foreach((array)$row['next'] as $item){$label=(string)$item['time'].' '.$item['title'];if(!empty($item['leave_by']))$label.=' · leave by '.date('g:i A',strtotime((string)$item['leave_by'])?:time());$next[]=$label;}if($next)$bits[]='next: '.implode(', ',$next);$parts[]=implode(' · ',$bits);}
        return 'ITINERARY INTELLIGENCE (saved trip facts + local derived timing only; no provider, mailbox, or device-location refresh): '.implode('; ',$parts).'. Fixed reservations are never silently moved. Flexible sequence suggestions are advisory until the owner or Co-planner explicitly applies them. Timing/conflict calculations may use saved public venue coordinates, but exact current-device coordinates are never part of this context. Do not claim a reservation, purchase, cancellation, or provider change was made from itinerary intelligence.';
    }
}
