<?php
declare(strict_types=1);

/**
 * Controlled provider action layer for Vacation Brain.
 *
 * The service deliberately separates planning approval from transaction approval:
 *  1. prepare a provider quote / handoff,
 *  2. lock and display the exact terms,
 *  3. require explicit user approval,
 *  4. execute or open the provider handoff,
 *  5. verify and write an immutable receipt.
 *
 * Payment-card data is never accepted or stored here. New bookings therefore use
 * provider-hosted checkout. The only direct provider mutation in v1.40 is an
 * explicitly-approved Booking.com accommodation cancellation after live policy
 * and fee revalidation.
 */
final class TripBookingActionService
{
    private const ACTIVE_STATUSES=['draft','prepared','awaiting_approval','approved','executing','handoff_pending','verification_pending'];

    private array $config;

    public function __construct(private PDO $pdo)
    {
        global $config;
        $this->config=is_array($config??null)?$config:[];
    }

    public function ready(): bool
    {
        return db_table_exists('trip_booking_action_intents')
            && db_table_exists('trip_booking_action_quotes')
            && db_table_exists('trip_booking_action_receipts');
    }

    public function intentsForTrip(int $userId,int $tripId,int $limit=50): array
    {
        if(!$this->ready())return [];
        $this->assertTrip($userId,$tripId);
        $limit=max(1,min(100,$limit));
        $stmt=$this->pdo->prepare("SELECT i.*,q.amount quote_amount,q.currency quote_currency,q.fee_amount quote_fee,q.terms_summary,q.source_state quote_source_state,q.expires_at quote_expires_at,q.quote_digest
            FROM trip_booking_action_intents i
            LEFT JOIN trip_booking_action_quotes q ON q.id=i.current_quote_id
            WHERE i.user_id=? AND i.dream_trip_id=?
            ORDER BY FIELD(i.status,'awaiting_approval','approved','executing','handoff_pending','verification_pending','failed','prepared','draft','completed','cancelled'),i.updated_at DESC,i.id DESC
            LIMIT $limit");
        $stmt->execute([$userId,$tripId]);
        $rows=$stmt->fetchAll()?:[];
        foreach($rows as &$row)$row=$this->publicIntent($row);unset($row);
        return $rows;
    }

    public function intent(int $userId,int $tripId,int $intentId): ?array
    {
        if(!$this->ready()||$intentId<1)return null;
        $stmt=$this->pdo->prepare("SELECT i.*,q.amount quote_amount,q.currency quote_currency,q.fee_amount quote_fee,q.terms_summary,q.source_state quote_source_state,q.expires_at quote_expires_at,q.quote_digest
            FROM trip_booking_action_intents i
            LEFT JOIN trip_booking_action_quotes q ON q.id=i.current_quote_id
            WHERE i.id=? AND i.user_id=? AND i.dream_trip_id=? LIMIT 1");
        $stmt->execute([$intentId,$userId,$tripId]);
        $row=$stmt->fetch();
        return $row?$this->publicIntent($row):null;
    }

    public function intentForBooking(int $userId,int $tripId,int $bookingId): ?array
    {
        if(!$this->ready()||$bookingId<1)return null;
        $stmt=$this->pdo->prepare("SELECT id FROM trip_booking_action_intents WHERE user_id=? AND dream_trip_id=? AND booking_id=? AND status NOT IN ('completed','cancelled') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId,$tripId,$bookingId]);
        $id=(int)($stmt->fetchColumn()?:0);
        return $id>0?$this->intent($userId,$tripId,$id):null;
    }

    /** Create/reuse a provider-hosted checkout intent for a Ready to Book booking. */
    public function ensureHandoffIntent(int $userId,int $tripId,int $bookingId,?int $actionId=null,?int $executionId=null): array
    {
        $this->requireReady();
        $booking=$this->booking($userId,$tripId,$bookingId);
        if(in_array((string)$booking['status'],['cancelled','confirmed'],true))throw new DomainException('This booking does not need a provider checkout handoff.');
        $provider=$this->providerSlug((string)($booking['provider_name']??''),(string)($booking['provider_url']??''),(string)$booking['booking_type']);
        $actionType=$this->handoffActionType((string)$booking['booking_type']);
        $key=hash('sha256','handoff|'.$userId.'|'.$tripId.'|'.$bookingId.'|'.$provider.'|'.$actionType);
        $this->pdo->prepare("INSERT INTO trip_booking_action_intents
            (user_id,dream_trip_id,booking_id,action_id,action_execution_id,action_type,provider_slug,adapter_mode,status,idempotency_key,provider_url)
            VALUES (?,?,?,?,?,?,?,'provider_handoff','draft',?,?)
            ON DUPLICATE KEY UPDATE booking_id=VALUES(booking_id),action_id=COALESCE(action_id,VALUES(action_id)),action_execution_id=COALESCE(action_execution_id,VALUES(action_execution_id)),provider_url=COALESCE(VALUES(provider_url),provider_url),updated_at=NOW()")
            ->execute([$userId,$tripId,$bookingId,$actionId,$executionId,$actionType,$provider,$key,$this->safeUrl((string)($booking['provider_url']??''))]);
        $stmt=$this->pdo->prepare('SELECT id FROM trip_booking_action_intents WHERE idempotency_key=? LIMIT 1');$stmt->execute([$key]);$intentId=(int)$stmt->fetchColumn();
        if($intentId<1)throw new RuntimeException('Could not prepare the booking action.');
        return $this->intent($userId,$tripId,$intentId)??[];
    }

    /** Lock the current provider-handoff facts for a second, transaction-level approval. */
    public function prepareHandoff(int $userId,int $tripId,int $intentId): array
    {
        $raw=$this->rawIntent($userId,$tripId,$intentId,true);
        if((string)$raw['adapter_mode']!=='provider_handoff')throw new DomainException('This action is not a provider checkout handoff.');
        $booking=$this->booking($userId,$tripId,(int)$raw['booking_id']);
        $url=$this->safeUrl((string)($booking['provider_url']??$raw['provider_url']??''));
        $amount=$booking['amount']!==null?(float)$booking['amount']:null;
        $currency=$this->currency((string)($booking['currency']??'USD'));
        $terms=[];
        $terms[]='Vacation Brain will open the provider’s checkout or reservation page; the provider remains the merchant of record.';
        $terms[]='Final price, inventory, cancellation terms, traveller details, and payment must be confirmed on the provider site.';
        if(!empty($booking['cancellation_deadline']))$terms[]='Recorded cancellation deadline: '.$this->displayDate((string)$booking['cancellation_deadline']).'.';
        if($url===null)$terms[]='No verified provider checkout URL is saved yet. Refresh the live provider result or add the provider URL before attempting the handoff.';
        $quote=[
            'booking_id'=>(int)$booking['id'],
            'booking_type'=>(string)$booking['booking_type'],
            'title'=>(string)$booking['title'],
            'provider'=>(string)($booking['provider_name']?:$raw['provider_slug']),
            'amount'=>$amount,
            'currency'=>$currency,
            'checkout_available'=>$url!==null,
            'provider_url_host'=>$url!==null?(string)(parse_url($url,PHP_URL_HOST)?:''):null,
            'requires_provider_confirmation'=>true,
        ];
        $quoteId=$this->storeQuote($raw,'handoff',$amount,$currency,null,implode(' ',$terms),'provider_handoff',$quote,(new DateTimeImmutable())->modify('+30 minutes'));
        $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='awaiting_approval',current_quote_id=?,provider_url=?,error_message=NULL,failure_code=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")
            ->execute([$quoteId,$url,$intentId,$userId,$tripId]);
        $this->receipt($raw,'prepared',['mode'=>'provider_handoff','quote_id'=>$quoteId,'checkout_available'=>$url!==null]);
        return $this->intent($userId,$tripId,$intentId)??[];
    }

    /**
     * Prepare a live Booking.com accommodation cancellation quote.
     * Operational order/reservation references are encrypted at rest and are not
     * copied into quote JSON, receipts, notifications, or agent context.
     */
    public function prepareBookingComCancellation(int $userId,int $tripId,int $bookingId,array $input): array
    {
        $this->requireReady();
        $booking=$this->booking($userId,$tripId,$bookingId);
        if((string)$booking['booking_type']!=='lodging')throw new DomainException('Direct Booking.com cancellation is available only for lodging bookings in v1.40.');
        if((string)$booking['status']==='cancelled')throw new DomainException('This lodging booking is already marked cancelled.');
        $order=trim((string)($input['order_reference']??''));
        $reservation=trim((string)($input['reservation_reference']??''));
        if($order===''&&$reservation==='')throw new InvalidArgumentException('Booking.com order or reservation reference is required to verify cancellation terms.');
        if(strlen($order)>180||strlen($reservation)>180)throw new InvalidArgumentException('Provider reference is too long.');
        $reason=$this->clip((string)($input['reason']??'Change in travel plans'),240);
        if($reason==='')$reason='Change in travel plans';
        $state=['order'=>$order,'reservation'=>$reservation,'reason'=>$reason];
        $stateHash=hash('sha256',strtolower($order).'|'.strtolower($reservation));
        $key=hash('sha256','booking-com-cancel|'.$userId.'|'.$tripId.'|'.$bookingId.'|'.$stateHash);
        $encrypted=$this->encrypt(json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->pdo->prepare("INSERT INTO trip_booking_action_intents
            (user_id,dream_trip_id,booking_id,action_type,provider_slug,adapter_mode,status,idempotency_key,provider_state_encrypted,provider_state_hash)
            VALUES (?,?,?,'cancel','booking_com','booking_com_cancel','draft',?,?,?)
            ON DUPLICATE KEY UPDATE provider_state_encrypted=VALUES(provider_state_encrypted),provider_state_hash=VALUES(provider_state_hash),status=IF(status IN ('completed','executing','verification_pending'),status,'draft'),error_message=NULL,failure_code=NULL,updated_at=NOW()")
            ->execute([$userId,$tripId,$bookingId,$key,$encrypted,$stateHash]);
        $stmt=$this->pdo->prepare('SELECT id FROM trip_booking_action_intents WHERE idempotency_key=? LIMIT 1');$stmt->execute([$key]);$intentId=(int)$stmt->fetchColumn();
        $raw=$this->rawIntent($userId,$tripId,$intentId,true);
        if(in_array((string)$raw['status'],['completed','executing','verification_pending'],true))return $this->intent($userId,$tripId,$intentId)??[];
        $details=$this->bookingComDetails($state,$userId,$tripId);
        $normalized=$this->normalizeCancellationQuote($details,$booking);
        if(!$normalized['cancellable'])throw new DomainException($normalized['message']);
        $expires=(new DateTimeImmutable())->modify('+15 minutes');
        $quoteId=$this->storeQuote($raw,'cancellation',$normalized['amount'],$normalized['currency'],$normalized['fee'],$normalized['terms'],'live',$normalized['public'],$expires);
        $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='awaiting_approval',current_quote_id=?,error_message=NULL,failure_code=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")
            ->execute([$quoteId,$intentId,$userId,$tripId]);
        $this->receipt($raw,'prepared',['mode'=>'booking_com_cancel','quote_id'=>$quoteId,'fee'=>$normalized['fee'],'currency'=>$normalized['currency'],'status'=>$normalized['public']['provider_status']]);
        return $this->intent($userId,$tripId,$intentId)??[];
    }

    /** Explicit transaction approval. No provider mutation happens here. */
    public function approve(int $userId,int $tripId,int $intentId): array
    {
        $this->requireReady();
        $this->pdo->beginTransaction();
        try{
            $raw=$this->rawIntent($userId,$tripId,$intentId,true,true);
            if((string)$raw['status']!=='awaiting_approval')throw new DomainException('This provider action is not waiting for approval.');
            $quote=$this->currentQuoteRaw($raw);
            if(!$quote)throw new RuntimeException('The provider quote is unavailable.');
            if(!empty($quote['expires_at'])&&strtotime((string)$quote['expires_at'])<=time())throw new DomainException('This provider quote expired. Refresh the quote before approving.');
            $stmt=$this->pdo->prepare("UPDATE trip_booking_action_intents SET status='approved',approved_at=NOW(),error_message=NULL,failure_code=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=? AND status='awaiting_approval'");
            $stmt->execute([$intentId,$userId,$tripId]);
            if($stmt->rowCount()!==1)throw new DomainException('The provider action changed before approval could be recorded.');
            $this->receipt($raw,'approved',['quote_id'=>(int)$quote['id'],'quote_digest'=>(string)$quote['quote_digest'],'action_type'=>(string)$raw['action_type']]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->intent($userId,$tripId,$intentId)??[];
    }

    /** Execute a direct provider action. Provider handoffs are opened separately. */
    public function execute(int $userId,int $tripId,int $intentId): array
    {
        $this->requireReady();
        $raw=$this->rawIntent($userId,$tripId,$intentId,true);
        if((string)$raw['status']!=='approved')throw new DomainException('Approve the current provider quote before execution.');
        if((string)$raw['adapter_mode']==='provider_handoff')throw new DomainException('This action uses provider-hosted checkout. Open the provider handoff instead of executing it on Vacation Brain.');
        if((string)$raw['adapter_mode']!=='booking_com_cancel')throw new DomainException('Unsupported provider action adapter.');
        return $this->executeBookingComCancellation($raw);
    }

    /** Mark a provider-hosted handoff opened and return the external URL. */
    public function openHandoff(int $userId,int $tripId,int $intentId): string
    {
        $this->requireReady();
        $raw=$this->rawIntent($userId,$tripId,$intentId,true);
        if((string)$raw['adapter_mode']!=='provider_handoff')throw new DomainException('This action is not a provider checkout handoff.');
        if(!in_array((string)$raw['status'],['approved','handoff_pending'],true))throw new DomainException('Approve the provider handoff before opening checkout.');
        $url=$this->safeUrl((string)($raw['provider_url']??''));
        if($url===null)throw new DomainException('No verified provider checkout URL is saved. Refresh the live result or add a provider URL first.');
        if((string)$raw['status']==='approved'){
            $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='handoff_pending',handoff_opened_at=NOW(),execution_started_at=COALESCE(execution_started_at,NOW()),updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=? AND status='approved'")->execute([$intentId,$userId,$tripId]);
            $this->receipt($raw,'handoff_opened',['provider_url_host'=>(string)(parse_url($url,PHP_URL_HOST)?:''),'action_type'=>(string)$raw['action_type']]);
        }
        return $url;
    }

    /** User confirms what happened on the provider-hosted checkout page. */
    public function confirmHandoff(int $userId,int $tripId,int $intentId,string $outcome): array
    {
        $this->requireReady();
        $raw=$this->rawIntent($userId,$tripId,$intentId,true);
        if((string)$raw['adapter_mode']!=='provider_handoff'||(string)$raw['status']!=='handoff_pending')throw new DomainException('This provider handoff is not waiting for confirmation.');
        $outcome=strtolower(trim($outcome));
        if(!in_array($outcome,['completed','not_completed'],true))throw new InvalidArgumentException('Choose whether the provider checkout was completed.');
        if($outcome==='not_completed'){
            $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='failed',failure_code='handoff_not_completed',error_message='Provider checkout was not completed.',failed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$intentId,$userId,$tripId]);
            $this->receipt($raw,'provider_failure',['mode'=>'provider_handoff','reason'=>'user_reported_not_completed']);
            return $this->intent($userId,$tripId,$intentId)??[];
        }
        $bookingId=(int)($raw['booking_id']??0);
        if($bookingId>0&&(new TripBookingService($this->pdo))->ready()){
            // A user-confirmed external checkout becomes "booked", not "confirmed";
            // confirmed is reserved for later provider/manual verification.
            (new TripBookingService($this->pdo))->updateBookingStatus($userId,$tripId,$bookingId,'booked');
        }
        $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='completed',executed_at=NOW(),verified_at=NOW(),completed_at=NOW(),error_message=NULL,failure_code=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$intentId,$userId,$tripId]);
        $this->receipt($raw,'handoff_confirmed',['mode'=>'provider_handoff','result'=>'user_confirmed_completed']);
        $this->receipt($raw,'completed',['verification_source'=>'user','booking_status'=>'booked']);
        return $this->intent($userId,$tripId,$intentId)??[];
    }

    public function cancelIntent(int $userId,int $tripId,int $intentId): array
    {
        $raw=$this->rawIntent($userId,$tripId,$intentId,true);
        if(!in_array((string)$raw['status'],['draft','prepared','awaiting_approval','approved','handoff_pending','failed'],true))throw new DomainException('This provider action cannot be cancelled in its current state.');
        $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$intentId,$userId,$tripId]);
        $this->receipt($raw,'cancelled',['source'=>'user']);
        return $this->intent($userId,$tripId,$intentId)??[];
    }

    public function receipts(int $userId,int $tripId,int $intentId,int $limit=30): array
    {
        $this->rawIntent($userId,$tripId,$intentId,true);
        $limit=max(1,min(100,$limit));
        $stmt=$this->pdo->prepare("SELECT event_type,provider_slug,provider_request_id,receipt_json,receipt_hash,created_at FROM trip_booking_action_receipts WHERE intent_id=? AND user_id=? AND dream_trip_id=? ORDER BY id DESC LIMIT $limit");
        $stmt->execute([$intentId,$userId,$tripId]);$rows=array_reverse($stmt->fetchAll()?:[]);
        foreach($rows as &$row){$row['receipt']=json_decode((string)($row['receipt_json']??''),true)?:[];unset($row['receipt_json']);}unset($row);
        return $rows;
    }

    private function executeBookingComCancellation(array $raw): array
    {
        $userId=(int)$raw['user_id'];$tripId=(int)$raw['dream_trip_id'];$intentId=(int)$raw['id'];$quote=$this->currentQuoteRaw($raw);
        if(!$quote)throw new RuntimeException('Cancellation quote is unavailable.');
        if(!empty($quote['expires_at'])&&strtotime((string)$quote['expires_at'])<=time()){
            $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='awaiting_approval',approved_at=NULL,error_message='Quote expired before execution.',failure_code='quote_expired',updated_at=NOW() WHERE id=?")->execute([$intentId]);
            throw new DomainException('Cancellation terms expired before execution. Refresh and approve the latest terms.');
        }
        $state=$this->providerState($raw);
        if(!$state)throw new RuntimeException('Encrypted provider state is unavailable.');

        // Re-read current Booking.com details immediately before the irreversible call.
        $details=$this->bookingComDetails($state,$userId,$tripId);
        $booking=$this->booking($userId,$tripId,(int)$raw['booking_id']);
        $fresh=$this->normalizeCancellationQuote($details,$booking);
        if(!$fresh['cancellable']){
            $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='failed',failure_code='not_cancellable',error_message=?,failed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$this->clip($fresh['message'],1000),$intentId]);
            $this->receipt($raw,'provider_failure',['stage'=>'preflight','reason'=>'not_cancellable']);
            throw new DomainException($fresh['message']);
        }
        $approvedPublic=json_decode((string)($quote['quote_json']??''),true)?:[];
        $freshDigest=$this->quoteDigest('cancellation',$fresh['amount'],$fresh['currency'],$fresh['fee'],$fresh['terms'],$fresh['public']);
        if(!hash_equals((string)$quote['quote_digest'],$freshDigest)){
            $newQuoteId=$this->storeQuote($raw,'cancellation',$fresh['amount'],$fresh['currency'],$fresh['fee'],$fresh['terms'],'live',$fresh['public'],(new DateTimeImmutable())->modify('+15 minutes'));
            $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='awaiting_approval',approved_at=NULL,current_quote_id=?,failure_code='terms_changed',error_message='Provider cancellation terms changed and require fresh approval.',updated_at=NOW() WHERE id=?")->execute([$newQuoteId,$intentId]);
            $this->receipt($raw,'prepared',['mode'=>'booking_com_cancel','quote_id'=>$newQuoteId,'reason'=>'terms_changed_before_execution']);
            throw new DomainException('Booking.com cancellation terms changed. Review and approve the refreshed quote before cancelling.');
        }

        $lock=$this->pdo->prepare("UPDATE trip_booking_action_intents SET status='executing',execution_started_at=NOW(),error_message=NULL,failure_code=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=? AND status='approved'");
        $lock->execute([$intentId,$userId,$tripId]);
        if($lock->rowCount()!==1)throw new DomainException('This cancellation is already being executed or changed.');
        $this->receipt($raw,'provider_request',['stage'=>'cancel','quote_digest'=>(string)$quote['quote_digest']]);

        $payload=['accommodation'=>['reason'=>$this->clip((string)($state['reason']??'Change in travel plans'),240)]];
        if(!empty($state['order']))$payload['order']=(string)$state['order'];
        if(!empty($state['reservation']))$payload['accommodation']['reservation']=(string)$state['reservation'];
        try{
            [$status,$body,$requestId]=$this->bookingComRequest('/orders/cancel',$payload,$userId,$tripId);
            $json=json_decode($body,true);if(!is_array($json))$json=[];
            $providerStatus=strtolower((string)($json['data']['status']??''));
            if($status>=200&&$status<300&&in_array($providerStatus,['successful','success','cancelled'],true)){
                if((new TripBookingService($this->pdo))->ready())(new TripBookingService($this->pdo))->updateBookingStatus($userId,$tripId,(int)$raw['booking_id'],'cancelled');
                $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='completed',provider_reference=?,executed_at=NOW(),verified_at=NOW(),completed_at=NOW(),error_message=NULL,failure_code=NULL,updated_at=NOW() WHERE id=?")->execute([$requestId?:null,$intentId]);
                $this->receipt($raw,'provider_success',['stage'=>'cancel','provider_status'=>$providerStatus?:'successful'], $requestId?:null);
                $this->receipt($raw,'completed',['verification_source'=>'provider_response','booking_status'=>'cancelled'],$requestId?:null);
                return $this->intent($userId,$tripId,$intentId)??[];
            }
            $message=$this->bookingComError($body)?:('Booking.com returned HTTP '.$status.'.');
            $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='failed',failure_code='provider_rejected',error_message=?,failed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$this->clip($message,1000),$intentId]);
            $this->receipt($raw,'provider_failure',['stage'=>'cancel','http_status'=>$status,'reason'=>$this->clip($message,300)],$requestId?:null);
            throw new RuntimeException($message);
        }catch(Throwable $e){
            $current=$this->rawIntent($userId,$tripId,$intentId,true);
            if((string)$current['status']==='executing'){
                // Network/transport ambiguity must never be blindly retried for a destructive action.
                $this->pdo->prepare("UPDATE trip_booking_action_intents SET status='verification_pending',failure_code='execution_uncertain',error_message=?,updated_at=NOW() WHERE id=?")->execute([$this->clip($e->getMessage(),1000),$intentId]);
                $this->receipt($raw,'provider_failure',['stage'=>'cancel','reason'=>'execution_uncertain']);
            }
            throw $e;
        }
    }

    private function bookingComDetails(array $state,int $userId,int $tripId): array
    {
        $payload=['extras'=>['policies','extra_charges']];
        if(!empty($state['order']))$payload['orders']=[(string)$state['order']];
        elseif(!empty($state['reservation']))$payload['reservations']=[(string)$state['reservation']];
        [$status,$body]=$this->bookingComRequest('/orders/details/accommodations',$payload,$userId,$tripId);
        if($status<200||$status>=300)throw new RuntimeException($this->bookingComError($body)?:('Booking.com details returned HTTP '.$status.'.'));
        $json=json_decode($body,true);if(!is_array($json))throw new RuntimeException('Booking.com returned an unreadable cancellation-details response.');
        $data=$json['data']??[];if(!is_array($data)||!isset($data[0])||!is_array($data[0]))throw new RuntimeException('Booking.com could not find the requested accommodation order.');
        return $data[0];
    }

    private function normalizeCancellationQuote(array $details,array $booking): array
    {
        $status=strtolower((string)($details['status']??''));
        $cancel=$isCancelled=in_array($status,['cancelled','cancelled_by_guest'],true);
        if($isCancelled)return ['cancellable'=>false,'message'=>'Booking.com reports that this accommodation reservation is already cancelled.'];
        if($status!==''&&$status!=='booked')return ['cancellable'=>false,'message'=>'Booking.com currently reports this reservation as '.str_replace('_',' ',$status).', so Vacation Brain will not submit a cancellation.'];
        $cancellation=is_array($details['cancellation_details']??null)?$details['cancellation_details']:[];
        $feeData=is_array($cancellation['fee']??null)?$cancellation['fee']:[];
        $fee=$this->firstMoney([$feeData['booker_currency']??null,$feeData['accommodation_currency']??null,$cancellation['fee_amount']??null]);
        $amount=$this->firstMoney([$details['price']['total']['booker_currency']??null,$details['price']['total']??null,$booking['amount']??null]);
        $currency=$this->currency((string)($details['currency']['booker']??$details['currency']??$booking['currency']??'USD'));
        $deadline=(string)($cancellation['at']??$booking['cancellation_deadline']??'');
        $terms='Booking.com reports this reservation as '.($status!==''?$status:'booked').'. ';
        $terms.=$fee!==null?('Current cancellation fee: '.$currency.' '.number_format($fee,2).'. '):'No cancellation fee amount was returned; verify the provider policy shown here before approval. ';
        if($deadline!=='')$terms.='Cancellation policy timestamp: '.$this->displayDate($deadline).'. ';
        $terms.='Approval authorizes Vacation Brain to submit one cancellation request. If the network result is uncertain, Vacation Brain will stop for verification rather than retry blindly.';
        $public=['provider_status'=>$status!==''?$status:'booked','fee'=>$fee,'currency'=>$currency,'cancellation_at'=>$deadline?:null,'booking_title'=>(string)$booking['title'],'booking_id'=>(int)$booking['id'],'direct_action'=>'cancel_accommodation'];
        return ['cancellable'=>true,'message'=>'','amount'=>$amount,'currency'=>$currency,'fee'=>$fee,'terms'=>$terms,'public'=>$public];
    }

    private function bookingComRequest(string $path,array $payload,int $userId,int $tripId): array
    {
        $settings=new TravelProviderSettingsService($this->pdo);$token=$settings->effectiveKey('booking_com');$affiliate=$settings->setting('booking_com','affiliate_id');
        if($token===''||$affiliate==='')throw new RuntimeException('Booking.com direct actions require an enabled Demand API token and Affiliate ID in Admin → Travel Providers.');
        $environment=$settings->setting('booking_com','environment','production');$base=$environment==='sandbox'?'https://demandapi-sandbox.booking.com/3.2':'https://demandapi.booking.com/3.2';
        $url=$base.$path;$headers=['Authorization: Bearer '.$token,'X-Affiliate-Id: '.$affiliate,'Accept: application/json','Content-Type: application/json'];$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$started=microtime(true);$status=0;$body='';$transport='';
        if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'VacationBrain/1.40']);$out=curl_exec($ch);$transport=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$body=is_string($out)?$out:'';}
        else{$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers)."\r\nUser-Agent: VacationBrain/1.40",'content'=>$json,'timeout'=>25,'ignore_errors'=>true]]);$out=@file_get_contents($url,false,$ctx);$body=is_string($out)?$out:'';$transport=$out===false?'HTTP request failed.':'';foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)){$status=(int)$m[1];break;}}
        $latency=(int)round((microtime(true)-$started)*1000);$ok=$status>=200&&$status<300;$error=$ok?null:($this->bookingComError($body)?:$transport?:'Provider request failed.');$settings->recordUsage('booking_com','booking_action',$userId,$tripId,$ok,$status,$latency,$error);
        if($status===0&&$transport!=='')throw new RuntimeException('Booking.com request could not be confirmed: '.$this->clip($transport,300));
        $decoded=json_decode($body,true);$requestId=is_array($decoded)?trim((string)($decoded['request_id']??'')):'';
        return [$status,$body,$requestId];
    }

    private function storeQuote(array $intent,string $type,?float $amount,string $currency,?float $fee,string $terms,string $sourceState,array $public,DateTimeImmutable $expires): int
    {
        $digest=$this->quoteDigest($type,$amount,$currency,$fee,$terms,$public);
        $find=$this->pdo->prepare('SELECT id FROM trip_booking_action_quotes WHERE intent_id=? AND quote_digest=? LIMIT 1');$find->execute([(int)$intent['id'],$digest]);$existing=(int)($find->fetchColumn()?:0);if($existing>0)return $existing;
        $v=$this->pdo->prepare('SELECT COALESCE(MAX(quote_version),0)+1 FROM trip_booking_action_quotes WHERE intent_id=?');$v->execute([(int)$intent['id']]);$version=(int)$v->fetchColumn();
        $stmt=$this->pdo->prepare('INSERT INTO trip_booking_action_quotes (intent_id,quote_version,provider_slug,quote_type,amount,currency,fee_amount,terms_summary,source_state,quote_json,quote_digest,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([(int)$intent['id'],$version,(string)$intent['provider_slug'],$type,$amount,$currency,$fee,$this->clip($terms,1500),$sourceState,json_encode($public,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),$digest,$expires->format('Y-m-d H:i:s')]);
        return (int)$this->pdo->lastInsertId();
    }

    private function quoteDigest(string $type,?float $amount,string $currency,?float $fee,string $terms,array $public): string
    {
        ksort($public);return hash('sha256',json_encode([$type,$amount,$currency,$fee,$terms,$public],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function receipt(array $intent,string $type,array $safe,?string $requestId=null): void
    {
        if(!$this->ready())return;
        // Never pass provider operational references into $safe. Only non-sensitive,
        // user-visible execution facts belong in immutable receipt JSON.
        $json=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if($json===false)$json='{}';
        $hash=hash('sha256',(int)$intent['id'].'|'.$type.'|'.$json.'|'.microtime(true).'|'.bin2hex(random_bytes(6)));
        $stmt=$this->pdo->prepare('INSERT INTO trip_booking_action_receipts (intent_id,user_id,dream_trip_id,event_type,provider_slug,provider_request_id,receipt_json,receipt_hash) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([(int)$intent['id'],(int)$intent['user_id'],(int)$intent['dream_trip_id'],$type,(string)$intent['provider_slug'],$requestId!==null?$this->clip($requestId,180):null,$json,$hash]);
    }

    private function rawIntent(int $userId,int $tripId,int $intentId,bool $required=false,bool $forUpdate=false): ?array
    {
        if(!$this->ready()){if($required)$this->requireReady();return null;}$sql='SELECT * FROM trip_booking_action_intents WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');$stmt=$this->pdo->prepare($sql);$stmt->execute([$intentId,$userId,$tripId]);$row=$stmt->fetch()?:null;if(!$row&&$required)throw new OutOfBoundsException('Booking action not found.');return $row;
    }

    private function currentQuoteRaw(array $intent): ?array
    {
        $id=(int)($intent['current_quote_id']??0);if($id<1)return null;$stmt=$this->pdo->prepare('SELECT * FROM trip_booking_action_quotes WHERE id=? AND intent_id=? LIMIT 1');$stmt->execute([$id,(int)$intent['id']]);return $stmt->fetch()?:null;
    }

    private function providerState(array $intent): array
    {
        $cipher=(string)($intent['provider_state_encrypted']??'');if($cipher==='')return [];$plain=$this->decrypt($cipher);if($plain==='')return [];$state=json_decode($plain,true);return is_array($state)?$state:[];
    }

    private function publicIntent(array $row): array
    {
        $expires=(string)($row['quote_expires_at']??'');$expired=$expires!==''&&strtotime($expires)<=time();
        return [
            'id'=>(int)$row['id'],'trip_id'=>(int)$row['dream_trip_id'],'booking_id'=>(int)($row['booking_id']??0)?:null,'action_id'=>(int)($row['action_id']??0)?:null,'action_execution_id'=>(int)($row['action_execution_id']??0)?:null,
            'action_type'=>(string)$row['action_type'],'provider_slug'=>(string)$row['provider_slug'],'adapter_mode'=>(string)$row['adapter_mode'],'status'=>(string)$row['status'],'provider_url'=>$this->safeUrl((string)($row['provider_url']??'')),'provider_reference'=>(string)($row['provider_reference']??''),'error'=>(string)($row['error_message']??''),'failure_code'=>(string)($row['failure_code']??''),
            'quote'=>['amount'=>$row['quote_amount']!==null?(float)$row['quote_amount']:null,'currency'=>(string)($row['quote_currency']??''),'fee'=>$row['quote_fee']!==null?(float)$row['quote_fee']:null,'terms'=>(string)($row['terms_summary']??''),'source_state'=>(string)($row['quote_source_state']??''),'expires_at'=>$expires?:null,'expired'=>$expired,'digest'=>(string)($row['quote_digest']??'')],
            'approved_at'=>$row['approved_at']??null,'execution_started_at'=>$row['execution_started_at']??null,'handoff_opened_at'=>$row['handoff_opened_at']??null,'executed_at'=>$row['executed_at']??null,'verified_at'=>$row['verified_at']??null,'completed_at'=>$row['completed_at']??null,'updated_at'=>(string)$row['updated_at'],
            'review_url'=>app_url('booking-action.php?id='.(int)$row['dream_trip_id'].'&intent='.(int)$row['id']),
        ];
    }

    private function booking(int $userId,int $tripId,int $bookingId): array
    {
        if($bookingId<1)throw new InvalidArgumentException('Booking is required.');$this->assertTrip($userId,$tripId);$stmt=$this->pdo->prepare('SELECT * FROM trip_bookings WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$bookingId,$userId,$tripId]);$row=$stmt->fetch();if(!$row)throw new OutOfBoundsException('Booking not found.');return $row;
    }

    private function assertTrip(int $userId,int $tripId): void
    {
        if($tripId<1||!(new DreamService($this->pdo))->get($userId,$tripId,false))throw new OutOfBoundsException('Trip not found.');
    }

    private function providerSlug(string $providerName,string $url,string $bookingType): string
    {
        $hay=strtolower($providerName.' '.$url);if(str_contains($hay,'booking.com')||str_contains($hay,'booking_com'))return 'booking_com';if(str_contains($hay,'skyscanner')||str_contains($hay,'skyscnr'))return 'skyscanner';if(str_contains($hay,'ticketmaster'))return 'ticketmaster';return match($bookingType){'flight'=>'flight_provider','lodging'=>'lodging_provider','event','activity'=>'event_provider',default=>'external_provider'};
    }

    private function handoffActionType(string $bookingType): string{return match($bookingType){'event','activity'=>'purchase','restaurant'=>'reserve',default=>'book'};}
    private function currency(string $value): string{$value=strtoupper(trim($value));return preg_match('/^[A-Z]{3}$/',$value)?$value:'USD';}
    private function firstMoney(array $values): ?float{foreach($values as $value){if(is_numeric($value))return max(0,(float)$value);}return null;}
    private function safeUrl(string $url): ?string{$url=trim($url);return $url!==''&&preg_match('#^https://#i',$url)?$this->clip($url,1500):null;}
    private function displayDate(string $value): string{$ts=strtotime($value);return $ts?date('M j, Y · g:i A T',$ts):$this->clip($value,80);}
    private function clip(string $value,int $max): string{$value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);}
    private function bookingComError(string $body): string{$json=json_decode($body,true);if(!is_array($json))return '';foreach([['error','message'],['detail','message'],['status','message']] as $p){$v=$json[$p[0]][$p[1]]??null;if(is_string($v)&&trim($v)!=='')return $this->clip($v,500);}if(is_string($json['message']??null))return $this->clip((string)$json['message'],500);return '';}

    private function cryptoKey(): string
    {
        $material=(string)($this->config['app']['internal_key']??'');if($material===''){$db=$this->config['db']??[];$material=implode('|',[(string)($db['host']??''),(string)($db['name']??''),(string)($db['user']??''),(string)($db['pass']??''),(string)($this->config['app']['base_url']??'')]);}return hash('sha256',$material,true);
    }
    private function encrypt(string $plain): string
    {
        if(!function_exists('openssl_encrypt'))throw new RuntimeException('PHP OpenSSL is required for secure provider action state.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Could not encrypt provider action state.');return 'v1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($cipher);
    }
    private function decrypt(string $payload): string
    {
        if(!function_exists('openssl_decrypt')||!str_starts_with($payload,'v1.'))return '';$p=explode('.',$payload,4);if(count($p)!==4)return '';$iv=base64_decode($p[1],true);$tag=base64_decode($p[2],true);$cipher=base64_decode($p[3],true);if($iv===false||$tag===false||$cipher===false)return '';$plain=openssl_decrypt($cipher,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);return $plain===false?'':$plain;
    }

    private function requireReady(): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade to enable Booking & Action Execution.');
    }
}
