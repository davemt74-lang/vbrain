<?php
require __DIR__ . '/app/bootstrap.php';

$title = 'Vacation Brain — Your AI Travel Agent';
$metaDescription = 'Vacation Brain is a travel AI agent for finding day trips, planning weekend getaways, building multi-day itineraries, and turning your travel mood into a real plan.';
$pageStyles = ['assets/landing-editorial.css'];

$heroAsset = '/assets/landing/hero-sunset-coast.webp';
$agentAsset = '/assets/landing/agent-getaway.webp';
$phoneAsset = '/assets/landing/phone-ai-agent.webp';
$heroExists = local_media_exists($heroAsset, __DIR__);
$agentExists = local_media_exists($agentAsset, __DIR__);
$phoneExists = local_media_exists($phoneAsset, __DIR__);

$landingDestinations = [];
$landingOwnerReady = false;
if (db_table_exists('destination_catalog')) {
    try {
        $pdo = db();
        $ownerService = new DestinationOwnerService($pdo);
        $landingOwnerReady = $ownerService->ready();
        $sampleClause = (db_column_exists('destination_catalog', 'is_sample') && !sample_data_enabled()) ? ' AND d.is_sample=0' : '';
        $hasGallery = db_table_exists('destination_gallery_images');
        $galleryHasRole = $hasGallery && db_column_exists('destination_gallery_images', 'image_role');
        if ($hasGallery) {
            $galleryOrder = $galleryHasRole ? "CASE WHEN COALESCE(gi.image_role,'')='hero' THEN 0 ELSE 1 END," : '';
            $gallerySelect = "(SELECT gi.image_url FROM destination_gallery_images gi WHERE gi.destination_catalog_id=d.id ORDER BY {$galleryOrder}gi.sort_order ASC,gi.id ASC LIMIT 1) AS gallery_image_url";
        } else {
            $gallerySelect = 'NULL AS gallery_image_url';
        }
        $publicationClause = ($landingOwnerReady && db_column_exists('destination_catalog', 'publication_status')) ? " AND d.publication_status='published'" : '';
        $stmt = $pdo->query("SELECT d.*, {$gallerySelect} FROM destination_catalog d WHERE d.status='active'{$publicationClause}{$sampleClause} ORDER BY d.featured DESC,d.sort_order ASC,d.name ASC LIMIT 12");
        $landingDestinations = $stmt->fetchAll() ?: [];
        foreach ($landingDestinations as &$destination) {
            $hero = trim((string)($destination['hero_image_url'] ?? ''));
            $gallery = trim((string)($destination['gallery_image_url'] ?? ''));
            $displayImage = '';
            foreach ([$hero, $gallery] as $candidate) {
                if ($candidate !== '' && local_media_exists($candidate, __DIR__)) {
                    $displayImage = media_url($candidate);
                    break;
                }
            }
            $destination['display_image'] = $displayImage;
            $destination['landing_url'] = $landingOwnerReady
                ? app_url('destination.php?destination_id=' . (int)$destination['id'])
                : app_url('destination-report.php?destination_id=' . (int)$destination['id']);
        }
        unset($destination);
    } catch (Throwable $e) {
        error_log('Vacation Brain landing destinations: ' . $e->getMessage());
        $landingDestinations = [];
    }
}

$tripCards = array_slice($landingDestinations, 0, 4);
$destinationStrip = array_slice($landingDestinations, 0, 9);
$tripFallbacks = [
    ['name' => 'Coastal Day Escape', 'city' => 'Nearby', 'region' => '', 'country' => '', 'short_description' => 'Ocean views, great food, and a total reset — all within reach.', 'typical_duration' => '1 day', 'display_image' => '', 'landing_url' => app_url('destinations.php')],
    ['name' => 'Desert Weekend', 'city' => 'Southwest', 'region' => '', 'country' => '', 'short_description' => 'Sunsets, spa days, wide open spaces, and a change of pace.', 'typical_duration' => '2–3 days', 'display_image' => '', 'landing_url' => app_url('destinations.php')],
    ['name' => 'Mountain Recharge', 'city' => 'Mountains', 'region' => '', 'country' => '', 'short_description' => 'Hike, breathe, unplug, and get back to what matters.', 'typical_duration' => '2–4 days', 'display_image' => '', 'landing_url' => app_url('destinations.php')],
    ['name' => 'Hidden City Stay', 'city' => 'City escape', 'region' => '', 'country' => '', 'short_description' => 'Good streets, memorable food, and somewhere new to wander.', 'typical_duration' => '2–3 days', 'display_image' => '', 'landing_url' => app_url('destinations.php')],
];
while (count($tripCards) < 4) {
    $tripCards[] = $tripFallbacks[count($tripCards)];
}

require __DIR__ . '/partials/header.php';
?>

<div class="vbe-page">
<section class="vbe-hero <?=$heroExists ? 'has-image' : ''?>"<?php if ($heroExists): ?> style="--vbe-hero-image:url('<?=e(media_url($heroAsset))?>')"<?php endif; ?>>
    <div class="vbe-hero-overlay" aria-hidden="true"></div>
    <div class="vbe-shell vbe-nav-wrap">
        <a class="vbe-brand" href="<?=e(app_url('index.php'))?>">VACATION BRAIN</a>
        <nav class="vbe-nav" aria-label="Vacation Brain landing navigation">
            <a href="<?=e(app_url('destinations.php'))?>">Destinations</a>
            <a href="#how-it-works">How It Works</a>
            <a href="<?=e(app_url('agent.php'))?>">AI Agent</a>
            <a href="#app">App</a>
            <?php if (current_user()): ?>
                <a href="<?=e(app_url('today.php'))?>">Dashboard</a>
            <?php else: ?>
                <a href="<?=e(app_url('login.php'))?>">Sign In</a>
            <?php endif; ?>
            <a class="vbe-nav-cta" href="<?=e(app_url('diagnosis.php'))?>">Start Diagnosis</a>
        </nav>
    </div>

    <div class="vbe-shell vbe-hero-content">
        <div class="vbe-hero-copy">
            <div class="vbe-kicker">TRAVEL DIFFERENTLY</div>
            <h1>Your travel mood,<br><em>diagnosed.</em></h1>
            <p class="vbe-hero-lead">Meet the AI agent for day trips, weekend getaways, and unforgettable escapes.</p>
            <p class="vbe-hero-body">Tell us your vibe, and Vacation Brain turns it into personalized trip ideas, smarter planning, and a real itinerary — with the power of AI.</p>
            <div class="vbe-actions">
                <a class="vbe-btn vbe-btn-light" href="<?=e(app_url('diagnosis.php'))?>">Take the Vacation Brain Diagnosis <span>↗</span></a>
                <a class="vbe-btn vbe-btn-ghost" href="<?=e(app_url('agent.php'))?>">Talk to the Travel AI Agent <span>◯</span></a>
            </div>
        </div>
        <a class="vbe-scroll-cue" href="#trips"><span>↓</span> Explore destinations</a>
    </div>
</section>

<?php if ($destinationStrip): ?>
<section class="vbe-destination-strip" aria-label="Featured destinations">
    <div class="vbe-strip-track vbe-shell">
        <?php foreach ($destinationStrip as $destination): ?>
            <a class="vbe-strip-item" href="<?=e((string)$destination['landing_url'])?>">
                <span class="vbe-strip-thumb <?=$destination['display_image'] === '' ? 'is-placeholder' : ''?>"<?php if ($destination['display_image'] !== ''): ?> style="background-image:url('<?=e((string)$destination['display_image'])?>')"<?php endif; ?>></span>
                <span><?=e((string)$destination['name'])?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="vbe-section vbe-trips" id="trips">
    <div class="vbe-shell">
        <div class="vbe-section-head">
            <div><div class="vbe-kicker dark">AI CURATED GETAWAYS</div><h2>Trips worth acting on.</h2></div>
            <p>From quick day trips to bucket-list adventures, get personalized recommendations for every kind of traveler.</p>
        </div>
        <div class="vbe-trip-grid">
            <?php foreach ($tripCards as $index => $destination):
                $location = trim(implode(', ', array_filter([(string)($destination['city'] ?? ''), (string)($destination['region'] ?? '')])));
                if ($location === '') $location = (string)($destination['country'] ?? 'Explore');
                $duration = trim((string)($destination['typical_duration'] ?? '')) ?: ['1 day','2–3 days','2–4 days','3–5 days'][$index];
            ?>
            <article class="vbe-trip-card">
                <a class="vbe-trip-image <?=$destination['display_image'] === '' ? 'is-placeholder trip-'.$index : ''?>" href="<?=e((string)$destination['landing_url'])?>"<?php if ($destination['display_image'] !== ''): ?> style="background-image:url('<?=e((string)$destination['display_image'])?>')"<?php endif; ?> aria-label="View <?=e((string)$destination['name'])?>"></a>
                <div class="vbe-trip-body">
                    <span class="vbe-trip-icon"><?=['☼','⌁','△','▥'][$index]?></span>
                    <h3><a href="<?=e((string)$destination['landing_url'])?>"><?=e((string)$destination['name'])?></a></h3>
                    <div class="vbe-trip-meta"><span>⌖ <?=e($location)?></span><span>◷ <?=e($duration)?></span></div>
                    <p><?=e((string)($destination['short_description'] ?? 'A trip worth leaving the house for.'))?></p>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <div class="vbe-center-link"><a href="<?=e(app_url('destinations.php'))?>">Explore all destinations →</a></div>
    </div>
</section>

<section class="vbe-section vbe-how" id="how-it-works">
    <div class="vbe-shell">
        <div class="vbe-section-head">
            <div><div class="vbe-kicker dark">POWERED BY AI. INSPIRED BY YOU.</div><h2>A smarter way to plan.</h2></div>
            <p>Vacation Brain combines AI, your travel preferences, and destination intelligence to turn a mood into a real plan.</p>
        </div>
        <div class="vbe-feature-grid">
            <article><span>◉</span><h3>Travel diagnosis</h3><p>Answer a few fun questions and we’ll learn what kind of escape your brain is asking for.</p></article>
            <article><span>✦</span><h3>AI itineraries</h3><p>Build custom day-by-day plans around your interests, timing, energy, and travel style.</p></article>
            <article><span>▣</span><h3>Budget-aware suggestions</h3><p>Compare realistic options for spontaneous day trips, weekend resets, and bigger adventures.</p></article>
            <article><span>◎</span><h3>Human-feeling guidance</h3><p>Ask follow-up questions, change your mind, and let the travel agent keep the plan moving.</p></article>
        </div>
    </div>
</section>

<section class="vbe-app" id="app">
    <div class="vbe-shell vbe-app-grid">
        <div class="vbe-phone-stage" aria-label="Vacation Brain app preview">
            <?php if ($phoneExists): ?>
                <img class="vbe-phone vbe-phone-back" src="<?=e(media_url($phoneAsset))?>" alt="Vacation Brain mobile AI travel agent preview" loading="lazy">
                <img class="vbe-phone vbe-phone-front" src="<?=e(media_url($phoneAsset))?>" alt="Vacation Brain mobile trip planning preview" loading="lazy">
            <?php else: ?>
                <div class="vbe-phone-placeholder">Vacation Brain<br><strong>Your AI travel agent</strong></div>
            <?php endif; ?>
        </div>
        <div class="vbe-app-copy">
            <div class="vbe-kicker dark">THE VACATION BRAIN EXPERIENCE</div>
            <h2>Everything you need.<br>Nothing you don’t.</h2>
            <p class="vbe-app-lead">Your travel AI agent, always with you. Discover, plan, save, and explore — all in one place.</p>
            <div class="vbe-app-rows">
                <div><strong>Before</strong><span>Get inspired, take your travel diagnosis, and plan with AI.</span></div>
                <div><strong>During</strong><span>Keep your itinerary close and ask the agent to adapt as plans change.</span></div>
                <div><strong>After</strong><span>Save what worked and make the next recommendation smarter.</span></div>
            </div>
            <div class="vbe-actions dark-actions">
                <a class="vbe-btn vbe-btn-dark" href="<?=e(app_url(current_user() ? 'today.php' : 'signup.php'))?>">Open Vacation Brain <span>→</span></a>
                <a class="vbe-text-link" href="<?=e(app_url('agent.php'))?>">Meet the AI agent ↗</a>
            </div>
        </div>
    </div>
</section>

<section class="vbe-stats">
    <div class="vbe-shell">
        <div class="vbe-section-head compact">
            <div><div class="vbe-kicker dark">BUILT FOR REAL ESCAPES</div><h2>From nearby to far away.</h2></div>
            <p>Vacation Brain is designed around the amount of time you actually have — not just the trips you save and never take.</p>
        </div>
        <div class="vbe-stat-grid">
            <div><strong>1</strong><span>DAY TRIPS</span></div>
            <div><strong>2–3</strong><span>WEEKEND DAYS</span></div>
            <div><strong>4+</strong><span>MULTI-DAY PLANS</span></div>
            <div><strong>24/7</strong><span>AI TRAVEL AGENT</span></div>
        </div>
    </div>
</section>

<section class="vbe-agent-band <?=$agentExists ? 'has-image' : ''?>"<?php if ($agentExists): ?> style="--vbe-agent-image:url('<?=e(media_url($agentAsset))?>')"<?php endif; ?>>
    <div class="vbe-agent-overlay" aria-hidden="true"></div>
    <div class="vbe-shell vbe-agent-content">
        <div class="vbe-agent-head">
            <div><div class="vbe-kicker">FOR EVERY KIND OF TRAVELER</div><h2>The agent makes<br>the getaway possible.</h2></div>
            <p>Whether you have a free afternoon or a few weeks, Vacation Brain helps turn travel intentions into real experiences.</p>
        </div>
        <div class="vbe-agent-grid">
            <article><span>◈</span><h3>Local explorers</h3><p>Find day trips, hidden gems, and easy escapes close enough to do something about today.</p><a href="<?=e(app_url('destinations.php'))?>">Explore near you →</a></article>
            <article><span>□</span><h3>Weekend reset seekers</h3><p>Turn two or three open days into a trip with the right pace, place, and itinerary.</p><a href="<?=e(app_url('diagnosis.php'))?>">Find a weekend trip →</a></article>
            <article><span>△</span><h3>Multi-day adventurers</h3><p>Build a bigger escape with destination research, lodging ideas, activities, and an AI plan.</p><a href="<?=e(app_url('agent.php'))?>">Plan a bigger trip →</a></article>
        </div>
    </div>
</section>

<section class="vbe-newsletter">
    <div class="vbe-shell vbe-newsletter-grid">
        <div>
            <div class="vbe-kicker dark">START SOMEWHERE</div>
            <h2>Give your brain<br>something to plan.</h2>
            <p>Take the diagnosis, save destinations, and let Vacation Brain start building the kind of getaway you’ll actually want to take.</p>
        </div>
        <div class="vbe-signup-card">
            <span class="vbe-kicker dark">YOUR NEXT TRIP STARTS HERE</span>
            <h3>Meet your travel AI agent.</h3>
            <p>No generic travel search. Tell Vacation Brain what you need and let the agent narrow the world down for you.</p>
            <a class="vbe-btn vbe-btn-dark full" href="<?=e(app_url('diagnosis.php'))?>">Take the Vacation Brain Diagnosis</a>
            <a class="vbe-signin-link" href="<?=e(app_url(current_user() ? 'today.php' : 'login.php'))?>"><?=current_user() ? 'Go to your dashboard' : 'Already have an account? Sign in'?> →</a>
        </div>
    </div>
</section>

<footer class="vbe-footer">
    <div class="vbe-shell vbe-footer-grid">
        <div><strong>VACATION BRAIN</strong><small>TRAVEL MORE HUMAN</small></div>
        <nav aria-label="Footer"><a href="<?=e(app_url('destinations.php'))?>">Destinations</a><a href="#how-it-works">How It Works</a><a href="<?=e(app_url('agent.php'))?>">AI Agent</a><a href="<?=e(app_url('shop.php'))?>">Shop</a></nav>
        <div class="vbe-footer-note">AI-assisted travel planning for brighter escapes.</div>
    </div>
</footer>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
