<?php
declare(strict_types=1);

final class DestinationPromptService
{
    private const JSON_FIELDS=[
        'visual_keywords','landmark_keywords','environment_keywords','activity_keywords','wardrobe_keywords',
        'tourist_boost_keywords','humor_keywords','avoid_keywords','default_scenes','default_vibes',
    ];

    public function __construct(private PDO $pdo) {}

    public function destination(int $destinationId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM destination_catalog WHERE id=? LIMIT 1');
        $stmt->execute([$destinationId]);
        return $stmt->fetch() ?: null;
    }

    public function get(int $destinationId): array
    {
        $destination=$this->destination($destinationId);
        if(!$destination) throw new RuntimeException('Destination not found.');
        $row=null;
        if(db_table_exists('destination_prompt_profiles')){
            $stmt=$this->pdo->prepare('SELECT * FROM destination_prompt_profiles WHERE destination_catalog_id=? LIMIT 1');
            $stmt->execute([$destinationId]);$row=$stmt->fetch() ?: null;
        }
        $profile=[
            'destination_catalog_id'=>$destinationId,
            'prompt_summary'=>trim((string)($row['prompt_summary']??$destination['short_description']??'')),
            'source_mode'=>(string)($row['source_mode']??'derived'),
            'provider'=>(string)($row['provider']??''),
            'model_name'=>(string)($row['model_name']??''),
        ];
        foreach(self::JSON_FIELDS as $field){
            $column=$field.'_json';
            $decoded=$row?json_decode((string)($row[$column]??'[]'),true):[];
            $profile[$field]=is_array($decoded)?array_values($decoded):[];
        }
        if(!$profile['default_vibes'])$profile['default_vibes']=array_values(array_filter(array_map('trim',explode(',',(string)($destination['vibe']??'')))));
        if(!$profile['activity_keywords']&&!empty($destination['best_for']))$profile['activity_keywords']=array_values(array_filter(array_map('trim',explode(',',(string)$destination['best_for']))));
        $profile['destination']=$destination;
        return $profile;
    }

    public function save(int $destinationId,array $input,int $adminId,string $sourceMode='manual',?string $provider=null,?string $model=null): array
    {
        if(!$this->destination($destinationId)) throw new RuntimeException('Destination not found.');
        $summary=$this->clean((string)($input['prompt_summary']??''),4000);
        $values=[];
        foreach(self::JSON_FIELDS as $field)$values[$field]=$this->listValue($input[$field]??[]);
        $stmt=$this->pdo->prepare('INSERT INTO destination_prompt_profiles (destination_catalog_id,prompt_summary,visual_keywords_json,landmark_keywords_json,environment_keywords_json,activity_keywords_json,wardrobe_keywords_json,tourist_boost_keywords_json,humor_keywords_json,avoid_keywords_json,default_scenes_json,default_vibes_json,source_mode,provider,model_name,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE prompt_summary=VALUES(prompt_summary),visual_keywords_json=VALUES(visual_keywords_json),landmark_keywords_json=VALUES(landmark_keywords_json),environment_keywords_json=VALUES(environment_keywords_json),activity_keywords_json=VALUES(activity_keywords_json),wardrobe_keywords_json=VALUES(wardrobe_keywords_json),tourist_boost_keywords_json=VALUES(tourist_boost_keywords_json),humor_keywords_json=VALUES(humor_keywords_json),avoid_keywords_json=VALUES(avoid_keywords_json),default_scenes_json=VALUES(default_scenes_json),default_vibes_json=VALUES(default_vibes_json),source_mode=VALUES(source_mode),provider=VALUES(provider),model_name=VALUES(model_name),created_by=VALUES(created_by),updated_at=NOW()');
        $stmt->execute([
            $destinationId,$summary?:null,
            json_encode($values['visual_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['landmark_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['environment_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['activity_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['wardrobe_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['tourist_boost_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['humor_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['avoid_keywords'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['default_scenes'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($values['default_vibes'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            $sourceMode,$provider,$model,$adminId,
        ]);
        return $this->get($destinationId);
    }

    public function promptText(array $profile): string
    {
        $d=$profile['destination']??[];
        $lines=[];
        $location=trim(implode(', ',array_filter([(string)($d['city']??''),(string)($d['region']??''),(string)($d['country']??'')])));
        $lines[]='DESTINATION PROMPT PROFILE: '.trim((string)($d['name']??'Destination')).($location!==''?' — '.$location:'').'.';
        if(!empty($profile['prompt_summary']))$lines[]='Destination character: '.trim((string)$profile['prompt_summary']);
        $labels=[
            'visual_keywords'=>'Visual vocabulary','landmark_keywords'=>'Recognizable place cues','environment_keywords'=>'Environment','activity_keywords'=>'Activities','wardrobe_keywords'=>'Vacation wardrobe context','tourist_boost_keywords'=>'Tourist exaggeration vocabulary','humor_keywords'=>'Humor vocabulary','default_scenes'=>'Suggested scenes','default_vibes'=>'Suggested vibes','avoid_keywords'=>'Avoid',
        ];
        foreach($labels as $field=>$label){if(!empty($profile[$field]))$lines[]=$label.': '.implode(', ',array_map('strval',$profile[$field])).'.';}
        $lines[]='Use destination vocabulary selectively; do not cram every keyword into one image. Prefer details that fit the user profile, selected scene, vibe, and Over the Top strength.';
        return implode("\n",$lines);
    }

    public function formValue(array $profile,string $field): string
    {
        return implode("\n",array_map('strval',$profile[$field]??[]));
    }

    private function listValue(mixed $value): array
    {
        if(is_array($value))$items=$value;
        else{
            $raw=trim((string)$value);
            if($raw==='')return [];
            if(str_starts_with($raw,'[')){$decoded=json_decode($raw,true);$items=is_array($decoded)?$decoded:preg_split('/[\r\n,]+/',$raw);}
            else $items=preg_split('/[\r\n,]+/',$raw);
        }
        $out=[];$seen=[];
        foreach($items?:[] as $item){$item=$this->clean((string)$item,240);if($item===''||isset($seen[strtolower($item)]))continue;$seen[strtolower($item)]=true;$out[]=$item;if(count($out)>=40)break;}
        return $out;
    }

    private function clean(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
