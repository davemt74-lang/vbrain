<?php
declare(strict_types=1);

final class VacationProfileService
{
    public function __construct(private PDO $pdo) {}

    public function snapshot(int $userId): array
    {
        $summaryStmt=$this->pdo->prepare('SELECT * FROM user_score_summary WHERE user_id=?');
        $summaryStmt->execute([$userId]);
        $summary=$summaryStmt->fetch() ?: ['vacation_brain_score'=>0,'current_streak'=>0,'longest_streak'=>0,'lifetime_checkins'=>0,'score_level'=>'thinking_about_it'];

        $traitStmt=$this->pdo->prepare('SELECT t.slug,t.name,t.trait_group,ut.score,ut.confidence,ut.interaction_count FROM user_traits ut JOIN traits t ON t.id=ut.trait_id WHERE ut.user_id=? ORDER BY ut.score DESC');
        $traitStmt->execute([$userId]);
        $traitRows=$traitStmt->fetchAll();
        $traits=[];
        foreach($traitRows as $row){$traits[$row['slug']]=$row;}

        $events=[];
        $eventStmt=$this->pdo->prepare('SELECT event_type,COUNT(*) AS total FROM user_events WHERE user_id=? GROUP BY event_type');
        $eventStmt->execute([$userId]);
        foreach($eventStmt->fetchAll() as $row){$events[$row['event_type']]=(int)$row['total'];}

        $score=(int)($summary['vacation_brain_score']??0);
        $level=$this->level($score);
        $archetype=$this->archetype($traits);
        $roasts=$this->roasts($traits,$summary,$events,$archetype);
        $signals=$this->signals($traits);

        return compact('summary','traits','events','score','level','archetype','roasts','signals');
    }

    public function level(int $score): array
    {
        $levels=[
            ['min'=>0,'max'=>299,'slug'=>'thinking_about_it','title'=>'Thinking About It','label'=>'Early Vacation Brain','line'=>'The symptoms are present, but you are still pretending this is casual.'],
            ['min'=>300,'max'=>499,'slug'=>'needs_a_break','title'=>'Needs a Break','label'=>'Active Vacation Brain','line'=>'Normal responsibilities are beginning to compete with destination research.'],
            ['min'=>500,'max'=>699,'slug'=>'mentally_packing','title'=>'Mentally Packing','label'=>'Advanced Vacation Brain','line'=>'You have emotionally selected luggage even if no trip exists yet.'],
            ['min'=>700,'max'=>899,'slug'=>'vacation_brain_activated','title'=>'Vacation Brain Activated','label'=>'Severe Vacation Brain','line'=>'At least part of you has already left town.'],
            ['min'=>900,'max'=>1199,'slug'=>'checked_out','title'=>'Checked Out','label'=>'Critical Vacation Brain','line'=>'Your calendar says work. Your browser history says otherwise.'],
            ['min'=>1200,'max'=>PHP_INT_MAX,'slug'=>'basically_at_the_airport','title'=>'Basically at the Airport','label'=>'Terminal Vacation Brain','line'=>'We are now discussing logistics, not whether you need a vacation.'],
        ];
        foreach($levels as $idx=>$level){
            if($score >= $level['min'] && $score <= $level['max']){
                $next=$levels[$idx+1]??null;
                $level['next']=$next;
                $level['remaining']=$next ? max(0,$next['min']-$score) : 0;
                $span=max(1,$level['max']==PHP_INT_MAX?300:$level['max']-$level['min']+1);
                $level['progress']=$next ? min(100,max(0,(($score-$level['min'])/$span)*100)) : 100;
                return $level;
            }
        }
        return $levels[0];
    }

    public function archetype(array $traits): array
    {
        $value=function(string $slug) use($traits): float { return isset($traits[$slug])?(float)$traits[$slug]['score']:50.0; };
        $candidates=[
            ['name'=>'Professional Lounger','slug'=>'professional-lounger','score'=>($value('relaxation')*1.1+$value('pool')+$value('resort_preference'))/3.1,'description'=>'Your ideal itinerary contains shade, water, something cold to drink, and suspiciously few obligations.','merch'=>'PLANS OPTIONAL. POOL REQUIRED.'],
            ['name'=>'Upgraded Escapist','slug'=>'upgraded-escapist','score'=>($value('luxury')+$value('convenience')+$value('direct_flight_preference'))/3,'description'=>'You understand that money cannot buy happiness, but it can buy a direct flight and a better room.','merch'=>'I PAID EXTRA FOR PEACE.'],
            ['name'=>'Chaotic Explorer','slug'=>'chaotic-explorer','score'=>($value('adventure')+$value('spontaneity')+$value('activity_level'))/3,'description'=>'You like a loose plan, a good story, and at least one decision that would worry a more organized traveler.','merch'=>'THE PLAN IS: FIGURE IT OUT THERE.'],
            ['name'=>'Food-First Traveler','slug'=>'food-first-traveler','score'=>$value('food'),'description'=>'Destinations are mostly elaborate transportation systems between meals you want to remember.','merch'=>'I TRAVEL FOR DINNER.'],
            ['name'=>'Spreadsheet Vacationer','slug'=>'spreadsheet-vacationer','score'=>($value('planning')+$value('convenience'))/2,'description'=>'You call it relaxing because the relaxation has already been assigned a time slot.','merch'=>'RELAXATION: 2:15–3:00 PM.'],
            ['name'=>'Deal-Chasing Dreamer','slug'=>'deal-chasing-dreamer','score'=>($value('budget_sensitivity')+$value('flight_tolerance'))/2,'description'=>'You will tolerate remarkable inconvenience if the savings can be described as “basically another night.”','merch'=>'SHOW ME THE CHEAPER FLIGHT.'],
            ['name'=>'Beach Brain','slug'=>'beach-brain','score'=>($value('beach')+$value('pool')+$value('resort_preference'))/3,'description'=>'Your Vacation Brain has independently concluded that most problems look smaller near water.','merch'=>'CURRENTLY SEEKING HORIZONTAL WATER.'],
            ['name'=>'Night Owl Nomad','slug'=>'night-owl-nomad','score'=>($value('nightlife')+(100-$value('morning_tolerance')))/2,'description'=>'You strongly support local culture, provided the local culture is still open after 9 PM.','merch'=>'NO SUNRISE EXCURSIONS.'],
            ['name'=>'Easy Escape Artist','slug'=>'easy-escape-artist','score'=>($value('relaxation')+$value('convenience')+$value('spontaneity'))/3,'description'=>'You want the vacation to feel easy enough that planning it does not become a second job.','merch'=>'LOW EFFORT. HIGH VACATION.'],
        ];
        usort($candidates,fn($a,$b)=>$b['score']<=>$a['score']);
        $winner=$candidates[0];$winner['score']=(int)round($winner['score']);
        return $winner;
    }

    private function signals(array $traits): array
    {
        $rows=array_values($traits);
        usort($rows,fn($a,$b)=>(float)$b['score']<=>(float)$a['score']);
        $signals=[];
        foreach(array_slice($rows,0,4) as $row){$signals[]=['label'=>$row['name'],'value'=>(int)$row['score'],'kind'=>'high'];}
        usort($rows,fn($a,$b)=>(float)$a['score']<=>(float)$b['score']);
        foreach(array_slice($rows,0,2) as $row){if((float)$row['score']<45)$signals[]=['label'=>$row['name'],'value'=>(int)$row['score'],'kind'=>'low'];}
        return array_slice($signals,0,6);
    }

    private function roasts(array $traits,array $summary,array $events,array $archetype): array
    {
        $v=function(string $slug) use($traits): float { return isset($traits[$slug])?(float)$traits[$slug]['score']:50.0; };
        $lines=[];
        $streak=(int)($summary['current_streak']??0);
        $swipes=(int)($events['choice_selected']??0);
        if($streak>=7)$lines[]="You have checked in {$streak} days in a row just to confirm that yes, you still want a vacation. Excellent research methodology.";
        if($swipes>=50)$lines[]="You have answered {$swipes} vacation questions. At this point we know more about your pool-chair ethics than most of your coworkers do.";
        if($v('morning_tolerance')<35)$lines[]='Your morning tolerance suggests that any itinerary containing the word “sunrise” is actually a threat.';
        if($v('direct_flight_preference')>70)$lines[]='You do not hate connections. You simply believe airports should stop inventing extra airports between you and the beach.';
        if($v('luxury')>70 && $v('budget_sensitivity')>65)$lines[]='You want luxury and a deal, which is a sophisticated way of saying you would like the upgrade but resent paying for it.';
        if($v('adventure')>70 && $v('relaxation')>70)$lines[]='You selected both adventure and relaxation, so apparently the plan is to do something reckless and then lie down immediately afterward.';
        if($v('pool')>75)$lines[]='The pool score is high enough that we should probably stop pretending the destination itself matters.';
        if($v('food')>75)$lines[]='You do not plan vacations around food. You merely reject any trip where food has not been adequately planned around you.';
        $lines[]='Your current classification is '.$archetype['name'].'. That sounds nicer than “person who has mentally left the building.”';
        return array_slice(array_values(array_unique($lines)),0,4);
    }
}
