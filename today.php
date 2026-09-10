<?php
require __DIR__ . '/app/bootstrap.php';
$userId = require_auth();
$pdo = db();
$user = current_user();
$pageStyles = ['assets/dashboard-trips.css'];
$title = 'Dashboard — Vacation Brain';

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

$localTrips = dashboard_trip_rows($pdo, 'local');
$weekendTrips = dashboard_trip_rows($pdo, 'weekend');
if (sample_data_enabled()) {
    $fallbacks = dashboard_sample_trip_fallbacks();
    if (!$localTrips) $localTrips = $fallbacks['local'];
    if (!$weekendTrips) $weekendTrips = $fallbacks['weekend'];
}

$multiDayTrips = [];
if (db_table_exists('destination_catalog')) {
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
        'url'=>app_url('destination-report.php?q='.urlencode((string)($trip['search_query'] ?? $trip['name']))),
    ];
}

function dashboard_review_count(int $count): string
{
    if ($count >= 1000) return rtrim(rtrim(number_format($count / 1000, 1), '0'), '.') . 'k';
    return number_format($count);
}

require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-trip-dashboard">
  <div class="shell">
    <section class="vb-dashboard-section vb-local-section" data-local-section>
      <div class="vb-dashboard-section-head">
        <div class="vb-dashboard-title-wrap"><span class="vb-dashboard-icon">🚗</span><div><h1>Local Day Trips</h1><p>Quick escapes. Big smiles. Adventure is closer than you think.</p></div></div>
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
            $url = app_url('destination-report.php?q='.urlencode((string)($trip['search_query'] ?? $trip['name'])));
          ?>
          <article class="vb-trip-card vb-local-card" data-trip-card data-lat="<?=e((string)($trip['latitude'] ?? ''))?>" data-lng="<?=e((string)($trip['longitude'] ?? ''))?>">
            <a class="vb-trip-image <?=$image===''?'is-placeholder':''?>" href="<?=e($url)?>"<?php if($image!==''):?> style="background-image:url('<?=e(media_url($image))?>')"<?php endif;?>>
              <span class="vb-trip-time" data-trip-time><?=e((string)($trip['duration_text'] ?? 'Nearby'))?></span><span class="vb-trip-heart" aria-hidden="true">♡</span>
            </a>
            <div class="vb-trip-card-body"><h2><?=e((string)$trip['name'])?></h2><p><?=e((string)($trip['subtitle'] ?? $trip['location_text'] ?? ''))?></p><div class="vb-trip-rating"><span>★</span> <?=number_format((float)($trip['rating'] ?? 4.7),1)?> <small>(<?=e(dashboard_review_count((int)($trip['review_count'] ?? 0)))?>)</small></div><a class="vb-trip-details" href="<?=e($url)?>">View Details</a></div>
          </article>
          <?php endforeach;?>
          <?php if(!$localTrips):?><div class="dashboard-card vb-trip-empty"><h2>No local day trips yet.</h2><p class="muted">Turn on Sample Data or add local suggestions to the dashboard catalog.</p></div><?php endif;?>
        </div>
      </div>
    </section>

    <section class="vb-dashboard-section vb-scroll-section">
      <div class="vb-dashboard-section-head compact"><div class="vb-dashboard-title-wrap"><span class="vb-dashboard-icon blue">🧳</span><div><h2>Weekend Getaways</h2><p>Just a couple of days. A whole new you.</p></div></div><a class="vb-see-more" href="<?=e(app_url('destinations.php'))?>">See more getaways →</a></div>
      <div class="vb-wide-trip-grid">
        <?php foreach(array_slice($weekendTrips,0,4) as $trip):
          $image=trim((string)($trip['image_url'] ?? ''));
          $url=app_url('destination-report.php?q='.urlencode((string)($trip['search_query'] ?? $trip['name'])));
        ?>
        <article class="vb-wide-trip-card"><a class="vb-wide-trip-image <?=$image===''?'is-placeholder':''?>" href="<?=e($url)?>"<?php if($image!==''):?> style="background-image:url('<?=e(media_url($image))?>')"<?php endif;?>><span class="vb-trip-heart" aria-hidden="true">♡</span></a><div class="vb-wide-trip-body"><h3><?=e((string)$trip['name'])?></h3><div class="vb-wide-meta"><span>→ <?=e((string)($trip['duration_text'] ?? 'Weekend'))?></span><span><?=e((string)($trip['subtitle'] ?? 'Explore · Relax'))?></span><span class="rating">★ <?=number_format((float)($trip['rating'] ?? 4.6),1)?> <small>(<?=e(dashboard_review_count((int)($trip['review_count'] ?? 0)))?>)</small></span></div></div></article>
        <?php endforeach;?>
        <?php if(!$weekendTrips):?><div class="dashboard-card vb-trip-empty"><h2>No weekend getaways yet.</h2><p class="muted">Sample Data can populate this section while the live catalog grows.</p></div><?php endif;?>
      </div>
    </section>

    <section class="vb-dashboard-section vb-scroll-section">
      <div class="vb-dashboard-section-head compact"><div class="vb-dashboard-title-wrap"><span class="vb-dashboard-icon purple">✈</span><div><h2>Multi-Day Excursions</h2><p>Go further. Stay longer. Make it epic.</p></div></div><a class="vb-see-more" href="<?=e(app_url('destinations.php'))?>">See more excursions →</a></div>
      <div class="vb-wide-trip-grid">
        <?php foreach(array_slice($multiDayTrips,0,4) as $trip):
          $image=trim((string)($trip['hero_image_url'] ?? ''));
          $url=app_url('destination-report.php?destination_id='.(int)$trip['id']);
          $meta=(string)($trip['best_for'] ?? $trip['vibe'] ?? 'Adventure · Escape');
        ?>
        <article class="vb-wide-trip-card"><a class="vb-wide-trip-image <?=$image===''?'is-placeholder':''?>" href="<?=e($url)?>"<?php if($image!==''):?> style="background-image:url('<?=e(media_url($image))?>')"<?php endif;?>><span class="vb-trip-heart" aria-hidden="true">♡</span></a><div class="vb-wide-trip-body"><h3><?=e((string)$trip['name'])?></h3><div class="vb-wide-meta"><span>→ 5–7 days</span><span><?=e($meta)?></span><span class="rating">★ 4.8 <small>(sample)</small></span></div></div></article>
        <?php endforeach;?>
        <?php if(!$multiDayTrips):?><div class="dashboard-card vb-trip-empty"><h2>No multi-day excursions yet.</h2><p class="muted">Add destinations to the catalog or enable Sample Data.</p></div><?php endif;?>
      </div>
    </section>

    <section class="vb-dashboard-cta"><div class="vb-cta-icon">✈</div><div><h2>Not sure where to go?</h2><p>Let Vacation Brain diagnose your perfect escape.</p></div><a class="button primary" href="<?=e(app_url('diagnosis.php'))?>">Take the Vacation Brain Diagnosis <span>→</span></a></section>
  </div>
</section>
<script type="application/json" id="vb-daytrip-data"><?=json_encode($mapTrips, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="<?=e(app_url('assets/dashboard-trips.js'))?>"></script>
<?php require __DIR__.'/partials/footer.php';?>
