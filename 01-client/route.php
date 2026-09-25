<?php
/**
 * Slate — public route entrypoint.
 *
 * Apache rewrites /<prefix>/<rest> to this file with _route and _path
 * query params set. We just delegate to PublicRouter in includes/.
 *
 * This file exists as a thin trampoline so the Apache rewrite target
 * isn't inside the /includes/ directory (which is 403'd at the
 * RedirectMatch layer). The real logic lives in includes/PublicRouter.php.
 */
require_once __DIR__ . '/includes/PublicRouter.php';
