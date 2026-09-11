<?php
declare(strict_types=1);

final class TripCommandCenterService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('dream_trips');
    }

    public function snapshot(int $userId): array
    {
        if($userId<1)throw new InvalidArgumentException('User is required.');
        if(!$this->ready())return $this->emptySnapshot();

        $trips=$this->upcomingTrips($userId);
        $agents=$this->activeAgents($userId);
        $alerts=$this->watchAlerts($userId);
        $actions=$this->activeActions($userId);
        $executions=$this->executions($userId);
        $handoffs=$this->bookingHandoffs($userId);
        $risks=$this->risks($trips,$actions,$alerts);
        $attention=$this->attention($actions,$executions,$risks);

        $approvals=0;$executionActive=0;$executionFailed=0;
        foreach($executions as $execution){
            $status=(string)($execution['status']??'');
            if($status==='awaiting_approval')$approvals++;
            if(in_array($status,['queued','agent_working'],true))$executionActive++;
            if($status==='failed')$executionFailed++;
        }
        $openMoves=0;foreach($actions as $action)if(($action['status']??'')==='open')$openMoves++;
        $watch24=0;foreach($alerts as $alert)if($this->withinHours((string)($alert['created_at']??''),24))$watch24++;
        $activeJobs=count($agents);
        $needs=count($attention);

        return [
            'ready'=>true,
            'generated_at'=>date(DATE_ATOM),
            'status'=>$needs>0?'Needs your attention':($activeJobs+$executionActive>0?'Vacation Brain is working':'Trips are under control'),
            'summary'=>[
                'upcoming_trips'=>count($trips),
                'active_agents'=>$activeJobs,
                'active_executions'=>$executionActive,
                'approvals_waiting'=>$approvals,
                'open_next_moves'=>$openMoves,
                'watch_alerts_24h'=>$watch24,
                'booking_handoffs'=>count($handoffs),
                'failed_executions'=>$executionFailed,
                'needs_you'=>$needs,
            ],
            'attention'=>$attention,
            'upcoming_trips'=>$trips,
            'active_agents'=>$agents,
            'watch_alerts'=>$alerts,
            'booking_handoffs'=>$handoffs,
            'risks'=>$risks,
        ];
    }

    private function upcomingTrips(int $userId): array
    {
        $itemsSelect=db_table_exists('dream_trip_items')?'(SELECT COALESCE(SUM(COALESCE(i.price,0)),0) FROM dream_trip_items i WHERE i.dream_trip_id=dt.id)':'0';
        $stmt=$this->pdo->prepare("SELECT dt.id,dt.name,dt.status,dt.start_date,dt.end_date,dt.travelers,dt.target_budget,dt.booking_readiness,dt.metadata_json,dt.updated_at".(db_column_exists('dream_trips','currency')?',dt.currency':'').",$itemsSelect AS planned_spend FROM dream_trips dt WHERE dt.user_id=? AND dt.status NOT IN ('abandoned','completed') AND (dt.end_date IS NULL OR dt.end_date>=CURDATE()) ORDER BY dt.start_date IS NULL,dt.start_date ASC,dt.updated_at DESC,dt.id DESC LIMIT 6");
        $stmt->execute([$userId]);$rows=$stmt->fetchAll()?:[];
        $jobCounts=$this->tripCountMap('trip_agent_jobs',$userId,"status IN ('queued','running')");
        $watchCounts=$this->tripCountMap('travel_watches',$userId,"is_active=1");
        $actionCounts=$this->tripCountMap('trip_agent_actions',$userId,"status IN ('open','accepted')");
        $approvalCounts=$this->tripCountMap('trip_agent_action_executions',$userId,"status='awaiting_approval'");
        foreach($rows as &$row){
            $tripId=(int)$row['id'];$meta=json_decode((string)($row['metadata_json']??''),true)?:[];
            $destination=trim((string)($meta['destination_name']??''));
            $target=$row['target_budget']!==null?(float)$row['target_budget']:null;$spend=(float)($row['planned_spend']??0);
            $row=[
                'id'=>$tripId,'name'=>(string)$row['name'],'destination'=>$destination,'status'=>(string)$row['status'],
                'start_date'=>$row['start_date']??null,'end_date'=>$row['end_date']??null,'date_label'=>$this->dateLabel($row['start_date']??null,$row['end_date']??null),'days_until'=>$this->daysUntil($row['start_date']??null),
                'travelers'=>(int)($row['travelers']??1),'target_budget'=>$target,'planned_spend'=>$spend,'budget_percent'=>$target!==null&&$target>0?(int)round(($spend/$target)*100):null,
                'currency'=>(string)($row['currency']??'USD'),'booking_readiness'=>(int)($row['booking_readiness']??0),
                'active_agents'=>(int)($jobCounts[$tripId]??0),'active_watches'=>(int)($watchCounts[$tripId]??0),'next_moves'=>(int)($actionCounts[$tripId]??0),'approvals_waiting'=>(int)($approvalCounts[$tripId]??0),
                'url'=>app_url('dream-trip.php?id='.$tripId.'&tab=overview'),
            ];
        }unset($row);return $rows;
    }

    private function activeAgents(int $userId): array
    {
        if(!db_table_exists('trip_agent_jobs'))return [];
        $stmt=$this->pdo->prepare("SELECT j.id,j.dream_trip_id,j.agent_type,j.status,j.progress,j.status_text,j.updated_at,dt.name trip_name FROM trip_agent_jobs j JOIN dream_trips dt ON dt.id=j.dream_trip_id WHERE j.user_id=? AND j.status IN ('queued','running') ORDER BY FIELD(j.status,'running','queued'),j.updated_at DESC,j.id DESC LIMIT 10");
        $stmt->execute([$userId]);$out=[];foreach($stmt->fetchAll()?:[] as $row){$tripId=(int)$row['dream_trip_id'];$agent=(string)$row['agent_type'];$out[]=['id'=>(int)$row['id'],'trip_id'=>$tripId,'trip_name'=>(string)$row['trip_name'],'agent_type'=>$agent,'agent_label'=>$this->agentLabel($agent),'status'=>(string)$row['status'],'progress'=>(int)$row['progress'],'status_text'=>(string)($row['status_text']??''),'updated_at'=>(string)$row['updated_at'],'url'=>app_url('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($agent).'#agent-results')];}return $out;
    }

    private function watchAlerts(int $userId): array
    {
        if(!db_table_exists('travel_watch_events')||!db_table_exists('travel_watches'))return [];
        $stmt=$this->pdo->prepare("SELECT e.id,e.data_type,e.event_type,e.direction,e.title,e.body,e.created_at,w.destination_name,w.dream_trip_id FROM travel_watch_events e JOIN travel_watches w ON w.id=e.watch_id WHERE e.user_id=? AND e.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY e.created_at DESC,e.id DESC LIMIT 8");$stmt->execute([$userId]);$out=[];
        foreach($stmt->fetchAll()?:[] as $row){$tripId=(int)($row['dream_trip_id']??0);$out[]=['id'=>(int)$row['id'],'data_type'=>(string)$row['data_type'],'event_type'=>(string)$row['event_type'],'direction'=>(string)$row['direction'],'title'=>(string)$row['title'],'body'=>(string)$row['body'],'destination'=>(string)$row['destination_name'],'trip_id'=>$tripId?:null,'created_at'=>(string)$row['created_at'],'url'=>$tripId>0?app_url('dream-trip.php?id='.$tripId.'&tab=overview#next-moves'):app_url('watches.php')];}return $out;
    }

    private function activeActions(int $userId): array
    {
        if(!db_table_exists('trip_agent_actions'))return [];
        $stmt=$this->pdo->prepare("SELECT a.id,a.dream_trip_id,a.agent_type,a.action_kind,a.title,a.body,a.priority,a.target_tab,a.status,a.updated_at,dt.name trip_name FROM trip_agent_actions a JOIN dream_trips dt ON dt.id=a.dream_trip_id WHERE a.user_id=? AND a.status IN ('open','accepted') ORDER BY FIELD(a.status,'accepted','open'),a.priority DESC,a.updated_at DESC,a.id DESC LIMIT 20");$stmt->execute([$userId]);$out=[];
        foreach($stmt->fetchAll()?:[] as $row){$tripId=(int)$row['dream_trip_id'];$tab=(string)($row['target_tab']?:'overview');$out[]=['id'=>(int)$row['id'],'trip_id'=>$tripId,'trip_name'=>(string)$row['trip_name'],'agent_type'=>(string)$row['agent_type'],'action_kind'=>(string)$row['action_kind'],'title'=>(string)$row['title'],'body'=>(string)$row['body'],'priority'=>(int)$row['priority'],'target_tab'=>$tab,'status'=>(string)$row['status'],'updated_at'=>(string)$row['updated_at'],'url'=>app_url('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($tab).'#next-moves')];}return $out;
    }

    private function executions(int $userId): array
    {
        if(!db_table_exists('trip_agent_action_executions')||!db_table_exists('trip_agent_actions'))return [];
        $stmt=$this->pdo->prepare("SELECT e.id,e.action_id,e.dream_trip_id,e.agent_type,e.status,e.proposal_type,e.proposal_json,e.error_message,e.updated_at,a.title,a.priority,dt.name trip_name FROM trip_agent_action_executions e JOIN trip_agent_actions a ON a.id=e.action_id JOIN dream_trips dt ON dt.id=e.dream_trip_id WHERE e.user_id=? AND (e.status IN ('queued','agent_working','awaiting_approval','failed') OR e.updated_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)) ORDER BY FIELD(e.status,'awaiting_approval','failed','agent_working','queued','completed','rejected','cancelled'),e.updated_at DESC,e.id DESC LIMIT 20");$stmt->execute([$userId]);$out=[];
        foreach($stmt->fetchAll()?:[] as $row){$proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))$proposal=[];$tripId=(int)$row['dream_trip_id'];$out[]=['id'=>(int)$row['id'],'action_id'=>(int)$row['action_id'],'trip_id'=>$tripId,'trip_name'=>(string)$row['trip_name'],'agent_type'=>(string)$row['agent_type'],'status'=>(string)$row['status'],'proposal_type'=>(string)($row['proposal_type']??''),'proposal'=>$proposal,'title'=>(string)$row['title'],'priority'=>(int)$row['priority'],'error'=>(string)($row['error_message']??''),'updated_at'=>(string)$row['updated_at'],'url'=>app_url('dream-trip.php?id='.$tripId.'&tab=overview#next-moves')];}return $out;
    }

    private function bookingHandoffs(int $userId): array
    {
        if(!db_table_exists('trip_agent_action_executions')||!db_table_exists('trip_agent_actions'))return [];
        $stmt=$this->pdo->prepare("SELECT e.id,e.action_id,e.dream_trip_id,e.status,e.proposal_json,e.updated_at,a.title,dt.name trip_name FROM trip_agent_action_executions e JOIN trip_agent_actions a ON a.id=e.action_id JOIN dream_trips dt ON dt.id=e.dream_trip_id WHERE e.user_id=? AND e.proposal_type='booking_handoff' AND e.status IN ('awaiting_approval','completed') ORDER BY FIELD(e.status,'awaiting_approval','completed'),e.updated_at DESC,e.id DESC LIMIT 6");$stmt->execute([$userId]);$out=[];
        foreach($stmt->fetchAll()?:[] as $row){$proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))$proposal=[];$tripId=(int)$row['dream_trip_id'];$out[]=['id'=>(int)$row['id'],'action_id'=>(int)$row['action_id'],'trip_id'=>$tripId,'trip_name'=>(string)$row['trip_name'],'status'=>(string)$row['status'],'title'=>(string)($proposal['title']??$row['title']),'approval_note'=>(string)($proposal['approval_note']??'Confirm live provider availability, final price, terms, and payment before booking.'),'updated_at'=>(string)$row['updated_at'],'url'=>app_url('dream-trip.php?id='.$tripId.'&tab=overview#next-moves')];}return $out;
    }

    private function risks(array $trips,array $actions,array $alerts): array
    {
        $out=[];$seen=[];
        foreach($trips as $trip){$pct=$trip['budget_percent'];if($pct!==null&&$pct>=90){$severity=$pct>100?'high':'medium';$key='budget:'.$trip['id'];$seen[$key]=true;$out[]=['key'=>$key,'kind'=>'budget','severity'=>$severity,'title'=>$pct>100?'Budget is over target':'Budget is getting tight','body'=>$trip['name'].' has '.max(0,$pct).'% of its target budget represented by saved trip items.','trip_id'=>$trip['id'],'url'=>$trip['url']];}}
        foreach($actions as $action){if(!in_array($action['agent_type'],['weather','budget'],true)||$action['status']!=='open')continue;$key=$action['agent_type'].':action:'.$action['id'];if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=['key'=>$key,'kind'=>$action['agent_type'],'severity'=>$action['priority']>=80?'high':'medium','title'=>$action['title'],'body'=>$action['body'],'trip_id'=>$action['trip_id'],'url'=>$action['url']];if(count($out)>=8)break;}
        foreach($alerts as $alert){if($alert['data_type']!=='weather'||!$this->withinHours($alert['created_at'],48))continue;$key='weather:alert:'.$alert['id'];if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=['key'=>$key,'kind'=>'weather','severity'=>'medium','title'=>$alert['title'],'body'=>$alert['body'],'trip_id'=>$alert['trip_id'],'url'=>$alert['url']];if(count($out)>=8)break;}
        usort($out,fn($a,$b)=>($b['severity']==='high'?2:1)<=>($a['severity']==='high'?2:1));return array_slice($out,0,8);
    }

    private function attention(array $actions,array $executions,array $risks): array
    {
        $out=[];$seen=[];
        foreach($executions as $execution){$status=$execution['status'];if($status==='awaiting_approval'){$key='approval:'.$execution['id'];$seen[$key]=true;$out[]=['key'=>$key,'kind'=>'approval','priority'=>100,'title'=>'Approve a proposed trip change','body'=>$execution['title'].' · '.$execution['trip_name'],'trip_name'=>$execution['trip_name'],'url'=>$execution['url'],'cta'=>'Review approval'];}elseif($status==='failed'){$key='failed:'.$execution['id'];$seen[$key]=true;$out[]=['key'=>$key,'kind'=>'failed','priority'=>95,'title'=>'Agent execution needs attention','body'=>$execution['title'].' · '.$execution['trip_name'],'trip_name'=>$execution['trip_name'],'url'=>$execution['url'],'cta'=>'Review failure'];}}
        foreach($actions as $action){if($action['status']!=='open'||$action['priority']<70)continue;$key='move:'.$action['id'];if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=['key'=>$key,'kind'=>'next_move','priority'=>$action['priority'],'title'=>$action['title'],'body'=>$action['body'],'trip_name'=>$action['trip_name'],'url'=>$action['url'],'cta'=>'Open Next Move'];}
        foreach($risks as $risk){if($risk['severity']!=='high')continue;$key='risk:'.$risk['key'];if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=['key'=>$key,'kind'=>'risk','priority'=>85,'title'=>$risk['title'],'body'=>$risk['body'],'trip_name'=>'','url'=>$risk['url'],'cta'=>'Review risk'];}
        usort($out,fn($a,$b)=>$b['priority']<=>$a['priority']);return array_slice($out,0,8);
    }

    private function tripCountMap(string $table,int $userId,string $predicate): array
    {
        if(!db_table_exists($table)||!db_column_exists($table,'dream_trip_id')||!db_column_exists($table,'user_id'))return [];
        $stmt=$this->pdo->prepare("SELECT dream_trip_id,COUNT(*) c FROM $table WHERE user_id=? AND dream_trip_id IS NOT NULL AND $predicate GROUP BY dream_trip_id");$stmt->execute([$userId]);$out=[];foreach($stmt->fetchAll()?:[] as $row)$out[(int)$row['dream_trip_id']]=(int)$row['c'];return $out;
    }

    private function dateLabel(mixed $start,mixed $end): string
    {
        $start=trim((string)$start);$end=trim((string)$end);if($start==='')return 'Dates flexible';$s=strtotime($start);if(!$s)return 'Dates flexible';if($end===''||$end===$start)return date('M j, Y',$s);$e=strtotime($end);if(!$e)return date('M j, Y',$s);if(date('Y-m',$s)===date('Y-m',$e))return date('M j',$s).'–'.date('j, Y',$e);return date('M j',$s).' – '.date('M j, Y',$e);
    }

    private function daysUntil(mixed $value): ?int
    {
        $value=trim((string)$value);if($value==='')return null;try{$start=new DateTimeImmutable($value);$today=new DateTimeImmutable('today');return (int)$today->diff($start)->format('%r%a');}catch(Throwable $e){return null;}
    }

    private function withinHours(string $value,int $hours): bool
    {
        $ts=strtotime($value);return $ts!==false&&$ts>=time()-($hours*3600);
    }

    private function agentLabel(string $agent): string
    {
        return match($agent){'weather'=>'Weather Agent','flights'=>'Flights Agent','events'=>'Events Agent','local'=>'Local Agent','itinerary'=>'Itinerary Agent','budget'=>'Budget Agent',default=>'Overview Agent'};
    }

    private function emptySnapshot(): array
    {
        return ['ready'=>false,'generated_at'=>date(DATE_ATOM),'status'=>'Command Center unavailable','summary'=>['upcoming_trips'=>0,'active_agents'=>0,'active_executions'=>0,'approvals_waiting'=>0,'open_next_moves'=>0,'watch_alerts_24h'=>0,'booking_handoffs'=>0,'failed_executions'=>0,'needs_you'=>0],'attention'=>[],'upcoming_trips'=>[],'active_agents'=>[],'watch_alerts'=>[],'booking_handoffs'=>[],'risks'=>[]];
    }
}
