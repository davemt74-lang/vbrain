<?php
declare(strict_types=1);

final class TripBookingReminderService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_readiness_requirements') && db_table_exists('dream_trips');
    }

    public function dueAttention(int $userId,int $days=7): array
    {
        if($userId<1)throw new InvalidArgumentException('User is required.');
        if(!$this->ready())return [];$days=max(1,min(30,$days));
        $cutoff=date('Y-m-d H:i:s',time()+($days*86400));
        $stmt=$this->pdo->prepare("SELECT r.id,r.dream_trip_id,r.requirement_type,r.title,r.due_at,r.notes,dt.name trip_name FROM trip_readiness_requirements r JOIN dream_trips dt ON dt.id=r.dream_trip_id AND dt.user_id=r.user_id WHERE r.user_id=? AND r.is_required=1 AND r.status='open' AND r.due_at IS NOT NULL AND r.due_at<=? AND dt.status NOT IN ('completed','abandoned') ORDER BY r.due_at ASC,r.id ASC LIMIT 8");
        $stmt->execute([$userId,$cutoff]);$out=[];$now=time();
        foreach($stmt->fetchAll()?:[] as $row){$due=strtotime((string)$row['due_at']);if($due===false)continue;$overdue=$due<$now;$tripId=(int)$row['dream_trip_id'];$when=$overdue?'overdue':$this->relativeDue($due-$now);$out[]=['key'=>'requirement:'.(int)$row['id'],'kind'=>'risk','priority'=>$overdue?97:90,'title'=>$overdue?'Required trip item is overdue':'Required trip item due soon','body'=>(string)$row['title'].' · '.(string)$row['trip_name'].' · '.$when,'trip_name'=>(string)$row['trip_name'],'url'=>app_url('trip-bookings.php?id='.$tripId),'cta'=>'Review readiness'];}
        return $out;
    }

    public function augmentCommandCenterSnapshot(int $userId,array $snapshot): array
    {
        if(!$this->ready())return $snapshot;$reminders=$this->dueAttention($userId,7);if(!$reminders)return $snapshot;
        $attention=array_merge($reminders,is_array($snapshot['attention']??null)?$snapshot['attention']:[]);$seen=[];$dedup=[];
        foreach($attention as $item){$key=(string)($item['key']??'');if($key!==''&&isset($seen[$key]))continue;if($key!=='')$seen[$key]=true;$dedup[]=$item;}
        usort($dedup,fn($a,$b)=>(int)($b['priority']??0)<=>(int)($a['priority']??0));$snapshot['attention']=array_slice($dedup,0,8);$snapshot['summary']['readiness_reminders']=count($reminders);$snapshot['summary']['needs_you']=count($snapshot['attention']);$snapshot['status']='Needs your attention';return $snapshot;
    }

    private function relativeDue(int $seconds): string
    {
        if($seconds<=3600)return 'due within an hour';$hours=(int)ceil($seconds/3600);if($hours<24)return 'due in '.$hours.'h';$days=(int)ceil($hours/24);return 'due in '.$days.' day'.($days===1?'':'s');
    }
}
