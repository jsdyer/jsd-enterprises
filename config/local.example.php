<?php

declare(strict_types=1);

return [
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=jsd_enterprises;charset=utf8mb4',
        'username' => 'jsd_app',
        'password' => 'replace-with-a-unique-password',
    ],
    'registration_enabled' => true,
    'community_enabled' => false,
    'admin_enabled' => false, // Install the admin schema and grant an account access before enabling.
    'secure_cookies' => false, // Set true for HTTPS production deployments.
    'market_data_dir' => '', // Absolute path to authorized JSON data outside public/.
    'market_stale_days' => 5, // Calendar days before the admin console flags a close as stale.
    'publish_licensed_api' => false,
];
