<?php
/**
 * Loaded first by every page and API endpoint.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$config = require APP_ROOT . '/config/config.php';
define('APP_DEBUG', (bool) $config['debug']);

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sanitizer.php';
require_once __DIR__ . '/importer.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/icons.php';

set_exception_handler('handle_uncaught_exception');

if (PHP_SAPI !== 'cli') {
    start_app_session();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
}
