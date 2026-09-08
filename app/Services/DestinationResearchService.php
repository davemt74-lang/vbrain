<?php
declare(strict_types=1);

final class DestinationResearchService
{
    public function __construct(private PDO $pdo) {}

    public function criteria(): array
    {
        return [
            'summary' => 'General summary and who this destination fits best',
            'weather_history' => 'Historical/typical weather patterns for the requested season',
            'weather_forecast' => 'Current forecast for the requested dates when available',
            'lodging' => 'Hotels plus vacation-rental options such as Airbnb and Vrbo, with price/area context',
            'day_trips' => 'Day trips, activities, attractions and entertainment opportunities',
            'restaurants' => 'Favorite local restaurants grounded in current online-review signals',
            'shows' => 'Upcoming concerts, theater, comedy, sports, festivals and other events',
            'gallery' => 'General-location photo gallery and useful photo-search references',
            'sources' => 'Web sources used so the report can be refreshed and audited later',
        ];
    }

    public function destination(int $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM destination_catalog WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row)return null;
        if(!sample_data_enabled() && !empty($row['is_sample'])) return null;
        return $row;
    }

    public function ensureDestination(?int $destinationId, string $query): array
    {
        if($destinationId){$row=$this->destination($destinationId);if($row)return $row;}
        $query=trim($query);if($query==='')throw new InvalidArgumentException('Enter a destination to research.');
        $slug=$this->slugify($query);
        $stmt=$this->pdo->prepare('SELECT * FROM destination_catalog WHERE slug=? LIMIT 1');$stmt->execute([$slug]);$row=$stmt->fetch();if($row)return $row;
        $stmt=$this->pdo->prepare("INSERT INTO destination_catalog (slug,name,short_description,status,is_sample,featured,sort_order,metadata_json) VALUES (?,?,?,'active',0,0,999,JSON_OBJECT('created_by_research',true))");
        $stmt->execute([$slug,$query,'Destination research created by Vacation Brain.']);
        return $this->destination((int)$this->pdo->lastInsertId()) ?: throw new RuntimeException('Could not create the destination record.');
    }

    public function latestForDestination(int $destinationId): ?array
    {
        $sql='SELECT * FROM destination_reports WHERE destination_catalog_id=? AND status=?';$args=[$destinationId,'ready'];
        if(!sample_data_enabled() && db_column_exists('destination_reports','is_sample')){$sql.=' AND is_sample=0';}
        $sql.=' ORDER BY generated_at DESC,id DESC LIMIT 1';$stmt=$this->pdo->prepare($sql);$stmt->execute($args);$row=$stmt->fetch();
        return $row?$this->hydrateReport($row):null;
    }

    public function report(int $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM destination_reports WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row || (!sample_data_enabled() && !empty($row['is_sample'])))return null;
        return $this->hydrateReport($row);
    }

    public function gallery(int $destinationId): array
    {
        $sql='SELECT * FROM destination_gallery_images WHERE destination_catalog_id=?';$args=[$destinationId];
        if(!sample_data_enabled())$sql.=' AND is_sample=0';
        $sql.=' ORDER BY sort_order,id LIMIT 18';$stmt=$this->pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
    }

    public function recentReports(int $limit=100): array
    {
        $limit=max(1,min(250,$limit));
        $sql='SELECT dr.*,dc.name destination_name FROM destination_reports dr LEFT JOIN destination_catalog dc ON dc.id=dr.destination_catalog_id';
        if(!sample_data_enabled())$sql.=' WHERE dr.is_sample=0';
        $sql.=' ORDER BY dr.generated_at DESC,dr.id DESC LIMIT '.$limit;
        return $this->pdo->query($sql)->fetchAll();
    }

    public function generate(int $userId, ?int $destinationId, string $query, ?string $startDate=null, ?string $endDate=null): array
    {
        if(!db_table_exists('destination_reports'))throw new RuntimeException('Destination research requires the latest database upgrade.');
        $dest=$this->ensureDestination($destinationId,$query);
        $start=$this->dateOrNull($startDate);$end=$this->dateOrNull($endDate);if($start&&$end&&$end<$start)[$start,$end]=[$end,$start];
        $location=trim(implode(', ',array_filter([(string)($dest['name']??''),(string)($dest['region']??''),(string)($dest['country']??'')])));
        if($location==='')$location=trim($query);
        $today=date('Y-m-d');
        $travelWindow=$start?($start.($end&&$end!==$start?' through '.$end:'')):'No exact dates supplied; research the next useful travel window and clearly say when current forecast data is not applicable.';
        $criteria=implode("\n- ",array_values($this->criteria()));
        $system=<<<SYS
You are the Vacation Brain Destination Research Agent. Research current travel information on the public web and return one strict JSON object only. Be practical, source-aware, and concise. Do not invent ratings, review counts, dates, prices, URLs, or current weather. If a fact cannot be verified, use null or say that it could not be verified. Distinguish historical climate norms from a real forecast. For Airbnb and Vrbo, provide representative current listing/search options or neighborhoods and link to valid public pages; never claim availability that was not verified. Restaurant recommendations must reflect current online review/reputation signals. Upcoming shows/events must include dates when verified. Every concrete recommendation should carry a source_url when one was found.

The JSON object MUST use this shape:
{
  "normalized_location": "string",
  "location": {"city":"string|null","region":"string|null","country":"string|null","latitude":null,"longitude":null},
  "summary": "2-5 paragraph useful destination summary",
  "best_for": ["string"],
  "vibe": ["string"],
  "weather_history": {"summary":"string","seasonal_notes":[{"period":"string","conditions":"string","typical_high":"string|null","typical_low":"string|null","rain_notes":"string|null","source_url":"string|null"}]},
  "weather_forecast": {"summary":"string","as_of":"YYYY-MM-DD|null","days":[{"date":"YYYY-MM-DD","high":"string|null","low":"string|null","conditions":"string|null","precipitation":"string|null","source_url":"string|null"}]},
  "lodging": {"summary":"string","hotels":[{"name":"string","area":"string|null","summary":"string","price_text":"string|null","rating":null,"review_count":null,"website_url":"string|null","booking_url":"string|null","source_url":"string|null"}],"airbnb":[{"name":"string","area":"string|null","summary":"string","price_text":"string|null","booking_url":"string|null","source_url":"string|null"}],"vrbo":[{"name":"string","area":"string|null","summary":"string","price_text":"string|null","booking_url":"string|null","source_url":"string|null"}]},
  "day_trips": [{"name":"string","summary":"string","distance":"string|null","price_text":"string|null","website_url":"string|null","booking_url":"string|null","source_url":"string|null"}],
  "entertainment": [{"name":"string","summary":"string","category":"string|null","website_url":"string|null","source_url":"string|null"}],
  "restaurants": [{"name":"string","cuisine":"string|null","area":"string|null","summary":"string","rating":null,"review_count":null,"price_text":"string|null","website_url":"string|null","source_url":"string|null"}],
  "shows": [{"name":"string","category":"string|null","venue":"string|null","starts_at":"YYYY-MM-DD HH:MM:SS|null","summary":"string","ticket_url":"string|null","source_url":"string|null"}],
  "gallery": [{"image_url":"string|null","caption":"string","source_url":"string|null","source_name":"string|null","photo_search_query":"string|null"}],
  "sources": [{"source_type":"weather|hotel|rental|restaurant|event|attraction|general|photo","title":"string|null","publisher":"string|null","url":"string","snippet":"string|null"}]
}
SYS;
        $input="Research destination: {$location}\nToday: {$today}\nTravel window: {$travelWindow}\n\nReport criteria:\n- {$criteria}\n\nFavor official tourism/weather/event sources for factual/current claims, official hotel/venue/restaurant sites for direct details, and reputable review/discovery sources for reputation context. Include multiple useful options, not one winner.";
        $ai=(new AiProviderService($this->pdo))->generateResearchJson($system,$input,$userId,'destination_research',7500);
        if(!$ai||empty($ai['data']))throw new RuntimeException('Vacation Brain could not complete live web research with the active LLM. Check Admin → AI / API Keys and make sure web search is enabled for that provider.');
        $data=$ai['data'];$normalized=trim((string)($data['normalized_location']??$location));
        $summary=trim((string)($data['summary']??''));if($summary==='')$summary='Vacation Brain completed the research but did not return a summary.';
        $lodging=is_array($data['lodging']??null)?$data['lodging']:[];$dayTrips=is_array($data['day_trips']??null)?$data['day_trips']:[];$entertainment=is_array($data['entertainment']??null)?$data['entertainment']:[];
        if($entertainment)$dayTrips=['day_trips'=>$dayTrips,'entertainment'=>$entertainment];
        $ttl=max(1,min(168,(int)(site_setting('destination_research.report_ttl_hours','24')??24)));
        $stmt=$this->pdo->prepare('INSERT INTO destination_reports (destination_catalog_id,requested_by,query_text,normalized_location,travel_start_date,travel_end_date,status,research_mode,provider,model_name,is_sample,summary_text,weather_history_json,weather_forecast_json,lodging_json,day_trips_json,restaurants_json,shows_json,photo_gallery_json,sources_json,raw_response_json,generated_at,expires_at) VALUES (?,?,?,?,?,?,\'ready\',\'web_ai\',?,?,0,?,?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? HOUR))');
        $stmt->execute([(int)$dest['id'],$userId,$query?:$location,$normalized,$start,$end,(string)$ai['provider'],(string)$ai['model'],$summary,$this->json($data['weather_history']??[]),$this->json($data['weather_forecast']??[]),$this->json($lodging),$this->json($dayTrips),$this->json($data['restaurants']??[]),$this->json($data['shows']??[]),$this->json($data['gallery']??[]),$this->json($data['sources']??[]),$this->json($data),$ttl]);
        $reportId=(int)$this->pdo->lastInsertId();
        $this->updateDestinationFromResearch((int)$dest['id'],$data,$summary,$normalized);
        $this->saveSources($reportId,$data['sources']??[]);
        $this->saveEntities($reportId,(int)$dest['id'],$data);
        $this->saveGallery($reportId,(int)$dest['id'],$data['gallery']??[]);
        return $this->report($reportId) ?: throw new RuntimeException('Research was saved but could not be reloaded.');
    }

    public function agentContext(int $userId, int $limit=2): string
    {
        if(!db_table_exists('destination_reports'))return '';
        $limit=max(1,min(5,$limit));
        $sampleClause=sample_data_enabled()?'':' AND dr.is_sample=0';
        $stmt=$this->pdo->prepare('SELECT dr.normalized_location,dr.summary_text,dr.generated_at FROM destination_reports dr WHERE dr.status=\'ready\' '.$sampleClause.' AND (dr.requested_by=? OR dr.requested_by IS NULL) ORDER BY (dr.requested_by=? ) DESC,dr.generated_at DESC LIMIT '.$limit);
        $stmt->execute([$userId,$userId]);$rows=$stmt->fetchAll();if(!$rows)return '';
        $bits=[];foreach($rows as $r){$summary=preg_replace('/\s+/',' ',trim((string)$r['summary_text']))??'';$bits[]=(string)$r['normalized_location'].': '.substr($summary,0,360);}
        return "Saved destination research available to Vacation Brain:\n- ".implode("\n- ",$bits);
    }

    private function hydrateReport(array $row): array
    {
        foreach(['weather_history_json','weather_forecast_json','lodging_json','day_trips_json','restaurants_json','shows_json','photo_gallery_json','sources_json'] as $key){$row[str_replace('_json','',$key)]=$this->decode((string)($row[$key]??''));}
        return $row;
    }

    private function updateDestinationFromResearch(int $destinationId,array $data,string $summary,string $normalized): void
    {
        $loc=is_array($data['location']??null)?$data['location']:[];$best=is_array($data['best_for']??null)?implode(', ',array_slice($data['best_for'],0,8)) : '';$vibe=is_array($data['vibe']??null)?implode(' · ',array_slice($data['vibe'],0,5)) : '';
        $stmt=$this->pdo->prepare('UPDATE destination_catalog SET city=COALESCE(NULLIF(?,\'\'),city),region=COALESCE(NULLIF(?,\'\'),region),country=COALESCE(NULLIF(?,\'\'),country),latitude=COALESCE(?,latitude),longitude=COALESCE(?,longitude),short_description=CASE WHEN short_description IS NULL OR short_description=\'\' OR JSON_EXTRACT(COALESCE(metadata_json,JSON_OBJECT()),\'$.created_by_research\')=true THEN ? ELSE short_description END,description=?,best_for=COALESCE(NULLIF(?,\'\'),best_for),vibe=COALESCE(NULLIF(?,\'\'),vibe),metadata_json=JSON_SET(COALESCE(metadata_json,JSON_OBJECT()),\'$.normalized_location\',?,\'$.last_research_mode\',\'web_ai\'),last_researched_at=NOW() WHERE id=?');
        $stmt->execute([(string)($loc['city']??''),(string)($loc['region']??''),(string)($loc['country']??''),is_numeric($loc['latitude']??null)?(float)$loc['latitude']:null,is_numeric($loc['longitude']??null)?(float)$loc['longitude']:null,substr(strip_tags($summary),0,500),$summary,$best,$vibe,$normalized,$destinationId]);
    }

    private function saveSources(int $reportId,$sources): void
    {
        if(!is_array($sources))return;$seen=[];$ins=$this->pdo->prepare('INSERT INTO destination_report_sources (report_id,source_type,title,publisher,url,snippet) VALUES (?,?,?,?,?,?)');
        foreach($sources as $s){if(!is_array($s))continue;$url=trim((string)($s['url']??''));if(!$this->validHttpUrl($url)||isset($seen[$url]))continue;$seen[$url]=1;$ins->execute([$reportId,substr((string)($s['source_type']??'web'),0,64),$this->nullString($s['title']??null,500),$this->nullString($s['publisher']??null,255),$url,$this->nullString($s['snippet']??null,2000)]);}
    }

    private function saveEntities(int $reportId,int $destinationId,array $data): void
    {
        $groups=[];$lodging=is_array($data['lodging']??null)?$data['lodging']:[];
        foreach(['hotels'=>'hotel','airbnb'=>'airbnb','vrbo'=>'vrbo'] as $key=>$type)foreach((array)($lodging[$key]??[]) as $item)$groups[]=[$type,$item];
        foreach((array)($data['day_trips']??[]) as $item)$groups[]=['day_trip',$item];foreach((array)($data['entertainment']??[]) as $item)$groups[]=['entertainment',$item];foreach((array)($data['restaurants']??[]) as $item)$groups[]=['restaurant',$item];foreach((array)($data['shows']??[]) as $item)$groups[]=['show',$item];
        $ins=$this->pdo->prepare('INSERT INTO destination_research_entities (report_id,destination_catalog_id,entity_type,name,summary,address,website_url,booking_url,source_url,rating,review_count,price_text,starts_at,ends_at,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($groups as [$type,$item]){if(!is_array($item))continue;$name=trim((string)($item['name']??''));if($name==='')continue;$website=$this->safeUrl($item['website_url']??null);$booking=$this->safeUrl($item['booking_url']??($item['ticket_url']??null));$source=$this->safeUrl($item['source_url']??null);$rating=is_numeric($item['rating']??null)?min(5,max(0,(float)$item['rating'])):null;$reviews=is_numeric($item['review_count']??null)?max(0,(int)$item['review_count']):null;$starts=$this->dateTimeOrNull($item['starts_at']??null);$ends=$this->dateTimeOrNull($item['ends_at']??null);
            $ins->execute([$reportId,$destinationId,$type,substr($name,0,300),$this->nullString($item['summary']??null,5000),$this->nullString($item['address']??($item['area']??null),500),$website,$booking,$source,$rating,$reviews,$this->nullString($item['price_text']??null,120),$starts,$ends,$this->json($item)]);
            if(in_array($type,['hotel','airbnb','vrbo','restaurant','day_trip','entertainment'],true))$this->catalogPlace($type,$item,$destinationId);
        }
    }

    private function catalogPlace(string $type,array $item,int $destinationId): void
    {
        $name=trim((string)($item['name']??''));if($name==='')return;$d=$this->destination($destinationId);$city=(string)($d['city']??'');$region=(string)($d['region']??'');$country=(string)($d['country']??'');
        $placeType=match($type){'airbnb','vrbo'=>'vacation_rental','day_trip'=>'attraction','entertainment'=>'entertainment',default=>$type};
        $check=$this->pdo->prepare('SELECT id FROM places WHERE name=? AND place_type=? AND COALESCE(city,\'\')=? AND COALESCE(country,\'\')=? LIMIT 1');$check->execute([$name,$placeType,$city,$country]);$id=$check->fetchColumn();
        $website=$this->safeUrl($item['website_url']??null);$booking=$this->safeUrl($item['booking_url']??($item['ticket_url']??null));$summary=$this->nullString($item['summary']??null,5000);$meta=$this->json(['destination_catalog_id'=>$destinationId,'source_url'=>$this->safeUrl($item['source_url']??null),'rating'=>$item['rating']??null,'review_count'=>$item['review_count']??null,'price_text'=>$item['price_text']??null]);
        if($id){$stmt=$this->pdo->prepare('UPDATE places SET description=COALESCE(?,description),website_url=COALESCE(?,website_url),booking_url=COALESCE(?,booking_url),region=COALESCE(NULLIF(?,\'\'),region),metadata_json=?,active=1,is_sample=0,updated_at=NOW() WHERE id=?');$stmt->execute([$summary,$website,$booking,$region,$meta,(int)$id]);}
        else{$stmt=$this->pdo->prepare('INSERT INTO places (name,place_type,description,address,city,region,country,website_url,booking_url,active,is_sample,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,1,0,?)');$stmt->execute([$name,$placeType,$summary,$this->nullString($item['address']??null,500),$city?:null,$region?:null,$country?:null,$website,$booking,$meta]);}
    }

    private function saveGallery(int $reportId,int $destinationId,$gallery): void
    {
        if(!is_array($gallery))return;$ins=$this->pdo->prepare('INSERT INTO destination_gallery_images (destination_catalog_id,report_id,image_url,caption,source_url,source_name,is_sample,sort_order) VALUES (?,?,?,?,?,?,0,?)');$sort=20;
        foreach($gallery as $g){if(!is_array($g))continue;$url=trim((string)($g['image_url']??''));if(!$this->validHttpUrl($url))continue;$check=$this->pdo->prepare('SELECT 1 FROM destination_gallery_images WHERE destination_catalog_id=? AND image_url=? LIMIT 1');$check->execute([$destinationId,$url]);if($check->fetchColumn())continue;$ins->execute([$destinationId,$reportId,$url,$this->nullString($g['caption']??null,300),$this->safeUrl($g['source_url']??null),$this->nullString($g['source_name']??null,255),$sort]);$sort+=10;}
    }

    private function slugify(string $value): string{$v=strtolower(trim($value));$v=preg_replace('/[^a-z0-9]+/','-',$v)??'destination';$v=trim($v,'-');if($v==='')$v='destination';$base=substr($v,0,170);$slug=$base;$n=2;while(true){$s=$this->pdo->prepare('SELECT 1 FROM destination_catalog WHERE slug=? LIMIT 1');$s->execute([$slug]);if(!$s->fetchColumn())return $slug;$slug=$base.'-'.$n++;}}
    private function decode(string $json): array{$v=json_decode($json,true);return is_array($v)?$v:[];}
    private function json($v): string{return json_encode(is_array($v)?$v:[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'[]';}
    private function validHttpUrl(string $url): bool{return $url!==''&&filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true);}
    private function safeUrl($url): ?string{$u=trim((string)$url);return $this->validHttpUrl($u)?$u:null;}
    private function nullString($v,int $max): ?string{$s=trim((string)$v);if($s==='')return null;return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max);}
    private function dateOrNull($v): ?string{$s=trim((string)$v);if($s==='')return null;$d=DateTime::createFromFormat('Y-m-d',$s);return $d&&$d->format('Y-m-d')===$s?$s:null;}
    private function dateTimeOrNull($v): ?string{$s=trim((string)$v);if($s==='')return null;try{return (new DateTime($s))->format('Y-m-d H:i:s');}catch(Throwable){return null;}}
}
