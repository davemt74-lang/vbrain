<?php
declare(strict_types=1);

/**
 * Read-only, confirmation-safe Booking Inbox context for the main agent.
 * Only normalized parsed_json is read. Encrypted source text, confirmation codes,
 * traveler names, filenames, booking notes, and payment data are never selected.
 */
final class BookingImportAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool{return db_table_exists('trip_booking_imports')&&db_table_exists('dream_trips');}

    public function context(int $userId,int $limit=8): string
    {
        $rows=$this->active($userId,$limit);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$p=(array)$row['booking'];$bits=[];$bits[]=(string)$row['trip_name'];$bits[]=ucwords(str_replace('_',' ',(string)($p['booking_type']??'booking')));if(!empty($p['title']))$bits[]=(string)$p['title'];if(!empty($p['provider_name']))$bits[]='provider '.(string)$p['provider_name'];if(!empty($p['flight_number']))$bits[]='flight '.(string)$p['flight_number'];if(!empty($p['departure_iata'])||!empty($p['arrival_iata']))$bits[]=trim((string)($p['departure_iata']??'').' → '.(string)($p['arrival_iata']??''));if(!empty($p['starts_at']))$bits[]='starts '.(string)$p['starts_at'];if(!empty($p['ends_at']))$bits[]='ends '.(string)$p['ends_at'];if(isset($p['amount'])&&$p['amount']!=='')$bits[]=(string)($p['currency']??'USD').' '.number_format((float)$p['amount'],2);$bits[]='import '.str_replace('_',' ',(string)$row['status']);$parts[]=implode(' · ',$bits);}
        return 'BOOKING INBOX STATE (saved normalized booking facts only; no inbox/provider refresh): '.implode('; ',$parts).'. Imported reservations are user-supplied Booked records unless separately provider-verified elsewhere. Private source confirmations, confirmation codes, traveler names, filenames, booking notes, payment data, and encrypted source material are excluded.';
    }

    public function active(int $userId,int $limit=8): array
    {
        if(!$this->ready())return [];$limit=max(1,min(20,$limit));$q=$this->pdo->prepare("SELECT i.status,i.parsed_json,i.updated_at,dt.name trip_name FROM trip_booking_imports i JOIN dream_trips dt ON dt.id=i.dream_trip_id AND dt.user_id=i.user_id WHERE i.user_id=? AND i.booking_id IS NOT NULL AND i.status IN ('matched','verified') ORDER BY i.updated_at DESC,i.id DESC LIMIT $limit");$q->execute([$userId]);$out=[];foreach($q->fetchAll()?:[] as $row){$p=json_decode((string)($row['parsed_json']??''),true);if(!is_array($p))$p=[];unset($p['notes'],$p['source_note'],$p['confirmation_code'],$p['traveler_names']);$out[]=['status'=>(string)$row['status'],'trip_name'=>(string)$row['trip_name'],'booking'=>$p,'updated_at'=>(string)$row['updated_at']];}return $out;
    }
}
