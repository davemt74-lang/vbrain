<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function shell_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function shell_cart_snapshot(): array
{
    $raw = $_SESSION['shop_cart'] ?? [];
    $cart = is_array($raw) ? $raw : [];
    $normalized = [];
    foreach ($cart as $productId => $qty) {
        $id = (int)$productId;
        $count = max(0, min(10, (int)$qty));
        if ($id > 0 && $count > 0) $normalized[$id] = $count;
    }
    $_SESSION['shop_cart'] = $normalized;

    if (!$normalized || !db_table_exists('merch_catalog_products')) {
        return ['count' => 0, 'subtotal' => 0, 'items' => []];
    }

    $ids = array_keys($normalized);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id,slug,name,price,image_url FROM merch_catalog_products WHERE id IN ($marks) AND status='active'");
    $stmt->execute($ids);

    $items = [];
    $count = 0;
    $subtotal = 0.0;
    $found = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $id = (int)$row['id'];
        $qty = (int)($normalized[$id] ?? 0);
        if ($qty < 1) continue;
        $found[$id] = true;
        $price = (float)$row['price'];
        $lineTotal = $price * $qty;
        $count += $qty;
        $subtotal += $lineTotal;
        $image = trim((string)($row['image_url'] ?? ''));
        $items[] = [
            'id' => $id,
            'name' => (string)$row['name'],
            'price' => round($price, 2),
            'qty' => $qty,
            'line_total' => round($lineTotal, 2),
            'image_url' => $image !== '' ? media_url($image) : '',
            'product_url' => app_url('shop-product.php?slug=' . urlencode((string)$row['slug'])),
        ];
    }

    foreach (array_keys($normalized) as $id) {
        if (!isset($found[$id])) unset($_SESSION['shop_cart'][$id]);
    }

    return ['count' => $count, 'subtotal' => round($subtotal, 2), 'items' => $items];
}

function shell_notification_url(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return app_url('notifications.php');
    if (preg_match('#^https?://#i', $value)) return $value;
    return app_url(ltrim($value, '/'));
}

function shell_notification_snapshot(?int $userId): array
{
    if (!$userId) {
        return [
            'guest' => true,
            'unread_count' => 0,
            'items' => [],
            'login_url' => app_url('login.php'),
        ];
    }

    try {
        $service = new NotificationService(db());
        $recent = $service->recent($userId, 8, false);
        $items = [];
        foreach ($recent as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'body' => trim((string)($row['body'] ?? '')),
                'created_at' => (string)($row['created_at'] ?? ''),
                'unread' => empty($row['read_at']),
                'url' => shell_notification_url($row['action_url'] ?? null),
            ];
        }
        return [
            'guest' => false,
            'unread_count' => $service->unreadCount($userId),
            'items' => $items,
            'all_url' => app_url('notifications.php'),
        ];
    } catch (Throwable $e) {
        return ['guest' => false, 'unread_count' => 0, 'items' => [], 'all_url' => app_url('notifications.php')];
    }
}

function shell_state(): array
{
    return [
        'ok' => true,
        'cart' => shell_cart_snapshot(),
        'notifications' => shell_notification_snapshot(auth_user_id()),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = (string)($_POST['_csrf'] ?? '');
    $expected = (string)($_SESSION['_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        shell_json(['ok' => false, 'error' => 'Your session expired. Refresh and try again.'], 419);
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'cart_remove') {
            $id = (int)($_POST['product_id'] ?? 0);
            if ($id > 0) unset($_SESSION['shop_cart'][$id]);
        } elseif ($action === 'cart_qty') {
            $id = (int)($_POST['product_id'] ?? 0);
            $qty = max(0, min(10, (int)($_POST['qty'] ?? 0)));
            if ($id < 1) throw new InvalidArgumentException('Invalid product.');
            if ($qty === 0) unset($_SESSION['shop_cart'][$id]);
            else $_SESSION['shop_cart'][$id] = $qty;
        } elseif ($action === 'notifications_read_all') {
            $userId = auth_user_id();
            if (!$userId) throw new RuntimeException('Log in to manage notifications.');
            (new NotificationService(db()))->markAllRead($userId);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        shell_json(shell_state());
    } catch (Throwable $e) {
        shell_json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

shell_json(shell_state());
