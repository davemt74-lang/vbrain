<?php
declare(strict_types=1);

final class DestinationOwnerService
{
    private AccountTypeService $accounts;

    public function __construct(private PDO $pdo)
    {
        $this->accounts=new AccountTypeService($pdo);
    }

    public function ready(): bool
    {
        return db_column_exists('users','account_type')
            && db_column_exists('destination_catalog','publication_status')
            && db_table_exists('destination_memberships')
            && db_table_exists('destination_trip_types')
            && db_table_exists('destination_claims')
            && db_table_exists('destination_change_log');
    }

    public function tripTypeCatalog(): array
    {
        return [
            'day_trip'=>['label'=>'Day Trip','description'=>'Designed for a local or same-day escape.'],
            'weekend'=>['label'=>'Weekend Getaway','description'=>'A strong fit for roughly two to three days.'],
            'multi_day'=>['label'=>'Multi-Day Excursion','description'=>'Best experienced as a longer stay.'],
        ];
    }

    public function publicationCatalog(): array
    {
        return [
            'draft'=>'Draft',
            'pending_review'=>'Pending Review',
            'published'=>'Published',
            'suspended'=>'Suspended',
        ];
    }

    public function canUseDashboard(int $userId): bool
    {
        if (is_admin() && auth_user_id()===$userId) return true;
        if (!$this->ready()) return false;
        if ($this->accounts->hasFeature($userId,'destination_dashboard')) return true;
        $stmt=$this->pdo->prepare("SELECT 1 FROM destination_memberships WHERE user_id=? AND status='active' LIMIT 1");
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    public function memberships(int $userId): array
    {
        if (!$this->ready()) return [];
        $stmt=$this->pdo->prepare("SELECT dm.*,d.name,d.slug,d.city,d.region,d.country,d.hero_image_url,d.publication_status,d.status,
            (SELECT GROUP_CONCAT(CONCAT(dt.trip_type,':',dt.is_primary) ORDER BY dt.sort_order,dt.id SEPARATOR ',') FROM destination_trip_types dt WHERE dt.destination_catalog_id=d.id) AS trip_types_csv
            FROM destination_memberships dm JOIN destination_catalog d ON d.id=dm.destination_catalog_id
            WHERE dm.user_id=? AND dm.status='active' ORDER BY dm.member_role='owner' DESC,d.name");
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function destination(int $destinationId): ?array
    {
        if (!db_table_exists('destination_catalog')) return null;
        $stmt=$this->pdo->prepare('SELECT * FROM destination_catalog WHERE id=? LIMIT 1');
        $stmt->execute([$destinationId]);
        return $stmt->fetch() ?: null;
    }

    public function roleFor(int $userId,int $destinationId): ?string
    {
        if (is_admin() && auth_user_id()===$userId) return 'admin';
        if (!$this->ready()) return null;
        $stmt=$this->pdo->prepare("SELECT member_role FROM destination_memberships WHERE user_id=? AND destination_catalog_id=? AND status='active' LIMIT 1");
        $stmt->execute([$userId,$destinationId]);
        $role=$stmt->fetchColumn();
        return $role===false ? null : (string)$role;
    }

    public function canManage(int $userId,int $destinationId): bool
    {
        return in_array($this->roleFor($userId,$destinationId),['admin','owner','manager'],true);
    }

    public function canManageTeam(int $userId,int $destinationId): bool
    {
        return in_array($this->roleFor($userId,$destinationId),['admin','owner'],true);
    }

    public function tripTypes(int $destinationId): array
    {
        if (!db_table_exists('destination_trip_types')) return [];
        $stmt=$this->pdo->prepare('SELECT trip_type,is_primary,sort_order FROM destination_trip_types WHERE destination_catalog_id=? ORDER BY sort_order,id');
        $stmt->execute([$destinationId]);
        return $stmt->fetchAll() ?: [];
    }

    public function saveListing(int $actorId,int $destinationId,array $input): array
    {
        $this->assertReady();
        if (!$this->canManage($actorId,$destinationId)) throw new RuntimeException('You do not have permission to edit this destination.');
        $before=$this->destination($destinationId);
        if (!$before) throw new InvalidArgumentException('Destination not found.');
        $name=trim((string)($input['name']??''));
        if ($name==='') throw new InvalidArgumentException('Destination name is required.');
        $email=trim((string)($input['contact_email']??''));
        if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid contact email or leave it blank.');
        foreach (['website_url','booking_url','hero_image_url'] as $urlField) {
            $url=trim((string)($input[$urlField]??''));
            if ($url!=='' && !preg_match('#^(?:https?://|/)#i',$url)) throw new InvalidArgumentException(ucwords(str_replace('_',' ',$urlField)).' must be an http(s) URL or a local path.');
        }
        $latitude=trim((string)($input['latitude']??''));
        $longitude=trim((string)($input['longitude']??''));
        $lat=$latitude===''?null:(float)$latitude;
        $lng=$longitude===''?null:(float)$longitude;
        if ($lat!==null && ($lat < -90 || $lat > 90)) throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        if ($lng!==null && ($lng < -180 || $lng > 180)) throw new InvalidArgumentException('Longitude must be between -180 and 180.');

        $values=[
            $name,
            trim((string)($input['city']??'')),
            trim((string)($input['region']??'')),
            trim((string)($input['country']??'')),
            trim((string)($input['address']??'')),
            $lat,$lng,
            trim((string)($input['short_description']??'')),
            trim((string)($input['description']??'')),
            trim((string)($input['hero_image_url']??'')),
            trim((string)($input['best_for']??'')),
            trim((string)($input['vibe']??'')),
            trim((string)($input['typical_duration']??'')),
            trim((string)($input['price_range']??'')),
            trim((string)($input['best_season']??'')),
            trim((string)($input['website_url']??'')),
            trim((string)($input['booking_url']??'')),
            $email,
            trim((string)($input['contact_phone']??'')),
            trim((string)($input['transportation_notes']??'')),
            trim((string)($input['official_highlights']??'')),
            trim((string)($input['owner_notes']??'')),
            $destinationId,
        ];
        $stmt=$this->pdo->prepare('UPDATE destination_catalog SET name=?,city=?,region=?,country=?,address=?,latitude=?,longitude=?,short_description=?,description=?,hero_image_url=?,best_for=?,vibe=?,typical_duration=?,price_range=?,best_season=?,website_url=?,booking_url=?,contact_email=?,contact_phone=?,transportation_notes=?,official_highlights=?,owner_notes=? WHERE id=?');
        $stmt->execute($values);
        $after=$this->destination($destinationId) ?: [];
        $this->audit($destinationId,$actorId,'listing_updated',$this->auditProjection($before),$this->auditProjection($after),'Official destination listing updated.');
        return $after;
    }

    public function setTripTypes(int $actorId,int $destinationId,array $types,string $primary): void
    {
        $this->assertReady();
        if (!$this->canManage($actorId,$destinationId)) throw new RuntimeException('You do not have permission to change trip types.');
        $catalog=$this->tripTypeCatalog();
        $types=array_values(array_unique(array_filter(array_map('strval',$types),fn($v)=>isset($catalog[$v]))));
        if (!$types) throw new InvalidArgumentException('Choose at least one trip type.');
        if (!in_array($primary,$types,true)) $primary=$types[0];
        $before=$this->tripTypes($destinationId);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM destination_trip_types WHERE destination_catalog_id=?')->execute([$destinationId]);
            $stmt=$this->pdo->prepare('INSERT INTO destination_trip_types (destination_catalog_id,trip_type,is_primary,sort_order) VALUES (?,?,?,?)');
            $sort=['day_trip'=>10,'weekend'=>20,'multi_day'=>30];
            foreach ($types as $type) $stmt->execute([$destinationId,$type,$type===$primary?1:0,$sort[$type]??100]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit($destinationId,$actorId,'trip_types_updated',$before,$this->tripTypes($destinationId),'Destination placement updated.');
    }

    public function submitForReview(int $actorId,int $destinationId): void
    {
        $this->assertReady();
        if (!$this->canManage($actorId,$destinationId)) throw new RuntimeException('You do not have permission to submit this destination.');
        $before=$this->destination($destinationId);
        $this->pdo->prepare("UPDATE destination_catalog SET publication_status='pending_review',status='draft' WHERE id=?")->execute([$destinationId]);
        $after=$this->destination($destinationId);
        $this->audit($destinationId,$actorId,'submitted_for_review',$this->auditProjection($before?:[]),$this->auditProjection($after?:[]),'Owner submitted listing for review.');
    }

    public function setPublicationStatus(int $adminId,int $destinationId,string $status,string $note=''): void
    {
        $this->assertReady();
        if (!is_admin() || auth_user_id()!==$adminId) throw new RuntimeException('Administrator access required.');
        if (!isset($this->publicationCatalog()[$status])) throw new InvalidArgumentException('Invalid publication status.');
        $before=$this->destination($destinationId);
        if (!$before) throw new InvalidArgumentException('Destination not found.');
        $legacy=$status==='published'?'active':'draft';
        if ($status==='published') {
            $this->pdo->prepare('UPDATE destination_catalog SET publication_status=?,status=?,published_at=COALESCE(published_at,NOW()) WHERE id=?')->execute([$status,$legacy,$destinationId]);
        } else {
            $this->pdo->prepare('UPDATE destination_catalog SET publication_status=?,status=? WHERE id=?')->execute([$status,$legacy,$destinationId]);
        }
        $after=$this->destination($destinationId);
        $this->audit($destinationId,$adminId,'publication_status_updated',$this->auditProjection($before),$this->auditProjection($after?:[]),$note!==''?$note:'Publication status changed to '.$status.'.');
    }

    public function requestClaim(int $userId,int $destinationId,array $input): int
    {
        $this->assertReady();
        if (!$this->destination($destinationId)) throw new InvalidArgumentException('Destination not found.');
        if ($this->canManage($userId,$destinationId)) throw new InvalidArgumentException('You already have access to this destination.');
        $check=$this->pdo->prepare("SELECT id FROM destination_claims WHERE destination_catalog_id=? AND user_id=? AND status='pending' LIMIT 1");
        $check->execute([$destinationId,$userId]);
        if ($check->fetchColumn()) throw new InvalidArgumentException('You already have a pending claim for this destination.');
        $proof=trim((string)($input['proof_text']??''));
        if (mb_strlen($proof)<20) throw new InvalidArgumentException('Tell us briefly how you are connected to this destination.');
        $businessUrl=trim((string)($input['business_url']??''));
        if ($businessUrl!=='' && !filter_var($businessUrl,FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Enter a valid business website URL or leave it blank.');
        $stmt=$this->pdo->prepare("INSERT INTO destination_claims (destination_catalog_id,user_id,status,business_name,business_url,proof_text) VALUES (?,?,'pending',?,?,?)");
        $stmt->execute([$destinationId,$userId,trim((string)($input['business_name']??'')),$businessUrl,$proof]);
        $id=(int)$this->pdo->lastInsertId();
        $this->audit($destinationId,$userId,'claim_requested',null,['claim_id'=>$id],'Destination ownership claim submitted.');
        return $id;
    }

    public function claimsForUser(int $userId): array
    {
        if (!db_table_exists('destination_claims')) return [];
        $stmt=$this->pdo->prepare('SELECT c.*,d.name,d.city,d.region,d.country FROM destination_claims c JOIN destination_catalog d ON d.id=c.destination_catalog_id WHERE c.user_id=? ORDER BY c.created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function pendingClaims(): array
    {
        if (!$this->ready()) return [];
        return $this->pdo->query("SELECT c.*,d.name AS destination_name,d.city,d.region,d.country,u.email,u.display_name FROM destination_claims c JOIN destination_catalog d ON d.id=c.destination_catalog_id JOIN users u ON u.id=c.user_id WHERE c.status='pending' ORDER BY c.created_at ASC")->fetchAll() ?: [];
    }

    public function resolveClaim(int $adminId,int $claimId,string $decision,string $note=''): void
    {
        $this->assertReady();
        if (!is_admin() || auth_user_id()!==$adminId) throw new RuntimeException('Administrator access required.');
        if (!in_array($decision,['approved','rejected'],true)) throw new InvalidArgumentException('Invalid claim decision.');
        $stmt=$this->pdo->prepare("SELECT * FROM destination_claims WHERE id=? AND status='pending' LIMIT 1");
        $stmt->execute([$claimId]);
        $claim=$stmt->fetch();
        if (!$claim) throw new InvalidArgumentException('Pending claim not found.');
        $this->pdo->beginTransaction();
        try {
            if ($decision==='approved') {
                $this->assignMembershipInternal($adminId,(int)$claim['destination_catalog_id'],(int)$claim['user_id'],'owner');
                $this->pdo->prepare('UPDATE destination_catalog SET owner_verified=1 WHERE id=?')->execute([(int)$claim['destination_catalog_id']]);
            }
            $this->pdo->prepare('UPDATE destination_claims SET status=?,resolution_note=?,resolved_by=?,resolved_at=NOW() WHERE id=?')->execute([$decision,$note,$adminId,$claimId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit((int)$claim['destination_catalog_id'],$adminId,'claim_'.$decision,['claim_id'=>$claimId],['user_id'=>(int)$claim['user_id'],'decision'=>$decision],$note);
    }

    public function assignMembership(int $adminId,int $destinationId,int $userId,string $role): void
    {
        $this->assertReady();
        if (!is_admin() || auth_user_id()!==$adminId) throw new RuntimeException('Administrator access required.');
        $this->assignMembershipInternal($adminId,$destinationId,$userId,$role);
        $this->audit($destinationId,$adminId,'membership_assigned',null,['user_id'=>$userId,'role'=>$role],'Destination access assigned by administrator.');
    }

    public function addManagerByEmail(int $ownerId,int $destinationId,string $email): void
    {
        $this->assertReady();
        if (!$this->canManageTeam($ownerId,$destinationId)) throw new RuntimeException('Only a destination owner can manage the destination team.');
        $stmt=$this->pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND status='active' LIMIT 1");
        $stmt->execute([trim($email)]);
        $userId=(int)($stmt->fetchColumn() ?: 0);
        if (!$userId) throw new InvalidArgumentException('No active Vacation Brain account matches that email.');
        if ($userId===$ownerId) throw new InvalidArgumentException('You already own this destination.');
        $this->assignMembershipInternal($ownerId,$destinationId,$userId,'manager');
        $this->audit($destinationId,$ownerId,'manager_added',null,['user_id'=>$userId],'Destination manager added.');
    }

    public function removeManager(int $actorId,int $destinationId,int $membershipId): void
    {
        $this->assertReady();
        if (!$this->canManageTeam($actorId,$destinationId)) throw new RuntimeException('Only a destination owner can manage the destination team.');
        $stmt=$this->pdo->prepare("SELECT user_id,member_role FROM destination_memberships WHERE id=? AND destination_catalog_id=? AND status='active' LIMIT 1");
        $stmt->execute([$membershipId,$destinationId]);
        $row=$stmt->fetch();
        if (!$row || $row['member_role']!=='manager') throw new InvalidArgumentException('Active destination manager not found.');
        $this->pdo->prepare("UPDATE destination_memberships SET status='revoked',revoked_at=NOW() WHERE id=?")->execute([$membershipId]);
        $this->audit($destinationId,$actorId,'manager_removed',['user_id'=>(int)$row['user_id']],null,'Destination manager access removed.');
    }

    public function team(int $destinationId): array
    {
        if (!$this->ready()) return [];
        $stmt=$this->pdo->prepare("SELECT dm.id,dm.user_id,dm.member_role,dm.status,dm.assigned_at,u.display_name,u.email,u.account_type FROM destination_memberships dm JOIN users u ON u.id=dm.user_id WHERE dm.destination_catalog_id=? AND dm.status='active' ORDER BY dm.member_role='owner' DESC,u.display_name,u.email");
        $stmt->execute([$destinationId]);
        return $stmt->fetchAll() ?: [];
    }

    public function publicTripRows(string $tripType,int $limit=8): array
    {
        if (!$this->ready() || !isset($this->tripTypeCatalog()[$tripType])) return [];
        $sampleClause=(db_column_exists('destination_catalog','is_sample')&&!sample_data_enabled())?' AND d.is_sample=0':'';
        $stmt=$this->pdo->prepare("SELECT d.*,dt.is_primary FROM destination_catalog d JOIN destination_trip_types dt ON dt.destination_catalog_id=d.id AND dt.trip_type=? WHERE d.status='active' AND d.publication_status='published'{$sampleClause} ORDER BY dt.is_primary DESC,d.featured DESC,d.sort_order,d.name LIMIT ".max(1,min(50,$limit)));
        $stmt->execute([$tripType]);
        return $stmt->fetchAll() ?: [];
    }

    public function stats(int $destinationId): array
    {
        $stats=['views'=>0,'outbound_clicks'=>0,'plans'=>0,'selected'=>0,'watched'=>0,'research_reports'=>0];
        if (db_table_exists('destination_engagement_events')) {
            $stmt=$this->pdo->prepare("SELECT event_type,COUNT(*) total FROM destination_engagement_events WHERE destination_catalog_id=? GROUP BY event_type");
            $stmt->execute([$destinationId]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                if ($row['event_type']==='view') $stats['views']=(int)$row['total'];
                elseif (in_array($row['event_type'],['website_click','booking_click'],true)) $stats['outbound_clicks']+=(int)$row['total'];
                elseif ($row['event_type']==='plan') $stats['plans']=(int)$row['total'];
            }
        }
        if (db_table_exists('dashboard_destination_context')) {
            $stmt=$this->pdo->prepare('SELECT COALESCE(SUM(is_selected=1),0) selected_count,COALESCE(SUM(is_watching=1),0) watched_count FROM dashboard_destination_context WHERE destination_catalog_id=?');
            $stmt->execute([$destinationId]);
            $row=$stmt->fetch() ?: [];
            $stats['selected']=(int)($row['selected_count']??0);
            $stats['watched']=(int)($row['watched_count']??0);
        }
        if (db_table_exists('destination_reports')) {
            $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM destination_reports WHERE destination_catalog_id=?');
            $stmt->execute([$destinationId]);
            $stats['research_reports']=(int)$stmt->fetchColumn();
        }
        return $stats;
    }

    public function completeness(int $destinationId): int
    {
        $d=$this->destination($destinationId);
        if (!$d) return 0;
        $fields=['name','city','country','short_description','description','hero_image_url','best_for','vibe','typical_duration','website_url','best_season','transportation_notes'];
        $done=0;
        foreach ($fields as $field) if (trim((string)($d[$field]??''))!=='') $done++;
        $total=count($fields)+1;
        if ($this->tripTypes($destinationId)) $done++;
        return (int)round(($done/$total)*100);
    }

    public function recentChanges(int $destinationId,int $limit=20): array
    {
        if (!db_table_exists('destination_change_log')) return [];
        $stmt=$this->pdo->prepare('SELECT l.*,u.display_name,u.email FROM destination_change_log l LEFT JOIN users u ON u.id=l.changed_by WHERE l.destination_catalog_id=? ORDER BY l.created_at DESC,l.id DESC LIMIT '.max(1,min(100,$limit)));
        $stmt->execute([$destinationId]);
        return $stmt->fetchAll() ?: [];
    }

    public function logEngagement(int $destinationId,?int $userId,string $eventType,array $metadata=[]): void
    {
        if (!db_table_exists('destination_engagement_events') || $destinationId<1) return;
        $allowed=['view','website_click','booking_click','plan','photo'];
        if (!in_array($eventType,$allowed,true)) return;
        $stmt=$this->pdo->prepare('INSERT INTO destination_engagement_events (destination_catalog_id,user_id,event_type,metadata_json) VALUES (?,?,?,?)');
        $stmt->execute([$destinationId,$userId,$eventType,$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
    }

    private function assignMembershipInternal(int $actorId,int $destinationId,int $userId,string $role): void
    {
        if (!in_array($role,['owner','manager'],true)) throw new InvalidArgumentException('Invalid destination member role.');
        if (!$this->destination($destinationId)) throw new InvalidArgumentException('Destination not found.');
        $check=$this->pdo->prepare("SELECT id FROM users WHERE id=? AND status='active' LIMIT 1");
        $check->execute([$userId]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('User not found.');
        if ($role==='owner') {
            $this->pdo->prepare("UPDATE destination_memberships SET status='revoked',revoked_at=NOW() WHERE destination_catalog_id=? AND member_role='owner' AND user_id<>? AND status='active'")->execute([$destinationId,$userId]);
        }
        $stmt=$this->pdo->prepare("INSERT INTO destination_memberships (destination_catalog_id,user_id,member_role,status,assigned_by,assigned_at,revoked_at) VALUES (?,?,?,'active',?,NOW(),NULL) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role),status='active',assigned_by=VALUES(assigned_by),assigned_at=NOW(),revoked_at=NULL");
        $stmt->execute([$destinationId,$userId,$role,$actorId]);
        $desired=$role==='owner'?'destination_owner':'destination_manager';
        $current=$this->accounts->type($userId);
        if ($role==='owner' || $current==='traveler') $this->accounts->setType($userId,$desired);
    }

    private function audit(int $destinationId,?int $actorId,string $action,?array $before,?array $after,string $note=''): void
    {
        if (!db_table_exists('destination_change_log')) return;
        $stmt=$this->pdo->prepare('INSERT INTO destination_change_log (destination_catalog_id,changed_by,action_name,before_json,after_json,note) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $destinationId,$actorId,$action,
            $before===null?null:json_encode($before,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            $after===null?null:json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            $note!==''?$note:null,
        ]);
    }

    private function auditProjection(array $d): array
    {
        $keys=['name','city','region','country','address','latitude','longitude','short_description','description','hero_image_url','best_for','vibe','typical_duration','price_range','best_season','website_url','booking_url','contact_email','contact_phone','transportation_notes','official_highlights','publication_status','status'];
        $out=[];
        foreach ($keys as $key) $out[$key]=$d[$key]??null;
        return $out;
    }

    private function assertReady(): void
    {
        if (!$this->ready()) throw new RuntimeException('Run System Upgrade before using destination owner accounts.');
    }
}
