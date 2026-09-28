<?php
/**
 * Media library — legacy plugin page (superseded).
 *
 * The media library is now a core Slate feature at /admin/media.php.
 * This page is kept only so old bookmarks/links keep working — it
 * redirects to the core page.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('media.view');

header('Location: ' . SLATE_URL . '/admin/media.php', true, 302);
echo '<!doctype html><meta charset="utf-8">'
   . '<title>' . e(__('media_library', 'Media library')) . '</title>'
   . '<p>' . e(__('media_library_moved', 'The media library has moved.')) . ' '
   . '<a href="' . e(SLATE_URL . '/admin/media.php') . '">' . e(__('media_open_library', 'Open the media library')) . '</a>.</p>';
exit;
