<?php
/**
 * Restaurant storefront — shared helpers + layout.
 *
 * Included by every storefront page (via router.php). config.php is already
 * loaded by the public entrypoint. Provides a session cart, money/escaping
 * helpers, and a branded header/footer. CSRF reuses the core token.
 *
 * Design: warm "smokehouse" theme (dark smoke nav/hero, ember accent on a
 * cream canvas, Playfair Display + DM Sans). The header brand mark is driven
 * dynamically from the core `brand_logo_path` setting, falling back to an
 * initial-letter tile when no logo is configured.
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
require_once SLATE_ROOT . '/plugins/restaurant/RestaurantAPI.php';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Base URL of the storefront, e.g. https://site/order . */
function sf_base(): string { return rtrim(SLATE_URL, '/') . '/order'; }
function sf_url(string $path = ''): string { return sf_base() . ($path !== '' ? '/' . ltrim($path, '/') : ''); }

function sf_money(int $cents): string { return RestaurantAPI::money($cents); }

/** The session cart: a list of [item_id, qty, modifiers[], notes]. */
function sf_cart(): array { return $_SESSION['rorder_cart'] ?? []; }
function sf_cart_set(array $cart): void { $_SESSION['rorder_cart'] = array_values($cart); }
function sf_cart_clear(): void { unset($_SESSION['rorder_cart']); }
function sf_cart_count(): int {
    $n = 0; foreach (sf_cart() as $l) $n += max(1, (int)($l['qty'] ?? 1)); return $n;
}

/** Look up an active menu item by id from the current tenant. */
function sf_item(int $id): ?array {
    return Database::row(
        "SELECT * FROM restaurant_items WHERE id = ? AND tenant_id = ? AND is_active = 1",
        [$id, current_tenant_id()]) ?: null;
}

/** Per-unit price of a cart line (item + chosen modifier deltas), in cents. */
function sf_line_unit_cents(array $line): int {
    $item = sf_item((int)($line['item_id'] ?? 0));
    if (!$item) return 0;
    $cents = (int)$item['price_cents'];
    $modIds = array_map('intval', (array)($line['modifiers'] ?? []));
    if ($modIds) {
        $in = implode(',', array_fill(0, count($modIds), '?'));
        $rows = Database::rows(
            "SELECT price_delta_cents FROM restaurant_modifiers WHERE id IN ($in) AND tenant_id = ?",
            array_merge($modIds, [current_tenant_id()]));
        foreach ($rows as $r) $cents += (int)$r['price_delta_cents'];
    }
    return $cents;
}

/** Names of a line's chosen modifiers, for display. */
function sf_line_mod_names(array $line): array {
    $modIds = array_map('intval', (array)($line['modifiers'] ?? []));
    if (!$modIds) return [];
    $in = implode(',', array_fill(0, count($modIds), '?'));
    return array_column(Database::rows(
        "SELECT name FROM restaurant_modifiers WHERE id IN ($in) AND tenant_id = ?",
        array_merge($modIds, [current_tenant_id()])), 'name');
}

/** Cart subtotal in cents (display only; the order engine is authoritative). */
function sf_cart_subtotal(): int {
    $sum = 0;
    foreach (sf_cart() as $l) $sum += sf_line_unit_cents($l) * max(1, (int)($l['qty'] ?? 1));
    return $sum;
}

function sf_biz_name(): string {
    return (string) RestaurantAPI::setting('biz_name', '') ?: 'Online ordering';
}

/**
 * Inline line-icon (24×24, single stroke, currentColor). No emoji anywhere in
 * the storefront — every glyph comes from here so it renders identically on
 * every device.
 */
function sf_icon(string $name, int $size = 18): string {
    static $paths = [
        'bag'      => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'utensils' => '<path d="M3 2v7a2 2 0 0 0 4 0V2"/><path d="M5 9v13"/><path d="M19 2c-1.7 0-3 2-3 4.5S17.3 11 19 11h.5V2Z"/><path d="M19 11v11"/>',
        'pin'      => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'alert'    => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'x'        => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'arrow'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'note'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg class="sf-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $body . '</svg>';
}

/** SVG icon strings for client-side use (toast). Keyed the same as sf_icon(). */
function sf_icons_js(): string {
    $mk = fn($n) => sf_icon($n, 16);
    return json_encode(['check' => $mk('check'), 'alert' => $mk('alert')]);
}

/**
 * Brand identity for the header, resolved dynamically:
 *   logo_url  — absolute URL to the configured brand logo, or '' if none
 *   name      — business name (restaurant setting, then core site name)
 *   sub       — short uppercase sublabel under the name (or '')
 *   initial   — first letter of the name, for the fallback tile
 */
function sf_brand(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $name = sf_biz_name();

    // Resolve the brand logo dynamically. Prefer the core Branding setting,
    // then fall back to the content-builder site logo (both store a path under
    // /uploads/branding). An empty value means "use the initial tile".
    $logoPath = trim((string) Database::setting('brand_logo_path'));
    if ($logoPath === '') $logoPath = trim((string) Database::setting('content-builder.logo_url'));

    $logoUrl = '';
    if ($logoPath !== '') {
        if (preg_match('#^https?://#i', $logoPath)) {
            $logoUrl = $logoPath;                                  // already absolute
        } else {
            // App-relative (with or without a leading slash) — mirror the core
            // convention: strip the leading slash and hang it off SLATE_URL.
            $logoUrl = rtrim(SLATE_URL, '/') . '/' . ltrim($logoPath, '/');
        }
    }

    $sub = trim((string) Database::setting('brand_sublabel'));
    if ($sub === '') {
        // Fall back to the city/state line so the lockup still reads as a place.
        $s = RestaurantAPI::settings();
        $sub = trim(implode(', ', array_filter([$s['addr_city'] ?? '', $s['addr_state'] ?? ''])));
    }

    return $cache = [
        'logo_url' => $logoUrl,
        'name'     => $name,
        'sub'      => $sub,
        'initial'  => mb_strtoupper(mb_substr($name, 0, 1)) ?: 'A',
    ];
}

/** Render the branded nav brand lockup (logo or initial tile + name + sub). */
function sf_brand_lockup(): string {
    $b = sf_brand();
    $mark = $b['logo_url'] !== ''
        ? '<img class="sf-logo-img" src="' . e($b['logo_url']) . '" alt="' . e($b['name']) . '">'
        : '<span class="sf-logo-tile" aria-hidden="true">' . e($b['initial']) . '</span>';
    $sub = $b['sub'] !== '' ? '<span class="sf-brand-sub">' . e($b['sub']) . '</span>' : '';
    return '<a class="sf-brand" href="' . e(sf_url()) . '">'
         . $mark
         . '<span class="sf-brand-txt"><span class="sf-brand-name">' . e($b['name']) . '</span>' . $sub . '</span>'
         . '</a>';
}

function sf_header(string $title = '', bool $bare = false): void {
    $biz = sf_biz_name();
    $full = $title !== '' ? "$title · $biz" : $biz;
    $count = sf_cart_count();
    $subtotal = sf_cart_subtotal();
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($full) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<style>
:root{
  --smoke:#1A1109;--ember:#C0380A;--ember-d:#8B2207;--ember-l:#E8501A;
  --cream:#FAF6EF;--tan:#F0E8D8;--tan2:#E4D5BE;--tan3:#D4C4AB;
  --wood:#7A4F2E;--muted:#8C7B68;--ink:#1A1109;--white:#fff;
  --r-sm:10px;--r-md:14px;--r-lg:18px;--r-xl:24px;--r-pill:50px;
}
*,*::before,*::after{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;font-family:'DM Sans',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--cream);line-height:1.5;overflow-x:hidden}
a{color:var(--ember-d)}
img{max-width:100%;display:block}

/* ── NAV (branded, dynamic logo) — light theme ── */
.sf-top{background:var(--white);border-bottom:1px solid var(--tan2);position:sticky;top:0;z-index:200;box-shadow:0 1px 3px rgba(26,17,9,.04)}
.sf-top-in{max-width:1100px;margin:0 auto;padding:0 20px;min-height:62px;display:flex;align-items:center;justify-content:space-between;gap:14px}
.sf-brand{display:flex;align-items:center;gap:11px;text-decoration:none;min-width:0}
.sf-logo-img{height:38px;width:auto;max-width:170px;object-fit:contain;border-radius:8px}
.sf-logo-tile{width:38px;height:38px;flex-shrink:0;background:var(--ember);border-radius:9px;display:flex;align-items:center;justify-content:center;font-family:'Playfair Display',serif;color:#fff;font-size:19px;font-weight:900;line-height:1}
.sf-brand-txt{display:flex;flex-direction:column;min-width:0}
.sf-brand-name{font-family:'Playfair Display',serif;color:var(--ink);font-size:16px;font-weight:700;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sf-brand-sub{font-size:10px;color:var(--muted);font-weight:600;letter-spacing:.7px;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sf-ico{display:inline-block;vertical-align:middle;flex-shrink:0}
.sf-cart-link{display:inline-flex;align-items:center;gap:8px;background:var(--ember);color:#fff;text-decoration:none;border-radius:var(--r-pill);padding:8px 16px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:background .15s}
.sf-cart-link:hover{background:var(--ember-l)}
.sf-cart-link .sf-cart-total{opacity:.9;font-weight:500}
.sf-cart-badge{background:#fff;color:var(--ember);border-radius:var(--r-pill);min-width:19px;height:19px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;padding:0 5px;margin-left:1px}

/* ── HERO ── */
.sf-hero{background:var(--smoke);padding:38px 20px 36px;text-align:center}
.sf-hero-in{max-width:640px;margin:0 auto}
.sf-eyebrow{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:9px}
.sf-hero-title{font-family:'Playfair Display',serif;color:#fff;font-size:clamp(28px,5vw,44px);font-weight:900;line-height:1.08;margin:0 0 12px}
.sf-hero-title em{color:var(--ember-l);font-style:normal}
.sf-hero-addr{font-size:12.5px;color:rgba(255,255,255,.45);margin-bottom:16px}
.sf-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(192,56,10,.22);border:1px solid rgba(192,56,10,.45);border-radius:var(--r-pill);padding:6px 15px;color:#F5A07A;font-size:12px;font-weight:600}

/* ── LAYOUT ── */
.sf-wrap{max-width:1100px;margin:0 auto;padding:24px 20px 80px}
.sf-menu-col{max-width:880px;margin:0 auto}

/* checkout: form + sticky order summary */
.sf-checkout{display:grid;grid-template-columns:1fr 350px;gap:28px;align-items:start;max-width:1000px;margin:0 auto}
@media(max-width:820px){.sf-checkout{grid-template-columns:1fr;max-width:600px}}
.sf-card-title{font-family:'Playfair Display',serif;font-size:16px;font-weight:700;margin-bottom:13px;display:flex;align-items:center;gap:8px}
.sf-summary{position:sticky;top:78px}
@media(max-width:820px){.sf-summary{position:static}}
.sf-osum-row{display:flex;justify-content:space-between;gap:12px;align-items:baseline;padding:9px 0;border-bottom:1px solid var(--tan)}
.sf-osum-row:last-of-type{border-bottom:0}
.sf-osum-q{font-weight:700;color:var(--ember-d);margin-right:6px}
.sf-osum-nm{flex:1;min-width:0;font-size:13.5px;font-weight:600;line-height:1.35}
.sf-osum-mod{font-size:11.5px;color:var(--muted);font-weight:400}
.sf-osum-pr{font-size:13.5px;font-weight:700;white-space:nowrap;font-variant-numeric:tabular-nums}

/* headings / text used across single-column pages */
.sf-h1{font-family:'Playfair Display',serif;margin:6px 0 4px;font-size:1.7rem;font-weight:900}
.sf-sub{color:var(--muted);margin:0 0 18px}
.sf-muted{color:var(--muted)}
.sf-back{display:inline-block;margin:4px 0 14px;color:var(--muted);text-decoration:none;font-size:.9rem;font-weight:500}
.sf-back:hover{color:var(--ember)}

/* ── SECTION HEADERS ── */
.sf-sec{display:flex;align-items:center;gap:14px;margin:26px 0 12px}
.sf-sec:first-child{margin-top:6px}
.sf-sec h2{font-family:'Playfair Display',serif;font-size:21px;font-weight:700;color:var(--ink);white-space:nowrap;margin:0}
.sf-sec-line{flex:1;height:1.5px;background:linear-gradient(to right,var(--tan2),transparent)}
/* legacy single .sf-cat (used by other pages) */
.sf-cat{display:inline-block;margin:26px 0 10px;padding-bottom:3px;font-family:'Playfair Display',serif;font-size:21px;font-weight:700;border-bottom:2px solid var(--ember)}

/* ── CARD / ITEM ROWS ── */
.sf-card{background:#fff;border:1px solid var(--tan2);border-radius:var(--r-lg);padding:16px;margin-bottom:14px}
.sf-card.sf-flush{padding:6px 16px}
.sf-item{display:flex;gap:14px;justify-content:space-between;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--tan)}
.sf-item:last-child{border-bottom:0}
.sf-item .nm{font-weight:600;font-size:14.5px}
.sf-item .ds{color:var(--muted);font-size:.86rem;margin-top:3px;line-height:1.5}
.sf-item .pr{white-space:nowrap;font-weight:700;font-variant-numeric:tabular-nums}
.sf-item.is86{opacity:.45}
.sf-soldout-tag{font-size:10px;background:var(--tan);color:var(--muted);border-radius:var(--r-pill);padding:2px 9px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}

/* item add controls */
.sf-add-btn{background:var(--ember);border:none;border-radius:50%;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:20px;line-height:1;cursor:pointer;transition:background .15s,transform .1s;padding:0}
.sf-add-btn:hover{background:var(--ember-l)}
.sf-add-btn:active{transform:scale(.9)}
.sf-choose-btn{background:transparent;border:1.5px solid var(--ember);color:var(--ember);border-radius:var(--r-pill);padding:7px 15px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-block;transition:background .15s,color .15s}
.sf-choose-btn:hover{background:var(--ember);color:#fff}
.sf-item-actions{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0}
.sf-ci-info{flex:1;min-width:0}

/* ── BUTTONS ── */
.sf-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:var(--ember);color:#fff;border:0;border-radius:var(--r-pill);padding:11px 20px;font:inherit;font-weight:600;font-size:.95rem;cursor:pointer;text-decoration:none;transition:background .15s}
.sf-btn:hover{background:var(--ember-l)}
.sf-btn-ghost{background:transparent;color:var(--ink);border:1.5px solid var(--tan2)}
.sf-btn-ghost:hover{background:var(--tan);color:var(--ink);border-color:var(--tan3)}
.sf-btn-block{display:flex;width:100%}
.sf-btn[disabled]{opacity:.5;cursor:not-allowed}

/* ── FORM FIELDS ── */
.sf-field{margin-bottom:13px}
.sf-field:last-child{margin-bottom:0}
.sf-field label{display:block;font-weight:600;margin-bottom:5px;font-size:.9rem}
.sf-field input,.sf-field select,.sf-field textarea{width:100%;padding:11px 12px;border:1.5px solid var(--tan2);border-radius:var(--r-sm);font:inherit;background:#fff;color:var(--ink);transition:border-color .15s}
.sf-field input:focus,.sf-field select:focus,.sf-field textarea:focus{outline:none;border-color:var(--ember)}
.sf-row{display:flex;gap:10px;align-items:center}
.sf-qty{width:72px!important;display:inline-block}

/* totals */
.sf-totrow{display:flex;justify-content:space-between;padding:4px 0;font-size:.95rem}
.sf-totrow.grand{font-size:1.15rem;font-weight:800;border-top:1px solid var(--tan2);margin-top:8px;padding-top:10px}
.sf-totrow.grand span:last-child{color:var(--ember)}

/* flash */
.sf-flash{padding:11px 15px;border-radius:var(--r-sm);margin:14px 0;font-weight:600;font-size:.92rem}
.sf-flash-err{background:#fdecea;color:#9a2310;border:1px solid #f3c4ba}
.sf-flash-ok{background:#eef7ee;color:#1f7a33;border:1px solid #bfe3c2}
.sf-flash-info{background:#fbf3e6;color:#8a5a16;border:1px solid #ecd9b4}

/* modifier options (item page) */
.sf-mopt{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--tan);cursor:pointer}
.sf-mopt:last-child{border-bottom:0}
.sf-mopt input{width:17px;height:17px;accent-color:var(--ember);flex-shrink:0}
.sf-mopt .mp{margin-left:auto;color:var(--muted);font-variant-numeric:tabular-nums}

/* ── INLINE OPTION PANEL (menu, no redirect) ── */
.sf-opts{background:var(--cream);border:1.5px solid var(--ember);border-radius:var(--r-md);padding:14px;margin:-6px 0 14px;animation:sfSlide .18s ease}
.sf-opts[hidden]{display:none}
@keyframes sfSlide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.sf-opts-name{font-weight:600;font-size:14px;margin-bottom:11px}
.sf-opts-grp{margin-bottom:11px}
.sf-opts-glabel{font-size:11px;font-weight:700;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px}
.sf-opts-glabel .rq{color:var(--ember)}
.sf-opts-chips{display:flex;gap:8px;flex-wrap:wrap}
.sf-chip{position:relative;border:1.5px solid var(--tan2);border-radius:var(--r-pill);padding:6px 14px;font-size:13px;font-weight:500;color:var(--ink);cursor:pointer;background:#fff;transition:border-color .12s,background .12s;user-select:none}
.sf-chip:hover{border-color:var(--ember)}
.sf-chip.sel{border-color:var(--ember);background:rgba(192,56,10,.08);color:var(--ember-d)}
.sf-chip em{font-style:normal;font-size:11px;color:var(--muted);margin-left:4px}
.sf-chip.sel em{color:var(--ember)}
.sf-chip input{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
.sf-opts-instr{width:100%;border:1.5px solid var(--tan2);border-radius:var(--r-sm);padding:9px 12px;font:inherit;font-size:13px;color:var(--ink);background:#fff;resize:none;outline:none;margin:2px 0 11px;transition:border-color .15s}
.sf-opts-instr:focus{border-color:var(--ember)}
.sf-opts-instr::placeholder{color:var(--muted)}
.sf-opts-foot{display:flex;align-items:center;gap:10px}
.sf-opts-foot .sf-stepper{background:#fff;border:1px solid var(--tan2)}
.sf-opts-foot .sf-stepper button{width:30px;height:30px;font-size:16px}
.sf-opts-foot .sf-btn{flex:1;padding:10px 14px}
.sf-opts-cancel{background:none;border:0;color:var(--muted);font:inherit;font-size:12px;cursor:pointer;white-space:nowrap;padding:0 2px}
.sf-opts-cancel:hover{color:var(--ember)}

/* ── CART (drawer contents) ── */
.sf-cart-title{font-family:'Playfair Display',serif;font-size:18px;font-weight:700;display:flex;align-items:center;gap:8px}
.sf-cart-empty{text-align:center;padding:48px 14px;color:var(--muted)}
.sf-cart-empty .ico{opacity:.28;margin-bottom:10px;line-height:0}
.sf-cart-empty p{font-size:13.5px;line-height:1.5;margin:0}
.sf-ci{padding:14px 0;border-bottom:1px solid var(--tan)}
.sf-ci:last-of-type{border-bottom:0}
.sf-ci-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px}
.sf-ci-name{flex:1;min-width:0;font-size:14px;font-weight:600;line-height:1.35}
.sf-ci-price{font-size:14px;font-weight:700;white-space:nowrap;font-variant-numeric:tabular-nums}
.sf-ci-mod{font-size:12px;color:var(--muted);margin-top:3px}
.sf-ci-note{font-size:12px;color:var(--muted);font-style:italic;margin-top:3px}
.sf-ci-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px}
.sf-ci-actions{display:flex;align-items:center;gap:12px}
.sf-ci-unit{font-size:11.5px;color:var(--muted);font-variant-numeric:tabular-nums}
.sf-stepper{display:inline-flex;align-items:center;background:var(--tan);border-radius:var(--r-pill);overflow:hidden}
.sf-stepper button{background:none;border:0;width:28px;height:28px;cursor:pointer;font-size:15px;color:var(--ink);display:flex;align-items:center;justify-content:center;padding:0;transition:background .1s}
.sf-stepper button:hover{background:var(--tan2)}
.sf-stepper span{font-size:13px;font-weight:600;min-width:22px;text-align:center}
.sf-ci-rm{background:none;border:0;cursor:pointer;color:var(--muted);font-size:13px;padding:2px;line-height:0;transition:color .12s;display:flex}
.sf-ci-rm:hover{color:var(--ember)}
.sf-cart-div{height:1px;background:var(--tan2);margin:6px 0 10px}
.sf-cart-foot{position:sticky;bottom:0;margin-top:auto;background:var(--cream);padding-bottom:4px}
.sf-cart-foot .sub{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px}
.sf-cart-foot .sub .v{font-size:17px;font-weight:700}
.sf-cart-foot .tax{font-size:11.5px;color:var(--muted);margin-bottom:13px}
.sf-clear{background:none;border:0;width:100%;text-align:center;font-size:12px;color:var(--muted);cursor:pointer;padding:9px 0 2px;transition:color .12s}
.sf-clear:hover{color:var(--ember)}

/* mobile floating cart trigger */
.sf-mob-cart{display:none;position:fixed;bottom:18px;right:18px;z-index:300;background:var(--ember);color:#fff;border:0;border-radius:var(--r-pill);padding:13px 20px;font:inherit;font-size:14px;font-weight:600;cursor:pointer;align-items:center;gap:9px;box-shadow:0 6px 20px rgba(192,56,10,.4)}
.sf-mob-cart:hover{background:var(--ember-l)}
.sf-mob-cart .b{background:#fff;color:var(--ember);border-radius:50%;min-width:21px;height:21px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;padding:0 5px}

/* overlay + right-hand slide-in drawer */
.sf-overlay{position:fixed;inset:0;background:rgba(26,17,9,.5);z-index:400;opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
.sf-overlay.open{opacity:1;visibility:visible}
.sf-drawer{position:fixed;top:0;right:0;bottom:0;width:min(420px,100%);z-index:500;background:var(--cream);transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:-12px 0 40px rgba(26,17,9,.22)}
.sf-drawer.open{transform:none}
.sf-drawer-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 20px;background:#fff;border-bottom:1px solid var(--tan2);flex-shrink:0}
.sf-drawer-body{flex:1;overflow-y:auto;padding:6px 20px 18px;display:flex;flex-direction:column}
.sf-drawer-body>div{flex:1;display:flex;flex-direction:column}
.sf-drawer-close{background:var(--tan);border:0;width:33px;height:33px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--ink);cursor:pointer;flex-shrink:0;transition:background .12s}
.sf-drawer-close:hover{background:var(--tan2)}

/* ── TOAST ── */
.sf-toast{position:fixed;bottom:84px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--smoke);color:#fff;border-radius:var(--r-pill);padding:10px 18px;font-size:13px;font-weight:600;opacity:0;transition:opacity .2s,transform .2s;z-index:600;pointer-events:none;max-width:90vw;display:inline-flex;align-items:center;gap:8px}
.sf-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.sf-toast .sf-ico{color:#7CD992}
.sf-toast.err .sf-ico{color:#FFB4A1}

/* in-place cart highlight when the nav cart is tapped on desktop */
.sf-pulse{animation:sfPulse .9s ease}
@keyframes sfPulse{0%{box-shadow:0 0 0 0 rgba(192,56,10,.45)}100%{box-shadow:0 0 0 12px rgba(192,56,10,0)}}

.sf-closed{text-align:center;padding:60px 16px}
.sf-foot{margin-top:34px;padding-top:18px;border-top:1px solid var(--tan2);font-size:.83rem;text-align:center;color:var(--muted);line-height:1.7}

@media(max-width:700px){
  .sf-mob-cart{display:inline-flex}
  .sf-wrap{padding-bottom:96px}
}
@media(max-width:520px){
  .sf-brand-name{font-size:15px}
  .sf-cart-link .sf-cart-total{display:none}
}
</style>
</head><body>
<div class="sf-top"><div class="sf-top-in">
    <?= sf_brand_lockup() ?>
    <a class="sf-cart-link" href="<?= e(sf_url('cart')) ?>" data-open-cart>
        <?= sf_icon('bag', 16) ?>
        <span>Cart</span>
        <span class="sf-cart-total" data-cart-total><?= sf_money($subtotal) ?></span>
        <span class="sf-cart-badge" data-cart-badge><?= $count ?></span>
    </a>
</div></div>
<?php
    // Single-column pages get the wrap opened here (closed by sf_footer).
    // The menu page passes $bare=true so it can render a full-width hero
    // before opening its own .sf-wrap.
    if (!$bare) echo '<div class="sf-wrap">';
}

/**
 * Dark hero band. Call right after sf_header() on the menu page.
 * Defaults pull from settings, but a page may override the headline.
 */
function sf_hero(?string $titleHtml = null): void {
    $b = sf_brand();
    $s = RestaurantAPI::settings();
    $eyebrow = trim(implode(', ', array_filter([$s['addr_city'] ?? '', $s['addr_state'] ?? ''])));
    $addr = trim(implode(', ', array_filter([
        $s['addr_line1'] ?? '', $s['addr_city'] ?? '', $s['addr_state'] ?? '', $s['addr_postal'] ?? '',
    ])));
    $title = $titleHtml !== null ? $titleHtml : e($b['name']);
    ?>
<div class="sf-hero"><div class="sf-hero-in">
    <?php if ($eyebrow !== ''): ?><div class="sf-eyebrow"><?= e($eyebrow) ?></div><?php endif; ?>
    <h1 class="sf-hero-title"><?= $title ?></h1>
    <?php if ($addr !== ''): ?><div class="sf-hero-addr"><?= e($addr) ?></div><?php endif; ?>
    <div class="sf-chip"><?= sf_icon('pin', 13) ?> Order online for pickup<?= RestaurantAPI::setting('online_delivery', '0') === '1' ? ' or delivery' : '' ?></div>
</div></div>
<?php
}

/**
 * The cart's swappable inner HTML (line items + footer, or the empty state).
 * The menu's JS replaces every [data-cart-body] with this after each API call,
 * so the sidebar and drawer stay live without a reload. The forms post to
 * /order/api (AJAX-intercepted; a plain submit still works and redirects).
 */
function sf_cart_body_html(): string {
    $cart = sf_cart();
    if (!$cart) {
        return '<div class="sf-cart-empty"><div class="ico">' . sf_icon('utensils', 34) . '</div><p>Your cart is empty.<br>Choose something delicious!</p></div>';
    }
    $api = e(sf_url('api'));
    $html = '';
    foreach ($cart as $i => $line) {
        $item = sf_item((int)$line['item_id']);
        if (!$item) continue;
        $unit = sf_line_unit_cents($line);
        $qty  = max(1, (int)$line['qty']);
        $mods = sf_line_mod_names($line);
        $html .= '<div class="sf-ci">';
        $html .= '<div class="sf-ci-head"><div class="sf-ci-name">' . e($item['name']) . '</div>'
              . '<div class="sf-ci-price">' . sf_money($unit * $qty) . '</div></div>';
        if ($mods) $html .= '<div class="sf-ci-mod">' . e(implode(', ', $mods)) . '</div>';
        if (trim((string)($line['notes'] ?? '')) !== '') $html .= '<div class="sf-ci-note">“' . e($line['notes']) . '”</div>';
        $html .= '<div class="sf-ci-foot"><div class="sf-stepper">'
              . '<form class="sf-ajax" method="post" action="' . $api . '" style="display:contents">' . csrf_field()
              . '<input type="hidden" name="_action" value="qty"><input type="hidden" name="i" value="' . (int)$i . '">'
              . '<button type="submit" name="qty" value="' . ($qty - 1) . '" aria-label="Decrease">−</button>'
              . '<span>' . $qty . '</span>'
              . '<button type="submit" name="qty" value="' . ($qty + 1) . '" aria-label="Increase">+</button>'
              . '</form></div>';
        $html .= '<div class="sf-ci-actions"><span class="sf-ci-unit">' . sf_money($unit) . ' ea</span>'
              . '<form class="sf-ajax" method="post" action="' . $api . '" style="margin:0">' . csrf_field()
              . '<input type="hidden" name="_action" value="remove"><input type="hidden" name="i" value="' . (int)$i . '">'
              . '<button class="sf-ci-rm" type="submit" aria-label="Remove">' . sf_icon('trash', 15) . '</button></form></div>';
        $html .= '</div></div>';
    }
    $sub = sf_cart_subtotal();
    $html .= '<div class="sf-cart-div"></div><div class="sf-cart-foot">';
    $html .= '<div class="sub"><span class="sf-muted">Subtotal</span><span class="v" data-cart-total>' . sf_money($sub) . '</span></div>';
    $html .= '<div class="tax">Tax' . (RestaurantAPI::serviceChargeAuto() ? ' & service charge' : '') . ' calculated at checkout</div>';
    $html .= '<a class="sf-btn sf-btn-block" href="' . e(sf_url('checkout')) . '">Checkout ' . sf_icon('arrow', 16) . '</a>';
    $html .= '<form class="sf-ajax" method="post" action="' . $api . '" style="margin:0">' . csrf_field()
          . '<input type="hidden" name="_action" value="clear">'
          . '<button class="sf-clear" type="submit">Clear order</button></form>';
    $html .= '</div>';
    return $html;
}

/** The swappable cart body (line items + footer, or empty state). */
function sf_render_cart(): void {
    echo '<div data-cart-body>' . sf_cart_body_html() . '</div>';
}

/** Floating trigger (mobile), right-hand slide-in cart drawer, toast + all JS. */
function sf_cart_drawer(): void {
    static $done = false;
    if ($done) return;     // emit once per page even if called explicitly + via footer
    $done = true;
    $count = sf_cart_count();
    ?>
<button class="sf-mob-cart" type="button" data-open-cart aria-label="View cart">
    <?= sf_icon('bag', 18) ?> <span data-cart-total><?= sf_money(sf_cart_subtotal()) ?></span>
    <span class="b" data-cart-badge><?= $count ?></span>
</button>
<div class="sf-overlay" id="sf-overlay" onclick="sfCloseDrawer()"></div>
<aside class="sf-drawer" id="sf-drawer" aria-label="Your order">
    <div class="sf-drawer-head">
        <div class="sf-cart-title"><?= sf_icon('bag', 18) ?> Your order</div>
        <button class="sf-drawer-close" type="button" onclick="sfCloseDrawer()" aria-label="Close cart"><?= sf_icon('x', 18) ?></button>
    </div>
    <div class="sf-drawer-body"><?php sf_render_cart(); ?></div>
</aside>
<div class="sf-toast" id="sf-toast"></div>
<script>
(function(){
  var API = <?= json_encode(sf_url('api')) ?>;
  var CUR = <?= json_encode(preg_replace('/[0-9.,\s]/', '', sf_money(100))) ?>;
  var ICONS = <?= sf_icons_js() ?>;
  function $(s, r){ return (r||document).querySelector(s); }
  function $all(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function money(cents){ return CUR + (cents/100).toFixed(2); }
  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  // ── drawer (referenced by inline onclick) ──
  window.sfOpenDrawer = function(){ $('#sf-drawer').classList.add('open'); $('#sf-overlay').classList.add('open'); document.body.style.overflow = 'hidden'; };
  window.sfCloseDrawer = function(){
    $('#sf-drawer').classList.remove('open'); $('#sf-overlay').classList.remove('open'); document.body.style.overflow = '';
    // if a page shows its own server-rendered order summary (checkout), keep it in sync
    if (window.__sfCartDirty && document.querySelector('[data-cart-summary]')){ location.reload(); }
  };

  // ── toast (SVG icon, no emoji) ──
  var tT;
  function toast(msg, isErr){
    var t = $('#sf-toast'); if(!t) return;
    t.classList.toggle('err', !!isErr);
    t.innerHTML = (isErr ? ICONS.alert : ICONS.check) + '<span>' + esc(msg) + '</span>';
    t.classList.add('show');
    clearTimeout(tT); tT = setTimeout(function(){ t.classList.remove('show'); }, 2300);
  }

  // ── "Cart" opens the slide-in drawer in place (never a separate page) ──
  function revealCart(){ window.sfOpenDrawer(); }

  // ── apply fresh cart state from the API everywhere ──
  function apply(state){
    if (typeof state.body === 'string')
      $all('[data-cart-body]').forEach(function(el){ el.innerHTML = state.body; });
    if (state.count != null)
      $all('[data-cart-badge]').forEach(function(el){ el.textContent = state.count; });
    if (state.subtotal != null)
      $all('[data-cart-total]').forEach(function(el){ el.textContent = state.subtotal; });
  }

  // ── inline option panels ──
  function recalc(panel){
    var base = parseInt(panel.dataset.base, 10) || 0, add = 0;
    $all('.sf-chip input:checked', panel).forEach(function(i){ add += parseInt(i.dataset.delta, 10) || 0; });
    var qty = parseInt($('[data-qty-input]', panel).value, 10) || 1;
    var t = $('[data-line-total]', panel);
    if (t) t.textContent = money((base + add) * qty);
  }
  function closeAllPanels(except){
    $all('.sf-opts').forEach(function(p){ if (p !== except) p.hidden = true; });
  }
  // toggle from a "Choose" control
  document.addEventListener('click', function(e){
    var oc = e.target.closest('[data-open-cart]');
    if (oc){ e.preventDefault(); revealCart(); return; }
    var tog = e.target.closest('[data-toggle-panel]');
    if (tog){
      e.preventDefault();
      var panel = document.getElementById(tog.getAttribute('data-toggle-panel'));
      if (!panel) return;
      var willOpen = panel.hidden;
      closeAllPanels(panel);
      panel.hidden = !willOpen;
      if (willOpen){ recalc(panel); panel.scrollIntoView({behavior:'smooth', block:'nearest'}); }
      return;
    }
    var cancel = e.target.closest('[data-cancel-panel]');
    if (cancel){ var p = cancel.closest('.sf-opts'); if (p) p.hidden = true; return; }
    var step = e.target.closest('[data-qstep]');
    if (step){
      var pnl = step.closest('.sf-opts'); var inp = $('[data-qty-input]', pnl);
      var v = Math.max(1, (parseInt(inp.value, 10) || 1) + parseInt(step.dataset.qstep, 10));
      inp.value = v; $('[data-qty]', pnl).textContent = v; recalc(pnl);
    }
  });
  // chip selection (single-select groups behave like radios)
  document.addEventListener('change', function(e){
    var inp = e.target.closest('.sf-chip input'); if (!inp) return;
    var panel = inp.closest('.sf-opts'); var grp = inp.closest('.sf-opts-grp');
    if (grp && parseInt(grp.dataset.max, 10) === 1 && inp.checked)
      $all('.sf-chip input', grp).forEach(function(o){ if (o !== inp) o.checked = false; });
    $all('.sf-chip', panel).forEach(function(c){ c.classList.toggle('sel', $('input', c).checked); });
    recalc(panel);
  });

  // ── one delegated submit handler for every cart-mutating form ──
  document.addEventListener('submit', function(e){
    var f = e.target.closest('form.sf-ajax'); if (!f) return;
    e.preventDefault();
    var panel = f.closest('.sf-opts');
    if (panel){
      var bad = null;
      $all('.sf-opts-grp', panel).forEach(function(g){
        if (bad) return;
        var picked = $all('.sf-chip input:checked', g).length;
        var min = parseInt(g.dataset.min, 10) || 0, max = parseInt(g.dataset.max, 10) || 0;
        if (g.dataset.required === '1' && picked < Math.max(1, min)) bad = 'Please choose: ' + g.dataset.name;
        else if (max > 0 && picked > max) bad = 'Choose at most ' + max + ' for: ' + g.dataset.name;
      });
      if (bad){ toast(bad, true); return; }
    }
    var fd = new FormData(f);
    if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
    var isAdd = fd.get('_action') === 'add';
    var btn = e.submitter || f.querySelector('[type=submit]');
    if (btn) btn.disabled = true;
    fetch(API, { method:'POST', body:fd, headers:{ 'X-Requested-With':'fetch' } })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (btn) btn.disabled = false;
        if (!j.ok){ toast(j.error || 'Something went wrong', true); return; }
        apply(j);
        window.__sfCartDirty = true;
        if (panel) panel.hidden = true;
        if (isAdd){ window.sfOpenDrawer(); }   // show the cart slide in on add
      })
      .catch(function(){ if (btn) btn.disabled = false; toast('Network error — please retry', true); });
  });

  // first paint may arrive with ?added= (no-JS add round-trip); show + clean it
  try {
    var p = new URLSearchParams(location.search);
    if (p.has('added')){ toast('Added: ' + p.get('added')); history.replaceState({}, '', location.pathname); }
  } catch(e){}
})();
</script>
<?php
}

function sf_footer(): void {
    $s = RestaurantAPI::settings();
    $addr = trim(implode(', ', array_filter([$s['addr_line1'], $s['addr_city'], $s['addr_state'], $s['addr_postal']])));
    ?>
    <div class="sf-foot"><?= e(sf_biz_name()) ?><?= $addr ? ' &nbsp;·&nbsp; ' . e($addr) : '' ?></div>
</div>
<?php sf_cart_drawer(); /* slide-in cart + interaction JS — on every storefront page */ ?>
</body></html>
<?php
}

/** Render a "closed / unavailable" page and exit. */
function sf_closed(string $msg): void {
    sf_header('Unavailable');
    echo '<div class="sf-closed"><h1 class="sf-h1">Online ordering is unavailable</h1><p class="sf-sub">' . e($msg) . '</p>'
       . '<a class="sf-btn sf-btn-ghost" href="' . e(sf_url()) . '">Reload</a></div>';
    sf_footer();
    exit;
}
