<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$userId = require_auth();
$pdo = db();

function dashboard_tabs_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dashboard_tabs_snapshot(PDO $pdo, int $userId): array
{
    if (!db_table_exists('dashboard_agent_tabs')) {
        return ['ok'=>false,'upgrade_required'=>true,'tabs'=>[]];
    }
    $stmt = $pdo->prepare('SELECT id,name,purpose,sort_order,settings_json,created_at,updated_at FROM dashboard_agent_tabs WHERE user_id=? ORDER BY sort_order ASC,id ASC');
    $stmt->execute([$userId]);
    return ['ok'=>true,'tabs'=>$stmt->fetchAll() ?: []];
}

if (!db_table_exists('dashboard_agent_tabs')) {
    dashboard_tabs_json(['ok'=>false,'upgrade_required'=>true,'error'=>'Run System Upgrade before creating agent tabs.'], 409);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    dashboard_tabs_json(dashboard_tabs_snapshot($pdo, $userId));
}

$provided = (string)($_POST['_csrf'] ?? '');
$expected = (string)($_SESSION['_csrf'] ?? '');
if ($expected === '' || !hash_equals($expected, $provided)) {
    dashboard_tabs_json(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'], 419);
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'create') {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM dashboard_agent_tabs WHERE user_id=?');
        $countStmt->execute([$userId]);
        if ((int)$countStmt->fetchColumn() >= 12) {
            throw new RuntimeException('You can create up to 12 dashboard agents.');
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        if ($name === '') $name = 'New Agent';
        if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
        if (mb_strlen($purpose) > 500) $purpose = mb_substr($purpose, 0, 500);

        $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM dashboard_agent_tabs WHERE user_id=?');
        $sortStmt->execute([$userId]);
        $sortOrder = (int)$sortStmt->fetchColumn();

        $stmt = $pdo->prepare('INSERT INTO dashboard_agent_tabs (user_id,name,purpose,sort_order,settings_json) VALUES (?,?,?,?,JSON_OBJECT())');
        $stmt->execute([$userId,$name,$purpose,$sortOrder]);
        $id = (int)$pdo->lastInsertId();
        dashboard_tabs_json(['ok'=>true,'tab'=>['id'=>$id,'name'=>$name,'purpose'=>$purpose,'sort_order'=>$sortOrder]]);
    }

    if ($action === 'update') {
        $id = (int)($_POST['tab_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        if ($id < 1) throw new InvalidArgumentException('Invalid agent tab.');
        if ($name === '') throw new InvalidArgumentException('Agent name is required.');
        if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
        if (mb_strlen($purpose) > 500) $purpose = mb_substr($purpose, 0, 500);

        $stmt = $pdo->prepare('UPDATE dashboard_agent_tabs SET name=?,purpose=? WHERE id=? AND user_id=?');
        $stmt->execute([$name,$purpose,$id,$userId]);
        if ($stmt->rowCount() < 1) {
            $check = $pdo->prepare('SELECT id FROM dashboard_agent_tabs WHERE id=? AND user_id=?');
            $check->execute([$id,$userId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Agent tab not found.');
        }
        dashboard_tabs_json(['ok'=>true,'tab'=>['id'=>$id,'name'=>$name,'purpose'=>$purpose]]);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['tab_id'] ?? 0);
        if ($id < 1) throw new InvalidArgumentException('Invalid agent tab.');
        $stmt = $pdo->prepare('DELETE FROM dashboard_agent_tabs WHERE id=? AND user_id=?');
        $stmt->execute([$id,$userId]);
        dashboard_tabs_json(['ok'=>true,'deleted_id'=>$id]);
    }

    throw new InvalidArgumentException('Unknown action.');
} catch (Throwable $e) {
    dashboard_tabs_json(['ok'=>false,'error'=>$e->getMessage()], 400);
}
