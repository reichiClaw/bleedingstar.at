<?php

declare(strict_types=1);

/**
 * Copy to config/config.php and fill in the values.
 * config/config.php stays outside the document root and is not committed.
 */
return [
    'base_url' => 'https://www.bleedingstar.at',
    'timezone' => 'Europe/Vienna',
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
    'spotify' => [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
    ],
    'legacy_export' => dirname(__DIR__) . '/data/content.json',
];
