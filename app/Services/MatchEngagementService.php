<?php
declare(strict_types=1);

final class MatchEngagementService
{
    public function __construct(private PDO $pdo) {}

    public function match(int $matchId,int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT tm.*,CASE WHEN tm.user_a_id=? THEN tm.user_b_id ELSE tm.user_a_id END partner_id FROM travel_matches tm WHERE tm.id=? AND (tm.user_a_id=? OR tm.user_b_id=?) AND tm.status="active" LIMIT 1');
        $stmt->execute([$userId,$matchId,$userId,$userId]);
        $row=$stmt->fetch();
        if(!$row) throw new RuntimeException('Travel Match not available.');
        return $row;
    }

    public function recordActivity(int $matchId,int $userId,string $type): void
    {
        $this->match($matchId,$userId);
        $type=preg_replace('/[^a-z0-9_\-]/i','',strtolower($type)) ?: 'activity';
        $stmt=$this->pdo->prepare('INSERT INTO travel_match_activity_days (match_id,user_id,activity_date,activity_type,activity_count) VALUES (?,?,CURDATE(),?,1) ON DUPLICATE KEY UPDATE last_at=NOW(),activity_count=activity_count+1');
        $stmt->execute([$matchId,$userId,$type]);
    }

    public function streak(int $matchId,int $userId): array
    {
        $match=$this->match($matchId,$userId);
        $stmt=$this->pdo->prepare('SELECT activity_date,COUNT(DISTINCT user_id) participants FROM travel_match_activity_days WHERE match_id=? AND user_id IN (?,?) GROUP BY activity_date HAVING participants=2 ORDER BY activity_date DESC LIMIT 366');
        $stmt->execute([$matchId,(int)$match['user_a_id'],(int)$match['user_b_id']]);
        $dates=array_map(fn($r)=>(string)$r['activity_date'],$stmt->fetchAll());
        $set=array_fill_keys($dates,true);
        $today=new DateTimeImmutable('today');
        $cursor=isset($set[$today->format('Y-m-d')])?$today:$today->modify('-1 day');
        $count=0;
        while(isset($set[$cursor->format('Y-m-d')])){$count++;$cursor=$cursor->modify('-1 day');}
        return ['current'=>$count,'shared_days'=>count($dates),'active_today'=>isset($set[$today->format('Y-m-d')])];
    }

    public function dailyQuestion(int $matchId,int $userId): array
    {
        $match=$this->match($matchId,$userId);
        $date=date('Y-m-d');
        $stmt=$this->pdo->prepare('SELECT * FROM travel_match_daily_questions WHERE match_id=? AND question_date=? LIMIT 1');
        $stmt->execute([$matchId,$date]);$row=$stmt->fetch();
        if(!$row){
            $pick=$this->pdo->prepare('SELECT ci.id FROM content_items ci WHERE ci.status="published" AND ci.content_type IN ("swipe_question","scenario","would_you_rather","this_or_that","destination_prompt") AND (SELECT COUNT(*) FROM content_choices cc WHERE cc.content_id=ci.id)>=2 AND NOT EXISTS (SELECT 1 FROM travel_match_daily_questions mdq WHERE mdq.match_id=? AND mdq.content_id=ci.id) ORDER BY CRC32(CONCAT(ci.id,?,?)) LIMIT 1');
            $pick->execute([$matchId,$matchId,$date]);$contentId=(int)($pick->fetchColumn()?:0);
            if(!$contentId){
                $fallback=$this->pdo->query('SELECT ci.id FROM content_items ci WHERE ci.status="published" AND (SELECT COUNT(*) FROM content_choices cc WHERE cc.content_id=ci.id)>=2 ORDER BY ci.id LIMIT 1');
                $contentId=(int)($fallback->fetchColumn()?:0);
            }
            if(!$contentId) throw new RuntimeException('No daily Travel Match questions are available yet.');
            $ins=$this->pdo->prepare('INSERT INTO travel_match_daily_questions (match_id,question_date,content_id) VALUES (?,?,?)');
            try{$ins->execute([$matchId,$date,$contentId]);}catch(Throwable $e){}
            $stmt->execute([$matchId,$date]);$row=$stmt->fetch();
        }
        if(!$row) throw new RuntimeException('Daily Travel Match question could not be loaded.');
        $content=$this->pdo->prepare('SELECT id,title,body,short_body FROM content_items WHERE id=?');$content->execute([$row['content_id']]);$item=$content->fetch();
        $choices=$this->pdo->prepare('SELECT id,label,description,sort_order FROM content_choices WHERE content_id=? ORDER BY sort_order,id');$choices->execute([$row['content_id']]);
        $answers=$this->pdo->prepare('SELECT a.user_id,a.choice_id,a.answered_at,c.label,u.display_name FROM travel_match_daily_answers a JOIN content_choices c ON c.id=a.choice_id JOIN users u ON u.id=a.user_id WHERE a.daily_question_id=? ORDER BY a.answered_at');$answers->execute([$row['id']]);$answerRows=$answers->fetchAll();
        $mine=null;foreach($answerRows as $a){if((int)$a['user_id']===$userId)$mine=$a;}
        $revealed=count($answerRows)>=2;
        return ['id'=>(int)$row['id'],'date'=>$date,'content'=>$item,'choices'=>$choices->fetchAll(),'answers'=>$revealed?$answerRows:($mine?[$mine]:[]),'mine'=>$mine,'revealed'=>$revealed,'partner_id'=>(int)$match['partner_id']];
    }

    public function answerDailyQuestion(int $matchId,int $userId,int $choiceId): array
    {
        $daily=$this->dailyQuestion($matchId,$userId);
        $valid=false;foreach($daily['choices'] as $choice){if((int)$choice['id']===$choiceId){$valid=true;break;}}
        if(!$valid) throw new InvalidArgumentException('Choose one of today’s answers.');
        $stmt=$this->pdo->prepare('INSERT INTO travel_match_daily_answers (daily_question_id,user_id,choice_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE choice_id=VALUES(choice_id),answered_at=NOW()');
        $stmt->execute([$daily['id'],$userId,$choiceId]);
        $this->recordActivity($matchId,$userId,'daily_question');
        $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric) VALUES (? ,"travel_match_daily_answer",?)')->execute([$userId,$matchId]);
        (new ScoreService($this->pdo))->award($userId,'travel_match_daily_answer',2);
        $this->refreshInsights($matchId,$userId);
        return $this->dailyQuestion($matchId,$userId);
    }

    public function icebreakers(int $matchId,int $userId,int $limit=5): array
    {
        $match=$this->match($matchId,$userId);$partner=(int)$match['partner_id'];$tm=new TravelMatchService($this->pdo);$compat=$tm->compatibility($userId,$partner);$responses=$tm->relevantResponses($userId,$partner,8);$out=[];
        foreach($responses as $r){
            if(count($out)>=$limit)break;
            $q=trim((string)($r['body']?:$r['title']));$their=trim((string)$r['choice_label']);$your=trim((string)($r['viewer_choice_label']??''));
            if($your!=='' && $your===$their)$out[]='We both answered “'.$their.'” to “'.$q.'” — is that genuinely us, or just who we become on vacation?';
            elseif($your!=='')$out[]='We split on “'.$q.'” — you said “'.$their.'” and I said “'.$your.'.” Defend your answer.';
            else $out[]='Your answer to “'.$q.'” was “'.$their.'.” I need the story behind that.';
        }
        foreach($compat['agreements'] as $a){if(count($out)>=$limit)break;$out[]='Vacation Brain says we agree on '.strtolower((string)$a['label']).'. What would our ideal trip actually look like?';}
        foreach($compat['conflicts'] as $c){if(count($out)>=$limit)break;$out[]='Apparently '.strtolower((string)$c['label']).' could be our first vacation argument. What is your non-negotiable?';}
        $profile=$tm->profile($partner);if(count($out)<$limit && !empty($profile['favorite_destination']))$out[]='You keep dreaming about '.$profile['favorite_destination'].'. What is the first thing we would do there?';
        $fallback=['Window seat or aisle — and how strongly do you feel about it?','What is one vacation expense you will never regret paying extra for?','What is the earliest acceptable time for a vacation alarm?','Pick one: better hotel, better food, or better flight.'];
        foreach($fallback as $line){if(count($out)>=$limit)break;if(!in_array($line,$out,true))$out[]=$line;}
        return array_slice(array_values(array_unique($out)),0,$limit);
    }

    public function discoveries(int $matchId,int $userId): array
    {
        $this->refreshInsights($matchId,$userId);
        $stmt=$this->pdo->prepare('SELECT * FROM travel_match_discoveries WHERE match_id=? ORDER BY unlocked_at DESC,id DESC');$stmt->execute([$matchId]);return $stmt->fetchAll();
    }

    public function achievements(int $matchId,int $userId): array
    {
        $this->refreshInsights($matchId,$userId);
        $stmt=$this->pdo->prepare('SELECT * FROM travel_match_achievements WHERE match_id=? ORDER BY unlocked_at DESC,id DESC');$stmt->execute([$matchId]);return $stmt->fetchAll();
    }

    public function sharedDream(int $matchId,int $userId,bool $create=true): ?array
    {
        $this->match($matchId,$userId);
        $stmt=$this->pdo->prepare('SELECT * FROM travel_match_shared_dreams WHERE match_id=? LIMIT 1');$stmt->execute([$matchId]);$dream=$stmt->fetch();
        if(!$dream && $create){$this->pdo->prepare('INSERT INTO travel_match_shared_dreams (match_id,updated_by) VALUES (?,?)')->execute([$matchId,$userId]);$stmt->execute([$matchId]);$dream=$stmt->fetch();}
        if(!$dream)return null;
        $items=$this->pdo->prepare('SELECT i.*,u.display_name FROM travel_match_shared_dream_items i JOIN users u ON u.id=i.added_by WHERE i.shared_dream_id=? ORDER BY i.created_at DESC,id DESC');$items->execute([$dream['id']]);$dream['items']=$items->fetchAll();return $dream;
    }

    public function saveSharedDream(int $matchId,int $userId,array $input): array
    {
        $dream=$this->sharedDream($matchId,$userId,true);$name=trim((string)($input['name']??'Our Completely Hypothetical Trip'));$destination=trim((string)($input['destination']??''));$vibe=trim((string)($input['vibe']??''));$budget=in_array(($input['budget_style']??''),['budget','comfortable','premium','ridiculous'],true)?$input['budget_style']:'comfortable';$level=max(1,min(5,(int)($input['dream_level']??2)));$notes=trim((string)($input['notes']??''));
        if($name==='')$name='Our Completely Hypothetical Trip';if(strlen($name)>180)$name=substr($name,0,180);if(strlen($notes)>5000)$notes=substr($notes,0,5000);
        $stmt=$this->pdo->prepare('UPDATE travel_match_shared_dreams SET name=?,destination=?,vibe=?,budget_style=?,dream_level=?,notes=?,updated_by=?,updated_at=NOW() WHERE id=?');$stmt->execute([$name,$destination?:null,$vibe?:null,$budget,$level,$notes?:null,$userId,$dream['id']]);
        $this->recordActivity($matchId,$userId,'shared_dream');$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric) VALUES (? ,"travel_match_shared_dream_updated",?)')->execute([$userId,$matchId]);(new ScoreService($this->pdo))->award($userId,'travel_match_shared_dream_updated',3);$this->refreshInsights($matchId,$userId);return $this->sharedDream($matchId,$userId,true);
    }

    public function addSharedDreamItem(int $matchId,int $userId,string $type,string $title,string $notes=''): void
    {
        $dream=$this->sharedDream($matchId,$userId,true);$allowed=['idea','hotel','food','activity','flight','splurge'];if(!in_array($type,$allowed,true))$type='idea';$title=trim($title);$notes=trim($notes);if($title==='')throw new InvalidArgumentException('Give the shared dream item a name.');if(strlen($title)>220)$title=substr($title,0,220);if(strlen($notes)>700)$notes=substr($notes,0,700);
        $this->pdo->prepare('INSERT INTO travel_match_shared_dream_items (shared_dream_id,added_by,item_type,title,notes) VALUES (?,?,?,?,?)')->execute([$dream['id'],$userId,$type,$title,$notes?:null]);$this->recordActivity($matchId,$userId,'shared_dream');$this->refreshInsights($matchId,$userId);
    }

    public function startMatchedGame(int $matchId,int $userId,string $type): array
    {
        $match=$this->match($matchId,$userId);$tm=new TravelMatchService($this->pdo);if(!isset($tm->gameCatalog()[$type]))throw new InvalidArgumentException('Unknown compatibility game.');$token=bin2hex(random_bytes(20));
        $stmt=$this->pdo->prepare('INSERT INTO travel_match_games (match_id,creator_user_id,partner_user_id,invite_token,game_type) VALUES (?,?,?,?,?)');$stmt->execute([$matchId,$userId,(int)$match['partner_id'],$token,$type]);$this->recordActivity($matchId,$userId,'game_started');return ['id'=>(int)$this->pdo->lastInsertId(),'token'=>$token];
    }

    public function refreshInsights(int $matchId,int $userId): void
    {
        $match=$this->match($matchId,$userId);$a=(int)$match['user_a_id'];$b=(int)$match['user_b_id'];
        $same=$this->sharedAnswerCount($a,$b,true);$different=$this->sharedAnswerCount($a,$b,false);$dailyStats=$this->dailyPairStats($matchId,$a,$b);$same+=$dailyStats['same'];$different+=$dailyStats['different'];$traitsA=$this->traitScores($a);$traitsB=$this->traitScores($b);$streak=$this->streak($matchId,$userId)['current'];
        if($same>=1)$this->unlockDiscovery($matchId,'shared_instinct','Shared instinct','You have already made the same Vacation Brain choice at least once. Statistically insignificant. Emotionally important.','agreement');
        if($same>=3){$this->unlockDiscovery($matchId,'same_brain_3','Same Brain','You have picked the exact same answer on at least three Vacation Brain questions. Concerningly coordinated.','agreement');$this->unlockAchievement($matchId,'same-brain','Same Brain','Three shared Vacation Brain answers matched exactly.');}
        if($different>=1){$this->unlockDiscovery($matchId,'first_argument','Your first imaginary argument','You answered at least one of the same vacation questions differently. The itinerary committee has officially formed.','conflict');$this->unlockAchievement($matchId,'first-argument','First Argument','You found your first meaningful Vacation Brain disagreement.');}
        if(isset($traitsA['morning_tolerance'],$traitsB['morning_tolerance']) && $traitsA['morning_tolerance']<=30 && $traitsB['morning_tolerance']<=30){$this->unlockDiscovery($matchId,'no_sunrise','No Sunrise People','Neither of you appears interested in proving anything before breakfast.','agreement');$this->unlockAchievement($matchId,'no-sunrise-people','No Sunrise People','Both Vacation Brains have very low morning tolerance.');}
        if(isset($traitsA['direct_flight_preference'],$traitsB['direct_flight_preference']) && $traitsA['direct_flight_preference']>=70 && $traitsB['direct_flight_preference']>=70)$this->unlockDiscovery($matchId,'direct_flight_people','Connection Avoidance Society','You both strongly prefer direct flights. Your relationship may survive the airport after all.','agreement');
        if(isset($traitsA['budget_sensitivity'],$traitsB['budget_sensitivity']) && abs($traitsA['budget_sensitivity']-$traitsB['budget_sensitivity'])>=35)$this->unlockDiscovery($matchId,'budget_treaty','Budget treaty required','Your Vacation Brains disagree sharply about what counts as “worth the upgrade.”','conflict');
        if($streak>=3)$this->unlockAchievement($matchId,'match-streak-3','Three-Day Escape Streak','You both showed up for this Travel Match three days in a row.');
        $dream=$this->sharedDream($matchId,$userId,false);if($dream && (!empty($dream['destination']) || count($dream['items']??[])>0)){$this->unlockDiscovery($matchId,'shared_dream_started','The hypothetical trip is getting suspicious','You started building a shared dream trip. This is how tabs multiply.','milestone');$this->unlockAchievement($matchId,'dreaming-together','Dreaming Together','You started a shared hypothetical vacation.');}
        $games=$this->pdo->prepare('SELECT * FROM travel_match_games WHERE match_id=? AND status="completed"');$games->execute([$matchId]);$tm=new TravelMatchService($this->pdo);
        foreach($games->fetchAll() as $game){$result=$tm->gameResult($game);if(!$result)continue;$key=(string)$game['game_type'];if($result['score']>=80)$this->unlockDiscovery($matchId,'game_'.$key.'_80','Mini-game compatibility unlocked','You scored '.$result['score'].'% on '.($tm->gameCatalog()[$key]['title']??'a Travel Match game').'. Vacation science is impressed.','game');if($key==='airport-survival' && $result['score']>=80)$this->unlockAchievement($matchId,'airport-compatible','Airport Compatible','You scored at least 80% on Would We Survive the Airport?');}
    }

    private function sharedAnswerCount(int $a,int $b,bool $same): int
    {
        $op=$same?'=':'<>';$sql='SELECT COUNT(*) FROM (SELECT ua.choice_id a_choice,ub.choice_id b_choice FROM (SELECT cc.content_id,MAX(ue.choice_id) choice_id FROM user_events ue JOIN content_choices cc ON cc.id=ue.choice_id WHERE ue.user_id=? AND ue.event_type="choice_selected" GROUP BY cc.content_id) ua JOIN (SELECT cc.content_id,MAX(ue.choice_id) choice_id FROM user_events ue JOIN content_choices cc ON cc.id=ue.choice_id WHERE ue.user_id=? AND ue.event_type="choice_selected" GROUP BY cc.content_id) ub ON ub.content_id=ua.content_id WHERE ua.choice_id '.$op.' ub.choice_id) x';$s=$this->pdo->prepare($sql);$s->execute([$a,$b]);return (int)$s->fetchColumn();
    }

    private function dailyPairStats(int $matchId,int $a,int $b): array
    {
        $sql='SELECT SUM(CASE WHEN aa.choice_id=bb.choice_id THEN 1 ELSE 0 END) same_count,SUM(CASE WHEN aa.choice_id<>bb.choice_id THEN 1 ELSE 0 END) different_count FROM travel_match_daily_questions q JOIN travel_match_daily_answers aa ON aa.daily_question_id=q.id AND aa.user_id=? JOIN travel_match_daily_answers bb ON bb.daily_question_id=q.id AND bb.user_id=? WHERE q.match_id=?';
        $s=$this->pdo->prepare($sql);$s->execute([$a,$b,$matchId]);$row=$s->fetch()?:[];
        return ['same'=>(int)($row['same_count']??0),'different'=>(int)($row['different_count']??0)];
    }

    private function traitScores(int $userId): array
    { $s=$this->pdo->prepare('SELECT t.slug,ut.score FROM user_traits ut JOIN traits t ON t.id=ut.trait_id WHERE ut.user_id=?');$s->execute([$userId]);$out=[];foreach($s->fetchAll() as $r)$out[(string)$r['slug']]=(int)$r['score'];return $out; }
    private function unlockDiscovery(int $matchId,string $key,string $title,string $body,string $type): void
    {
        $stmt=$this->pdo->prepare('INSERT IGNORE INTO travel_match_discoveries (match_id,discovery_key,title,body,discovery_type) VALUES (?,?,?,?,?)');$stmt->execute([$matchId,$key,$title,$body,$type]);
        if($stmt->rowCount())$this->notifyMatch($matchId,'match_discovery','New Match Discovery',$title.' — '.$body,'discovery-'.$matchId.'-'.$key);
    }
    private function unlockAchievement(int $matchId,string $key,string $name,string $description): void
    {
        $stmt=$this->pdo->prepare('INSERT IGNORE INTO travel_match_achievements (match_id,achievement_key,name,description) VALUES (?,?,?,?)');$stmt->execute([$matchId,$key,$name,$description]);
        if($stmt->rowCount())$this->notifyMatch($matchId,'match_achievement','Travel Match achievement: '.$name,$description,'match-achievement-'.$matchId.'-'.$key);
    }
    private function notifyMatch(int $matchId,string $type,string $title,string $body,string $uniqueBase): void
    {
        $s=$this->pdo->prepare('SELECT user_a_id,user_b_id FROM travel_matches WHERE id=?');$s->execute([$matchId]);$m=$s->fetch();if(!$m)return;$n=new NotificationService($this->pdo);
        foreach([(int)$m['user_a_id'],(int)$m['user_b_id']] as $uid)$n->create($uid,$type,$title,$body,'match-space.php?match='.$matchId,null,$matchId,$uniqueBase.'-'.$uid);
    }
}
