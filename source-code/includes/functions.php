<?php
/**
 * Small shared helpers: escaping, JSON responses, request parsing, URLs.
 */
declare(strict_types=1);

/** An error that is safe to show to the user, with an HTTP status code. */
class HttpError extends RuntimeException
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/** Escape untrusted text for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Send a JSON success response and stop. */
function json_response(array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send a JSON error response and stop. */
function json_error(string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => ['message' => $message]], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_method(string ...$methods): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        throw new HttpError(405, 'Method not allowed.');
    }
}

/** Decode a JSON request body into an array, or fail with 400. */
function read_json_body(int $maxBytes = 2_000_000): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') {
        throw new HttpError(400, 'Request body is empty.');
    }
    if (strlen($raw) > $maxBytes) {
        throw new HttpError(413, 'Request body is too large.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new HttpError(400, 'Malformed JSON request body.');
    }
    return $data;
}

/** Parse a positive integer ID from untrusted input, or return null. */
function parse_id($value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value)) {
        return (int) $value;
    }
    return null;
}

function require_id($value, string $label = 'document'): int
{
    $id = parse_id($value);
    if ($id === null) {
        throw new HttpError(400, "A valid $label id is required.");
    }
    return $id;
}

/** Count characters in a UTF-8 string without depending on mbstring. */
function utf8_length(string $value): int
{
    return (int) preg_match_all('/./su', $value);
}

/** URL path of the app root, so the app also works inside a sub-folder. */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', APP_ROOT);
    $relative = str_starts_with($script, $root) ? substr($script, strlen($root)) : '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = $relative !== '' && str_ends_with($scriptName, $relative)
        ? substr($scriptName, 0, -strlen($relative))
        : '';
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function is_api_request(): bool
{
    return str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/** MySQL UTC timestamp -> ISO 8601 string for the browser. */
function iso_time(?string $timestamp): ?string
{
    return $timestamp ? str_replace(' ', 'T', $timestamp) . 'Z' : null;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $version = is_file($file) ? filemtime($file) : 0;
    return url('assets/' . $path) . '?v=' . $version;
}

function handle_uncaught_exception(Throwable $e): void
{
    if ($e instanceof HttpError) {
        $status = $e->status;
        $message = $e->getMessage();
    } else {
        error_log('[ajaia] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $status = 500;
        $message = APP_DEBUG ? $e->getMessage() : 'Something went wrong on our side. Please try again.';
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error ($status): $message\n");
        exit(1);
    }
    if (is_api_request()) {
        json_error($message, $status);
    }
    render_error_page($status, $message);
}

function render_error_page(int $status, string $message): void
{
    http_response_code($status);
    $pageTitle = $status === 404 ? 'Not found' : 'Error';
    $bodyClass = 'page-centered';
    require APP_ROOT . '/includes/header.php';
    ?>
    <main class="error-card card">
        <div class="error-code"><?= $status ?></div>
        <h1><?= $status === 404 ? 'We couldn’t find that' : 'Something went wrong' ?></h1>
        <p><?= e($message) ?></p>
        <a class="btn btn-primary" href="<?= e(url('dashboard.php')) ?>">Back to documents</a>
    </main>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}
