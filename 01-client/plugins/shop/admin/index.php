<?php
/**
 * Shop — the admin landing page is the orders list.
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('shop.view');
ModuleGuard::require('shop');

header('Location: ' . plugin_url('shop', 'admin/orders.php'));
exit;
