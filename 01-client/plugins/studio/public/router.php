<?php
/**
 * Studio — public customer area at /studio.
 *
 *   ?view=catalog (default)   — browse active classes (no login)
 *   ?view=class&id=…           — class detail (no login)
 *   ?view=portal              — parent dashboard (login required)
 *   ?view=register&class=…     — self-serve enrollment (login required)
 *
 * config.php is already loaded by the PublicRouter; portal_ui provides the
 * head/topbar/foot chrome so the page matches the customer app.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('studio');
ModuleGuard::requirePublic('dance-studio');
require_once SLATE_ROOT . '/includes/portal_ui.php';
require_once __DIR__ . '/_pubui.php';
require_once dirname(__DIR__) . '/StudioAPI.php';

use Slate\Services\Identity\ContactRepository;
use Slate\Tenancy\TenantContext;

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

// Small avatar helpers shared by the public views (guarded — admin/_ui may define them too).
if (!function_exists('studio_gravatar_url')) {
    function studio_gravatar_url(string $email, int $size = 80): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) { return ''; }
        return 'https://www.gravatar.com/avatar/' . md5($email) . '?s=' . ($size * 2) . '&d=404';
    }
}
if (!function_exists('studio_initials')) {
    function studio_initials(string $name): string
    {
        $i = '';
        foreach (preg_split('/\s+/', trim($name)) as $w) { if ($w !== '') { $i .= mb_substr($w, 0, 1); } if (mb_strlen($i) >= 2) { break; } }
        return mb_strtoupper($i !== '' ? $i : '?');
    }
}

$base = SLATE_URL . '/studio';
$view = (string) ($_GET['view'] ?? 'catalog');
if (!in_array($view, ['catalog', 'class', 'prices', 'policies', 'portal', 'register', 'pay', 'recital', 'tickets'], true)) {
    $view = 'catalog';
}

// ── Login-gated views + POST actions (must run before output) ──
if (in_array($view, ['portal', 'register', 'pay', 'recital', 'tickets'], true)) {
    Auth::requireCustomer();
    $cid = (int) Auth::customerId();

    // Reserve seats, then send the parent to Stripe. The row is written as
    // 'reserved' before checkout so the seats are held while they're away and
    // an abandoned checkout leaves something an admin can see and cancel.
    if ($view === 'tickets') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
            $rid = (int) ($_POST['recital_id'] ?? 0);
            try {
                $fam = StudioAPI::getFamilyByParent($cid);
                if ($fam === null) { throw new \RuntimeException(__('studio_no_family', 'No family on your account.')); }

                $cust     = Auth::customer();
                $ticketId = StudioAPI::reserveTickets($rid, (int) $fam['id'], (int) ($_POST['quantity'] ?? 1), [
                    'contact_id' => $cid,
                    'name'       => (string) ($cust['name'] ?? ''),
                    'email'      => (string) ($cust['email'] ?? ''),
                ]);

                $ticket  = Database::row('SELECT * FROM studio_recital_tickets WHERE tenant_id = ? AND id = ?',
                    [current_tenant_id(), $ticketId]);
                $recital = StudioAPI::getRecital($rid);

                if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
                    // No card processing configured — the reservation still
                    // stands and the studio can take payment at the door.
                    $_SESSION['studio_pub_flash'] = ['type' => 'success', 'msg' => sprintf(
                        __('studio_tickets_reserved', '%d seat(s) reserved for “%s”. Pay at the front desk.'),
                        (int) $ticket['quantity'], (string) $recital['name'])];
                    header('Location: ' . $base . '?view=recital&id=' . $rid); exit;
                }

                $sess = StripePaymentAPI::createCheckout(
                    [['name' => $recital['name'] . ' — ' . __('studio_tickets', 'tickets'),
                      'amount_cents' => (int) $recital['ticket_price_cents'],
                      'quantity' => (int) $ticket['quantity']]],
                    [
                        'currency'       => strtolower((string) ($ticket['currency'] ?? 'USD')),
                        'customer_email' => (string) ($cust['email'] ?? ''),
                        'success_url'    => $base . '?view=recital&id=' . $rid . '&paid=1&session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url'     => $base . '?view=recital&id=' . $rid . '&cancelled=1',
                        'metadata'       => [
                            'source_plugin' => 'studio',
                            'ticket_id'     => (string) $ticketId,
                            'tenant_id'     => (string) current_tenant_id(),
                            'customer_id'   => (string) $cid,
                        ],
                    ]
                );
                header('Location: ' . $sess['url']); exit;
            } catch (\Throwable $e) {
                $_SESSION['studio_pub_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        header('Location: ' . $base . '?view=recital&id=' . (int) ($_POST['recital_id'] ?? 0)); exit;
    }

    // Ticket checkout return — same self-reconciliation as tuition, for the
    // same reason: the webhook may never arrive.
    if ($view === 'recital' && isset($_GET['paid'])) {
        $sessionId = trim((string) ($_GET['session_id'] ?? ''));
        $done = false;
        if ($sessionId !== '' && class_exists('StripePaymentAPI')) {
            try {
                $sess = StripePaymentAPI::getSession($sessionId);
                $md   = $sess['metadata'] ?? [];
                $tk   = (int) ($md['ticket_id'] ?? 0);
                $owns = $tk > 0 && (int) Database::value(
                    "SELECT COUNT(*) FROM studio_recital_tickets t
                       JOIN studio_families f ON f.id = t.family_id AND f.tenant_id = t.tenant_id
                      WHERE t.tenant_id = ? AND t.id = ? AND f.primary_parent_id = ?",
                    [current_tenant_id(), $tk, $cid]) > 0;

                if ($sess && ($sess['payment_status'] ?? '') === 'paid'
                    && ($md['source_plugin'] ?? '') === 'studio' && $owns) {
                    StudioAPI::markTicketPaid($tk, (int) ($sess['amount_total'] ?? 0),
                        $sessionId, (string) ($sess['payment_intent'] ?? ''));
                    $done = true;
                }
            } catch (\Throwable $e) {
                slate_log('Studio: ticket return reconcile failed: ' . $e->getMessage(), 'error');
            }
        }
        $_SESSION['studio_pub_flash'] = ['type' => 'success', 'msg' => $done
            ? __('studio_tickets_paid', 'Payment received — your tickets are confirmed.')
            : __('studio_tickets_pending', 'Payment received — your tickets will confirm shortly.')];
        header('Location: ' . $base . '?view=recital&id=' . (int) ($_GET['id'] ?? 0)); exit;
    }

    // Start a Stripe checkout for one enrollment's (discounted) tuition.
    if ($view === 'pay') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
            try {
                $eid = (int) ($_POST['enrollment_id'] ?? 0);
                if (!StudioAPI::enrollmentBelongsToParent($eid, $cid)) {
                    throw new \RuntimeException(__('studio_not_your_enroll', 'That enrollment is not on your account.'));
                }
                if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
                    throw new \RuntimeException(__('studio_pay_unavailable', 'Online payment is not available right now.'));
                }
                $en = Database::row(
                    "SELECT e.student_id, e.series_id, s.name AS series_name
                       FROM studio_enrollments e JOIN studio_class_series s ON s.id = e.series_id
                      WHERE e.tenant_id = ? AND e.id = ?",
                    [current_tenant_id(), $eid]
                );
                if ($en === null) { throw new \RuntimeException(__('studio_enroll_missing', 'Enrollment not found.')); }
                $tu   = StudioAPI::calculateTuition((int) $en['student_id'], (int) $en['series_id']);
                $cust = Auth::customer();
                $sess = StripePaymentAPI::createCheckout(
                    [['name' => $en['series_name'] . ' — ' . __('studio_tuition', 'tuition'), 'amount_cents' => $tu->minor, 'quantity' => 1]],
                    [
                        'currency'       => strtolower($tu->currency),
                        'customer_email' => (string) ($cust['email'] ?? ''),
                        // Carry the session id back so the return can verify the
                        // payment itself rather than waiting on the webhook.
                        'success_url'    => $base . '?view=portal&paid=1&session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url'     => $base . '?view=portal&cancelled=1',
                        'metadata'       => [
                            'source_plugin' => 'studio',
                            'enrollment_id' => (string) $eid,
                            'tenant_id'     => (string) current_tenant_id(),
                            'customer_id'   => (string) $cid,
                        ],
                    ]
                );
                header('Location: ' . $sess['url']); exit;
            } catch (\Throwable $e) {
                $_SESSION['studio_pub_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        header('Location: ' . $base . '?view=portal'); exit;
    }

    // ── Stripe checkout return ──────────────────────────────────────────
    // Reconcile here rather than relying on the webhook. The webhook is still
    // the backstop (Studio::onStripeEvent), but it needs Stripe to reach this
    // host, and when it doesn't the parent pays and the enrollment stays
    // unpaid until an admin marks it by hand. Verifying the session on return
    // closes that gap; markEnrollmentPaid is idempotent, so whichever path
    // arrives first wins and the other is a no-op.
    if ($view === 'portal') {
        if (isset($_GET['paid'])) {
            $confirmed = false;
            $sessionId = trim((string) ($_GET['session_id'] ?? ''));

            if ($sessionId !== '' && class_exists('StripePaymentAPI')) {
                try {
                    $sess = StripePaymentAPI::getSession($sessionId);
                    $md   = $sess['metadata'] ?? [];
                    $eid  = (int) ($md['enrollment_id'] ?? 0);

                    // Only act on our own sessions, paid, and belonging to the
                    // signed-in parent — the session id arrives in a URL.
                    if ($sess
                        && ($sess['payment_status'] ?? '') === 'paid'
                        && ($md['source_plugin'] ?? '') === 'studio'
                        && $eid > 0
                        && StudioAPI::enrollmentBelongsToParent($eid, $cid)
                    ) {
                        $paidInfo = StudioAPI::enrollmentPaidInfo(
                            Database::value('SELECT meta FROM studio_enrollments WHERE tenant_id = ? AND id = ?',
                                [current_tenant_id(), $eid])
                        );
                        if (empty($paidInfo['paid'])) {
                            StudioAPI::markEnrollmentPaid(
                                $eid,
                                (int) ($sess['amount_total'] ?? 0),
                                $sessionId,
                                (string) ($sess['payment_intent'] ?? ''),
                                (string) ($sess['customer_details']['email'] ?? $sess['customer_email'] ?? '')
                            );
                        }
                        $confirmed = true;
                    }
                } catch (\Throwable $e) {
                    slate_log('Studio: checkout return reconcile failed: ' . $e->getMessage(), 'error');
                }
            }

            $_SESSION['studio_pub_flash'] = $confirmed
                ? ['type' => 'success', 'msg' => __('studio_paid_ok2', 'Payment received — thank you! Your tuition is marked paid.')]
                : ['type' => 'success', 'msg' => __('studio_paid_ok', 'Payment received — thank you! Your receipt will update shortly.')];
        } elseif (isset($_GET['cancelled'])) {
            $_SESSION['studio_pub_flash'] = ['type' => '', 'msg' => __('studio_paid_cancel', 'Payment cancelled — no charge was made.')];
        }
    }

    if ($view === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $_SESSION['studio_pub_flash'] = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
        } else {
            try {
                $seriesId  = (int) ($_POST['class_id'] ?? 0);
                $studentId = (int) ($_POST['student_id'] ?? 0);
                $newName   = trim((string) ($_POST['new_student'] ?? ''));
                if ($seriesId <= 0 || StudioAPI::getPublicClass($seriesId) === null) {
                    throw new \InvalidArgumentException(__('studio_pick_class', 'Please choose a class.'));
                }
                $famId = StudioAPI::ensureFamilyForParent($cid);
                if ($studentId <= 0 && $newName !== '') {
                    $studentId = (new ContactRepository(new TenantContext()))->create(['display_name' => $newName])->id;
                    StudioAPI::addStudentToFamily($famId, $studentId);
                }
                if ($studentId <= 0) {
                    throw new \InvalidArgumentException(__('studio_pick_dancer', 'Pick a dancer or add one.'));
                }
                $res = StudioAPI::enrollStudent($studentId, $seriesId);
                if (empty($res['ok'])) {
                    throw new \RuntimeException($res['error'] ?? __('studio_reg_failed', 'Could not register.'));
                }
                $cls = (string) Database::value('SELECT name FROM studio_class_series WHERE id = ?', [$seriesId]);
                $tu  = StudioAPI::calculateTuition($studentId, $seriesId);
                if (class_exists('Notifications')) {
                    $studentName = (string) Database::value('SELECT display_name FROM contacts WHERE id = ?', [$studentId]);
                    Notifications::add(
                        ($res['status'] === 'waitlist' ? 'Waitlisted · ' : 'New enrollment · ') . ($studentName ?: 'A student'),
                        [
                            'body' => ($studentName ?: 'A student') . ' '
                                    . ($res['status'] === 'waitlist' ? 'joined the waitlist for ' : 'registered for ') . $cls,
                            'url'  => function_exists('plugin_url') ? plugin_url('studio', 'admin/enrollments.php') : '',
                            'icon' => $res['status'] === 'waitlist' ? 'clock' : 'user-plus',
                        ]
                    );
                }
                $_SESSION['studio_pub_flash'] = ['type' => 'success', 'msg' => ($res['status'] === 'waitlist')
                    ? sprintf(__('studio_reg_waitlist', 'Added to the waitlist for “%s”. We\'ll be in touch if a spot opens.'), $cls)
                    : sprintf(__('studio_reg_ok2', 'Registered for “%s” — tuition $%s. Pay now below or from My Studio.'), $cls, number_format($tu->minor / 100, 2))];
            } catch (\Throwable $e) {
                $_SESSION['studio_pub_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
            }
            header('Location: ' . $base . '?view=portal'); exit;
        }
    }
}

$flash = $_SESSION['studio_pub_flash'] ?? null;
unset($_SESSION['studio_pub_flash']);

$siteName = Database::setting('site_name') ?: 'Studio';
$titles = ['catalog' => __('studio_catalog', 'Class catalog'), 'class' => __('studio_class', 'Class'),
           'portal' => __('studio_my_studio', 'My studio'), 'register' => __('studio_register', 'Register'),
           'recital' => __('studio_recital', 'Recital'), 'policies' => __('studio_policies', 'Policies')];

// One chrome for every view. The catalog and class pages are public, but they
// still belong to the same product as the parent portal, so they render in the
// shared shell too — the shell shows a Sign in button instead of navigation
// when nobody is logged in. The studio-public body class keeps the tokens and
// focus rings from the contrast pass.
require_once SLATE_ROOT . '/includes/portal_shell.php';

$currentPortalNav = 'studio';
slate_portal_shell_head($titles[$view] ?? $siteName, 'studio-public');
studio_pub_css();
slate_portal_shell_open();

if ($flash) {
    echo '<div class="mapp-flash mapp-flash--' . e((string) $flash['type']) . '" role="status">'
       . e((string) $flash['msg']) . '</div>';
}

switch ($view) {
    case 'class':    require __DIR__ . '/views/class.php'; break;
    case 'portal':   require __DIR__ . '/views/portal.php'; break;
    case 'register': require __DIR__ . '/views/register.php'; break;
    case 'recital':  require __DIR__ . '/views/recital.php'; break;
    case 'prices':   require __DIR__ . '/views/prices.php'; break;
    case 'policies': require __DIR__ . '/views/policies.php'; break;
    default:         require __DIR__ . '/views/catalog.php'; break;
}

studio_pub_busy_js();
slate_portal_shell_close();
