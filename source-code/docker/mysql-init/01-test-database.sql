-- Separate database used by tests/run-tests.php so tests never touch dev data.
CREATE DATABASE IF NOT EXISTS ajaia_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON ajaia_test.* TO 'ajaia'@'%';
FLUSH PRIVILEGES;
