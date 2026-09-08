<?php
declare(strict_types=1);

final class TravelMatchService
{
    private const TRAIT_WEIGHTS = [
        'relaxation'=>1.15,'adventure'=>1.1,'planning'=>1.0,'spontaneity'=>1.0,
        'budget_sensitivity'=>1.25,'luxury'=>1.0,'food'=>.85,'nightlife'=>1.0,
        'morning_tolerance'=>1.2,'direct_flight_preference'=>.8,'flight_tolerance'=>.8,
        'pool'=>.65,'beach'=>.65,'resort_preference'=>.7,'activity_level'=>.9,'convenience'=>.8,
    ];

    public function __construct(private PDO $pdo) {}

    public function profile(int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT tmp.*,u.display_name,u.avatar_url FROM travel_match_profiles tmp JOIN users u ON u.id=tmp.user_id WHERE tmp.user_id=?');
        $stmt->execute([$userId]);$row=$stmt->fetch();
        if(!$row) return null;
        $row['dealbreakers']=$this->decode((string)($row['dealbreakers_json']??''));
        return $row;
    }

    public function promptCatalog(): array
    {
        return [
            'ideal_morning'=>'My ideal vacation morning is…',
            'travel_hill'=>'The travel hill I will die on is…',
            'airport_opinion'=>'My most irrational airport opinion is…',
            'worth_splurge'=>'I will always spend extra money on…',
            'skip_every_time'=>'You can keep this vacation activity…',
            'perfect_day'=>'My perfect vacation day includes…',
            'trip_red_flag'=>'My vacation red flag is…',
            'move_here'=>'The place I would accidentally move to is…',
            'travel_confession'=>'My embarrassing travel confession is…',
            'roommate_rule'=>'If we share a hotel room, you should know…',
        ];
    }

    public function photos(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT id,photo_url,caption,sort_order FROM travel_match_profile_photos WHERE user_id=? ORDER BY sort_order,id');
        $stmt->execute([$userId]);return $stmt->fetchAll();
    }

    public function prompts(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT prompt_key,answer_text,sort_order FROM travel_match_profile_prompts WHERE user_id=? ORDER BY sort_order,id');
        $stmt->execute([$userId]);$catalog=$this->promptCatalog();$out=[];
        foreach($stmt->fetchAll() as $row){$row['prompt_label']=$catalog[(string)$row['prompt_key']]??'Vacation opinion';$out[]=$row;}
        return $out;
    }

    public function extendedProfile(int $userId): ?array
    {
        $row=$this->profile($userId);if(!$row)return null;
        $row['photos']=$this->photos($userId);$row['prompts']=$this->prompts($userId);$row['activity_label']=$this->activityLabel($row);
        return $row;
    }

    public function touchActivity(int $userId): void
    {
        $this->pdo->prepare('UPDATE travel_match_profiles SET last_match_active_at=NOW() WHERE user_id=? AND enabled=1')->execute([$userId]);
    }

    public function saveProfile(int $userId,array $input): array
    {
        $enrollment=(string)($input['enrollment_status']??(!empty($input['enabled'])?'enrolled':'unenrolled'));
        if(!in_array($enrollment,['enrolled','unenrolled'],true)) $enrollment='unenrolled';
        $enabled=$enrollment==='enrolled'?1:0;
        $age=(int)($input['age']??0);
        if($enabled && ($age<18 || $age>99)) throw new InvalidArgumentException('Travel Matching is 18+ only. Enter an age from 18 to 99.');
        $mode=in_array(($input['match_mode']??''),['dating','travel_buddy','either'],true)?$input['match_mode']:'either';
        $partnerGender=in_array(($input['partner_gender']??$input['show_me']??''),['everyone','women','men','nonbinary'],true)?($input['partner_gender']??$input['show_me']):'everyone';
        $show=$partnerGender;
        $pace=in_array(($input['travel_pace']??''),['slow','balanced','packed'],true)?$input['travel_pace']:'balanced';
        $budget=in_array(($input['budget_style']??''),['budget','comfortable','premium'],true)?$input['budget_style']:'comfortable';
        $gender=(string)($input['gender_identity']??'');
        if(!in_array($gender,['woman','man','nonbinary','prefer_not'],true)) $gender='prefer_not';
        $minAge=max(18,min(99,(int)($input['min_partner_age']??18)));$maxAge=max(18,min(99,(int)($input['max_partner_age']??99)));if($minAge>$maxAge)[$minAge,$maxAge]=[$maxAge,$minAge];
        $scope=in_array(($input['discovery_scope']??''),['anywhere','country','region','city'],true)?$input['discovery_scope']:'anywhere';$showActivity=!empty($input['show_activity_status'])?1:0;
        $city=trim((string)($input['home_city']??''));$region=trim((string)($input['home_region']??''));$country=trim((string)($input['home_country']??''));
        $bio=trim((string)($input['bio']??''));$favorite=trim((string)($input['favorite_destination']??''));
        if(strlen($bio)>500) $bio=substr($bio,0,500);
        $accountAvatar='';
        try {
            $avatarStmt=$this->pdo->prepare('SELECT avatar_url FROM users WHERE id=? LIMIT 1');
            $avatarStmt->execute([$userId]);
            $accountAvatar=trim((string)$avatarStmt->fetchColumn());
        } catch (Throwable $e) {}
        $photoInputs=[];for($i=1;$i<=4;$i++){
            $fallback=$i===1?($input['photo_url']??$accountAvatar):'';
            $url=trim((string)($input['photo_'.$i]??$fallback));
            if($i===1 && $url==='' && $accountAvatar!=='')$url=$accountAvatar;
            if($url!==''){$this->validatePhotoUrl($url);$photoInputs[]=$url;}
        }
        // Account avatar is canonical for primary Travel Match imagery.
        if($accountAvatar!=='' && (!isset($input['photo_1']) || trim((string)$input['photo_1'])==='')){
            if($photoInputs){$photoInputs[0]=$accountAvatar;}else{$this->validatePhotoUrl($accountAvatar);$photoInputs[]=$accountAvatar;}
        }
        $photo=$photoInputs[0]??'';
        $dealbreakers=[];
        foreach(['no_smoking','no_sunrise','nightlife_ok','budget_matters','spontaneous_ok'] as $key){$dealbreakers[$key]=!empty($input[$key]);}
        $promptCatalog=$this->promptCatalog();$promptRows=[];$used=[];
        for($i=1;$i<=3;$i++){ $key=(string)($input['prompt_key_'.$i]??'');$answer=trim((string)($input['prompt_answer_'.$i]??''));if($key===''||$answer==='')continue;if(!isset($promptCatalog[$key])||isset($used[$key]))continue;$used[$key]=true;if(strlen($answer)>500)$answer=substr($answer,0,500);$promptRows[]=['key'=>$key,'answer'=>$answer,'sort'=>$i-1]; }
        $before=$this->profile($userId);
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('INSERT INTO travel_match_profiles (user_id,enabled,enrollment_status,enrolled_at,unenrolled_at,age,gender_identity,partner_gender,min_partner_age,max_partner_age,discovery_scope,show_activity_status,show_me,match_mode,home_city,home_region,home_country,bio,favorite_destination,travel_pace,budget_style,photo_url,dealbreakers_json,last_match_active_at) VALUES (?,?,?,IF(?=1,NOW(),NULL),IF(?=0,NOW(),NULL),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),enrollment_status=VALUES(enrollment_status),enrolled_at=IF(VALUES(enabled)=1 AND enabled=0,NOW(),enrolled_at),unenrolled_at=IF(VALUES(enabled)=0 AND enabled=1,NOW(),unenrolled_at),age=VALUES(age),gender_identity=VALUES(gender_identity),partner_gender=VALUES(partner_gender),min_partner_age=VALUES(min_partner_age),max_partner_age=VALUES(max_partner_age),discovery_scope=VALUES(discovery_scope),show_activity_status=VALUES(show_activity_status),show_me=VALUES(show_me),match_mode=VALUES(match_mode),home_city=VALUES(home_city),home_region=VALUES(home_region),home_country=VALUES(home_country),bio=VALUES(bio),favorite_destination=VALUES(favorite_destination),travel_pace=VALUES(travel_pace),budget_style=VALUES(budget_style),photo_url=VALUES(photo_url),dealbreakers_json=VALUES(dealbreakers_json),last_match_active_at=IF(VALUES(enabled)=1,NOW(),last_match_active_at)');
            $stmt->execute([$userId,$enabled,$enrollment,$enabled,$enabled,$age,$gender?:null,$partnerGender,$minAge,$maxAge,$scope,$showActivity,$show,$mode,$city?:null,$region?:null,$country?:null,$bio?:null,$favorite?:null,$pace,$budget,$photo?:null,json_encode($dealbreakers)]);
            $this->pdo->prepare('DELETE FROM travel_match_profile_photos WHERE user_id=?')->execute([$userId]);
            if($photoInputs){$ins=$this->pdo->prepare('INSERT INTO travel_match_profile_photos (user_id,photo_url,sort_order) VALUES (?,?,?)');foreach($photoInputs as $i=>$url)$ins->execute([$userId,$url,$i]);}
            $this->pdo->prepare('DELETE FROM travel_match_profile_prompts WHERE user_id=?')->execute([$userId]);
            if($promptRows){$ins=$this->pdo->prepare('INSERT INTO travel_match_profile_prompts (user_id,prompt_key,answer_text,sort_order) VALUES (?,?,?,?)');foreach($promptRows as $row)$ins->execute([$userId,$row['key'],$row['answer'],$row['sort']]);}
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $readiness=$this->profileReadiness($userId,true);
        if($enabled && $readiness['score']<65){
            $this->pdo->prepare('UPDATE travel_match_profiles SET enabled=0,enrollment_status="unenrolled",unenrolled_at=NOW() WHERE user_id=?')->execute([$userId]);
            throw new InvalidArgumentException('Your Travel Match profile is '.$readiness['score'].'% ready. Finish the required profile basics before enrolling.');
        }
        if($enabled && (!$before || empty($before['enabled']))){
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type) VALUES (? ,"travel_match_profile_enabled")')->execute([$userId]);
            (new ScoreService($this->pdo))->award($userId,'travel_match_profile_enabled',10);
            (new AchievementService($this->pdo))->evaluate($userId);
        }
        return $this->extendedProfile($userId) ?? [];
    }

    public function profileReadiness(int $userId,bool $persist=false): array
    {
        $p=$this->profile($userId);if(!$p)return ['score'=>0,'ready'=>false,'missing'=>['Create your Travel Match profile'],'answer_count'=>0];
        $photos=$this->photos($userId);$prompts=$this->prompts($userId);$missing=[];$score=0;
        if((int)($p['age']??0)>=18)$score+=10;else$missing[]='Add your age';
        if(!empty($p['gender_identity']) && $p['gender_identity']!=='prefer_not')$score+=5;else$missing[]='Choose your gender';
        if(!empty($photos) || !empty($p['photo_url']))$score+=20;else$missing[]='Add a primary photo';
        if(count($photos)>=2)$score+=5;
        if(trim((string)($p['home_city']??''))!=='' || trim((string)($p['home_country']??''))!=='')$score+=10;else$missing[]='Add your home base';
        if(trim((string)($p['favorite_destination']??''))!=='')$score+=10;else$missing[]='Add a dream destination';
        if(trim((string)($p['bio']??''))!=='')$score+=5;
        $promptCount=min(3,count($prompts));$score+=$promptCount*5;if($promptCount<1)$missing[]='Answer at least one travel prompt';
        if(!empty($p['travel_pace'])&&!empty($p['budget_style']))$score+=5;
        if((string)($p['dealbreakers_json']??'')!=='')$score+=5;
        $q=$this->pdo->prepare('SELECT COUNT(DISTINCT choice_id) FROM user_events WHERE user_id=? AND event_type="choice_selected"');$q->execute([$userId]);$answers=(int)$q->fetchColumn();$score+=min(10,(int)floor($answers/1.5));if($answers<5)$missing[]='Answer at least 5 Vacation Brain questions';
        $score=max(0,min(100,$score));$ready=$score>=65 && empty(array_intersect($missing,['Add your age','Add a primary photo','Add your home base','Add a dream destination','Answer at least one travel prompt','Answer at least 5 Vacation Brain questions']));
        if($persist){$this->pdo->prepare('UPDATE travel_match_profiles SET profile_readiness=?,readiness_updated_at=NOW(),onboarding_completed=IF(?=1,1,onboarding_completed),onboarding_completed_at=IF(?=1,COALESCE(onboarding_completed_at,NOW()),onboarding_completed_at) WHERE user_id=?')->execute([$score,$ready?1:0,$ready?1:0,$userId]);}
        return ['score'=>$score,'ready'=>$ready,'missing'=>array_values(array_unique($missing)),'answer_count'=>$answers];
    }

    public function recordQualityFeedback(int $actor,int $target,string $context,string $reason): void
    {
        if($actor===$target)throw new InvalidArgumentException('Feedback needs another profile.');
        $contexts=['pass','unmatch'];$reasons=['too_far','different_budget','different_pace','not_enough_common','not_interested'];
        if(!in_array($context,$contexts,true))$context='pass';if(!in_array($reason,$reasons,true))$reason='not_interested';
        $this->pdo->prepare('INSERT INTO travel_match_quality_feedback (actor_user_id,target_user_id,context_type,reason) VALUES (?,?,?,?)')->execute([$actor,$target,$context,$reason]);
    }

    public function unmatch(int $userId,int $matchId,string $reason='not_interested'): void
    {
        $s=$this->pdo->prepare('SELECT *,CASE WHEN user_a_id=? THEN user_b_id ELSE user_a_id END partner_id FROM travel_matches WHERE id=? AND (user_a_id=? OR user_b_id=?) AND status="active"');$s->execute([$userId,$matchId,$userId,$userId]);$m=$s->fetch();if(!$m)throw new RuntimeException('That Travel Match is no longer active.');
        $partner=(int)$m['partner_id'];$allowed=['too_far','different_budget','different_pace','not_enough_common','not_interested'];if(!in_array($reason,$allowed,true))$reason='not_interested';
        $this->pdo->prepare('UPDATE travel_matches SET status="unmatched",unmatched_by_user_id=?,unmatched_at=NOW(),unmatch_reason=?,updated_at=NOW() WHERE id=? AND status="active"')->execute([$userId,$reason,$matchId]);
        $this->recordQualityFeedback($userId,$partner,'unmatch',$reason);
        $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric,value_text) VALUES (? ,"travel_match_unmatched",?,?)')->execute([$userId,$partner,$reason]);
    }

    public function dailyPicks(int $userId,array $candidates,int $limit=8): array
    {
        $limit=max(1,min(12,$limit));if(!$candidates)return[];$today=date('Y-m-d');
        $existing=$this->pdo->prepare('SELECT target_user_id,rank_score,rank_reasons_json FROM travel_match_daily_picks WHERE user_id=? AND pick_date=? ORDER BY rank_score DESC,id');$existing->execute([$userId,$today]);$by=[];foreach($existing->fetchAll() as $r)$by[(int)$r['target_user_id']]=$r;
        $candidateBy=[];foreach($candidates as $c)$candidateBy[(int)$c['user_id']]=$c;
        $selected=[];foreach($by as $id=>$meta){if(isset($candidateBy[$id])){$row=$candidateBy[$id];$row['daily_pick_reasons']=$this->decode((string)($meta['rank_reasons_json']??''));$selected[]=$row;if(count($selected)>=$limit)break;}}
        if(count($selected)<$limit){$ins=$this->pdo->prepare('INSERT IGNORE INTO travel_match_daily_picks (user_id,target_user_id,pick_date,rank_score,rank_reasons_json) VALUES (?,?,?,?,?)');$already=array_column($selected,'user_id');foreach($candidates as $c){if(count($selected)>=$limit)break;$id=(int)$c['user_id'];if(in_array($id,$already,true))continue;$reasons=$c['rank_reasons']??[];$ins->execute([$userId,$id,$today,(float)($c['discovery_rank']??0),json_encode($reasons)]);$c['daily_pick_reasons']=$reasons;$selected[]=$c;$already[]=$id;}}
        return array_slice($selected,0,$limit);
    }

    public function candidates(int $userId,int $limit=30,array $filters=[]): array
    {
        $mine=$this->profile($userId);if(!$mine || empty($mine['enabled'])) return [];
        $ageMin=max((int)($mine['min_partner_age']??18),max(18,min(99,(int)($filters['age_min']??18))));
        $ageMax=min((int)($mine['max_partner_age']??99),max(18,min(99,(int)($filters['age_max']??99))));if($ageMin>$ageMax)return[];
        $scope=(string)($filters['scope']??($mine['discovery_scope']??'anywhere'));if(!in_array($scope,['anywhere','country','region','city'],true))$scope='anywhere';
        $pace=(string)($filters['pace']??'any');if(!in_array($pace,['any','slow','balanced','packed'],true))$pace='any';$recent=!empty($filters['recent']);
        $queryLimit=max(50,min(200,$limit*7));
        $sampleClause=(db_column_exists('users','is_sample') && !sample_data_enabled()) ? ' AND u.is_sample=0' : '';
        $stmt=$this->pdo->prepare('SELECT tmp.*,u.display_name,u.avatar_url FROM travel_match_profiles tmp JOIN users u ON u.id=tmp.user_id WHERE tmp.enabled=1 AND tmp.user_id<>? AND u.status="active"'.$sampleClause.' AND tmp.age BETWEEN ? AND ? AND tmp.profile_readiness>=55 AND NOT EXISTS (SELECT 1 FROM travel_match_actions b WHERE ((b.actor_user_id=? AND b.target_user_id=tmp.user_id) OR (b.actor_user_id=tmp.user_id AND b.target_user_id=?)) AND b.action_type="block") AND NOT EXISTS (SELECT 1 FROM travel_match_actions done WHERE done.actor_user_id=? AND done.target_user_id=tmp.user_id AND done.action_type IN ("like","pass")) ORDER BY tmp.last_match_active_at DESC,tmp.updated_at DESC LIMIT '.$queryLimit);
        $stmt->execute([$userId,$ageMin,$ageMax,$userId,$userId,$userId]);$rows=$stmt->fetchAll();$out=[];
        foreach($rows as $row){if(!$this->modeCompatible((string)$mine['match_mode'],(string)$row['match_mode']))continue;if(!$this->audienceCompatible($mine,$row))continue;if($pace!=='any'&&(string)$row['travel_pace']!==$pace)continue;if($recent&&(!$row['last_match_active_at']||strtotime((string)$row['last_match_active_at'])<time()-7*86400))continue;if(!$this->locationCompatible($mine,$row,$scope))continue;$row['dealbreakers']=$this->decode((string)($row['dealbreakers_json']??''));$row['activity_label']=$this->activityLabel($row);$out[]=$row;}
        if(!$out)return[];
        $ids=array_map(fn($row)=>(int)$row['user_id'],$out);$archetypes=$this->candidateArchetypes($ids);$previews=$this->candidatePromptPreviews($ids);$photos=$this->candidatePhotos($ids);$traitSimilarity=$this->candidateTraitSimilarity($userId,$ids);$feedback=$this->qualityFeedbackProfile($userId);
        foreach($out as &$row){$id=(int)$row['user_id'];$row['archetype']=$archetypes[$id]??null;$row['prompt_preview']=$previews[$id]??null;$row['photos']=$photos[$id]??[];$rank=35.0;$reasons=[];
            $ready=(int)($row['profile_readiness']??0);$rank+=min(12,$ready*.12);if($ready>=80)$reasons[]='Complete travel profile';
            $sim=(float)($traitSimilarity[$id]??50);$rank+=($sim/100)*22;if($sim>=78)$reasons[]='Strong travel-style overlap';
            if((string)$mine['travel_pace']===(string)$row['travel_pace']){$rank+=8;$reasons[]='Similar vacation pace';}elseif(($feedback['different_pace']??0)>0)$rank-=8;
            if((string)$mine['budget_style']===(string)$row['budget_style']){$rank+=7;$reasons[]='Similar spending style';}elseif(($feedback['different_budget']??0)>0)$rank-=8;
            if($this->sameText($mine['favorite_destination']??'',$row['favorite_destination']??'')){$rank+=7;$reasons[]='Same dream destination';}
            if($this->sameText($mine['home_city']??'',$row['home_city']??'')){$rank+=5;$reasons[]='Nearby Vacation Brain';}elseif(($feedback['too_far']??0)>1)$rank-=4;
            if(!empty($row['last_match_active_at'])){$delta=time()-strtotime((string)$row['last_match_active_at']);if($delta<86400){$rank+=8;$reasons[]='Active today';}elseif($delta<7*86400){$rank+=4;$reasons[]='Active this week';}}
            if(!empty($row['prompt_preview']))$rank+=3;if(count($row['photos'])>=2)$rank+=2;$row['discovery_rank']=$rank;$row['rank_reasons']=array_slice(array_values(array_unique($reasons)),0,3);
        }unset($row);
        usort($out,fn($a,$b)=>($b['discovery_rank']<=>$a['discovery_rank'])?:strcmp((string)($b['last_match_active_at']??''),(string)($a['last_match_active_at']??'')));
        return array_slice($out,0,$limit);
    }

    public function compatibility(int $a,int $b): array
    {
        if($a===$b) return ['score'=>100,'agreements'=>[],'conflicts'=>[],'warning'=>'You are extremely compatible with yourself. Encouraging.'];
        $traitsA=$this->traits($a);$traitsB=$this->traits($b);$pieces=[];$weighted=0.0;$weightTotal=0.0;
        foreach(self::TRAIT_WEIGHTS as $slug=>$baseWeight){
            if(!isset($traitsA[$slug],$traitsB[$slug])) continue;
            $va=(float)$traitsA[$slug]['score'];$vb=(float)$traitsB[$slug]['score'];
            $confidence=min((float)$traitsA[$slug]['confidence'],(float)$traitsB[$slug]['confidence']);
            $weight=$baseWeight*(.55+.45*($confidence/100));$similarity=max(0,100-abs($va-$vb));
            $weighted += $similarity*$weight;$weightTotal += $weight;
            $pieces[]=['slug'=>$slug,'label'=>$traitsA[$slug]['name'],'a'=>(int)round($va),'b'=>(int)round($vb),'similarity'=>(int)round($similarity),'weight'=>$baseWeight];
        }
        $score=$weightTotal>0?(int)round($weighted/$weightTotal):50;
        $profileA=$this->profile($a);$profileB=$this->profile($b);
        if($profileA&&$profileB){
            if($profileA['travel_pace']===$profileB['travel_pace']) $score+=3; else $score-=3;
            if($profileA['budget_style']===$profileB['budget_style']) $score+=4; else $score-=5;
            $score += $this->dealbreakerAdjustment($profileA,$profileB);
        }
        $score=max(0,min(99,$score));
        usort($pieces,fn($x,$y)=>$y['similarity']<=>$x['similarity']);$agreements=array_slice($pieces,0,3);
        usort($pieces,fn($x,$y)=>$x['similarity']<=>$y['similarity']);$conflicts=array_slice(array_filter($pieces,fn($p)=>$p['similarity']<78),0,3);
        $warning=$this->warning($agreements,$conflicts,$score);
        return ['score'=>$score,'agreements'=>$agreements,'conflicts'=>$conflicts,'warning'=>$warning];
    }

    public function relevantResponses(int $viewerId,int $targetId,int $limit=8): array
    {
        $limit=max(1,min(20,$limit));
        $stmt=$this->pdo->prepare('SELECT ue.id AS event_id,ue.occurred_at,ci.id AS content_id,ci.title,ci.body,ci.short_body,ci.humor_level,ci.sarcasm_level,cc.id AS choice_id,cc.label AS choice_label,cc.description AS choice_description FROM user_events ue JOIN content_choices cc ON cc.id=ue.choice_id JOIN content_items ci ON ci.id=cc.content_id WHERE ue.user_id=? AND ue.event_type="choice_selected" AND ci.status="published" ORDER BY ue.occurred_at DESC,ue.id DESC LIMIT 250');
        $stmt->execute([$targetId]);$raw=$stmt->fetchAll();if(!$raw)return[];

        $answers=[];$choiceIds=[];$contentIds=[];$seenContent=[];
        foreach($raw as $row){$contentId=(int)$row['content_id'];if(isset($seenContent[$contentId]))continue;$seenContent[$contentId]=true;$answers[]=$row;$choiceIds[]=(int)$row['choice_id'];$contentIds[]=$contentId;}
        if(!$answers)return[];

        $contentPlaceholders=implode(',',array_fill(0,count($contentIds),'?'));
        $viewerStmt=$this->pdo->prepare('SELECT ue.id,cc.content_id,cc.id AS choice_id,cc.label AS choice_label FROM user_events ue JOIN content_choices cc ON cc.id=ue.choice_id WHERE ue.user_id=? AND ue.event_type="choice_selected" AND cc.content_id IN ('.$contentPlaceholders.') ORDER BY ue.occurred_at DESC,ue.id DESC');
        $viewerStmt->execute(array_merge([$viewerId],$contentIds));$viewerAnswers=[];
        foreach($viewerStmt->fetchAll() as $row){$cid=(int)$row['content_id'];if(!isset($viewerAnswers[$cid]))$viewerAnswers[$cid]=$row;}

        $choicePlaceholders=implode(',',array_fill(0,count($choiceIds),'?'));
        $effectsStmt=$this->pdo->prepare('SELECT cte.choice_id,t.slug,t.name,cte.score_delta FROM choice_trait_effects cte JOIN traits t ON t.id=cte.trait_id WHERE cte.choice_id IN ('.$choicePlaceholders.')');
        $effectsStmt->execute($choiceIds);$effectsByChoice=[];
        foreach($effectsStmt->fetchAll() as $effect){$effectsByChoice[(int)$effect['choice_id']][]=$effect;}

        $viewerTraits=$this->traits($viewerId);$targetTraits=$this->traits($targetId);$ranked=[];
        foreach($answers as $answer){
            $effects=$effectsByChoice[(int)$answer['choice_id']]??[];$sameQuestion=$viewerAnswers[(int)$answer['content_id']]??null;
            $bestRelation='interesting';$bestTrait=null;$bestTraitScore=-INF;$score=((int)$answer['humor_level']*2.0)+((int)$answer['sarcasm_level']*.75);
            foreach($effects as $effect){
                $slug=(string)$effect['slug'];if(!isset($viewerTraits[$slug],$targetTraits[$slug]))continue;
                $viewer=(float)$viewerTraits[$slug]['score'];$target=(float)$targetTraits[$slug]['score'];
                $viewerStrength=min(1.0,abs($viewer-50)/50);$targetStrength=min(1.0,abs($target-50)/50);$similarity=max(0.0,100-abs($viewer-$target));$gap=100-$similarity;
                $effectStrength=min(1.0,abs((float)$effect['score_delta'])/10);$traitWeight=(float)(self::TRAIT_WEIGHTS[$slug]??.65);
                if($similarity>=82){$relation='agreement';$relationScore=22+($similarity-82)*.45;}elseif($similarity<=62){$relation='difference';$relationScore=24+($gap-38)*.5;}else{$relation='interesting';$relationScore=9;}
                $traitScore=$relationScore+($viewerStrength*18)+($targetStrength*10)+($effectStrength*9)+($traitWeight*5);$score+=$traitScore;
                if($traitScore>$bestTraitScore){$bestTraitScore=$traitScore;$bestRelation=$relation;$bestTrait=['slug'=>$slug,'name'=>(string)$effect['name'],'viewer'=>(int)round($viewer),'target'=>(int)round($target),'similarity'=>(int)round($similarity)];}
            }
            if(!$bestTrait)continue;

            $viewerChoice=null;
            if($sameQuestion){
                $viewerChoice=(string)$sameQuestion['choice_label'];$score+=38;
                if((int)$sameQuestion['choice_id']===(int)$answer['choice_id']){$bestRelation='agreement';$score+=18;$context='You picked the exact same answer. Vacation Brain considers this either compatibility or coordinated bad judgment.';}
                else{$bestRelation='difference';$score+=14;$context='You answered this one differently. This is the kind of tiny vacation opinion that becomes very important around day three.';}
            } else {
                $context=match($bestRelation){'agreement'=>'You two land almost exactly the same way on '.strtolower($bestTrait['name']).'.','difference'=>'Potential vacation argument: your '.strtolower($bestTrait['name']).' instincts are very different.',default=>'This answer touches '.strtolower($bestTrait['name']).', which matters to both of your travel styles.'};
            }
            $answer['relevance_score']=$score;$answer['relation']=$bestRelation;$answer['trait']=$bestTrait;$answer['context']=$context;$answer['viewer_choice_label']=$viewerChoice;$ranked[]=$answer;
        }
        usort($ranked,fn($a,$b)=>$b['relevance_score']<=>$a['relevance_score']);

        $selected=[];$traitCounts=[];
        foreach($ranked as $row){$slug=(string)$row['trait']['slug'];if(($traitCounts[$slug]??0)>=2)continue;$selected[]=$row;$traitCounts[$slug]=($traitCounts[$slug]??0)+1;if(count($selected)>=$limit)break;}
        return $selected;
    }

    public function act(int $actor,int $target,string $action): array
    {
        if($actor===$target) throw new InvalidArgumentException('You cannot Travel Match with yourself.');
        if(!in_array($action,['like','pass','block'],true)) throw new InvalidArgumentException('Unknown matching action.');
        $actorProfile=$this->profile($actor);$targetProfile=$this->profile($target);if($action!=='block' && (!$actorProfile || empty($actorProfile['enabled']))) throw new InvalidArgumentException('Enroll in Travel Matching before liking or passing profiles.');if($action!=='block' && (!$targetProfile || empty($targetProfile['enabled']))) throw new InvalidArgumentException('That Travel Match profile is not available.');
        $this->pdo->prepare('INSERT INTO travel_match_actions (actor_user_id,target_user_id,action_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE action_type=VALUES(action_type),updated_at=NOW()')->execute([$actor,$target,$action]);
        if($action==='like'){
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric) VALUES (? ,"travel_match_like",?)')->execute([$actor,$target]);
            (new ScoreService($this->pdo))->award($actor,'travel_match_like',1);
        }
        $matched=false;$matchId=null;$newMatch=false;
        if($action==='block'){
            [$a,$b]=$actor<$target?[$actor,$target]:[$target,$actor];
            $this->pdo->prepare('UPDATE travel_matches SET status="blocked",updated_at=NOW() WHERE user_a_id=? AND user_b_id=?')->execute([$a,$b]);
        }
        if($action==='like'){
            $reverse=$this->pdo->prepare('SELECT 1 FROM travel_match_actions WHERE actor_user_id=? AND target_user_id=? AND action_type="like"');$reverse->execute([$target,$actor]);
            if($reverse->fetchColumn()){
                [$a,$b]=$actor<$target?[$actor,$target]:[$target,$actor];$compat=$this->compatibility($a,$b);
                $existing=$this->pdo->prepare('SELECT id FROM travel_matches WHERE user_a_id=? AND user_b_id=?');$existing->execute([$a,$b]);$existingId=$existing->fetchColumn();
                $this->pdo->prepare('INSERT INTO travel_matches (user_a_id,user_b_id,compatibility_score,status) VALUES (?,?,?,"active") ON DUPLICATE KEY UPDATE compatibility_score=VALUES(compatibility_score),status="active",updated_at=NOW()')->execute([$a,$b,$compat['score']]);
                $q=$this->pdo->prepare('SELECT id FROM travel_matches WHERE user_a_id=? AND user_b_id=?');$q->execute([$a,$b]);$matchId=(int)$q->fetchColumn();$matched=true;$newMatch=!$existingId;
                if(!$existingId){
                    $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric) VALUES (? ,"travel_match_mutual",?),(? ,"travel_match_mutual",?)')->execute([$actor,$target,$target,$actor]);
                    (new ScoreService($this->pdo))->award($actor,'travel_match_mutual',15);(new ScoreService($this->pdo))->award($target,'travel_match_mutual',15);
                    (new AchievementService($this->pdo))->evaluate($actor);(new AchievementService($this->pdo))->evaluate($target);
                    $names=$this->pdo->prepare('SELECT id,display_name FROM users WHERE id IN (?,?)');$names->execute([$actor,$target]);$by=[];foreach($names->fetchAll() as $n)$by[(int)$n['id']]=(string)$n['display_name'];$notifications=new NotificationService($this->pdo);
                    $notifications->create($actor,'travel_match','It’s a Travel Match','You and '.($by[$target]??'another Vacation Brain').' both said yes.','match-chat.php?match='.$matchId,$target,$matchId,'match-'.$matchId.'-'.$actor);
                    $notifications->create($target,'travel_match','It’s a Travel Match','You and '.($by[$actor]??'another Vacation Brain').' both said yes.','match-chat.php?match='.$matchId,$actor,$matchId,'match-'.$matchId.'-'.$target);
                }
            }
        }
        return ['matched'=>$matched,'match_id'=>$matchId,'new_match'=>$newMatch];
    }

    public function actionFor(int $actor,int $target): ?string
    { $s=$this->pdo->prepare('SELECT action_type FROM travel_match_actions WHERE actor_user_id=? AND target_user_id=?');$s->execute([$actor,$target]);$v=$s->fetchColumn();return $v?(string)$v:null; }

    public function matches(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT tm.*,CASE WHEN tm.user_a_id=? THEN tm.user_b_id ELSE tm.user_a_id END partner_id FROM travel_matches tm WHERE (tm.user_a_id=? OR tm.user_b_id=?) AND tm.status="active" ORDER BY tm.matched_at DESC');
        $stmt->execute([$userId,$userId,$userId]);$rows=$stmt->fetchAll();
        foreach($rows as &$row){$row['partner']=$this->profile((int)$row['partner_id']);$row['compatibility']=['score'=>(int)($row['compatibility_score']??50)];}unset($row);
        return $rows;
    }

    public function unseenMatch(int $userId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT tm.*,CASE WHEN tm.user_a_id=? THEN tm.user_b_id ELSE tm.user_a_id END partner_id FROM travel_matches tm WHERE tm.status="active" AND ((tm.user_a_id=? AND tm.user_a_seen_at IS NULL) OR (tm.user_b_id=? AND tm.user_b_seen_at IS NULL)) ORDER BY tm.matched_at DESC LIMIT 1');
        $stmt->execute([$userId,$userId,$userId]);$row=$stmt->fetch();if(!$row)return null;$row['partner']=$this->profile((int)$row['partner_id']);$row['compatibility']=$this->compatibility($userId,(int)$row['partner_id']);return $row;
    }

    public function markMatchSeen(int $matchId,int $userId): void
    {
        $match=$this->pdo->prepare('SELECT user_a_id,user_b_id FROM travel_matches WHERE id=? AND status="active"');$match->execute([$matchId]);$row=$match->fetch();if(!$row)return;if((int)$row['user_a_id']===$userId)$this->pdo->prepare('UPDATE travel_matches SET user_a_seen_at=COALESCE(user_a_seen_at,NOW()) WHERE id=?')->execute([$matchId]);elseif((int)$row['user_b_id']===$userId)$this->pdo->prepare('UPDATE travel_matches SET user_b_seen_at=COALESCE(user_b_seen_at,NOW()) WHERE id=?')->execute([$matchId]);
    }

    public function createInvite(int $creator): array
    {
        $token=bin2hex(random_bytes(20));$this->pdo->prepare('INSERT INTO travel_match_invites (creator_user_id,invite_token,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))')->execute([$creator,$token]);
        return ['token'=>$token,'url'=>app_url('match-invite.php?token='.$token)];
    }

    public function invite(string $token): ?array
    { $s=$this->pdo->prepare('SELECT * FROM travel_match_invites WHERE invite_token=? AND status IN ("open","claimed") AND (expires_at IS NULL OR expires_at>NOW())');$s->execute([$token]);return $s->fetch()?:null; }

    public function claimInvite(string $token,int $userId): ?int
    {
        $invite=$this->invite($token);if(!$invite) return null;if((int)$invite['creator_user_id']===$userId) return (int)$invite['creator_user_id'];
        if($invite['claimed_user_id'] && (int)$invite['claimed_user_id']!==$userId) return null;
        if(!$invite['claimed_user_id'])$this->pdo->prepare('UPDATE travel_match_invites SET claimed_user_id=?,status="claimed",claimed_at=NOW() WHERE id=? AND claimed_user_id IS NULL')->execute([$userId,$invite['id']]);
        return (int)$invite['creator_user_id'];
    }

    public function createGame(int $creator,?int $partner,string $type,?int $matchId=null): array
    {
        if(!isset($this->gameCatalog()[$type])) throw new InvalidArgumentException('Unknown compatibility game.');
        if($partner===$creator) throw new InvalidArgumentException('Pick another Vacation Brain.');
        $token=bin2hex(random_bytes(20));$this->pdo->prepare('INSERT INTO travel_match_games (match_id,creator_user_id,partner_user_id,invite_token,game_type) VALUES (?,?,?,?,?)')->execute([$matchId,$creator,$partner,$token,$type]);
        return ['id'=>(int)$this->pdo->lastInsertId(),'token'=>$token];
    }

    public function gameByToken(string $token): ?array
    { $s=$this->pdo->prepare('SELECT * FROM travel_match_games WHERE invite_token=?');$s->execute([$token]);return $s->fetch()?:null; }

    public function gameCatalog(): array
    {
        return [
            'airport-survival'=>['title'=>'Would We Survive the Airport?','description'=>'Five decisions between you and Gate B37.','questions'=>[
                ['key'=>'arrival','q'=>'How early are we arriving?','options'=>['3h'=>'Three hours. I need emotional runway.','90m'=>'About 90 minutes. Civilized.','45m'=>'Forty-five minutes. Plenty of time.']],
                ['key'=>'delay','q'=>'Flight delayed two hours. First move?','options'=>['bar'=>'Find the airport bar.','lounge'=>'Investigate a lounge.','gate'=>'Stay near the gate and complain efficiently.']],
                ['key'=>'seat','q'=>'Only one window seat. Who gets it?','options'=>['rotate'=>'Rotate by flight segment.','me'=>'Me. This is not a democracy.','them'=>'You take it. I will nap.']],
                ['key'=>'connection','q'=>'We can save $280 each with a 3-hour connection.','options'=>['save'=>'Take the savings.','direct'=>'Pay for the direct flight.','depends'=>'Depends how good the airport food is.']],
                ['key'=>'boarding','q'=>'Boarding group called. What are we doing?','options'=>['line'=>'Standing immediately.','wait'=>'Waiting until the line dies down.','snack'=>'Apparently buying one last snack.']],
            ]],
            'first-trip'=>['title'=>'Pick Our First Trip','description'=>'Five choices. One imaginary first vacation.','questions'=>[
                ['key'=>'setting','q'=>'Pick the setting.','options'=>['beach'=>'Warm beach','city'=>'Big food city','mountain'=>'Mountain escape']],
                ['key'=>'hotel','q'=>'Where are we sleeping?','options'=>['resort'=>'Full-service resort','boutique'=>'Boutique hotel','rental'=>'Apartment or rental']],
                ['key'=>'pace','q'=>'How packed is the itinerary?','options'=>['slow'=>'One real thing per day','balanced'=>'A couple plans and room to wander','packed'=>'We came here to do things']],
                ['key'=>'splurge','q'=>'Choose the splurge.','options'=>['room'=>'Better room','meal'=>'Ridiculous dinner','activity'=>'Once-in-a-lifetime activity']],
                ['key'=>'morning','q'=>'First morning alarm?','options'=>['none'=>'There is no alarm','nine'=>'9:00 AM is acceptable','sunrise'=>'We are catching sunrise']],
            ]],
            'budget-battle'=>['title'=>'Budget Battle','description'=>'Five tiny money decisions that could save a trip or start an argument.','questions'=>[
                ['key'=>'flight_upgrade','q'=>'The nonstop flight costs $240 more each.','options'=>['pay'=>'Pay it. Time is vacation currency.','save'=>'Take the connection and save the money.','depends'=>'Depends on connection length.']],
                ['key'=>'hotel_view','q'=>'Ocean view is $90 more per night.','options'=>['yes'=>'Absolutely. I came for the ocean.','no'=>'I can walk outside and see it for free.','some'=>'Upgrade only a couple nights.']],
                ['key'=>'dinner','q'=>'One ridiculous dinner costs $180 per person.','options'=>['book'=>'Book it. Memories have a menu.','skip'=>'No meal needs a financing plan.','lunch'=>'Do the fancy place at lunch.']],
                ['key'=>'activity','q'=>'Private tour is triple the group-tour price.','options'=>['private'=>'Privacy is the upgrade.','group'=>'I enjoy keeping money.','none'=>'I will explore without a clipboard.']],
                ['key'=>'daily','q'=>'How closely are we tracking daily vacation spending?','options'=>['track'=>'We have a budget and it has opinions.','rough'=>'Roughly. No spreadsheets at dinner.','ignore'=>'That sounds like a problem for Future Me.']],
            ]],
            'hotel-room'=>['title'=>'Hotel Room Test','description'=>'Five room-sharing decisions before somebody touches the thermostat.','questions'=>[
                ['key'=>'temp','q'=>'Hotel room temperature?','options'=>['cold'=>'Cold enough to require blankets.','normal'=>'Normal human temperature.','warm'=>'Warm. I did not travel to shiver.']],
                ['key'=>'alarm','q'=>'One person needs an early alarm.','options'=>['fine'=>'Fine. I can go back to sleep.','vibrate'=>'Phone vibration only.','separate'=>'That sounds like a separate-room issue.']],
                ['key'=>'mess','q'=>'Suitcase strategy?','options'=>['unpack'=>'Unpack into drawers.','organized'=>'Live from suitcase, but civilized.','explode'=>'Open suitcase. Allow clothing ecosystem to develop.']],
                ['key'=>'bathroom','q'=>'Bathroom schedule before dinner?','options'=>['plan'=>'We coordinate like adults.','whoever'=>'First person ready gets it.','spa'=>'I am taking as long as I take.']],
                ['key'=>'tv','q'=>'Hotel TV at bedtime?','options'=>['off'=>'Silence and darkness.','low'=>'Something mindless on low.','on'=>'Vacation TV is part of the experience.']],
            ]],
            'red-flags'=>['title'=>'Vacation Red Flags','description'=>'The tiny choices that become large arguments somewhere around day three.','questions'=>[
                ['key'=>'work','q'=>'Checking work email on vacation?','options'=>['never'=>'Absolutely not','once'=>'Once a day max','fine'=>'If needed, it is fine']],
                ['key'=>'budget','q'=>'Unexpected $300 upgrade appears.','options'=>['no'=>'Keep the money','maybe'=>'Pitch me the value','yes'=>'We are already here']],
                ['key'=>'late','q'=>'Someone is 25 minutes late for dinner.','options'=>['wait'=>'We wait','text'=>'One text, then order','leave'=>'They know where the restaurant is']],
                ['key'=>'plan','q'=>'One person changes tomorrow’s plan at 11 PM.','options'=>['fine'=>'Spontaneity!','vote'=>'We vote','no'=>'The itinerary has rights']],
                ['key'=>'morning2','q'=>'A 6 AM excursion is proposed.','options'=>['yes'=>'Adventure requires sacrifice','maybe'=>'Only if it is extraordinary','no'=>'This is vacation misconduct']],
            ]],
        ];
    }

    public function answerGame(array $game,int $userId,array $answers): void
    {
        if((int)$game['creator_user_id']!==$userId && !empty($game['partner_user_id']) && (int)$game['partner_user_id']!==$userId) {
            throw new RuntimeException('This compatibility game belongs to someone else.');
        }
        if(empty($game['partner_user_id']) && (int)$game['creator_user_id']!==$userId) {
            $this->pdo->prepare('UPDATE travel_match_games SET partner_user_id=? WHERE id=? AND partner_user_id IS NULL')->execute([$userId,$game['id']]);
        }
        $catalog=$this->gameCatalog();
        $questions=$catalog[$game['game_type']]['questions']??[];
        $valid=[];
        foreach($questions as $q){
            $choice=(string)($answers[$q['key']]??'');
            if(!array_key_exists($choice,$q['options'])) throw new InvalidArgumentException('Answer every compatibility question.');
            $valid[$q['key']]=$choice;
        }
        $this->pdo->prepare('INSERT INTO travel_match_game_answers (game_id,user_id,answers_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE answers_json=VALUES(answers_json),answered_at=NOW()')->execute([$game['id'],$userId,json_encode($valid)]);
        if(!empty($game['match_id'])) {
            try {(new MatchEngagementService($this->pdo))->recordActivity((int)$game['match_id'],$userId,'mini_game');} catch(Throwable $e) {}
        }
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM travel_match_game_answers WHERE game_id=?');
        $count->execute([$game['id']]);
        if((int)$count->fetchColumn()>=2){
            $done=$this->pdo->prepare('UPDATE travel_match_games SET status="completed",completed_at=NOW() WHERE id=? AND status<>"completed"');
            $done->execute([$game['id']]);
            if($done->rowCount()){
                $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric) SELECT user_id,"travel_match_game_completed",? FROM travel_match_game_answers WHERE game_id=?')->execute([$game['id'],$game['id']]);
                foreach($this->gameParticipants((int)$game['id']) as $uid){
                    (new ScoreService($this->pdo))->award($uid,'travel_match_game_completed',8);
                    (new AchievementService($this->pdo))->evaluate($uid);
                }
            }
            if(!empty($game['match_id'])) {
                try {(new MatchEngagementService($this->pdo))->refreshInsights((int)$game['match_id'],$userId);} catch(Throwable $e) {}
            }
        }
    }

    public function gameResult(array $game): ?array
    {
        $s=$this->pdo->prepare('SELECT user_id,answers_json FROM travel_match_game_answers WHERE game_id=? ORDER BY answered_at');$s->execute([$game['id']]);$rows=$s->fetchAll();if(count($rows)<2)return null;
        $a=$this->decode($rows[0]['answers_json']);$b=$this->decode($rows[1]['answers_json']);$same=0;$diff=[];$catalog=$this->gameCatalog()[$game['game_type']];
        foreach($catalog['questions'] as $q){if(($a[$q['key']]??null)===($b[$q['key']]??null))$same++;else$diff[]=$q['q'];}
        $pct=(int)round(($same/max(1,count($catalog['questions'])))*100);
        $line=$pct>=80?'Disturbingly compatible. You may proceed to arguing about packing.':($pct>=60?'Strong potential. A few negotiations may be required near the airport.':($pct>=40?'Workable, provided someone is willing to compromise.':'This trip may require separate itineraries and possibly separate terminals.'));
        return ['score'=>$pct,'same'=>$same,'total'=>count($catalog['questions']),'differences'=>$diff,'line'=>$line,'users'=>[(int)$rows[0]['user_id'],(int)$rows[1]['user_id']]];
    }

    public function report(int $reporter,int $reported,string $reason,string $details=''): void
    {
        if($reporter===$reported) throw new InvalidArgumentException('You cannot report yourself.');
        $allowed=['harassment','spam','fake_profile','unsafe_behavior','underage_concern','other'];if(!in_array($reason,$allowed,true))$reason='other';
        $details=trim($details);if(strlen($details)>1000)$details=substr($details,0,1000);
        $this->pdo->prepare('INSERT INTO travel_match_reports (reporter_user_id,reported_user_id,reason,details) VALUES (?,?,?,?)')->execute([$reporter,$reported,$reason,$details?:null]);
        $this->act($reporter,$reported,'block');
    }

    private function validatePhotoUrl(string $url): void
    {
        if(str_starts_with($url,'/uploads/')) return;
        if(!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)) throw new InvalidArgumentException('Use a valid Vacation Brain profile image.');
    }

    private function candidatePhotos(array $userIds): array
    {
        $userIds=array_values(array_unique(array_filter(array_map('intval',$userIds))));if(!$userIds)return[];
        $ph=implode(',',array_fill(0,count($userIds),'?'));
        $stmt=$this->pdo->prepare('SELECT user_id,photo_url,sort_order FROM travel_match_profile_photos WHERE user_id IN ('.$ph.') ORDER BY user_id,sort_order,id');
        $stmt->execute($userIds);$out=[];
        foreach($stmt->fetchAll() as $row){$uid=(int)$row['user_id'];if(count($out[$uid]??[])>=4)continue;$out[$uid][]=(string)$row['photo_url'];}
        return $out;
    }

    private function candidatePromptPreviews(array $userIds): array
    {
        $userIds=array_values(array_unique(array_filter(array_map('intval',$userIds))));if(!$userIds)return[];$ph=implode(',',array_fill(0,count($userIds),'?'));
        $stmt=$this->pdo->prepare('SELECT p.user_id,p.prompt_key,p.answer_text FROM travel_match_profile_prompts p JOIN (SELECT user_id,MIN(sort_order) min_sort FROM travel_match_profile_prompts WHERE user_id IN ('.$ph.') GROUP BY user_id) x ON x.user_id=p.user_id AND x.min_sort=p.sort_order');$stmt->execute($userIds);$catalog=$this->promptCatalog();$out=[];
        foreach($stmt->fetchAll() as $row)$out[(int)$row['user_id']]=['label'=>$catalog[(string)$row['prompt_key']]??'Vacation opinion','answer'=>(string)$row['answer_text']];return$out;
    }

    private function locationCompatible(array $viewer,array $candidate,string $scope): bool
    {
        if($scope==='anywhere')return true;
        $same=function($a,$b):bool{return trim(strtolower((string)$a))!==''&&trim(strtolower((string)$a))===trim(strtolower((string)$b));};
        if($scope==='country')return $same($viewer['home_country']??'',$candidate['home_country']??'');
        if($scope==='region')return $same($viewer['home_country']??'',$candidate['home_country']??'')&&$same($viewer['home_region']??'',$candidate['home_region']??'');
        return $same($viewer['home_country']??'',$candidate['home_country']??'')&&$same($viewer['home_region']??'',$candidate['home_region']??'')&&$same($viewer['home_city']??'',$candidate['home_city']??'');
    }

    private function activityLabel(array $profile): ?string
    {
        if(empty($profile['show_activity_status']) || empty($profile['last_match_active_at']))return null;$ts=strtotime((string)$profile['last_match_active_at']);if(!$ts)return null;$delta=time()-$ts;
        if($delta<3600)return 'Active recently';if(date('Y-m-d',$ts)===date('Y-m-d'))return 'Active today';if($delta<7*86400)return 'Active this week';return null;
    }

    private function candidateTraitSimilarity(int $viewerId,array $userIds): array
    {
        $viewer=$this->traits($viewerId);$userIds=array_values(array_unique(array_filter(array_map('intval',$userIds))));if(!$viewer||!$userIds)return[];$ph=implode(',',array_fill(0,count($userIds),'?'));
        $s=$this->pdo->prepare('SELECT ut.user_id,t.slug,ut.score,ut.confidence FROM user_traits ut JOIN traits t ON t.id=ut.trait_id WHERE ut.user_id IN ('.$ph.')');$s->execute($userIds);$sum=[];$weight=[];
        foreach($s->fetchAll() as $r){$slug=(string)$r['slug'];if(!isset(self::TRAIT_WEIGHTS[$slug],$viewer[$slug]))continue;$w=(float)self::TRAIT_WEIGHTS[$slug]*(.5+.5*(min((float)$viewer[$slug]['confidence'],(float)$r['confidence'])/100));$sim=max(0,100-abs((float)$viewer[$slug]['score']-(float)$r['score']));$id=(int)$r['user_id'];$sum[$id]=($sum[$id]??0)+$sim*$w;$weight[$id]=($weight[$id]??0)+$w;}
        $out=[];foreach($userIds as $id)$out[$id]=($weight[$id]??0)>0?($sum[$id]/$weight[$id]):50;return$out;
    }

    private function qualityFeedbackProfile(int $userId): array
    { $s=$this->pdo->prepare('SELECT reason,COUNT(*) c FROM travel_match_quality_feedback WHERE actor_user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 180 DAY) GROUP BY reason');$s->execute([$userId]);$out=[];foreach($s->fetchAll() as $r)$out[(string)$r['reason']]=(int)$r['c'];return$out; }
    private function sameText($a,$b): bool { $a=trim(strtolower((string)$a));$b=trim(strtolower((string)$b));return $a!==''&&$a===$b; }

    private function candidateArchetypes(array $userIds): array
    {
        $userIds=array_values(array_unique(array_filter(array_map('intval',$userIds))));if(!$userIds)return[];
        $placeholders=implode(',',array_fill(0,count($userIds),'?'));
        $stmt=$this->pdo->prepare('SELECT ut.user_id,t.slug,t.name,t.trait_group,ut.score,ut.confidence,ut.interaction_count FROM user_traits ut JOIN traits t ON t.id=ut.trait_id WHERE ut.user_id IN ('.$placeholders.')');
        $stmt->execute($userIds);$grouped=[];
        foreach($stmt->fetchAll() as $row){$grouped[(int)$row['user_id']][(string)$row['slug']]=$row;}
        $profileService=new VacationProfileService($this->pdo);$out=[];
        foreach($userIds as $id){$out[$id]=$profileService->archetype($grouped[$id]??[]);}
        return $out;
    }

    private function audienceCompatible(array $viewer,array $candidate): bool
    {
        $accept=function(string $show,string $gender): bool { if($show==='everyone')return true;return ($show==='women'&&$gender==='woman')||($show==='men'&&$gender==='man')||($show==='nonbinary'&&$gender==='nonbinary'); };
        $viewerPref=(string)($viewer['partner_gender']??$viewer['show_me']??'everyone');$candidatePref=(string)($candidate['partner_gender']??$candidate['show_me']??'everyone');
        return $accept($viewerPref,(string)$candidate['gender_identity']) && $accept($candidatePref,(string)$viewer['gender_identity']);
    }

    private function gameParticipants(int $gameId): array
    { $s=$this->pdo->prepare('SELECT user_id FROM travel_match_game_answers WHERE game_id=?');$s->execute([$gameId]);return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)); }
    private function traits(int $userId): array
    { $s=$this->pdo->prepare('SELECT t.slug,t.name,ut.score,ut.confidence FROM user_traits ut JOIN traits t ON t.id=ut.trait_id WHERE ut.user_id=?');$s->execute([$userId]);$out=[];foreach($s->fetchAll() as $r)$out[$r['slug']]=$r;return $out; }
    private function modeCompatible(string $a,string $b): bool { return $a==='either'||$b==='either'||$a===$b; }
    private function decode(string $json): array { if($json==='')return[];$v=json_decode($json,true);return is_array($v)?$v:[]; }
    private function dealbreakerAdjustment(array $a,array $b): int
    {
        $adj=0;$da=$a['dealbreakers']??[];$db=$b['dealbreakers']??[];
        if(($da['no_sunrise']??false)&&$b['travel_pace']==='packed')$adj-=3;if(($db['no_sunrise']??false)&&$a['travel_pace']==='packed')$adj-=3;
        if(($da['budget_matters']??false)&&$a['budget_style']!==$b['budget_style'])$adj-=4;if(($db['budget_matters']??false)&&$a['budget_style']!==$b['budget_style'])$adj-=4;
        if(($da['spontaneous_ok']??false)&&($db['spontaneous_ok']??false))$adj+=2;if(($da['nightlife_ok']??false)&&($db['nightlife_ok']??false))$adj+=2;
        return $adj;
    }
    private function warning(array $agreements,array $conflicts,int $score): string
    {
        if($conflicts){$c=$conflicts[0];return 'You may need a treaty concerning '.strtolower((string)$c['label']).'.';}
        if($agreements){$a=$agreements[0];return 'You agree suspiciously well on '.strtolower((string)$a['label']).'.';}
        return $score>=75?'This looks promising. Do not ruin it by suggesting a 6 AM excursion.':'More data required. Please continue having unnecessary vacation opinions.';
    }
}
