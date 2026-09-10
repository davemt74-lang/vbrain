<?php
require __DIR__ . '/app/bootstrap.php';
$userId = require_auth();
$pdo = db();
$user = current_user();
$pageStyles = ['assets/dashboard-trips.css'];
$title = 'Dashboard — Vacation Brain';
$destinationOwnerService = new DestinationOwnerService($pdo);

function dashboard_sample_trip_fallbacks(): array
{
    return [
        'local' => [
            ['slug'=>'sedona-red-rock-trails','name'=>'Sedona Red Rock Trails','location_text'=>'Sedona, Arizona','latitude'=>34.8697,'longitude'=>-111.7609,'drive_minutes'=>110,'duration_text'=>'1h 50m','subtitle'=>'Hiking · Views · Vibes','rating'=>4.8,'review_count'=>1200,'image_url'=>'/assets/sample/destinations/sedona.webp','search_query'=>'Sedona, Arizona'],
            ['slug'=>'lake-pleasant','name'=>'Lake Pleasant','location_text'=>'Peoria, Arizona','latitude'=>33.8474,'longitude'=>-112.2657,'drive_minutes'=>45,'duration_text'=>'45m','subtitle'=>'Boating · Swimming · Relaxing','rating'=>4.6,'review_count'=>892,'image_url'=>'/assets/sample/destinations/maui.webp','search_query'=>'Lake Pleasant, Arizona'],
            ['slug'=>'cave-creek','name'=>'Cave Creek','location_text'=>'Cave Creek, Arizona','latitude'=>33.8333,'longitude'=>-111.9508,'drive_minutes'=>35,'duration_text'=>'35m','subtitle'=>'Food · Shops · Western Fun','rating'=>4.5,'review_count'=>614,'image_url'=>'/assets/sample/destinations/sedona.webp','search_query'=>'Cave Creek, Arizona'],
            ['slug'=>'tonto-natural-bridge','name'=>'Tonto Natural Bridge','location_text'=>'Pine, Arizona','latitude'=>34.3226,'longitude'=>-111.4497,'drive_minutes'=>100,'duration_text'=>'1h 40m','subtitle'=>'Hiking · Waterfall · Nature','rating'=>4.7,'review_count'=>530,'image_url'=>'/assets/sample/destinations/costa-rica.webp','search_query'=>'Tonto Natural Bridge State Park, Arizona'],
        ],
        'weekend' => [
            ['slug'=>'flagstaff-weekend','name'=>'Flagstaff','location_text'=>'Flagstaff, Arizona','latitude'=>35.1983,'longitude'=>-111.6513,'drive_minutes'=>135,'duration_text'=>'2h 15m','subtitle'=>'Breweries · Nature','rating'=>4.6,'review_count'=>1100,'image_url'=>'/assets/sample/destinations/aspen.webp','search_query'=>'Flagstaff, Arizona'],
            ['slug'=>'scottsdale-weekend','name'=>'Scottsdale','location_text'=>'Scottsdale, Arizona','latitude'=>33.4942,'longitude'=>-111.9261,'drive_minutes'=>30,'duration_text'=>'30m','subtitle'=>'Resorts · Dining · Nightlife','rating'=>4.5,'review_count'=>980,'image_url'=>'/assets/sample/destinations/tulum.webp','search_query'=>'Scottsdale, Arizona'],
            ['slug'=>'las-vegas-weekend','name'=>'Las Vegas','location_text'=>'Las Vegas, Nevada','latitude'=>36.1699,'longitude'=>-115.1398,'drive_minutes'=>270,'duration_text'=>'4h 30m','subtitle'=>'Entertainment · Shows','rating'=>4.4,'review_count'=>2300,'image_url'=>'/assets/sample/destinations/paris.webp','search_query'=>'Las Vegas, Nevada'],
            ['slug'=>'palm-springs-weekend','name'=>'Palm Springs','location_text'=>'Palm Springs, California','latitude'=>33.8303,'longitude'=>-116.5453,'drive_minutes'=>250,'duration_text'=>'4h 10m','subtitle'=>'Relax · Pools · Culture','rating'=>4.3,'review_count'=>1700,'image_url'=>'/assets/sample/destinations/bali.webp','search_query'=>'Palm Springs, California'],
        ],
    ];
}

function dashboard_trip_rows(PDO $pdo, string $category): array
{
    if (!db_table_exists('dashboard_trip_suggestions')) return [];
    $sampleClause = sample_data_enabled() ? '' : ' AND is_sample=0';
    $stmt = $pdo->prepare("SELECT * FROM dashboard_trip_suggestions WHERE status='active' AND category=?{$sampleClause} ORDER BY sort_order ASC,id ASC LIMIT 8");
    $stmt->execute([$category]);
    return $stmt->fetchAll() ?: [];
}

function dashboard_catalog_trip_rows(DestinationOwnerService $service,string $type): array
{
    if (!$service->ready()) return [];
    $defaultDuration=['day_trip'=>'Day trip','weekend'=>'Weekend','multi_day'=>'Multi-day'][$type]??'Trip';
    $rows=[];
    foreach($service->publicTripRows($type,8) as $d){
        $location=trim(implode(', ',array_filter([(string)($d['city']??''),(string)($d['region']??''),(string)($d['country']??'')])));
        $rows[]=[
            'destination_catalog_id'=>(int)$d['id'],
            'slug'=>'catalog-'.$d['id'].'-'.$type,
            'name'=>(string)$d['name'],
            'location_text'=>$location,
            'latitude'=>$d['latitude']??null,
            'longitude'=>$d['longitude']??null,
            'drive_minutes'=>null,
            'duration_text'=>trim((string)($d['typical_duration']??''))?:$defaultDuration,
            'subtitle'=>trim((string)($d['best_for']??''))?:trim((string)($d['vibe']??'')),
            'rating'=>4.7,
            'review_count'=>0,
            'image_url'=>(string)($d['hero_image_url']??''),
            'search_query'=>trim((string)$d['name'].($location!==''?', '.$location:'')),
        ];
    }
    return $rows;
}

function dashboard_merge_trip_rows(array $primary,array $secondary,int $limit=8): array
{
    $out=[];$seen=[];
    foreach(array_merge($primary,$secondary) as $row){$key=strtolower(trim((string)($row['name']??$row['slug']??'')));if($key===''||isset($seen[$key]))continue;$seen[$key]=true;$out[]=$row;if(count($out)>=$limit)break;}
    return $out;
}

function dashboard_trip_url(array $trip): string
{
    $catalogId=(int)($trip['destination_catalog_id']??0);
    if($catalogId>0) return app_url('destination-report.php?destination_id='.$catalogId);
    return app_url('destination-report.php?q='.urlencode((string)($trip['search_query']??$trip['name']??'')));
}

$localTrips = dashboard_merge_trip_rows(dashboard_catalog_trip_rows($destinationOwnerService,'day_trip'),dashboard_trip_rows($pdo, 'local'));
$weekendTrips = dashboard_merge_trip_rows(dashboard_catalog_trip_rows($destinationOwnerService,'weekend'),dashboard_trip_rows($pdo, 'weekend'));
if (sample_data_enabled()) {
    $fallbacks = dashboard_sample_trip_fallbacks();
    if (!$localTrips) $localTrips = $fallbacks['local'];
    if (!$weekendTrips) $weekendTrips = $fallbacks['weekend'];
}

$multiDayTrips = [];
if ($destinationOwnerService->ready()) {
    $multiDayTrips = $destinationOwnerService->publicTripRows('multi_day',8);
} elseif (db_table_exists('destination_catalog')) {
    $sampleClause = (db_column_exists('destination_catalog','is_sample') && !sample_data_enabled()) ? ' AND d.is_sample=0' : '';
    $wanted = ['cabo-san-lucas','puerto-rico','maui','costa-rica'];
    $placeholders = implode(',', array_fill(0, count($wanted), '?'));
    $stmt = $pdo->prepare("SELECT d.* FROM destination_catalog d WHERE d.status='active' AND d.slug IN ({$placeholders}){$sampleClause} ORDER BY FIELD(d.slug,'cabo-san-lucas','puerto-rico','maui','costa-rica')");
    $stmt->execute($wanted);
    $multiDayTrips = $stmt->fetchAll() ?: [];
    if (count($multiDayTrips) < 4) {
        $stmt = $pdo->prepare("SELECT d.* FROM destination_catalog d WHERE d.status='active'{$sampleClause} ORDER BY d.featured DESC,d.sort_order ASC,d.name ASC LIMIT 4");
        $stmt->execute();
        $multiDayTrips = $stmt->fetchAll() ?: [];
    }
}

$mapTrips = [];
foreach ($localTrips as $trip) {
    if (!isset($trip['latitude'],$trip['longitude'])) continue;
    $lat = (float)$trip['latitude']; $lng = (float)$trip['longitude'];
    if (!$lat && !$lng) continue;
    $mapTrips[] = [
        'name'=>(string)$trip['name'],
        'lat'=>$lat,
        'lng'=>$lng,
        'duration'=>(string)($trip['duration_text'] ?? ''),
        'image'=>media_url((string)($trip['image_url'] ?? '')),
        'url'=>dashboard_trip_url($trip),
    ];
}

function dashboard_review_count(int $count): string
{
    if ($count >= 1000) return rtrim(rtrim(number_format($count / 1000, 1), '0'), '.') . 'k';
    return number_format($count);
}

$tabsReady = db_table_exists('dashboard_agent_tabs');
$agentTabs = [];
if ($tabsReady) {
    $stmt = $pdo->prepare('SELECT id,name,purpose,sort_order FROM dashboard_agent_tabs WHERE user_id=? ORDER BY sort_order ASC,id ASC');
    $stmt->execute([$userId]);
    $agentTabs = $stmt->fetchAll() ?: [];
}
$requestedAgentTab = trim((string)($_GET['agent_tab'] ?? 'main'));
$activeAgentTab = 'main';
foreach ($agentTabs as $agentTab) {
    if ((string)$agentTab['id'] === $requestedAgentTab) {
        $activeAgentTab = (string)$agentTab['id'];
        break;
    }
}
$agentActions = [
    ['name'=>'Plan a Trip','copy'=>'Start a new trip and shape the destination, dates, pace, and priorities.','url'=>app_url('dream.php')],
    ['name'=>'Create a Photo Album','copy'=>'Build a vacation album from saved or generated travel photos.','url'=>app_url('vacation-gallery.php')],
    ['name'=>'Research a Destination','copy'=>'Create a detailed destination report with lodging, weather, food, and things to do.','url'=>app_url('destination-report.php')],
    ['name'=>'Find a Weekend Getaway','copy'=>'Look for a short escape that fits your location and available time.','url'=>app_url('destinations.php')],
    ['name'=>'Build an Itinerary','copy'=>'Turn a destination idea into a practical day-by-day travel plan.','url'=>app_url('agent.php')],
    ['name'=>'Create a Fake Vacation','copy'=>'Generate a playful vacation concept and add it to your Vacation Brain.','url'=>app_url('vacation-yourself.php')],
];

require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-trip-dashboard">
  <div class="shell" data-agent-workspace data-tabs-ready="<?=$tabsReady?'1':'0'?>" data-active-tab="<?=e($activeAgentTab)?>" data-api-url="<?=e(app_url('api/dashboard-tabs.php'))?>" data-page-url="<?=e(app_url('today.php'))?>">
    <input type="hidden" data-agent-csrf value="<?=e(csrf_token())?>">

    <div class="vb-agent-tabbar" role="tablist" aria-label="Vacation Brain workspaces">
      <div class="vb-agent-tab <?=$activeAgentTab==='main'?'active':''?>" data-agent-tab data-tab-key="main" data-tab-name="Main" data-tab-purpose="">
        <button type="button" class="vb-agent-tab-select" data-agent-tab-select role="tab" aria-selected="<?=$activeAgentTab==='main'?'true':'false'?>">Main</button>
        <button type="button" class="vb-agent-tab-settings" data-agent-tab-settings aria-label="Main tab settings">⚙</button>
      </div>
      <?php foreach($agentTabs as $agentTab): $tabKey=(string)$agentTab['id'];?>
      <div class="vb-agent-tab <?=$activeAgentTab===$tabKey?'active':''?>" data-agent-tab data-tab-key="<?=e($tabKey)?>" data-tab-name="<?=e((string)$agentTab['name'])?>" data-tab-purpose="<?=e((string)($agentTab['purpose'] ?? ''))?>">
        <button type="button" class="vb-agent-tab-select" data-agent-tab-select role="tab" aria-selected="<?=$activeAgentTab===$tabKey?'true':'false'?>"><?=e((string)$agentTab['name'])?></button>
        <button type="button" class="vb-agent-tab-settings" data-agent-tab-settings aria-label="<?=e((string)$agentTab['name'])?> settings">⚙</button>
      </div>
      <?php endforeach;?>
      <button type="button" class="vb-agent-tab-add" data-agent-add aria-label="Create a new agent tab">+</button>
    </div>

    <div class="vb-agent-pane" data-agent-pane="main" <?=$activeAgentTab==='main'?'':'hidden'?>>
      <section class="vb-dashboard-section vb-local-section" data-local-section>
        <div class="vb-dashboard-section-head">
          <div class="vb-dashboard-title-wrap"><div><h1>Local Day Trips</h1><p>Quick escapes. Big smiles. Adventure is closer than you think.</p></div></div>
          <button class="button secondary small vb-map-toggle" type="button" data-map-toggle aria-expanded="true">Hide map <span>⌃</span></button>
        </div>
        <div class="vb-local-layout" data-local-layout>
          <div class="vb-map-card" data-map-card>
            <div id="vb-daytrip-map" class="vb-daytrip-map" aria-label="Map of nearby day trips"></div>
            <button class="vb-use-location" type="button" data-use-location><span>⌖</span> Use my location</button>
            <div class="vb-map-status" data-map-status aria-live="polite"></div>
          </div>
          <div class="vb-local-cards">
            <?php foreach(array_slice($localTrips,0,4) as $trip):
              $image = trim((string)($trip['image_url'] ?? ''));
              $url = dashboard_trip_url($trip);
            ?>
            <article class="vb-trip-card vb-local-card" data-trip-card data-destination-id="<?=(int)($trip['destination_catalog_id']??0)?>" data-lat="<?=e((string)($trip['latitude'] ?? ''))?>" data-lng="<?=e((string)($trip['longitude'] ?? ''))?>">
              <a class="vb-trip-image <?=$image===''?'is-placeholder':''?>" href="<?=e($url)?>"<?php if($image!==''):?> style="background-image:url('<?=e(media_url($image))?>')"<?php endif;?>>
                <span class="vb-trip-time" data-trip-time><?=e((string)($trip['duration_text'] ?? 'Nearby'))?></span><span class="vb-trip-heart" aria-hidden="true">♡</span>
              </a>
              <div class="vb-trip-card-body"><h2><?=e((string)$trip['name'])?></h2><p><?=e((string)($trip['subtitle'] ?? $trip['location_text'] ?? ''))?></p><div class="vb-trip-rating"><span>★</span> <?=number_format((float)($trip['rating'] ?? 4.7),1)?> <small>(<?=e(dashboard_review_count((int)($trip['review_count'] ?? 0)))?>)</small></div><a class="vb-trip-details" href="<?=e($url)?>">View Details</a></div>
            </article>
            <?php endforeach;?>
            <?php if(!$localTrips):?><div class="dashboard-card vb-trip-empty"><h2>No local day trips yet.</h2><p class="muted">Destination owners can classify published listings as Day Trip from their dashboard.</p></div><?php endif;?>
          </div>
        </div>
      </section>

      <section class="vb-dashboard-section vb-scroll-section">
        <div class="vb-dashboard-section-head compact"><div class="vb-dashboard-title-wrap"><div><h2>Weekend Getaways</h2><p>Just a couple of days. A whole new you.</p></div></div><a class="vb-see-more" href="<?=e(app_url('destinations.php'))?>">See more getaways →</a></div>
        <div class="vb-wide-trip-grid">
          <?php foreach(array_slice($weekendTrips,0,4) as $trip):
            $image=trim((string)($trip['image_url'] ?? ''));
            $url=dashboard_trip_url($trip);
          ?>
          <article class="vb-wide-trip-card" data-destination-id="<?=(int)($trip['destination_catalog_id']??0)?>"><a class="vb-wide-trip-image <?=$image===''?'is-placeholder':''?>" href="<?=e($url)?>"<?php if($image!==''):?> style="background-image:url('<?=e(media_url($image))?>')"<?php endif;?>><span class="vb-trip-heart" aria-hidden="true">♡</span></a><div class="vb-wide-trip-body"><h3><?=e((string)$trip['name'])?></h3><div class="vb-wide-meta"><span>→ <?=e((string)($trip['duration_text'] ?? 'Weekend'))?></span><span><?=e((string)($trip['subtitle'] ?? 'Explore · Relax'))?></span><span class="rating">★ <?=number_format((float)($trip['rating'] ?? 4.6),1)?> <small>(<?=e(dashboard_review_count((int)($trip['review_count'] ?? 0)))?>)</small></span></div></div></article>
          <?php endforeach;?>
          <?php if(!$weekendTrips):?><div class="dashboard-card vb-trip-empty"><h2>No weekend getaways yet.</h2><p class="muted">Destination owners can classify published listings as Weekend Getaway.</p></div><?php endif;?>
        </div>
      </section>

      <section class="vb-dashboard-section vb-scroll-section">
        <div class="vb-dashboard-section-head compact"><div class="vb-dashboard-title-wrap"><div><h2>Multi-Day Excursions</h2><p>Go further. Stay longer. Make it epic.</p></div></div><a class="vb-see-more" href="<?=e(app_url('destinations.php'))?>">See more excursions →</a></div>
        <div class="vb-wide-trip-grid">
          <?php foreach(array_slice($multiDayTrips,0,4) as $trip):
            $image=trim((string)($trip['hero_image_url'] ?? ''));
            $url=app_url('destination-report.php?destination_id='.(int)$trip['id']);
            $meta=(string)($trip['best_for'] ?? $trip['vibe'] ?? 'Adventure · Escape');
            $duration=trim((string)($trip['typical_duration']??''))?:'5–7 days';
          ?>
          <article class="vb-wide-trip-card" data-destination-id="<?=(int)$trip['id']?>"><a class="vb-wide-trip-image <?=$image===''?'is-placeholder':''?>" href="<?=e($url)?>"<?php if($image!==''):?> style="background-image:url('<?=e(media_url($image))?>')"<?php endif;?>><span class="vb-trip-heart" aria-hidden="true">♡</span></a><div class="vb-wide-trip-body"><h3><?=e((string)$trip['name'])?></h3><div class="vb-wide-meta"><span>→ <?=e($duration)?></span><span><?=e($meta)?></span><span class="rating">★ 4.8 <small>(sample)</small></span></div></div></article>
          <?php endforeach;?>
          <?php if(!$multiDayTrips):?><div class="dashboard-card vb-trip-empty"><h2>No multi-day excursions yet.</h2><p class="muted">Destination owners can classify published listings as Multi-Day Excursion.</p></div><?php endif;?>
        </div>
      </section>

      <section class="vb-dashboard-cta"><div class="vb-cta-icon">✈</div><div><h2>Not sure where to go?</h2><p>Let Vacation Brain diagnose your perfect escape.</p></div><a class="button primary" href="<?=e(app_url('diagnosis.php'))?>">Take the Vacation Brain Diagnosis <span>→</span></a></section>
    </div>

    <?php foreach($agentTabs as $agentTab): $tabKey=(string)$agentTab['id'];?>
    <section class="vb-agent-pane vb-agent-workspace-pane" data-agent-pane="<?=e($tabKey)?>" <?=$activeAgentTab===$tabKey?'':'hidden'?>>
      <div class="vb-agent-canvas-head"><div><span class="eyebrow">Vacation Brain Agent</span><h1><?=e((string)$agentTab['name'])?></h1><p><?=e(trim((string)($agentTab['purpose'] ?? '')) ?: 'Choose an action to give this agent something to work on.')?></p></div><button type="button" class="button secondary small" data-agent-tab-settings data-agent-settings-for="<?=e($tabKey)?>">Tab settings</button></div>
      <div class="vb-agent-action-grid">
        <?php foreach($agentActions as $action):?>
        <a class="vb-agent-action-card" href="<?=e($action['url'])?>"><strong><?=e($action['name'])?></strong><span><?=e($action['copy'])?></span><b>Start →</b></a>
        <?php endforeach;?>
      </div>
      <div class="vb-agent-empty-state"><strong>Agent activity will appear here.</strong><span>We are establishing the workspace and actions first; live planning jobs and agent activity can plug into this canvas next.</span></div>
    </section>
    <?php endforeach;?>
  </div>
</section>

<div class="vb-tab-drawer-backdrop" data-tab-drawer-backdrop></div>
<aside class="vb-tab-settings-drawer" data-tab-settings-drawer aria-hidden="true" aria-label="Tab settings">
  <div class="vb-tab-drawer-head"><div><span class="eyebrow">Workspace</span><h2 data-tab-drawer-title>Tab settings</h2></div><button type="button" class="vb-tab-drawer-close" data-tab-drawer-close aria-label="Close tab settings">×</button></div>
  <p class="muted" data-tab-drawer-intro>Manage this Vacation Brain tab.</p>
  <div class="vb-tab-error" data-tab-error hidden></div>
  <div class="vb-main-tab-note" data-main-tab-note hidden>The Main tab is permanent and contains your default dashboard. It cannot be deleted.</div>
  <form data-tab-settings-form hidden>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="tab_id" value="">
    <label>Agent name<input class="input" type="text" name="name" maxlength="120" required></label>
    <label>Purpose<textarea class="input" name="purpose" rows="4" maxlength="500" placeholder="What should this agent focus on?"></textarea></label>
    <button class="button primary" type="submit" data-tab-save>Save settings</button>
  </form>
  <div class="vb-tab-danger"><button type="button" class="button secondary" data-tab-delete hidden>Delete tab</button></div>
</aside>

<script type="application/json" id="vb-daytrip-data"><?=json_encode($mapTrips, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="<?=e(app_url('assets/dashboard-trips.js'))?>"></script>
<script src="<?=e(app_url('assets/dashboard-tabs.js'))?>"></script>
<?php require __DIR__.'/partials/footer.php';?>