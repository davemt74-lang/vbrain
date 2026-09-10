<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = (string)file_get_contents($root.'/app/helpers.php');
$adminAccess = (string)file_get_contents($root.'/admin-access.php');
$header = (string)file_get_contents($root.'/partials/header.php');
$adminIndex = (string)file_get_contents($root.'/admin/index.php');

function fail_contract(string $message): never
{
    fwrite(STDERR, "Permissions contract failed: {$message}\n");
    exit(1);
}

function function_body(string $source, string $name): string
{
    $needle = 'function '.$name.'(';
    $start = strpos($source, $needle);
    if ($start === false) fail_contract("missing {$name}()");
    $brace = strpos($source, '{', $start);
    if ($brace === false) fail_contract("missing opening brace for {$name}()");

    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($source, $brace + 1, $i - $brace - 1);
        }
    }
    fail_contract("unterminated {$name}()");
}

$roleBody = function_body($helpers, 'auth_user_role');
if (str_contains($roleBody, 'installation_owner_user_id')) {
    fail_contract('auth_user_role() must not promote the installation owner outside the stored role');
}
if (stripos($roleBody, 'UPDATE user_auth SET role') !== false) {
    fail_contract('auth_user_role() must be read-only and must not rewrite account roles');
}
if (!str_contains($roleBody, 'SELECT role FROM user_auth')) {
    fail_contract('auth_user_role() must read the stored user_auth.role');
}

$entryBody = function_body($helpers, 'can_show_admin_entry');
if (!preg_match('/^\s*return\s+is_admin\(\)\s*;\s*$/s', $entryBody)) {
    fail_contract('Admin Dashboard menu visibility must depend only on is_admin()');
}

$claimBody = function_body($helpers, 'admin_owner_claim_available');
if (!str_contains($claimBody, 'installation_owner_user_id() === null') || !str_contains($claimBody, 'is_primary_install_user()')) {
    fail_contract('one-time admin setup must require both an unclaimed install and the first account');
}

if (!str_contains($adminAccess, '$ownerId!==null || !is_primary_install_user()')) {
    fail_contract('admin-access.php must reject existing-owner and non-first-account recovery attempts');
}
if (!str_contains($adminAccess, "UPDATE user_auth SET role=? WHERE user_id=?")) {
    fail_contract('admin-access.php must explicitly assign the Admin role on successful first-account setup');
}
if (!str_contains($header, 'if(can_show_admin_entry())')) {
    fail_contract('header must use the centralized Admin Dashboard visibility guard');
}
if (!str_contains($adminIndex, 'require_admin()')) {
    fail_contract('Admin Dashboard must enforce require_admin() server-side');
}

echo "Permissions contract passed.\n";
