<?php
declare(strict_types=1);

/** Read-only booking and transaction state for the main Vacation Brain agent. No provider refresh. */
final class BookingActionAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_booking_action_intents')&&db_table_exists('trip_booking_action_quotes');
    }

    public function context(int $userId,int $limit=6): string
    {
        $sections=[];$rows=$this->active($userId,$limit);
        if($rows){$parts=[];foreach($rows as $row){$bits=[];$bits[]=(string)$row['trip_name'];$bits[]=ucwords(str_replace('_',' ',(string)$row['action_type'])).' via '.ucwords(str_replace('_',' ',(string)$row['provider_slug']));$bits[]='status '.str_replace('_',' ',(string)$row['status']);if($row['amount']!==null)$bits[]=(string)($row['currency']?:'USD').' '.number_format((float)$row['amount'],2);if($row['fee_amount']!==null)$bits[]='fee '.(string)($row['currency']?:'USD').' '.number_format((float)$row['fee_amount'],2);if(!empty($row['source_state']))$bits[]='source '.str_replace('_',' ',(string)$row['source_state']);if(!empty($row['expires_at']))$bits[]='quote expires '.(string)$row['expires_at'];$parts[]=implode(' · ',$bits);}$sections[]='BOOKING ACTION STATE (saved transaction ledger only; no provider refresh): '.implode('; ',$parts).'. Planning approval is separate from transaction approval. Never claim a checkout, booking, purchase, modification, or cancellation completed unless its ledger state is completed. A provider-handoff completed state is user-confirmed Booked, not provider-verified Confirmed. verification_pending means the destructive-action outcome is uncertain and must not be retried until the provider state is checked. Provider order/reservation references, encrypted provider state, confirmation codes, booking notes, and payment data are excluded.';}
        try{if(class_exists('BookingImportAgentContextService')){$imports=new BookingImportAgentContextService($this->pdo);if($imports->ready()){$ctx=$imports->context($userId,8);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('BookingMailboxAgentContextService')){$mail=new BookingMailboxAgentContextService($this->pdo);if($mail->ready()){$ctx=$mail->context($userId,8);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('TripItineraryAgentContextService')){$itinerary=new TripItineraryAgentContextService($this->pdo);if($itinerary->ready()){$ctx=$itinerary->context($userId,3);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('TripTravelDayCopilotAgentContextService')){$copilot=new TripTravelDayCopilotAgentContextService($this->pdo);if($copilot->ready()){$ctx=$copilot->context($userId,3);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('TripRecoveryAgentContextService')){$recovery=new TripRecoveryAgentContextService($this->pdo);if($recovery->ready()){$ctx=$recovery->context($userId,3);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('TripDisruptionResolutionAgentContextService')){$resolution=new TripDisruptionResolutionAgentContextService($this->pdo);if($resolution->ready()){$ctx=$resolution->context($userId,3);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('TripCostIntelligenceAgentContextService')){$costs=new TripCostIntelligenceAgentContextService($this->pdo);if($costs->ready()){$ctx=$costs->context($userId,3);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('TripAffordabilityAgentContextService')){$affordability=new TripAffordabilityAgentContextService($this->pdo);if($affordability->ready()){$ctx=$affordability->context($userId,3);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        try{if(class_exists('TripUnifiedInboxAgentContextService')){$inbox=new TripUnifiedInboxAgentContextService($this->pdo);if($inbox->ready()){$ctx=$inbox->context($userId,8);if($ctx!=='')$sections[]=$ctx;}}}catch(Throwable){}
        return implode("\n\n",$sections);
    }

    /** Pending actions plus the most recent completed result, using only safe ledger columns. */
    public function active(int $userId,int $limit=6): array
    {
        if(!$this->ready())return [];$limit=max(1,min(20,$limit));
        $sql="SELECT i.id,i.dream_trip_id,i.action_type,i.provider_slug,i.adapter_mode,i.status,q.amount,q.currency,q.fee_amount,q.source_state,q.expires_at,t.name trip_name
            FROM trip_booking_action_intents i
            JOIN dream_trips t ON t.id=i.dream_trip_id AND t.user_id=i.user_id
            LEFT JOIN trip_booking_action_quotes q ON q.id=i.current_quote_id
            WHERE i.user_id=? AND i.status IN ('draft','prepared','awaiting_approval','approved','executing','handoff_pending','verification_pending','failed','completed')
            ORDER BY FIELD(i.status,'verification_pending','awaiting_approval','approved','executing','handoff_pending','failed','prepared','draft','completed'),i.updated_at DESC,i.id DESC LIMIT $limit";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$userId]);return $stmt->fetchAll()?:[];
    }
}
