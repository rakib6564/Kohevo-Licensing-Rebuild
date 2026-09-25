<?php
/**
 * Slate — customer password reset (the form the reset link opens).
 * Validates the token, then accepts a new password.
 */
require_once dirname(__DIR__) . '/config.php';

$token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
$flash = null;
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed. Please try again.')];
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');
        if ($password !== $confirm) {
            $flash = ['type' => 'error', 'msg' => __('auth_passwords_dont_match_period', 'The two passwords don\'t match.')];
        } else {
            $result = Auth::resetCustomerPassword($token, $password);
            if ($result['ok']) {
                $done = true;
            } else {
                $flash = ['type' => 'error', 'msg' => $result['error']];
            }
        }
    }
}

$pageTitle = __('auth_set_new_password', 'Set a new password');
// Matches login.php/register.php — the branded two-column shell is the only
// variant that renders the tenant's uploaded logo instead of the initial-letter mark.
$customerPageVariant = 'auth-split';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-card">
    <?php if ($done): ?>
        <h1><?= __('auth_password_updated', 'Password updated') ?></h1>
        <p class="auth-card-sub"><?= __('auth_password_updated_sub', 'You can now sign in with your new password.') ?></p>
        <a href="<?= e(SLATE_URL) ?>/customer/login.php" class="btn btn-primary btn-block btn-lg">
            <?= __('auth_continue_to_signin', 'Continue to sign in') ?>
        </a>
    <?php elseif ($token === ''): ?>
        <h1><?= __('auth_reset_link_missing', 'Reset link missing') ?></h1>
        <p class="auth-card-sub"><?= __('auth_no_token_in_url', 'There\'s no token in this URL.') ?></p>
        <div class="alert alert-warning" role="status">
            <?= __('auth_use_link_or_request_new', 'Use the link from your reset email, or') ?>
            <a href="<?= e(SLATE_URL) ?>/customer/forgot-password.php"><?= __('auth_request_new_one', 'request a new one') ?></a>.
        </div>
    <?php else: ?>
        <h1><?= __('auth_set_new_password', 'Set a new password') ?></h1>
        <p class="auth-card-sub"><?= __('auth_choose_password_length', 'Choose something at least 8 characters long.') ?></p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="field">
                <label class="field-label" for="password"><?= __('auth_new_password', 'New password') ?></label>
                <input type="password" id="password" name="password" required minlength="8"
                       autocomplete="new-password" autofocus>
            </div>

            <div class="field">
                <label class="field-label" for="password_confirm"><?= __('auth_confirm_new_password', 'Confirm new password') ?></label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8"
                       autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg"><?= __('auth_update_password', 'Update password') ?></button>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
