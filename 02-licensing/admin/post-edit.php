<?php
/**
 * admin/post-edit.php — compatibility shim
 *
 * The visual page editor lives at admin/editor.php. This file redirects
 * any old bookmark or link that still points to /admin/post-edit.php so
 * nothing breaks.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();

$id   = isset($_GET['id'])   ? (int)$_GET['id']   : 0;
$type = isset($_GET['type']) ? (string)$_GET['type'] : '';

$qs = $id > 0 ? '?id=' . $id : ($type !== '' ? '?type=' . urlencode($type) : '');

header('Location: ' . SLATE_URL . '/admin/editor.php' . $qs, true, 302);
exit;
