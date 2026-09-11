<?php
declare(strict_types=1);

final class VacationAgentService
{
    private VacationPhotoAgentService $photoAgent;

    public function __construct(private PDO $pdo)
    {
        $this->photoAgent=new VacationPhotoAgentService($pdo,dirname(__DIR__,2));
    }

    public function send(int $userId,string $message): string
    {
        return (string)$this->sendWithResult($userId,$message)['message'];
    }

    public function sendWithResult(int $userId,string $message,array $input=[]): array
    {
        $action=trim((string)($input['action']??''));
        $message=trim($message);
        if($message==='' && $action!=='')$message=$this->photoAgent->actionLabel($action,$input);
        if($message==='')throw new InvalidArgumentException('Ask Vacation Brain something.');

        $this->pdo->prepare('INSERT INTO agent_messages (user_id,role,body) VALUES (? ,"user",?)')->execute([$userId,$message]);

        try{
            $result=$this->photoAgent->handle($userId,$message,$input);
            if($result){
                $reply=(string)($result['message']??'Vacation Brain completed the action.');
            }else{
                $reply=$this->llmReply($userId,$message) ?? $this->reply($userId,$message);
                $result=['intent'=>'conversation','type'=>'text','message'=>$reply];
            }
        }catch(Throwable $e){
            $reply=trim($e->getMessage()) ?: 'Vacation Brain could not complete that action.';
            $result=['intent'=>'vacation_photo_error','type'=>'error','message'=>$reply];
        }

        $this->pdo->prepare('INSERT INTO agent_messages (user_id,role,body) VALUES (? ,"assistant",?)')->execute([$userId,$reply]);
        $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_text) VALUES (? ,"agent_message",?)')->execute([$userId,substr($message,0,1000)]);
        (new ScoreService($this->pdo))->award($userId,'agent_message',1);
        return $result;
    }

    public function history(int $userId,int $limit=60): array
    {
        $stmt=$this->pdo->prepare('SELECT role,body,created_at FROM agent_messages WHERE user_id=? ORDER BY id DESC LIMIT '.max(1,min(100,$limit)));$stmt->execute([$userId]);return array_reverse($stmt->fetchAll());
    }

    public function photoContext(int $userId): array
    {
        return $this->photoAgent->context($userId);
    }

    private function llmReply(int $userId,string $message): ?string
    {
        try{
            $profile=(new VacationProfileService($this->pdo))->snapshot($userId);
            $traits=array_slice(array_values($profile['traits']??[]),0,8);
            $traitText=implode(', ',array_map(fn($t)=>(string)($t['name']??'Trait').' '.(int)($t['score']??50).'%', $traits));
            $researchContext='';
            try{$researchContext=(new DestinationResearchService($this->pdo))->agentContext($userId,2);}catch(Throwable){}
            $dashboardContext='';
            try{$dashboardContext=(new DashboardDestinationContextService($this->pdo))->promptContext($userId);}catch(Throwable){}
            $memoryContext='';
            try{
                if(class_exists('TravelerMemoryGraphService')){$graph=new TravelerMemoryGraphService($this->pdo);if($graph->ready())$memoryContext=$graph->agentContext($userId,5);}
                if($memoryContext===''&&class_exists('TripMemoryService')){$memory=new TripMemoryService($this->pdo);if($memory->ready())$memoryContext=$memory->agentContext($userId);}
            }catch(Throwable){}
            $liveTripContext='';
            try{if(class_exists('LiveTravelAgentContextService'))$liveTripContext=(new LiveTravelAgentContextService($this->pdo))->context($userId);}catch(Throwable){}
            $system='You are Vacation Brain, a playful sarcastic vacation-daydreaming concierge. Be funny, useful, concise, and never present Vacation Brain as medical or mental-health care. The user\'s current Vacation Brain archetype is '.($profile['archetype']['name']??'Unknown').'. Strong travel signals: '.$traitText.'. Use these signals naturally when relevant. When Traveler Memory is present, distinguish diagnosis guesses from preferences supported by completed trips, honor ignored learning signals, and never infer private trip notes. When LIVE TRIP CONTEXT is present, it contains only previously saved provider snapshots: do not claim you refreshed a provider during this chat. When PROACTIVE TRIP STATE is present, treat it as saved risk/opportunity evidence with source state and confidence, not certainty. You may recommend or research next moves, but never imply that an itinerary, reservation, purchase, or booking change has been applied without the user\'s explicit approval. Respect freshness labels. Treat Aviationstack booked-flight status as operational information that can still change; treat Skyscanner airfare as indicative only; treat Booking.com lodging results as search availability, not confirmed reservations. Never infer or request booking confirmation codes from live-provider context. Do not reveal hidden scoring mechanics or claim certainty about preferences. Vacation Yourself image actions are handled by a separate controlled application action layer. Never claim you generated, remixed, shared, favorited, or changed an image unless the application has actually done so.'.($memoryContext!==''?"\n\n".$memoryContext:'').($liveTripContext!==''?"\n\n".$liveTripContext:'').($researchContext!==''?"\n\n".$researchContext:'').$dashboardContext;
            return (new AiProviderService($this->pdo))->generateText($system,$message,$userId,'agent_chat',450);
        }catch(Throwable){return null;}
    }

    private function reply(int $userId,string $message): string
    {
        $q=strtolower($message);$profile=(new VacationProfileService($this->pdo))->snapshot($userId);$traits=$profile['traits'];
        $v=fn(string $slug)=>(int)($traits[$slug]['score']??50);
        $selected=[];try{$selected=(new DashboardDestinationContextService($this->pdo))->names($userId);}catch(Throwable){}
        if($selected && (str_contains($q,'compare')||str_contains($q,'selected')||str_contains($q,'locations'))){
            return 'Your selected destination context is '.implode(', ',$selected).'. The AI provider is unavailable right now, so I can keep those places selected but I cannot produce a reliable live comparison until the configured agent model responds.';
        }
        if($this->isLiveTripQuestion($q)){
            try{$live=(new LiveTravelAgentContextService($this->pdo))->fallbackSummary($userId);if($live){$answer=$this->liveFallback($q,$live);if($answer!=='')return $answer;}}catch(Throwable){}
        }
        if(str_contains($q,'roast'))return $profile['roasts'][0]??'You have successfully outsourced being judged for wanting a vacation.';
        if(str_contains($q,'learned')||str_contains($q,'know about me')||str_contains($q,'travel history')||str_contains($q,'last trip')||str_contains($q,'liked about')){
            try{
                if(class_exists('TravelerMemoryGraphService')){$graph=new TravelerMemoryGraphService($this->pdo);if($graph->ready()){ $ctx=$graph->agentContext($userId,5);if($ctx!=='')return $ctx.' Open Traveler Memory for the full history and correction controls.';}}
            }catch(Throwable){}
            $top=array_slice(array_values($traits),0,3);$bits=array_map(fn($t)=>$t['name'].' '.(int)$t['score'].'%',$top);return 'Current diagnosis: '.$profile['archetype']['name'].'. Strong signals: '.implode(', ',$bits).'. Translation: '.$profile['archetype']['description'];
        }
        if(str_contains($q,'where should')||str_contains($q,'where do i')||str_contains($q,'destination')){
            if($selected)return 'You explicitly selected '.implode(', ',$selected).'. I would start there before wandering into unrelated recommendations.';
            if($v('beach')>70||$v('pool')>75)return 'Start with warm beach/resort destinations where the pool is a feature, not an afterthought. Your profile is making a very strong argument against complicated sightseeing before lunch.';
            if($v('city_preference')>70&&$v('food')>65)return 'Your profile wants a walkable city with enough food to turn “sightseeing” into a sequence of meals. I would daydream in that direction first.';
            if($v('adventure')>70)return 'Your profile wants somewhere with a story attached: water, mountains, trails, unusual activities, or a decision your family will question later.';
            return 'I would start with the easiest trip that makes your weather app jealous. You do not need a life-changing expedition to have a valid Vacation Brain episode.';
        }
        if(str_contains($q,'excuse')){$c=(new FunContentService($this->pdo))->random('vacation_excuse',$userId);return $c?(string)$c['body']:'I have reached the medically irrelevant conclusion that your calendar is too full and the beach is not.';}
        if(str_contains($q,'out of office')||str_contains($q,'ooo')){$c=(new FunContentService($this->pdo))->random('out_of_office',$userId);return $c?(string)$c['body']:'I am currently unavailable due to a temporary improvement in priorities.';}
        if(str_contains($q,'morning')&&$v('morning_tolerance')<45)return 'I checked the data. Your Vacation Brain considers sunrise an event that should occur privately without your participation.';
        if(str_contains($q,'dream')){
            $dreams=(new DreamService($this->pdo))->all($userId);if($dreams){$d=$dreams[0];return 'You keep circling “'.$d['name'].'.” Current status: '.$d['temperature'].'. I am not saying book it. I am saying the browser tab has witnesses.';}return 'You have not created a Dream Trip yet. This is unusually disciplined. Go make one before the condition improves.';
        }
        if(str_contains($q,'break')||str_contains($q,'escape'))return 'I recommend the Escape tab. Your options include a tiny Vacation Break, a local Vacation Substitution, Weather Envy, or letting me help fabricate an out-of-office message.';
        $comment=(new FunContentService($this->pdo))->random('agent_comment',$userId);return $comment?(string)$comment['body']:'I have reviewed the situation and recommend continued vacation-related procrastination.';
    }

    private function isLiveTripQuestion(string $q): bool
    {
        foreach(['flight status','my flight','gate','delay','weather for my trip','weather on my trip','hotel price','lodging','hotel availability','what is happening near','events on my trip','trip weather','what should i worry','needs attention','anything changed','what changed','trip risk','trip conflict','proactive'] as $needle)if(str_contains($q,$needle))return true;return false;
    }

    private function liveFallback(string $q,array $live): string
    {
        $trip=$live['trip']??[];$tripName=(string)($trip['name']??'your trip');
        $proactiveQuestion=false;foreach(['what should i worry','needs attention','anything changed','what changed','trip risk','trip conflict','proactive'] as $needle)if(str_contains($q,$needle)){$proactiveQuestion=true;break;}
        if($proactiveQuestion){$issues=(array)($live['proactive']??[]);if($issues){$bits=[];foreach(array_slice($issues,0,3) as $issue){if(!is_array($issue))continue;$bits[]=strtoupper((string)($issue['severity']??'info')).' — '.(string)($issue['title']??'Trip issue').' ['.(string)($issue['source_state']??'inferred').', '.(int)($issue['confidence']??0).'% confidence]';}if($bits)return $tripName.' currently needs attention on: '.implode('; ',$bits).'. These are saved risk signals; no provider refresh was triggered by this chat. Open Proactive Vacation Brain for the recommended Next Moves. Any itinerary, reservation, purchase, or booking change still requires your approval.';}return 'No proactive issue is currently open for '.$tripName.' based on the saved trip data. This answer did not refresh any travel provider.';}
        if(str_contains($q,'flight')||str_contains($q,'gate')||str_contains($q,'delay')){$f=$live['flights']??[];$statuses=(array)($f['booked_statuses']??[]);if($statuses){$row=$statuses[0];$dep=(array)($row['departure']??[]);$bits=[(string)($row['flight_number']??'Flight'),ucwords(str_replace('_',' ',(string)($row['status']??'unknown')))];if(!empty($dep['gate']))$bits[]='gate '.$dep['gate'];if(isset($dep['delay'])&&$dep['delay']!==null)$bits[]=(int)$dep['delay'].'m delay';return $tripName.': '.implode(' · ',$bits).'. Snapshot '.(string)($live['health']['flights']['freshness']??'age unknown').'; confirm critical gate/terminal details with the airline.';}if(!empty($f['ok'])&&isset($f['min_price']))return $tripName.' has indicative airfare from '.(string)($f['currency']??'USD').' '.number_format((float)$f['min_price'],0).'. That is planning data, not a confirmed or guaranteed bookable fare.';return 'I do not have a successful saved flight snapshot for '.$tripName.' yet. Open the trip dashboard and refresh Flights.';}
        if(str_contains($q,'weather')){$w=$live['weather']??[];$d=$w['days'][0]??null;if(is_array($d)){return $tripName.' weather snapshot: '.(isset($d['high'])?round((float)$d['high']).'° high, ':'').(isset($d['precip_probability'])?round((float)$d['precip_probability']).'% rain, ':'').(string)($d['conditions']??'conditions unavailable').'. Snapshot '.(string)($live['health']['weather']['freshness']??'age unknown').'.';}return 'I do not have a successful saved weather snapshot for '.$tripName.' yet. Open the trip dashboard and refresh Weather.';}
        if(str_contains($q,'hotel')||str_contains($q,'lodging')){$l=$live['lodging']??[];if(!empty($l['ok']))return $tripName.' lodging snapshot has '.count((array)($l['items']??[])).' current results'.(isset($l['min_price'])?', from '.(string)($l['currency']??'USD').' '.number_format((float)$l['min_price'],0):'').'. This is search availability, not a confirmed reservation. Snapshot '.(string)($live['health']['lodging']['freshness']??'age unknown').'.';return 'I do not have a successful saved lodging snapshot for '.$tripName.' yet. Open Live Lodging from the trip dashboard and refresh it.';}
        if(str_contains($q,'event')||str_contains($q,'happening near')){$e=$live['events']??[];if(!empty($e['items'])){$first=$e['items'][0];return $tripName.' currently has '.count((array)$e['items']).' matching event results. One of the first is '.(string)($first['name']??'an event').(!empty($first['date'])?' on '.(string)$first['date']:'').'. Snapshot '.(string)($live['health']['events']['freshness']??'age unknown').'.';}return 'I do not have a successful saved events snapshot for '.$tripName.' yet. Open the trip dashboard and refresh Events.';}
        return '';
    }
}
