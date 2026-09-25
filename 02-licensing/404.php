<?php
// Load the app so the error page can show your branding (logo, accent, hero).
try { require_once __DIR__ . '/config.php'; } catch (\Throwable $e) {}
require_once __DIR__ . '/includes/error_page.php';
slate_render_error(404, 'Page not found', 'The page you were looking for doesn\'t exist or has moved.');
