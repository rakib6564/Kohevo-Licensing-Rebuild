<?php
/**
 * Slate — customer "forgot password" form.
 * Always claims success regardless of whether the email exists,
 * to avoid leaking which addresses are registered.
 */
require_once dirname(__DIR__) . '/config.php';

$flash = null;
$email = '';
$sent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed. Please try again.')];
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        Auth::sendCustomerPasswordReset($email);
        $sent = true;  // always show the same confirmation page
    }
}

$pageTitle = __('auth_reset_password_title', 'Reset your password');
// Matches login.php/register.php — the branded two-column shell is the only
// variant that renders the tenant's uploaded logo instead of the initial-letter mark.
$customerPageVariant = 'auth-split';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-card">
    <?php if ($sent): ?>
        <h1><?= __('auth_check_inbox', 'Check your inbox') ?></h1>
        <p class="auth-card-sub">
            <?= sprintf(e(__('auth_reset_if_registered', 'If %s is registered, we\'ve sent a reset link.')), '<strong>' . e($email) . '</strong>') ?>
        </p>
        <div class="alert alert-info" role="status">
            <?= __('auth_reset_link_validity', 'The link is valid for 2 hours. If it doesn\'t arrive, check your spam folder.') ?>
        </div>
        <a href="<?= e(SLATE_URL) ?>/customer/login.php" class="btn btn-primary btn-block"><?= __('auth_back_to_signin', 'Back to sign in') ?></a>
    <?php else: ?>
        <h1><?= __('auth_reset_password_title', 'Reset your password') ?></h1>
        <p class="auth-card-sub"><?= __('auth_reset_password_sub', 'Enter your account email — we\'ll send you a link.') ?></p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <div class="field">
                <label class="field-label" for="email"><?= __('email', 'Email') ?></label>
                <input type="email" id="email" name="email" required autocomplete="email"
                       value="<?= e($email) ?>" autofocus maxlength="190">
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg"><?= __('auth_send_reset_link', 'Send reset link') ?></button>
        </form>
    <?php endif; ?>
</div>

<div class="auth-footer">
    <?= __('auth_remembered_it', 'Remembered it?') ?> <a href="<?= e(SLATE_URL) ?>/customer/login.php"><?= __('auth_sign_in_btn', 'Sign in') ?></a>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
