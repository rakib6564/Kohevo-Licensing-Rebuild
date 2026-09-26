<?php
/**
 * Booking — printable invoice. Accessible to staff (?id=) or to the customer
 * via their self-service token (?token=). Renders a clean standalone page.
 *
 * Branded + print-optimized: pulls the same Settings → Branding values
 * (logo, accent colour, business address/phone) used across the rest of
 * Slate, and its print stylesheet targets a real one-page paper layout
 * (@page size/margins, print-color-adjust so the accent survives printing,
 * no orphaned totals rows) rather than just hiding the on-screen chrome.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
BookingAPI::ensureSchema();
ModuleGuard::require('booking');

$tid = current_tenant_id();
$id    = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');

if ($token !== '') {
    $appt = BookingAPI::findByManageToken($token);
} else {
    Auth::require();
    Auth::requirePerm('booking.view');
    $appt = Database::row(
        "SELECT a.*, s.name AS service_name, s.currency, p.name AS provider_name
           FROM booking_appointments a
           JOIN booking_services  s ON s.id = a.service_id
           JOIN booking_providers p ON p.id = a.provider_id
          WHERE a.id = ? AND a.tenant_id = ?",
        [$id, $tid]
    );
}

if (!$appt) { http_response_code(404); echo __('booking_invoice_not_found', 'Invoice not found.'); exit; }

// Assign an invoice number on first view.
$invoiceNo = (string)($appt['invoice_no'] ?? '');
if ($invoiceNo === '') {
    $invoiceNo = 'INV-' . date('Y', strtotime($appt['created_at'])) . '-' . str_pad((string)$appt['id'], 5, '0', STR_PAD_LEFT);
    try { Database::update('booking_appointments', ['invoice_no' => $invoiceNo], 'id = ?', [(int)$appt['id']]); }
    catch (\Throwable $e) { /* column guaranteed by migration */ }
}

$cur      = $appt['currency'] ?: 'USD';
$money    = fn($cents) => $cur . ' ' . number_format(((int)$cents) / 100, 2);
$addons   = json_decode((string)($appt['addons_json'] ?? '[]'), true) ?: [];
$party    = max(1, (int)($appt['party_size'] ?? 1));

// ── Brand (same settings the rest of Slate reads — Settings → Branding) ──
$bizName   = (string)(Database::setting('business_name') ?: Database::setting('site_name') ?: 'Kohevo');
$bizEmail  = (string)(Database::setting('business_email') ?: '');
$bizPhone  = (string)(Database::setting('business_phone') ?: '');
$bizAddr   = (string)(Database::setting('business_address') ?: '');
$logoPath  = trim((string)(Database::setting('brand_logo_path') ?: ''));
$base      = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') : '';
$logoUrl   = $logoPath !== '' ? (preg_match('#^https?://#i', $logoPath) ? $logoPath : $base . '/' . ltrim($logoPath, '/')) : '';

// Sanitize + shade the accent colour (same small hex helpers used across the
// codebase's other branded surfaces — kept local since this is a standalone
// print page, not an email).
$sanitizeHex = function (string $s): string {
    $s = trim($s);
    return preg_match('/^#?([0-9a-f]{6})$/i', $s, $m) ? '#' . strtolower($m[1]) : '';
};
$hexToRgb = function (string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
};
$shadeHex = function (string $hex, float $ratio) use ($hexToRgb): string {
    [$r, $g, $b] = $hexToRgb($hex);
    $adj = function (int $c) use ($ratio): int {
        $c = $ratio < 0 ? $c * (1 + $ratio) : $c + (255 - $c) * $ratio;
        return max(0, min(255, (int)round($c)));
    };
    return sprintf('#%02x%02x%02x', $adj($r), $adj($g), $adj($b));
};
$accent     = $sanitizeHex((string)(Database::setting('brand_accent_color') ?: '')) ?: '#2563EB';
$accentDeep = $shadeHex($accent, -0.22);
$accentSoft = $shadeHex($accent, 0.92);

$subtotal = (int)$appt['price_cents'] + (int)$appt['discount_cents']; // taxable + discount = subtotal
$discount = (int)$appt['discount_cents'];
$tax      = (int)$appt['tax_cents'];
$total    = (int)$appt['price_cents'] + $tax;
$paid     = (int)$appt['paid_cents'] + (int)($appt['gift_applied_cents'] ?? 0);
$balance  = max(0, $total - $paid);

// Status pill tone — semantic colour rather than a fixed brand tint, so
// "paid" reads unmistakably differently from "refunded" at a glance.
$payStatus = (string)($appt['payment_status'] ?? 'none');
$statusTone = match ($payStatus) {
    'paid'                => ['bg' => '#ECFDF5', 'fg' => '#065F46'],
    'deposit_paid'        => ['bg' => '#EFF6FF', 'fg' => '#1E40AF'],
    'pending'             => ['bg' => '#FFFBEB', 'fg' => '#92400E'],
    'refunded', 'partially_refunded' => ['bg' => '#FEF2F2', 'fg' => '#991B1B'],
    default               => ['bg' => '#F1F5F9', 'fg' => '#475569'],
};
$statusLabel = ucwords(str_replace('_', ' ', $payStatus));
?>
<!DOCTYPE html><html lang="<?= e(I18n::currentLocale()) ?>"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(__('booking_invoice_title', 'Invoice')) ?> <?= e($invoiceNo) ?> — <?= e($bizName) ?></title>
<style>
  :root {
    --accent: <?= e($accent) ?>;
    --accent-deep: <?= e($accentDeep) ?>;
    --accent-soft: <?= e($accentSoft) ?>;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #16181D;
    background: #EEF0F3;
    padding: 40px 16px;
  }
  .sheet {
    max-width: 760px;
    margin: 0 auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(15,17,23,0.04), 0 18px 50px rgba(15,17,23,0.10);
    overflow: hidden;
  }
  .sheet-body { padding: 40px 44px 36px; }

  .toolbar-row { display: flex; justify-content: flex-end; margin-bottom: 22px; }
  .btn-print {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px; background: var(--accent); color: #fff;
    border-radius: 9px; text-decoration: none; font-size: 13.5px; font-weight: 600;
    border: 0; cursor: pointer;
  }
  .btn-print:hover { background: var(--accent-deep); }

  .inv-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 30px; }
  .brand-mark { display: flex; align-items: center; gap: 10px; }
  .brand-mark img { max-height: 40px; max-width: 180px; display: block; }
  .brand-mark .name { font-size: 17px; font-weight: 700; letter-spacing: -0.01em; color: #16181D; }
  .biz-lines { margin-top: 8px; font-size: 12px; color: #71757E; line-height: 1.6; }

  .inv-title-block { text-align: right; }
  .inv-title { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.02em; color: #16181D; }
  .inv-meta { margin-top: 6px; font-size: 12.5px; color: #71757E; line-height: 1.6; }
  .inv-meta strong { color: #3F4450; }

  .divider { height: 1px; background: #ECEDEF; margin: 0 0 22px; }

  .parties { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
  .party-label { font-size: 10.5px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #A2A6AE; margin-bottom: 6px; }
  .party-name { font-size: 14.5px; font-weight: 700; color: #16181D; }
  .party-sub { font-size: 12.5px; color: #71757E; margin-top: 2px; }
  .party-right { text-align: right; }

  .pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
    background: <?= e($statusTone['bg']) ?>; color: <?= e($statusTone['fg']) ?>;
  }

  table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 13.5px; }
  table.items thead th {
    text-align: left; padding: 10px 4px; font-size: 10.5px; font-weight: 700;
    letter-spacing: 0.08em; text-transform: uppercase; color: #A2A6AE;
    border-bottom: 2px solid var(--accent-soft);
  }
  table.items th.num, table.items td.num { text-align: right; }
  table.items td { padding: 12px 4px; border-bottom: 1px solid #F1F2F4; vertical-align: top; }
  table.items tbody tr:nth-child(odd) td { background: #FAFAFB; }
  .item-name { font-weight: 600; color: #16181D; }
  .item-sub { color: #A2A6AE; font-weight: 400; }

  .totals-wrap { display: flex; justify-content: flex-end; margin-top: 14px; }
  .totals { width: 300px; font-size: 13.5px; }
  .totals tr td { padding: 6px 2px; }
  .totals tr td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .totals .muted-row td { color: #71757E; }
  .totals .grand td { font-size: 16px; font-weight: 800; color: #16181D; border-top: 2px solid #16181D; padding-top: 10px; }
  .totals .balance td { font-weight: 700; color: var(--accent-deep); }

  .footnote { margin-top: 34px; padding-top: 18px; border-top: 1px solid #ECEDEF; font-size: 12px; color: #A2A6AE; }
  .footnote strong { color: #71757E; }

  @media (max-width: 560px) {
    .sheet-body { padding: 26px 20px; }
    .inv-head { flex-direction: column; }
    .inv-title-block { text-align: left; }
    .parties { flex-direction: column; gap: 14px; }
    .party-right { text-align: left; }
    .totals { width: 100%; }
  }

  /* ── Print ──────────────────────────────────────────────────────── */
  @page { size: A4; margin: 14mm 16mm; }
  @media print {
    html, body { background: #fff; padding: 0; }
    .no-print { display: none !important; }
    .sheet { max-width: none; margin: 0; border-radius: 0; box-shadow: none; }
    .sheet-body { padding: 0; }
    table.items tbody tr { break-inside: avoid; }
    .totals-wrap { break-inside: avoid; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head><body>
<div class="sheet">
  <div class="sheet-body">
    <div class="toolbar-row no-print">
      <button type="button" class="btn-print" onclick="window.print()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        <?= e(__('booking_invoice_print_save_pdf', 'Print / Save PDF')) ?>
      </button>
    </div>

    <div class="inv-head">
      <div>
        <div class="brand-mark">
          <?php if ($logoUrl !== ''): ?>
            <img src="<?= e($logoUrl) ?>" alt="<?= e($bizName) ?>">
          <?php else: ?>
            <span class="name"><?= e($bizName) ?></span>
          <?php endif; ?>
        </div>
        <div class="biz-lines">
          <?php if ($bizEmail): ?><div><?= e($bizEmail) ?></div><?php endif; ?>
          <?php if ($bizPhone): ?><div><?= e($bizPhone) ?></div><?php endif; ?>
          <?php if ($bizAddr): ?><div><?= nl2br(e($bizAddr)) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="inv-title-block">
        <h1 class="inv-title"><?= e(__('booking_invoice_title', 'Invoice')) ?></h1>
        <div class="inv-meta">
          <div><strong><?= e($invoiceNo) ?></strong></div>
          <div><?= e(I18n::localDate('j F Y', strtotime($appt['created_at']))) ?></div>
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <div class="parties">
      <div>
        <div class="party-label"><?= e(__('booking_invoice_billed_to', 'Billed to')) ?></div>
        <div class="party-name"><?= e($appt['customer_name']) ?></div>
        <div class="party-sub"><?= e($appt['customer_email']) ?></div>
      </div>
      <div class="party-right">
        <div class="party-label"><?= e(__('booking_invoice_appointment_label', 'Appointment')) ?></div>
        <div class="party-name"><?= e(slate_format_datetime($appt['starts_at'], 'l, j F Y')) ?></div>
        <div class="party-sub">
          <?= e(__('booking_invoice_ref_label', 'Ref')) ?> <?= e($appt['ref']) ?>
          &nbsp;·&nbsp;
          <span class="pill"><?= e($statusLabel) ?></span>
        </div>
      </div>
    </div>

    <table class="items">
      <thead><tr><th><?= e(__('booking_invoice_item', 'Item')) ?></th><th class="num"><?= e(__('booking_invoice_qty', 'Qty')) ?></th><th class="num"><?= e(__('booking_invoice_amount', 'Amount')) ?></th></tr></thead>
      <tbody>
        <tr>
          <td>
            <span class="item-name"><?= e($appt['service_name']) ?></span>
            <span class="item-sub"> <?= e(__('booking_invoice_with', 'with')) ?> <?= e($appt['provider_name']) ?></span>
          </td>
          <td class="num"><?= $party ?></td>
          <td class="num"><?= e($money((int)$appt['price_cents'] + $discount - array_sum(array_map(fn($a)=>(int)($a['price_cents'] ?? 0), $addons)))) ?></td>
        </tr>
        <?php foreach ($addons as $a): ?>
          <tr>
            <td><span class="item-sub">+ <?= e($a['name'] ?? __('booking_invoice_addon_default', 'Add-on')) ?></span></td>
            <td class="num">1</td>
            <td class="num"><?= e($money((int)($a['price_cents'] ?? 0))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals-wrap">
      <table class="totals">
        <tr class="muted-row"><td><?= e(__('booking_invoice_subtotal', 'Subtotal')) ?></td><td class="num"><?= e($money($subtotal)) ?></td></tr>
        <?php if ($discount > 0): ?><tr class="muted-row"><td><?= e(__('booking_invoice_discount', 'Discount')) ?><?= !empty($appt['coupon_code']) ? ' (' . e($appt['coupon_code']) . ')' : '' ?></td><td class="num">&minus; <?= e($money($discount)) ?></td></tr><?php endif; ?>
        <?php if ($tax > 0): ?><tr class="muted-row"><td><?= e(__('booking_invoice_tax', 'Tax')) ?></td><td class="num"><?= e($money($tax)) ?></td></tr><?php endif; ?>
        <tr class="grand"><td><?= e(__('booking_invoice_total', 'Total')) ?></td><td class="num"><?= e($money($total)) ?></td></tr>
        <?php if ((int)($appt['gift_applied_cents'] ?? 0) > 0): ?><tr class="muted-row"><td><?= e(__('booking_gift_card', 'Gift card')) ?></td><td class="num">&minus; <?= e($money((int)$appt['gift_applied_cents'])) ?></td></tr><?php endif; ?>
        <?php if ((int)$appt['paid_cents'] > 0): ?><tr class="muted-row"><td><?= e(__('booking_invoice_paid', 'Paid')) ?></td><td class="num">&minus; <?= e($money((int)$appt['paid_cents'])) ?></td></tr><?php endif; ?>
        <tr class="balance"><td><?= e(__('booking_invoice_balance_due', 'Balance due')) ?></td><td class="num"><?= e($money($balance)) ?></td></tr>
      </table>
    </div>

    <div class="footnote">
      <strong><?= e(__('booking_invoice_thank_you', 'Thank you for your business.')) ?></strong>
      <?php if ($bizEmail): ?> <?= e(sprintf(__('booking_invoice_questions', 'Questions about this invoice? Reach us at %s.'), $bizEmail)) ?><?php endif; ?>
    </div>
  </div>
</div>
</body></html>
