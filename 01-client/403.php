<?php
// Load the app so the error page can show your branding (logo, accent, hero).
try { require_once __DIR__ . '/config.php'; } catch (\Throwable $e) {}
require_once __DIR__ . '/includes/error_page.php';
slate_render_error(403, 'Access denied', 'You don\'t have permission to view this page.');
