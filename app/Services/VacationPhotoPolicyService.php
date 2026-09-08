<?php
declare(strict_types=1);

final class VacationPhotoPolicyService
{
    public function __construct(private PDO $pdo) {}

    public function limits(int $userId): array
    {
        $defaults=[
            'daily'=>max(0,(int)site_setting('vacation_photos.daily_limit','8')),
            'monthly'=>max(0,(int)site_setting('vacation_photos.monthly_limit','40')),
            'lifetime'=>max(0,(int)site_setting('vacation_photos.lifetime_limit','0')),
            'comped'=>0,
        ];
        if(!db_table_exists('vacation_photo_user_limits'))return $defaults;
        $stmt=$this->pdo->prepare('SELECT daily_limit,monthly_limit,lifetime_limit,comped_generations FROM vacation_photo_user_limits WHERE user_id=? LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)return $defaults;
        return [
            'daily'=>$row['daily_limit']===null?$defaults['daily']:max(0,(int)$row['daily_limit']),
            'monthly'=>$row['monthly_limit']===null?$defaults['monthly']:max(0,(int)$row['monthly_limit']),
            'lifetime'=>$row['lifetime_limit']===null?$defaults['lifetime']:max(0,(int)$row['lifetime_limit']),
            'comped'=>max(0,(int)$row['comped_generations']),
        ];
    }

    public function usage(int $userId): array
    {
        if(!db_table_exists('vacation_photo_generations'))return ['today'=>0,'month'=>0,'lifetime'=>0,'completed'=>0,'failed'=>0];
        $stmt=$this->pdo->prepare('SELECT SUM(created_at>=CURDATE()) today_count,SUM(created_at>=DATE_FORMAT(CURDATE(),"%Y-%m-01")) month_count,COUNT(*) lifetime_count,SUM(status="completed") completed_count,SUM(status="failed") failed_count FROM vacation_photo_generations WHERE user_id=? AND deleted_at IS NULL');$stmt->execute([$userId]);$row=$stmt->fetch()?:[];
        return ['today'=>(int)($row['today_count']??0),'month'=>(int)($row['month_count']??0),'lifetime'=>(int)($row['lifetime_count']??0),'completed'=>(int)($row['completed_count']??0),'failed'=>(int)($row['failed_count']??0)];
    }

    public function status(int $userId): array
    {
        $limits=$this->limits($userId);$usage=$this->usage($userId);$effectiveLifetime=$limits['lifetime']>0?$limits['lifetime']+$limits['comped']:0;
        $remaining=[
            'daily'=>$limits['daily']>0?max(0,$limits['daily']-$usage['today']):null,
            'monthly'=>$limits['monthly']>0?max(0,$limits['monthly']-$usage['month']):null,
            'lifetime'=>$effectiveLifetime>0?max(0,$effectiveLifetime-$usage['lifetime']):null,
        ];
        $allowed=site_setting_bool('vacation_photos.enabled',true);
        $reason=$allowed?'': 'Vacation Yourself is currently disabled.';
        foreach(['daily','monthly','lifetime'] as $period){if($allowed&&$limits[$period]>0&&$remaining[$period]!==null&&$remaining[$period]<=0){$allowed=false;$reason=match($period){'daily'=>'Your Vacation Yourself daily generation limit has been reached.','monthly'=>'Your Vacation Yourself monthly generation limit has been reached.',default=>'Your Vacation Yourself generation allowance has been reached.'};}}
        return ['allowed'=>$allowed,'reason'=>$reason,'limits'=>$limits,'usage'=>$usage,'remaining'=>$remaining];
    }

    public function assertCanGenerate(int $userId): void
    {
        $status=$this->status($userId);if(!$status['allowed'])throw new RuntimeException((string)$status['reason']);
    }

    public function estimate(string $quality,string $size,int $referenceCount=0): float
    {
        $quality=in_array($quality,['low','medium','high'],true)?$quality:'medium';$shape=$size==='1024x1024'?'square':'large';
        $base=max(0,(float)site_setting('vacation_photos.estimate.'.$quality.'.'.$shape,$quality==='high'?($shape==='square'?'0.1500':'0.2200'):($quality==='low'?($shape==='square'?'0.0150':'0.0200'):($shape==='square'?'0.0450':'0.0600'))));
        $reference=max(0,(float)site_setting('vacation_photos.estimate.reference_image','0.0100'));
        return round($base+($reference*max(0,$referenceCount)),4);
    }

    public function generationEstimate(array $row): float
    {
        $refs=json_decode((string)($row['source_refs_json']??'[]'),true);return $this->estimate((string)($row['quality']??'medium'),(string)($row['size']??'1024x1024'),is_array($refs)?count($refs):0);
    }

    public function aggregate(?int $days=30): array
    {
        $where='WHERE g.status="completed" AND g.image_url IS NOT NULL AND g.deleted_at IS NULL';$args=[];if($days!==null&&$days>0){$where.=' AND g.created_at>=DATE_SUB(NOW(),INTERVAL ? DAY)';$args[]=$days;}
        $stmt=$this->pdo->prepare('SELECT g.*,u.display_name,u.email FROM vacation_photo_generations g JOIN users u ON u.id=g.user_id '.$where.' ORDER BY g.created_at DESC LIMIT 2000');$stmt->execute($args);$rows=$stmt->fetchAll()?:[];$total=0.0;$byQuality=['low'=>0,'medium'=>0,'high'=>0];
        foreach($rows as &$row){$row['_estimated_cost']=$this->generationEstimate($row);$total+=$row['_estimated_cost'];$quality=(string)($row['quality']??'medium');if(isset($byQuality[$quality]))$byQuality[$quality]++;}unset($row);
        return ['rows'=>$rows,'count'=>count($rows),'estimated_cost'=>round($total,2),'by_quality'=>$byQuality];
    }

    public function saveDefaults(array $input,int $adminId): void
    {
        $settings=[
            'vacation_photos.enabled'=>!empty($input['enabled'])?'1':'0','vacation_photos.gallery_enabled'=>!empty($input['gallery_enabled'])?'1':'0','vacation_photos.sharing_enabled'=>!empty($input['sharing_enabled'])?'1':'0',
            'vacation_photos.daily_limit'=>(string)max(0,min(1000,(int)($input['daily_limit']??8))),'vacation_photos.monthly_limit'=>(string)max(0,min(10000,(int)($input['monthly_limit']??40))),'vacation_photos.lifetime_limit'=>(string)max(0,min(1000000,(int)($input['lifetime_limit']??0))),
            'vacation_photos.default_quality'=>in_array(($input['default_quality']??'medium'),['low','medium','high'],true)?(string)$input['default_quality']:'medium','vacation_photos.default_over_the_top'=>(string)max(0,min(100,(int)($input['default_over_the_top']??35))),'vacation_photos.max_reference_images'=>(string)max(1,min(4,(int)($input['max_reference_images']??4))),
        ];
        foreach(['low.square','low.large','medium.square','medium.large','high.square','high.large','reference_image'] as $key)$settings['vacation_photos.estimate.'.$key]=number_format(max(0,min(10,(float)($input['cost_'.$key]??site_setting('vacation_photos.estimate.'.$key,'0')))),4,'.','');
        foreach($settings as $key=>$value)set_site_setting($key,$value,str_starts_with($key,'vacation_photos.estimate.')?'vacation_photo_costs':'vacation_photos',$adminId);
    }

    public function saveUserLimit(int $userId,array $input,int $adminId): void
    {
        $exists=$this->pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1');$exists->execute([$userId]);if(!$exists->fetchColumn())throw new InvalidArgumentException('User not found.');
        $nullable=static fn($v)=>$v===''||$v===null?null:max(0,(int)$v);
        $stmt=$this->pdo->prepare('INSERT INTO vacation_photo_user_limits (user_id,daily_limit,monthly_limit,lifetime_limit,comped_generations,notes,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE daily_limit=VALUES(daily_limit),monthly_limit=VALUES(monthly_limit),lifetime_limit=VALUES(lifetime_limit),comped_generations=VALUES(comped_generations),notes=VALUES(notes),updated_by=VALUES(updated_by),updated_at=NOW()');
        $stmt->execute([$userId,$nullable($input['daily_limit']??null),$nullable($input['monthly_limit']??null),$nullable($input['lifetime_limit']??null),max(0,(int)($input['comped_generations']??0)),substr(trim((string)($input['notes']??'')),0,500)?:null,$adminId]);
    }
}
