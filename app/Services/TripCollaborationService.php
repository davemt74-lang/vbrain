<?php
declare(strict_types=1);

/**
 * Permissioned collaboration for Dream Trips.
 *
 * This service never broadens the canonical owner-only services implicitly. Shared
 * screens use an explicit projection that excludes confirmation codes, booking
 * notes, payment state, provider operational references, private Traveler Memory,
 * and Booking & Action Execution controls.
 */
final class TripCollaborationService
{
    private const ROLES=['co_planner','traveler','viewer'];
    private const VOTES=['love','yes','maybe','no'];
    private const RSVPS=['unknown','going','maybe','not_going'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_collaborators')
            && db_table_exists('trip_collaboration_invites')
            && db_table_exists('trip_collaboration_votes')
            && db_table_exists('trip_collaboration_events');
    }

    public function access(int $userId,int $tripId): ?array
    {
        if($userId<1||$tripId<1)return null;
        $stmt=$this->pdo->prepare('SELECT user_id FROM dream_trips WHERE id=? LIMIT 1');
        $stmt->execute([$tripId]);$ownerId=(int)($stmt->fetchColumn()?:0);
        if($ownerId<1)return null;
        if($ownerId===$userId)return [
            'role'=>'owner','is_owner'=>true,'can_view'=>true,'can_plan'=>true,'can_vote'=>true,
            'can_invite'=>true,'can_manage_members'=>true,'can_view_bookings'=>true,'can_view_budget'=>true,
        ];
        if(!$this->ready())return null;
        $stmt=$this->pdo->prepare("SELECT role FROM trip_collaborators WHERE dream_trip_id=? AND user_id=? AND status='active' LIMIT 1");
        $stmt->execute([$tripId,$userId]);$role=(string)($stmt->fetchColumn()?:'');
        if(!in_array($role,self::ROLES,true))return null;
        return [
            'role'=>$role,'is_owner'=>false,'can_view'=>true,'can_plan'=>$role==='co_planner',
            'can_vote'=>in_array($role,['co_planner','traveler'],true),'can_invite'=>false,'can_manage_members'=>false,
            'can_view_bookings'=>in_array($role,['co_planner','traveler'],true),'can_view_budget'=>$role==='co_planner',
        ];
    }

    public function requireAccess(int $userId,int $tripId,string $capability='view'): array
    {
        $access=$this->access($userId,$tripId);
        $key=match($capability){'plan'=>'can_plan','vote'=>'can_vote','invite'=>'can_invite','members'=>'can_manage_members','bookings'=>'can_view_bookings','budget'=>'can_view_budget',default=>'can_view'};
        if(!$access||empty($access[$key]))throw new OutOfBoundsException('Shared trip not found or permission denied.');
        return $access;
    }

    public function sharedWithMe(int $userId): array
    {
        if(!$this->ready())return [];
        $stmt=$this->pdo->prepare("SELECT dt.id,dt.name,dt.status,dt.start_date,dt.end_date,dt.travelers,dt.metadata_json,tc.role,tc.rsvp,tc.updated_at collaboration_updated_at,u.display_name owner_name,u.username owner_username
            FROM trip_collaborators tc
            JOIN dream_trips dt ON dt.id=tc.dream_trip_id AND dt.status<>'abandoned'
            JOIN users u ON u.id=dt.user_id
            WHERE tc.user_id=? AND tc.status='active'
            ORDER BY tc.updated_at DESC,dt.updated_at DESC,dt.id DESC");
        $stmt->execute([$userId]);$rows=$stmt->fetchAll()?:[];
        foreach($rows as &$row){$meta=json_decode((string)($row['metadata_json']??''),true)?:[];$row['destination_name']=(string)($meta['destination_name']??'');unset($row['metadata_json']);}unset($row);
        return $rows;
    }

    public function members(int $userId,int $tripId): array
    {
        $this->requireAccess($userId,$tripId,'view');
        $owner=$this->owner($tripId);
        $rows=[[
            'user_id'=>(int)$owner['id'],'role'=>'owner','rsvp'=>'going','display_name'=>$this->displayName($owner),
            'username'=>(string)($owner['username']??''),'avatar_url'=>(string)($owner['avatar_url']??''),'status'=>'active','is_owner'=>true,
        ]];
        if(!$this->ready())return $rows;
        $stmt=$this->pdo->prepare("SELECT tc.user_id,tc.role,tc.rsvp,tc.status,u.display_name,u.username,u.avatar_url,u.email
            FROM trip_collaborators tc JOIN users u ON u.id=tc.user_id
            WHERE tc.dream_trip_id=? AND tc.status='active' ORDER BY FIELD(tc.role,'co_planner','traveler','viewer'),tc.joined_at,tc.id");
        $stmt->execute([$tripId]);
        foreach($stmt->fetchAll()?:[] as $row){$rows[]=[
            'user_id'=>(int)$row['user_id'],'role'=>(string)$row['role'],'rsvp'=>(string)$row['rsvp'],
            'display_name'=>$this->displayName($row),'username'=>(string)($row['username']??''),'avatar_url'=>(string)($row['avatar_url']??''),
            'status'=>(string)$row['status'],'is_owner'=>false,
        ];}
        return $rows;
    }

    /** Owner-only. Returns the raw invitation token exactly once. */
    public function invite(int $ownerUserId,int $tripId,string $email,string $role='traveler'): array
    {
        $this->requireReady();$this->requireAccess($ownerUserId,$tripId,'invite');
        $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
        $role=$this->role($role);
        $owner=$this->owner($tripId);if(strtolower((string)$owner['email'])===$email)throw new InvalidArgumentException('The trip owner is already part of this trip.');
        $existing=$this->pdo->prepare("SELECT tc.id FROM trip_collaborators tc JOIN users u ON u.id=tc.user_id WHERE tc.dream_trip_id=? AND LOWER(u.email)=LOWER(?) AND tc.status='active' LIMIT 1");
        $existing->execute([$tripId,$email]);if($existing->fetchColumn())throw new DomainException('That person is already a collaborator on this trip.');

        // Revoke earlier pending invitations to the same address so only one link works.
        $this->pdo->prepare("UPDATE trip_collaboration_invites SET status='revoked',revoked_at=NOW(),updated_at=NOW() WHERE dream_trip_id=? AND LOWER(invited_email)=LOWER(?) AND status='pending'")->execute([$tripId,$email]);
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$expires=(new DateTimeImmutable())->modify('+7 days');
        $stmt=$this->pdo->prepare("INSERT INTO trip_collaboration_invites (dream_trip_id,invited_by,invited_email,role,token_hash,status,expires_at) VALUES (?,?,?,?,?,'pending',?)");
        $stmt->execute([$tripId,$ownerUserId,$email,$role,$hash,$expires->format('Y-m-d H:i:s')]);$inviteId=(int)$this->pdo->lastInsertId();
        $this->event($tripId,$ownerUserId,'invited',null,null,['role'=>$role,'invite_id'=>$inviteId]);
        return ['id'=>$inviteId,'email'=>$email,'role'=>$role,'expires_at'=>$expires->format('Y-m-d H:i:s'),'token'=>$token,'url'=>app_url('trip-invite.php?token='.rawurlencode($token))];
    }

    public function pendingInvites(int $ownerUserId,int $tripId): array
    {
        $this->requireAccess($ownerUserId,$tripId,'members');if(!$this->ready())return [];
        $this->expireInvites($tripId);
        $stmt=$this->pdo->prepare("SELECT id,invited_email,role,status,expires_at,created_at FROM trip_collaboration_invites WHERE dream_trip_id=? AND status='pending' ORDER BY created_at DESC,id DESC");
        $stmt->execute([$tripId]);return $stmt->fetchAll()?:[];
    }

    public function revokeInvite(int $ownerUserId,int $tripId,int $inviteId): void
    {
        $this->requireReady();$this->requireAccess($ownerUserId,$tripId,'members');
        $stmt=$this->pdo->prepare("UPDATE trip_collaboration_invites SET status='revoked',revoked_at=NOW(),updated_at=NOW() WHERE id=? AND dream_trip_id=? AND status='pending'");
        $stmt->execute([$inviteId,$tripId]);if($stmt->rowCount()===1)$this->event($tripId,$ownerUserId,'invite_revoked',null,null,['invite_id'=>$inviteId]);
    }

    public function invitePreview(string $token): ?array
    {
        if(!$this->ready())return null;$token=trim($token);if(!preg_match('/^[a-f0-9]{64}$/i',$token))return null;$hash=hash('sha256',$token);
        $stmt=$this->pdo->prepare("SELECT i.id,i.dream_trip_id,i.invited_email,i.role,i.status,i.expires_at,dt.name,dt.start_date,dt.end_date,dt.metadata_json,u.display_name owner_name,u.username owner_username
            FROM trip_collaboration_invites i JOIN dream_trips dt ON dt.id=i.dream_trip_id JOIN users u ON u.id=dt.user_id WHERE i.token_hash=? LIMIT 1");
        $stmt->execute([$hash]);$row=$stmt->fetch();if(!$row)return null;
        if((string)$row['status']==='pending'&&strtotime((string)$row['expires_at'])<=time()){$this->pdo->prepare("UPDATE trip_collaboration_invites SET status='expired',updated_at=NOW() WHERE id=? AND status='pending'")->execute([(int)$row['id']]);$row['status']='expired';}
        $meta=json_decode((string)($row['metadata_json']??''),true)?:[];$row['destination_name']=(string)($meta['destination_name']??'');unset($row['metadata_json']);return $row;
    }

    public function acceptInvite(int $userId,string $token): int
    {
        $this->requireReady();$token=trim($token);if(!preg_match('/^[a-f0-9]{64}$/i',$token))throw new InvalidArgumentException('Invitation link is invalid.');
        $hash=hash('sha256',$token);$this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT * FROM trip_collaboration_invites WHERE token_hash=? LIMIT 1 FOR UPDATE");$stmt->execute([$hash]);$invite=$stmt->fetch();if(!$invite)throw new OutOfBoundsException('Invitation not found.');
            if((string)$invite['status']!=='pending')throw new DomainException('This invitation is no longer active.');
            if(strtotime((string)$invite['expires_at'])<=time()){$this->pdo->prepare("UPDATE trip_collaboration_invites SET status='expired',updated_at=NOW() WHERE id=?")->execute([(int)$invite['id']]);throw new DomainException('This invitation has expired. Ask the trip owner for a new link.');}
            $user=$this->user($userId);if(strtolower((string)$user['email'])!==strtolower((string)$invite['invited_email']))throw new DomainException('This invitation was sent to a different account email. Sign in with the invited account.');
            $tripId=(int)$invite['dream_trip_id'];$owner=$this->owner($tripId);if((int)$owner['id']===$userId)throw new DomainException('The trip owner cannot accept a collaborator invitation.');
            $role=$this->role((string)$invite['role']);
            $this->pdo->prepare("INSERT INTO trip_collaborators (dream_trip_id,user_id,role,rsvp,status,added_by,joined_at) VALUES (?,?,?,'unknown','active',?,NOW())
                ON DUPLICATE KEY UPDATE role=VALUES(role),status='active',removed_at=NULL,added_by=VALUES(added_by),joined_at=NOW(),updated_at=NOW()")
                ->execute([$tripId,$userId,$role,(int)$invite['invited_by']]);
            $this->pdo->prepare("UPDATE trip_collaboration_invites SET status='accepted',accepted_by=?,accepted_at=NOW(),updated_at=NOW() WHERE id=? AND status='pending'")->execute([$userId,(int)$invite['id']]);
            $this->event($tripId,$userId,'joined',$userId,null,['role'=>$role]);$this->pdo->commit();return $tripId;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function changeRole(int $ownerUserId,int $tripId,int $memberUserId,string $role): void
    {
        $this->requireReady();$this->requireAccess($ownerUserId,$tripId,'members');$role=$this->role($role);
        $stmt=$this->pdo->prepare("UPDATE trip_collaborators SET role=?,updated_at=NOW() WHERE dream_trip_id=? AND user_id=? AND status='active'");$stmt->execute([$role,$tripId,$memberUserId]);
        if($stmt->rowCount()!==1)throw new OutOfBoundsException('Collaborator not found.');$this->event($tripId,$ownerUserId,'role_changed',$memberUserId,null,['role'=>$role]);
    }

    public function removeMember(int $ownerUserId,int $tripId,int $memberUserId): void
    {
        $this->requireReady();$this->requireAccess($ownerUserId,$tripId,'members');
        $stmt=$this->pdo->prepare("UPDATE trip_collaborators SET status='removed',removed_at=NOW(),updated_at=NOW() WHERE dream_trip_id=? AND user_id=? AND status='active'");$stmt->execute([$tripId,$memberUserId]);
        if($stmt->rowCount()!==1)throw new OutOfBoundsException('Collaborator not found.');$this->event($tripId,$ownerUserId,'member_removed',$memberUserId,null,[]);
    }

    public function leave(int $userId,int $tripId): void
    {
        $access=$this->requireAccess($userId,$tripId,'view');if(!empty($access['is_owner']))throw new DomainException('The trip owner cannot leave their own trip.');
        $stmt=$this->pdo->prepare("UPDATE trip_collaborators SET status='removed',removed_at=NOW(),updated_at=NOW() WHERE dream_trip_id=? AND user_id=? AND status='active'");$stmt->execute([$tripId,$userId]);
        if($stmt->rowCount()===1)$this->event($tripId,$userId,'member_removed',$userId,null,['source'=>'self']);
    }

    public function setRsvp(int $userId,int $tripId,string $rsvp): void
    {
        $access=$this->requireAccess($userId,$tripId,'view');if(!empty($access['is_owner']))throw new DomainException('The trip owner is always listed as going.');
        $rsvp=strtolower(trim($rsvp));if(!in_array($rsvp,self::RSVPS,true))throw new InvalidArgumentException('Unknown RSVP status.');
        $stmt=$this->pdo->prepare("UPDATE trip_collaborators SET rsvp=?,updated_at=NOW() WHERE dream_trip_id=? AND user_id=? AND status='active'");$stmt->execute([$rsvp,$tripId,$userId]);
        if($stmt->rowCount()!==1)throw new OutOfBoundsException('Collaborator not found.');$this->event($tripId,$userId,'rsvp_changed',$userId,null,['rsvp'=>$rsvp]);
    }

    public function setVote(int $userId,int $tripId,int $itemId,string $vote,string $comment=''): void
    {
        $this->requireReady();$this->requireAccess($userId,$tripId,'vote');$vote=strtolower(trim($vote));if(!in_array($vote,self::VOTES,true))throw new InvalidArgumentException('Unknown vote.');
        $item=$this->pdo->prepare('SELECT id FROM dream_trip_items WHERE id=? AND dream_trip_id=? LIMIT 1');$item->execute([$itemId,$tripId]);if(!$item->fetchColumn())throw new OutOfBoundsException('Trip item not found.');
        $comment=$this->clip($comment,280);$this->pdo->prepare("INSERT INTO trip_collaboration_votes (dream_trip_id,dream_trip_item_id,user_id,vote,comment) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE vote=VALUES(vote),comment=VALUES(comment),updated_at=NOW()")
            ->execute([$tripId,$itemId,$userId,$vote,$comment!==''?$comment:null]);$this->event($tripId,$userId,'vote_changed',$userId,$itemId,['vote'=>$vote]);
    }

    public function addItem(int $userId,int $tripId,array $input): int
    {
        $this->requireReady();$access=$this->requireAccess($userId,$tripId,'plan');
        if(!empty($access['is_owner'])){(new DreamService($this->pdo))->addItem($userId,$tripId,$input);$stmt=$this->pdo->prepare('SELECT id FROM dream_trip_items WHERE dream_trip_id=? ORDER BY id DESC LIMIT 1');$stmt->execute([$tripId]);return (int)$stmt->fetchColumn();}
        $title=$this->clip((string)($input['title']??''),255);if($title==='')throw new InvalidArgumentException('Give the trip item a name.');
        $type=strtolower(trim((string)($input['item_type']??'idea')));if(!in_array($type,['idea','hotel','food','activity','flight','experience','merch'],true))$type='idea';
        $notes=$this->clip((string)($input['notes']??''),1000);$price=($input['price']??'')!==''&&$input['price']!==null?max(0,(float)$input['price']):null;
        $scheduled=$this->dateOrNull((string)($input['scheduled_date']??''));$daypart=in_array((string)($input['daypart']??''),['morning','afternoon','evening','anytime'],true)?(string)$input['daypart']:null;
        $sort=$this->pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM dream_trip_items WHERE dream_trip_id=?');$sort->execute([$tripId]);$sortOrder=(int)$sort->fetchColumn();
        $stmt=$this->pdo->prepare('INSERT INTO dream_trip_items (dream_trip_id,item_type,title,price,notes,scheduled_date,daypart,sort_order) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$tripId,$type,$title,$price,$notes!==''?$notes:null,$scheduled,$daypart,$sortOrder]);$itemId=(int)$this->pdo->lastInsertId();
        $this->event($tripId,$userId,'item_added',$userId,$itemId,['item_type'=>$type]);return $itemId;
    }

    public function removeItem(int $userId,int $tripId,int $itemId): void
    {
        $this->requireReady();$this->requireAccess($userId,$tripId,'plan');$stmt=$this->pdo->prepare('DELETE FROM dream_trip_items WHERE id=? AND dream_trip_id=?');$stmt->execute([$itemId,$tripId]);
        if($stmt->rowCount()!==1)throw new OutOfBoundsException('Trip item not found.');$this->event($tripId,$userId,'item_removed',$userId,null,['item_id'=>$itemId]);
    }

    /** Safe projection for owner and collaborators; never returns private operational fields. */
    public function snapshot(int $userId,int $tripId): array
    {
        $access=$this->requireAccess($userId,$tripId,'view');
        $stmt=$this->pdo->prepare('SELECT id,user_id,name,description,status,start_date,end_date,travelers,target_budget,dream_level,booking_readiness,origin_name,origin_iata,destination_iata,currency,metadata_json,updated_at FROM dream_trips WHERE id=? LIMIT 1');
        $stmt->execute([$tripId]);$trip=$stmt->fetch();if(!$trip)throw new OutOfBoundsException('Trip not found.');$meta=json_decode((string)($trip['metadata_json']??''),true)?:[];
        $trip['destination_name']=(string)($meta['destination_name']??'');unset($trip['metadata_json']);
        if(empty($access['can_view_budget']))unset($trip['target_budget']);
        $itemsStmt=$this->pdo->prepare('SELECT id,item_type,title,price,notes,scheduled_date,daypart,sort_order FROM dream_trip_items WHERE dream_trip_id=? ORDER BY scheduled_date IS NULL,scheduled_date,FIELD(daypart,"morning","afternoon","evening","anytime"),sort_order,id');$itemsStmt->execute([$tripId]);$items=$itemsStmt->fetchAll()?:[];
        if(empty($access['can_view_budget']))foreach($items as &$item)unset($item['price']);unset($item);
        $votes=[];if($this->ready()){$v=$this->pdo->prepare("SELECT dream_trip_item_id,vote,COUNT(*) total FROM trip_collaboration_votes WHERE dream_trip_id=? GROUP BY dream_trip_item_id,vote");$v->execute([$tripId]);foreach($v->fetchAll()?:[] as $row)$votes[(int)$row['dream_trip_item_id']][(string)$row['vote']]=(int)$row['total'];}
        foreach($items as &$item)$item['votes']=$votes[(int)$item['id']]??[];unset($item);
        $bookings=[];if(!empty($access['can_view_bookings'])&&db_table_exists('trip_bookings')){
            $b=$this->pdo->prepare("SELECT id,booking_type,title,provider_name,status,starts_at,ends_at,location_name,location_address,terminal,gate,operational_status FROM trip_bookings WHERE dream_trip_id=? AND status<>'cancelled' ORDER BY starts_at IS NULL,starts_at,id");$b->execute([$tripId]);$bookings=$b->fetchAll()?:[];
        }
        return ['access'=>$access,'trip'=>$trip,'members'=>$this->members($userId,$tripId),'items'=>$items,'bookings'=>$bookings,'events'=>$this->recentEvents($userId,$tripId,20)];
    }

    public function recentEvents(int $userId,int $tripId,int $limit=20): array
    {
        $this->requireAccess($userId,$tripId,'view');if(!$this->ready())return [];$limit=max(1,min(50,$limit));
        $stmt=$this->pdo->prepare("SELECT e.event_type,e.created_at,e.detail_json,e.dream_trip_item_id,a.display_name actor_name,a.username actor_username,s.display_name subject_name,s.username subject_username
            FROM trip_collaboration_events e LEFT JOIN users a ON a.id=e.actor_user_id LEFT JOIN users s ON s.id=e.subject_user_id
            WHERE e.dream_trip_id=? ORDER BY e.id DESC LIMIT $limit");$stmt->execute([$tripId]);$rows=$stmt->fetchAll()?:[];
        foreach($rows as &$row){$row['detail']=json_decode((string)($row['detail_json']??''),true)?:[];unset($row['detail_json']);}unset($row);return $rows;
    }

    private function owner(int $tripId): array
    {
        $stmt=$this->pdo->prepare('SELECT u.id,u.email,u.display_name,u.username,u.avatar_url FROM dream_trips dt JOIN users u ON u.id=dt.user_id WHERE dt.id=? LIMIT 1');$stmt->execute([$tripId]);$row=$stmt->fetch();if(!$row)throw new OutOfBoundsException('Trip not found.');return $row;
    }

    private function user(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT id,email,display_name,username,avatar_url FROM users WHERE id=? AND status="active" LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)throw new OutOfBoundsException('User account not found.');return $row;
    }

    private function event(int $tripId,?int $actor,string $type,?int $subject,?int $item,array $detail): void
    {
        if(!$this->ready())return;$json=$detail?json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE):null;
        $this->pdo->prepare('INSERT INTO trip_collaboration_events (dream_trip_id,actor_user_id,event_type,subject_user_id,dream_trip_item_id,detail_json) VALUES (?,?,?,?,?,?)')->execute([$tripId,$actor,$type,$subject,$item,$json]);
    }

    private function expireInvites(int $tripId): void
    {
        $this->pdo->prepare("UPDATE trip_collaboration_invites SET status='expired',updated_at=NOW() WHERE dream_trip_id=? AND status='pending' AND expires_at<=NOW()") ->execute([$tripId]);
    }

    private function role(string $role): string
    {
        $role=strtolower(trim($role));if(!in_array($role,self::ROLES,true))throw new InvalidArgumentException('Unknown collaborator role.');return $role;
    }

    private function displayName(array $row): string
    {
        $name=trim((string)($row['display_name']??''));if($name!=='')return $name;$username=trim((string)($row['username']??''));if($username!=='')return $username;return 'Traveler';
    }

    private function dateOrNull(string $value): ?string
    {
        $value=trim($value);if($value==='')return null;$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $dt&&$dt->format('Y-m-d')===$value?$value:null;
    }

    private function clip(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }

    private function requireReady(): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade to enable Collaborative Trips & Travelers.');
    }
}
