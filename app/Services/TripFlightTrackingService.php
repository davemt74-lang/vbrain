<?php
declare(strict_types=1);

final class TripFlightTrackingService
{
    public function __construct(private PDO $pdo) {}
    public function ready(): bool{return db_table_exists('trip_bookings')&&db_column_exists('trip_bookings','flight_number')&&db_column_exists('trip_bookings','departure_iata')&&db_column_exists('trip_bookings','arrival_iata');}

    public function rows(int $userId,int $tripId): array
    {
        if(!$this->ready())return [];$this->assertTrip($userId,$tripId);$q=$this->pdo->prepare("SELECT id,title,provider_name,status,starts_at,flight_number,departure_iata,arrival_iata FROM trip_bookings WHERE user_id=? AND dream_trip_id=? AND booking_type='flight' AND status<>'cancelled' ORDER BY COALESCE(starts_at,'9999-12-31'),id");$q->execute([$userId,$tripId]);return $q->fetchAll()?:[];
    }

    public function save(int $userId,int $tripId,int $bookingId,array $input): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade for live flight tracking.');$this->assertTrip($userId,$tripId);$q=$this->pdo->prepare("SELECT id FROM trip_bookings WHERE id=? AND user_id=? AND dream_trip_id=? AND booking_type='flight' LIMIT 1");$q->execute([$bookingId,$userId,$tripId]);if(!$q->fetchColumn())throw new OutOfBoundsException('Flight booking not found.');
        $flight=preg_replace('/\s+/','',strtoupper(trim((string)($input['flight_number']??''))))??'';if($flight!==''&&!preg_match('/^[A-Z0-9]{2,12}$/',$flight))throw new InvalidArgumentException('Use an airline flight number such as AA123 or WN2048.');$dep=$this->iata((string)($input['departure_iata']??''));$arr=$this->iata((string)($input['arrival_iata']??''));
        $this->pdo->prepare('UPDATE trip_bookings SET flight_number=?,departure_iata=?,arrival_iata=?,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?')->execute([$flight!==''?$flight:null,$dep?:null,$arr?:null,$bookingId,$userId,$tripId]);
        if(db_table_exists('trip_booking_events')){try{$this->pdo->prepare('INSERT INTO trip_booking_events (trip_booking_id,user_id,dream_trip_id,event_type,metadata_json) VALUES (?,?,?,?,?)')->execute([$bookingId,$userId,$tripId,'flight_tracking_updated',json_encode(['flight_number'=>$flight,'departure_iata'=>$dep,'arrival_iata'=>$arr],JSON_UNESCAPED_SLASHES)]);}catch(Throwable){}}
    }

    private function assertTrip(int $userId,int $tripId): void{$q=$this->pdo->prepare('SELECT id FROM dream_trips WHERE id=? AND user_id=? LIMIT 1');$q->execute([$tripId,$userId]);if(!$q->fetchColumn())throw new OutOfBoundsException('Trip not found.');}
    private function iata(string $value): string{$value=strtoupper(trim($value));if($value==='')return '';if(!preg_match('/^[A-Z]{3}$/',$value))throw new InvalidArgumentException('Airport codes must be three letters.');return $value;}
}
