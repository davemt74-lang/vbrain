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
            $system='You are Vacation Brain, a playful sarcastic vacation-daydreaming concierge. Be funny, useful, concise, and never present Vacation Brain as medical or mental-health care. The user\'s current Vacation Brain archetype is '.($profile['archetype']['name']??'Unknown').'. Strong travel signals: '.$traitText.'. Use these signals naturally when relevant. Do not reveal hidden scoring mechanics or claim certainty about preferences. Vacation Yourself image actions are handled by a separate controlled application action layer. Never claim you generated, remixed, shared, favorited, or changed an image unless the application has actually done so.'.($researchContext!==''?"\n\n".$researchContext:'').$dashboardContext;
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
        if(str_contains($q,'roast'))return $profile['roasts'][0]??'You have successfully outsourced being judged for wanting a vacation.';
        if(str_contains($q,'learned')||str_contains($q,'know about me')||str_contains($q,'profile')){
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
}
