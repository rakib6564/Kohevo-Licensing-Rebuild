<?php
/**
 * Stripe Payment Gateway for Slate Shop — bootstrap.
 *
 * Registers TWO payment providers with Shop:
 *
 *   - "stripe"           — Stripe Checkout (hosted, redirect). The
 *                          customer is sent to checkout.stripe.com to
 *                          pay, then bounced back. Gives Apple Pay /
 *                          Google Pay / Link automatically. PCI SAQ A.
 *                          Recommended unless you need the embedded UX.
 *
 *   - "stripe_embedded"  — Stripe Payment Element. Card / wallet UI
 *                          rendered inline on the Slate checkout page;
 *                          customer never leaves. Slightly more PCI
 *                          paperwork (SAQ A-EP) but a unified UX.
 *
 * Both providers share the same:
 *   - admin settings page (API keys, mode, webhook secret)
 *   - mapping table (stripepayment_sessions) for idempotent order
 *     creation across the browser-redirect / JS-confirm / webhook paths
 *   - StripeAPI class for low-level HTTP
 *
 * The plugin is "available" as soon as keys are configured; admins can
 * decide which provider to enable for customers via the
 * `stripe_show_hosted` / `stripe_show_embedded` settings (both default
 * on, but admins can hide either to force one flow).
 */

class StripePayment extends Plugin {

    public function boot(): void {
        // Generic facade first (StripeAPI delegates to it). Order matters
        // because StripeAPI's require_once depends on this file.
        $genericApi = $this->dir('StripePaymentAPI.php');
        if (file_exists($genericApi)) require_once $genericApi;

        $apiFile = $this->dir('StripeAPI.php');
        if (file_exists($apiFile)) require_once $apiFile;

        // Card-present (Terminal) helpers — additive, depends on the
        // generic API above. Reusable by any plugin (e.g. restaurant POS).
        $terminalApi = $this->dir('StripeTerminalAPI.php');
        if (file_exists($terminalApi)) require_once $terminalApi;

        $applied = $this->setting('applied_version', '0.0.0');
        if (version_compare($applied, $this->version, '<')) {
            $this->runMigrations();
            if ($this->schemaIsCurrent()) {
                $this->setSetting('applied_version', $this->version);
            }
        }

        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);
        Hook::addFilter('shop_payment_providers', [$this, 'registerProviders']);
        Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);

        // MCP AI Gateway integration (read-only — no refunds, no key changes)
        require_once __DIR__ . '/StripePaymentMcpHandler.php';
        StripePaymentMcpHandler::register();

        // The generic webhook hook lets other plugins react to Stripe
        // events without owning an endpoint. Shop's own listener is in
        // public/webhook.php (kept for back-compat — its cart-completion
        // logic is too tightly coupled to live here).
    }

    // ── Admin nav ─────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        // Either permission earns a look at this block — the per-item
        // 'perm' below then shows only the one item each caller actually
        // holds. A settings-only or charges-only role must still see its
        // one nav entry; only holding neither hides both.
        if (!Auth::can('stripe.manage_settings') && !Auth::can('stripe.manage_charges') && !Auth::isSuperAdmin()) return $items;

        // Stripe settings live under SHOP if Shop is active (where most
        // users find them), otherwise under SYSTEM. Charges are always
        // their own item — they span multiple plugins.
        $shopActive = PluginLoader::isActive('shop');

        $items[] = [
            'slug'   => 'shop-stripe',
            'label'  => __('stripe_settings', 'Payment Gateway'),
            'href'   => $this->url('admin/settings.php'),
            'icon'   => 'credit-card',
            'perm'   => 'stripe.manage_settings',
            'order'  => $shopActive ? 519 : 720,
            'group'  => $shopActive ? 'shop' : 'system',
        ];
        $items[] = [
            'slug'   => 'shop-stripe-charges',
            'label'  => __('stripe_charges', 'Transaction history'),
            'href'   => $this->url('admin/charges.php'),
            'icon'   => 'bar-chart-3',
            'perm'   => 'stripe.manage_charges',
            'order'  => $shopActive ? 521 : 721,
            'group'  => $shopActive ? 'shop' : 'system',
        ];
        return $items;
    }

    // ── Dashboard widget ──────────────────────────────────────

    /** Recent charge activity. Only registered while the plugin is active. */
    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('stripe.manage_charges') && !Auth::isSuperAdmin()) return $widgets;
        $tid = current_tenant_id();
        try {
            $todayCount = (int) Database::value(
                "SELECT COUNT(*) FROM stripepayment_charges
                  WHERE tenant_id = ? AND status IN ('succeeded','partial_refund')
                    AND DATE(created_at) = CURDATE()", [$tid]);
            $todayVol = (int) Database::value(
                "SELECT COALESCE(SUM(amount_cents - refunded_cents),0) FROM stripepayment_charges
                  WHERE tenant_id = ? AND status <> 'failed'
                    AND DATE(created_at) = CURDATE()", [$tid]);
            $totalCount = (int) Database::value(
                "SELECT COUNT(*) FROM stripepayment_charges
                  WHERE tenant_id = ? AND status IN ('succeeded','partial_refund','refunded')", [$tid]);
            $cur = (string) (Database::value(
                "SELECT currency FROM stripepayment_charges WHERE tenant_id = ? ORDER BY id DESC LIMIT 1", [$tid]) ?: 'USD');
            $latest = Database::rows(
                "SELECT amount_cents, refunded_cents, currency, customer_email, status, created_at
                   FROM stripepayment_charges WHERE tenant_id = ? ORDER BY id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $volStr = number_format($todayVol / 100, 2) . ' ' . strtoupper($cur);
        $chargesUrl = $this->url('admin/charges.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('stripe_charges', 'Stripe') ?></h2>
                <a href="<?= e($chargesUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('stripe_volume_today', 'Volume today') ?></div>
                    <div class="dwidget-kpi-v"><?= e($volStr) ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('stripe_total_charges', 'Total charges') ?></div>
                    <div class="dwidget-kpi-v"><?= $totalCount ?></div>
                </div>
            </div>
            <?php if ($latest): ?>
                <div class="dlist">
                    <?php foreach ($latest as $c):
                        $netCents = (int)$c['amount_cents'] - (int)$c['refunded_cents'];
                        $email = (string)($c['customer_email'] ?? '');
                        $colors = ['succeeded' => 'success', 'refunded' => 'muted', 'partial_refund' => 'warning', 'failed' => 'danger'];
                        slate_dlist_row([
                            'avatar_html'  => slate_avatar_overlay_html($email !== '' ? mb_substr($email, 0, 2) : '?', $email),
                            'avatar_color' => $colors[$c['status']] ?? 'muted',
                            'title'        => $email !== '' ? $email : __('stripe_guest', 'Guest'),
                            'sub'          => ucfirst(str_replace('_', ' ', (string)$c['status'])),
                            'amount'       => slate_format_price_plain($netCents, $c['currency']),
                            'time'         => $c['created_at'] ? I18n::localDate('M j', strtotime($c['created_at'])) : '',
                            'href'         => $chargesUrl,
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('stripe_no_charges', 'No charges yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Provider registration ─────────────────────────────────

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    public function registerProviders(array $providers): array {
        $secretKey      = (string)$this->setting('secret_key', '');
        $publishableKey = (string)$this->setting('publishable_key', '');

        $configured = $secretKey !== '' && $publishableKey !== ''
                      && PluginLoader::isActive('shop');

        $showHosted   = $this->setting('show_hosted', '1') !== '0';
        $showEmbedded = $this->setting('show_embedded', '1') !== '0';

        if ($showHosted) {
            $providers[] = [
                'id'          => 'stripe',
                'label'       => __('payment_stripe', 'Credit or debit card'),
                'description' => __('payment_stripe_desc',
                    'Pay securely via Stripe — you will be redirected to complete payment.'),
                'priority'    => 20,
                'available'   => $configured,
                'render_form' => null,
                'handle_checkout' => [$this, 'handleHostedCheckout'],
            ];
        }

        if ($showEmbedded) {
            $providers[] = [
                'id'          => 'stripe_embedded',
                'label'       => __('payment_stripe_embedded', 'Pay with card / wallet'),
                'description' => __('payment_stripe_embedded_desc',
                    'Card, Apple Pay, Google Pay, Link. Stay on this page to complete payment.'),
                'priority'    => 10,  // shown FIRST when enabled
                'available'   => $configured,
                'render_form' => [$this, 'renderEmbeddedForm'],
                'handle_checkout' => [$this, 'handleEmbeddedCheckout'],
            ];
        }

        return $providers;
    }

    // ──────────────────────────────────────────────────────────
    // Hosted Checkout provider — redirect to stripe.com
    // ──────────────────────────────────────────────────────────

    public function handleHostedCheckout(string $sid, array $billing): array {
        if (!class_exists('StripeAPI')) {
            return ['ok' => false, 'error' => __('sp_err_stripe_plugin_not_loaded', 'Stripe plugin not loaded.')];
        }

        $cart = ShopAPI::cartTotals($sid);
        if (empty($cart['items'])) {
            return ['ok' => false, 'error' => __('sp_err_your_cart_is_empty', 'Your cart is empty.')];
        }

        $successUrl = SLATE_URL . '/plugins/stripe-payment/public/success.php'
                    . '?session_id={CHECKOUT_SESSION_ID}&sid=' . urlencode($sid);
        $cancelUrl  = SLATE_URL . '/shop/checkout?error=cancelled';

        try {
            $session = StripeAPI::createCheckoutSession($sid, $successUrl, $cancelUrl, [
                'customer_email' => $billing['email'] ?? null,
                'metadata' => [
                    'shop_sid'   => $sid,
                    'first_name' => $billing['first_name'] ?? '',
                    'last_name'  => $billing['last_name']  ?? '',
                    'notes'      => $billing['notes']      ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            slate_log('Stripe createCheckoutSession failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => __('sp_err_could_not_initialise_payment_please_try_again', 'Could not initialise payment. Please try again.')];
        }

        if (empty($session['id']) || empty($session['url'])) {
            return ['ok' => false, 'error' => __('sp_err_stripe_returned_no_session_url', 'Stripe returned no session URL.')];
        }

        try {
            Database::insert('stripepayment_sessions', [
                'stripe_session_id' => $session['id'],
                'payment_intent_id' => null,
                'shop_sid'          => $sid,
                'flow'              => 'hosted',
                'status'            => 'pending',
                'billing_json'      => json_encode($billing),
                'created_at'        => slate_db_now(),
            ]);
        } catch (\Throwable $e) {
            slate_log('Stripe session mapping insert failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => __('sp_err_could_not_record_payment_session', 'Could not record payment session.')];
        }

        return ['ok' => true, 'redirect' => $session['url']];
    }

    // ──────────────────────────────────────────────────────────
    // Embedded Payment Element provider — inline card form
    // ──────────────────────────────────────────────────────────

    /**
     * Called by shop/storefront/checkout.php's `render_form` slot when
     * the embedded provider is registered. Returns the HTML for the
     * inline Stripe Payment Element mount point, plus the JS bootstrap
     * that initialises Stripe.js, fetches a client_secret, mounts the
     * element, and intercepts form submission to confirm payment.
     */
    public function renderEmbeddedForm(): string {
        $publishableKey = (string)$this->setting('publishable_key', '');
        if ($publishableKey === '') return '';

        $createIntentUrl = SLATE_URL . '/plugins/stripe-payment/public/create-intent.php';

        // We escape into JS via json_encode (single-quote-safe + slash-safe).
        // The HTML below is rendered as-is inside the checkout form, in a
        // slot Shop already provides for provider-supplied extras.
        ob_start(); ?>
<div class="sp-embedded-wrap">
    <div id="sp-payment-loading" class="sp-payment-loading">
        <span class="sp-payment-spinner" aria-hidden="true"></span>
        <span>Loading secure payment form…</span>
    </div>
    <div id="sp-payment-element" class="sp-payment-element" hidden></div>
    <div id="sp-payment-errors" class="sp-payment-errors" role="alert" hidden></div>
    <noscript>
        <p class="sp-payment-noscript">
            JavaScript is required for embedded card payments. Pick the
            other payment method, or enable JavaScript and reload.
        </p>
    </noscript>
</div>
<script src="https://js.stripe.com/v3/" defer></script>
<script>
(function () {
    // Idempotency: this script may be evaluated more than once if the
    // checkout form re-renders (e.g. after a validation flash). Bail
    // out if we've already wired everything up.
    if (window.__slateStripeEmbedded) return;
    window.__slateStripeEmbedded = true;

    var publishableKey  = <?= json_encode($publishableKey) ?>;
    var createIntentUrl = <?= json_encode($createIntentUrl) ?>;

    // State machine. The submit handler MUST refuse to call
    // stripe.confirmPayment() in any state except 'ready'.
    //
    //   idle         → no init attempted yet, or last attempt failed
    //   initializing → fetch + Stripe.js + .mount() in progress
    //   mounting     → mount() called, awaiting element's `ready` event
    //   ready        → safe to call confirmPayment()
    //   submitting   → confirmPayment in flight; refuse re-entry
    var state = 'idle';

    var stripe          = null;
    var elements        = null;
    var paymentElement  = null;
    var clientSecret    = null;
    var paymentIntentId = null;

    // Avoid console-spamming in production but keep a single log line
    // per state change so problems are diagnosable when a customer
    // sends us a screenshot of their devtools.
    function log(msg, extra) {
        if (window.console && console.log) {
            console.log('[slate-stripe]', msg, extra === undefined ? '' : extra);
        }
    }

    function whenStripeReady(cb) {
        if (window.Stripe) return cb();
        var tries = 0;
        var iv = setInterval(function () {
            tries++;
            if (window.Stripe) { clearInterval(iv); cb(); }
            else if (tries > 100) {  // ~10s
                clearInterval(iv);
                showError('Could not load Stripe. Refresh the page and try again.');
            }
        }, 100);
    }

    function showError(msg) {
        log('error', msg);
        var box = document.getElementById('sp-payment-errors');
        if (box) { box.textContent = msg; box.hidden = false; }
    }
    function hideError() {
        var box = document.getElementById('sp-payment-errors');
        if (box) { box.hidden = true; }
    }
    function setLoading(visible) {
        var loading = document.getElementById('sp-payment-loading');
        var mount   = document.getElementById('sp-payment-element');
        if (loading) loading.hidden = !visible;
        if (mount)   mount.hidden   = visible;
    }
    function setSubmitDisabled(disabled, text) {
        var form = document.querySelector('form');
        if (!form) return;
        var btn = form.querySelector('button[type="submit"]');
        if (!btn) return;
        if (disabled) {
            if (!btn.dataset.spOrigText) btn.dataset.spOrigText = btn.textContent;
            btn.disabled = true;
            if (text) btn.textContent = text;
        } else {
            btn.disabled = false;
            if (btn.dataset.spOrigText) {
                btn.textContent = btn.dataset.spOrigText;
                delete btn.dataset.spOrigText;
            }
        }
    }

    function findEmbeddedRadio() {
        return document.querySelector('input[name="payment_method"][value="stripe_embedded"]');
    }
    function isEmbeddedSelected() {
        var r = findEmbeddedRadio();
        return r && r.checked;
    }

    async function initElement() {
        if (state !== 'idle') {
            log('initElement: state=' + state + ', skipping');
            return;
        }
        state = 'initializing';
        log('initElement: start');
        hideError();
        setLoading(true);
        // Disable submit until ready
        setSubmitDisabled(true, 'Loading payment form…');

        var resp;
        try {
            resp = await fetch(createIntentUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
        } catch (e) {
            state = 'idle';
            setLoading(false);
            setSubmitDisabled(false);
            showError('Network error contacting payment server. ' + (e.message || ''));
            return;
        }
        var data = null;
        try { data = await resp.json(); } catch (e) {}
        if (!resp.ok || !data || !data.client_secret) {
            state = 'idle';
            setLoading(false);
            setSubmitDisabled(false);
            var msg = (data && data.error) ? data.error
                : ('Could not initialise payment (HTTP ' + resp.status + ').');
            showError(msg);
            return;
        }
        clientSecret    = data.client_secret;
        paymentIntentId = data.payment_intent_id || null;
        log('initElement: got client_secret, mounting');

        try {
            stripe   = window.Stripe(publishableKey);
            elements = stripe.elements({
                clientSecret: clientSecret,
                appearance: { theme: 'stripe' }
            });
            paymentElement = elements.create('payment', { layout: 'tabs' });
        } catch (e) {
            state = 'idle';
            setLoading(false);
            setSubmitDisabled(false);
            showError('Stripe.js error: ' + (e.message || e));
            log('stripe.elements()/create() threw', e);
            return;
        }

        // Wire up the element's lifecycle events BEFORE mounting:
        //
        // - 'ready'  : iframe loaded, safe to call confirmPayment()
        // - 'change' : surfaces incremental validation messages
        // - 'loaderror' : Stripe couldn't fetch its iframe (network/CSP)
        paymentElement.on('ready', function () {
            log('paymentElement ready');
            state = 'ready';
            setLoading(false);
            setSubmitDisabled(false);
        });
        paymentElement.on('change', function (event) {
            if (event && event.complete) hideError();
        });
        paymentElement.on('loaderror', function (event) {
            state = 'idle';
            setLoading(false);
            setSubmitDisabled(false);
            var em = (event && event.error && event.error.message)
                ? event.error.message
                : 'Could not load Stripe payment form.';
            showError(em);
            log('paymentElement loaderror', event);
        });

        var mount = document.getElementById('sp-payment-element');
        if (!mount) {
            state = 'idle';
            setLoading(false);
            setSubmitDisabled(false);
            showError('Payment form container not found. Reload the page.');
            return;
        }
        state = 'mounting';
        try {
            paymentElement.mount(mount);
            log('mount called');
        } catch (e) {
            state = 'idle';
            setLoading(false);
            setSubmitDisabled(false);
            showError('Failed to mount payment form: ' + (e.message || e));
            log('mount threw', e);
            return;
        }

        // Always-present hidden field so the form POST that creates
        // the order knows which intent to verify.
        ensureHiddenField('stripe_payment_intent_id', paymentIntentId);
    }

    function ensureHiddenField(name, value) {
        var form = document.querySelector('form');
        if (!form) return;
        var existing = form.querySelector('input[name="' + name + '"]');
        if (existing) { existing.value = value; return; }
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = name;
        inp.value = value;
        form.appendChild(inp);
    }

    // Intercept form submission ONLY when the embedded option is the
    // selected payment method. Otherwise let the form submit normally.
    function attachSubmit() {
        var form = document.querySelector('form');
        if (!form) return;

        form.addEventListener('submit', async function (e) {
            if (!isEmbeddedSelected()) return;            // not our problem
            if (form.dataset.spConfirmed === '1') return; // already confirmed in a prior submit, let through
            e.preventDefault();

            // State gating — only proceed when element is ready.
            if (state === 'idle') {
                showError('Payment form is not ready. Please wait or pick another payment method.');
                // Kick off init in case the radio change handler didn't.
                initElement();
                return;
            }
            if (state === 'initializing' || state === 'mounting') {
                showError('Payment form is still loading — please wait a moment and try again.');
                return;
            }
            if (state === 'submitting') {
                // Double-click — already in flight.
                return;
            }
            if (state !== 'ready') {
                showError('Payment form is in an unexpected state (' + state + '). Reload the page.');
                return;
            }
            if (!stripe || !elements) {
                // Defensive — should be unreachable when state===ready.
                showError('Payment form not fully initialised. Reload the page.');
                return;
            }

            hideError();
            state = 'submitting';
            setSubmitDisabled(true, 'Processing…');

            var result;
            try {
                result = await stripe.confirmPayment({
                    elements: elements,
                    confirmParams: {
                        // Stripe needs this even when redirect: 'if_required'
                        return_url: window.location.origin
                            + '/plugins/stripe-payment/public/return.php',
                    },
                    redirect: 'if_required',
                });
            } catch (e) {
                state = 'ready';
                setSubmitDisabled(false);
                showError('Unexpected error: ' + (e.message || e));
                log('confirmPayment threw', e);
                return;
            }

            if (result.error) {
                state = 'ready';
                setSubmitDisabled(false);
                showError(result.error.message || 'Payment failed.');
                log('confirmPayment error', result.error);
                return;
            }

            var pi = result.paymentIntent;
            if (!pi || (pi.status !== 'succeeded' && pi.status !== 'requires_capture')) {
                state = 'ready';
                setSubmitDisabled(false);
                showError('Payment did not complete (status: '
                    + (pi ? pi.status : 'unknown') + ').');
                return;
            }

            // Payment succeeded client-side. Submit the form so the
            // server can verify the PaymentIntent independently and
            // create the order.
            ensureHiddenField('stripe_payment_intent_id', pi.id);
            form.dataset.spConfirmed = '1';
            form.submit();
        });
    }

    // Initialise (or tear down) when the radio changes.
    function onRadioChange() {
        if (isEmbeddedSelected()) {
            initElement();
        }
    }

    whenStripeReady(function () {
        attachSubmit();
        document.querySelectorAll('input[name="payment_method"]').forEach(function (r) {
            r.addEventListener('change', onRadioChange);
        });
        // If embedded is selected by default on page load, initialise.
        if (isEmbeddedSelected()) initElement();
    });
})();
</script>
<style>
.sp-embedded-wrap { margin-top: 0.75rem; }
.sp-payment-element { padding: 6px 2px; min-height: 60px; }
.sp-payment-loading {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    font-size: 13px;
    color: var(--text-2, #5a6b7e);
}
.sp-payment-loading[hidden] { display: none; }
.sp-payment-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: sp-spin 0.8s linear infinite;
}
@keyframes sp-spin {
    to { transform: rotate(360deg); }
}
.sp-payment-errors {
    margin-top: 0.6rem;
    padding: 0.6rem 0.75rem;
    background: rgba(220,38,38,0.06);
    border: 1px solid rgba(220,38,38,0.3);
    border-radius: 6px;
    color: #b91c1c;
    font-size: 13px;
}
.sp-payment-noscript {
    margin-top: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: var(--bg-2, #f7f7f9);
    border-radius: 6px;
    font-size: 13px;
}
</style>
        <?php
        return ob_get_clean();
    }

    /**
     * Server-side dispatcher for the embedded provider. By the time we
     * get here, JS has already confirmed the PaymentIntent client-side,
     * and the form's hidden `stripe_payment_intent_id` field tells us
     * which intent to verify. We re-verify the status against Stripe
     * (never trust the client), create the order, and return synchronously
     * — no redirect needed since payment is already complete.
     *
     * This looks up the same stripepayment_sessions row (by
     * payment_intent_id) as webhook.php's payment_intent.succeeded handler
     * and return.php's 3DS-redirect handler, and can genuinely race with
     * either of them, or with a second concurrent call to this same method
     * (e.g. a double place-order submit). That race is closed by the same
     * atomic status='claimed' conditional-UPDATE mechanism webhook.php's
     * completePayment(), success.php, and return.php use — see the
     * "Atomically claim" comment below. Unlike those three top-level entry
     * scripts, this is a class method with no header()/exit() anywhere in
     * it — every path returns an array — so the claim/release/wait logic
     * here is implemented as private methods (embeddedReleaseClaim(),
     * embeddedWaitForCompletion()) rather than copied as bare functions,
     * and the wait-on-rejection outcome is translated into this function's
     * own existing return shapes rather than any redirect.
     */
    public function handleEmbeddedCheckout(string $sid, array $billing): array {
        if (!class_exists('StripeAPI')) {
            return ['ok' => false, 'error' => __('sp_err_stripe_plugin_not_loaded', 'Stripe plugin not loaded.')];
        }

        $piId = trim((string)($_POST['stripe_payment_intent_id'] ?? ''));
        if ($piId === '') {
            return ['ok' => false, 'error' =>
                'Payment was not confirmed — please re-enter your card details and try again.'];
        }

        // Look up the mapping row that create-intent.php wrote when it
        // created this PaymentIntent. This anchors us to a known cart
        // sid; a forged $piId from a different browser session can't
        // be used to consume someone else's cart.
        $row = Database::row(
            "SELECT id, shop_sid, order_id, status
               FROM stripepayment_sessions
              WHERE payment_intent_id = ?",
            [$piId]
        );
        if (!$row) {
            return ['ok' => false, 'error' => __('sp_err_unknown_payment_session', 'Unknown payment session.')];
        }
        if ($row['shop_sid'] !== $sid) {
            slate_log("Stripe embedded checkout: shop_sid mismatch on $piId", 'warning');
            return ['ok' => false, 'error' => __('sp_err_payment_session_does_not_match_your_cart', 'Payment session does not match your cart.')];
        }

        // Already converted to an order? — webhook beat us here. Return
        // success with the existing order_id; shop/checkout will redirect
        // to its confirmation page.
        if (!empty($row['order_id'])) {
            return ['ok' => true, 'order_id' => (int)$row['order_id']];
        }

        // Verify with Stripe directly — the client said "succeeded",
        // but we don't trust the client.
        try {
            $pi = StripeAPI::getPaymentIntent($piId);
        } catch (\Throwable $e) {
            slate_log('Stripe getPaymentIntent failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => __('sp_err_could_not_verify_payment_with_stripe', 'Could not verify payment with Stripe.')];
        }
        $status = (string)($pi['status'] ?? '');
        if ($status !== 'succeeded' && $status !== 'requires_capture') {
            return ['ok' => false, 'error' =>
                'Payment did not complete on Stripe (status: ' . $status . ').'];
        }

        // Persist the updated billing on the row (the customer may have
        // edited the form between create-intent and place-order).
        try {
            Database::update('stripepayment_sessions',
                ['billing_json' => json_encode($billing)],
                'id = ?', [(int)$row['id']]
            );
        } catch (\Throwable $e) {
            slate_log('Stripe embedded billing update failed: ' . $e->getMessage(), 'warning');
        }

        // Atomically claim this row before doing any work — closes the
        // TOCTOU between the order_id check above and the mapping write
        // below. Without this, a concurrent webhook.php delivery, a
        // concurrent return.php hit, or a second concurrent call to this
        // same method (e.g. a double place-order submit) can pass the same
        // order_id check and independently reach ShopAPI::checkoutCart(),
        // creating a second real order; it has no de-duplication of its
        // own. Same atomic conditional UPDATE and state name ('claimed')
        // as webhook.php's completePayment(), success.php, and return.php
        // — see webhook.php for the full mechanics writeup.
        $claimed = Database::update(
            'stripepayment_sessions',
            ['status' => 'claimed'],
            'id = ? AND order_id IS NULL AND status != ?',
            [(int)$row['id'], 'claimed']
        );

        if ($claimed === 0) {
            // Someone else — webhook.php, return.php, or another call to
            // this same method — is either actively processing this row or
            // has already finished. Give the in-flight case a short,
            // tightly bounded window to land rather than immediately
            // reporting failure for what may actually be a success in
            // progress. Translate the outcome into this function's own
            // existing return shapes (never a redirect).
            $winner = $this->embeddedWaitForCompletion((int)$row['id']);
            if ($winner !== null) {
                return ['ok' => true, 'order_id' => (int)$winner['order_id']];
            }
            slate_log("Stripe embedded checkout: claim unavailable for $piId (row {$row['id']})", 'warning');
            return ['ok' => false, 'error' =>
                'Payment succeeded but order could not be recorded. Please contact us.'];
        }

        // Create the order. Same path as success.php / webhook.php.
        try {
            $res = ShopAPI::checkoutCart($sid, $billing);
        } catch (\Throwable $e) {
            slate_log('Stripe embedded checkoutCart threw: ' . $e->getMessage(), 'error');
            $this->embeddedReleaseClaim((int)$row['id']);
            return ['ok' => false, 'error' =>
                'Payment succeeded but order could not be recorded. Please contact us.'];
        }
        if (empty($res['ok']) || empty($res['order_id'])) {
            slate_log('Stripe embedded order creation failed: ' .
                ($res['error'] ?? '?'), 'error');
            $this->embeddedReleaseClaim((int)$row['id']);
            return ['ok' => false, 'error' => $res['error']
                ?? 'Payment succeeded but order could not be recorded. Please contact us.'];
        }
        $orderId = (int)$res['order_id'];

        // Reconcile what Stripe actually captured against the rebuilt order
        // total. If the cart drifted between intent creation and payment,
        // reconcilePaidAmount() parks the order on-hold for review instead
        // of auto-fulfilling a wrong total (mirrors success.php / webhook.php).
        $paidCents    = (int)($pi['amount_received'] ?? $pi['amount'] ?? 0);
        $paidCurrency = strtoupper((string)($pi['currency'] ?? ''));
        $recon        = ShopAPI::reconcilePaidAmount($orderId, $paidCents, $paidCurrency);

        try {
            Database::update('stripepayment_sessions', [
                'order_id'     => $orderId,
                'status'       => 'completed',
                'completed_at' => slate_db_now(),
            ], 'id = ?', [(int)$row['id']]);
            // Advance to 'processing' only when the captured amount matches.
            // On a mismatch reconcilePaidAmount() already set 'on-hold'.
            if (!empty($recon['ok'])) {
                ShopAPI::updateStatus($orderId, 'processing');
            }
        } catch (\Throwable $e) {
            slate_log('Stripe embedded mapping update failed: ' . $e->getMessage(), 'warning');
        }

        return ['ok' => true, 'order_id' => $orderId];
    }

    /**
     * Best-effort release of the 'claimed' state back to 'pending' so a
     * later retry (a resubmitted place-order, or the webhook) can attempt
     * this row again. Called only from handleEmbeddedCheckout()'s checkout
     * failure paths — never on success, where the row moves straight to
     * 'completed' instead. Named distinctly from webhook.php's
     * releaseClaim(), success.php's successReleaseClaim(), and
     * return.php's returnReleaseClaim(): all four call sites implement the
     * same claim protocol independently (no shared helper yet), matching
     * the approved scope for this fix.
     */
    private function embeddedReleaseClaim(int $rowId): void {
        try {
            Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
        } catch (\Throwable $e) {
            slate_log("Stripe embedded checkout: failed to release claim on row $rowId: " . $e->getMessage(), 'warning');
        }
    }

    /**
     * Short, tightly bounded wait for a concurrent completion (webhook.php,
     * return.php, or another call to handleEmbeddedCheckout()) to finish
     * claiming this row, used only when our own claim attempt was
     * rejected. Not a polling loop or a long-lived wait — a fixed handful
     * of very short re-reads (5 x 100ms = 500ms worst case, only on the
     * rare collision path). Returns the row once order_id is populated, or
     * null if it never lands within the window.
     */
    private function embeddedWaitForCompletion(int $rowId): ?array {
        for ($i = 0; $i < 5; $i++) {
            usleep(100000);
            $fresh = Database::row(
                "SELECT order_id FROM stripepayment_sessions WHERE id = ?",
                [$rowId]
            );
            if ($fresh && !empty($fresh['order_id'])) {
                return $fresh;
            }
        }
        return null;
    }

    // ── Schema ────────────────────────────────────────────────

    private function runMigrations(): void {
        // v1.0.0 — initial table
        try {
            Database::query("
                CREATE TABLE IF NOT EXISTS stripepayment_sessions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    stripe_session_id VARCHAR(255) NULL,
                    shop_sid          VARCHAR(64)  NOT NULL,
                    order_id          INT UNSIGNED NULL,
                    status            VARCHAR(20)  NOT NULL DEFAULT 'pending',
                    billing_json      TEXT NULL,
                    created_at        DATETIME NOT NULL,
                    completed_at      DATETIME NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_stripe_session_id (stripe_session_id),
                    KEY idx_shop_sid (shop_sid),
                    KEY idx_status   (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            slate_log('stripe-payment migration (create table) failed: ' . $e->getMessage(), 'error');
        }

        // v1.1.0 — add payment_intent_id (embedded flow) and flow column.
        // stripe_session_id becomes nullable: embedded-only rows don't
        // have a Checkout Session id.
        $this->ensureColumn('stripepayment_sessions', 'payment_intent_id',
            "VARCHAR(255) NULL AFTER stripe_session_id");
        $this->ensureColumn('stripepayment_sessions', 'flow',
            "VARCHAR(16) NOT NULL DEFAULT 'hosted' AFTER shop_sid");
        $this->ensureIndex('stripepayment_sessions', 'uniq_payment_intent_id',
            'UNIQUE (payment_intent_id)');

        // The original create-table made stripe_session_id NOT NULL.
        // For new installs (table just created) we already used NULL.
        // For existing 1.0.0/1.0.1 installs we need to relax it.
        try {
            Database::query(
                "ALTER TABLE stripepayment_sessions
                 MODIFY stripe_session_id VARCHAR(255) NULL"
            );
        } catch (\Throwable $e) {
            // Already nullable? — silent.
            slate_log('stripe-payment migration (relax NOT NULL) note: ' . $e->getMessage(), 'info');
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("stripe-payment ensureColumn $table.$column: " . $e->getMessage(), 'error');
        }
    }

    private function ensureIndex(string $table, string $indexName, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$table, $indexName]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("stripe-payment ensureIndex $table.$indexName: " . $e->getMessage(), 'error');
        }
    }

    private function schemaIsCurrent(): bool {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripepayment_sessions'"
            );
            if ((int)$exists === 0) return false;
            foreach (['payment_intent_id', 'flow'] as $col) {
                $e = Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripepayment_sessions'
                        AND COLUMN_NAME = ?",
                    [$col]
                );
                if ((int)$e === 0) return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
