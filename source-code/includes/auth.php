<?php
/**
 * Session-based authentication and CSRF protection.
 * The signed-in user's id only ever comes from the server-side session.
 */
declare(strict_types=1);

const SESSION_IDLE_SECONDS = 8 * 3600;
const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_SECONDS = 60;

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ajaia_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $last = $_SESSION['last_activity'] ?? null;
    if ($last !== null && time() - $last > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_expired'] = true;
    }
    $_SESSION['last_activity'] = time();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return $user = null;
    }
    $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $row['id'] = (int) $row['id'];
    }
    return $user = $row ?: null;
}

/** For pages: send anonymous visitors to the login screen. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
    }
    return $user;
}

/** For API endpoints: 401 instead of a redirect. */
function require_api_user(): array
{
    $user = current_user();
    if ($user === null) {
        throw new HttpError(401, 'Your session has expired. Please sign in again.');
    }
    return $user;
}

/** Returns the user on success, null on bad credentials. */
function attempt_login(string $email, string $password): ?array
{
    $lockedUntil = $_SESSION['login_locked_until'] ?? 0;
    if ($lockedUntil > time()) {
        throw new HttpError(429, 'Too many failed sign-in attempts. Try again in a minute.');
    }

    $stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
        if ($_SESSION['login_failures'] >= LOGIN_MAX_FAILURES) {
            $_SESSION['login_locked_until'] = time() + LOGIN_LOCK_SECONDS;
            $_SESSION['login_failures'] = 0;
        }
        return null;
    }

    session_regenerate_id(true);
    $_SESSION = [
        'user_id'       => (int) $row['id'],
        'last_activity' => time(),
        'csrf_token'    => bin2hex(random_bytes(32)),
    ];
    return ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']];
}

function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Validate the token from the X-CSRF-Token header or a _csrf form field. */
function verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
        throw new HttpError(403, 'Your security token is missing or expired. Refresh the page and try again.');
    }
}

function user_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials ?: '?';
}
