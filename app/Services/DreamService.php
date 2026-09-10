<?php
declare(strict_types=1);

final class DreamService
{
    public function __construct(private PDO $pdo) {}

    public function all(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT dt.*,(SELECT COUNT(*) FROM dream_trip_items dti WHERE dti.dream_trip_id=dt.id) AS item_count,(SELECT COUNT(*) FROM user_events ue WHERE ue.user_id=dt.user_id AND ue.dream_trip_id=dt.id AND ue.event_type="dream_trip_viewed") AS view_count FROM dream_trips dt WHERE dt.user_id=? AND dt.status<>"abandoned" ORDER BY dt.updated_at DESC,dt.id DESC');
        $stmt->execute([$userId]);$rows=$stmt->fetchAll();
        foreach($rows as &$row){$row=$this->decorate($row);}unset($row);
        return $rows;
    }

    public function create(int $userId,array $input): int
    {
        $name=trim((string)($input['name']??''));$destination=trim((string)($input['destination']??''));
        if($name==='')$name=$destination!==''?$destination.' Trip':'Unnamed Escape';
        $description=trim((string)($input['description']??''));$travelers=max(1,min(30,(int)($input['travelers']??1)));$budget=($input['target_budget']??'')!==''?max(0,(float)$input['target_budget']):null;$dreamLevel=max(1,min(5,(int)($input['dream_level']??2)));$status=$this->validStatus((string)($input['status']??'planning'));
        $start=$this->dateOrNull((string)($input['start_date']??''));$end=$this->dateOrNull((string)($input['end_date']??''));if($start&&$end&&$end<$start)throw new InvalidArgumentException('Return date must be on or after the departure date.');
        $meta=['destination_name'=>$destination];$destinationCatalogId=$this->resolveCatalogId($destination);$catalog=$destinationCatalogId?$this->catalogDestination($destinationCatalogId):null;
        $origin=trim((string)($input['origin_name']??$input['origin']??''));$originIata=$this->iata((string)($input['origin_iata']??''));$destinationIata=$this->iata((string)($input['destination_iata']??''));$currency=$this->currency((string)($input['currency']??'USD'));
        $this->pdo->beginTransaction();
        try{
            if(db_column_exists('dream_trips','origin_name')){
                $stmt=$this->pdo->prepare('INSERT INTO dream_trips (user_id,name,description,status,destination_catalog_id,start_date,end_date,travelers,target_budget,dream_level,booking_readiness,origin_name,origin_iata,destination_iata,destination_latitude,destination_longitude,currency,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?)');
                $stmt->execute([$userId,$name,$description!==''?$description:null,$status,$destinationCatalogId,$start,$end,$travelers,$budget,$dreamLevel,$origin,$originIata?:null,$destinationIata?:null,$catalog['latitude']??null,$catalog['longitude']??null,$currency,json_encode($meta,JSON_UNESCAPED_SLASHES)]);
            }else{
                $stmt=$this->pdo->prepare('INSERT INTO dream_trips (user_id,name,description,status,start_date,end_date,travelers,target_budget,dream_level,booking_readiness,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,0,?)');
                $stmt->execute([$userId,$name,$description!==''?$description:null,$status,$start,$end,$travelers,$budget,$dreamLevel,json_encode($meta,JSON_UNESCAPED_SLASHES)]);
            }
            $id=(int)$this->pdo->lastInsertId();$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,dream_trip_id,value_text) VALUES (? ,"dream_trip_created",?,?)')->execute([$userId,$id,$destination?:$name]);(new ScoreService($this->pdo))->award($userId,'dream_trip_created',25);(new AchievementService($this->pdo))->evaluate($userId);$this->recalculate($userId,$id);$this->pdo->commit();return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function get(int $userId,int $id,bool $recordView=true): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM dream_trips WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$id,$userId]);$row=$stmt->fetch();if(!$row)return null;
        if($recordView){$this->pdo->prepare('INSERT INTO user_events (user_id,event_type,dream_trip_id,value_text) VALUES (? ,"dream_trip_viewed",?,?)')->execute([$userId,$id,$row['name']]);(new ScoreService($this->pdo))->award($userId,'dream_trip_viewed',2);$this->recalculate($userId,$id);}
        $row=$this->decorate($row);$items=$this->pdo->prepare('SELECT * FROM dream_trip_items WHERE dream_trip_id=? ORDER BY '.(db_column_exists('dream_trip_items','scheduled_date')?'scheduled_date IS NULL,scheduled_date,FIELD(daypart,"morning","afternoon","evening","anytime"),':'').'sort_order,id');$items->execute([$id]);$row['items']=$items->fetchAll();return $row;
    }

    public function update(int $userId,int $id,array $input): void
    {
        $current=$this->get($userId,$id,false);if(!$current)throw new RuntimeException('Dream trip not found.');$meta=json_decode((string)($current['metadata_json']??''),true)?:[];$destination=trim((string)($input['destination']??($meta['destination_name']??'')));$meta['destination_name']=$destination;
        $name=trim((string)($input['name']??$current['name']));if($name==='')$name='Unnamed Escape';$description=trim((string)($input['description']??($current['description']??'')));$status=$this->validStatus((string)($input['status']??$current['status']));$travelers=max(1,min(30,(int)($input['travelers']??$current['travelers'])));$budget=array_key_exists('target_budget',$input)?(($input['target_budget']??'')!==''?max(0,(float)$input['target_budget']):null):$current['target_budget'];$dreamLevel=max(1,min(5,(int)($input['dream_level']??$current['dream_level'])));
        $start=array_key_exists('start_date',$input)?$this->dateOrNull((string)$input['start_date']):($current['start_date']??null);$end=array_key_exists('end_date',$input)?$this->dateOrNull((string)$input['end_date']):($current['end_date']??null);if($start&&$end&&$end<$start)throw new InvalidArgumentException('Return date must be on or after the departure date.');
        if(db_column_exists('dream_trips','origin_name')){
            $origin=trim((string)($input['origin_name']??$input['origin']??($current['origin_name']??'')));$originIata=$this->iata((string)($input['origin_iata']??($current['origin_iata']??'')));$destinationIata=$this->iata((string)($input['destination_iata']??($current['destination_iata']??'')));$currency=$this->currency((string)($input['currency']??($current['currency']??'USD')));$catalogId=$this->resolveCatalogId($destination);$catalog=$catalogId?$this->catalogDestination($catalogId):null;
            $stmt=$this->pdo->prepare('UPDATE dream_trips SET name=?,description=?,status=?,start_date=?,end_date=?,travelers=?,target_budget=?,dream_level=?,metadata_json=?,origin_name=?,origin_iata=?,destination_iata=?,currency=?,destination_catalog_id=?,destination_latitude=COALESCE(?,destination_latitude),destination_longitude=COALESCE(?,destination_longitude),updated_at=NOW() WHERE id=? AND user_id=?');
            $stmt->execute([$name,$description?:null,$status,$start,$end,$travelers,$budget,$dreamLevel,json_encode($meta,JSON_UNESCAPED_SLASHES),$origin,$originIata?:null,$destinationIata?:null,$currency,$catalogId,$catalog['latitude']??null,$catalog['longitude']??null,$id,$userId]);
        }else{$this->pdo->prepare('UPDATE dream_trips SET name=?,description=?,status=?,start_date=?,end_date=?,travelers=?,target_budget=?,dream_level=?,metadata_json=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$name,$description?:null,$status,$start,$end,$travelers,$budget,$dreamLevel,json_encode($meta,JSON_UNESCAPED_SLASHES),$id,$userId]);}
        $this->recalculate($userId,$id);
    }

    public function addItem(int $userId,int $dreamId,array $input): void
    {
        if(!$this->get($userId,$dreamId,false))throw new RuntimeException('Dream trip not found.');$title=trim((string)($input['title']??''));if($title==='')throw new InvalidArgumentException('Give the trip item a name.');$type=(string)($input['item_type']??'idea');$allowed=['idea','hotel','food','activity','flight','experience','merch'];if(!in_array($type,$allowed,true))$type='idea';$notes=trim((string)($input['notes']??''));$price=($input['price']??'')!==''&&$input['price']!==null?max(0,(float)$input['price']):null;$sortStmt=$this->pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM dream_trip_items WHERE dream_trip_id=?');$sortStmt->execute([$dreamId]);$sort=(int)$sortStmt->fetchColumn();
        if(db_column_exists('dream_trip_items','scheduled_date')){$scheduled=$this->dateOrNull((string)($input['scheduled_date']??''));$daypart=in_array((string)($input['daypart']??''),['morning','afternoon','evening','anytime'],true)?(string)$input['daypart']:null;$sourceProvider=trim((string)($input['source_provider']??''));$sourceExternal=trim((string)($input['source_external_id']??''));$sourceUrl=trim((string)($input['source_url']??$input['url']??''));$stmt=$this->pdo->prepare('INSERT INTO dream_trip_items (dream_trip_id,item_type,title,url,price,notes,scheduled_date,daypart,source_provider,source_external_id,source_url,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$dreamId,$type,$title,$sourceUrl?:null,$price,$notes?:null,$scheduled,$daypart,$sourceProvider?:null,$sourceExternal?:null,$sourceUrl?:null,$sort]);}
        else{$this->pdo->prepare('INSERT INTO dream_trip_items (dream_trip_id,item_type,title,price,notes,sort_order) VALUES (?,?,?,?,?,?)')->execute([$dreamId,$type,$title,$price,$notes?:null,$sort]);}
        $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,dream_trip_id,value_text) VALUES (? ,"dream_item_added",?,?)')->execute([$userId,$dreamId,$type]);(new ScoreService($this->pdo))->award($userId,'dream_item_added',2);(new AchievementService($this->pdo))->evaluate($userId);$this->recalculate($userId,$dreamId);
    }

    public function deleteItem(int $userId,int $dreamId,int $itemId): void
    {
        if(!$this->get($userId,$dreamId,false))return;$this->pdo->prepare('DELETE FROM dream_trip_items WHERE id=? AND dream_trip_id=?')->execute([$itemId,$dreamId]);$this->recalculate($userId,$dreamId);
    }

    private function recalculate(int $userId,int $dreamId): void
    {
        $stmt=$this->pdo->prepare('SELECT dt.*,(SELECT COUNT(*) FROM dream_trip_items WHERE dream_trip_id=dt.id) AS items,(SELECT COUNT(*) FROM user_events WHERE user_id=? AND dream_trip_id=dt.id AND event_type="dream_trip_viewed") AS views FROM dream_trips dt WHERE dt.id=? AND dt.user_id=?');$stmt->execute([$userId,$dreamId,$userId]);$r=$stmt->fetch();if(!$r)return;$meta=json_decode((string)($r['metadata_json']??''),true)?:[];$score=10;if(!empty($meta['destination_name']))$score+=15;if($r['target_budget']!==null)$score+=10;if((int)$r['travelers']>1)$score+=5;if(!empty($r['start_date'])&&!empty($r['end_date']))$score+=10;if(isset($r['origin_name'])&&trim((string)$r['origin_name'])!=='')$score+=5;$score+=min(20,(int)$r['items']*4);$score+=min(20,(int)$r['views']*2);$statusWeights=['fantasy'=>0,'maybe'=>5,'considering'=>10,'serious'=>18,'planning'=>25,'booked'=>35,'completed'=>35,'abandoned'=>0];$score+=($statusWeights[$r['status']]??0);$score=min(100,$score);$this->pdo->prepare('UPDATE dream_trips SET booking_readiness=? WHERE id=? AND user_id=?')->execute([$score,$dreamId,$userId]);
    }

    private function decorate(array $row): array
    {
        $meta=json_decode((string)($row['metadata_json']??''),true)?:[];$row['destination_name']=$meta['destination_name']??'';$read=(float)($row['booking_readiness']??0);$row['temperature']=$read<30?'Pure daydream':($read<50?'Getting suspicious':($read<70?'Looking pretty real':($read<90?'Book-it energy':'Basically packed')));$labels=['fantasy'=>'Daydreaming','maybe'=>'Maybe someday','considering'=>'Considering it','serious'=>'This is getting serious','planning'=>'Planning-ish','booked'=>'Booked','completed'=>'Been there','abandoned'=>'We moved on'];$row['status_label']=$labels[$row['status']]??ucfirst((string)$row['status']);$row['date_label']=$this->dateLabel($row['start_date']??null,$row['end_date']??null);return $row;
    }

    private function validStatus(string $status): string
    {
        $allowed=['fantasy','maybe','considering','serious','planning','booked','completed','abandoned'];return in_array($status,$allowed,true)?$status:'fantasy';
    }

    private function dateOrNull(string $value): ?string
    {
        $value=trim($value);if($value==='')return null;$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $dt&&$dt->format('Y-m-d')===$value?$value:null;
    }

    private function iata(string $value): string
    {
        $value=strtoupper(trim($value));return preg_match('/^[A-Z]{3}$/',$value)?$value:'';
    }

    private function currency(string $value): string
    {
        $value=strtoupper(trim($value));return preg_match('/^[A-Z]{3}$/',$value)?$value:'USD';
    }

    private function resolveCatalogId(string $destination): ?int
    {
        if($destination===''||!db_table_exists('destination_catalog')||!db_column_exists('dream_trips','destination_catalog_id'))return null;$stmt=$this->pdo->prepare("SELECT id FROM destination_catalog WHERE LOWER(name)=LOWER(?) AND status='active' ORDER BY publication_status='published' DESC LIMIT 1");$stmt->execute([$destination]);$id=(int)($stmt->fetchColumn()?:0);return $id>0?$id:null;
    }

    private function catalogDestination(int $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,latitude,longitude FROM destination_catalog WHERE id=? LIMIT 1');$stmt->execute([$id]);return $stmt->fetch()?:null;
    }

    private function dateLabel(mixed $start,mixed $end): string
    {
        $start=trim((string)$start);$end=trim((string)$end);if($start==='')return 'Dates flexible';$s=strtotime($start);if(!$s)return 'Dates flexible';if($end===''||$end===$start)return date('M j, Y',$s);$e=strtotime($end);if(!$e)return date('M j, Y',$s);if(date('Y',$s)===date('Y',$e)&&date('M',$s)===date('M',$e))return date('M j',$s).'–'.date('j, Y',$e);return date('M j',$s).' – '.date('M j, Y',$e);
    }
}
