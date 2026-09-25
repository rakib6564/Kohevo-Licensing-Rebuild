<?php
/**
 * Slate — public landing page (app root).
 *
 * The "choose your vessel" entry point. All content is driven by the Landing
 * settings tab; the renderer is shared with /forms/ so both URLs show the same
 * page. See includes/landing.php.
 */
require_once __DIR__ . '/config.php';

// Maintenance mode: show the branded 503 page to public visitors (admins pass).
require_once __DIR__ . '/includes/error_page.php';
slate_maintenance_gate();

// Remote license gate: show the branded "license inactive" page to public
// visitors when this install's remote license status is restricted
// (admins pass, same as maintenance mode above). A no-op for every
// install that has never configured remote licensing.
slate_license_gate();

require_once __DIR__ . '/includes/landing.php';

slate_render_landing();
