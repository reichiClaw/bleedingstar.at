<?php

declare(strict_types=1);

/**
 * Copy to config/config.php and fill in the values.
 * config/config.php stays outside the document root and is not committed.
 */
return [
    'base_url' => 'https://www.bleedingstar.at',
    'timezone' => 'Europe/Vienna',
    // Optional: at least 32 random characters open /setup?token=... once, until storage/install.done exists.
    // Only needed on hosting without shell access; leave empty otherwise.
    'setup_token' => '',
    // Optional: at least 32 random characters let a web cron call /jobs/run?token=...&source=discogs|deezer|apple|spotify|cache.
    // Leave empty when the catalogue jobs run from a shell cron instead.
    'cron_token' => '',
    // Optional: seconds a catalogue run may take before it stops cleanly (0 = unlimited).
    // Default: max_execution_time minus 30 s, which fits the 180 s limit of World4You web and cron requests.
    // 'job_time_budget' => 150,
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=bleedingstar;charset=utf8mb4',
        'user' => 'bleedingstar',
        'pass' => '',
    ],
    'mail_to' => 'reichi@bleedingstar.at',
    'mail_from' => 'reichi@bleedingstar.at',
    'identity' => [
        'name' => 'Christian Reichinger',
        'brand' => 'BleedingStar Music Services',
        'street' => 'Maria Aich 3',
        'postal' => '4971 Aurolzmünster',
        'uid' => 'ATU 67362668',
        'phone' => '+436644385462',
        'phone_href' => '+436644385462',
        'email' => 'reichi@bleedingstar.at',
        'personal_site' => 'https://www.reichi.com',
    ],
    'discogs' => [
        'label_id' => 316841,
        'token' => '',
        'user_agent' => 'BleedingStarCatalog/1.0 +https://www.bleedingstar.at',
        'allow_label_ids' => [316841],
        'allow_label_names' => ['BleedingStar Records'],
        'max_age_hours' => 4,
        'timeout' => 20,
    ],
    // Deezer and Apple need no credentials. Both default to the Discogs label names and user agent.
    'deezer' => [
        'enabled' => true,
        'label_names' => ['BleedingStar Records'],
        'timeout' => 20,
    ],
    'apple' => [
        'enabled' => true,
        'country' => 'at',
        'timeout' => 20,
    ],
    // Spotify has no public search. Create an app at https://developer.spotify.com/dashboard
    // (Web API, Client Credentials; the redirect URI is unused) and paste its id and secret.
    // The source stays off until both values are set and enabled is true. The app owner
    // needs Spotify Premium while the app remains in development mode.
    'spotify' => [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
        'market' => 'AT',
    ],
    'legacy_export' => dirname(__DIR__) . '/data/content.json',
];
