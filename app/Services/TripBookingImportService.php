<?php
declare(strict_types=1);

/**
 * Private Booking Inbox ingestion and normalization.
 *
 * Source confirmation text is encrypted at rest and never enters ordinary agent
 * context. Payment-card-like data is redacted before the retained source copy is
 * encrypted. AI parsing is opt-in per import and receives only the redacted text.
 * Canonical reservations continue to live in trip_bookings.
 */
final class TripBookingImportService
{
    private const MAX_FILE_BYTES=8_388_608;
    private const MAX_TEXT_BYTES=120_000;
    private const TYPES=['flight','lodging','transport','event','restaurant','activity','document','other'];
    private const STATUSES=['imported','parsed','matched','needs_review','verified','rejected'];
    private array $config;

    public function __construct(private PDO $pdo)
    {
        global $config;
        $this->config=is_array($config??null)?$config:[];
    }

    public function ready(): bool
    {
        return db_table_exists('trip_booking_imports')
            && db_table_exists('trip_booking_import_events')
            && db_table_exists('trip_bookings');
    }

    public function inbox(int $userId,int $limit=100): array
    {
        if(!$this->ready())return [];
        $limit=max(1,min(200,$limit));
        $q=$this->pdo->prepare("SELECT i.id,i.dream_trip_id,i.booking_id,i.status,i.source_type,i.original_filename,i.mime_type,i.source_size,i.parsed_json,i.parser_mode,i.ai_used,i.match_confidence,i.match_reason,i.error_message,i.verified_at,i.created_at,i.updated_at,dt.name trip_name,b.title booking_title,b.status booking_status
            FROM trip_booking_imports i
            LEFT JOIN dream_trips dt ON dt.id=i.dream_trip_id AND dt.user_id=i.user_id
            LEFT JOIN trip_bookings b ON b.id=i.booking_id AND b.user_id=i.user_id
            WHERE i.user_id=?
            ORDER BY FIELD(i.status,'needs_review','parsed','imported','matched','verified','rejected'),i.updated_at DESC,i.id DESC LIMIT $limit");
        $q->execute([$userId]);$rows=$q->fetchAll()?:[];
        foreach($rows as &$row)$row=$this->decorate($row,false);unset($row);
        return $rows;
    }

    public function get(int $userId,int $importId,bool $includeSensitive=false): ?array
    {
        if(!$this->ready()||$importId<1)return null;
        $q=$this->pdo->prepare("SELECT i.*,dt.name trip_name,b.title booking_title,b.status booking_status FROM trip_booking_imports i LEFT JOIN dream_trips dt ON dt.id=i.dream_trip_id AND dt.user_id=i.user_id LEFT JOIN trip_bookings b ON b.id=i.booking_id AND b.user_id=i.user_id WHERE i.id=? AND i.user_id=? LIMIT 1");
        $q->execute([$importId,$userId]);$row=$q->fetch();
        return $row?$this->decorate($row,$includeSensitive):null;
    }

    public function trips(int $userId): array
    {
        $q=$this->pdo->prepare("SELECT id,name,status,start_date,end_date,origin_name,origin_iata,destination_iata,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.destination_name')),'') destination_name FROM dream_trips WHERE user_id=? AND status NOT IN ('abandoned','completed') ORDER BY start_date IS NULL,start_date,updated_at DESC,id DESC");
        $q->execute([$userId]);return $q->fetchAll()?:[];
    }

    /** Receive pasted text or a confirmation file and optionally auto-link a high-confidence match. */
    public function receive(int $userId,array $input,?array $file=null): array
    {
        $this->requireReady();
        $pasted=trim((string)($input['source_text']??''));
        $selectedTrip=max(0,(int)($input['trip_id']??0));
        $useAi=!empty($input['use_ai']);
        $autoAdd=!array_key_exists('auto_add',$input)||!empty($input['auto_add']);
        $sourceType='paste';$filename=null;$mime='text/plain';$sourceSize=strlen($pasted);$sourceForHash=$pasted;$text=$pasted;$fileNote='';

        if($file&&((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)){
            [$filename,$mime,$sourceSize,$fileHashMaterial,$fileText,$fileNote]=$this->readUpload($file);
            $sourceType='file';$sourceForHash=$fileHashMaterial;
            if(trim($fileText)!=='')$text=trim($text."\n\n".$fileText);
        }
        if(trim($text)===''&&$sourceType==='paste')throw new InvalidArgumentException('Paste a booking confirmation or choose a confirmation file.');
        if(strlen($text)>self::MAX_TEXT_BYTES)$text=substr($text,0,self::MAX_TEXT_BYTES);
        $redacted=$this->redactPaymentData($text);
        if($redacted==='')$redacted=$fileNote!==''?$fileNote:'Confirmation source received; no parseable text was retained.';
        $sourceHash=hash('sha256',$sourceType.'|'.($filename??'').'|'.$sourceForHash);

        $existing=$this->bySourceHash($userId,$sourceHash);
        if($existing){$this->event((int)$existing['id'],$userId,'duplicate',['source_type'=>$sourceType]);return $this->decorate($existing,true)+['duplicate'=>true];}

        $parsed=$this->parseLocal($redacted);
        $parserMode='local';$aiUsed=0;
        if($useAi&&trim($text)!==''){
            $ai=$this->parseWithAi($userId,$redacted);
            if($ai){$parsed=$this->mergeParsed($parsed,$ai);$parserMode='local_ai';$aiUsed=1;}
        }
        [$parsed,$sensitive]=$this->splitSensitive($parsed);
        [$tripId,$confidence,$reason]=$this->matchTrip($userId,$parsed,$selectedTrip);
        $hasUseful=$this->hasUsefulParse($parsed,$sensitive);
        $status=$tripId>0&&$hasUseful?'matched':($hasUseful?'needs_review':'needs_review');
        if(!$hasUseful&&$fileNote!=='')$parsed['source_note']=$fileNote;
        $fingerprint=$this->bookingFingerprint($parsed,$sensitive);

        $encryptedSource=$this->encrypt($redacted);
        $encryptedSensitive=$sensitive?$this->encrypt(json_encode($sensitive,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)):null;
        try{
            $q=$this->pdo->prepare("INSERT INTO trip_booking_imports (user_id,dream_trip_id,status,source_type,original_filename,mime_type,source_size,source_hash,source_encrypted,parsed_json,sensitive_encrypted,parser_mode,ai_used,match_confidence,match_reason,booking_fingerprint,error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $q->execute([$userId,$tripId?:null,$status,$sourceType,$filename,$mime,$sourceSize,$sourceHash,$encryptedSource,json_encode($parsed,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$encryptedSensitive,$parserMode,$aiUsed,$confidence,$reason,$fingerprint,$hasUseful?null:'Vacation Brain could not safely extract enough booking details. Review the source and enter the missing fields.']);
            $importId=(int)$this->pdo->lastInsertId();
        }catch(PDOException $e){
            if((string)$e->getCode()==='23000'){
                $dupe=$fingerprint?$this->byFingerprint($userId,$fingerprint):$this->bySourceHash($userId,$sourceHash);
                if($dupe){$this->event((int)$dupe['id'],$userId,'duplicate',['source_type'=>$sourceType]);return $this->decorate($dupe,true)+['duplicate'=>true];}
            }
            throw $e;
        }
        $this->event($importId,$userId,'received',['source_type'=>$sourceType,'mime_type'=>$mime,'ai_requested'=>$useAi]);
        $this->event($importId,$userId,'parsed',['parser_mode'=>$parserMode,'booking_type'=>$parsed['booking_type']??'other']);
        if($tripId>0)$this->event($importId,$userId,'matched',['dream_trip_id'=>$tripId,'confidence'=>$confidence,'reason'=>$reason]);
        else $this->event($importId,$userId,'needs_review',['reason'=>$reason]);

        if($tripId>0&&$autoAdd&&$confidence>=75&&$hasUseful){
            try{$this->materialize($userId,$importId,$tripId,$parsed,$sensitive,false);}catch(DomainException $e){$this->pdo->prepare("UPDATE trip_booking_imports SET status='needs_review',error_message=?,updated_at=NOW() WHERE id=? AND user_id=?")->execute([$this->clip($e->getMessage(),700),$importId,$userId]);$this->event($importId,$userId,'needs_review',['reason'=>$e->getMessage()]);}
        }
        return $this->get($userId,$importId,true)??[];
    }

    /** Traveler review is the only path that marks an import Verified. */
    public function verify(int $userId,int $importId,array $input): array
    {
        $this->requireReady();$current=$this->get($userId,$importId,true);if(!$current)throw new OutOfBoundsException('Booking Inbox item not found.');
        if((string)$current['status']==='rejected')throw new DomainException('Rejected confirmations cannot be verified.');
        $tripId=max(0,(int)($input['trip_id']??$current['dream_trip_id']??0));if($tripId<1)throw new InvalidArgumentException('Choose the trip this confirmation belongs to.');$this->assertTrip($userId,$tripId);
        $parsed=$this->normalizeParsed(array_merge((array)($current['parsed']??[]),$input));
        $sensitive=(array)($current['sensitive']??[]);$confirmation=$this->clip(trim((string)($input['confirmation_code']??($sensitive['confirmation_code']??''))),120);if($confirmation!=='')$sensitive['confirmation_code']=$confirmation;else unset($sensitive['confirmation_code']);
        $fingerprint=$this->bookingFingerprint($parsed,$sensitive);
        if($fingerprint){$dupe=$this->byFingerprint($userId,$fingerprint);if($dupe&&(int)$dupe['id']!==$importId)throw new DomainException('This reservation already exists in Booking Inbox. Open the existing import instead of creating a duplicate.');}
        $this->pdo->prepare("UPDATE trip_booking_imports SET dream_trip_id=?,status='matched',parsed_json=?,sensitive_encrypted=?,match_confidence=100,match_reason='Traveler reviewed and selected this trip.',booking_fingerprint=?,error_message=NULL,updated_at=NOW() WHERE id=? AND user_id=?")
            ->execute([$tripId,json_encode($parsed,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$sensitive?$this->encrypt(json_encode($sensitive,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)):null,$fingerprint,$importId,$userId]);
        $this->materialize($userId,$importId,$tripId,$parsed,$sensitive,true);
        $this->pdo->prepare("UPDATE trip_booking_imports SET status='verified',verified_at=NOW(),error_message=NULL,updated_at=NOW() WHERE id=? AND user_id=?")->execute([$importId,$userId]);
        $this->event($importId,$userId,'verified',['dream_trip_id'=>$tripId]);
        return $this->get($userId,$importId,true)??[];
    }

    public function reject(int $userId,int $importId): void
    {
        $this->requireReady();$row=$this->get($userId,$importId,false);if(!$row)throw new OutOfBoundsException('Booking Inbox item not found.');
        $this->pdo->prepare("UPDATE trip_booking_imports SET status='rejected',updated_at=NOW() WHERE id=? AND user_id=?")->execute([$importId,$userId]);$this->event($importId,$userId,'rejected',[]);
    }

    /** Owner-only private source download. Returns redacted confirmation text, never raw unfiltered binary. */
    public function source(int $userId,int $importId): array
    {
        $this->requireReady();$q=$this->pdo->prepare('SELECT original_filename,mime_type,source_encrypted FROM trip_booking_imports WHERE id=? AND user_id=? LIMIT 1');$q->execute([$importId,$userId]);$row=$q->fetch();if(!$row)throw new OutOfBoundsException('Booking Inbox item not found.');
        $text=$this->decrypt((string)$row['source_encrypted']);if($text==='')throw new RuntimeException('The private source copy could not be opened.');
        $name=trim((string)($row['original_filename']??''));if($name==='')$name='booking-confirmation-'.$importId.'.txt';else $name=preg_replace('/[^A-Za-z0-9._ -]/','_',pathinfo($name,PATHINFO_FILENAME)).'.txt';
        return ['filename'=>$name,'mime'=>'text/plain; charset=utf-8','body'=>$text];
    }

    public function augmentCommandCenterSnapshot(int $userId,array $snapshot): array
    {
        if(!$this->ready())return $snapshot;
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM trip_booking_imports WHERE user_id=? AND status IN ('imported','parsed','needs_review')");$q->execute([$userId]);$count=(int)$q->fetchColumn();
        $snapshot['summary']['booking_imports_review']=$count;
        if($count>0){
            $item=['key'=>'booking-inbox-review','kind'=>'booking_import','priority'=>92,'title'=>$count===1?'Review a booking confirmation':'Review '.$count.' booking confirmations','body'=>'Booking Inbox has confirmation details that need a trip match or traveler review.','trip_name'=>'','url'=>app_url('booking-inbox.php'),'cta'=>'Open Booking Inbox'];
            $attention=is_array($snapshot['attention']??null)?$snapshot['attention']:[];array_unshift($attention,$item);$snapshot['attention']=array_slice($attention,0,8);$snapshot['summary']['needs_you']=count($snapshot['attention']);$snapshot['status']='Needs your attention';
        }
        return $snapshot;
    }

    public function safeAgentContext(int $userId,int $tripId): array
    {
        if(!$this->ready())return [];$this->assertTrip($userId,$tripId);
        $q=$this->pdo->prepare("SELECT parsed_json,status,match_confidence,updated_at FROM trip_booking_imports WHERE user_id=? AND dream_trip_id=? AND booking_id IS NOT NULL AND status IN ('matched','verified') ORDER BY updated_at DESC LIMIT 20");$q->execute([$userId,$tripId]);$out=[];
        foreach($q->fetchAll()?:[] as $row){$p=json_decode((string)($row['parsed_json']??''),true);if(!is_array($p))$p=[];unset($p['notes'],$p['source_note']);$out[]=['status'=>(string)$row['status'],'match_confidence'=>(int)$row['match_confidence'],'booking'=>$p,'updated_at'=>(string)$row['updated_at']];}
        return $out;
    }

    private function materialize(int $userId,int $importId,int $tripId,array $parsed,array $sensitive,bool $reviewed): int
    {
        $this->assertTrip($userId,$tripId);$type=$this->type((string)($parsed['booking_type']??'other'));$title=$this->clip(trim((string)($parsed['title']??'')),180);if($title==='')$title=ucfirst($type).' reservation';
        $provider=$this->nullable($parsed['provider_name']??null,180);$confirmation=$this->nullable($sensitive['confirmation_code']??null,120);$amount=$this->money($parsed['amount']??null);$currency=$this->currency((string)($parsed['currency']??'USD'));
        $starts=$this->dateTime($parsed['starts_at']??null);$ends=$this->dateTime($parsed['ends_at']??null);if($starts&&$ends&&$ends<$starts)throw new DomainException('The extracted booking end time is before its start time. Review the dates.');
        $cancel=$this->dateTime($parsed['cancellation_deadline']??null);$checkin=$this->dateTime($parsed['checkin_opens_at']??null);$url=$this->url($parsed['provider_url']??null);$notes=$this->nullable($parsed['notes']??null,3000);
        $flight=$this->flightNumber((string)($parsed['flight_number']??''));$dep=$this->iata((string)($parsed['departure_iata']??''));$arr=$this->iata((string)($parsed['arrival_iata']??''));

        if($confirmation){$q=$this->pdo->prepare("SELECT id,dream_trip_id FROM trip_bookings WHERE user_id=? AND confirmation_code=? AND status<>'cancelled' ORDER BY id DESC LIMIT 1");$q->execute([$userId,$confirmation]);$dupe=$q->fetch();if($dupe){if((int)$dupe['dream_trip_id']!==$tripId)throw new DomainException('That confirmation code is already attached to another active trip. Review the trip match before importing.');$bookingId=(int)$dupe['id'];$this->pdo->prepare('UPDATE trip_booking_imports SET booking_id=? WHERE id=? AND user_id=?')->execute([$bookingId,$importId,$userId]);return $bookingId;}}

        $q=$this->pdo->prepare('SELECT booking_id FROM trip_booking_imports WHERE id=? AND user_id=? FOR UPDATE');
        $this->pdo->beginTransaction();
        try{
            $q->execute([$importId,$userId]);$bookingId=(int)($q->fetchColumn()?:0);
            $eventType='created';
            if($bookingId>0){
                $owned=$this->pdo->prepare('SELECT id FROM trip_bookings WHERE id=? AND user_id=? LIMIT 1');$owned->execute([$bookingId,$userId]);if(!$owned->fetchColumn())throw new DomainException('The linked booking no longer belongs to this account.');
                $sql='UPDATE trip_bookings SET dream_trip_id=?,booking_type=?,title=?,provider_name=?,confirmation_code=?,status=IF(status IN (\'confirmed\',\'cancelled\'),status,\'booked\'),amount=?,currency=?,starts_at=?,ends_at=?,cancellation_deadline=?,checkin_opens_at=?,provider_url=?,notes=?,source=\'import\',requires_live_confirmation=0,updated_at=NOW()';$params=[$tripId,$type,$title,$provider,$confirmation,$amount,$currency,$starts,$ends,$cancel,$checkin,$url,$notes];
                if(db_column_exists('trip_bookings','flight_number')){$sql.=',flight_number=?,departure_iata=?,arrival_iata=?';array_push($params,$flight?:null,$dep?:null,$arr?:null);}$sql.=' WHERE id=? AND user_id=?';array_push($params,$bookingId,$userId);$this->pdo->prepare($sql)->execute($params);$eventType='updated';
            }else{
                if(db_column_exists('trip_bookings','flight_number')){$sql="INSERT INTO trip_bookings (user_id,dream_trip_id,booking_type,title,provider_name,confirmation_code,flight_number,departure_iata,arrival_iata,status,payment_status,amount,currency,starts_at,ends_at,cancellation_deadline,checkin_opens_at,provider_url,notes,source,requires_live_confirmation) VALUES (?,?,?,?,?,?,?,?,?,'booked','unknown',?,?,?,?,?,?,?,?, 'import',0)";$params=[$userId,$tripId,$type,$title,$provider,$confirmation,$flight?:null,$dep?:null,$arr?:null,$amount,$currency,$starts,$ends,$cancel,$checkin,$url,$notes];}
                else{$sql="INSERT INTO trip_bookings (user_id,dream_trip_id,booking_type,title,provider_name,confirmation_code,status,payment_status,amount,currency,starts_at,ends_at,cancellation_deadline,checkin_opens_at,provider_url,notes,source,requires_live_confirmation) VALUES (?,?,?,?,?,?,'booked','unknown',?,?,?,?,?,?,?,?, 'import',0)";$params=[$userId,$tripId,$type,$title,$provider,$confirmation,$amount,$currency,$starts,$ends,$cancel,$checkin,$url,$notes];}
                $this->pdo->prepare($sql)->execute($params);$bookingId=(int)$this->pdo->lastInsertId();
                $this->pdo->prepare('UPDATE trip_booking_imports SET booking_id=?,dream_trip_id=?,status=\'matched\',updated_at=NOW() WHERE id=? AND user_id=?')->execute([$bookingId,$tripId,$importId,$userId]);
            }
            if(db_table_exists('trip_booking_events')){$detail=['source'=>'booking_inbox','import_id'=>$importId,'reviewed'=>$reviewed,'booking_type'=>$type];$this->pdo->prepare('INSERT INTO trip_booking_events (booking_id,user_id,dream_trip_id,event_type,detail_json) VALUES (?,?,?,?,?)')->execute([$bookingId,$userId,$tripId,$eventType,json_encode($detail,JSON_UNESCAPED_SLASHES)]);}
            $this->event($importId,$userId,'booking_linked',['booking_id'=>$bookingId,'dream_trip_id'=>$tripId,'reviewed'=>$reviewed]);
            $this->pdo->commit();return $bookingId;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function parseLocal(string $text): array
    {
        $flat=preg_replace('/[\t ]+/',' ',$text)??$text;$lower=strtolower($flat);$out=[];
        $type='other';if(preg_match('/\b(flight|airline|boarding|departure|record locator)\b/i',$flat))$type='flight';elseif(preg_match('/\b(hotel|resort|lodging|check[- ]?in|airbnb|vrbo|room)\b/i',$flat))$type='lodging';elseif(preg_match('/\b(rental car|car rental|vehicle|hertz|avis|enterprise|budget rent)\b/i',$flat))$type='transport';elseif(preg_match('/\b(restaurant|dinner|lunch|table reservation|opentable|resy)\b/i',$flat))$type='restaurant';elseif(preg_match('/\b(ticket|concert|show|event|ticketmaster)\b/i',$flat))$type='event';elseif(preg_match('/\b(activity|tour|excursion|experience|admission)\b/i',$flat))$type='activity';$out['booking_type']=$type;
        $providers=['Booking.com','Airbnb','Vrbo','Marriott','Hilton','Hyatt','IHG','Expedia','United Airlines','American Airlines','Delta Air Lines','Southwest Airlines','Alaska Airlines','JetBlue','Frontier Airlines','Spirit Airlines','Hertz','Avis','Enterprise','Ticketmaster','OpenTable','Resy'];foreach($providers as $p){if(stripos($flat,$p)!==false){$out['provider_name']=$p;break;}}
        if(preg_match('/(?:confirmation|reservation|record locator|booking)(?:\s+(?:number|code|id))?\s*[:#]?\s*([A-Z0-9][A-Z0-9-]{3,24})/i',$flat,$m))$out['confirmation_code']=strtoupper($m[1]);
        if(preg_match('/\b([A-Z0-9]{2})\s?([0-9]{1,4})\b/',$flat,$m)&&$type==='flight')$out['flight_number']=strtoupper($m[1].$m[2]);
        if(preg_match('/\b([A-Z]{3})\s*(?:→|->|to|–|-)\s*([A-Z]{3})\b/i',$flat,$m)){$out['departure_iata']=strtoupper($m[1]);$out['arrival_iata']=strtoupper($m[2]);$out['destination_name']=strtoupper($m[2]);}
        if(preg_match('/(?:destination|location|city)\s*:\s*([^\r\n]{2,100})/i',$text,$m))$out['destination_name']=$this->clip(trim($m[1]),120);
        if(preg_match('/https?:\/\/[^\s<>"\']+/i',$flat,$m))$out['provider_url']=rtrim($m[0],').,;');
        if(preg_match('/(?:total|amount|price|paid)\s*[:\-]?\s*(?:USD\s*)?\$?\s*([0-9]{1,7}(?:,[0-9]{3})*(?:\.\d{2})?)/i',$flat,$m))$out['amount']=(float)str_replace(',','',$m[1]);
        if(preg_match('/\b(USD|EUR|GBP|CAD|AUD|JPY)\b/i',$flat,$m))$out['currency']=strtoupper($m[1]);elseif(str_contains($flat,'$'))$out['currency']='USD';
        $out=array_merge($out,$this->extractDates($text));
        if(preg_match('/(?:guest|passenger|traveler|traveller)\s*:\s*([^\r\n]{2,120})/i',$text,$m))$out['traveler_names']=$this->clip(trim($m[1]),180);
        $provider=(string)($out['provider_name']??'');$flight=(string)($out['flight_number']??'');$out['title']=$flight!==''?'Flight '.$flight:($provider!==''?ucfirst($type).' · '.$provider:ucfirst($type).' reservation');
        if($type==='lodging'&&preg_match('/(?:property|hotel|stay)\s*:\s*([^\r\n]{2,150})/i',$text,$m))$out['title']=$this->clip(trim($m[1]),180);
        return $this->normalizeParsed($out);
    }

    private function extractDates(string $text): array
    {
        $out=[];$patterns=[
            '/\b20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}(?:[ T]\d{1,2}:\d{2}(?:\s*[AP]M)?)?/i',
            '/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:,?\s+20\d{2})(?:\s+(?:at\s+)?\d{1,2}:\d{2}\s*(?:AM|PM)?)?/i',
            '/\b\d{1,2}\/\d{1,2}\/20\d{2}(?:\s+\d{1,2}:\d{2}\s*(?:AM|PM)?)?/i',
        ];$dates=[];foreach($patterns as $p){if(preg_match_all($p,$text,$m)){foreach($m[0] as $raw){$ts=strtotime($raw);if($ts)$dates[$ts]=date('Y-m-d H:i:s',$ts);}}}ksort($dates);$vals=array_values($dates);
        if($vals)$out['starts_at']=$vals[0];if(count($vals)>1)$out['ends_at']=$vals[count($vals)-1];
        if(preg_match('/(?:cancel(?:lation)?(?:\s+free)?(?:\s+by|\s+before)?)[^\r\n]{0,40}?((?:20\d{2}[-\/]\d{1,2}[-\/]\d{1,2})|(?:[A-Za-z]{3,9}\s+\d{1,2},?\s+20\d{2})|(?:\d{1,2}\/\d{1,2}\/20\d{2}))/i',$text,$m)){if($ts=strtotime($m[1]))$out['cancellation_deadline']=date('Y-m-d H:i:s',$ts);}
        if(preg_match('/check[- ]?in(?:\s+opens|\s+from|\s+at)?[^\r\n]{0,35}?((?:20\d{2}[-\/]\d{1,2}[-\/]\d{1,2})|(?:[A-Za-z]{3,9}\s+\d{1,2},?\s+20\d{2}))(?:\s+(?:at\s+)?(\d{1,2}:\d{2}\s*(?:AM|PM)?))?/i',$text,$m)){if($ts=strtotime(trim($m[1].' '.($m[2]??''))))$out['checkin_opens_at']=date('Y-m-d H:i:s',$ts);}
        return $out;
    }

    private function parseWithAi(int $userId,string $text): array
    {
        $system='You extract travel reservation facts. Return exactly one JSON object and no prose. Never infer missing facts. Never return payment card numbers, bank data, passwords, login links, or security codes. Allowed keys: booking_type,title,provider_name,confirmation_code,flight_number,departure_iata,arrival_iata,destination_name,starts_at,ends_at,cancellation_deadline,checkin_opens_at,amount,currency,provider_url. booking_type must be flight,lodging,transport,event,restaurant,activity,document,other. Dates must be YYYY-MM-DD HH:MM:SS when the source clearly provides enough information; otherwise null.';
        $prompt="Extract only facts explicitly present in this traveler-provided booking confirmation.\n\n".substr($text,0,20000);
        $raw=(new AiProviderService($this->pdo))->generateText($system,$prompt,$userId,'booking_import_parse',1000);if(!$raw)return [];$raw=trim($raw);if(str_starts_with($raw,'```')){$raw=preg_replace('/^```(?:json)?\s*/i','',$raw)??$raw;$raw=preg_replace('/\s*```$/','',$raw)??$raw;}$json=json_decode($raw,true);if(!is_array($json))return [];
        return $this->normalizeParsed($json);
    }

    private function mergeParsed(array $local,array $ai): array
    {
        foreach($ai as $k=>$v){if($v===null||$v==='')continue;if(!isset($local[$k])||$local[$k]===null||$local[$k]===''||in_array($k,['starts_at','ends_at','cancellation_deadline','checkin_opens_at','destination_name'],true))$local[$k]=$v;}return $this->normalizeParsed($local);
    }

    private function normalizeParsed(array $p): array
    {
        $out=[];$out['booking_type']=$this->type((string)($p['booking_type']??'other'));$out['title']=$this->clip(trim((string)($p['title']??'')),180);$out['provider_name']=$this->nullable($p['provider_name']??null,180);$out['flight_number']=$this->flightNumber((string)($p['flight_number']??''))?:null;$out['departure_iata']=$this->iata((string)($p['departure_iata']??''))?:null;$out['arrival_iata']=$this->iata((string)($p['arrival_iata']??''))?:null;$out['destination_name']=$this->nullable($p['destination_name']??null,120);$out['starts_at']=$this->dateTime($p['starts_at']??null);$out['ends_at']=$this->dateTime($p['ends_at']??null);$out['cancellation_deadline']=$this->dateTime($p['cancellation_deadline']??null);$out['checkin_opens_at']=$this->dateTime($p['checkin_opens_at']??null);$out['amount']=$this->money($p['amount']??null);$out['currency']=$this->currency((string)($p['currency']??'USD'));$out['provider_url']=$this->url($p['provider_url']??null);$out['notes']=$this->nullable($p['notes']??null,3000);
        foreach(['source_note'] as $k)if(isset($p[$k]))$out[$k]=$this->clip((string)$p[$k],500);
        if(isset($p['confirmation_code']))$out['confirmation_code']=$this->clip(trim((string)$p['confirmation_code']),120);if(isset($p['traveler_names']))$out['traveler_names']=$this->clip(trim((string)$p['traveler_names']),180);
        return array_filter($out,static fn($v)=>$v!==null&&$v!=='');
    }

    private function splitSensitive(array $parsed): array
    {
        $s=[];foreach(['confirmation_code','traveler_names'] as $k){if(isset($parsed[$k])&&$parsed[$k]!==''){$s[$k]=$parsed[$k];unset($parsed[$k]);}}return [$parsed,$s];
    }

    private function matchTrip(int $userId,array $parsed,int $selectedTrip): array
    {
        if($selectedTrip>0){$trip=$this->assertTrip($userId,$selectedTrip);return [$selectedTrip,100,'Traveler selected this trip.'];}
        $trips=$this->trips($userId);if(!$trips)return [0,0,'No active trip is available.'];$scores=[];$start=!empty($parsed['starts_at'])?strtotime((string)$parsed['starts_at']):false;$destination=strtolower(trim((string)($parsed['destination_name']??'')));$arr=strtoupper(trim((string)($parsed['arrival_iata']??'')));$dep=strtoupper(trim((string)($parsed['departure_iata']??'')));
        foreach($trips as $trip){$score=0;$reasons=[];$tripDest=strtolower(trim((string)($trip['destination_name']??'')));if($arr!==''&&strtoupper((string)($trip['destination_iata']??''))===$arr){$score+=45;$reasons[]='arrival airport matches';}elseif($destination!==''&&$tripDest!==''&&($this->textOverlap($destination,$tripDest)>=0.6||str_contains($tripDest,$destination)||str_contains($destination,$tripDest))){$score+=40;$reasons[]='destination matches';}if($dep!==''&&strtoupper((string)($trip['origin_iata']??''))===$dep){$score+=10;$reasons[]='origin airport matches';}
            if($start&&$trip['start_date']){$tripStart=strtotime((string)$trip['start_date'].' 00:00:00');$tripEnd=$trip['end_date']?strtotime((string)$trip['end_date'].' 23:59:59'):$tripStart;if($tripStart&&$tripEnd&&$start>=$tripStart-259200&&$start<=$tripEnd+259200){$score+=45;$reasons[]='dates overlap';}elseif($tripStart&&abs($start-$tripStart)<=604800){$score+=25;$reasons[]='dates are close';}}
            $scores[]=['id'=>(int)$trip['id'],'score'=>min(100,$score),'reason'=>implode(', ',$reasons)];}
        usort($scores,fn($a,$b)=>$b['score']<=>$a['score']);$best=$scores[0];$second=$scores[1]['score']??0;if($best['score']>=65&&($best['score']-$second)>=15)return [$best['id'],$best['score'],'Automatic match: '.($best['reason']?:'strong trip signals').'.'];return [0,(int)$best['score'],$best['score']>0?'Best candidate was not unique enough: '.($best['reason']?:'partial signals').'.':'No strong destination/date match was found.'];
    }

    private function bookingFingerprint(array $parsed,array $sensitive): ?string
    {
        $confirmation=strtolower(trim((string)($sensitive['confirmation_code']??'')));$provider=strtolower(trim((string)($parsed['provider_name']??'')));$type=(string)($parsed['booking_type']??'other');$start=substr((string)($parsed['starts_at']??''),0,10);$flight=strtolower((string)($parsed['flight_number']??''));if($confirmation!=='')return hash('sha256','confirm|'.$provider.'|'.$confirmation);$title=strtolower(trim((string)($parsed['title']??'')));if($title===''&&$start===''&&$flight==='')return null;return hash('sha256','booking|'.$type.'|'.$provider.'|'.$title.'|'.$flight.'|'.$start);
    }

    private function readUpload(array $file): array
    {
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('That confirmation file could not be uploaded.');$tmp=(string)($file['tmp_name']??'');$size=(int)($file['size']??0);if($tmp===''||!is_file($tmp))throw new InvalidArgumentException('The uploaded confirmation is missing.');if($size<1||$size>self::MAX_FILE_BYTES)throw new InvalidArgumentException('Confirmation files must be 8 MB or smaller.');if(PHP_SAPI!=='cli'&&!is_uploaded_file($tmp))throw new InvalidArgumentException('Invalid confirmation upload.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'application/octet-stream';$allowed=['text/plain','text/html','message/rfc822','application/pdf','image/jpeg','image/png','image/webp'];if(!in_array($mime,$allowed,true))throw new InvalidArgumentException('Use TXT, HTML, EML, PDF, JPEG, PNG, or WebP confirmation files.');$filename=$this->clip(basename((string)($file['name']??'confirmation')),255);$raw=file_get_contents($tmp);if($raw===false)throw new RuntimeException('Vacation Brain could not read that confirmation file.');$hashMaterial=hash('sha256',$raw);$text='';$note='';
        if(in_array($mime,['text/plain','message/rfc822'],true))$text=$raw;elseif($mime==='text/html')$text=html_entity_decode(strip_tags($raw),ENT_QUOTES|ENT_HTML5,'UTF-8');elseif($mime==='application/pdf'){$text=$this->pdfText($tmp);if(trim($text)==='')$note='PDF saved as an import reference, but this server could not extract its text. Paste the confirmation text to finish the import.';}else{$note='Image confirmation received. Image bytes were not retained because Vacation Brain will not store unfiltered documents that could contain payment-card data. Paste the confirmation text to finish the import.';}
        return [$filename,$mime,$size,$hashMaterial,$text,$note];
    }

    private function pdfText(string $path): string
    {
        if(!function_exists('exec'))return '';$probe=[];$code=0;@exec('command -v pdftotext 2>/dev/null',$probe,$code);if($code!==0||empty($probe[0]))return '';$out=[];$code=0;@exec(escapeshellcmd((string)$probe[0]).' -layout '.escapeshellarg($path).' - 2>/dev/null',$out,$code);return $code===0?implode("\n",$out):'';
    }

    private function redactPaymentData(string $text): string
    {
        $text=preg_replace('/\b(?:\d[ -]*?){13,19}\b/','[payment-card number removed]',$text)??$text;$text=preg_replace('/\b(?:cvv|cvc|security code)\s*[:#-]?\s*\d{3,4}\b/i','$1 [removed]',$text)??$text;return trim($text);
    }

    private function hasUsefulParse(array $p,array $s): bool{return !empty($s['confirmation_code'])||!empty($p['flight_number'])||!empty($p['starts_at'])||!empty($p['provider_name'])||(!empty($p['title'])&&(string)$p['booking_type']!=='other');}
    private function bySourceHash(int $userId,string $hash): ?array{$q=$this->pdo->prepare('SELECT * FROM trip_booking_imports WHERE user_id=? AND source_hash=? LIMIT 1');$q->execute([$userId,$hash]);$r=$q->fetch();return $r?:null;}
    private function byFingerprint(int $userId,string $hash): ?array{$q=$this->pdo->prepare('SELECT * FROM trip_booking_imports WHERE user_id=? AND booking_fingerprint=? LIMIT 1');$q->execute([$userId,$hash]);$r=$q->fetch();return $r?:null;}
    private function assertTrip(int $userId,int $tripId): array{$q=$this->pdo->prepare("SELECT id,name,status,start_date,end_date,origin_name,origin_iata,destination_iata,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.destination_name')),'') destination_name FROM dream_trips WHERE id=? AND user_id=? LIMIT 1");$q->execute([$tripId,$userId]);$r=$q->fetch();if(!$r)throw new OutOfBoundsException('Trip not found.');return $r;}

    private function decorate(array $row,bool $includeSensitive): array
    {
        $parsed=json_decode((string)($row['parsed_json']??''),true);$row['parsed']=is_array($parsed)?$parsed:[];unset($row['parsed_json'],$row['source_encrypted'],$row['sensitive_encrypted'],$row['source_hash'],$row['booking_fingerprint']);$row['id']=(int)$row['id'];$row['dream_trip_id']=isset($row['dream_trip_id'])?(int)$row['dream_trip_id']:null;$row['booking_id']=isset($row['booking_id'])?(int)$row['booking_id']:null;$row['match_confidence']=(int)($row['match_confidence']??0);$row['ai_used']=!empty($row['ai_used']);
        if($includeSensitive){$q=$this->pdo->prepare('SELECT sensitive_encrypted FROM trip_booking_imports WHERE id=? AND user_id=? LIMIT 1');$q->execute([$row['id'],(int)($row['user_id']??0)]);$enc=(string)($q->fetchColumn()?:'');$s=$enc!==''?json_decode($this->decrypt($enc),true):[];$row['sensitive']=is_array($s)?$s:[];}else unset($row['user_id']);return $row;
    }

    private function event(int $importId,int $userId,string $type,array $detail): void
    {
        if(!$this->ready())return;$allowed=['received','parsed','matched','needs_review','booking_linked','verified','rejected','duplicate'];if(!in_array($type,$allowed,true))return;$this->pdo->prepare('INSERT INTO trip_booking_import_events (import_id,user_id,event_type,detail_json) VALUES (?,?,?,?)')->execute([$importId,$userId,$type,$detail?json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
    }

    private function cryptoKey(): string
    {
        $material=(string)($this->config['app']['internal_key']??'');if($material===''){$db=$this->config['db']??[];$material=implode('|',[(string)($db['host']??''),(string)($db['name']??''),(string)($db['user']??''),(string)($db['pass']??''),(string)($this->config['app']['base_url']??'')]);}return hash('sha256',$material,true);
    }
    private function encrypt(string $plain): string{if(!function_exists('openssl_encrypt'))throw new RuntimeException('PHP OpenSSL is required for private Booking Inbox storage.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Could not encrypt Booking Inbox source.');return 'v1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($cipher);}
    private function decrypt(string $payload): string{if(!function_exists('openssl_decrypt')||!str_starts_with($payload,'v1.'))return '';$p=explode('.',$payload,4);if(count($p)!==4)return '';$iv=base64_decode($p[1],true);$tag=base64_decode($p[2],true);$cipher=base64_decode($p[3],true);if($iv===false||$tag===false||$cipher===false)return '';$plain=openssl_decrypt($cipher,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);return is_string($plain)?$plain:'';}

    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Booking Inbox.');}
    private function type(string $v): string{$v=strtolower(trim($v));return in_array($v,self::TYPES,true)?$v:'other';}
    private function clip(string $v,int $n): string{$v=trim($v);return function_exists('mb_substr')?mb_substr($v,0,$n):substr($v,0,$n);}
    private function nullable(mixed $v,int $n): ?string{$v=$this->clip((string)$v,$n);return $v===''?null:$v;}
    private function currency(string $v): string{$v=strtoupper(trim($v));return preg_match('/^[A-Z]{3}$/',$v)?$v:'USD';}
    private function money(mixed $v): ?float{if($v===null||$v==='')return null;if(!is_numeric(str_replace(',','',(string)$v)))return null;$n=(float)str_replace(',','',(string)$v);return $n>=0&&$n<=99999999?round($n,2):null;}
    private function dateTime(mixed $v): ?string{$v=trim((string)$v);if($v==='')return null;$ts=strtotime($v);return $ts?date('Y-m-d H:i:s',$ts):null;}
    private function url(mixed $v): ?string{$v=$this->nullable($v,1500);return $v&&preg_match('#^https?://#i',$v)?$v:null;}
    private function iata(string $v): string{$v=strtoupper(trim($v));return preg_match('/^[A-Z]{3}$/',$v)?$v:'';}
    private function flightNumber(string $v): string{$v=preg_replace('/\s+/','',strtoupper(trim($v)))??'';return $v!==''&&preg_match('/^[A-Z0-9]{2,12}$/',$v)?$v:'';}
    private function textOverlap(string $a,string $b): float{$ta=array_values(array_filter(preg_split('/[^a-z0-9]+/',$a)?:[],fn($x)=>strlen($x)>2));$tb=array_values(array_filter(preg_split('/[^a-z0-9]+/',$b)?:[],fn($x)=>strlen($x)>2));if(!$ta||!$tb)return 0.0;$common=array_intersect(array_unique($ta),array_unique($tb));return count($common)/max(1,min(count(array_unique($ta)),count(array_unique($tb))));}
}
