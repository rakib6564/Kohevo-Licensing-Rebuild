<?php
/**
 * Slate — customer email verification.
 * Consumes a single-use token from the verification email and
 * flips email_verified on the customer record.
 */
require_once dirname(__DIR__) . '/config.php';

$token  = (string)($_GET['token'] ?? '');
$result = null;

if ($token !== '') {
    $customerId = Auth::verifyCustomerEmail($token);
    $result = ($customerId !== null) ? 'ok' : 'fail';
}

$pageTitle = __('auth_verify_email_title', 'Verify your email');
// Matches login.php/register.php — the branded two-column shell is the only
// variant that renders the tenant's uploaded logo instead of the initial-letter mark.
$customerPageVariant = 'auth-split';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-card">
    <?php if ($result === 'ok'): ?>
        <h1><?= __('auth_verified_title', "You're verified") ?></h1>
        <p class="auth-card-sub"><?= __('auth_email_confirmed', 'Thanks — your email address is confirmed.') ?></p>
        <div class="alert alert-success" role="status">
            <?= __('auth_can_now_sign_in', 'You can now sign in to your account.') ?>
        </div>
        <a href="<?= e(SLATE_URL) ?>/customer/login.php" class="btn btn-primary btn-block btn-lg">
            <?= __('auth_continue_to_signin', 'Continue to sign in') ?>
        </a>
    <?php elseif ($result === 'fail'): ?>
        <h1><?= __('auth_link_expired', 'Link expired') ?></h1>
        <p class="auth-card-sub"><?= __('auth_verify_link_invalid', 'This verification link is invalid or has already been used.') ?></p>
        <div class="alert alert-warning" role="status">
            <?= __('auth_verify_failed_hint', 'Sign in to request a fresh verification email, or create a new account.') ?>
        </div>
        <a href="<?= e(SLATE_URL) ?>/customer/login.php" class="btn btn-primary btn-block"><?= __('auth_sign_in_btn', 'Sign in') ?></a>
    <?php else: ?>
        <h1><?= __('auth_verify_email_title', 'Verify your email') ?></h1>
        <p class="auth-card-sub"><?= __('auth_verify_no_token', 'No token in the URL.') ?></p>
        <div class="alert alert-info" role="status">
            <?= __('auth_verify_use_link', 'Use the link we sent to your email address. If you didn\'t get one,') ?>
            <a href="<?= e(SLATE_URL) ?>/customer/register.php"><?= __('auth_register_first', 'register first') ?></a>.
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
