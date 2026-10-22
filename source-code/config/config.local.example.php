<?php
/**
 * Production configuration template.
 *
 * On your hosting: copy this file to config/config.local.php (same folder),
 * fill in the MySQL details from your hosting control panel, and upload.
 * The app creates its tables and demo accounts on the first page load.
 */

return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'your_database_name',
    'db_user' => 'your_database_user',
    'db_pass' => 'your_database_password',
    'debug'   => false,
];
