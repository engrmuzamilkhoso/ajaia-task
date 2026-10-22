<?php
/**
 * PDO connection and zero-command install: if the tables don't exist yet,
 * database/schema.sql and database/seed.sql are applied automatically.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $config;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_port'],
        $config['db_name']
    );

    try {
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        error_log('[ajaia] Database connection failed: ' . $e->getMessage());
        $pdo = null;
        throw new HttpError(503, 'The database is unavailable. Check the credentials in config/config.local.php.');
    }

    if (!empty($config['auto_install'])) {
        ensure_schema($pdo);
    }
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    // document_shares is created last, so its presence means install finished.
    if ($pdo->query("SHOW TABLES LIKE 'document_shares'")->fetchColumn() !== false) {
        return;
    }
    run_sql_file($pdo, APP_ROOT . '/database/schema.sql');
    run_sql_file($pdo, APP_ROOT . '/database/seed.sql');
}

function run_sql_file(PDO $pdo, string $path): void
{
    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
}
