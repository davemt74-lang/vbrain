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
        'timezone' => 'America/Phoenix',
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

    /*
     * Optional live travel-intelligence providers.
     * Admin > Travel Providers can securely store encrypted credentials after
     * migration 046. These config/environment values remain a server-side
     * fallback for existing installations and are never rendered to browsers.
     *
     * Visual Crossing: global forecast + historical weather.
     * Ticketmaster: local live events via Discovery API.
     * Google Places: restaurants, bars, attractions and local businesses.
     * Skyscanner: approved-partner indicative airfare intelligence.
     * Aviationstack: operational status for flights users record as booked.
     * Booking.com Demand API: live lodging search/look/redirect inventory.
     *
     * U.S. weather automatically falls back to api.weather.gov when destination
     * coordinates are available, even without a Visual Crossing key.
     */
    'travel' => [
        'visual_crossing_key' => '', // or VISUAL_CROSSING_API_KEY
        'ticketmaster_key' => '',    // or TICKETMASTER_API_KEY
        'google_places_key' => '',   // or GOOGLE_PLACES_API_KEY
        'skyscanner_key' => '',      // or SKYSCANNER_API_KEY
        'aviationstack_key' => '',   // or AVIATIONSTACK_API_KEY
        'booking_com_token' => '',   // or BOOKING_COM_API_TOKEN
        'booking_com_affiliate_id' => '',
        'booking_com_booker_country' => 'us',
        'booking_com_platform' => 'desktop',
        'booking_com_environment' => 'production', // production | sandbox
        'market' => 'US',
        'locale' => 'en-US',
        'currency' => 'USD',
    ],
];