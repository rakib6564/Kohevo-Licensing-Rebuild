<?php
/**
 * Kohevo Studio — Shared Admin Navigation & SVG Icon Library.
 *
 * Provides:
 * - `sb_svg(string $name, int $size = 18)`: Zero-dependency SVG stroke outline icon renderer.
 * - pagination, breadcrumbs and flash helpers shared by the screens.
 *
 * The Studio screens are listed once, in the platform sidebar (`StudioBuilder::navItems()`); their styles are
 * `assets/admin/studio-admin.css`, linked in the admin <head> (`StudioBuilder::adminHead()`).
 */

declare(strict_types=1);

require_once __DIR__ . '/_media_picker.php';

if (!function_exists('sb_svg')) {
    /**
     * Render a clean SVG stroke outline icon.
     */
    function sb_svg(string $name, int $size = 18, string $class = ''): string
    {
        $icons = [
            'overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'pages' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'content' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><line x1="12" y1="13" x2="12" y2="22"/>',
            'media' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'templates' => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
            'types-fields' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
            'tag' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
            'themes' => '<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.6-.7 1.6-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7h2c3 0 5.5-2.5 5.5-5.5C22 6 17.5 2 12 2z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="11.5" r="1"/>',
            'seo' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'menus' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'global-settings' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'ai-mcp' => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
            'reviews' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'code-tracking' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            'redirects' => '<polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
            'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'external-link' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
            'grip' => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
            'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
            'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'check' => '<polyline points="20 6 9 17 4 12"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'layer' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
            'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'copy' => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
            'refresh' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
            'sparkles' => '<path d="M12 3v3m0 12v3M3 12h3m12 0h3m-2.6-6.4l-2.1 2.1m-6.6 6.6l-2.1 2.1m0-10.8l2.1 2.1m6.6 6.6l2.1 2.1"/>',
            'history' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
            'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'close' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'palette' => '<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.6-.7 1.6-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7h2c3 0 5.5-2.5 5.5-5.5C22 6 17.5 2 12 2z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="11.5" r="1"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        ];

        $path = $icons[$name] ?? $icons['overview'];
        $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';

        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' . $cls . ' aria-hidden="true">'
            . $path
            . '</svg>';
    }
}

if (!function_exists('sb_render_pagination')) {
    /**
     * A pager for an admin list: "Showing a-b of N", page links with gaps, and a rows-per-page choice. Links keep `$query`
     * (the list's filters); `p` and `per` are the pager's own. `$total` is the number of rows over ALL pages.
     *
     * @param array<string, scalar> $query
     * @param list<int>             $sizes  the rows-per-page choices
     */
    function sb_render_pagination(string $file, array $query, int $page, int $perPage, int $total, array $sizes = [25, 50, 100]): void
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        if ($total <= min($sizes)) {
            return; // everything fits on the smallest page: nothing to page through
        }
        $url = static fn(int $p, int $per) => e($file . '?' . http_build_query(array_merge($query, ['per' => $per, 'p' => $p])));
        $from = ($page - 1) * $perPage + 1;
        $to = min($total, $page * $perPage);

        $nums = array_values(array_unique(array_filter([1, 2, $page - 1, $page, $page + 1, $pages - 1, $pages], static fn(int $n): bool => $n >= 1 && $n <= $pages)));
        sort($nums);

        echo '<nav class="sb-pager" aria-label="' . e(__('studio_pagination', 'Pagination')) . '">';
        echo '<span>' . e(sprintf(__('studio_showing_range', 'Showing %1$d–%2$d of %3$d'), $from, $to, $total)) . '</span>';
        echo '<div class="sb-pager__pages">';
        echo '<a class="sb-pager__link' . ($page <= 1 ? ' is-disabled' : '') . '" href="' . $url(max(1, $page - 1), $perPage) . '" rel="prev" aria-label="' . e(__('studio_prev_page', 'Previous page')) . '">‹</a>';
        $prev = 0;
        foreach ($nums as $n) {
            if ($n - $prev > 1) {
                echo '<span class="sb-pager__gap" aria-hidden="true">…</span>';
            }
            echo '<a class="sb-pager__link' . ($n === $page ? ' is-current' : '') . '" href="' . $url($n, $perPage) . '"' . ($n === $page ? ' aria-current="page"' : '') . '>' . $n . '</a>';
            $prev = $n;
        }
        echo '<a class="sb-pager__link' . ($page >= $pages ? ' is-disabled' : '') . '" href="' . $url(min($pages, $page + 1), $perPage) . '" rel="next" aria-label="' . e(__('studio_next_page', 'Next page')) . '">›</a>';
        echo '</div>';
        echo '<div class="sb-pager__size"><span>' . e(__('studio_rows_per_page', 'Rows per page')) . '</span>';
        foreach ($sizes as $size) {
            echo '<a class="sb-pager__link' . ($size === $perPage ? ' is-current' : '') . '" href="' . $url(1, $size) . '">' . $size . '</a>';
        }
        echo '</div></nav>';
    }
}

if (!function_exists('sb_render_crumbs')) {
    /**
     * Breadcrumb trail for a Studio admin screen: [[label, url|null], ...]; the last item (url null) is the current screen.
     *
     * @param list<array{0: string, 1: ?string}> $trail
     */
    function sb_render_crumbs(array $trail): void
    {
        echo '<nav class="sb-crumbs" aria-label="' . e(__('studio_breadcrumb', 'Breadcrumb')) . '">';
        foreach ($trail as $i => [$label, $url]) {
            if ($i > 0) {
                echo '<span aria-hidden="true">/</span>';
            }
            echo $url !== null
                ? '<a href="' . e($url) . '">' . e($label) . '</a>'
                : '<span aria-current="page">' . e($label) . '</span>';
        }
        echo '</nav>';
    }
}

if (!function_exists('sb_flash_redirect')) {
    /**
     * POST/redirect/GET for a Studio admin screen: the message is kept in the session for the next page view and the
     * browser is sent there with a 303, so a refresh shows the result again instead of repeating the action. `$url` is
     * the screen itself with the query string that matters (a filter, a tab), or wherever the action leads. The short
     * body carries the message and a link, for a client that does not follow redirects.
     *
     * @param array{type: string, msg: string} $flash extra keys (links the message shows) are kept
     */
    function sb_flash_redirect(string $url, array $flash): never
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['sb_flash'] = $flash;
        }
        header('Location: ' . $url, true, 303);
        echo '<div class="alert alert-' . e((string) $flash['type']) . '">' . e((string) $flash['msg']) . '</div>'
            . '<p><a href="' . e($url) . '">' . e(__('studio_continue', 'Continue')) . '</a></p>';
        exit;
    }
}

if (!function_exists('sb_render_flash')) {
    /**
     * A screen's message, the same way everywhere: success / info / warning as they are, anything else as an error.
     * An error or warning is announced at once (`role="alert"`), the rest politely (`role="status"`).
     *
     * @param array{type: string, msg: string}|null $flash
     */
    function sb_render_flash(?array $flash): void
    {
        if ($flash === null || (string) ($flash['msg'] ?? '') === '') {
            return;
        }
        $type = in_array($flash['type'] ?? '', ['success', 'info', 'warning'], true) ? (string) $flash['type'] : 'error';
        $role = in_array($type, ['error', 'warning'], true) ? 'alert' : 'status';
        echo '<div class="alert alert-' . $type . ' sb-alert" role="' . $role . '">' . e((string) $flash['msg']) . '</div>';
    }
}

if (!function_exists('sb_take_flash')) {
    /**
     * The message `sb_flash_redirect()` left for this page view, once: it is removed as it is read.
     *
     * @return array{type: string, msg: string}|null
     */
    function sb_take_flash(): ?array
    {
        $flash = $_SESSION['sb_flash'] ?? null;
        unset($_SESSION['sb_flash']);
        return is_array($flash) && is_string($flash['type'] ?? null) && is_string($flash['msg'] ?? null) ? $flash : null;
    }
}

if (!function_exists('sb_studio_entitled')) {
    /**
     * Whether this site's licence includes Kohevo Studio. The application service checks this before every write it
     * makes; a screen that writes settings or rows itself checks it here first. `ModuleGuard::allows()` is the admin-route
     * form of the same `EntitlementService::canAccess()` answer (with the test-runner bypass every module guard has).
     */
    function sb_studio_entitled(): bool
    {
        return \Slate\Services\Licensing\ModuleGuard::allows('studio-builder');
    }
}

if (!function_exists('sb_log_error')) {
    /** Log a failure an admin is shown only a generic message for: where it happened, the exception class and its text. */
    function sb_log_error(string $where, \Throwable $e): void
    {
        error_log('[studio-builder] ' . $where . ': ' . $e::class . ': ' . $e->getMessage());
    }
}
