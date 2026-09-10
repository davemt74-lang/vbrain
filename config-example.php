<?php
declare(strict_types=1);

/*
 * Vacation Brain configuration template.
 *
 * Copy this file to config.php in the web root and update the values below.
 * Do not commit your real config.php or any real credentials.
 *
 * IMPORTANT: Keep app.internal_key stable after you start saving encrypted API
 * keys in Admin. Changing it later will make previously encrypted keys unreadable.
 */

return [
    'app' => [
        // No trailing slash. For the live site use: https://vacationbrain.com
        'base_url' => 'https://vacationbrain.com',

        // Use a valid PHP timezone identifier.
        'timezone' => 'America/Phoenix',

        // Browser session cookie name for this installation.
        'session_name' => 'vacation_brain_session',

        // Replace with a long, random secret and then keep it unchanged.
        // Example generator: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'internal_key' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',
    ],

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'vacation_brain',
        'user' => 'vacation_brain',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
];
