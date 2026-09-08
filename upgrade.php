<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/UpgradeService.php';

$adminId = require_admin();
$pdo = db();
$upgrader = new UpgradeService($pdo, __DIR__);
$error = '';
$success = '';
$results = [];

try {
    $upgrader->ensureTrackingTables();
    $baselineCount = $upgrader->bootstrapLegacyHistory($adminId);
    if ($baselineCount > 0) {
        $success = 'Upgrade tracking initialized for this existing installation. ' . $baselineCount . ' previous migrations were safely marked as already applied.';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if ((string)($_POST['action'] ?? '') === 'upgrade') {
            $results = $upgrader->applyPending($adminId);
            $success = $results
                ? 'Vacation Brain upgraded successfully. ' . count($results) . ' migration' . (count($results) === 1 ? '' : 's') . ' applied.'
                : 'Vacation Brain is already up to date.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$currentVersion = $upgrader->currentAppVersion();
$targetVersion = $upgrader->targetAppVersion();
$statusRows = [];
$pending = [];
try {
    $statusRows = $upgrader->migrationStatus();
    $pending = array_values(array_filter($statusRows, fn(array $m): bool => $m['status'] === 'pending'));
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}

$recentRuns = [];
try {
    $recentRuns = $pdo->query('SELECT * FROM upgrade_runs ORDER BY id DESC LIMIT 12')->fetchAll();
} catch (Throwable) {}

$title = 'System Upgrade — Vacation Brain';
require __DIR__ . '/partials/header.php';
?>
<section class="dashboard"><div class="shell">
  <div class="dashboard-head">
    <div><div class="eyebrow">Admin · System</div><h1>Vacation Brain Upgrade</h1><p class="muted">One-click database upgrades. Vacation Brain tracks every numbered SQL migration and only runs files that have not already been applied.</p></div>
    <a class="button secondary small" href="<?=e(app_url('admin/index.php'))?>">Back to Admin</a>
  </div>

  <?php if ($error): ?><div class="notice error" style="margin-bottom:18px"><strong>Upgrade stopped.</strong><br><?=e($error)?></div><?php endif; ?>
  <?php if ($success): ?><div class="notice success" style="margin-bottom:18px"><?=e($success)?></div><?php endif; ?>

  <div class="admin-stats">
    <div class="info-card"><strong><?=e($currentVersion)?></strong><span>installed version</span></div>
    <div class="info-card"><strong><?=e($targetVersion)?></strong><span>available version</span></div>
    <div class="info-card"><strong><?=count($pending)?></strong><span>pending SQL migration<?=count($pending)===1?'':'s'?></span></div>
    <div class="info-card"><strong><?=count($statusRows)-count($pending)?></strong><span>tracked migrations</span></div>
  </div>

  <div class="dashboard-card" style="margin-top:20px">
    <div class="admin-table-head"><div><h2><?= $pending ? 'Upgrade available' : 'Database is current' ?></h2>
      <p class="muted"><?= $pending ? 'The SQL files below will run in numeric order. Completed migrations are never rerun.' : 'There are no unapplied SQL migrations in this release.' ?></p></div></div>
    <?php if ($pending): ?>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th>Migration</th><th>Version</th><th>Size</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($pending as $m): ?><tr><td><strong><?=e($m['filename'])?></strong><br><small class="muted"><?=e(substr($m['checksum'],0,16))?>…</small></td><td><?=e($m['app_version'] ?: '—')?></td><td><?=number_format((int)$m['bytes'])?> bytes</td><td><span class="badge">Pending</span></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <form method="post" style="margin-top:18px" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Upgrading Vacation Brain…';">
        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="upgrade">
        <button class="button primary" type="submit">Run Vacation Brain Upgrade</button>
      </form>
    <?php else: ?><p><strong>✓ No database upgrade is required.</strong></p><?php endif; ?>
  </div>

  <div class="dashboard-card" style="margin-top:20px">
    <div class="admin-table-head"><div><h2>Migration history</h2><p class="muted">Checksums make it visible if an already-applied migration file was later changed.</p></div></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>SQL file</th><th>Status</th><th>Version</th><th>Applied</th></tr></thead><tbody>
    <?php foreach (array_reverse($statusRows) as $m): ?><tr><td><?=e($m['filename'])?></td><td><?php if($m['checksum_changed']):?><strong style="color:#a14747">Changed after install</strong><?php else:?><?=e(ucfirst($m['status']))?><?php endif;?></td><td><?=e($m['app_version'] ?: '—')?></td><td><?=e($m['applied_at'] ?: '—')?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>

  <?php if ($recentRuns): ?><div class="dashboard-card" style="margin-top:20px"><div class="admin-table-head"><div><h2>Recent upgrade runs</h2></div></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Migration</th><th>Status</th><th>Statements</th><th>Runtime</th><th>Started</th></tr></thead><tbody><?php foreach($recentRuns as $run):?><tr><td><?=e($run['filename'])?></td><td><?=e($run['status'])?></td><td><?=(int)$run['statement_count']?></td><td><?=(int)$run['execution_ms']?> ms</td><td><?=e($run['started_at'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php endif; ?>
</div></section>
<?php require __DIR__ . '/partials/footer.php'; ?>
