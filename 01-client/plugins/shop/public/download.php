<?php
/**
 * Shop — a buyer's download of a digital product's file.
 *
 *   GET ?t=<token>   the token comes from the order's confirmation page or email. Each request counts one download;
 *                    an unknown, used-up or expired link, or one of an order that is no longer paid, answers 404.
 *
 * Files live in data/shop-files/ (never served by the web server) and are streamed from here.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('shop');
require_once dirname(__DIR__) . '/ShopStoreAPI.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$file = ShopStoreAPI::claimDownload((string) ($_GET['t'] ?? ''));
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo __('shop_download_unavailable', 'This download link is not available any more.');
    exit;
}

$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']) ?: 'download';
header('Content-Type: application/octet-stream');
header('Content-Length: ' . $file['size']);
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
header('X-Content-Type-Options: nosniff');
while (ob_get_level() > 0) ob_end_clean();
readfile($file['abs']);
