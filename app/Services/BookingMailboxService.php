<?php
declare(strict_types=1);

/**
 * Read-only connected travel-mail ingestion and reservation-change intelligence.
 * Gmail is the first provider. OAuth tokens and retained payment-redacted source
 * are encrypted at rest. Mailbox reads never authorize provider transactions.
 */
final class BookingMailboxService
{
    private const GMAIL_SCOPE='https://www.googleapis.com/auth/gmail.readonly';
    private const GOOGLE_AUTH='https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN='https://oauth2.googleapis.com/token';
    private const GOOGLE_REVOKE='https://oauth2.googleapis.com/revoke';
    private const GMAIL_API='https://gmail.googleapis.com/gmail/v1';
    private const MAX_SYNC_PAGES=3;
    private const PAGE_SIZE=50;
    private const CHANGE_FIELDS=['flight_number','departure_iata','arrival_iata','starts_at','ends_at','cancellation_deadline','checkin_opens_at','amount','currency'];
    private const BOOKING_SAVE_FIELDS=['starts_at','ends_at','cancellation_deadline','checkin_opens_at','amount','currency'];
    private array $config;

    public function __construct(private PDO $pdo)
    {
        global $config;
        $this->config=is_array($config??null)?$config:[];
    }

    public function ready(): bool
    {
        return db_table_exists('booking_mail_connections')
            && db_table_exists('booking_mail_messages')
            && db_table_exists('booking_change_proposals')
            && db_table_exists('trip_booking_imports')
            && db_table_exists('trip_bookings');
    }

    public function googleConfigured(): bool
    {
        return $this->googleClientId()!==''&&$this->googleClientSecret()!=='';
    }

    public function connection(int $userId): ?array
    {
        if(!$this->ready())return null;
        $row=$this->connectionRow($userId);
        if(!$row)return null;
        unset($row['access_token_encrypted'],$row['refresh_token_encrypted']);
        $row['id']=(int)$row['id'];
        $row['auto_import']=!empty($row['auto_import']);
        $row['use_ai']=!empty($row['use_ai']);
        $row['scan_days']=(int)$row['scan_days'];
        $ignored=json_decode((string)($row['ignored_senders_json']??''),true);
        $row['ignored_senders']=is_array($ignored)?array_values($ignored):[];
        unset($row['ignored_senders_json']);
        return $row;
    }

    public function authorizationUrl(int $userId): string
    {
        $this->requireReady();
        if(!$this->googleConfigured())throw new RuntimeException('Google Gmail OAuth is not configured on this server.');
        $state=bin2hex(random_bytes(32));
        $_SESSION['booking_mail_oauth']=['user_id'=>$userId,'state_hash'=>hash('sha256',$state),'created_at'=>time()];
        $params=[
            'client_id'=>$this->googleClientId(),
            'redirect_uri'=>$this->googleRedirectUri(),
            'response_type'=>'code',
            'scope'=>self::GMAIL_SCOPE,
            'access_type'=>'offline',
            'prompt'=>'consent',
            'state'=>$state,
        ];
        return self::GOOGLE_AUTH.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    }

    public function completeGoogleOAuth(int $userId,string $code,string $state): array
    {
        $this->requireReady();
        $pending=$_SESSION['booking_mail_oauth']??null;
        unset($_SESSION['booking_mail_oauth']);
        if(!is_array($pending)
            ||(int)($pending['user_id']??0)!==$userId
            ||time()-(int)($pending['created_at']??0)>900
            ||!hash_equals((string)($pending['state_hash']??''),hash('sha256',$state))){
            throw new DomainException('The Gmail connection request expired or could not be verified. Start the connection again.');
        }
        if($code==='')throw new InvalidArgumentException('Google did not return an authorization code.');
        $token=$this->requestJson('POST',self::GOOGLE_TOKEN,[],[
            'code'=>$code,
            'client_id'=>$this->googleClientId(),
            'client_secret'=>$this->googleClientSecret(),
            'redirect_uri'=>$this->googleRedirectUri(),
            'grant_type'=>'authorization_code',
        ]);
        $access=trim((string)($token['access_token']??''));
        if($access==='')throw new RuntimeException('Google did not return an access token.');
        $existing=$this->connectionRow($userId);
        $refresh=trim((string)($token['refresh_token']??''));
        if($refresh===''&&$existing&&!empty($existing['refresh_token_encrypted']))$refresh=$this->decrypt((string)$existing['refresh_token_encrypted']);
        if($refresh==='')throw new RuntimeException('Google did not return an offline refresh token. Reconnect and approve access again.');
        $profile=$this->gmailJson($access,'/users/me/profile');
        $email=strtolower(trim((string)($profile['emailAddress']??'')));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Vacation Brain could not identify the connected Gmail account.');
        $expiresAt=date('Y-m-d H:i:s',time()+max(300,(int)($token['expires_in']??3600)));
        $sql="INSERT INTO booking_mail_connections
              (user_id,provider,account_email,status,access_token_encrypted,refresh_token_encrypted,token_expires_at,scope,next_sync_after,last_error)
              VALUES (?,'gmail',?,'connected',?,?,?,?,NOW(),NULL)
              ON DUPLICATE KEY UPDATE account_email=VALUES(account_email),status='connected',access_token_encrypted=VALUES(access_token_encrypted),refresh_token_encrypted=VALUES(refresh_token_encrypted),token_expires_at=VALUES(token_expires_at),scope=VALUES(scope),next_sync_after=NOW(),last_error=NULL,updated_at=NOW()";
        $this->pdo->prepare($sql)->execute([$userId,$email,$this->encrypt($access),$this->encrypt($refresh),$expiresAt,self::GMAIL_SCOPE]);
        return $this->connection($userId)??[];
    }

    public function savePreferences(int $userId,array $input): array
    {
        $this->requireReady();
        $row=$this->connectionRow($userId);if(!$row)throw new OutOfBoundsException('Connect Gmail before changing mail settings.');
        $auto=!empty($input['auto_import'])?1:0;
        $ai=!empty($input['use_ai'])?1:0;
        $days=max(7,min(180,(int)($input['scan_days']??90)));
        $this->pdo->prepare('UPDATE booking_mail_connections SET auto_import=?,use_ai=?,scan_days=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$auto,$ai,$days,(int)$row['id'],$userId]);
        return $this->connection($userId)??[];
    }

    public function setStatus(int $userId,string $status): array
    {
        $this->requireReady();
        if(!in_array($status,['connected','paused'],true))throw new InvalidArgumentException('Unknown connected-mail status.');
        $row=$this->connectionRow($userId);if(!$row)throw new OutOfBoundsException('No connected Gmail account was found.');
        $this->pdo->prepare("UPDATE booking_mail_connections SET status=?,next_sync_after=IF(?='connected',NOW(),next_sync_after),last_error=IF(?='connected',NULL,last_error),updated_at=NOW() WHERE id=? AND user_id=?")->execute([$status,$status,$status,(int)$row['id'],$userId]);
        return $this->connection($userId)??[];
    }

    /** Disconnect removes encrypted connected-mail/source history, not canonical trip bookings. */
    public function disconnect(int $userId): void
    {
        $this->requireReady();
        $row=$this->connectionRow($userId);if(!$row)return;
        $refresh=!empty($row['refresh_token_encrypted'])?$this->decrypt((string)$row['refresh_token_encrypted']):'';
        $access=!empty($row['access_token_encrypted'])?$this->decrypt((string)$row['access_token_encrypted']):'';
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT DISTINCT import_id FROM booking_mail_messages WHERE connection_id=? AND user_id=? AND import_id IS NOT NULL');
            $q->execute([(int)$row['id'],$userId]);$importIds=array_values(array_filter(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[])));
            $this->pdo->prepare("DELETE FROM booking_mail_connections WHERE id=? AND user_id=? AND provider='gmail'")->execute([(int)$row['id'],$userId]);
            $this->deleteConnectedImports($userId,$importIds);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $token=$refresh!==''?$refresh:$access;if($token!=='')$this->revokeGoogleToken($token);
    }

    private function deleteConnectedImports(int $userId,array $importIds): void
    {
        $importIds=array_values(array_unique(array_filter(array_map('intval',$importIds),fn($id)=>$id>0)));
        if(!$importIds)return;
        $placeholders=implode(',',array_fill(0,count($importIds),'?'));
        $params=array_merge([$userId],$importIds);
        $this->pdo->prepare("DELETE FROM trip_booking_imports WHERE user_id=? AND source_type='email' AND id IN ($placeholders)")->execute($params);
    }

    public function ignoreSender(int $userId,string $sender): array
    {
        $this->requireReady();$sender=strtolower(trim($sender));
        if(!filter_var($sender,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Choose a valid sender address to ignore.');
        $row=$this->connectionRow($userId);if(!$row)throw new OutOfBoundsException('No connected Gmail account was found.');
        $list=json_decode((string)($row['ignored_senders_json']??''),true);if(!is_array($list))$list=[];
        $list=array_values(array_unique(array_filter(array_map(fn($v)=>strtolower(trim((string)$v)),$list))));
        if(!in_array($sender,$list,true))$list[]=$sender;$list=array_slice($list,-100);
        $this->pdo->prepare('UPDATE booking_mail_connections SET ignored_senders_json=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([json_encode($list,JSON_UNESCAPED_SLASHES),(int)$row['id'],$userId]);
        $this->pdo->prepare("UPDATE booking_mail_messages SET status='ignored',classification='ignored',updated_at=NOW() WHERE user_id=? AND LOWER(from_address)=? AND status IN ('new','imported')")->execute([$userId,$sender]);
        return $this->connection($userId)??[];
    }

    public function removeIgnoredSender(int $userId,string $sender): array
    {
        $this->requireReady();$sender=strtolower(trim($sender));
        $row=$this->connectionRow($userId);if(!$row)throw new OutOfBoundsException('No connected Gmail account was found.');
        $list=json_decode((string)($row['ignored_senders_json']??''),true);if(!is_array($list))$list=[];
        $list=array_values(array_filter($list,fn($x)=>strtolower((string)$x)!==$sender));
        $this->pdo->prepare('UPDATE booking_mail_connections SET ignored_senders_json=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([json_encode($list,JSON_UNESCAPED_SLASHES),(int)$row['id'],$userId]);
        return $this->connection($userId)??[];
    }

    public function syncUser(int $userId,bool $force=false): array
    {
        $this->requireReady();
        $conn=$this->connectionRow($userId);if(!$conn)throw new OutOfBoundsException('Connect Gmail before syncing Booking Inbox.');
        if((string)$conn['status']==='paused')return ['checked'=>0,'imported'=>0,'changes'=>0,'ignored'=>0,'errors'=>0,'paused'=>true];
        if((string)$conn['status']==='error'&&!$force)throw new DomainException('The Gmail connection needs to be reauthorized.');
        if(!$force&&!empty($conn['next_sync_after'])&&strtotime((string)$conn['next_sync_after'])>time())return ['checked'=>0,'imported'=>0,'changes'=>0,'ignored'=>0,'errors'=>0,'not_due'=>true];
        $access=$this->accessToken($conn);$days=max(7,min(180,(int)$conn['scan_days']));
        $query='newer_than:'.$days.'d {confirmation reservation itinerary "booking confirmation" "schedule change" "flight change" "reservation changed" "reservation canceled" "reservation cancelled" "check-in"}';
        $refs=$this->gmailMessageRefs($access,$query);
        $result=['checked'=>0,'imported'=>0,'changes'=>0,'ignored'=>0,'errors'=>0,'paused'=>false];
        $ignored=json_decode((string)($conn['ignored_senders_json']??''),true);if(!is_array($ignored))$ignored=[];$ignored=array_map(fn($v)=>strtolower(trim((string)$v)),$ignored);
        foreach($refs as $ref){
            $providerId=trim((string)($ref['id']??''));if($providerId===''||$this->messageExists((int)$conn['id'],$providerId))continue;$result['checked']++;
            try{
                $raw=$this->gmailJson($access,'/users/me/messages/'.rawurlencode($providerId).'?format=full');
                $message=$this->decodeGmailMessage($raw);$sender=strtolower((string)$message['from_address']);
                if($sender!==''&&in_array($sender,$ignored,true)){$this->insertMessage($conn,$message,'ignored','ignored',null,null,null);$result['ignored']++;continue;}
                if(!$this->looksTravelRelated($message)){$this->insertMessage($conn,$message,'unknown','ignored',null,null,null);$result['ignored']++;continue;}
                $privateSource=$this->redactPaymentData(trim('Subject: '.(string)$message['subject']."\nFrom: ".(string)$message['from']."\n\n".(string)$message['body']));
                $parseSource=$this->redactPaymentData(trim('Subject: '.(string)$message['subject']."\n\n".(string)$message['body']));
                $parsed=$this->parseMessage($userId,$message,$parseSource,!empty($conn['use_ai']));
                $booking=$this->findBooking($userId,$parsed);
                if($booking){
                    [$classification,$proposal]=$this->changeForBooking($booking,$parsed,$message);
                    $messageId=$this->insertMessage($conn,$message,$classification,$proposal?'change_review':'imported',$privateSource,null,(int)$booking['id']);
                    if($proposal){$this->insertChangeProposal($userId,(int)$conn['id'],$messageId,$booking,$proposal);$result['changes']++;}else $result['imported']++;
                }else{
                    $messageId=$this->insertMessage($conn,$message,'reservation','new',$privateSource,null,null);
                    $import=(new TripBookingImportService($this->pdo))->receive($userId,['source_text'=>$parseSource,'trip_id'=>0,'auto_add'=>!empty($conn['auto_import'])?1:0,'use_ai'=>0],null);
                    $importId=(int)($import['id']??0);$bookingId=(int)($import['booking_id']??0);
                    if($importId>0&&empty($import['duplicate']))$this->pdo->prepare("UPDATE trip_booking_imports SET source_type='email',updated_at=updated_at WHERE id=? AND user_id=?")->execute([$importId,$userId]);
                    $this->pdo->prepare("UPDATE booking_mail_messages SET import_id=?,booking_id=?,status='imported',updated_at=NOW() WHERE id=? AND user_id=?")->execute([$importId?:null,$bookingId?:null,$messageId,$userId]);
                    $result['imported']++;
                }
            }catch(Throwable $e){
                $result['errors']++;error_log('Booking mailbox message sync failed: '.$e->getMessage());$this->insertErrorMessage($conn,$providerId,$e->getMessage());
            }
        }
        $lastError=$result['errors']>0?$result['errors'].' message(s) could not be processed.':null;
        $this->pdo->prepare("UPDATE booking_mail_connections SET last_sync_at=NOW(),next_sync_after=DATE_ADD(NOW(),INTERVAL 15 MINUTE),last_error=?,updated_at=NOW() WHERE id=? AND user_id=?")->execute([$lastError,(int)$conn['id'],$userId]);
        return $result;
    }

    private function gmailMessageRefs(string $access,string $query): array
    {
        $refs=[];$pageToken='';
        for($page=0;$page<self::MAX_SYNC_PAGES;$page++){
            $params=['q'=>$query,'maxResults'=>self::PAGE_SIZE];if($pageToken!=='')$params['pageToken']=$pageToken;
            $list=$this->gmailJson($access,'/users/me/messages?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));
            foreach(is_array($list['messages']??null)?$list['messages']:[] as $ref)if(!empty($ref['id']))$refs[(string)$ref['id']]=$ref;
            $pageToken=trim((string)($list['nextPageToken']??''));if($pageToken==='')break;
        }
        return array_values($refs);
    }

    public function runDue(int $limit=20): array
    {
        if(!$this->ready())return ['connections'=>0,'checked'=>0,'imported'=>0,'changes'=>0,'ignored'=>0,'errors'=>0,'upgrade_required'=>true];
        $limit=max(1,min(100,$limit));
        $q=$this->pdo->query("SELECT user_id FROM booking_mail_connections WHERE status='connected' AND (next_sync_after IS NULL OR next_sync_after<=NOW()) ORDER BY COALESCE(next_sync_after,'1970-01-01') ASC,id ASC LIMIT $limit");
        $out=['connections'=>0,'checked'=>0,'imported'=>0,'changes'=>0,'ignored'=>0,'errors'=>0];
        foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $uid){$out['connections']++;try{$r=$this->syncUser((int)$uid,false);foreach(['checked','imported','changes','ignored','errors'] as $k)$out[$k]+=(int)($r[$k]??0);}catch(Throwable $e){$out['errors']++;error_log('Connected Booking Inbox sync failed: '.$e->getMessage());$this->markConnectionError((int)$uid,$e->getMessage());}}
        return $out;
    }

    public function messages(int $userId,int $limit=80): array
    {
        if(!$this->ready())return [];$limit=max(1,min(200,$limit));
        $q=$this->pdo->prepare("SELECT m.id,m.message_date,m.from_address,m.from_name,m.subject,m.snippet,m.classification,m.status,m.import_id,m.booking_id,m.error_message,m.created_at,m.updated_at,(m.source_encrypted IS NOT NULL) has_source,b.title booking_title,dt.id dream_trip_id,dt.name trip_name FROM booking_mail_messages m LEFT JOIN trip_bookings b ON b.id=m.booking_id AND b.user_id=m.user_id LEFT JOIN dream_trips dt ON dt.id=b.dream_trip_id AND dt.user_id=m.user_id WHERE m.user_id=? ORDER BY COALESCE(m.message_date,m.created_at) DESC,m.id DESC LIMIT $limit");
        $q->execute([$userId]);$rows=$q->fetchAll()?:[];foreach($rows as &$row)$row['has_source']=!empty($row['has_source']);unset($row);return $rows;
    }

    public function changes(int $userId,string $status='needs_review',int $limit=60): array
    {
        if(!$this->ready())return [];$limit=max(1,min(100,$limit));$where=$status==='all'?'':' AND c.status=?';$params=[$userId];if($status!=='all')$params[]=$status;
        $q=$this->pdo->prepare("SELECT c.*,m.subject,m.message_date,b.title booking_title,b.booking_type,b.status booking_status,dt.name trip_name FROM booking_change_proposals c JOIN booking_mail_messages m ON m.id=c.message_id JOIN trip_bookings b ON b.id=c.booking_id AND b.user_id=c.user_id JOIN dream_trips dt ON dt.id=c.dream_trip_id AND dt.user_id=c.user_id WHERE c.user_id=?$where ORDER BY c.updated_at DESC,c.id DESC LIMIT $limit");
        $q->execute($params);$rows=$q->fetchAll()?:[];
        foreach($rows as &$r){foreach(['old_json'=>'old','new_json'=>'new','diff_json'=>'diff'] as $col=>$key){$v=json_decode((string)$r[$col],true);$r[$key]=is_array($v)?$v:[];unset($r[$col]);}$r['id']=(int)$r['id'];$r['message_id']=(int)$r['message_id'];$r['booking_id']=(int)$r['booking_id'];$r['dream_trip_id']=(int)$r['dream_trip_id'];}unset($r);
        return $rows;
    }

    public function applyChange(int $userId,int $proposalId): array
    {
        $this->requireReady();$p=null;$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare("SELECT c.* FROM booking_change_proposals c WHERE c.id=? AND c.user_id=? FOR UPDATE");$q->execute([$proposalId,$userId]);$p=$q->fetch();
            if(!$p)throw new OutOfBoundsException('Reservation change was not found.');
            if((string)$p['status']!=='needs_review')throw new DomainException('This reservation change is no longer waiting for review.');
            $booking=$this->bookingRow($userId,(int)$p['booking_id'],true);if(!$booking)throw new DomainException('The linked booking no longer exists.');
            if((int)$booking['dream_trip_id']!==(int)$p['dream_trip_id'])throw new DomainException('The linked booking moved to another trip; review the current trip before applying this email.');
            $current=$this->bookingProjection($booking);
            if(!hash_equals((string)$p['booking_snapshot_hash'],$this->snapshotHash($current))){
                $this->pdo->prepare("UPDATE booking_change_proposals SET status='superseded',updated_at=NOW() WHERE id=? AND user_id=?")->execute([$proposalId,$userId]);
                $this->pdo->commit();
                throw new DomainException('The booking changed after this email was received. The old proposal was superseded; sync or review the newer booking state.');
            }
            $new=json_decode((string)$p['new_json'],true);if(!is_array($new))$new=[];
            $bookingService=new TripBookingService($this->pdo);$tripId=(int)$p['dream_trip_id'];$bookingId=(int)$p['booking_id'];
            if((string)$p['change_type']==='cancelled'){
                $bookingService->updateBookingStatus($userId,$tripId,$bookingId,'cancelled');
            }else{
                $input=['booking_id'=>$bookingId];foreach(self::BOOKING_SAVE_FIELDS as $field)if(array_key_exists($field,$new))$input[$field]=$new[$field];
                if(count($input)>1)$bookingService->saveBooking($userId,$tripId,$input);
                if((string)($booking['booking_type']??'')==='flight'&&array_intersect(['flight_number','departure_iata','arrival_iata'],array_keys($new))){
                    $flight=new TripFlightTrackingService($this->pdo);if($flight->ready())$flight->save($userId,$tripId,$bookingId,[
                        'flight_number'=>$new['flight_number']??$booking['flight_number']??'',
                        'departure_iata'=>$new['departure_iata']??$booking['departure_iata']??'',
                        'arrival_iata'=>$new['arrival_iata']??$booking['arrival_iata']??'',
                    ]);
                }
            }
            $this->pdo->prepare("UPDATE booking_change_proposals SET status='applied',applied_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?")->execute([$proposalId,$userId]);
            $this->pdo->prepare("UPDATE booking_mail_messages SET status='applied',updated_at=NOW() WHERE id=? AND user_id=?")->execute([(int)$p['message_id'],$userId]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $tripId=(int)($p['dream_trip_id']??0);
        if($tripId>0){
            try{$ops=new TripTravelOperationsService($this->pdo);if($ops->ready())$ops->snapshot($userId,$tripId,null,true);}catch(Throwable $e){error_log('Post-email booking operations sync failed: '.$e->getMessage());}
            try{$proactive=new ProactiveTravelService($this->pdo);if($proactive->ready())$proactive->assessTrip($userId,$tripId,true,'booking_mail_change');}catch(Throwable $e){error_log('Post-email proactive assessment failed: '.$e->getMessage());}
        }
        return $this->changes($userId,'all',100);
    }

    public function dismissChange(int $userId,int $proposalId): void
    {
        $this->requireReady();$q=$this->pdo->prepare("SELECT message_id,status FROM booking_change_proposals WHERE id=? AND user_id=? LIMIT 1");$q->execute([$proposalId,$userId]);$row=$q->fetch();
        if(!$row)throw new OutOfBoundsException('Reservation change was not found.');if((string)$row['status']!=='needs_review')throw new DomainException('This reservation change is no longer waiting for review.');
        $this->pdo->beginTransaction();try{$this->pdo->prepare("UPDATE booking_change_proposals SET status='dismissed',dismissed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?")->execute([$proposalId,$userId]);$this->pdo->prepare("UPDATE booking_mail_messages SET status='dismissed',updated_at=NOW() WHERE id=? AND user_id=?")->execute([(int)$row['message_id'],$userId]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function source(int $userId,int $messageId): array
    {
        $this->requireReady();$q=$this->pdo->prepare('SELECT subject,source_encrypted FROM booking_mail_messages WHERE id=? AND user_id=? LIMIT 1');$q->execute([$messageId,$userId]);$row=$q->fetch();
        if(!$row)throw new OutOfBoundsException('Connected-mail message was not found.');$body=$this->decrypt((string)($row['source_encrypted']??''));if($body==='')throw new RuntimeException('No retained redacted source is available for that message.');
        $name='booking-mail-'.$messageId.'-'.preg_replace('/[^A-Za-z0-9_-]+/','-',substr((string)($row['subject']??'message'),0,60)).'.txt';return ['filename'=>$name,'mime'=>'text/plain; charset=utf-8','body'=>$body];
    }

    public function augmentCommandCenterSnapshot(int $userId,array $snapshot): array
    {
        if(!$this->ready())return $snapshot;
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM booking_change_proposals WHERE user_id=? AND status='needs_review'");$q->execute([$userId]);$count=(int)$q->fetchColumn();$snapshot['summary']['booking_changes_review']=$count;
        if($count>0){$item=['key'=>'booking-mail-change-review','kind'=>'booking_change','priority'=>97,'title'=>$count===1?'Review a reservation change':'Review '.$count.' reservation changes','body'=>'Connected Booking Inbox found a provider-reported change. Review the before/after details before Vacation Brain updates the canonical trip.','trip_name'=>'','url'=>app_url('booking-mailbox.php'),'cta'=>'Review changes'];$attention=is_array($snapshot['attention']??null)?$snapshot['attention']:[];array_unshift($attention,$item);$snapshot['attention']=array_slice($attention,0,8);$snapshot['summary']['needs_you']=count($snapshot['attention']);$snapshot['status']='Needs your attention';}
        $conn=$this->connection($userId);if($conn&&((string)$conn['status']==='error'||!empty($conn['last_error'])))$snapshot['summary']['booking_mail_issue']=1;
        return $snapshot;
    }

    public function safeAgentContext(int $userId,int $limit=8): array
    {
        if(!$this->ready())return [];$limit=max(1,min(20,$limit));
        $q=$this->pdo->prepare("SELECT c.change_type,c.status,c.diff_json,c.updated_at,b.title booking_title,dt.name trip_name FROM booking_change_proposals c JOIN trip_bookings b ON b.id=c.booking_id AND b.user_id=c.user_id JOIN dream_trips dt ON dt.id=c.dream_trip_id AND dt.user_id=c.user_id WHERE c.user_id=? AND c.status IN ('needs_review','applied') ORDER BY FIELD(c.status,'needs_review','applied'),c.updated_at DESC LIMIT $limit");
        $q->execute([$userId]);$out=[];
        foreach($q->fetchAll()?:[] as $r){$diff=json_decode((string)$r['diff_json'],true);if(!is_array($diff))$diff=[];$safe=[];foreach($diff as $field=>$change)if(in_array($field,array_merge(self::CHANGE_FIELDS,['status']),true))$safe[$field]=$change;$out[]=['trip_name'=>(string)$r['trip_name'],'booking_title'=>(string)$r['booking_title'],'change_type'=>(string)$r['change_type'],'status'=>(string)$r['status'],'diff'=>$safe,'updated_at'=>(string)$r['updated_at']];}
        return $out;
    }

    private function changeForBooking(array $booking,array $parsed,array $message): array
    {
        $old=$this->bookingProjection($booking);$new=[];$diff=[];$cancelled=$this->isCancellationMessage($message);
        if($cancelled){if((string)$booking['status']==='cancelled')return ['no_change',null];$new=['status'=>'cancelled'];$diff['status']=['from'=>(string)$booking['status'],'to'=>'cancelled'];return ['cancelled',['type'=>'cancelled','old'=>$old,'new'=>$new,'diff'=>$diff]];}
        foreach(self::CHANGE_FIELDS as $field){
            if(!array_key_exists($field,$parsed)||$parsed[$field]===null||$parsed[$field]==='')continue;
            $from=$old[$field]??null;$to=$parsed[$field];
            if($field==='amount'){$from=$from===null?null:round((float)$from,2);$to=round((float)$to,2);}
            if((string)$from!==(string)$to){$new[$field]=$to;$diff[$field]=['from'=>$from,'to'=>$to];}
        }
        if($diff)return ['changed',['type'=>'changed','old'=>$old,'new'=>$new,'diff'=>$diff]];
        return ['no_change',null];
    }

    private function insertChangeProposal(int $userId,int $connectionId,int $messageId,array $booking,array $proposal): void
    {
        $old=(array)$proposal['old'];$new=(array)$proposal['new'];$diff=(array)$proposal['diff'];
        $q=$this->pdo->prepare("INSERT IGNORE INTO booking_change_proposals (user_id,connection_id,message_id,dream_trip_id,booking_id,change_type,status,booking_snapshot_hash,old_json,new_json,diff_json) VALUES (?,?,?,?,?,?,'needs_review',?,?,?,?)");
        $q->execute([$userId,$connectionId,$messageId,(int)$booking['dream_trip_id'],(int)$booking['id'],(string)$proposal['type'],$this->snapshotHash($old),json_encode($old,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($new,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($diff,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }

    private function insertMessage(array $conn,array $message,string $classification,string $status,?string $source,?int $importId,?int $bookingId): int
    {
        $enc=$source!==null&&$source!==''?$this->encrypt($source):null;$messageDate=trim((string)($message['message_date']??''));if($messageDate==='')$messageDate=null;
        $q=$this->pdo->prepare("INSERT INTO booking_mail_messages (connection_id,user_id,provider_message_id,provider_thread_id,message_date,from_address,from_name,subject,snippet,classification,status,source_encrypted,import_id,booking_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $q->execute([(int)$conn['id'],(int)$conn['user_id'],(string)$message['id'],(string)($message['thread_id']??''),$messageDate,$this->nullable((string)($message['from_address']??''),255),$this->nullable((string)($message['from_name']??''),255),$this->nullable((string)($message['subject']??''),500),$this->nullable((string)($message['snippet']??''),700),$classification,$status,$enc,$importId,$bookingId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertErrorMessage(array $conn,string $providerId,string $message): void
    {
        try{$q=$this->pdo->prepare("INSERT IGNORE INTO booking_mail_messages (connection_id,user_id,provider_message_id,classification,status,error_message) VALUES (?,?,?,?,?,?)");$q->execute([(int)$conn['id'],(int)$conn['user_id'],$providerId,'unknown','error',$this->clip($message,700)]);}catch(Throwable){}
    }

    private function messageExists(int $connectionId,string $providerId): bool
    {
        $q=$this->pdo->prepare('SELECT 1 FROM booking_mail_messages WHERE connection_id=? AND provider_message_id=? LIMIT 1');$q->execute([$connectionId,$providerId]);return (bool)$q->fetchColumn();
    }

    private function decodeGmailMessage(array $raw): array
    {
        $headers=[];foreach(is_array($raw['payload']['headers']??null)?$raw['payload']['headers']:[] as $h){$name=strtolower(trim((string)($h['name']??'')));if($name!=='')$headers[$name]=(string)($h['value']??'');}
        $from=$this->decodeHeader((string)($headers['from']??''));[$fromName,$fromAddress]=$this->parseFrom($from);
        $body=$this->gmailBody((array)($raw['payload']??[]));if($body==='')$body=(string)($raw['snippet']??'');
        $internal=(int)($raw['internalDate']??0);$date=$internal>0?date('Y-m-d H:i:s',(int)floor($internal/1000)):null;
        return ['id'=>(string)($raw['id']??''),'thread_id'=>(string)($raw['threadId']??''),'message_date'=>$date,'from'=>$from,'from_name'=>$fromName,'from_address'=>$fromAddress,'subject'=>$this->decodeHeader((string)($headers['subject']??'')),'snippet'=>html_entity_decode((string)($raw['snippet']??''),ENT_QUOTES|ENT_HTML5,'UTF-8'),'body'=>$body];
    }

    private function gmailBody(array $part): string
    {
        $plain=[];$html=[];
        $walk=function(array $p)use(&$walk,&$plain,&$html){$mime=strtolower((string)($p['mimeType']??''));$data=(string)($p['body']['data']??'');if($data!==''){$decoded=$this->base64UrlDecode($data);if($mime==='text/plain')$plain[]=$decoded;elseif($mime==='text/html')$html[]=html_entity_decode(strip_tags($decoded),ENT_QUOTES|ENT_HTML5,'UTF-8');}foreach(is_array($p['parts']??null)?$p['parts']:[] as $child)$walk((array)$child);};
        $walk($part);$text=$plain?implode("\n",$plain):implode("\n",$html);$text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$text)??$text;return substr(trim($text),0,120000);
    }

    private function looksTravelRelated(array $message): bool
    {
        $hay=strtolower((string)$message['subject'].' '.(string)$message['from'].' '.substr((string)$message['body'],0,12000));$score=0;
        foreach(['reservation','confirmation','itinerary','flight','airline','hotel','resort','check-in','check in','rental car','boarding','ticket','booking','departure','arrival'] as $needle)if(str_contains($hay,$needle))$score++;
        foreach(['booking.com','airbnb','vrbo','expedia','marriott','hilton','hyatt','united','aa.com','delta','southwest','alaskaair','jetblue','frontier','spirit','hertz','avis','enterprise','ticketmaster','opentable','resy'] as $provider)if(str_contains($hay,$provider)){$score+=2;break;}
        return $score>=2;
    }

    private function parseMessage(int $userId,array $message,string $source,bool $useAi): array
    {
        $flat=preg_replace('/[\t ]+/',' ',$source)??$source;$out=[];$type='other';
        if(preg_match('/\b(flight|airline|boarding|departure|record locator)\b/i',$flat))$type='flight';
        elseif(preg_match('/\b(hotel|resort|lodging|check[- ]?in|airbnb|vrbo|room)\b/i',$flat))$type='lodging';
        elseif(preg_match('/\b(rental car|car rental|vehicle|hertz|avis|enterprise)\b/i',$flat))$type='transport';
        elseif(preg_match('/\b(restaurant|dinner|lunch|table reservation|opentable|resy)\b/i',$flat))$type='restaurant';
        elseif(preg_match('/\b(ticket|concert|show|event|ticketmaster)\b/i',$flat))$type='event';
        elseif(preg_match('/\b(activity|tour|excursion|experience|admission)\b/i',$flat))$type='activity';
        $out['booking_type']=$type;
        foreach(['Booking.com','Airbnb','Vrbo','Marriott','Hilton','Hyatt','IHG','Expedia','United Airlines','American Airlines','Delta Air Lines','Southwest Airlines','Alaska Airlines','JetBlue','Frontier Airlines','Spirit Airlines','Hertz','Avis','Enterprise','Ticketmaster','OpenTable','Resy'] as $provider)if(stripos($flat,$provider)!==false){$out['provider_name']=$provider;break;}
        if(empty($out['provider_name']))$out['provider_name']=$this->providerFromSender((string)($message['from_address']??''),(string)($message['from_name']??''));
        if(preg_match('/(?:confirmation|reservation|record locator|booking)(?:\s+(?:number|code|id))?\s*[:#]?\s*([A-Z0-9][A-Z0-9-]{3,24})/i',$flat,$m))$out['confirmation_code']=strtoupper($m[1]);
        if($type==='flight'&&preg_match('/\b([A-Z]{2}|[A-Z][0-9]|[0-9][A-Z])\s?([0-9]{1,4})\b/',$flat,$m))$out['flight_number']=strtoupper($m[1].$m[2]);
        if(preg_match('/\b([A-Z]{3})\s*(?:→|->|to|–|-)\s*([A-Z]{3})\b/i',$flat,$m)){$out['departure_iata']=strtoupper($m[1]);$out['arrival_iata']=strtoupper($m[2]);}
        if(preg_match('/(?:total|amount|price|paid)\s*[:\-]?\s*(?:USD\s*)?\$?\s*([0-9]{1,7}(?:,[0-9]{3})*(?:\.\d{2})?)/i',$flat,$m))$out['amount']=(float)str_replace(',','',$m[1]);
        if(preg_match('/\b(USD|EUR|GBP|CAD|AUD|JPY)\b/i',$flat,$m))$out['currency']=strtoupper($m[1]);elseif(str_contains($flat,'$'))$out['currency']='USD';
        $out=array_merge($out,$this->explicitDates($flat,$type));$provider=(string)($out['provider_name']??'');$flight=(string)($out['flight_number']??'');$out['title']=$flight!==''?'Flight '.$flight:($provider!==''?ucfirst($type).' · '.$provider:ucfirst($type).' reservation');
        if($useAi){$aiSource=$this->redactForAi($source);$ai=$this->parseWithAi($userId,$aiSource);foreach($ai as $k=>$v)if($v!==null&&$v!==''&&(!isset($out[$k])||$out[$k]===''||in_array($k,['starts_at','ends_at','cancellation_deadline','checkin_opens_at'],true)))$out[$k]=$v;}
        return $this->normalizeParsed($out);
    }

    private function redactForAi(string $source): string
    {
        $source=$this->redactPaymentData($source);
        $source=preg_replace('/((?:confirmation|reservation|record locator|booking)(?:\s+(?:number|code|id))?\s*[:#]?\s*)[A-Z0-9][A-Z0-9-]{3,24}/i','$1[private code removed]',$source)??$source;
        return $source;
    }

    private function explicitDates(string $text,string $type): array
    {
        $out=[];$date='(?:20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}\/\d{1,2}\/20\d{2}|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},?\s+20\d{2})';$time='(?:\s+(?:at\s+)?\d{1,2}:\d{2}\s*(?:AM|PM)?)?';
        $patterns=$type==='lodging'?[['starts_at','check[- ]?in'],['ends_at','check[- ]?out']]:($type==='transport'?[['starts_at','pick[- ]?up'],['ends_at','drop[- ]?off']]:[['starts_at','(?:depart(?:ure)?|event|reservation|start)'],['ends_at','(?:arriv(?:al)?|end)']]);
        foreach($patterns as [$key,$label])if(preg_match('/\b'.$label.'(?:\s+(?:date|time))?\s*[:\-]?\s*('.$date.$time.')/i',$text,$m)){if($ts=strtotime($m[1]))$out[$key]=date('Y-m-d H:i:s',$ts);}
        if(preg_match('/\bcancel(?:lation)?(?:\s+free)?(?:\s+by|\s+before)?\s*[:\-]?\s*('.$date.$time.')/i',$text,$m)){if($ts=strtotime($m[1]))$out['cancellation_deadline']=date('Y-m-d H:i:s',$ts);}
        if(preg_match('/\bcheck[- ]?in(?:\s+opens|\s+from|\s+at)\s*[:\-]?\s*('.$date.$time.')/i',$text,$m)){if($ts=strtotime($m[1]))$out['checkin_opens_at']=date('Y-m-d H:i:s',$ts);}
        return $out;
    }

    private function parseWithAi(int $userId,string $source): array
    {
        try{
            $system='Extract only explicit travel reservation facts from traveler-provided confirmation text. Return exactly one JSON object and no prose. Never infer missing facts. Never return payment cards, bank data, passwords, login links, security codes, confirmation/record-locator codes, traveler names, sender identity, or arbitrary email text. Allowed keys: booking_type,title,provider_name,flight_number,departure_iata,arrival_iata,starts_at,ends_at,cancellation_deadline,checkin_opens_at,amount,currency. booking_type must be flight,lodging,transport,event,restaurant,activity,document,other. Dates must be YYYY-MM-DD HH:MM:SS only when clearly stated.';
            $raw=(new AiProviderService($this->pdo))->generateText($system,substr($source,0,20000),$userId,'booking_mail_parse',900);if(!$raw)return [];$raw=trim($raw);
            if(str_starts_with($raw,'```')){$raw=preg_replace('/^```(?:json)?\s*/i','',$raw)??$raw;$raw=preg_replace('/\s*```$/','',$raw)??$raw;}
            $json=json_decode($raw,true);if(!is_array($json))return [];
            $allowed=array_flip(['booking_type','title','provider_name','flight_number','departure_iata','arrival_iata','starts_at','ends_at','cancellation_deadline','checkin_opens_at','amount','currency']);
            return $this->normalizeParsed(array_intersect_key($json,$allowed));
        }catch(Throwable $e){error_log('Connected Booking Inbox AI parse failed: '.$e->getMessage());return [];}
    }

    private function findBooking(int $userId,array $parsed): ?array
    {
        $confirmation=trim((string)($parsed['confirmation_code']??''));
        if($confirmation!==''){
            $q=$this->pdo->prepare("SELECT * FROM trip_bookings WHERE user_id=? AND confirmation_code=? AND status<>'cancelled' ORDER BY id DESC LIMIT 3");$q->execute([$userId,$confirmation]);$rows=$q->fetchAll()?:[];
            $rows=array_values(array_filter($rows,fn($row)=>$this->bookingCompatible($row,$parsed)));if(count($rows)===1)return $rows[0];
        }
        $flight=trim((string)($parsed['flight_number']??''));$starts=trim((string)($parsed['starts_at']??''));
        if($flight!==''&&db_column_exists('trip_bookings','flight_number')){$sql="SELECT * FROM trip_bookings WHERE user_id=? AND booking_type='flight' AND flight_number=? AND status<>'cancelled'";$params=[$userId,$flight];if($starts!==''){$sql.=' AND starts_at BETWEEN DATE_SUB(?,INTERVAL 3 DAY) AND DATE_ADD(?,INTERVAL 3 DAY)';$params[]=$starts;$params[]=$starts;}$sql.=' ORDER BY id DESC LIMIT 3';$q=$this->pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];if(count($rows)===1)return $rows[0];}
        $provider=trim((string)($parsed['provider_name']??''));$type=trim((string)($parsed['booking_type']??''));
        if($provider!==''&&$type!==''&&$starts!==''){$q=$this->pdo->prepare("SELECT * FROM trip_bookings WHERE user_id=? AND booking_type=? AND provider_name=? AND status<>'cancelled' AND starts_at BETWEEN DATE_SUB(?,INTERVAL 1 DAY) AND DATE_ADD(?,INTERVAL 1 DAY) ORDER BY id DESC LIMIT 3");$q->execute([$userId,$type,$provider,$starts,$starts]);$rows=$q->fetchAll()?:[];if(count($rows)===1)return $rows[0];}
        return null;
    }

    private function bookingCompatible(array $booking,array $parsed): bool
    {
        $type=(string)($parsed['booking_type']??'other');if($type!=='other'&&!empty($booking['booking_type'])&&(string)$booking['booking_type']!==$type)return false;
        $provider=$this->providerKey((string)($parsed['provider_name']??''));$existing=$this->providerKey((string)($booking['provider_name']??''));if($provider!==''&&$existing!==''&&$provider!==$existing)return false;
        return true;
    }

    private function providerKey(string $value): string
    {
        $v=strtolower(preg_replace('/[^a-z0-9]+/','',trim($value))??'');foreach(['airlines','airline','hotels','hotel','inc','com'] as $suffix)$v=str_replace($suffix,'',$v);return $v;
    }

    private function isCancellationMessage(array $message): bool
    {
        $subject=strtolower((string)($message['subject']??''));$body=strtolower(substr((string)($message['body']??''),0,20000));
        if(preg_match('/\b(cancelled|canceled|cancellation confirmed)\b/',$subject))return true;
        return preg_match('/\b(your|this|the)\s+(reservation|booking|flight|stay|trip|ticket)\s+(has been|was|is)\s+(cancelled|canceled)\b/',$body)===1;
    }

    private function bookingProjection(array $b): array
    {
        $out=[];foreach(array_merge(['id','dream_trip_id','booking_type','status'],self::CHANGE_FIELDS) as $k)$out[$k]=$b[$k]??null;return $out;
    }

    private function snapshotHash(array $projection): string
    {
        return hash('sha256',json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function bookingRow(int $userId,int $bookingId,bool $lock=false): ?array
    {
        $sql='SELECT * FROM trip_bookings WHERE id=? AND user_id=? LIMIT 1'.($lock?' FOR UPDATE':'');$q=$this->pdo->prepare($sql);$q->execute([$bookingId,$userId]);$r=$q->fetch();return $r?:null;
    }

    private function connectionRow(int $userId): ?array
    {
        $q=$this->pdo->prepare("SELECT * FROM booking_mail_connections WHERE user_id=? AND provider='gmail' LIMIT 1");$q->execute([$userId]);$r=$q->fetch();return $r?:null;
    }

    private function accessToken(array $conn): string
    {
        $expires=!empty($conn['token_expires_at'])?strtotime((string)$conn['token_expires_at']):0;
        $access=!empty($conn['access_token_encrypted'])?$this->decrypt((string)$conn['access_token_encrypted']):'';
        if($access!==''&&$expires>time()+90)return $access;
        $refresh=!empty($conn['refresh_token_encrypted'])?$this->decrypt((string)$conn['refresh_token_encrypted']):'';if($refresh==='')throw new RuntimeException('The Gmail connection needs to be reauthorized.');
        $token=$this->requestJson('POST',self::GOOGLE_TOKEN,[],['client_id'=>$this->googleClientId(),'client_secret'=>$this->googleClientSecret(),'refresh_token'=>$refresh,'grant_type'=>'refresh_token']);
        $access=trim((string)($token['access_token']??''));if($access==='')throw new RuntimeException('Google could not refresh the Gmail access token.');$expiresAt=date('Y-m-d H:i:s',time()+max(300,(int)($token['expires_in']??3600)));
        $this->pdo->prepare('UPDATE booking_mail_connections SET access_token_encrypted=?,token_expires_at=?,last_error=NULL,status=\'connected\',updated_at=NOW() WHERE id=?')->execute([$this->encrypt($access),$expiresAt,(int)$conn['id']]);return $access;
    }

    private function gmailJson(string $access,string $path): array
    {
        return $this->requestJson('GET',self::GMAIL_API.$path,['Authorization: Bearer '.$access]);
    }

    private function requestJson(string $method,string $url,array $headers=[],?array $form=null): array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for connected Gmail.');
        $ch=curl_init($url);$headers[]='Accept: application/json';$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method];
        if($form!==null){$opts[CURLOPT_POSTFIELDS]=http_build_query($form,'','&',PHP_QUERY_RFC3986);$opts[CURLOPT_HTTPHEADER]=array_merge($headers,['Content-Type: application/x-www-form-urlencoded']);}
        curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
        if($raw===false||$err!=='')throw new RuntimeException('Connected Gmail request failed.');$json=json_decode((string)$raw,true);
        if($code<200||$code>=300){$detail=is_array($json)?(string)($json['error_description']??$json['error']['message']??''):'';throw new RuntimeException('Google Gmail request failed (HTTP '.$code.').'.($detail!==''?' '.$this->clip($detail,240):''));}
        return is_array($json)?$json:[];
    }

    private function revokeGoogleToken(string $token): void
    {
        if(!function_exists('curl_init'))return;
        try{$ch=curl_init(self::GOOGLE_REVOKE);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query(['token'=>$token],'','&',PHP_QUERY_RFC3986)]);curl_exec($ch);curl_close($ch);}catch(Throwable){}
    }

    private function markConnectionError(int $userId,string $error): void
    {
        try{$fatal=preg_match('/\b(401|invalid_grant|unauthenticated|revoked|reauthor)/i',$error)===1;$this->pdo->prepare("UPDATE booking_mail_connections SET status=IF(?=1,'error',status),last_error=?,next_sync_after=DATE_ADD(NOW(),INTERVAL 30 MINUTE),updated_at=NOW() WHERE user_id=? AND provider='gmail'")->execute([$fatal?1:0,$this->clip($error,700),$userId]);}catch(Throwable){}
    }

    private function googleClientId(): string{return trim((string)($this->config['booking_mail']['google_client_id']??getenv('GOOGLE_GMAIL_CLIENT_ID')?:''));}
    private function googleClientSecret(): string{return trim((string)($this->config['booking_mail']['google_client_secret']??getenv('GOOGLE_GMAIL_CLIENT_SECRET')?:''));}
    private function googleRedirectUri(): string{$configured=trim((string)($this->config['booking_mail']['google_redirect_uri']??''));return $configured!==''?$configured:app_url('booking-mail-oauth.php');}

    private function providerFromSender(string $email,string $name): ?string
    {
        $domain=strtolower((string)substr(strrchr($email,'@')?:'',1));$map=['booking.com'=>'Booking.com','airbnb.com'=>'Airbnb','vrbo.com'=>'Vrbo','expedia.com'=>'Expedia','marriott.com'=>'Marriott','hilton.com'=>'Hilton','hyatt.com'=>'Hyatt','united.com'=>'United Airlines','aa.com'=>'American Airlines','delta.com'=>'Delta Air Lines','southwest.com'=>'Southwest Airlines','alaskaair.com'=>'Alaska Airlines','jetblue.com'=>'JetBlue','flyfrontier.com'=>'Frontier Airlines','spirit.com'=>'Spirit Airlines','hertz.com'=>'Hertz','avis.com'=>'Avis','enterprise.com'=>'Enterprise','ticketmaster.com'=>'Ticketmaster','opentable.com'=>'OpenTable','resy.com'=>'Resy'];
        foreach($map as $needle=>$label)if($domain===$needle||str_ends_with($domain,'.'.$needle))return $label;$name=$this->clip(trim($name),100);return $name!==''?$name:null;
    }

    private function parseFrom(string $from): array
    {
        if(preg_match('/^(.*?)<([^>]+)>$/',$from,$m)){$name=trim(trim($m[1])," \t\n\r\0\x0B\"");$email=strtolower(trim($m[2]));return [$name,filter_var($email,FILTER_VALIDATE_EMAIL)?$email:''];}$email=strtolower(trim($from));return ['',filter_var($email,FILTER_VALIDATE_EMAIL)?$email:''];
    }

    private function decodeHeader(string $v): string
    {
        if(function_exists('iconv_mime_decode')){$d=@iconv_mime_decode($v,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8');if(is_string($d))return $d;}return $v;
    }

    private function base64UrlDecode(string $data): string
    {
        $data=strtr($data,'-_','+/');$pad=strlen($data)%4;if($pad)$data.=str_repeat('=',4-$pad);$decoded=base64_decode($data,true);return $decoded===false?'':$decoded;
    }

    private function redactPaymentData(string $text): string
    {
        $text=preg_replace('/\b(?:\d[ -]*?){13,19}\b/','[payment-card number removed]',$text)??$text;$text=preg_replace('/\b((?:cvv|cvc|security code))\s*[:#-]?\s*\d{3,4}\b/i','$1 [removed]',$text)??$text;return substr(trim($text),0,120000);
    }

    private function normalizeParsed(array $p): array
    {
        $out=[];$type=strtolower(trim((string)($p['booking_type']??'other')));$out['booking_type']=in_array($type,['flight','lodging','transport','event','restaurant','activity','document','other'],true)?$type:'other';
        foreach(['title'=>180,'provider_name'=>180,'confirmation_code'=>120] as $k=>$n){$v=$this->clip((string)($p[$k]??''),$n);if($v!=='')$out[$k]=$v;}
        $flight=preg_replace('/\s+/','',strtoupper((string)($p['flight_number']??'')))??'';if(preg_match('/^(?:[A-Z]{2}|[A-Z][0-9]|[0-9][A-Z])[0-9]{1,4}$/',$flight))$out['flight_number']=$flight;
        foreach(['departure_iata','arrival_iata'] as $k){$v=strtoupper(trim((string)($p[$k]??'')));if(preg_match('/^[A-Z]{3}$/',$v))$out[$k]=$v;}
        foreach(['starts_at','ends_at','cancellation_deadline','checkin_opens_at'] as $k){$v=trim((string)($p[$k]??''));if($v!==''&&($ts=strtotime($v)))$out[$k]=date('Y-m-d H:i:s',$ts);}
        if(isset($p['amount'])&&is_numeric(str_replace(',','',(string)$p['amount']))){$n=(float)str_replace(',','',(string)$p['amount']);if($n>=0&&$n<=99999999)$out['amount']=round($n,2);}
        $currency=strtoupper(trim((string)($p['currency']??'')));if(preg_match('/^[A-Z]{3}$/',$currency))$out['currency']=$currency;
        return $out;
    }

    private function cryptoKey(): string
    {
        $material=(string)($this->config['app']['internal_key']??'');if($material===''){$db=$this->config['db']??[];$material=implode('|',[(string)($db['host']??''),(string)($db['name']??''),(string)($db['user']??''),(string)($db['pass']??''),(string)($this->config['app']['base_url']??'')]);}return hash('sha256',$material,true);
    }

    private function encrypt(string $plain): string
    {
        if(!function_exists('openssl_encrypt'))throw new RuntimeException('PHP OpenSSL is required for connected Booking Inbox.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Could not encrypt connected Booking Inbox data.');return 'v1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($cipher);
    }

    private function decrypt(string $payload): string
    {
        if(!function_exists('openssl_decrypt')||!str_starts_with($payload,'v1.'))return '';$p=explode('.',$payload,4);if(count($p)!==4)return '';$iv=base64_decode($p[1],true);$tag=base64_decode($p[2],true);$cipher=base64_decode($p[3],true);if($iv===false||$tag===false||$cipher===false)return '';$plain=openssl_decrypt($cipher,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);return is_string($plain)?$plain:'';
    }

    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Connected Booking Inbox.');}
    private function clip(string $v,int $n): string{$v=trim($v);return function_exists('mb_substr')?mb_substr($v,0,$n):substr($v,0,$n);}
    private function nullable(string $v,int $n): ?string{$v=$this->clip($v,$n);return $v===''?null:$v;}
}
