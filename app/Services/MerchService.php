<?php
declare(strict_types=1);

final class MerchService
{
    public function __construct(private PDO $pdo) {}

    public function recommendations(int $userId): array
    {
        $out=[];
        $stmt=$this->pdo->prepare('SELECT mt.*,mp.name AS product_name,mp.product_type,mp.base_price,a.name AS achievement_name,a.slug AS achievement_slug,mtr.id AS trigger_id
          FROM merch_trigger_rules mtr JOIN merch_templates mt ON mt.id=mtr.template_id JOIN merch_products mp ON mp.id=mt.product_id
          LEFT JOIN achievements a ON a.id=mtr.achievement_id
          WHERE mtr.active=1 AND mt.active=1 AND mp.active=1 AND mtr.trigger_type="achievement"
          AND EXISTS (SELECT 1 FROM user_achievements ua WHERE ua.user_id=? AND ua.achievement_id=mtr.achievement_id AND ua.completed=1)
          ORDER BY mtr.id DESC');
        $stmt->execute([$userId]);
        foreach($stmt->fetchAll() as $r){$cfg=json_decode((string)$r['design_config_json'],true)?:[];$r['headline']=$cfg['headline']??$r['name'];$r['subheadline']=$cfg['subheadline']??'';$out[]=$r;}

        $profile=(new VacationProfileService($this->pdo))->snapshot($userId);
        foreach(array_slice(array_values($profile['traits']),0,4) as $t){
            if((float)$t['score']<62)continue;
            $out[]=['name'=>'Vacation Brain Trait Tee','product_name'=>'Classic Vacation Brain T-Shirt','product_type'=>'shirt','base_price'=>29.00,'headline'=>strtoupper($t['name']).' BRAIN','subheadline'=>'Vacation Brain profile: '.(int)$t['score'].'%','achievement_name'=>null,'trait'=>$t['slug']];
        }

        $events=$profile['events'];$streak=(int)($profile['summary']['current_streak']??0);
        if($streak>=2)$out[]=$this->behavior('shirt','DAY '.$streak.' OF THINKING ABOUT VACATION','Still physically present','Daily check-in streak');
        if(($events['weather_envy_checked']??0)>=2)$out[]=$this->behavior('shirt','I CHECK OTHER CITIES’ WEATHER','For emotional reasons','Weather Envy');
        if(($events['dream_trip_viewed']??0)>=5)$out[]=$this->behavior('hoodie','I REOPEN VACATION TABS','This is research','Dream Trip revisits',49.00);
        if(($events['roast_generated']??0)>=1)$out[]=$this->behavior('mug','MY VACATION APP ROASTED ME','I asked for it','Roast My Vacation Brain',18.00);
        if(($events['vacation_excuse_generated']??0)>=3)$out[]=$this->behavior('sticker','VACATION EXCUSE DEPARTMENT','Please hold','Excuse Generator',6.00);
        if(($events['vacation_break_completed']??0)>=3)$out[]=$this->behavior('shirt','I TAKE VACATIONS IN 3-MINUTE INCREMENTS','Vacation Break Program','Vacation Breaks');
        if(($events['choice_selected']??0)>=25)$out[]=$this->behavior('shirt','I HAVE STRONG VACATION OPINIONS','Ask me about airport timing','Vacation Swipe');
        $out[]=$this->behavior('shirt',strtoupper($profile['archetype']['name']),$profile['archetype']['merch'],'Vacation Brain archetype');

        $seen=[];$dedup=[];foreach($out as $item){$k=strtolower(($item['product_type']??'').':'.($item['headline']??''));if(isset($seen[$k]))continue;$seen[$k]=1;$dedup[]=$item;}
        return array_slice($dedup,0,16);
    }

    private function behavior(string $type,string $headline,string $sub,string $source,float $price=29.00): array
    {
        $labels=['shirt'=>'Classic Vacation Brain T-Shirt','hoodie'=>'Vacation Brain Hoodie','mug'=>'Vacation Brain Mug','sticker'=>'Vacation Brain Sticker'];
        return ['name'=>'Behavior Merch','product_name'=>$labels[$type]??'Vacation Brain Merch','product_type'=>$type,'base_price'=>$price,'headline'=>$headline,'subheadline'=>$sub,'achievement_name'=>null,'behavior_source'=>$source];
    }
}
