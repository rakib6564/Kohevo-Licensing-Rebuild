<?php
/**
 * Booking+ — HSR-style redirect banner.
 *
 * When a client attempts to book a service that has a
 * `prereq_service_id` configured and they haven't completed it yet,
 * BookingPlus's gate attaches a `redirect_to` URL that lands them
 * here. This page explains the redirect and forwards them to the
 * required prior service's /book URL with a friendly CTA.
 *
 * URL: /plugins/booking/public/prereq.php?from=<id>&to=<id>
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('booking');
ModuleGuard::requirePublic('booking'); // Phase 11: active is not entitled (07 §2, §4)
require_once dirname(__DIR__) . '/BookingPlusAPI.php';

BookingPlusAPI::ensureSchema();

$fromId = (int)($_GET['from'] ?? 0);
$toId   = (int)($_GET['to']   ?? 0);
$tid    = current_tenant_id();

if ($fromId <= 0 || $toId <= 0) {
    http_response_code(400);
    bookingplus_prereq_page('Missing information',
        '<p>This page was reached without the right context. Please start again from the booking page.</p>',
        SLATE_URL . '/book', 'Back to booking');
    exit;
}

$fromSvc = Database::row(
    "SELECT id, name, slug FROM booking_services WHERE id = ? AND tenant_id = ?",
    [$fromId, $tid]
);
$toSvc = Database::row(
    "SELECT id, name, slug FROM booking_services WHERE id = ? AND tenant_id = ?",
    [$toId, $tid]
);

if (!$fromSvc || !$toSvc) {
    http_response_code(404);
    bookingplus_prereq_page('Service not found',
        '<p>One of the services in this link is no longer available. Please start again from the main booking page.</p>',
        SLATE_URL . '/book', 'Back to booking');
    exit;
}

$cfg     = BookingPlusAPI::getServiceConfig((int)$fromSvc['id']);
$customMsg = trim((string)($cfg['prereq_message'] ?? ''));

$title = 'Let\'s take one step back';
$intro = '<p style="font-size:15px;line-height:1.6;">'
       . 'The <strong>' . e($fromSvc['name']) . '</strong> session needs a bit of groundwork first.</p>';

$explanation = $customMsg !== ''
    ? '<p style="font-size:15px;line-height:1.6;color:#334155;">' . e($customMsg) . '</p>'
    : '<p style="font-size:15px;line-height:1.6;color:#334155;">Before I can guide a '
      . e($fromSvc['name']) . ' session, we\'ll need to have a short introductory call so I can prepare properly. '
      . 'Let\'s start with a <strong>' . e($toSvc['name']) . '</strong> — we\'ll take it from there.</p>';

$ctaUrl = SLATE_URL . '/book?service=' . (int)$toSvc['id'];

bookingplus_prereq_page(
    $title,
    $intro . $explanation,
    $ctaUrl,
    'Book a ' . $toSvc['name'] . ' →'
);


// ── Rendering helper (glass card, matches public/message.php style) ─
function bookingplus_prereq_page(string $title, string $bodyHtml, string $ctaUrl, string $ctaLabel): void {
    $siteName = Database::setting('site_name') ?: 'Booking';
    $accent   = (string) (Database::setting('brand_accent_color') ?: '#111111');
    $logoRel  = trim((string) Database::setting('brand_logo_path'));
    $logoUrl  = $logoRel !== '' ? SLATE_URL . '/' . ltrim($logoRel, '/') : '';
    ?>
<!doctype html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — <?= e($siteName) ?></title>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
    <style>
        :root { --bp-accent: <?= e($accent) ?>; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e8eff5 100%);
            color: #1a2332;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 20px;
        }
        .bp-topbrand { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; opacity: 0.85; }
        .bp-topbrand img { height: 28px; width: auto; }
        .bp-topbrand span { font-weight: 600; font-size: 15px; color: #334155; }
        .bp-card {
            width: 100%;
            max-width: 560px;
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 20px;
            padding: 36px 32px 32px;
            box-shadow: 0 8px 32px rgba(15,23,42,0.08), 0 2px 8px rgba(15,23,42,0.04);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .bp-icon {
            width: 56px; height: 56px; margin-bottom: 18px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--bp-accent), rgba(17,17,17,0.75));
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(17,17,17,0.22);
        }
        .bp-icon svg { width: 28px; height: 28px; color: #fff; }
        .bp-card h1 {
            font-size: 26px;
            margin: 0 0 14px;
            line-height: 1.25;
        }
        .bp-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px 22px;
            border-radius: 12px;
            background: var(--bp-accent);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            margin-top: 22px;
            box-shadow: 0 4px 14px rgba(37,99,235,0.3);
            transition: transform 0.05s, box-shadow 0.15s;
        }
        .bp-cta:hover { box-shadow: 0 6px 20px rgba(37,99,235,0.4); }
        .bp-cta:active { transform: translateY(1px); }
        .bp-ghost {
            display: inline-block;
            margin-top: 14px;
            margin-left: 12px;
            font-size: 14px;
            color: #64748b;
            text-decoration: none;
        }
        .bp-ghost:hover { color: #334155; text-decoration: underline; }
        @media (max-width: 500px) {
            .bp-card { padding: 28px 22px; border-radius: 16px; }
            .bp-card h1 { font-size: 22px; }
            .bp-ghost { display: block; margin-left: 0; margin-top: 12px; }
        }
    </style>
</head>
<body>
    <div class="bp-topbrand">
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php endif; ?>
        <span><?= e($siteName) ?></span>
    </div>
    <div class="bp-card">
        <div class="bp-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <path d="M8 12h8M12 8l4 4-4 4"/>
            </svg>
        </div>
        <h1><?= e($title) ?></h1>
        <?= $bodyHtml ?>
        <div>
            <a href="<?= e($ctaUrl) ?>" class="bp-cta"><?= e($ctaLabel) ?></a>
            <a href="<?= e(SLATE_URL) ?>" class="bp-ghost">Back to the site</a>
        </div>
    </div>
</body>
</html>
    <?php
}
