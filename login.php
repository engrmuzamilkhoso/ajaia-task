<?php
require __DIR__ . '/includes/bootstrap.php';

/** Only allow same-app relative redirects after login. */
function safe_next(string $next): string
{
    $base = base_path() . '/';
    if ($next !== '' && str_starts_with($next, $base) && !str_starts_with($next, '//') && !str_contains($next, '\\')) {
        return substr($next, strlen($base));
    }
    return 'dashboard.php';
}

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
if (current_user()) {
    redirect(safe_next($next));
}

$error = null;
$email = (string) ($_GET['email'] ?? '');
$expired = !empty($_SESSION['session_expired']);
unset($_SESSION['session_expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    try {
        if ($email === '' || $password === '') {
            $error = 'Enter your email and password.';
        } elseif (attempt_login($email, $password)) {
            redirect(safe_next($next));
        } else {
            $error = 'That email and password combination is not correct.';
        }
    } catch (HttpError $e) {
        $error = $e->getMessage();
    }
}

$demoUsers = list_demo_users(db());
$flash = take_flash();
$pageTitle = 'Sign in';
$bodyClass = 'page-login';
require __DIR__ . '/includes/header.php';
?>
<main class="login-layout">
    <section class="login-card card">
        <div class="brand brand-lg">
            <span class="brand-mark" aria-hidden="true"><?= icon('doc') ?></span>
            <span class="brand-name">Ajaia Docs</span>
        </div>
        <h1>Sign in</h1>
        <p class="muted">Write, format and share documents with your team.</p>

        <?php if ($expired): ?>
            <div class="alert alert-info"><?= icon('alert') ?> Your session expired. Please sign in again.</div>
        <?php endif; ?>
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?= icon('alert') ?> <?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="form" id="login-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" id="login-email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" id="login-password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>
    </section>

    <aside class="demo-card card" aria-labelledby="demo-heading">
        <h2 id="demo-heading">Demo accounts</h2>
        <p class="muted">For reviewers. Every account uses the password <code>demo1234</code>. Choose one to sign in.</p>
        <ul class="demo-list">
            <?php foreach ($demoUsers as $demo): ?>
                <li>
                    <button type="button" class="demo-user" data-email="<?= e($demo['email']) ?>">
                        <span class="avatar" aria-hidden="true"><?= e(user_initials($demo['name'])) ?></span>
                        <span class="demo-user-text">
                            <strong><?= e($demo['name']) ?></strong>
                            <span><?= e($demo['email']) ?></span>
                        </span>
                        <span class="demo-user-action">Sign in</span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="fine-print">Demo sign-in is for evaluation only; it is not production identity verification.</p>
    </aside>
</main>
<?php
$scripts = ['login.js'];
require __DIR__ . '/includes/footer.php';
