<?php
/**
 * Restaurant storefront router.
 *
 * Reached via the core PublicRouter (prefix "order"): the catch-all
 * .htaccess rule sends /order/* → public.php → PublicRouter::dispatch,
 * which requires this file with $_GET['_route_path'] set to the suffix.
 *
 *   /order            → index.php   (menu)
 *   /order/item?id=N  → item.php    (item + options)
 *   /order/cart       → cart.php
 *   /order/checkout   → checkout.php
 *   /order/confirm    → confirm.php
 *   /order/api        → api.php     (JSON cart ops; redirects as no-JS fallback)
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
ModuleGuard::requirePublic('restaurant');

$path = trim((string)($_GET['_route_path'] ?? ''), '/');
if (strpos($path, '..') !== false || strpos($path, '\\') !== false) {
    http_response_code(400); echo 'Bad request'; exit;
}
$top = $path === '' ? '' : explode('/', $path)[0];

require_once __DIR__ . '/includes/sf.php';

switch ($top) {
    case '':         require __DIR__ . '/index.php';    break;
    case 'item':     require __DIR__ . '/item.php';     break;
    case 'cart':     require __DIR__ . '/cart.php';     break;
    case 'checkout': require __DIR__ . '/checkout.php'; break;
    case 'confirm':  require __DIR__ . '/confirm.php';  break;
    case 'api':      require __DIR__ . '/api.php';      break;
    default:
        http_response_code(404);
        sf_header('Not found');
        echo '<h1 class="sf-h1">Page not found</h1><p class="sf-sub"><a href="' . e(sf_url()) . '">Back to the menu →</a></p>';
        sf_footer();
}
