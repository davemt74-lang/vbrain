<?php
require __DIR__.'/app/bootstrap.php';$userId=require_auth();$profile=(new VacationProfileService(db()))->snapshot($userId);$title='Escape — Vacation Brain';require __DIR__.'/partials/header.php';
?>
<section class="dashboard"><div class="shell"><div class="dashboard-head"><div><div class="eyebrow">Escape</div><h1>Go somewhere. Mentally counts.</h1><p class="muted">Your <?=$profile['archetype']['name']?> profile currently recommends low-stakes escapism.</p></div></div>
<div class="escape-grid">
<a class="escape-card featured" href="<?=e(app_url('substitutions.php'))?>"><span>LOCAL ESCAPE</span><h2>Vacation Substitutions</h2><p>Can’t leave town? Build a fake vacation day from local food, pools, spas, neighborhoods and activities.</p><strong>Fake it better →</strong></a>
<a class="escape-card" href="<?=e(app_url('weather-envy.php'))?>"><span>WEATHER ENVY</span><h2>Somewhere has better weather.</h2><p>Compare where you are with where your Vacation Brain would rather be.</p><strong>Make yourself jealous →</strong></a>
<a class="escape-card" href="<?=e(app_url('breaks.php'))?>"><span>1–5 MINUTES</span><h2>Vacation Breaks</h2><p>Take a tiny imaginary escape before somebody schedules another meeting.</p><strong>Take a break →</strong></a>
<a class="escape-card" href="<?=e(app_url('generator.php?type=vacation_excuse'))?>"><span>OFFICIAL NONSENSE</span><h2>Vacation Excuse Generator</h2><p>Generate a completely unnecessary justification for needing a vacation.</p><strong>Generate excuse →</strong></a>
<a class="escape-card" href="<?=e(app_url('generator.php?type=out_of_office'))?>"><span>MENTALLY ABSENT</span><h2>Out-of-Office Generator</h2><p>Write the message now. Decide whether you are actually leaving later.</p><strong>Go OOO →</strong></a>
<a class="escape-card" href="<?=e(app_url('roast.php'))?>"><span>POOR DECISIONS REVIEW</span><h2>Roast My Vacation Brain</h2><p>Allow the app to use everything it has learned about you against you.</p><strong>I can take it →</strong></a>
</div></div></section><?php require __DIR__.'/partials/footer.php';?>
