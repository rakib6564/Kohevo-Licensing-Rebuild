<?php
/**
 * Coaching — self-emitting stylesheets.
 *
 * Coaching's CSS was queued from Coaching::boot() via enqueueStyle(), which
 * PluginLoader only runs for an ACTIVE plugin. The pages themselves work fine
 * when reached directly, so on an install where coaching is installed but not
 * activated every admin page rendered with its markup intact and none of its
 * 694 lines of design attached — labels and values stacked as bare text.
 *
 * A page should carry its own styling rather than depend on boot-time
 * enqueueing it may never receive. This emits the link directly, is idempotent
 * per file, and coexists with the queue: if the plugin IS active and
 * PluginLoader already emitted the same href, the browser dedupes it.
 *
 * mtime cache-bust because these assets sit behind a 7-day CDN cache.
 */

declare(strict_types=1);

if (!function_exists('coaching_emit_css')) {
    function coaching_emit_css(string ...$files): void
    {
        static $done = [];
        foreach ($files as $file) {
            if (isset($done[$file])) { continue; }
            $done[$file] = true;

            $disk = dirname(__DIR__) . '/assets/css/' . $file;
            $ver  = @filemtime($disk);
            if ($ver === false) { continue; }   // asset missing — emit nothing

            echo '<link rel="stylesheet" href="'
               . e(plugin_url('coaching', 'assets/css/' . $file) . '?v=' . $ver)
               . '">' . "\n";
        }
    }
}
