<?php
/**
 * Slate — customer registration.
 */
require_once dirname(__DIR__) . '/config.php';

if (Auth::customer()) {
    header('Location: ' . SLATE_URL . '/customer/');
    exit;
}

$flash = null;
$email = '';
$name  = '';
$phone = '';
$registered = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed. Please try again.')];
    } else {
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $name     = trim((string)($_POST['name']  ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));

        $result = Auth::registerCustomer($email, $password, $name, $phone);
        if ($result['ok']) {
            $registered = true;
        } else {
            $flash = ['type' => 'error', 'msg' => $result['error']];
        }
    }
}

$pageTitle = __('auth_create_account_title', 'Create your account');
// Matches login.php — both auth screens now use the branded two-column shell
// rather than register sitting alone in the plain centered card.
$customerPageVariant = 'auth-split';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-card">
    <?php if ($registered): ?>
        <h1><?= __('auth_check_inbox', 'Check your inbox') ?></h1>
        <p class="auth-card-sub"><?= sprintf(e(__('auth_verification_sent', 'We\'ve sent a verification link to %s.')), '<strong>' . e($email) . '</strong>') ?></p>
        <div class="alert alert-success" role="status">
            <?= __('auth_click_confirm_link', 'Click the link in that email to confirm your address, then sign in.') ?>
        </div>
        <p class="text-sm text-muted">
            <?= __('auth_didnt_get_it', 'Didn\'t get it? Check your spam folder, or') ?>
            <a href="<?= e(SLATE_URL) ?>/customer/register.php"><?= __('auth_try_again', 'try again') ?></a>.
        </p>
    <?php else: ?>
        <h1><?= __('auth_create_account_title', 'Create your account') ?></h1>
        <p class="auth-card-sub"><?= __('auth_create_account_sub', 'A few details and you\'re in.') ?></p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <style>
        .wiz-steps { display:flex; align-items:center; gap:6px; margin:0 0 22px; max-width:100%; overflow:hidden; }
        .wiz-steps .s { display:flex; align-items:center; gap:8px; flex:1; min-width:0; }
        .wiz-steps .s .n {
            width:26px; height:26px; border-radius:50%; display:grid; place-items:center;
            font-size:12px; font-weight:700; background:var(--surface-2); color:var(--muted);
            border:1px solid var(--border); flex:none; transition:.15s;
        }
        .wiz-steps .s .t { font-size:12px; font-weight:600; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
        .wiz-steps .s .bar { flex:1 1 12px; min-width:12px; height:2px; background:var(--border); border-radius:2px; }
        .wiz-steps .s.is-active .n, .wiz-steps .s.is-done .n { background:var(--accent); color:var(--on-accent); border-color:var(--accent); }
        .wiz-steps .s.is-active .t, .wiz-steps .s.is-done .t { color:var(--text); }
        .wiz-steps .s.is-done .bar { background:var(--accent); }
        @media (max-width:420px){ .wiz-steps .s .t { display:none; } }
        .wiz.js .wiz-panel { display:none; }
        .wiz.js .wiz-panel.is-active { display:block; animation:wizfade .18s ease; }
        @keyframes wizfade { from{opacity:0; transform:translateY(4px)} to{opacity:1; transform:none} }
        .wiz-nav { display:flex; gap:10px; margin-top:6px; }
        .wiz-nav .btn { flex:1; }
        .wiz-review { border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; margin-bottom:16px; }
        .wiz-review .r { display:flex; justify-content:space-between; gap:12px; padding:11px 14px; border-bottom:1px solid var(--border); font-size:13px; }
        .wiz-review .r:last-child { border-bottom:0; }
        .wiz-review .r .k { color:var(--muted); } .wiz-review .r .v { font-weight:600; color:var(--text); text-align:right; word-break:break-word; }
        </style>

        <form method="post" autocomplete="on" class="wiz" id="regwiz" novalidate>
            <?= csrf_field() ?>

            <div class="wiz-steps" aria-hidden="true">
                <div class="s is-active" data-s="1"><span class="n">1</span><span class="t"><?= __('auth_wiz_step_login', 'Login') ?></span><span class="bar"></span></div>
                <div class="s" data-s="2"><span class="n">2</span><span class="t"><?= __('auth_wiz_step_about', 'About you') ?></span><span class="bar"></span></div>
                <div class="s" data-s="3"><span class="n">3</span><span class="t"><?= __('auth_wiz_step_review', 'Review') ?></span></div>
            </div>

            <!-- Step 1 — login -->
            <section class="wiz-panel is-active" data-step="1">
                <div class="field">
                    <label class="field-label" for="email"><?= __('email', 'Email') ?></label>
                    <input type="email" id="email" name="email" required autocomplete="email"
                           value="<?= e($email) ?>" maxlength="190" autofocus>
                </div>
                <div class="field">
                    <label class="field-label" for="password"><?= __('password', 'Password') ?></label>
                    <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8">
                </div>
                <div class="field">
                    <label class="field-label" for="password2"><?= __('auth_confirm_password', 'Confirm password') ?></label>
                    <input type="password" id="password2" required autocomplete="new-password" minlength="8">
                    <div class="field-hint" id="pw-hint"><?= __('auth_min_chars_hint', 'At least 8 characters.') ?></div>
                </div>
                <div class="wiz-nav">
                    <button type="button" class="btn btn-primary btn-lg" data-next><?= __('auth_continue', 'Continue') ?></button>
                </div>
            </section>

            <!-- Step 2 — about you -->
            <section class="wiz-panel" data-step="2">
                <div class="field">
                    <label class="field-label" for="name"><?= __('auth_your_name', 'Your name') ?></label>
                    <input type="text" id="name" name="name" required autocomplete="name" value="<?= e($name) ?>" maxlength="120">
                </div>
                <div class="field">
                    <label class="field-label" for="phone"><?= __('auth_phone', 'Phone') ?> <span class="text-muted text-xs">(<?= __('auth_optional', 'optional') ?>)</span></label>
                    <input type="tel" id="phone" name="phone" autocomplete="tel" value="<?= e($phone) ?>" maxlength="40">
                </div>
                <div class="wiz-nav">
                    <button type="button" class="btn btn-lg" data-back><?= __('auth_back', 'Back') ?></button>
                    <button type="button" class="btn btn-primary btn-lg" data-next><?= __('auth_continue', 'Continue') ?></button>
                </div>
            </section>

            <!-- Step 3 — review -->
            <section class="wiz-panel" data-step="3">
                <div class="wiz-review">
                    <div class="r"><span class="k"><?= __('auth_review_name', 'Name') ?></span><span class="v" data-rev="name">—</span></div>
                    <div class="r"><span class="k"><?= __('email', 'Email') ?></span><span class="v" data-rev="email">—</span></div>
                    <div class="r"><span class="k"><?= __('auth_phone', 'Phone') ?></span><span class="v" data-rev="phone">—</span></div>
                </div>
                <div class="wiz-nav">
                    <button type="button" class="btn btn-lg" data-back><?= __('auth_back', 'Back') ?></button>
                    <button type="submit" class="btn btn-primary btn-lg"><?= __('auth_create_account_btn', 'Create account') ?></button>
                </div>
            </section>
        </form>

        <script>
        (function () {
            var form = document.getElementById('regwiz');
            if (!form) return;
            form.classList.add('js');
            var panels = Array.prototype.slice.call(form.querySelectorAll('.wiz-panel'));
            var dots   = Array.prototype.slice.call(form.querySelectorAll('.wiz-steps .s'));
            var pw = form.querySelector('#password'), pw2 = form.querySelector('#password2'), hint = form.querySelector('#pw-hint');
            var cur = 0;
            var i18nPwMismatch = <?= json_encode(__('auth_passwords_dont_match', "Passwords don't match.")) ?>;
            var i18nPwHint     = <?= json_encode(__('auth_min_chars_hint', 'At least 8 characters.')) ?>;

            function checkPw() {
                if (pw2.value && pw.value !== pw2.value) { pw2.setCustomValidity(i18nPwMismatch); hint.textContent = i18nPwMismatch; hint.style.color = 'var(--danger)'; return false; }
                pw2.setCustomValidity(''); hint.textContent = i18nPwHint; hint.style.color = ''; return true;
            }
            pw && pw2 && [pw, pw2].forEach(function (el) { el.addEventListener('input', checkPw); });

            function show(i) {
                cur = i;
                panels.forEach(function (p, idx) { p.classList.toggle('is-active', idx === i); });
                dots.forEach(function (d, idx) { d.classList.toggle('is-active', idx === i); d.classList.toggle('is-done', idx < i); });
                var first = panels[i].querySelector('input'); if (first) { try { first.focus(); } catch (e) {} }
                if (i === panels.length - 1) {
                    form.querySelector('[data-rev=name]').textContent  = (form.name.value || '—');
                    form.querySelector('[data-rev=email]').textContent = (form.email.value || '—');
                    form.querySelector('[data-rev=phone]').textContent = (form.phone.value || '—');
                }
            }
            function validStep(i) {
                var inputs = panels[i].querySelectorAll('input, select, textarea');
                for (var j = 0; j < inputs.length; j++) {
                    if (i === 0) { checkPw(); }
                    if (!inputs[j].checkValidity()) { inputs[j].reportValidity(); return false; }
                }
                return true;
            }
            form.addEventListener('click', function (e) {
                if (e.target.closest('[data-next]')) { if (validStep(cur)) show(Math.min(cur + 1, panels.length - 1)); }
                else if (e.target.closest('[data-back]')) { show(Math.max(cur - 1, 0)); }
            });
            // Final submit: validate everything.
            form.addEventListener('submit', function (e) {
                for (var i = 0; i < panels.length; i++) { if (!validStep(i)) { e.preventDefault(); show(i); return; } }
            });
            show(0);
        })();
        </script>
    <?php endif; ?>
</div>

<div class="auth-footer">
    <?= __('auth_already_have_account', 'Already have an account?') ?> <a href="<?= e(SLATE_URL) ?>/customer/login.php"><?= __('auth_sign_in_btn', 'Sign in') ?></a>
</div>

<?php require __DIR__ . '/partials/auth_ui.php'; slate_auth_ui(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
