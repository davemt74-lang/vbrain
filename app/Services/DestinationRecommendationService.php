<?php
declare(strict_types=1);

final class DestinationRecommendationService
{
    private const TRAIT_TERMS=[
        'beach'=>['beach','ocean','coast','coastal','island','seaside','tropical'],
        'pool'=>['pool','resort','cabana'],
        'nightlife'=>['nightlife','night','bars','clubs','social','late'],
        'luxury'=>['luxury','premium','upscale','resort','spa'],
        'budget_sensitivity'=>['budget','value','affordable','easy'],
        'adventure'=>['adventure','hiking','outdoor','excursion','mountain','nature'],
        'food'=>['food','dining','restaurant','culinary','market'],
        'culture'=>['culture','history','historic','museum','architecture','art'],
        'relaxation'=>['relax','relaxed','spa','beach','resort','slow'],
        'resort_preference'=>['resort','pool','all-inclusive','spa'],
        'city_preference'=>['city','urban','neighborhood','nightlife','culture'],
        'romance'=>['romantic','romance','couples','sunset','wine'],
        'activity_level'=>['activity','adventure','hiking','tour','excursion'],
        'spontaneity'=>['flexible','spontaneous','easy','neighborhood'],
    ];

    public function __construct(private PDO $pdo) {}

    public function recommendations(int $userId,int $limit=3): array
    {
        if(!db_table_exists('destination_catalog'))return [];
        $limit=max(1,min(8,$limit));
        $traits=(new VacationProfileService($this->pdo))->snapshot($userId)['traits']??[];
        $weighted=[];
        foreach($traits as $slug=>$row){$score=(float)($row['score']??50);$confidence=(float)($row['confidence']??0);if($score<55||$confidence<10)continue;$weighted[$slug]=max(0,($score-50)/50)*max(.25,min(1,$confidence/100));}
        arsort($weighted);$weighted=array_slice($weighted,0,8,true);

        $sampleClause=(db_column_exists('destination_catalog','is_sample')&&!sample_data_enabled())?' AND d.is_sample=0':'';
        $hasPrompt=db_table_exists('destination_prompt_profiles');
        $sql='SELECT d.*'.($hasPrompt?',p.prompt_summary,p.visual_keywords_json,p.activity_keywords_json,p.default_vibes_json,p.default_scenes_json':'').' FROM destination_catalog d'.($hasPrompt?' LEFT JOIN destination_prompt_profiles p ON p.destination_catalog_id=d.id':'')." WHERE d.status='active'".$sampleClause.' ORDER BY d.featured DESC,d.sort_order,d.name';
        $rows=$this->pdo->query($sql)->fetchAll();$scored=[];
        foreach($rows as $row){
            $haystack=strtolower(implode(' ',[(string)($row['name']??''),(string)($row['short_description']??''),(string)($row['description']??''),(string)($row['best_for']??''),(string)($row['vibe']??''),(string)($row['prompt_summary']??''),(string)($row['visual_keywords_json']??''),(string)($row['activity_keywords_json']??''),(string)($row['default_vibes_json']??'')]));
            $score=!empty($row['featured'])?3.0:0.0;$reasons=[];
            foreach($weighted as $slug=>$weight){$matches=0;foreach(self::TRAIT_TERMS[$slug]??[$slug] as $term){if(str_contains($haystack,strtolower($term)))$matches++;}if($matches){$points=$weight*(3+min(3,$matches));$score+=$points;$label=(string)($traits[$slug]['name']??ucwords(str_replace('_',' ',$slug)));$reasons[$slug]=$label;}}
            $row['_recommendation_score']=$score;$row['_recommendation_reasons']=array_values($reasons);$scored[]=$row;
        }
        usort($scored,fn($a,$b)=>($b['_recommendation_score']<=>$a['_recommendation_score'])?:((int)$b['featured']<=>(int)$a['featured'])?:((int)$a['sort_order']<=>(int)$b['sort_order']));
        return array_slice($scored,0,$limit);
    }

    public function primary(int $userId): ?array
    {
        return $this->recommendations($userId,1)[0]??null;
    }
}
