<?php
/**
 * Application configuration.
 *
 * Values come from environment variables (used by Docker), and can be
 * overridden by config/config.local.php (used on shared hosting, where you
 * just upload the files and edit one PHP file — no commands needed).
 */

$config = [
    'db_host'      => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'      => (int) (getenv('DB_PORT') ?: 3306),
    'db_name'      => getenv('DB_NAME') ?: 'ajaia',
    'db_user'      => getenv('DB_USER') ?: 'root',
    'db_pass'      => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    // Show error details in responses. Keep false in production.
    'debug'        => getenv('APP_DEBUG') === '1',
    // Create tables + demo accounts automatically when the database is empty.
    'auto_install' => true,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, require $local);
}

return $config;
