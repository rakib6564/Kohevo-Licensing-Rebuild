<?php
/**
 * Membership — member-facing area.  URL: /member
 *
 *   ?view=home    (default)  — membership status + quick links
 *   ?view=onboarding         — 4-step profile-completion wizard (gate)
 *   ?view=profile            — view / edit member profile (+ avatar)
 *   ?view=plans              — browse + buy plans (POST _action=buy)
 *   ?view=schedule           — upcoming bookings (read from the Booking plugin)
 *   ?view=card               — digital member card + QR (downloadable)
 *   ?view=return             — Stripe checkout return (verifies + activates)
 *   ?view=wallet             — balance + transaction ledger
 *   POST _action=cancel_sub  — member self-cancels their membership
 *
 * Identity is core customers — the whole area requires a signed-in customer.
 * Access to everything except the wizard is gated on a completed profile
 * (4-step onboarding), per the product spec.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('membership');
require_once SLATE_ROOT . '/includes/portal_ui.php';   // slate_portal_asset_url(), slate_icon()
require_once dirname(__DIR__) . '/MembershipAPI.php';
MembershipAPI::ensureSchema();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

Auth::requireCustomer();
// Customer portal treatment (07 §4): the customer is already identifiable
// via their session, so a clear "not available" 403 is appropriate here —
// unlike the anonymous marketing landing page (public/landing.php), which
// uses the anti-enumeration 404 instead.
ModuleGuard::require('membership');
$cid  = (int) Auth::customerId();
$view = (string)($_GET['view'] ?? '');
if ($view === '') {
    $rPath = trim((string)($_GET['_route_path'] ?? ''), '/');
    if ($rPath !== '') {
        $view = ($rPath === 'overview') ? 'home' : $rPath;
    } else {
        $view = 'home';
    }
}
$tid   = current_tenant_id();
$flash = null;

MembershipAPI::ensureProfile($cid);

// A caller (typically Booking's step-4 gate notice) can ask to be sent back
// here once the member is fully cleared to book — stashed in the session so
// it survives the off-site Stripe checkout round trip and, if needed, the
// onboarding wizard. Only overwrite on a genuinely valid, same-origin value
// so a stray/invalid return_to on a later page view can't clobber one
// already in flight.
if (!empty($_GET['return_to'])) {
    $candidate = slate_safe_redirect_target((string)$_GET['return_to'], '');
    if ($candidate !== '') {
        $_SESSION['membership_return_to'] = $candidate;
    }
}

/**
 * If a return_to is stashed AND the member is now fully cleared (profile
 * complete — the other half, an active plan, is whatever just got them
 * here), send them back and forget the stash. Otherwise leave it in place:
 * the caller isn't done yet (e.g. still mid-onboarding), and Booking's own
 * gate will simply re-check next time they land on this URL.
 */
function membership_maybe_return(int $cid): bool {
    if (empty($_SESSION['membership_return_to'])) return false;
    $prof = MembershipAPI::profile($cid);
    if (MembershipAPI::profileCompletionEnabled() && empty($prof['onboarding_complete'])) return false;
    $dest = $_SESSION['membership_return_to'];
    unset($_SESSION['membership_return_to']);
    header('Location: ' . $dest);
    exit;
}

// ── POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('membership_csrf', 'Security check failed.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'buy') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $res = MembershipAPI::purchase($cid, $planId, !empty($_POST['add_insurance']));
            if (!empty($res['ok']) && !empty($res['url'])) { header('Location: ' . $res['url']); exit; }
            if (!empty($res['ok']) && !empty($res['free'])) {
                membership_maybe_return($cid);
                header('Location: ' . SLATE_URL . '/member?view=home&activated=1'); exit;
            }
            $flash = ['type' => 'error', 'msg' => $res['error'] ?? __('membership_buy_failed', 'Could not start the purchase.')];
            $view = 'plans';
        }

        elseif ($action === 'cancel_sub') {
            $subId = (int)($_POST['sub_id'] ?? 0);
            $sub   = MembershipAPI::subscription($subId);
            if ($sub && (int)$sub['customer_id'] === $cid) {
                MembershipAPI::cancelSubscription($subId, true);
                $flash = ['type' => 'success', 'msg' => __('membership_cancelled_ok', 'Your membership was cancelled.')];
            } else {
                $flash = ['type' => 'error', 'msg' => __('membership_not_found', 'Subscription not found.')];
            }
            $view = 'home';
        }

        elseif ($action === 'save_onboarding') {
            $step = max(1, min(4, (int)($_POST['step'] ?? 1)));
            $prof = MembershipAPI::profile($cid) ?: [];
            $fields = [];

            if ($step === 1) {
                $g = (string)($_POST['gender'] ?? 'undisclosed');
                $fields['gender'] = array_key_exists($g, MembershipAPI::genders()) ? $g : 'undisclosed';
                $fields['dob']    = trim((string)($_POST['dob'] ?? '')) !== '' ? (string)$_POST['dob'] : null;
                // Phone lives on the core customer record.
                $phone = trim((string)($_POST['phone'] ?? ''));
                Database::update('customers', ['phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null], 'id = ? AND tenant_id = ?', [$cid, $tid]);
            } elseif ($step === 2) {
                $sk = (string)($_POST['skill_level'] ?? 'none');
                $fields['skill_level']   = array_key_exists($sk, MembershipAPI::skillLevels()) ? $sk : 'none';
                $fields['medical_notes'] = trim((string)($_POST['medical_notes'] ?? '')) ?: null;
                $fields['allergies']     = trim((string)($_POST['allergies'] ?? '')) ?: null;
            } elseif ($step === 3) {
                $fields['emergency_name']     = trim((string)($_POST['emergency_name'] ?? '')) ?: null;
                $fields['emergency_phone']    = trim((string)($_POST['emergency_phone'] ?? '')) ?: null;
                $fields['emergency_relation'] = trim((string)($_POST['emergency_relation'] ?? '')) ?: null;
            } elseif ($step === 4) {
                if (empty($_POST['consent_terms'])) {
                    $flash = ['type' => 'error', 'msg' => __('membership_consent_required', 'You must accept the terms to continue.')];
                    $view = 'onboarding';
                } else {
                    $fields['consent_terms'] = 1;
                    $fields['consent_media'] = !empty($_POST['consent_media']) ? 1 : 0;
                    $fields['consent_at']    = slate_db_now();   // CORE-1: evidentiary, DB clock
                    $fields['onboarding_complete'] = 1;
                }
            }

            if (!($step === 4 && !empty($flash))) {
                $fields['onboarding_step'] = max((int)($prof['onboarding_step'] ?? 0), $step);
                Database::update('membership_profiles', $fields, 'customer_id = ? AND tenant_id = ?', [$cid, $tid]);
                AuditLog::record('membership.onboarding_step', (string)$cid, ['step' => $step]);
                if ($step >= 4) {
                    membership_maybe_return($cid);
                    header('Location: ' . SLATE_URL . '/member?view=home&welcome=1'); exit;
                }
                header('Location: ' . SLATE_URL . '/member?view=onboarding&step=' . ($step + 1)); exit;
            }
        }

        elseif ($action === 'save_profile') {
            $g  = (string)($_POST['gender'] ?? 'undisclosed');
            $sk = (string)($_POST['skill_level'] ?? 'none');
            $fields = [
                'gender'             => array_key_exists($g, MembershipAPI::genders()) ? $g : 'undisclosed',
                'dob'                => trim((string)($_POST['dob'] ?? '')) !== '' ? (string)$_POST['dob'] : null,
                'skill_level'        => array_key_exists($sk, MembershipAPI::skillLevels()) ? $sk : 'none',
                'medical_notes'      => trim((string)($_POST['medical_notes'] ?? '')) ?: null,
                'allergies'          => trim((string)($_POST['allergies'] ?? '')) ?: null,
                'emergency_name'     => trim((string)($_POST['emergency_name'] ?? '')) ?: null,
                'emergency_phone'    => trim((string)($_POST['emergency_phone'] ?? '')) ?: null,
                'emergency_relation' => trim((string)($_POST['emergency_relation'] ?? '')) ?: null,
            ];
            // Avatar (optional).
            if (!empty($_FILES['avatar']['name'])) {
                $up = Uploads::handle('avatar', 'membership/avatars', [
                    'max_bytes'     => 4 * 1024 * 1024,
                    'allowed_exts'  => ['jpg', 'jpeg', 'png', 'webp'],
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                ]);
                if (!empty($up['ok'])) {
                    $old = MembershipAPI::profile($cid)['avatar_path'] ?? '';
                    $fields['avatar_path'] = $up['path'];
                    if ($old) { try { Uploads::remove($old); } catch (\Throwable $e) {} }
                } else {
                    $flash = ['type' => 'error', 'msg' => $up['error'] ?? 'Avatar upload failed.'];
                }
            }
            $phone = trim((string)($_POST['phone'] ?? ''));
            Database::update('customers', ['phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null], 'id = ? AND tenant_id = ?', [$cid, $tid]);
            Database::update('membership_profiles', $fields, 'customer_id = ? AND tenant_id = ?', [$cid, $tid]);
            AuditLog::record('membership.profile_updated', (string)$cid);
            if (!$flash) $flash = ['type' => 'success', 'msg' => __('membership_profile_saved', 'Profile saved.')];
            $view = 'profile';
        }
    }
}

// ── Stripe return: verify the session, activate if the webhook hasn't yet ──
if ($view === 'return') {
    $subId   = (int)($_GET['sub'] ?? 0);
    $session = (string)($_GET['session_id'] ?? '');
    $sub     = MembershipAPI::subscription($subId);
    if ($sub && (int)$sub['customer_id'] === $cid) {
        if (($sub['status'] ?? '') !== 'active' && $session !== '' && class_exists('StripePaymentAPI')) {
            $s = StripePaymentAPI::getSession($session);
            if ($s && ($s['payment_status'] ?? '') === 'paid') {
                MembershipAPI::activateSubscription($subId, ['amount_cents' => (int)($s['amount_total'] ?? $sub['amount_cents'])]);
            }
        }
        $sub   = MembershipAPI::subscription($subId);
        $active = $sub && $sub['status'] === 'active';
        $flash = $active
            ? ['type' => 'success', 'msg' => __('membership_activated_ok', 'Payment received — your membership is active!')]
            : ['type' => 'info', 'msg' => __('membership_processing', 'Payment received — your membership will be active shortly.')];
        if ($active) membership_maybe_return($cid);
    }
    $view = 'home';
}

$profile = MembershipAPI::profile($cid) ?: [];
$status  = MembershipAPI::status($cid);
// With profile completion switched off nobody is 'un-onboarded', so no gate and the full nav shows.
$onboarded = !MembershipAPI::profileCompletionEnabled() || !empty($profile['onboarding_complete']);

// Profile-completion gate: until the wizard is done, the only view is itself.
if (!$onboarded && $view !== 'onboarding') {
    $view = 'onboarding';
}
// Switched off: the wizard is unreachable, not merely unforced (old links / bookmarks land on home).
if (!MembershipAPI::profileCompletionEnabled() && $view === 'onboarding') {
    $view = 'home';
}

// ── App shell (self-contained, mobile-app style) ────────────────────────
$selfUrl  = SLATE_URL . '/member';
$cust     = Auth::customer();
$siteName = Database::setting('site_name') ?: 'Kohevo';

// Brand accent + logo come from the SAME core settings the rest of the
// portal reads (brand_accent_color / brand_logo_path), so a tenant changing
// their brand colour in Admin shifts membership with everything else. This
// used to read ContentBuilder's accent_color, which drifted to a different
// cyan than core and broke the "one brand, one accent" contract.
$accent = trim((string) Database::setting('brand_accent_color'));
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) $accent = '#111111';

$logoUrls = slate_logo_urls();
$logoUrl  = $logoUrls['light'];
$logoDarkUrl = $logoUrls['dark'];

$memberName = (string)($cust['name'] ?? $cust['email'] ?? 'Member');
$initial    = mb_strtoupper(mb_substr($memberName, 0, 1));
$avatarPath = (string)($profile['avatar_path'] ?? '');

// The member area's own pages. These are SECTION navigation — "which page of
// my membership" — and stay distinct from the portal's global nav ("which part
// of my account"), which the shared shell renders in the bar.
$cardEnabled = MembershipAPI::cardEnabled();
if ($view === 'card' && !$cardEnabled) {
    $view = 'home';
}

$tabs = [
    ['v'=>'home',     'label'=>__('membership_overview', 'Overview'), 'icon'=>'grid'],
    ['v'=>'plans',    'label'=>__('membership_plans', 'Plans'),       'icon'=>'tag'],
];
if ($cardEnabled) {
    $tabs[] = ['v'=>'card', 'label'=>__('membership_card', 'Card'), 'icon'=>'qr'];
}
$tabs[] = ['v'=>'schedule', 'label'=>__('membership_schedule', 'Schedule'), 'icon'=>'calendar'];
$tabs[] = ['v'=>'profile',  'label'=>__('membership_profile', 'Profile'),   'icon'=>'user'];
$showChrome = $onboarded;   // the onboarding gate hides section nav

// Slate has ONE language switcher, provided site-wide by the Multilang
// Translate plugin via the same customer_portal_bar_actions slot (see
// MultilangTranslate::addPortalBarSwitcher()) — Membership no longer runs
// its own. That plugin drives the same ?lang= / I18n::currentLocale()
// this page's own __() calls already read, so nothing else here changes.

require_once SLATE_ROOT . '/includes/portal_shell.php';

$currentPortalNav = 'membership';
slate_portal_shell_head(__('membership_your', 'Your membership'));
slate_portal_shell_open([
    'active'        => 'membership',
    'area'          => $showChrome ? 'membership' : '',
    'subnav_active' => ($view === 'home' ? 'overview' : $view),
    'auto_subnav'   => ($showChrome && $view !== 'home'),
]);

if ($showChrome && $view !== 'home') {
    slate_portal_subnav(array_map(static fn (array $t): array => [
        'href'   => SLATE_URL . '/member/membership/' . ($t['v'] === 'home' ? 'overview' : $t['v']),
        'label'  => $t['label'],
        'icon'   => $t['icon'],
        'active' => $view === $t['v'],
    ], $tabs));
}

if ($flash) {
    echo '<div class="mapp-flash mapp-flash--' . e((string) $flash['type']) . '" role="status">'
       . e((string) $flash['msg']) . '</div>';
}

$views = dirname(__DIR__) . '/public/views';
$known = ['onboarding', 'profile', 'schedule', 'card', 'plans', 'wallet', 'home'];
$v = in_array($view, $known, true) ? $view : 'home';
require $views . '/' . $v . '.php';

slate_portal_shell_close();
return;
