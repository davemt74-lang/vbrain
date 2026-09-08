<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    global $config;
    $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verify_csrf(): void
{
    $provided = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['_csrf'] ?? ''), $provided)) {
        http_response_code(419);
        exit('Your session expired. Please refresh and try again.');
    }
}

function auth_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function require_auth(): int
{
    $id = auth_user_id();
    if (!$id) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? app_url('today.php');
        redirect('login.php');
    }
    return $id;
}


function installation_owner_user_id(): ?int
{
    try {
        if (!db_table_exists('site_settings')) return null;
        $raw = site_setting('installation.owner_user_id');
        if ($raw === null || !ctype_digit((string)$raw) || (int)$raw < 1) return null;
        $id = (int)$raw;
        $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND status='active' LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ? $id : null;
    } catch (Throwable) {
        return null;
    }
}

function auth_user_role(): ?string
{
    $id = auth_user_id();
    if (!$id) return null;

    try {
        // Once an installation owner has been claimed, that account is always Admin.
        $ownerId = installation_owner_user_id();
        if ($ownerId !== null && $ownerId === $id) {
            if (db_column_exists('user_auth','role')) {
                db()->prepare('UPDATE user_auth SET role=? WHERE user_id=? AND role<>?')->execute(['admin',$id,'admin']);
            }
            return 'admin';
        }

        /*
         * Legacy recovery: the earliest authenticated active account is treated as
         * the primary install user when no explicit owner has been recorded yet.
         * The separate Admin Access recovery page covers older installs where test
         * accounts happened to be created before the actual owner.
         */
        if ($ownerId === null) {
            $firstStmt = db()->query("SELECT MIN(ua.user_id) FROM user_auth ua JOIN users u ON u.id=ua.user_id WHERE u.status='active'");
            $first = $firstStmt ? $firstStmt->fetchColumn() : false;
            if ($first !== false && (int)$first === $id) {
                if (db_column_exists('user_auth','role')) {
                    db()->prepare('UPDATE user_auth SET role=? WHERE user_id=?')->execute(['admin',$id]);
                }
                return 'admin';
            }
        }

        if (!db_column_exists('user_auth','role')) return 'user';
        $stmt = db()->prepare('SELECT role FROM user_auth WHERE user_id=?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        $role = strtolower(trim((string)($raw === false ? '' : $raw)));

        $adminAliases = ['admin','administrator','system_admin','system-admin','superadmin','super_admin','owner'];
        $assessorAliases = ['assessor','qualified_assessor','qualified-assessor'];
        if (in_array($role, $adminAliases, true)) return 'admin';
        if (in_array($role, $assessorAliases, true)) return 'assessor';
        return $role !== '' ? $role : 'user';
    } catch (Throwable) {
        return null;
    }
}

function is_primary_install_user(): bool
{
    $id = auth_user_id();
    if (!$id) return false;
    $ownerId = installation_owner_user_id();
    if ($ownerId !== null) return $ownerId === $id;
    try {
        $first = db()->query("SELECT MIN(ua.user_id) FROM user_auth ua JOIN users u ON u.id=ua.user_id WHERE u.status='active'")->fetchColumn();
        return $first !== false && (int)$first === $id;
    } catch (Throwable) {
        return false;
    }
}

function admin_owner_claim_available(): bool
{
    return auth_user_id() !== null && installation_owner_user_id() === null;
}

function can_show_admin_entry(): bool
{
    return is_admin() || is_primary_install_user() || admin_owner_claim_available();
}

function is_admin(): bool
{
    return auth_user_role() === 'admin';
}

function require_admin(): int
{
    $id = require_auth();
    if (!is_admin()) {
        http_response_code(403);
        exit('Admin access required.');
    }
    return $id;
}

function is_assessor(): bool
{
    return in_array(auth_user_role(), ['admin','assessor'], true);
}

function require_assessor(): int
{
    $id = require_auth();
    if (!is_assessor()) {
        http_response_code(403);
        exit('Qualified assessor access required.');
    }
    return $id;
}

function current_user(): ?array
{
    $id = auth_user_id();
    if (!$id) return null;
    $stmt = db()->prepare("SELECT id,email,username,display_name,avatar_url,timezone,country_code FROM users WHERE id=? AND status='active'");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $out = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $out;
}

function diagnosis_disclaimer(): string
{
    return 'Vacation Brain self-diagnoses and professional assessments are entertainment and travel-preference guidance only. They are not medical or mental-health diagnoses. Vacation Brain assessors are not acting as healthcare professionals.';
}

function user_initials(?string $name): string
{
    $name=trim((string)$name); if($name==='') return 'VB';
    $parts=preg_split('/\s+/', $name) ?: [];
    $out=''; foreach(array_slice($parts,0,2) as $part){$out.=strtoupper(substr($part,0,1));}
    return $out ?: 'VB';
}


function db_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try {
        $stmt = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function db_column_exists(string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;
    try {
        $stmt = db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
        $stmt->execute([$table,$column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function sample_data_enabled(): bool
{
    if (!db_table_exists('site_settings')) return false;
    return site_setting_bool('sample_data.enabled', true);
}

function site_setting(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value === false ? $default : (string)$value;
    } catch (Throwable) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function site_setting_bool(string $key, bool $default = false): bool
{
    $value = site_setting($key, $default ? '1' : '0');
    return in_array(strtolower((string)$value), ['1','true','yes','on'], true);
}

function set_site_setting(string $key, ?string $value, string $group = 'general', ?int $adminId = null): void
{
    $stmt = db()->prepare('INSERT INTO site_settings (setting_key,setting_value,setting_group,updated_by,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_group=VALUES(setting_group),updated_by=VALUES(updated_by),updated_at=NOW()');
    $stmt->execute([$key,$value,$group,$adminId]);
}
