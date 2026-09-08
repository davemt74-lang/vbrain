<?php
declare(strict_types=1);

final class AchievementService
{
    public function __construct(private PDO $pdo) {}

    public function evaluate(int $userId): array
    {
        $unlocked=[];
        $scoreStmt=$this->pdo->prepare('SELECT vacation_brain_score,current_streak,lifetime_checkins FROM user_score_summary WHERE user_id=?');
        $scoreStmt->execute([$userId]);
        $summary=$scoreStmt->fetch() ?: ['vacation_brain_score'=>0,'current_streak'=>0,'lifetime_checkins'=>0];
        $score=(int)$summary['vacation_brain_score'];

        $traitStmt=$this->pdo->prepare('SELECT t.slug,ut.score FROM user_traits ut JOIN traits t ON t.id=ut.trait_id WHERE ut.user_id=?');
        $traitStmt->execute([$userId]);$traits=[];
        foreach($traitStmt->fetchAll() as $row)$traits[$row['slug']]=(float)$row['score'];

        $rules=$this->pdo->query('SELECT ar.*,a.slug,a.name,a.description FROM achievement_rules ar JOIN achievements a ON a.id=ar.achievement_id WHERE a.active=1')->fetchAll();
        foreach($rules as $rule){
            $complete=false;$progress=0;
            $filter=json_decode((string)($rule['filter_json']??''),true)?:[];
            switch($rule['rule_type']){
                case 'score_threshold':
                    $target=max(1,(float)$rule['threshold']);$progress=min(100,($score/$target)*100);$complete=$score >= $target;break;
                case 'trait_above':
                case 'trait_below':
                    $slug=(string)($filter['trait_slug']??'');$value=$traits[$slug]??null;$target=(float)$rule['threshold'];
                    if($value!==null){$complete=$rule['rule_type']==='trait_above' ? $value >= $target : $value <= $target;$progress=$complete?100:max(0,min(99,$rule['rule_type']==='trait_above'?($value/max(1,$target))*100:((100-$value)/max(1,100-$target))*100));}break;
                case 'event_count':
                    $event=(string)($rule['event_type']??'');$target=max(1,(int)$rule['threshold']);
                    if($event!==''){
                        $sql='SELECT COUNT(*) FROM user_events WHERE user_id=? AND event_type=?';$args=[$userId,$event];
                        if(isset($filter['value_text'])){$sql.=' AND value_text=?';$args[]=(string)$filter['value_text'];}
                        if(isset($filter['content_id'])){$sql.=' AND content_id=?';$args[]=(int)$filter['content_id'];}
                        if(($rule['time_window']??'')==='30_days')$sql.=' AND occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)';
                        $q=$this->pdo->prepare($sql);$q->execute($args);$count=(int)$q->fetchColumn();$progress=min(100,($count/$target)*100);$complete=$count >= $target;
                    }break;
                case 'checkin_streak':
                    $target=max(1,(int)$rule['threshold']);$value=(int)$summary['current_streak'];$progress=min(100,($value/$target)*100);$complete=$value >= $target;break;
                case 'lifetime_checkins':
                    $target=max(1,(int)$rule['threshold']);$value=(int)$summary['lifetime_checkins'];$progress=min(100,($value/$target)*100);$complete=$value >= $target;break;
            }

            $check=$this->pdo->prepare('SELECT completed FROM user_achievements WHERE user_id=? AND achievement_id=?');$check->execute([$userId,$rule['achievement_id']]);$already=(int)$check->fetchColumn()===1;
            $upsert=$this->pdo->prepare('INSERT INTO user_achievements (user_id,achievement_id,progress,completed,unlocked_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE progress=GREATEST(progress,VALUES(progress)),completed=GREATEST(completed,VALUES(completed)),unlocked_at=COALESCE(unlocked_at,VALUES(unlocked_at))');
            $upsert->execute([$userId,$rule['achievement_id'],round($progress,2),$complete?1:0,$complete?date('Y-m-d H:i:s'):null]);
            if($complete && !$already){
                $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,achievement_id,value_text) VALUES (? ,"achievement_unlocked",?,?)')->execute([$userId,$rule['achievement_id'],$rule['slug']]);
                $unlocked[]=['name'=>$rule['name'],'description'=>$rule['description']];
            }
        }
        return $unlocked;
    }
}
