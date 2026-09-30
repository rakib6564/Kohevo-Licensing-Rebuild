<?php
/**
 * Slate — UI design system.
 *
 * Dark-sidebar / light-canvas theme. Off-white canvas, white cards on
 * 1px hairline borders (no shadow), blue accent. DM Sans body, Syne
 * display headings, DM Mono code. Hand-crafted vanilla CSS — no build
 * step. Plugins should re-use these classes (.btn, .card, .field)
 * rather than inventing parallel styles.
 *
 * Design philosophy:
 *   - Light, neutral, professional. Closer to a Linear/Stripe-era
 *     admin than to the previous warm-cream Slate.
 *   - Hairline borders over shadows. Cards rest on 1px lines, not
 *     halo shadows. Crisp, modern, prints fine.
 *   - Blue accent reserved for primary actions and active states.
 *   - Native font stack with DM Sans + Syne + DM Mono via Google
 *     Fonts (loaded once from this file's CSS @import).
 *   - 44px minimum tap targets on mobile.
 *   - Real focus rings, accessible.
 *
 * The CSS block at the bottom is gated by SLATE_UI_COMPONENTS_EMITTED
 * so it only appears once per response. The helper functions
 * (slate_data_row, slate_data_list_script, slate_page_layout) are
 * defined unconditionally so callers can use them whether or not
 * the styles have been emitted.
 */

/**
 * Render one data-row inside a .data-list. See PLUGIN-API.md Section 16.
 *
 * $args keys:
 *   title         (string, required) — plain text, will be HTML-escaped
 *   title_html    (string, optional) — raw HTML, used instead of title when set.
 *                  Caller is responsible for escaping any user data inside.
 *   meta          (string, optional secondary line) — plain text, will be escaped
 *   meta_html     (string, optional) — raw HTML version of meta
 *   avatar        (string, 1-2 chars for the initial badge)
 *   avatar_color  (string, optional: accent|success|warning|danger|info|muted)
 *   value         (string, optional pill on the right of summary)
 *   badge         ([label, modifier] for the status badge — preferred over
 *                  putting badge HTML in title_html)
 *   detail        (array of label=>value pairs OR list of
 *                  ['label'=>..., 'value'=>..., 'muted'=>bool] OR
 *                  ['label'=>..., 'html'=>raw_html])
 *   actions       (raw HTML for the bottom action row)
 *   select        (optional ['name'=>'ids[]', 'value'=>'12', 'label'=>aria-label])
 *                  adds a row checkbox beside (not inside) the summary button;
 *                  the list should then use slate_data_list_head() for select-all.
 */
if (!function_exists('slate_data_row')) {
    function slate_data_row(array $args): void {
        $title       = (string)($args['title'] ?? '');
        $titleHtml   = isset($args['title_html']) ? (string)$args['title_html'] : null;
        $meta        = (string)($args['meta']  ?? '');
        $metaHtml    = isset($args['meta_html']) ? (string)$args['meta_html'] : null;
        $avatar      = (string)($args['avatar'] ?? '');
        $avatarHtml  = isset($args['avatar_html']) ? (string)$args['avatar_html'] : null; // raw SVG/icon
        $avatarColor = (string)($args['avatar_color'] ?? '');
        $value       = $args['value'] ?? null;
        $badge       = $args['badge'] ?? null; // [label, modifier]
        $detail      = (array)($args['detail'] ?? []);
        $actions     = $args['actions'] ?? null;
        $hasDetail   = !empty($detail) || !empty($actions);
        $select      = is_array($args['select'] ?? null) ? $args['select'] : null;

        $avatarClass = 'data-row-avatar';
        if ($avatarColor !== '') $avatarClass .= ' is-' . preg_replace('/[^a-z]/', '', strtolower($avatarColor));

        echo '<article class="data-row' . ($select ? ' has-select' : '') . '">';
        if ($select) {
            echo '<label class="data-row-check"><input type="checkbox" class="data-row-select" name="'
               . e((string)($select['name'] ?? 'ids[]')) . '" value="' . e((string)($select['value'] ?? ''))
               . '" aria-label="' . e((string)($select['label'] ?? '')) . '"></label>';
        }
        echo '<button type="button" class="data-row-summary" aria-expanded="false"'
           . ($hasDetail ? '' : ' disabled') . '>';

        if ($avatarHtml !== null && $avatarHtml !== '') {
            // Caller-supplied icon/photo markup (e.g. an inline SVG, or
            // initials + a photo <img> overlay from slate_avatar_overlay_html()).
            // Trusted: only ever passed curated HTML, never user input.
            // has-media (clips/covers a photo) vs has-icon (centers a small
            // SVG) based on which kind of markup was actually handed in.
            $avatarVariant = stripos($avatarHtml, '<img') !== false ? 'has-media' : 'has-icon';
            echo '<span class="' . $avatarClass . ' ' . $avatarVariant . '" aria-hidden="true">'
               . $avatarHtml . '</span>';
        } elseif ($avatar !== '') {
            echo '<span class="' . $avatarClass . '" aria-hidden="true">'
               . e(mb_substr($avatar, 0, 2)) . '</span>';
        }

        echo '<span class="data-row-main">';
        echo   '<span class="data-row-title">'
             . ($titleHtml !== null ? $titleHtml : e($title))
             . '</span>';
        if ($metaHtml !== null) {
            echo '<span class="data-row-meta">' . $metaHtml . '</span>';
        } elseif ($meta !== '') {
            echo '<span class="data-row-meta">' . e($meta) . '</span>';
        }
        echo '</span>';

        if ($value !== null && $value !== '') {
            echo '<span class="data-row-value">' . e((string)$value) . '</span>';
        }

        if (is_array($badge) && isset($badge[0])) {
            $mod = isset($badge[1]) ? preg_replace('/[^a-z0-9_-]/', '', (string)$badge[1]) : '';
            echo '<span class="badge' . ($mod !== '' ? ' badge-' . e($mod) : '') . '">'
               . e((string)$badge[0]) . '</span>';
        }

        if ($hasDetail) {
            echo '<svg class="data-row-chevron" viewBox="0 0 24 24" fill="none" '
               . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
               . 'stroke-linejoin="round" aria-hidden="true">'
               . '<polyline points="6 9 12 15 18 9"/></svg>';
        }
        echo '</button>';

        if ($hasDetail) {
            echo '<div class="data-row-detail" hidden>';
            if (!empty($detail)) {
                echo '<dl class="data-row-grid">';
                foreach ($detail as $k => $v) {
                    if (is_array($v)) {
                        $label = (string)($v['label'] ?? $k);
                        if (isset($v['html'])) {
                            echo '<div><dt>' . e($label) . '</dt><dd>' . $v['html'] . '</dd></div>';
                        } else {
                            $cls = !empty($v['muted']) ? ' class="is-muted"' : '';
                            echo '<div><dt>' . e($label) . '</dt><dd' . $cls . '>'
                               . e((string)($v['value'] ?? '')) . '</dd></div>';
                        }
                    } else {
                        echo '<div><dt>' . e((string)$k) . '</dt><dd>' . e((string)$v) . '</dd></div>';
                    }
                }
                echo '</dl>';
            }
            if (!empty($actions)) {
                echo '<div class="data-row-actions">' . $actions . '</div>';
            }
            echo '</div>';
        }

        echo '</article>';
    }
}

/**
 * Column header for a .data-list whose rows carry a select checkbox.
 * Emit it INSIDE the .data-list (add class "is-columnar has-head") before the rows.
 *
 * $cols: 'main' (label over avatar+title), 'value', 'badge' — plain text, escaped.
 * $opts: 'select_all' (aria-label; renders the select-all checkbox, id data-list-select-all).
 */
if (!function_exists('slate_data_list_head')) {
    function slate_data_list_head(array $cols, array $opts = []): void {
        echo '<div class="data-list-head">';
        if (!empty($opts['select_all'])) {
            echo '<label class="data-row-check"><input type="checkbox" class="data-list-select-all" aria-label="'
               . e((string)$opts['select_all']) . '"></label>';
        }
        echo '<span class="data-list-head-main">' . e((string)($cols['main'] ?? '')) . '</span>';
        if (isset($cols['value'])) echo '<span class="data-list-head-value">' . e((string)$cols['value']) . '</span>';
        if (isset($cols['badge'])) echo '<span class="data-list-head-badge">' . e((string)$cols['badge']) . '</span>';
        echo '<span class="data-list-head-chevron" aria-hidden="true"></span>';
        echo '</div>';
    }
}

/**
 * Render one app-style "recent list" row inside a .dlist. Used by dashboard
 * plugin widgets to show latest records (orders, submissions, charges, …).
 * $a: title, sub, avatar, avatar_color (success|danger|warning|info|muted),
 *     amount, time, href (whole row becomes a link).
 */
/**
 * Inline trend sparkline for a stat card — a tiny filled line chart with no
 * axes/labels, just the shape of a recent trend. No charting library: a
 * handful of points normalized into a fixed-size SVG path.
 *
 * $points: numeric values, oldest first (e.g. one per day/week). Fewer than
 * 2 points renders nothing (a flat/empty line says nothing useful).
 * $opts: width/height in px (default 64x24), color (any CSS color function,
 * default var(--accent)).
 */
if (!function_exists('slate_sparkline_svg')) {
    function slate_sparkline_svg(array $points, array $opts = []): string {
        $points = array_values(array_map('floatval', $points));
        $n = count($points);
        if ($n < 2) return '';

        $w     = (int)($opts['width']  ?? 64);
        $h     = (int)($opts['height'] ?? 24);
        $color = (string)($opts['color'] ?? 'var(--accent)');

        $max   = max($points);
        $min   = min($points);
        $range = ($max - $min) > 0.0001 ? ($max - $min) : 1.0;
        $stepX = $n > 1 ? $w / ($n - 1) : 0;

        $coords = [];
        foreach ($points as $i => $v) {
            $x = round($i * $stepX, 2);
            // 1px vertical padding top/bottom so the line never clips.
            $y = round($h - (($v - $min) / $range) * ($h - 2) - 1, 2);
            $coords[] = $x . ',' . $y;
        }
        $linePath = 'M' . implode(' L', $coords);
        $areaPath = $linePath . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z';
        $gradId   = 'spk' . substr(md5($linePath . mt_rand()), 0, 8);

        return '<svg class="dash-stat-spark" viewBox="0 0 ' . $w . ' ' . $h . '" '
             . 'width="' . $w . '" height="' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
             . '<defs><linearGradient id="' . $gradId . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="' . $color . '" stop-opacity="0.35"/>'
             . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0"/>'
             . '</linearGradient></defs>'
             . '<path d="' . e($areaPath) . '" fill="url(#' . $gradId . ')" stroke="none"/>'
             . '<path d="' . e($linePath) . '" fill="none" stroke="' . $color . '" '
             . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>'
             . '</svg>';
    }
}

/**
 * Render one glass KPI/stat card (see .dash-stat in the emitted CSS) —
 * icon badge + big number, a label, and an optional trend sparkline or
 * caption underneath. Used by the main dashboard (admin/index.php) and by
 * plugin dashboards (e.g. Booking's) so every "stat cards along the top"
 * row in the admin looks and behaves the same.
 *
 * $opts keys:
 *   icon    (string, required) — icon name understood by slate_admin_nav_icon()
 *   number  (string|int, required) — the big headline value, pre-formatted
 *           by the caller (so it can be a plain count, a currency string, etc.)
 *   label   (string, required) — small-caps label under the number
 *   caption (string, optional) — one line under the label (e.g. "12 inactive")
 *   spark   (array<float>, optional) — trend points, oldest first; rendered
 *           via slate_sparkline_svg() in the card's bottom-right corner.
 *           Ignored (falls back to the watermark icon) when fewer than 2
 *           points are given.
 *   tone    (string, optional: 'success') — tints the badge/marker
 */
if (!function_exists('slate_stat_card')) {
    function slate_stat_card(array $opts): void {
        $icon    = (string)($opts['icon'] ?? 'circle');
        $number  = (string)($opts['number'] ?? '0');
        $label   = (string)($opts['label'] ?? '');
        $caption = (string)($opts['caption'] ?? '');
        $spark   = array_values((array)($opts['spark'] ?? []));
        $tone    = (string)($opts['tone'] ?? '');

        $cls = 'dash-stat' . ($tone !== '' ? ' is-' . preg_replace('/[^a-z]/', '', strtolower($tone)) : '');
        $sparkSvg = count($spark) >= 2 ? slate_sparkline_svg($spark, ['width' => 64, 'height' => 26]) : '';

        // .dash-stat-num's 40px size was designed around short counts ("8",
        // "150"); a caller passing a formatted amount (Booking's revenue
        // card sends e.g. "EUR 2,137.00") overflowed the card at that size
        // -- scale down for longer content instead of asking every caller
        // to know or care about this.
        $numLen = mb_strlen($number);
        $numCls = 'dash-stat-num' . ($numLen > 10 ? ' dash-stat-num--sm' : ($numLen > 6 ? ' dash-stat-num--md' : ''));

        echo '<div class="' . $cls . '">';
        echo   '<div class="dash-stat-top">';
        echo     '<span class="dash-stat-badge">' . slate_admin_nav_icon($icon) . '</span>';
        echo     '<span class="' . $numCls . '">' . e($number) . '</span>';
        echo   '</div>';
        echo   '<div class="dash-stat-k">' . e($label) . '</div>';
        if ($caption !== '') {
            echo '<div class="dash-stat-s">' . e($caption) . '</div>';
        }
        if ($sparkSvg !== '') {
            echo $sparkSvg;
        } else {
            echo '<span class="dash-stat-wm">' . slate_admin_nav_icon($icon) . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('slate_dlist_row')) {
    function slate_dlist_row(array $a): void {
        $href = isset($a['href']) ? (string)$a['href'] : '';
        $tag  = $href !== '' ? 'a' : 'div';
        $ava     = (string)($a['avatar'] ?? '');
        $avaHtml = isset($a['avatar_html']) ? (string)$a['avatar_html'] : null; // raw icon/image
        $cls  = 'dlist-ava';
        if (!empty($a['avatar_color'])) $cls .= ' is-' . preg_replace('/[^a-z]/', '', strtolower((string)$a['avatar_color']));
        echo '<' . $tag . ' class="dlist-row"' . ($href !== '' ? ' href="' . e($href) . '"' : '') . '>';
        if ($avaHtml !== null && $avaHtml !== '') {
            // Trusted caller markup (e.g. initials + a Gravatar <img> overlay).
            echo '<span class="' . $cls . ' has-media">' . $avaHtml . '</span>';
        } elseif ($ava !== '') {
            echo '<span class="' . $cls . '">' . e(mb_substr($ava, 0, 2)) . '</span>';
        }
        echo '<span class="dlist-body">';
        echo   '<span class="dlist-title">' . e((string)($a['title'] ?? '')) . '</span>';
        if (isset($a['sub']) && $a['sub'] !== '') echo '<span class="dlist-sub">' . e((string)$a['sub']) . '</span>';
        echo '</span>';
        $amount = (string)($a['amount'] ?? '');
        $time   = (string)($a['time'] ?? '');
        if ($amount !== '' || $time !== '') {
            echo '<span class="dlist-trail">';
            if ($amount !== '') echo '<span class="dlist-amount">' . e($amount) . '</span>';
            if ($time !== '')   echo '<span class="dlist-time">' . e($time) . '</span>';
            echo '</span>';
        }
        echo '</' . $tag . '>';
    }
}

/**
 * Emit the JS that drives the collapse/expand behaviour.
 * Call once per page after the .data-list markup. Idempotent.
 */
if (!function_exists('slate_data_list_script')) {
    function slate_data_list_script(): void {
        if (defined('SLATE_DATA_LIST_JS_EMITTED')) return;
        define('SLATE_DATA_LIST_JS_EMITTED', true);
        ?>
        <script>
        (function () {
            if (window.__slateDataList) return;
            window.__slateDataList = true;

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.data-row-summary');
                if (!btn || btn.disabled) return;
                var row    = btn.closest('.data-row');
                var detail = row.querySelector('.data-row-detail');
                if (!detail) return;
                var list   = row.closest('.data-list');
                var single = list && list.hasAttribute('data-single-open');
                var open   = btn.getAttribute('aria-expanded') === 'true';

                if (!open && single) {
                    list.querySelectorAll('.data-row.is-open').forEach(function (other) {
                        if (other === row) return;
                        other.classList.remove('is-open');
                        var ob = other.querySelector('.data-row-summary');
                        var od = other.querySelector('.data-row-detail');
                        if (ob) ob.setAttribute('aria-expanded', 'false');
                        if (od) od.hidden = true;
                    });
                }

                if (open) {
                    row.classList.remove('is-open');
                    btn.setAttribute('aria-expanded', 'false');
                    detail.hidden = true;
                } else {
                    row.classList.add('is-open');
                    btn.setAttribute('aria-expanded', 'true');
                    detail.hidden = false;
                }
            });
        })();
        </script>
        <?php
    }
}

/**
 * Emit translated HTML5 constraint-validation messages for every form on
 * the page. The browser's own "Please fill in this field" bubble follows
 * the visitor's browser/OS language, not this site's language switcher —
 * this replaces it with our own, via setCustomValidity(), so a French
 * visitor sees a French message regardless of their browser's locale.
 * Call once per public-facing page with user-submitted forms. Idempotent.
 */
if (!function_exists('slate_form_validation_i18n_script')) {
    function slate_form_validation_i18n_script(): void {
        if (defined('SLATE_FORM_VALIDATION_JS_EMITTED')) return;
        define('SLATE_FORM_VALIDATION_JS_EMITTED', true);
        $msgs = [
            'required' => __('validation_required', 'Please fill in this field.'),
            'email'    => __('validation_email', 'Please enter a valid email address.'),
            'url'      => __('validation_url', 'Please enter a valid URL.'),
            'generic'  => __('validation_generic', 'Please correct this field.'),
        ];
        ?>
        <script>
        (function () {
            if (window.__slateFormValidation) return;
            window.__slateFormValidation = true;
            var MSG = <?= json_encode($msgs) ?>;

            function messageFor(el) {
                var v = el.validity;
                if (v.valueMissing) return MSG.required;
                if (v.typeMismatch) {
                    if (el.type === 'email') return MSG.email;
                    if (el.type === 'url') return MSG.url;
                }
                if (v.tooShort || v.tooLong || v.patternMismatch ||
                    v.rangeUnderflow || v.rangeOverflow || v.stepMismatch || v.badInput) {
                    return MSG.generic;
                }
                return '';
            }

            // 'invalid' does not bubble, so listen during the capture phase
            // on the document instead of wiring every field individually.
            document.addEventListener('invalid', function (e) {
                var el = e.target;
                if (el && typeof el.setCustomValidity === 'function') {
                    el.setCustomValidity(messageFor(el));
                }
            }, true);

            document.addEventListener('input', function (e) {
                var el = e.target;
                if (el && typeof el.setCustomValidity === 'function') el.setCustomValidity('');
            }, true);

            document.addEventListener('change', function (e) {
                var el = e.target;
                if (el && typeof el.setCustomValidity === 'function') el.setCustomValidity('');
            }, true);
        })();
        </script>
        <?php
    }
}

/**
 * Open a page layout wrapper.
 *
 *   $variant = 'default'     — single-column main content
 *   $variant = 'with-aside'  — main + 320px right rail (CSS grid)
 *
 * Pair with slate_page_layout_end(). Inside a 'with-aside' layout,
 * wrap primary content in <div class="page-main">...</div> and the
 * sidebar metadata in <aside class="page-aside">...</aside>.
 */
if (!function_exists('slate_page_layout')) {
    function slate_page_layout(string $variant = 'default'): void {
        $cls = 'page-layout';
        if ($variant === 'with-aside') $cls .= ' page-layout-with-aside';
        echo '<div class="' . e($cls) . '">';
    }
}
if (!function_exists('slate_page_layout_end')) {
    function slate_page_layout_end(): void {
        echo '</div>';
    }
}

/**
 * Reusable, compact pagination control.
 *
 *   slate_pagination($page, $totalPages, $_GET, ['total' => $count, 'label' => 'entries']);
 *
 * Preserves existing query params (minus `page`), renders Prev / numbered
 * pages with ellipses / Next, and an optional "X–Y of N" summary. No-ops
 * when there's a single page.
 */
if (!function_exists('slate_pagination')) {
    function slate_pagination(int $page, int $totalPages, array $queryParams = [], array $opts = []): void {
        $totalPages = max(1, $totalPages);
        $page       = max(1, min($totalPages, $page));

        $url = static function (int $p) use ($queryParams): string {
            $q = $queryParams;
            unset($q['page']);
            $q['page'] = $p;
            return '?' . http_build_query($q);
        };

        // Build the window of page numbers with ellipses.
        $pages = [];
        $win   = 1; // neighbours either side of current
        for ($p = 1; $p <= $totalPages; $p++) {
            if ($p === 1 || $p === $totalPages || ($p >= $page - $win && $p <= $page + $win)) {
                $pages[] = $p;
            } elseif (end($pages) !== '…') {
                $pages[] = '…';
            }
        }

        echo '<nav class="slate-pager" aria-label="Pagination">';

        if (!empty($opts['total'])) {
            $perPage = (int)($opts['per_page'] ?? 0);
            $label   = (string)($opts['label'] ?? __('items', 'items'));
            $total   = (int)$opts['total'];
            if ($perPage > 0 && $total > 0) {
                $start = ($page - 1) * $perPage + 1;
                $end   = min($total, $page * $perPage);
                echo '<span class="slate-pager-summary">'
                   . e(sprintf(__('pager_range_summary', '%s–%s of %s %s'), $start, $end, number_format($total), $label))
                   . '</span>';
            } else {
                echo '<span class="slate-pager-summary">'
                   . e(sprintf(__('pager_total_summary', '%s %s'), number_format($total), $label))
                   . '</span>';
            }
        }

        if ($totalPages > 1) {
            echo '<div class="slate-pager-pages">';
            // Prev
            if ($page > 1) {
                echo '<a class="slate-pager-btn" rel="prev" aria-label="Previous page" href="' . e($url($page - 1)) . '">‹</a>';
            } else {
                echo '<span class="slate-pager-btn is-disabled" aria-hidden="true">‹</span>';
            }
            foreach ($pages as $p) {
                if ($p === '…') {
                    echo '<span class="slate-pager-gap">…</span>';
                } elseif ($p === $page) {
                    echo '<span class="slate-pager-btn is-current" aria-current="page">' . $p . '</span>';
                } else {
                    echo '<a class="slate-pager-btn" href="' . e($url((int)$p)) . '">' . $p . '</a>';
                }
            }
            // Next
            if ($page < $totalPages) {
                echo '<a class="slate-pager-btn" rel="next" aria-label="Next page" href="' . e($url($page + 1)) . '">›</a>';
            } else {
                echo '<span class="slate-pager-btn is-disabled" aria-hidden="true">›</span>';
            }
            echo '</div>';
        }

        echo '</nav>';
    }
}

// ── Brand accent override ────────────────────────────────────
// Emits a small <style> that re-points the accent CSS variables at the
// admin-chosen brand colour (Settings → Branding). Call AFTER
// slate_ui_emit_css() so it wins the cascade. No-op when the colour is
// unset or still the theme default, so default installs emit nothing.
if (!function_exists('slate_brand_accent_emit')) {
    function slate_brand_accent_emit(): void {
        if (defined('SLATE_BRAND_ACCENT_EMITTED')) return;
        define('SLATE_BRAND_ACCENT_EMITTED', true);

        $accent = '';
        if (class_exists('Database')) {
            $raw = (string)Database::setting('brand_accent_color');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) $accent = strtoupper($raw);
        }

        // Manual override for --on-accent (button/badge TEXT on the accent
        // fill) — Settings -> Branding -> Button text color. 'auto' (the
        // default, and anything else) keeps the measured pick below; this
        // exists for the rare accent where an admin wants a specific answer
        // regardless of what the contrast math picks.
        $onAccentMode = '';
        if (class_exists('Database')) {
            $m = strtolower(trim((string)Database::setting('brand_button_text_mode')));
            if ($m === 'light' || $m === 'dark') $onAccentMode = $m;
        }

        if (($accent === '' || strcasecmp($accent, '#111111') === 0) && $onAccentMode === '') return; // nothing to override — theme already correct

        // Both companions come from the shared derivation in brand_tokens.php,
        // which measures contrast rather than testing lightness against a
        // threshold. The threshold that used to be here read "> 0.55 means use
        // ink", and a brand like #F89DC1 sits at 0.47 — so it picked white,
        // and every primary button in the admin was white on pale pink at
        // about 2:1 while the customer portal, which already measured, gave
        // parents the readable version of the same screen.
        require_once __DIR__ . '/brand_tokens.php';
        // If the accent itself is untouched (still the sentinel above) but a
        // text-color override was requested, derive tokens against this
        // file's own default accent so the emitted --accent matches what the
        // page would otherwise show — only --on-accent actually changes.
        $effectiveAccent = $accent !== '' ? $accent : '#111111';
        $tokens = slate_brand_accent_tokens($effectiveAccent, $onAccentMode ?: null);
        ?>
<style>
:root {
    --accent:       <?= e($effectiveAccent) ?>;
    --accent-deep:  color-mix(in srgb, <?= e($effectiveAccent) ?> 82%, #000);
    --accent-hover: color-mix(in srgb, <?= e($effectiveAccent) ?> 82%, #000);
    --accent-soft:  color-mix(in srgb, <?= e($effectiveAccent) ?> 14%, #fff);
    --on-accent:    <?= e($tokens['on_accent']) ?>;
    /* The brand hue darkened until it clears AA as text. Use this, not
       --accent, whenever the brand colour is the colour of words. */
    --accent-ink:   <?= e($tokens['accent_ink']) ?>;
    --ring:         color-mix(in srgb, <?= e($effectiveAccent) ?> 22%, transparent);
    --info:         <?= e($effectiveAccent) ?>;
    --info-soft:    color-mix(in srgb, <?= e($effectiveAccent) ?> 18%, #fff);
}
</style>
<?php
    }
}

// ── Sidebar themes ───────────────────────────────────────────
// The admin-selectable colour scheme for the desktop sidebar (Settings →
// Branding). Each theme overrides the --sidebar-* tokens. Dark themes only
// change the background; 'light' flips the text/border/hover tokens too.
// 'ink' is the default and emits nothing.
if (!function_exists('slate_sidebar_themes')) {
    function slate_sidebar_themes(): array {
        return [
            'ink'   => ['label' => 'Ink (default)',  'swatch' => '#0E1117', 'dark' => true,  'vars' => []],
            'navy'  => ['label' => 'Deep navy',      'swatch' => '#0E1A2B', 'dark' => true,  'vars' => ['--sidebar-bg' => '#0E1A2B']],
            'teal'  => ['label' => 'Brand teal-ink', 'swatch' => '#05242F', 'dark' => true,  'vars' => ['--sidebar-bg' => '#05242F']],
            'slate' => ['label' => 'Soft slate',     'swatch' => '#1A1F29', 'dark' => true,  'vars' => ['--sidebar-bg' => '#1A1F29']],
            'light' => ['label' => 'Light',          'swatch' => '#F6F7F9', 'dark' => false, 'vars' => [
                '--sidebar-bg'     => '#F6F7F9',
                '--sidebar-border' => 'rgba(0,0,0,0.08)',
                '--sidebar-hover'  => 'rgba(0,0,0,0.05)',
                '--sidebar-active' => 'rgba(0,0,0,0.06)',
                '--sidebar-text'   => '#475569',
                '--sidebar-muted'  => '#64748b',
                '--sidebar-subtle' => '#94a3b8',
                '--sidebar-strong' => '#0f172a',
            ]],
        ];
    }
}

// Emit the chosen sidebar theme's token overrides. Call AFTER
// slate_ui_emit_css() so it wins the cascade. No-op for the default 'ink'.
if (!function_exists('slate_sidebar_theme_emit')) {
    function slate_sidebar_theme_emit(): void {
        if (defined('SLATE_SIDEBAR_THEME_EMITTED')) return;
        define('SLATE_SIDEBAR_THEME_EMITTED', true);

        if (!class_exists('Database')) return;
        $key    = (string)Database::setting('sidebar_theme');
        $themes = slate_sidebar_themes();
        if ($key === '' || !isset($themes[$key]) || empty($themes[$key]['vars'])) return;

        echo "<style id=\"slate-sidebar-theme\">:root{";
        foreach ($themes[$key]['vars'] as $prop => $val) {
            echo e($prop) . ':' . e($val) . ';';
        }
        echo "}</style>\n";
    }
}

// ── Settings tab strip (shared by admin/settings.php and any
//    settings-adjacent page, e.g. the Translations plugin, that needs the
//    same strip with itself marked active) ────────────────────────────
if (!function_exists('slate_settings_tabs')) {
    /**
     * The canonical list of settings-area tabs: label, icon (a key from
     * slate_admin_nav_icon()'s map), and an absolute href. Absolute hrefs
     * (not "?tab=slug") so the strip renders identically regardless of
     * which page it's called from -- a relative "?tab=" only makes sense
     * when the current page IS admin/settings.php.
     */
    function slate_settings_tabs(): array {
        $base = SLATE_URL . '/admin/settings.php?tab=';
        return [
            'general'      => ['label' => __('general',      'General'),      'icon' => 'settings', 'href' => $base . 'general'],
            'permalinks'   => ['label' => __('permalinks',   'Permalinks'),   'icon' => 'link',      'href' => $base . 'permalinks'],
            'datetime'     => ['label' => __('datetime',     'Date & Time'),  'icon' => 'clock',     'href' => $base . 'datetime'],
            'branding'     => ['label' => __('branding',      'Branding'),    'icon' => 'palette',   'href' => $base . 'branding'],
            'media'        => ['label' => __('media',         'Media'),       'icon' => 'film',      'href' => $base . 'media'],
            'smtp'         => ['label' => __('smtp',          'SMTP'),        'icon' => 'mail',      'href' => $base . 'smtp'],
            'security'     => ['label' => __('security',      'Security'),    'icon' => 'shield',    'href' => $base . 'security'],
            'landing'      => ['label' => __('landing',       'Landing'),     'icon' => 'home',      'href' => $base . 'landing'],
            'translations' => ['label' => __('translations',  'Translations'),'icon' => 'globe',
                                'href' => SLATE_URL . '/plugins/multilang-translate/admin/index.php'],
            'system'       => ['label' => __('system',        'System'),      'icon' => 'sliders',   'href' => $base . 'system'],
        ];
    }

    /** Render the tab strip, marking $activeSlug current. */
    function slate_render_settings_tabs(string $activeSlug): void {
        $tabs = slate_settings_tabs();
        echo '<nav class="settings-tabs" role="tablist" aria-label="' . e(__('settings_sections', 'Settings sections')) . '">';
        foreach ($tabs as $slug => $tab) {
            $isActive = $activeSlug === $slug;
            echo '<a href="' . e($tab['href']) . '" class="settings-tab' . ($isActive ? ' is-active' : '') . '"'
               . ' role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '">'
               . (function_exists('slate_admin_nav_icon') ? slate_admin_nav_icon($tab['icon']) : '')
               . e($tab['label']) . '</a>';
        }
        echo '</nav>';
    }
}

// ── CSS emission (call once per response from templates) ─────
if (!function_exists('slate_ui_emit_css')) {
    function slate_ui_emit_css(): void {
        if (defined('SLATE_UI_COMPONENTS_EMITTED')) return;
        define('SLATE_UI_COMPONENTS_EMITTED', true);
        ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Inter:wght@400;500;600;700&display=swap">
<style>
/* ═════════════════════════════════════════════════════════════
   Slate — dark-sidebar / light-canvas theme
   Phase 0 redesign. Off-white canvas, white cards on hairline
   borders, blue accent for primary actions. DM Sans body, Syne
   display, DM Mono code.
   ═════════════════════════════════════════════════════════════ */
:root {
    /* Canvas + surfaces — premium light theme. A soft off-white canvas lets
       pure-white cards lift with a whisper of depth (no heavy shadows), while
       muted cool-grey secondary surfaces keep table headers, hovers, inputs
       and filters legible. */
    --bg:                #F1F3F6;
    --surface:           #FFFFFF;
    --surface-2:         #F5F6F8;
    --surface-sunken:    #EEF0F2;
    --card:              #FFFFFF;   /* alias for --surface, back-compat */
    --overlay:           rgba(14, 17, 23, 0.45);

    /* Sidebar (dark) */
    --sidebar-bg:        #0E1117;
    --sidebar-border:    rgba(255, 255, 255, 0.07);
    --sidebar-hover:     rgba(255, 255, 255, 0.06);
    --sidebar-active:    rgba(255, 255, 255, 0.10);
    --sidebar-text:      rgba(255, 255, 255, 0.86);
    --sidebar-muted:     rgba(255, 255, 255, 0.55);
    --sidebar-subtle:    rgba(255, 255, 255, 0.40);
    --sidebar-strong:    #ffffff;   /* high-emphasis sidebar text (themeable) */

    /* Ink ladder (on light surfaces) — slightly muted, slate-leaning. */
    --text:              #16181D;
    --text-2:            #3F4450;
    --muted:             #71757E;
    --subtle:            #A2A6AE;
    --faint:             #D3D5DA;

    /* Lines — solid premium greys (crisper than translucent black). */
    --border:            #ECEDEF;
    --border-strong:     #E0E2E6;
    --border-stronger:   #CFD2D8;

    /* Accent — Kohevo monochrome black/graphite, used for primary actions
       and active states. This is only the factory DEFAULT: any tenant with
       their own Settings → Branding accent colour overrides it via
       slate_brand_accent_emit(), which emits its own :root block later in
       <head> so it wins the cascade (see that function's docblock). */
    --accent:            #111111;
    --accent-deep:       #000000;
    --accent-soft:       #F3F4F6;
    --accent-hover:      #000000;
    --on-accent:         #FFFFFF;
    --ring:              rgba(17, 17, 17, 0.14);

    /* Semantic — soft tints kept at a consistent ~100 level so status
       badges/pills read clearly (the green & blue were a washed-out 50). */
    --success:           #16A34A;
    --success-soft:      #DCFCE7;
    --warning:           #D97706;
    --warning-soft:      #FEF3C7;
    --danger:            #DC2626;
    --danger-soft:       #FEE2E2;
    --info:              #2563EB;
    --info-soft:         #DBEAFE;

    /* Radii — rounded, modern-SaaS feel (cards/buttons noticeably softer). */
    --radius-sm:         9px;
    --radius:            11px;
    --radius-lg:         16px;
    --radius-xl:         20px;
    --radius-2xl:        26px;
    --radius-full:       9999px;

    /* Shadows — soft elevation so white cards float on the light canvas. */
    --shadow-xs:         0 1px 2px rgba(15, 17, 23, 0.04);
    --shadow-sm:         0 1px 2px rgba(15, 17, 23, 0.05), 0 1px 1px rgba(15,17,23,0.03);
    --shadow:            0 2px 6px rgba(15, 17, 23, 0.05);
    --shadow-md:         0 6px 20px rgba(15, 17, 23, 0.07);
    --shadow-lg:         0 18px 50px rgba(15, 17, 23, 0.16);

    /* ── Glassmorphism ──────────────────────────────────────────
       Frosted translucent surfaces over a soft gradient canvas. The
       light edge highlight (--glass-border + the inset top highlight in
       --glass-shadow) is what sells the "pane of glass" look. */
    --glass-bg:          rgba(255, 255, 255, 0.55);
    --glass-bg-strong:   rgba(255, 255, 255, 0.72);
    --glass-bg-2:        rgba(255, 255, 255, 0.40);
    --glass-border:      rgba(255, 255, 255, 0.70);
    --glass-blur:        18px;
    --glass-shadow:      0 8px 30px rgba(31, 41, 75, 0.10),
                         inset 0 1px 0 rgba(255, 255, 255, 0.60);
    --glass-shadow-lg:   0 14px 44px rgba(31, 41, 75, 0.16),
                         inset 0 1px 0 rgba(255, 255, 255, 0.65);
    /* Accent glow for primary/active elements. */
    --glow-accent:       0 6px 20px color-mix(in srgb, var(--accent) 38%, transparent);

    /* Typography */
    /* One cohesive typeface across the whole UI — Inter, the premium
       dashboard standard. Headings differ by weight + tracking only, so
       nothing clashes with the muted palette. */
    --font-sans:         "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI",
                         Roboto, "Helvetica Neue", Arial, sans-serif;
    --font-display:      "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI",
                         Roboto, "Helvetica Neue", Arial, sans-serif;
    --font-mono:         "DM Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace;

    /* Spacing */
    --space-1:           4px;
    --space-2:           8px;
    --space-3:           12px;
    --space-4:           16px;
    --space-5:           20px;
    --space-6:           24px;
    --space-8:           32px;
    --space-10:          40px;
    --space-12:          48px;

    /* Layout */
    --sidebar-width:           232px;
    --sidebar-collapsed-width: 76px;
    --topbar-height:     56px;
    --tabbar-height:     62px;
    --content-max:       1440px;
    --right-rail-width:  320px;
}

/* ─── Reset & base ─────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }

body {
    font-family: var(--font-sans);
    font-size: 14px;
    line-height: 1.5;
    color: var(--text);
    /* Soft, brand-tinted gradient canvas — gives the frosted glass surfaces
       something to refract. Fixed so it stays put while content scrolls. */
    background:
        radial-gradient(1100px 760px at 6% -8%,
            color-mix(in srgb, var(--accent) 10%, transparent), transparent 58%),
        radial-gradient(960px 720px at 104% 6%,
            color-mix(in srgb, var(--accent) 7%, transparent), transparent 55%),
        var(--bg);
    background-attachment: fixed;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
    font-feature-settings: "ss01", "tnum";
    overflow-x: hidden;
}

/* Scrollbars hidden entirely across the UI — scrolling still works (wheel,
   trackpad, touch, keyboard), there's just no visible scrollbar chrome. */
* { scrollbar-width: none; -ms-overflow-style: none; }
*::-webkit-scrollbar { width: 0; height: 0; display: none; }
html, body { scrollbar-width: none; -ms-overflow-style: none; }
html::-webkit-scrollbar, body::-webkit-scrollbar { width: 0; height: 0; display: none; }

/* Glassmorphism fallback — without backdrop-filter, translucent surfaces would
   let content bleed through illegibly. Make the frosted surfaces opaque. */
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
    .card, .stat { background: var(--surface); }
    .app-panel { background: var(--surface) !important; }
    .sidebar { background: var(--sidebar-bg) !important; }
    .topbar { background: var(--surface) !important; }
    .field input, .field select, .field textarea, .input { background: var(--surface); }
}

/* -- Dashboard plugin widgets -- bento-style KPI tiles, matching the
   member portal's own home-dashboard KPI row (.pstat in portal-app.css)
   and the admin dashboard's own top-of-page stat cards (.dash-stat further
   below): individually rounded, spaced, gently elevated tiles rather than
   a single joined hairline-divided strip. Used by the Shop / Forms /
   Booking / Coaching / Membership / etc. dashboard cards -- every plugin
   that registers an `admin_dashboard_widgets` entry shares this component,
   so this one restyle upgrades all of them at once. Deliberately still not
   icon-per-tile (that would mean touching every plugin's widget markup to
   assign one); the accent-dash bullet on the label carries the same
   "branded instrument, not a plain number" read that .pstat-k/.dash-stat-k
   use, without that per-call-site wiring. */
.dwidget-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(112px, 1fr));
    gap: 10px;
    margin: 2px 0 14px;
}
.dwidget-kpi {
    position: relative;
    min-width: 0;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 12px 14px 13px;
    transition: transform .16s cubic-bezier(.22,1,.36,1), box-shadow .16s ease, background .14s ease, border-color .14s ease;
}
.dwidget-kpi:hover {
    background: color-mix(in srgb, var(--accent) 5%, var(--surface-2));
    border-color: color-mix(in srgb, var(--accent) 30%, var(--border));
    transform: translateY(-2px);
    box-shadow: 0 10px 22px -6px rgba(15,23,42,.14);
}
.dwidget-kpi-k {
    display: flex; align-items: center; gap: 7px;
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--subtle);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dwidget-kpi-k::before {
    content: ""; flex: none; width: 12px; height: 2px; border-radius: 2px;
    background: var(--accent); opacity: .85;
}
.dwidget-kpi-v {
    font-size: 24px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2;
    font-variant-numeric: tabular-nums; margin-top: 6px; padding-left: 19px;
    color: var(--accent-deep, var(--text));
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dwidget-kpi-note { font-size: 11px; margin-top: 3px; padding-left: 19px; color: var(--muted); }
.dwidget-actions { display: flex; gap: 8px; flex-wrap: wrap; }
/* Header "view all" link, right-aligned in the card-header (fills the header). */
.dwidget-all {
    font-size: 12px; font-weight: 600; color: var(--accent);
    white-space: nowrap; flex: none;
}
.dwidget-all:hover { color: var(--accent-deep); text-decoration: none; }

/* ── App-style "recent list" — latest records inside a dashboard widget.
   Each row spans the full card width: avatar · title/sub · amount/time. */
.dlist { list-style: none; margin: 6px 0 0; padding: 0; }
.dlist-row {
    display: flex; align-items: center; gap: 11px;
    padding: 9px 8px; margin: 0 -8px;
    border-radius: 9px;
    color: var(--text); text-decoration: none;
    transition: background .12s ease;
}
.dlist-row + .dlist-row { box-shadow: 0 -1px 0 var(--border); }
a.dlist-row:hover { background: var(--surface-2); box-shadow: none; }
a.dlist-row:hover + .dlist-row { box-shadow: none; }
.dlist-ava {
    flex: none; width: 36px; height: 36px; border-radius: 10px;
    display: grid; place-items: center; font-weight: 700; font-size: 12px;
    text-transform: uppercase; letter-spacing: -.01em;
    background: var(--accent-soft); color: var(--accent);
}
.dlist-ava.is-success { background: var(--success-soft); color: var(--success); }
.dlist-ava.is-danger  { background: var(--danger-soft);  color: var(--danger); }
.dlist-ava.is-warning { background: var(--warning-soft); color: var(--warning); }
.dlist-ava.is-info    { background: var(--info-soft);    color: var(--info); }
.dlist-ava.is-muted   { background: var(--surface-sunken); color: var(--muted); }
/* Avatar with an image overlay (e.g. Gravatar) on top of the initials. */
.dlist-ava.has-media { position: relative; overflow: hidden; border-radius: 999px; }
.dlist-ava.has-media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
.dlist-body { flex: 1 1 auto; min-width: 0; }
.dlist-title, .dlist-sub { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dlist-title { font-size: 13px; font-weight: 600; letter-spacing: -.01em; }
.dlist-sub { font-size: 11.5px; color: var(--muted); margin-top: 1px; }
.dlist-trail {
    flex: none; max-width: 44%;
    display: flex; flex-direction: column; align-items: flex-end; gap: 2px;
    text-align: right;
}
.dlist-amount { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.dlist-time { font-size: 10.5px; color: var(--subtle); font-family: var(--font-mono); white-space: nowrap; }
.dlist-empty { font-size: 12.5px; color: var(--muted); text-align: center; padding: 16px 0; }
.dwidget-subhead {
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--subtle); margin: 4px 2px 2px;
}

/* ── Recent-activity lists (app-style rows) used inside dashboard widgets.
   Leading avatar/icon · two-line body · trailing amount + time. Truncates,
   tap-feedback, comfortable touch targets on mobile. */
.dlist { list-style: none; margin: 0; padding: 0; }
.dlist-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 6px; border-bottom: 1px solid var(--border);
    border-radius: 10px; color: var(--text); text-decoration: none;
    transition: background .14s ease;
}
.dlist-row:last-child { border-bottom: 0; }
a.dlist-row:hover { background: var(--surface-2); text-decoration: none; }
a.dlist-row:active { background: var(--surface-sunken); }
.dlist-ava {
    flex: none; width: 38px; height: 38px; border-radius: 11px;
    display: grid; place-items: center;
    font-size: 13px; font-weight: 700; letter-spacing: -.02em; text-transform: uppercase;
    background: var(--accent-soft); color: var(--accent);
}
.dlist-ava svg { width: 18px; height: 18px; color: var(--accent); }
.dlist-body { flex: 1 1 auto; min-width: 0; }
.dlist-title {
    font-size: 13px; font-weight: 600; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dlist-sub {
    font-size: 11.5px; color: var(--muted); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dlist-trail {
    flex: none; display: flex; flex-direction: column; align-items: flex-end;
    gap: 3px; text-align: right; max-width: 46%;
}
.dlist-amount {
    font-size: 13px; font-weight: 700; color: var(--text);
    font-variant-numeric: tabular-nums; white-space: nowrap;
}
.dlist-time { font-size: 10.5px; color: var(--subtle); font-family: var(--font-mono); white-space: nowrap; }
.dlist-trail .badge { font-size: 9.5px; }
.dlist-empty { padding: 16px 6px; text-align: center; color: var(--muted); font-size: 12.5px; }

@media (max-width: 600px) {
    .dlist-row { padding: 12px 6px; gap: 12px; }
    .dlist-ava { width: 42px; height: 42px; border-radius: 12px; }
}

a {
    color: var(--accent);
    text-decoration: none;
    transition: color 0.15s ease;
}
a:hover { color: var(--accent-deep); }

/* Accent link helper (back-compat) */
a.link-accent { color: var(--accent); }
a.link-accent:hover { color: var(--accent-hover); }

h1, h2, h3, h4, h5 {
    margin: 0 0 0.5em;
    font-weight: 600;
    letter-spacing: -0.015em;
    color: var(--text);
    font-family: var(--font-display);
}
h1 { font-size: 20px; line-height: 1.2; font-weight: 600; letter-spacing: -0.012em; color: var(--text-2); }
h2 { font-size: 16px; letter-spacing: -0.01em; line-height: 1.3; }
h3 { font-size: 14px; line-height: 1.4; font-family: var(--font-sans); }
h4 { font-size: 13px; line-height: 1.4; color: var(--text-2); font-family: var(--font-sans); }
/* Headings ease down on phones so they don't overpower small screens. */
@media (max-width: 600px) {
    h1 { font-size: 20px; }
    h2 { font-size: 15px; }
}

p { margin: 0 0 1em; }
p:last-child { margin-bottom: 0; }

code, pre {
    font-family: var(--font-mono);
    font-size: 0.92em;
}
code {
    background: var(--surface-2);
    padding: 1px 6px;
    border-radius: var(--radius-sm);
    color: var(--text-2);
    font-size: 12px;
    border: 1px solid var(--border);
}

hr {
    border: 0;
    height: 1px;
    background: var(--border);
    margin: var(--space-6) 0;
}

.section-divider {
    border-top: 1px solid var(--border);
    margin: var(--space-6) 0;
}

/* ─── Cards ────────────────────────────────────────────── */
.card {
    /* Frosted glass pane: translucent fill + backdrop blur + a light edge
       highlight, floating over the gradient canvas. */
    background: var(--glass-bg);
    -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-xl);
    padding: 20px;
    box-shadow: var(--glass-shadow);
    margin-bottom: var(--space-4);
}
.card.tight { padding: 14px; }

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin: 0 0 var(--space-3);
}
.card-header h2,
.card-header h3 { margin: 0; font-size: 14px; font-weight: 600; letter-spacing: -0.01em; font-family: var(--font-sans); }

.card-muted { color: var(--muted); font-size: 12px; }
.card-sub { color: var(--muted); font-size: 11.5px; }

.card-link {
    display: block;
    text-decoration: none;
    color: inherit;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.card-link:hover {
    border-color: var(--border-strong);
    box-shadow: var(--shadow-sm);
    color: inherit;
}

/* ─── Stat cards ───────────────────────────────────────── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    margin-bottom: var(--space-5);
}
.stat {
    background: var(--glass-bg);
    -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 14px 14px 16px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--glass-shadow);
    transition: transform 180ms cubic-bezier(.22,1,.36,1), box-shadow 180ms ease, border-color 180ms ease;
}
.stat:hover {
    transform: translateY(-3px);
    border-color: rgba(255, 255, 255, 0.9);
    box-shadow: var(--glass-shadow-lg);
}
.stat-label {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: var(--space-2);
}
.stat-value {
    font-family: var(--font-display);
    font-size: 28px;
    font-weight: 600;
    letter-spacing: -0.025em;
    color: var(--text);
    line-height: 1.05;
    font-variant-numeric: tabular-nums;
    font-feature-settings: "tnum";
}
.stat-sub {
    font-size: 11.5px;
    color: var(--muted);
    margin-top: 4px;
    font-weight: 500;
}

/* ─── Buttons ──────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 38px;
    padding: 8px 14px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.2;
    color: var(--text);
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius);
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, transform 0.05s ease;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
    white-space: nowrap;
}
.btn svg,
.btn .nav-icon {
    width: 16px;
    height: 16px;
    color: inherit;
    flex-shrink: 0;
}
.btn:hover {
    background: var(--surface-2);
    border-color: var(--border-stronger);
    color: var(--text);
    text-decoration: none;
}
.btn:active { background: var(--surface-2); transform: translateY(1px); }
.btn:focus-visible {
    outline: 0;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--ring);
}
.btn:disabled, .btn[disabled] {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
}

.btn-primary {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--on-accent);
}
.btn-primary:hover {
    background: var(--accent-deep);
    border-color: var(--accent-deep);
    color: var(--on-accent);
}
.btn-primary:active { background: var(--accent-deep); }
/* Filled buttons need a high-contrast ring — the faint --ring is invisible
   on the accent fill. A surface-coloured gap + solid ring reads on any bg. */
.btn-primary:focus-visible {
    box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--accent);
}

.btn-danger {
    color: var(--danger);
    background: var(--surface);
    border-color: var(--danger);
}
.btn-danger:hover {
    background: var(--danger-soft);
    color: var(--danger);
    border-color: var(--danger);
}
.btn-danger:focus-visible {
    border-color: var(--danger);
    box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--danger);
}

/* Secondary button: a real surface fill + border so it reads as a button
   (not bare text) anywhere it's used. For a text-only control use .btn-link. */
.btn-ghost {
    background: var(--surface-2);
    color: var(--text-2);
    border-color: var(--border);
}
.btn-ghost:hover {
    background: var(--surface-sunken);
    color: var(--text);
    border-color: var(--border-strong);
}

.btn-sm { min-height: 28px; padding: 4px 10px; font-size: 12px; border-radius: var(--radius-sm); }
.btn-lg { min-height: 42px; padding: 10px 16px; font-size: 14px; border-radius: var(--radius); }
.btn-block { display: flex; width: 100%; }

.btn-link {
    background: none;
    border: 0;
    padding: 0;
    color: var(--accent);
    font: inherit;
    cursor: pointer;
    min-height: 0;
}
.btn-link:hover { background: none; color: var(--accent-deep); text-decoration: underline; }

/* ─── Forms ────────────────────────────────────────────── */
.field { margin-bottom: var(--space-4); }
.field-label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-2);
    margin-bottom: 6px;
    letter-spacing: 0;
}
.field-required { color: var(--danger); }

.input,
.field input[type="text"],
.field input[type="email"],
.field input[type="password"],
.field input[type="number"],
.field input[type="url"],
.field input[type="search"],
.field input[type="tel"],
.field input[type="date"],
.field input[type="datetime-local"],
.field input[type="time"],
.field select,
.field textarea {
    display: block;
    width: 100%;
    min-height: 36px;
    padding: 8px 11px;
    font: inherit;
    font-size: 13px;
    line-height: 1.4;
    color: var(--text);
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius);
    outline: none;
    transition: border-color 0.12s ease, box-shadow 0.12s ease, background 0.12s ease;
    -webkit-appearance: none;
    appearance: none;
}
.field textarea { min-height: 88px; resize: vertical; line-height: 1.5; }
.field input::placeholder,
.field textarea::placeholder { color: var(--subtle); }

.field input:hover,
.field select:hover,
.field textarea:hover,
.input:hover { border-color: var(--border-stronger); }
.field input:focus,
.field select:focus,
.field textarea:focus,
.input:focus {
    border-color: var(--accent);
    background: var(--surface);
    box-shadow: 0 0 0 3px var(--ring), 0 4px 14px color-mix(in srgb, var(--accent) 18%, transparent);
}

.field select {
    appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'><path d='M1 1.5L6 6.5L11 1.5' stroke='%236B7280' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat: no-repeat;
    background-position: right 11px center;
    padding-right: 32px;
}

/* ─── Modern inputs everywhere in admin content ──────────
   Many admin pages render bare <input>/<select>/<textarea> that aren't
   wrapped in .field (search bars, status filters, inline forms), so they
   used to fall back to the browser default and looked inconsistent. Give
   every text-like control inside the main content area the same compact,
   modern styling — rounded, hairline-bordered, with a hover + focus ring. */
:is(main.content, main.cust-content) input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="hidden"]),
:is(main.content, main.cust-content) select,
:is(main.content, main.cust-content) textarea {
    font: inherit;
    font-size: 13px;
    line-height: 1.4;
    color: var(--text);
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius);
    min-height: 38px;
    padding: 9px 12px;
    outline: none;
    transition: border-color .12s ease, box-shadow .12s ease, background .12s ease;
    -webkit-appearance: none;
    appearance: none;
}
:is(main.content, main.cust-content) textarea { min-height: 88px; resize: vertical; line-height: 1.5; }
:is(main.content, main.cust-content) input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):hover,
:is(main.content, main.cust-content) select:hover,
:is(main.content, main.cust-content) textarea:hover { border-color: var(--border-stronger); }
:is(main.content, main.cust-content) input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):focus,
:is(main.content, main.cust-content) select:focus,
:is(main.content, main.cust-content) textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--ring);
}
:is(main.content, main.cust-content) select {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'><path d='M1 1.5L6 6.5L11 1.5' stroke='%236B7280' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat: no-repeat;
    background-position: right 11px center;
    padding-right: 32px;
}
:is(main.content, main.cust-content) input::placeholder,
:is(main.content, main.cust-content) textarea::placeholder { color: var(--subtle); }

/* Global overflow guard: a form control must never force its flex/grid parent
   wider than the viewport. A <select> whose longest <option> is wide (e.g. a
   long form title or client name) would otherwise blow out the layout and zoom
   the whole page out on mobile. min-width:0 lets it shrink; it clips instead. */
:is(main.content, main.cust-content) :is(input, select, textarea) {
    min-width: 0;
    max-width: 100%;
}

.field-hint {
    font-size: 11.5px;
    color: var(--muted);
    margin-top: 6px;
}
.field-error {
    font-size: 11.5px;
    color: var(--danger);
    margin-top: 6px;
}

.field-row {
    display: grid;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
}
/* Grid items must be allowed to shrink below their content's intrinsic min
   width. Otherwise a control with wide content — e.g. a <select> holding a long
   <option> like "Long Client Name (Fiverr)" — forces its column wider than the
   viewport, overflowing the whole page (every card gets cut off on the right).
   minmax(0,1fr) tracks + min-width:0 items let the controls clip instead. */
.field-row > * { min-width: 0; }
@media (min-width: 640px) {
    .field-row-2 { grid-template-columns: minmax(0,1fr) minmax(0,1fr); }
    .field-row-3 { grid-template-columns: repeat(3, minmax(0,1fr)); }
    /* Asymmetric ratios for specific field groups (title+dropdown+number,
       a narrow label beside a wide textarea, etc.) that still need the
       same single-column-below-640px / min-width:0 protections as
       .field-row-2/-3 above -- added when plugin code was found hardcoding
       its own ad-hoc grid-template-columns with neither. */
    .field-row-title  { grid-template-columns: minmax(0,2fr) minmax(0,1fr) minmax(0,1fr); }
    .field-row-wide   { grid-template-columns: minmax(0,1fr) minmax(0,3fr); }
    .field-row-recipe { grid-template-columns: minmax(0,2fr) minmax(0,1fr); }
}

.field-row-variant {
    grid-template-columns: minmax(0,1fr) minmax(0,1fr);
}
@media (min-width: 640px) {
    .field-row-variant { grid-template-columns: repeat(3, minmax(0,1fr)); }
}
@media (min-width: 960px) {
    .field-row-variant { grid-template-columns: repeat(5, minmax(0,1fr)); }
}

/* ─── Filter bar ──────────────────────────────────────── */
.filter-row {
    display: grid;
    gap: var(--space-2);
    align-items: end;
    grid-template-columns: minmax(0, 1fr);
}
/* Items must shrink below their content's intrinsic min width, or a filter
   <select> holding a long option (e.g. a form title) forces its column wider
   than the viewport — zooming the whole page out on mobile. */
.filter-row > * { min-width: 0; }
@media (min-width: 640px) {
    .filter-row {
        grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
    }
    .filter-row .filter-search { grid-column: 1 / -1; }
    .filter-row .filter-actions {
        grid-column: 1 / -1;
        display: flex;
        gap: var(--space-2);
        justify-content: flex-end;
    }
}
@media (min-width: 960px) {
    .filter-row {
        grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr) auto;
    }
    .filter-row .filter-search { grid-column: auto; }
    .filter-row .filter-actions {
        grid-column: auto;
        justify-content: flex-start;
    }
}
.filter-row .field { margin: 0; }
/* Compact filter bar: tiny uppercase labels, shorter controls, less air. */
.filter-row .field-label {
    font-size: 10px; font-weight: 600; letter-spacing: 0.04em;
    text-transform: uppercase; color: var(--subtle); margin-bottom: 4px;
}
.filter-row .field input,
.filter-row .field select { min-height: 34px; padding: 7px 11px; font-size: 12.5px; }
.filter-row .field select { padding-right: 30px; }
/* The card that wraps a filter bar hugs its contents. */
.card.tight.filter-card,
.filter-card { padding: 12px 14px; margin-bottom: var(--space-3); }

/* ─── Toolbar ─────────────────────────────────────────── */
.toolbar {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
}
.toolbar > * { flex-shrink: 0; }
.toolbar-spacer { flex: 1; min-width: 0; }

/* ─── Split layout (main + side meta, back-compat) ───── */
.split-layout {
    display: grid;
    gap: 1rem;
    grid-template-columns: 1fr;
}
@media (min-width: 960px) {
    .split-layout {
        grid-template-columns: 2fr 1fr;
        align-items: flex-start;
    }
}

/* ─── Page layout w/ right rail (canonical, Phase 0+) ───
   slate_page_layout('with-aside') opens .page-layout
   .page-layout-with-aside. Inside, the primary column is
   <div class="page-main"> and the rail is
   <aside class="page-aside"> holding .aside-card blocks. */
.page-layout { display: block; }
.page-layout-with-aside {
    display: grid;
    gap: var(--space-6);
    grid-template-columns: 1fr;
    align-items: flex-start;
}
@media (min-width: 1024px) {
    .page-layout-with-aside {
        grid-template-columns: minmax(0, 1fr) var(--right-rail-width);
    }
}
.page-main { min-width: 0; }
.page-aside {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}
.aside-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 16px;
    box-shadow: none;
    min-width: 0;
    overflow: hidden;
}

/* ─── Overflow containment ────────────────────────────────
   body{overflow-x:hidden} clips the page, so any child wider than the
   viewport (long URLs, code/iframe snippets, wide tables) gets visually
   *cut off* instead of wrapping. Keep wide content inside its column. */
:is(main.content, main.cust-content) { min-width: 0; max-width: 100%; }
.page-layout, .page-layout-with-aside, .page-main, .page-aside, .card { min-width: 0; }
:is(main.content, main.cust-content) pre,
:is(main.content, main.cust-content) .snippet {
    max-width: 100%;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
    overflow-x: auto;
}
:is(main.content, main.cust-content) code { overflow-wrap: anywhere; word-break: break-word; }
:is(main.content, main.cust-content) .table-wrap { max-width: 100%; overflow-x: auto; }
.aside-card-title {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--subtle);
    margin: 0 0 var(--space-3);
}

/* ─── KV list (label/value pairs, hairline dividers) ─── */
.kv-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
}
.kv-row {
    display: flex;
    justify-content: space-between;
    gap: var(--space-4);
    padding: 9px 0;
    border-bottom: 1px solid var(--border);
    font-size: 12.5px;
    line-height: 1.45;
}
.kv-row:first-child { padding-top: 0; }
.kv-row:last-child  { border-bottom: 0; padding-bottom: 0; }
.kv-row .kv-label {
    color: var(--muted);
    font-weight: 500;
    flex-shrink: 0;
}
.kv-row .kv-value {
    color: var(--text);
    text-align: right;
    min-width: 0;
    overflow-wrap: break-word;
    word-break: break-word;
}
.kv-row .kv-value.kv-mono {
    font-family: var(--font-mono);
    font-size: 12px;
}
/* Opt-in modifier for a row whose value is a real phrase rather than a short
   badge/number/arrow: label always keeps flex-shrink:0, so a long label
   paired with a long value otherwise squeezes the value into a near-zero
   width column, where word-break:break-word wraps it one character per
   line. Stacking the value below the label sidesteps that instead of
   fighting it. */
.kv-row.kv-row-stack {
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
}
.kv-row.kv-row-stack .kv-value {
    text-align: left;
}

/* ─── Audit trail (vertical timeline) ─────────────────── */
.audit-trail {
    list-style: none;
    margin: 0;
    padding: 0;
    position: relative;
}
.audit-trail::before {
    content: '';
    position: absolute;
    left: 5px;
    top: 8px;
    bottom: 8px;
    width: 1px;
    background: var(--border-strong);
}
.audit-trail-item {
    position: relative;
    padding: 0 0 14px 22px;
}
.audit-trail-item:last-child { padding-bottom: 0; }
.audit-trail-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 4px;
    width: 11px;
    height: 11px;
    border-radius: 999px;
    background: var(--surface);
    border: 2px solid var(--accent);
    box-sizing: border-box;
}
.audit-trail-item.is-muted::before { border-color: var(--faint); }
.audit-trail-action {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.35;
}
.audit-trail-meta {
    font-size: 11.5px;
    color: var(--muted);
    margin-top: 2px;
    line-height: 1.4;
}
.audit-trail-detail {
    font-size: 12px;
    color: var(--text-2);
    margin-top: 4px;
    line-height: 1.5;
}

/* ─── Tables ──────────────────────────────────────────── */
.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin: 0 calc(-1 * 18px);
    padding: 0 18px;
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table th,
.table td {
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.table th {
    font-weight: 600;
    font-size: 10.5px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    background: var(--surface-2);
}
.table th:first-child { border-top-left-radius: var(--radius-sm); }
.table th:last-child  { border-top-right-radius: var(--radius-sm); }
.table tbody tr:hover { background: var(--surface-2); }
.table tbody tr:last-child td { border-bottom: 0; }

/* ─── Alerts ──────────────────────────────────────────── */
.alert {
    display: flex;
    gap: var(--space-3);
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: var(--space-4);
    font-size: 13px;
    line-height: 1.5;
    align-items: flex-start;
}
.alert-success { background: var(--success-soft); border-color: rgba(22,163,74,0.18); color: #14532D; }
.alert-error   { background: var(--danger-soft);  border-color: rgba(220,38,38,0.20);  color: #7F1D1D; }
.alert-warning { background: var(--warning-soft); border-color: rgba(217,119,6,0.22);  color: #78350F; }
.alert-info    { background: var(--info-soft);    border-color: rgba(37,99,235,0.18);  color: #1E3A8A; }

/* ─── Badges ──────────────────────────────────────────── */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    font-size: 10.5px;
    font-weight: 500;
    line-height: 1.5;
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-2);
    border: 1px solid var(--border);
}
.badge .dot { width: 5px; height: 5px; border-radius: 999px; background: currentColor; }
.badge-active    { background: var(--success-soft); color: #15803D;    border-color: transparent; }
.badge-inactive  { background: var(--surface-2);    color: var(--muted); border-color: transparent; }
.badge-installed { background: var(--info-soft);    color: #1D4ED8;    border-color: transparent; }
.badge-warning   { background: var(--warning-soft); color: #B45309;    border-color: transparent; }
.badge-danger    { background: var(--danger-soft);  color: #B91C1C;    border-color: transparent; }
.badge-accent    { background: var(--accent-soft);  color: var(--accent-deep); border-color: transparent; }

/* Mono pill tag */
.tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    font-family: var(--font-mono);
    font-size: 10.5px;
    background: var(--surface);
    color: var(--text-2);
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
}
.tag.danger { color: var(--danger); }
.tag.ok { color: var(--success); }

/* ─── Segmented control ───────────────────────────────────
   <div class="segmented">
     <a class="segmented-item is-active">Week</a> …
   </div>  (use <button>/<a>/<label>) */
.segmented {
    display: inline-flex; align-items: center;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px; padding: 3px; gap: 2px;
    vertical-align: middle;
}
.segmented-item {
    appearance: none; border: 0; background: transparent;
    padding: 6px 14px; border-radius: 7px;
    font: inherit; font-size: 12.5px; font-weight: 500;
    color: var(--muted); cursor: pointer; text-decoration: none;
    white-space: nowrap; line-height: 1.2;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background .12s, color .12s, box-shadow .12s;
}
.segmented-item:hover { color: var(--text-2); }
.segmented-item.is-active,
.segmented-item[aria-selected="true"] {
    background: var(--surface); color: var(--text);
    box-shadow: 0 1px 2px rgba(15,17,23,0.06);
}
.segmented-item svg { width: 16px; height: 16px; }
/* Icon-only variant (e.g. grid/list view toggle) */
.segmented.segmented-icons .segmented-item { padding: 6px 9px; }

/* ─── Chips (filter pills) ────────────────────────────────
   <div class="chip-group"><a class="chip is-active">All</a> …</div> */
.chip-group { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 13px; border-radius: 999px;
    border: 1px solid var(--border-strong); background: var(--surface);
    font: inherit; font-size: 12.5px; font-weight: 500; color: var(--text-2);
    cursor: pointer; text-decoration: none; line-height: 1.2;
    transition: border-color .12s, background .12s, color .12s;
}
.chip:hover { border-color: var(--border-stronger); color: var(--text); }
.chip.is-active {
    background: var(--accent-soft); border-color: transparent; color: var(--accent-deep);
}
.chip-x {
    display: inline-flex; width: 14px; height: 14px;
    align-items: center; justify-content: center; opacity: .7;
}
.chip-x:hover { opacity: 1; }

/* ─── Switch (iOS-style toggle) ───────────────────────────
   <label class="switch"><input type="checkbox"><span class="switch-track"></span></label> */
.switch { position: relative; display: inline-flex; align-items: center; flex: none; }
.switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.switch-track {
    width: 42px; height: 24px; border-radius: 999px;
    background: var(--faint); transition: background .15s ease; position: relative;
    flex: none; cursor: pointer;
}
.switch-track::after {
    content: ""; position: absolute; top: 2px; left: 2px;
    width: 20px; height: 20px; border-radius: 999px; background: #fff;
    box-shadow: 0 1px 3px rgba(15,17,23,0.25); transition: transform .15s ease;
}
.switch input:checked + .switch-track { background: var(--accent); }
.switch input:checked + .switch-track::after { transform: translateX(18px); }
.switch input:focus-visible + .switch-track { box-shadow: 0 0 0 3px var(--ring); }
.switch input:disabled + .switch-track { opacity: .5; cursor: not-allowed; }
.switch-label { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; }

/* ─── Collapsible filter bar ──────────────────────────────
   A filter card auto-collapses behind a compact "Filters" toggle
   (see footer JS). Collapsed = just the toggle row; expanded = fields. */
.card.is-filter { padding: 0; overflow: hidden; margin-bottom: var(--space-3); }
.filter-toggle {
    display: flex; align-items: center; gap: 8px; width: 100%;
    padding: 10px 14px; border: 0; background: transparent;
    font: inherit; font-size: 12.5px; font-weight: 600; color: var(--text-2);
    cursor: pointer; text-align: left;
}
.filter-toggle:hover { background: var(--surface-2); }
.filter-toggle > .filter-ico { width: 15px; height: 15px; color: var(--muted); flex: none; }
.filter-toggle .filter-count {
    background: var(--accent); color: #fff; border-radius: 999px;
    min-width: 17px; height: 17px; padding: 0 5px; font-size: 10px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
}
.filter-toggle .filter-caret { margin-left: auto; color: var(--subtle); transition: transform .15s ease; flex: none; }
.filter-toggle[aria-expanded="true"] .filter-caret { transform: rotate(180deg); }
.card.is-filter .filter-row { padding: 4px 14px 14px; }
.card.is-filter .filter-row[hidden] { display: none; }

/* ─── Page header ─────────────────────────────────────── */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3) var(--space-4);
    margin-bottom: var(--space-3);
    flex-wrap: wrap;
}
.page-header h1 { margin: 0; font-family: var(--font-display); }
.page-header-sub {
    color: var(--muted);
    margin: 2px 0 0;
    font-size: 12.5px;
}

/* ─── Pagination ──────────────────────────────────────── */
.slate-pager {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px;
    margin-top: var(--space-4);
}
.slate-pager-summary { font-size: 12px; color: var(--muted); }
.slate-pager-pages { display: inline-flex; align-items: center; gap: 4px; margin-left: auto; }
.slate-pager-btn {
    min-width: 32px; height: 32px; padding: 0 8px;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--surface); color: var(--text-2);
    font-size: 12.5px; font-weight: 500; text-decoration: none;
    font-variant-numeric: tabular-nums;
    transition: border-color .12s, background .12s, color .12s;
}
.slate-pager-btn:hover { border-color: var(--border-stronger); background: var(--surface-2); color: var(--text); }
.slate-pager-btn.is-current { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
.slate-pager-btn.is-disabled { opacity: .4; pointer-events: none; }
.slate-pager-gap { padding: 0 2px; color: var(--subtle); font-size: 12.5px; }
@media (max-width: 767px) {
    .slate-pager { justify-content: center; }
    .slate-pager-pages { margin: 0 auto; }
    .slate-pager-summary { width: 100%; text-align: center; }
}

/* ─── Empty state ─────────────────────────────────────── */
.empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
}
.empty-icon {
    width: 44px;
    height: 44px;
    margin: 0 auto 10px;
    border-radius: var(--radius-lg);
    background: var(--surface-2);
    color: var(--muted);
    display: grid;
    place-items: center;
    border: 1px solid var(--border);
}
.empty-icon svg { width: 22px; height: 22px; opacity: 0.6; }
.empty-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
}

/* (A second, conflicting .segmented definition used to live here — its base
   rule clashed with the canonical .segmented / .segmented-item above. Removed.) */

/* ─── Dropzone ────────────────────────────────────────── */
.dropzone {
    background: var(--surface);
    border-radius: var(--radius-lg);
    padding: 24px 16px;
    text-align: center;
    border: 1px dashed var(--border-stronger);
    cursor: pointer;
    transition: border-color 160ms ease, background 160ms ease;
}
.dropzone:hover { border-color: var(--accent); background: var(--accent-soft); }

/* ─── Snippet (code block) ────────────────────────────── */
.snippet {
    font-family: var(--font-mono);
    font-size: 12px;
    background: #0E1117;
    color: #D8DAE0;
    padding: 12px 14px;
    border-radius: var(--radius);
    line-height: 1.55;
    overflow: auto;
    margin: 0;
    border: 1px solid #1F2329;
}

/* The canonical switch component is the iOS-style .switch / .switch-track
   defined above. (A second, conflicting .switch definition used to live here
   and broke every real toggle — removed.) */

/* ─── Utilities ───────────────────────────────────────── */
.text-muted   { color: var(--muted); }
.text-sub     { color: var(--text-2); }
.text-danger  { color: var(--danger); }
.text-success { color: var(--success); }
.text-warning { color: var(--warning); }
.text-accent  { color: var(--accent); }
.text-sm      { font-size: 12.5px; }
.text-xs      { font-size: 11.5px; }
.text-right   { text-align: right; }
.text-center  { text-align: center; }
.text-mono    { font-family: var(--font-mono); font-size: 12px; }

.eyebrow {
    font-size: 11px;
    letter-spacing: 0.06em;
    color: var(--subtle);
    font-weight: 600;
    text-transform: uppercase;
}

.flex         { display: flex; }
.flex-col     { display: flex; flex-direction: column; }
.flex-between { display: flex; align-items: center; justify-content: space-between; }
.flex-center  { display: flex; align-items: center; justify-content: center; }
.items-center { align-items: center; }
.gap-1 { gap: var(--space-1); }
.gap-2 { gap: var(--space-2); }
.gap-3 { gap: var(--space-3); }
.gap-4 { gap: var(--space-4); }

.mt-1 { margin-top: var(--space-1); }
.mt-2 { margin-top: var(--space-2); }
.mt-3 { margin-top: var(--space-3); }
.mt-4 { margin-top: var(--space-4); }
.mt-6 { margin-top: var(--space-6); }
.mb-1 { margin-bottom: var(--space-1); }
.mb-2 { margin-bottom: var(--space-2); }
.mb-3 { margin-bottom: var(--space-3); }
.mb-4 { margin-bottom: var(--space-4); }
.mb-6 { margin-bottom: var(--space-6); }

.hidden { display: none; }
.w-full { width: 100%; }

@media (max-width: 767px) { .hide-mobile { display: none !important; } }
@media (min-width: 768px) { .show-mobile { display: none !important; } }

/* ─── Flash (sticky page-top notification) ───────────── */
.flash {
    position: sticky;
    top: var(--topbar-height);
    z-index: 20;
}

/* The skip link is defined once in includes/a11y_head.php (emitted after this
   stylesheet), so it owns the canonical styling. Duplicate removed here. */

/* ─── Data list (collapsible card-rows) ──────────────── */
.data-list {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    background: var(--surface);
    width: 100%;
}

.data-row {
    background: var(--surface);
    border-radius: 0;
    overflow: hidden;
    transition: background 0.12s ease;
    width: 100%;
}
.data-row:not(:first-child) { border-top: 1px solid var(--border); }
.data-row.is-open { background: var(--surface-2); }

.data-row-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    padding: 12px 14px;
    background: transparent;
    border: 0;
    font: inherit;
    color: var(--text);
    text-align: left;
    cursor: pointer;
    min-height: 56px;
    -webkit-tap-highlight-color: transparent;
}
.data-row-summary:hover { background: var(--surface-2); }
.data-row.is-open .data-row-summary { background: transparent; }

.data-row-avatar {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: var(--radius);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 11.5px;
    letter-spacing: 0.02em;
    background: var(--accent-soft);
    color: var(--accent-deep);
    text-transform: uppercase;
}
.data-row-avatar.is-success { background: var(--success-soft); color: #14532D; }
.data-row-avatar.is-warning { background: var(--warning-soft); color: #78350F; }
.data-row-avatar.is-danger  { background: var(--danger-soft);  color: #7F1D1D; }
.data-row-avatar.is-info    { background: var(--info-soft);    color: #1E3A8A; }
.data-row-avatar.is-muted   { background: var(--surface-2);    color: var(--muted); }
.data-row-avatar.has-icon   { width: 38px; height: 38px; }
/* color:inherit overrides .nav-icon's sidebar colour so the glyph shows in
   the avatar's tone colour (is-info/is-warning/…) instead of being invisible. */
.data-row-avatar.has-icon svg { width: 19px; height: 19px; display: block; color: inherit; }
/* Photo overlay variant (initials underneath, an <img> layered on top --
   see slate_avatar_overlay_html() in includes/helpers.php). Mirrors
   .dlist-ava.has-media below; .has-icon above stays SVG-icon-only so
   existing callers (Forms, Coaching feed) are unaffected. */
.data-row-avatar.has-media { position: relative; overflow: hidden; width: 38px; height: 38px; }
.data-row-avatar.has-media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }

.data-row-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}
.data-row-title {
    font-weight: 600;
    font-size: 13.5px;
    color: var(--text);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.data-row-meta {
    font-size: 12px;
    color: var(--muted);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.data-row-value {
    flex-shrink: 0;
    padding: 2px 10px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 500;
    color: var(--text-2);
    white-space: nowrap;
}

.data-row-summary > .badge {
    flex-shrink: 0;
    white-space: nowrap;
}

.data-row-chevron {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    color: var(--subtle);
    transition: transform 0.22s cubic-bezier(.4,0,.2,1), color 0.12s;
}
.data-row.is-open .data-row-chevron {
    transform: rotate(90deg);
    color: var(--text-2);
}

.data-row-detail {
    border-top: 1px solid var(--border);
    padding: 14px 18px 16px 60px;
    background: var(--surface-2);
}
.data-row-detail[hidden] { display: none; }

.data-row-grid {
    margin: 0;
    display: grid;
    gap: 12px 24px;
    /* Auto-fit compact columns so wide detail panels fill end-to-end instead
       of leaving two very wide half-empty columns. */
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}
.data-row-grid > div { min-width: 0; }
.data-row-grid dt {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 3px;
}
.data-row-grid dd {
    margin: 0;
    font-size: 12.5px;
    color: var(--text);
    overflow-wrap: break-word;
    word-break: break-word;
}
.data-row-grid dd.is-muted { color: var(--muted); }

.data-row-progress {
    margin-top: 6px;
    height: 4px;
    background: var(--surface-2);
    border-radius: 999px;
    overflow: hidden;
}
.data-row-progress-fill {
    height: 100%;
    background: var(--accent);
    border-radius: inherit;
    transition: width 0.3s ease;
}

.data-row-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid var(--border);
}

/* Phones: drop the avatar-aligned indent, stack detail into 2 columns, and
   give action buttons real tap targets (2-up rows, wrapping inline forms). */
@media (max-width: 640px) {
    .data-row-detail { padding: 14px 16px 16px; }
    .data-row-grid { grid-template-columns: 1fr 1fr; gap: 10px 16px; }
    .data-row-actions { gap: 8px; }
    .data-row-actions > form { display: contents; }
    .data-row-actions .btn { flex: 1 1 calc(50% - 4px); justify-content: center; min-height: 38px; }
}
@media (max-width: 380px) {
    .data-row-grid { grid-template-columns: 1fr; }
    .data-row-actions .btn { flex-basis: 100%; }
}

/* Optional selectable + columnar variant (opt-in: .has-select rows, .data-list-head,
   .is-columnar list). Fixed value/badge widths keep header labels above their cells.
   Nothing here applies to lists that don't use these classes. */
.data-row { position: relative; }
.data-row-check { display: grid; place-items: center; width: 18px; flex: none; margin: 0; cursor: pointer; }
.data-row-check input { margin: 0; cursor: pointer; }
.data-row.has-select > .data-row-check { position: absolute; left: 14px; top: 28px; transform: translateY(-50%); z-index: 1; }
.data-row.has-select > .data-row-summary { padding-left: 44px; }
.data-row.is-selected { background: var(--accent-soft, var(--surface-2)); }
.data-list-head {
    display: flex; align-items: center; gap: 12px;
    padding: 9px 14px;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--muted);
}
.data-list-head-main { flex: 1; min-width: 0; padding-left: 0; }
.data-list.is-columnar .data-row-value,
.data-list-head-value { flex: 0 0 96px; width: 96px; text-align: center; overflow: hidden; text-overflow: ellipsis; }
.data-list.is-columnar .data-row-summary > .badge,
.data-list-head-badge { flex: 0 0 92px; width: 92px; text-align: center; justify-content: center; }
.data-list-head-chevron { flex: 0 0 18px; }
.data-list-selectbar {
    display: none; align-items: center; gap: 8px; padding: 10px 14px;
    background: var(--accent-soft, var(--surface-2)); border-bottom: 1px solid var(--border);
    font-size: 13px; font-weight: 600;
}
.data-list-selectbar.is-active { display: flex; }
@media (max-width: 640px) {
    .data-list-head-value { display: none; }
    .data-list-head { padding: 9px 12px; }
    .data-row.has-select > .data-row-check { left: 12px; }
    .data-row.has-select > .data-row-summary { padding-left: 42px; }
    .data-list.is-columnar .data-row-summary > .badge,
    .data-list-head-badge { flex-basis: auto; width: auto; }
    .data-list-head-badge { display: none; }
}

.data-list .empty {
    background: var(--surface);
}

@media (max-width: 960px) {
    .card-header {
        flex-wrap: wrap;
        row-gap: var(--space-2);
    }
    .card-header .flex,
    .card-header .toolbar {
        flex-wrap: wrap;
    }
    .page-header .flex.gap-2,
    .page-header .toolbar {
        flex-wrap: wrap;
    }
}

@media (max-width: 640px) {
    .data-row-summary { gap: var(--space-2); padding: 12px; }
    .data-row-value { display: none; }
    .data-row-grid { grid-template-columns: 1fr 1fr; }
    .data-row-detail { padding: 12px 14px 14px; }

    .card-header .flex,
    .card-header .toolbar {
        width: 100%;
    }
    .page-header .flex.gap-2,
    .page-header .toolbar {
        width: 100%;
    }

    .data-row-summary .badge {
        font-size: 10px;
        padding: 2px 7px;
        flex-shrink: 0;
    }

    .data-row-detail { padding-left: 14px; }
}
@media (max-width: 380px) {
    .data-row-meta { font-size: 11px; }
    .data-row-grid { grid-template-columns: 1fr; }
    .data-row-summary .badge {
        font-size: 9.5px;
        padding: 1px 5px;
    }
}

/* ═══════════════════════════════════════════════════════
   Mobile — app-style layout (≤767px)
   Full-width touch targets, stacked headers/filters, no
   horizontal overflow, comfortable tap sizes.
   ═══════════════════════════════════════════════════════ */
@media (max-width: 767px) {
    h1 { font-size: 19px; font-weight: 600; line-height: 1.25; }
    h2 { font-size: 15px; }
    .stat-value { font-size: 22px; }
    .card { border-radius: 14px; padding: 14px; margin-bottom: var(--space-3); }

    /* Page header: title stacks above a full-width action row. */
    .page-header { flex-direction: column; align-items: stretch; gap: 10px; }
    .page-header > div:first-child { min-width: 0; }
    .page-header .toolbar,
    .page-header .flex,
    .page-header form { width: 100%; }
    /* Primary call-to-action spans the width like a native app button. */
    .page-header .btn-primary,
    .page-header .toolbar > .btn:only-child,
    .page-header > .btn:last-child { width: 100%; justify-content: center; }

    /* Buttons + inputs: tidy, not chunky. */
    .btn { min-height: 40px; padding: 9px 14px; font-size: 13px; border-radius: 9px; }
    .btn-lg { min-height: 46px; padding: 12px 16px; font-size: 14px; }
    .btn-sm { min-height: 30px; padding: 6px 11px; font-size: 12px; }
    :is(main.content, main.cust-content) input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]),
    :is(main.content, main.cust-content) select,
    :is(main.content, main.cust-content) textarea {
        min-height: 44px; padding: 11px 12px; font-size: 15px; border-radius: 10px;
    }

    /* Filter / search bars: stack into a clean app-style column. The search
       field goes full width, secondary controls (status, Filter) sit below. */
    .toolbar { flex-wrap: wrap; gap: 8px; }
    .toolbar > * { flex: 1 1 auto; }
    :is(main.content, main.cust-content) form[style*="flex"] { flex-wrap: wrap; gap: 8px !important; }
    :is(main.content, main.cust-content) form[style*="flex"] > input[type="text"],
    :is(main.content, main.cust-content) form[style*="flex"] > input[type="search"],
    :is(main.content, main.cust-content) form[style*="flex"] > .field { flex: 1 1 100% !important; min-width: 0; }
    .filter-row { gap: 8px; }
    .filter-row .filter-actions { justify-content: stretch; }
    .filter-row .filter-actions .btn { flex: 1 1 auto; }

    /* Collapsible list rows: bigger tap area, tidy detail grid. */
    .data-row-summary { min-height: 56px; padding: 12px 14px; }
    .data-row-grid { grid-template-columns: 1fr; }
    .data-row-actions { flex-wrap: wrap; }
    .data-row-actions .btn { flex: 1 1 auto; }

    /* Key/value detail rows stack label over value. */
    .kv-row .kv-value { text-align: left; }
    .kv-row { flex-direction: column; gap: 2px; }

    /* Two-up field rows collapse to one column. minmax(0,1fr) (not 1fr) so a
       wide control like a long-option <select> can't force horizontal overflow. */
    .field-row-2, .field-row-3, .field-row-variant { grid-template-columns: minmax(0,1fr); }

    /* Stat/summary card grids go single-column for readability. */
    .stat-grid, .grid-3, .grid-2 { grid-template-columns: 1fr !important; }

    /* Prevent iOS Safari's zoom-on-focus: text controls need a ≥16px font. */
    .field input:not([type=checkbox]):not([type=radio]):not([type=color]),
    .field select, .field textarea, .input { font-size: 16px; }
}


/* ─── Stat/KPI cards with an optional sparkline ──────────────────
   Promoted out of admin/index.php (the main dashboard's own KPI cards)
   into the shared design system so any plugin's dashboard can use the
   same glass stat-card look — see slate_stat_card() / slate_sparkline_svg()
   below. admin/index.php was the first caller migrated onto this; Booking's
   dashboard (plugins/booking/admin/index.php) is the newest. ─────────── */
/* auto-fit: 3-4 across on wide screens, 2 on tablets, 1 on phones — no
   awkward empty cards because the number is pushed to the right edge. */
.dash-stats {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px; margin-bottom: 20px;
}
.dash-stat {
    position: relative; overflow: hidden;
    border: 1px solid var(--glass-border); border-radius: 16px;
    background: var(--glass-bg);
    -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    box-shadow: var(--glass-shadow);
    padding: 20px 22px;
    transition: transform .18s cubic-bezier(.22,1,.36,1), box-shadow .18s ease, border-color .18s ease;
}
.dash-stat:hover {
    transform: translateY(-3px);
    border-color: rgba(255,255,255,0.9);
    box-shadow: var(--glass-shadow-lg), var(--glow-accent);
}
/* badge (left) + number (right) on one row → fills the card width */
.dash-stat-top {
    position: relative; z-index: 1;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.dash-stat-badge {
    flex: none; width: 44px; height: 44px; border-radius: 13px;
    display: grid; place-items: center;
    background: color-mix(in srgb, var(--accent) 15%, rgba(255,255,255,0.45));
    border: 1px solid color-mix(in srgb, var(--accent) 24%, transparent);
    color: var(--accent);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.5);
}
.dash-stat-badge svg { width: 20px; height: 20px; color: var(--accent); }
.dash-stat-num {
    font-family: var(--font-display);
    font-size: 40px; font-weight: 700; letter-spacing: -0.045em; line-height: 1;
    color: var(--text); font-variant-numeric: tabular-nums;
    /* Same flex-shrink gap as .fb2-canvas/.fb2-side elsewhere in this
       codebase: a flex item's default min-width is its own content size,
       so without this a long value pushes the row (and the card) wider
       instead of shrinking/wrapping within it. */
    min-width: 0;
    text-align: right;
}
/* Longer content (e.g. a formatted currency amount) at length-based
   tiers -- see slate_stat_card()'s $numCls. Sized down enough to fit a
   typical card's inner width instead of overflowing it. */
.dash-stat-num.dash-stat-num--md { font-size: 26px; letter-spacing: -0.02em; }
.dash-stat-num.dash-stat-num--sm { font-size: 20px; letter-spacing: -0.01em; }
/* label with a small accent marker — the single restrained spot of colour */
.dash-stat-k {
    position: relative; z-index: 1; margin-top: 18px;
    display: flex; align-items: center; gap: 8px;
    font-family: var(--font-mono); font-size: 10.5px; letter-spacing: 0.12em;
    text-transform: uppercase; color: var(--muted); font-weight: 600;
}
.dash-stat-k::before {
    content: ""; flex: none; width: 14px; height: 2px; border-radius: 2px;
    background: var(--accent); opacity: .9;
}
.dash-stat-s { position: relative; z-index: 1; font-size: 12px; color: var(--subtle); margin-top: 4px; padding-left: 22px; }
/* whisper-faint neutral watermark for subtle depth (no colour) — mutually
   exclusive with a sparkline (a card shows one or the other, never both). */
.dash-stat-wm { position: absolute; right: -14px; bottom: -24px; opacity: .035; pointer-events: none; color: var(--text); }
.dash-stat-wm svg { width: 104px; height: 104px; color: var(--text); }
/* trend sparkline, bottom-right corner of the card, sitting quietly behind
   the label row rather than fighting the headline number for attention. */
.dash-stat-spark { position: absolute; right: 14px; bottom: 14px; opacity: .9; pointer-events: none; }

/* Colour variants for the badge — a stat card can be tinted a semantic
   colour (revenue=success, alerts=danger, ...) instead of the accent. */
.dash-stat.is-success .dash-stat-badge,
.dash-stat.is-success .dash-stat-k::before { color: var(--success); background: color-mix(in srgb, var(--success) 15%, rgba(255,255,255,0.45)); border-color: color-mix(in srgb, var(--success) 24%, transparent); }
.dash-stat.is-success .dash-stat-k::before { background: var(--success); }
.dash-stat.is-success .dash-stat-badge svg { color: var(--success); }

@media (max-width: 480px) {
    .dash-stat-spark { display: none; }
}

/* Smooth momentum scrolling for any intentionally-scrollable strip. */
.table-wrap, .topbar-pop-body, .snippet { -webkit-overflow-scrolling: touch; }

/* ─── Settings tab strip ─────────────────────────────────────
   Moved here from admin/settings.php so slate_render_settings_tabs()
   (below) renders identically wherever it's called from -- settings.php
   itself, or a settings-adjacent page like the Translations plugin that
   needs the same strip with itself marked active. */
.settings-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 1.5px solid var(--border);
    margin-bottom: 24px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    /* Hiding the scrollbar (above/below) removes the one native cue that
       there's more to scroll -- on a touch device with no visible
       scrollbar at all, a clipped tab at the edge (e.g. "Translations"
       cut down to "Tr...") just looks broken instead of "scroll for
       more". These four background layers are the standard scroll-shadow
       technique: two gradients scroll WITH the content via
       background-attachment:local (so they mask right up to the true
       edge and vanish once you've scrolled past it), the other two are
       fixed-position radial shadows that only peek out from behind that
       mask while there's still unscrolled content in that direction. */
    background:
        linear-gradient(to right, var(--bg) 0, transparent 24px) left / 32px 100% local,
        linear-gradient(to left,  var(--bg) 0, transparent 24px) right / 32px 100% local,
        radial-gradient(farthest-side at 0 50%,   rgba(0,0,0,.12), transparent) left / 14px 100% scroll,
        radial-gradient(farthest-side at 100% 50%, rgba(0,0,0,.12), transparent) right / 14px 100% scroll;
    background-repeat: no-repeat;
}
.settings-tabs::-webkit-scrollbar { display: none; }

.settings-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px 10px;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1.5px;
    white-space: nowrap;
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    transition: color 0.12s, border-color 0.12s, background 0.12s;
}
.settings-tab:hover {
    color: var(--text);
    background: var(--surface-2);
    text-decoration: none;
}
.settings-tab.is-active {
    color: var(--accent);
    border-bottom-color: var(--accent);
    font-weight: 600;
}
/* Generic svg selector, not a specific icon-helper's class name --
   slate_render_settings_tabs() feeds this via slate_admin_nav_icon()
   (class="nav-icon"), so pinning to that class's own name isn't needed
   and would be one more thing to keep in sync if the icon source changes.
   color:inherit is the important part: .nav-icon (admin/partials/header.php)
   hardcodes color:var(--sidebar-muted) for its native dark-sidebar context,
   which is a near-white, low-opacity color -- invisible against this
   light-background tab strip unless overridden back to inheriting the
   tab's own text color, same as .settings-tab's original bare .tab-icon
   (no explicit color, just currentColor from the parent) rendered before. */
.settings-tab svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    opacity: 0.75;
    color: inherit;
}
.settings-tab.is-active svg { opacity: 1; }
</style>
        <?php
    }
}
