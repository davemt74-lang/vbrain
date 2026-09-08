<?php
declare(strict_types=1);

final class VacationImageProfileService
{
    public function __construct(private PDO $pdo) {}

    public function build(int $userId): array
    {
        $diagnosis=$this->latestDiagnosis($userId);
        $vacation=(new VacationProfileService($this->pdo))->snapshot($userId);
        $travel=$this->travelProfile($userId);
        $dreams=$this->dreamTrips($userId);
        $recentChoices=$this->recentChoices($userId,$diagnosis['answer_ids']??[]);

        $traits=[];
        foreach(($vacation['traits']??[]) as $slug=>$row){
            $traits[]=[
                'slug'=>$slug,
                'name'=>(string)($row['name']??$slug),
                'score'=>(int)round((float)($row['score']??50)),
                'confidence'=>(int)round((float)($row['confidence']??0)),
                'interactions'=>(int)($row['interaction_count']??0),
            ];
        }

        $profile=[
            'profile_version'=>max(1,(int)site_setting('vacation_photos.profile_version','1')),
            'diagnosis'=>$diagnosis,
            'vacation_brain'=>[
                'score'=>(int)($vacation['score']??0),
                'level'=>(string)($vacation['level']['title']??''),
                'archetype'=>(string)($vacation['archetype']['name']??''),
                'archetype_description'=>(string)($vacation['archetype']['description']??''),
                'traits'=>$traits,
            ],
            'travel_profile'=>$travel,
            'dream_trips'=>$dreams,
            'recent_explicit_choices'=>$recentChoices,
        ];
        $prompt=$this->promptText($profile);
        $json=json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $hash=hash('sha256',$json);

        try{
            $stmt=$this->pdo->prepare('INSERT INTO vacation_photo_user_profiles (user_id,profile_version,profile_hash,profile_json,prompt_text,rebuilt_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE profile_version=VALUES(profile_version),profile_hash=VALUES(profile_hash),profile_json=VALUES(profile_json),prompt_text=VALUES(prompt_text),rebuilt_at=NOW()');
            $stmt->execute([$userId,$profile['profile_version'],$hash,$json,$prompt]);
        }catch(Throwable){/* Generation can still proceed if snapshot caching fails. */}

        return ['hash'=>$hash,'profile'=>$profile,'prompt'=>$prompt];
    }

    private function latestDiagnosis(int $userId): array
    {
        if(!db_table_exists('diagnosis_results')) return ['answers'=>[],'answer_ids'=>[]];
        $stmt=$this->pdo->prepare('SELECT diagnosis_score,vacation_brain_score,diagnosis_level,diagnosis_title,summary_text,prescription_json,answer_snapshot_json,trait_snapshot_json,created_at FROM diagnosis_results WHERE user_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);$row=$stmt->fetch();
        if(!$row) return ['answers'=>[],'answer_ids'=>[]];
        $ids=json_decode((string)($row['answer_snapshot_json']??'[]'),true);$ids=is_array($ids)?array_values(array_unique(array_map('intval',$ids))):[];
        $answers=[];
        if($ids){
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $q=$this->pdo->prepare("SELECT cc.id,cc.label,cc.description,ci.title,ci.body,ci.short_body FROM content_choices cc JOIN content_items ci ON ci.id=cc.content_id WHERE cc.id IN ($placeholders)");
            $q->execute($ids);$map=[];foreach($q->fetchAll() as $answer)$map[(int)$answer['id']]=$answer;
            foreach($ids as $id){if(!isset($map[$id]))continue;$r=$map[$id];$answers[]=['choice_id'=>$id,'question'=>trim((string)($r['body']?:$r['title'])),'answer'=>trim((string)$r['label']),'answer_detail'=>trim((string)($r['description']??''))];}
        }
        return [
            'score'=>(int)$row['diagnosis_score'],
            'vacation_brain_score'=>(int)$row['vacation_brain_score'],
            'level'=>(string)$row['diagnosis_level'],
            'title'=>(string)$row['diagnosis_title'],
            'summary'=>(string)$row['summary_text'],
            'prescription'=>json_decode((string)($row['prescription_json']??'[]'),true)?:[],
            'answers'=>$answers,
            'answer_ids'=>$ids,
            'created_at'=>(string)$row['created_at'],
        ];
    }

    private function travelProfile(int $userId): array
    {
        if(!db_table_exists('travel_match_profiles')) return [];
        try{
            $stmt=$this->pdo->prepare('SELECT bio,favorite_destination,travel_pace,budget_style,dealbreakers_json,match_mode FROM travel_match_profiles WHERE user_id=? LIMIT 1');
            $stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)return [];
            return [
                'bio'=>trim((string)($row['bio']??'')),
                'favorite_destination'=>trim((string)($row['favorite_destination']??'')),
                'travel_pace'=>(string)($row['travel_pace']??''),
                'budget_style'=>(string)($row['budget_style']??''),
                'trip_social_mode'=>(string)($row['match_mode']??''),
                'strong_preferences'=>json_decode((string)($row['dealbreakers_json']??'{}'),true)?:[],
            ];
        }catch(Throwable){return [];}
    }

    private function dreamTrips(int $userId): array
    {
        if(!db_table_exists('dream_trips')) return [];
        try{
            $stmt=$this->pdo->prepare('SELECT name,description,status,dream_level,booking_readiness,metadata_json FROM dream_trips WHERE user_id=? AND status<>"abandoned" ORDER BY updated_at DESC,id DESC LIMIT 8');
            $stmt->execute([$userId]);$out=[];
            foreach($stmt->fetchAll() as $row){$meta=json_decode((string)($row['metadata_json']??'{}'),true)?:[];$out[]=['name'=>(string)$row['name'],'destination'=>(string)($meta['destination_name']??''),'description'=>(string)($row['description']??''),'status'=>(string)$row['status'],'dream_level'=>(int)$row['dream_level'],'booking_readiness'=>(int)round((float)$row['booking_readiness'])];}
            return $out;
        }catch(Throwable){return [];}
    }

    private function recentChoices(int $userId,array $excludeIds): array
    {
        if(!db_table_exists('user_events')) return [];
        try{
            $stmt=$this->pdo->prepare('SELECT ue.choice_id,ue.occurred_at,cc.label,cc.description,ci.title,ci.body FROM user_events ue JOIN content_choices cc ON cc.id=ue.choice_id JOIN content_items ci ON ci.id=cc.content_id WHERE ue.user_id=? AND ue.event_type="choice_selected" AND ue.choice_id IS NOT NULL ORDER BY ue.occurred_at DESC,ue.id DESC LIMIT 60');
            $stmt->execute([$userId]);$exclude=array_fill_keys(array_map('intval',$excludeIds),true);$seen=[];$out=[];
            foreach($stmt->fetchAll() as $row){$id=(int)$row['choice_id'];if(isset($exclude[$id])||isset($seen[$id]))continue;$seen[$id]=true;$out[]=['question'=>trim((string)($row['body']?:$row['title'])),'answer'=>trim((string)$row['label']),'answer_detail'=>trim((string)($row['description']??'')),'answered_at'=>(string)$row['occurred_at']];if(count($out)>=24)break;}
            return $out;
        }catch(Throwable){return [];}
    }

    private function promptText(array $profile): string
    {
        $lines=[];
        $diagnosis=$profile['diagnosis']??[];
        $lines[]='VACATION BRAIN USER PREFERENCE PROFILE. Use these signals to decide what the vacation looks and feels like, not to redefine the person’s physical identity. The supplied reference photos are authoritative for appearance.';
        if(!empty($diagnosis['title']))$lines[]='Diagnosis: '.$diagnosis['title'].' ('.(int)($diagnosis['score']??0).'/100). '.trim((string)($diagnosis['summary']??''));
        if(!empty($diagnosis['answers'])){
            $lines[]='All original diagnosis answers:';
            foreach($diagnosis['answers'] as $i=>$answer){$line=($i+1).'. '.trim((string)$answer['question']).' → '.trim((string)$answer['answer']);if(!empty($answer['answer_detail']))$line.=' — '.trim((string)$answer['answer_detail']);$lines[]=$line;}
        }
        if(!empty($diagnosis['prescription']))$lines[]='Vacation prescription: '.implode(' | ',array_map('strval',$diagnosis['prescription']));

        $vb=$profile['vacation_brain']??[];
        if(!empty($vb['archetype']))$lines[]='Current archetype: '.$vb['archetype'].'. '.trim((string)($vb['archetype_description']??''));
        if(!empty($vb['traits'])){
            $parts=[];foreach($vb['traits'] as $trait){$parts[]=$trait['name'].' '.(int)$trait['score'].'/100';}$lines[]='Learned travel traits: '.implode(', ',$parts).'.';
        }
        $travel=$profile['travel_profile']??[];
        if($travel){$parts=[];foreach(['travel_pace'=>'pace','budget_style'=>'budget style','favorite_destination'=>'favorite destination','bio'=>'travel bio'] as $key=>$label){if(!empty($travel[$key]))$parts[]=$label.': '.trim((string)$travel[$key]);}if($parts)$lines[]='User-provided Travel Match preferences: '.implode('; ',$parts).'.';}
        if(!empty($profile['dream_trips'])){$parts=[];foreach($profile['dream_trips'] as $dream){$label=trim((string)($dream['destination']?:$dream['name']));if($label!=='')$parts[]=$label.' ('.(string)$dream['status'].')';}$parts=array_values(array_unique($parts));if($parts)$lines[]='Saved Dream Trips: '.implode(', ',$parts).'.';}
        if(!empty($profile['recent_explicit_choices'])){$lines[]='Additional recent user choices:';foreach($profile['recent_explicit_choices'] as $choice)$lines[]='- '.trim((string)$choice['question']).' → '.trim((string)$choice['answer']);}
        $lines[]='When preferences conflict, prefer explicit recent choices over older inferred traits. Use preferences for location details, activities, pace, luxury level, food, time of day, mood, styling, and props. Never invent a sensitive personal attribute from these preferences.';
        return implode("\n",$lines);
    }
}
