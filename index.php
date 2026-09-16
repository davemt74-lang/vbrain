<?php
require __DIR__ . '/app/bootstrap.php';

$title = 'Vacation Brain — Discover Your Next Adventure';
$metaDescription = 'Explore destinations, weekend getaways, day trips, multi-day escapes, activities, and AI-powered travel planning with Vacation Brain.';
$pageStyles = ['assets/landing-editorial.css'];

$assetBase = '/assets/landing-guide/';
$assets = [
    'hero' => $assetBase . 'hero-destination-guide.webp',
    'guide_destinations' => $assetBase . 'guide-destinations.webp',
    'guide_trips' => $assetBase . 'guide-trips.webp',
    'guide_activities' => $assetBase . 'guide-activities.webp',
    'guide_guides' => $assetBase . 'guide-travel-guides.webp',
    'getaway_multiday' => $assetBase . 'getaway-multiday.webp',
    'getaway_weekend' => $assetBase . 'getaway-weekend.webp',
    'getaway_daytrip' => $assetBase . 'getaway-daytrip.webp',
    'phone' => $assetBase . 'phone-travel-companion.webp',
    'footer' => $assetBase . 'footer-travel-guide.webp',
];

$media = [];
foreach ($assets as $key => $path) {
    $media[$key] = local_media_exists($path, __DIR__) ? media_url($path) : '';
}

$dashboardUrl = current_user() ? app_url('today.php') : app_url('login.php');
$accountLabel = current_user() ? 'Dashboard' : 'Log in';

require __DIR__ . '/partials/header.php';
?>

<div class="vbg-page">
    <header class="vbg-header">
        <div class="vbg-shell vbg-header-inner">
            <a class="vbg-brand" href="<?=e(app_url('index.php'))?>" aria-label="Vacation Brain home">
                <svg class="vbg-brand-mark" viewBox="0 0 48 32" aria-hidden="true"><path d="M2 27 14 9l8 11 8-16 16 23h-9L30 16l-8 13-8-10-6 8Z" fill="currentColor"/></svg>
                <span>VacationBrain</span>
            </a>
            <nav class="vbg-main-nav" aria-label="Primary navigation">
                <a href="<?=e(app_url('destinations.php'))?>">Destinations</a>
                <a href="#getaways">Trips</a>
                <a href="#activities">Activities</a>
                <a href="#about">About</a>
            </nav>
            <div class="vbg-header-actions">
                <a class="vbg-icon-link" href="<?=e(app_url('destinations.php'))?>" aria-label="Search destinations">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
                </a>
                <a class="vbg-icon-link vbg-account-link" href="<?=e($dashboardUrl)?>" aria-label="<?=e($accountLabel)?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5.5 20c.6-4 3-6 6.5-6s5.9 2 6.5 6"/></svg>
                </a>
                <a class="vbg-icon-link" href="<?=e(app_url('agent.php'))?>" aria-label="Open travel agent">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </a>
            </div>
        </div>
    </header>

    <main>
        <section class="vbg-hero<?= $media['hero'] !== '' ? ' has-image' : '' ?>"<?php if ($media['hero'] !== ''): ?> style="--vbg-hero-image:url('<?=e($media['hero'])?>')"<?php endif; ?>>
            <div class="vbg-hero-shade" aria-hidden="true"></div>
            <div class="vbg-shell vbg-hero-inner">
                <div class="vbg-hero-copy">
                    <div class="vbg-eyebrow light">MORE THAN A TRIP</div>
                    <h1>Discover<br>Your Next<br>Adventure</h1>
                    <p>Explore the world with personalized travel recommendations, destination guides, and AI-powered trip planning.</p>
                </div>

                <form class="vbg-search" action="<?=e(app_url('destinations.php'))?>" method="get" aria-label="Search destinations">
                    <label class="vbg-search-field vbg-search-place">
                        <span class="vbg-field-icon" aria-hidden="true">⌖</span>
                        <span class="sr-only">Destination</span>
                        <input type="search" name="q" placeholder="Where do you want to go?" autocomplete="off" required>
                    </label>
                    <label class="vbg-search-field">
                        <span class="vbg-field-icon" aria-hidden="true">□</span>
                        <span class="sr-only">Travel date</span>
                        <input type="date" name="date" aria-label="Travel date">
                    </label>
                    <label class="vbg-search-field vbg-travelers">
                        <span class="vbg-field-icon" aria-hidden="true">♙</span>
                        <span class="sr-only">Travelers</span>
                        <select name="travelers" aria-label="Travelers">
                            <option value="1">1 traveler</option>
                            <option value="2" selected>2 travelers</option>
                            <option value="3">3 travelers</option>
                            <option value="4">4 travelers</option>
                            <option value="5">5+ travelers</option>
                        </select>
                    </label>
                    <button type="submit" class="vbg-search-button"><span aria-hidden="true">⌕</span> Search</button>
                </form>
            </div>
        </section>

        <section class="vbg-guide-row" aria-labelledby="guide-title">
            <div class="vbg-shell">
                <h2 id="guide-title" class="sr-only">Explore Vacation Brain</h2>
                <div class="vbg-guide-grid">
                    <a class="vbg-guide-card" href="<?=e(app_url('destinations.php'))?>">
                        <img src="<?=e($media['guide_destinations'])?>" alt="Mountain lake destination" loading="lazy">
                        <div class="vbg-guide-body"><span class="vbg-guide-icon">⌑</span><div><h3>Destinations</h3><p>Find amazing places around the world</p></div><span class="vbg-arrow">→</span></div>
                    </a>
                    <a class="vbg-guide-card" href="<?=e(app_url('diagnosis.php'))?>">
                        <img src="<?=e($media['guide_trips'])?>" alt="Tropical island getaway" loading="lazy">
                        <div class="vbg-guide-body"><span class="vbg-guide-icon">▢</span><div><h3>Trips</h3><p>Curated getaways for every travel style</p></div><span class="vbg-arrow">→</span></div>
                    </a>
                    <a class="vbg-guide-card" href="<?=e(app_url('destinations.php?q=activities'))?>">
                        <img src="<?=e($media['guide_activities'])?>" alt="Snorkeling with a sea turtle" loading="lazy">
                        <div class="vbg-guide-body"><span class="vbg-guide-icon">◉</span><div><h3>Activities</h3><p>Experiences that make memories</p></div><span class="vbg-arrow">→</span></div>
                    </a>
                    <a class="vbg-guide-card" href="<?=e(app_url('destination-report.php'))?>">
                        <img src="<?=e($media['guide_guides'])?>" alt="Mediterranean coastal village" loading="lazy">
                        <div class="vbg-guide-body"><span class="vbg-guide-icon">◇</span><div><h3>Travel Guides</h3><p>Local tips, dining, culture and more</p></div><span class="vbg-arrow">→</span></div>
                    </a>
                </div>
            </div>
        </section>

        <section class="vbg-section vbg-getaways" id="getaways">
            <div class="vbg-shell">
                <div class="vbg-section-head">
                    <div><div class="vbg-eyebrow">TRIPS FOR THE TIME YOU HAVE</div><h2>Popular Getaways</h2><p>Handpicked ideas for every kind of traveler.</p></div>
                    <a class="vbg-view-all" href="<?=e(app_url('destinations.php'))?>">View All Trips <span>→</span></a>
                </div>
                <div class="vbg-getaway-grid">
                    <a class="vbg-getaway-card" href="<?=e(app_url('destinations.php?q=Amalfi'))?>">
                        <div class="vbg-getaway-image"><img src="<?=e($media['getaway_multiday'])?>" alt="Amalfi Coast, Italy" loading="lazy"><span class="vbg-trip-badge">Multi-Day</span></div>
                        <div class="vbg-getaway-copy"><h3>Italy’s Amalfi Coast</h3><p><strong>5 Days</strong><span>Culture</span><span>Food</span><span>Coastal Towns</span></p></div>
                    </a>
                    <a class="vbg-getaway-card" href="<?=e(app_url('destinations.php?q=mountain'))?>">
                        <div class="vbg-getaway-image"><img src="<?=e($media['getaway_weekend'])?>" alt="Rocky Mountain lake escape" loading="lazy"><span class="vbg-trip-badge">Weekend</span></div>
                        <div class="vbg-getaway-copy"><h3>Rocky Mountain Escape</h3><p><strong>3 Days</strong><span>Hiking</span><span>Nature</span><span>Scenic Views</span></p></div>
                    </a>
                    <a class="vbg-getaway-card" href="<?=e(app_url('destinations.php?q=Utah'))?>">
                        <div class="vbg-getaway-image"><img src="<?=e($media['getaway_daytrip'])?>" alt="Desert arch at sunset" loading="lazy"><span class="vbg-trip-badge">Day Trip</span></div>
                        <div class="vbg-getaway-copy"><h3>Arches National Park</h3><p><strong>1 Day</strong><span>Hiking</span><span>Photography</span><span>Nature</span></p></div>
                    </a>
                </div>
            </div>
        </section>

        <section class="vbg-section vbg-activities" id="activities">
            <div class="vbg-shell">
                <div class="vbg-section-head compact">
                    <div><div class="vbg-eyebrow">TOP ACTIVITIES</div><h2>Explore by what you love</h2><p>From outdoor adventures to cultural experiences.</p></div>
                    <a class="vbg-view-all" href="<?=e(app_url('destinations.php?q=activities'))?>">View All Activities <span>→</span></a>
                </div>
                <div class="vbg-activity-grid">
                    <a href="<?=e(app_url('destinations.php?q=hiking'))?>"><span class="vbg-activity-icon">♢</span><strong>Hiking</strong><small>Trails & scenic views</small></a>
                    <a href="<?=e(app_url('destinations.php?q=water'))?>"><span class="vbg-activity-icon">≋</span><strong>Water Sports</strong><small>Ocean, lakes & rivers</small></a>
                    <a href="<?=e(app_url('destinations.php?q=culture'))?>"><span class="vbg-activity-icon">◎</span><strong>Tours & Sightseeing</strong><small>Landmarks & culture</small></a>
                    <a href="<?=e(app_url('destinations.php?q=food'))?>"><span class="vbg-activity-icon">⋔</span><strong>Food & Drink</strong><small>Local flavors & dining</small></a>
                    <a href="<?=e(app_url('destinations.php?q=wellness'))?>"><span class="vbg-activity-icon">◒</span><strong>Wellness</strong><small>Relax & recharge</small></a>
                    <a href="<?=e(app_url('destinations.php?q=events'))?>"><span class="vbg-activity-icon">☆</span><strong>Events</strong><small>Festivals & more</small></a>
                </div>
            </div>
        </section>

        <section class="vbg-companion" id="about">
            <div class="vbg-shell vbg-companion-grid">
                <div class="vbg-phone-stage">
                    <?php if ($media['phone'] !== ''): ?><img src="<?=e($media['phone'])?>" alt="Vacation Brain mobile travel companion screens" loading="lazy"><?php endif; ?>
                </div>
                <div class="vbg-companion-copy">
                    <div class="vbg-eyebrow">TRAVEL SMARTER</div>
                    <h2>Your personal<br>travel companion</h2>
                    <p>Get real-time recommendations, save your favorite places, and build the perfect itinerary with your AI travel assistant.</p>
                    <div class="vbg-companion-actions">
                        <a class="vbg-primary-button" href="<?=e(app_url(current_user() ? 'today.php' : 'signup.php'))?>">Explore the App <span>→</span></a>
                        <span class="vbg-platform-note">Available on web now</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="vbg-footer-hero<?= $media['footer'] !== '' ? ' has-image' : '' ?>"<?php if ($media['footer'] !== ''): ?> style="--vbg-footer-image:url('<?=e($media['footer'])?>')"<?php endif; ?>>
            <div class="vbg-footer-shade" aria-hidden="true"></div>
            <div class="vbg-shell vbg-footer-hero-inner">
                <div>
                    <div class="vbg-eyebrow light">YOUR NEXT JOURNEY AWAITS</div>
                    <h2>Real destinations.<br>Personalized for you.</h2>
                    <p>Plan smarter. Explore further. Make it yours.</p>
                    <a class="vbg-light-button" href="<?=e(app_url('destinations.php'))?>"><span>⌕</span> Start Your Search <span>→</span></a>
                </div>
            </div>
        </section>
    </main>

    <footer class="vbg-footer">
        <div class="vbg-shell vbg-footer-inner">
            <a class="vbg-brand small" href="<?=e(app_url('index.php'))?>"><svg class="vbg-brand-mark" viewBox="0 0 48 32" aria-hidden="true"><path d="M2 27 14 9l8 11 8-16 16 23h-9L30 16l-8 13-8-10-6 8Z" fill="currentColor"/></svg><span>VacationBrain</span></a>
            <nav aria-label="Footer navigation"><a href="<?=e(app_url('destinations.php'))?>">Destinations</a><a href="#getaways">Trips</a><a href="#activities">Activities</a><a href="<?=e(app_url('agent.php'))?>">AI Agent</a></nav>
            <span>AI-assisted travel planning for better escapes.</span>
        </div>
    </footer>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
