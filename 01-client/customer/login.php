<?php
/**
 * Slate — customer login.
 */
require_once dirname(__DIR__) . '/config.php';

// If already logged in, send them to the dashboard.
if (Auth::customer()) {
    header('Location: ' . SLATE_URL . '/customer/');
    exit;
}

$flash = null;
$next  = $_GET['next'] ?? ($_POST['next'] ?? '');
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed. Please try again.')];
    } else {
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (Auth::attemptCustomerLogin($email, $password)) {
            // Validate `next` — only allow same-origin destinations.
            $target = slate_safe_redirect_target($next, SLATE_URL . '/customer/');
            header('Location: ' . $target);
            exit;
        }
        $flash = Auth::lastLoginWasThrottled()
            ? ['type' => 'error', 'msg' => __('auth_too_many_attempts', 'Too many login attempts. Please wait a few minutes and try again.')]
            : ['type' => 'error', 'msg' => __('auth_invalid_credentials', 'Email or password is incorrect.')];
    }
}

$pageTitle = __('auth_page_title_signin', 'Sign in');
$customerPageVariant = 'auth-split';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-card">
    <div class="auth-eyebrow"><?= __('customer_secure_access', 'Member access') ?></div>
    <h1><?= __('welcome_back', 'Welcome back') ?></h1>
    <p class="auth-card-sub"><?= __('customer_sign_in_sub', 'Sign in to manage your account.') ?></p>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <div class="field">
            <label class="field-label" for="email"><?= __('email', 'Email') ?></label>
            <input type="email" id="email" name="email" required autocomplete="email"
                   value="<?= e($email) ?>" autofocus>
        </div>

        <div class="field">
            <label class="field-label" for="password"><?= __('password', 'Password') ?></label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            <div class="field-hint" style="text-align:right;">
                <a href="<?= e(SLATE_URL) ?>/customer/forgot-password.php"><?= __('auth_forgot_password', 'Forgot password?') ?></a>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg"><?= __('auth_sign_in_btn', 'Sign in') ?></button>
    </form>
</div>

<div class="auth-footer">
    <?= __('auth_new_here', 'New here?') ?> <a href="<?= e(SLATE_URL) ?>/customer/register.php"><?= __('auth_create_account', 'Create an account') ?></a>
</div>

<?php require __DIR__ . '/partials/auth_ui.php'; slate_auth_ui(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
