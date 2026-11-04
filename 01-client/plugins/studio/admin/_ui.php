<?php
/**
 * Studio — small reusable admin list UI.
 *
 * Wraps the shared design-system primitives (`.data-list` + slate_data_row for
 * expandable rows, `.segmented` for tabs, `.filter-*` for the bar) and adds the
 * one piece the kit doesn't ship: client-side tab + search filtering over the
 * rows. Used by classes/enrollments/families so every Studio list looks and
 * behaves the same. No core files are modified.
 *
 * Usage:
 *   studio_ui_list_start($tabs, 'Search classes…');   // $tabs: [[value,label,count], …] ('all' first)
 *   foreach (...) studio_ui_row([... slate_data_row args ..., '_filter'=>'active', '_search'=>'ballet ryann']);
 *   studio_ui_list_end($rowCount, 'No classes yet');
 */

if (!function_exists('studio_gravatar_url')) {
    /** Gravatar URL (404 when none, so callers can fall back). '' for a bad email. */
    function studio_gravatar_url(string $email, int $size = 80): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) { return ''; }
        return 'https://www.gravatar.com/avatar/' . md5($email) . '?s=' . ($size * 2) . '&d=404';
    }
}

if (!function_exists('studio_initials')) {
    function studio_initials(string $name): string
    {
        $init = '';
        foreach (preg_split('/\s+/', trim($name)) as $w) {
            if ($w !== '') { $init .= mb_substr($w, 0, 1); }
            if (mb_strlen($init) >= 2) { break; }
        }
        return mb_strtoupper($init !== '' ? $init : '?');
    }
}

if (!function_exists('studio_avatar_media')) {
    /**
     * Raw HTML for a slate_data_row `avatar_html`: initials text (colored via the
     * row's avatar_color) with an image overlay that removes itself if it 404s.
     * Pass a Gravatar URL (people) or a featured-image URL (classes).
     */
    function studio_avatar_media(string $name, string $imageUrl = ''): string
    {
        $html = e(studio_initials($name));
        if ($imageUrl !== '') {
            $html .= '<img src="' . e($imageUrl) . '" alt="" loading="lazy" onerror="this.remove()">';
        }
        return $html;
    }
}

if (!function_exists('studio_image_field')) {
    /** A featured-image field: URL input + live preview + media-library browse. */
    function studio_image_field(string $name, string $current = '', string $label = 'Featured image', string $hint = ''): void
    {
        // The rules that size .studio-imgprev live in studio_ui_css(), which was
        // only ever called from studio_ui_list_start(). On an ?edit= page the
        // list never renders, so the preview had no CSS and the <img> drew at
        // its natural size — a full-width photo tearing the form apart.
        studio_ui_css();
        $id = 'img_' . preg_replace('/[^a-z0-9]/i', '', $name);
        ?>
        <div class="field">
            <label class="field-label" for="<?= e($id) ?>"><?= e($label) ?></label>
            <div class="studio-imgfield" data-studio-imgfield>
                <span class="studio-imgprev">
                    <?php if ($current !== ''): ?><img src="<?= e($current) ?>" alt=""><?php else: ?><?= function_exists('slate_icon') ? slate_icon('image', '') : '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>' ?><?php endif; ?>
                </span>
                <input type="url" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($current) ?>" placeholder="https://…">
                <button type="button" class="btn btn-sm studio-imgbrowse"><?= e(__('studio_browse', 'Browse')) ?></button>
            </div>
            <?php if ($hint !== ''): ?><div class="field-hint"><?= e($hint) ?></div><?php endif; ?>
        </div>
        <?php
        studio_image_field_script();
    }
}

if (!function_exists('studio_image_field_script')) {
    function studio_image_field_script(): void
    {
        if (defined('STUDIO_IMGFIELD_JS')) { return; }
        define('STUDIO_IMGFIELD_JS', true);
        ?>
        <script>
        (function () {
            function setUrl(wrap, u) {
                var input = wrap.querySelector('input[type=url]');
                var prev  = wrap.querySelector('.studio-imgprev');
                input.value = u;
                if (u) { prev.innerHTML = '<img alt="" src="' + u.replace(/"/g, '&quot;') + '">'; }
            }
            document.addEventListener('click', function (e) {
                var b = e.target.closest('.studio-imgbrowse'); if (!b) return;
                var wrap = b.closest('[data-studio-imgfield]'); if (!wrap) return;
                if (window.SlateMedia && SlateMedia.open) {
                    SlateMedia.open({ types: ['image'], multiple: false, onPick: function (item) {
                        var u = (item && (item.url || item.src)) || (typeof item === 'string' ? item : '');
                        if (u) setUrl(wrap, u);
                    }});
                } else {
                    var u = window.prompt('Image URL:'); if (u) setUrl(wrap, u.trim());
                }
            });
            document.addEventListener('input', function (e) {
                if (!e.target.matches('[data-studio-imgfield] input[type=url]')) return;
                var wrap = e.target.closest('[data-studio-imgfield]');
                var prev = wrap.querySelector('.studio-imgprev');
                var u = e.target.value.trim();
                if (u) { prev.innerHTML = '<img alt="" src="' + u.replace(/"/g, '&quot;') + '">'; }
            });
        })();
        </script>
        <?php
    }
}

if (!function_exists('studio_set_flash')) {
    /** Store a one-shot flash to survive the post→redirect→list hop. */
    function studio_set_flash(string $type, string $msg): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['studio_flash'] = ['type' => $type, 'msg' => $msg];
        }
    }
    /** Read + clear the one-shot flash. */
    function studio_take_flash(): ?array
    {
        $f = $_SESSION['studio_flash'] ?? null;
        unset($_SESSION['studio_flash']);
        return is_array($f) ? $f : null;
    }
}

if (!function_exists('studio_ui_css')) {
    function studio_ui_css(): void
    {
        if (defined('STUDIO_UI_CSS_EMITTED')) return;
        define('STUDIO_UI_CSS_EMITTED', true);
        ?>
        <style>
        .studio-section-heading { font-size: 15px; font-weight: 650; letter-spacing: -0.01em; margin: var(--space-5) 0 var(--space-3); }
        /* Image overlay on the shared data-row avatar (gravatar / featured image). */
        .data-row-avatar { position: relative; overflow: hidden; }
        .data-row-avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
        /* Inline image field (URL + preview + optional media browse). */
        .studio-imgfield { display: flex; align-items: center; gap: 12px; }
        .studio-imgfield .studio-imgprev {
            width: 52px; height: 52px; flex: none; border-radius: 10px; overflow: hidden;
            border: 1px solid var(--border); background: var(--surface-2);
            display: grid; place-items: center; color: var(--subtle);
        }
        .studio-imgfield .studio-imgprev img { width: 100%; height: 100%; object-fit: cover; }
        .studio-imgfield input[type="url"] { flex: 1; min-width: 0; }
        .studio-list { display: flex; flex-direction: column; gap: var(--space-3); }
        .studio-filterbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: var(--space-2); flex-wrap: wrap;
        }
        .studio-filterbar .segmented { flex-wrap: wrap; }
        .seg-count {
            font-size: 10.5px; font-weight: 600; line-height: 1;
            padding: 2px 6px; border-radius: 999px;
            background: var(--surface-sunken, var(--surface-2)); color: var(--muted);
            font-variant-numeric: tabular-nums;
        }
        .segmented-item.is-active .seg-count { background: var(--accent-soft); color: var(--accent-ink, var(--accent)); }
        .studio-search {
            flex: 1 1 200px; max-width: 280px; min-width: 0;
            border: 1px solid var(--border); border-radius: 10px;
            background: var(--surface); color: var(--text);
            font: inherit; font-size: 12.5px; padding: 8px 12px; min-height: 36px;
        }
        .studio-search:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--ring); }
        .studio-search::placeholder { color: var(--subtle); }
        .studio-empty {
            text-align: center; color: var(--muted); font-size: 13px;
            padding: 32px 20px; border: 1px dashed var(--border-stronger);
            border-radius: var(--radius-lg); background: var(--surface);
        }
        .studio-empty[hidden] { display: none; }
        .studio-empty svg.icon {
            width: 26px; height: 26px; color: var(--subtle); margin-bottom: 10px;
        }
        .studio-empty-t { font-size: 14px; font-weight: 650; color: var(--text); }
        .studio-empty-s { margin: 5px auto 0; max-width: 46ch; }
        .studio-empty .btn { margin-top: var(--space-3); }
        /* Attendance roster. Was built on .kv-row, which is a READ-ONLY detail
           list and deliberately stacks label-over-value on narrow screens — so
           the marking form became a name with a shrink-to-fit <select> dangling
           under it. A roster row keeps name and control side by side and gives
           the control a usable width at every size. */
        .studio-roster { display: flex; flex-direction: column; }
        .studio-roster-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: var(--space-4); padding: 10px 0; border-bottom: 1px solid var(--border);
        }
        .studio-roster-row:first-child { padding-top: 0; }
        .studio-roster-row:last-child  { border-bottom: 0; }
        .studio-roster-name { font-weight: 600; color: var(--text); min-width: 0; overflow-wrap: anywhere; }
        .studio-roster-row select { flex: 0 0 auto; min-width: 150px; }
        .studio-roster-foot {
            display: flex; justify-content: flex-end; gap: var(--space-2);
            margin-top: var(--space-4); padding-top: var(--space-4);
            border-top: 1px solid var(--border);
        }
        @media (max-width: 560px) {
            .studio-roster-row { gap: var(--space-3); }
            .studio-roster-row select { min-width: 0; width: 44%; }
            .studio-roster-foot .btn { flex: 1 1 auto; }
        }
        .studio-chip {
            display: inline-flex; align-items: center; gap: 4px; font-size: 12px; padding: 2px 8px; margin: 0 4px 4px 0;
            border-radius: 999px; background: var(--surface-2); border: 1px solid var(--border);
        }
        .studio-chip-x {
            border: 0; background: transparent; color: var(--muted); cursor: pointer;
            font-size: 14px; line-height: 1; padding: 0 1px; border-radius: 999px;
        }
        .studio-chip-x:hover { color: var(--danger); }
        /* Bulk selection */
        .studio-list.is-bulk .data-row { position: relative; }
        .studio-list.is-bulk .data-row-summary { padding-left: 42px; }
        /* Centred on the 56px summary, NOT on the row: `top:50%` put the box in
           the middle of an expanded row, floating over the detail grid (it
           landed on top of the "Allergies" label on the Students list). */
        .studio-check {
            position: absolute; left: 15px; top: 20px;
            width: 16px; height: 16px; z-index: 2; cursor: pointer; accent-color: var(--accent);
        }
        .studio-bulkbar {
            display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap;
            padding: 10px 14px; border: 1px solid var(--accent); border-radius: var(--radius-lg);
            background: var(--accent-soft);
        }
        .studio-bulkbar[hidden] { display: none; }
        .studio-selcount { font-size: 12.5px; font-weight: 600; color: var(--accent-deep, var(--accent)); }
        .studio-selall { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-2); cursor: pointer; }
        .studio-selall input { width: 15px; height: 15px; accent-color: var(--accent); }
        .studio-bulk-actions { display: inline-flex; gap: var(--space-2); margin-left: auto; flex-wrap: wrap; }
        </style>
        <?php
    }
}

if (!function_exists('studio_ui_list_start')) {
    /**
     * @param array $tabs  [['value'=>'all','label'=>'All','count'=>N], …]; empty = no tabs.
     * @param ?string $searchPlaceholder  null = no search box.
     * @param array $bulkActions  [['value'=>'delete','label'=>'Delete','danger'=>true,'confirm'=>'…'], …].
     *              When non-empty, the list becomes a POST <form> with per-row checkboxes and a
     *              bulk bar; buttons submit name="_do" value="bulk_<value>" with checked ids[].
     */
    function studio_ui_list_start(array $tabs = [], ?string $searchPlaceholder = 'Search…', array $bulkActions = []): void
    {
        studio_ui_css();
        $bulk = $bulkActions !== [];
        $GLOBALS['__studio_ui_bulk'] = $bulk;

        if ($bulk) {
            echo '<form method="post" class="studio-list is-bulk" data-studio-list>';
            echo function_exists('csrf_field') ? csrf_field() : '';
            echo '<div class="studio-bulkbar" hidden>';
            echo '<label class="studio-selall"><input type="checkbox" class="studio-check-all"> '
               . e(__('studio_select_all', 'Select all')) . '</label>';
            echo '<span class="studio-selcount">0 ' . e(__('studio_selected', 'selected')) . '</span>';
            echo '<span class="studio-bulk-actions">';
            foreach ($bulkActions as $ba) {
                $val     = (string)($ba['value'] ?? '');
                $label   = (string)($ba['label'] ?? ucfirst($val));
                $danger  = !empty($ba['danger']);
                $confirm = (string)($ba['confirm'] ?? '');
                echo '<button type="submit" name="_do" value="bulk_' . e($val) . '" class="btn btn-sm'
                   . ($danger ? ' btn-danger' : '') . '"'
                   . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
                   . e($label) . '</button>';
            }
            echo '</span></div>';
        } else {
            echo '<div class="studio-list" data-studio-list>';
        }

        if ($tabs || $searchPlaceholder !== null) {
            echo '<div class="studio-filterbar">';
            if ($tabs) {
                // ?tab= pre-selects a tab so the overview KPIs (and any bookmark)
                // can deep-link straight to e.g. the waitlist. Falls back to the
                // first tab when the value isn't one this list offers.
                $wantTab = (string)($_GET['tab'] ?? '');
                $values  = array_map(static fn ($t) => (string)($t['value'] ?? 'all'), $tabs);
                if (!in_array($wantTab, $values, true)) { $wantTab = $values[0] ?? 'all'; }

                echo '<div class="segmented" role="tablist">';
                foreach ($tabs as $t) {
                    $val   = (string)($t['value'] ?? 'all');
                    $label = (string)($t['label'] ?? ucfirst($val));
                    $count = $t['count'] ?? null;
                    $cls   = 'segmented-item' . ($val === $wantTab ? ' is-active' : '');
                    echo '<button type="button" class="' . $cls . '" data-filter-value="' . e($val) . '"'
                       . ' role="tab" aria-selected="' . ($val === $wantTab ? 'true' : 'false') . '">'
                       . e($label)
                       . ($count !== null ? ' <span class="seg-count">' . (int)$count . '</span>' : '')
                       . '</button>';
                }
                echo '</div>';
            }
            if ($searchPlaceholder !== null) {
                echo '<input type="search" class="studio-search" placeholder="' . e($searchPlaceholder)
                   . '" aria-label="' . e($searchPlaceholder) . '" autocomplete="off">';
            }
            echo '</div>';
        }
        echo '<div class="data-list">';
    }
}

if (!function_exists('studio_ui_row')) {
    /** slate_data_row + optional `_filter` / `_search` tags and (in bulk mode) an `_id` checkbox. */
    function studio_ui_row(array $args): void
    {
        $filter = strtolower((string)($args['_filter'] ?? 'all'));
        $search = strtolower((string)($args['_search'] ?? ''));
        $id     = (int)($args['_id'] ?? 0);
        unset($args['_filter'], $args['_search'], $args['_id']);

        ob_start();
        slate_data_row($args);
        $html = ob_get_clean();

        $open = '<article class="data-row" data-filter="' . e($filter) . '" data-search="' . e($search) . '">';
        if (!empty($GLOBALS['__studio_ui_bulk']) && $id > 0) {
            $open .= '<input type="checkbox" class="studio-check" name="ids[]" value="' . $id
                   . '" aria-label="' . e(__('studio_select_row', 'Select')) . '">';
        }
        echo str_replace('<article class="data-row">', $open, $html);
    }
}

if (!function_exists('studio_ui_list_end')) {
    /**
     * Closes the list and renders its two empty states.
     *
     * "Nothing exists yet" and "your filter matched nothing" are different
     * situations and were previously served by the same element — so searching
     * a populated Classes list for a typo answered "No classes yet", which is
     * simply untrue. They are now separate: the first is a first-run prompt and
     * takes an optional CTA, the second offers a way back out of the filter.
     *
     * @param array $opts ['sub'=>…, 'cta_href'=>…, 'cta_label'=>…, 'icon'=>…]
     *                    Optional; omitting them keeps the old bare-label look.
     */
    function studio_ui_list_end(int $rowCount, string $emptyLabel = 'Nothing here yet', array $opts = []): void
    {
        $bulk = !empty($GLOBALS['__studio_ui_bulk']);
        echo '</div>'; // .data-list

        // ── Nothing exists yet ──
        echo '<div class="studio-empty" data-empty="none"' . ($rowCount > 0 ? ' hidden' : '') . '>';
        if (function_exists('slate_icon')) {
            echo slate_icon((string) ($opts['icon'] ?? 'file'), 'icon');
        }
        echo '<div class="studio-empty-t">' . e($emptyLabel) . '</div>';
        if (!empty($opts['sub'])) {
            echo '<p class="studio-empty-s">' . e((string) $opts['sub']) . '</p>';
        }
        if (!empty($opts['cta_href']) && !empty($opts['cta_label'])) {
            echo '<a class="btn btn-primary" href="' . e((string) $opts['cta_href']) . '">'
               . e((string) $opts['cta_label']) . '</a>';
        }
        echo '</div>';

        // ── Rows exist, but the tab/search hides them all ──
        echo '<div class="studio-empty" data-empty="filtered" hidden>';
        if (function_exists('slate_icon')) { echo slate_icon('search', 'icon'); }
        echo '<div class="studio-empty-t">' . e(__('studio_no_matches', 'No matches')) . '</div>';
        echo '<p class="studio-empty-s">'
           . e(__('studio_no_matches_sub', 'Nothing here matches the current tab or search.')) . '</p>';
        echo '<button type="button" class="btn studio-empty-reset">'
           . e(__('studio_clear_filters', 'Clear filters')) . '</button>';
        echo '</div>';

        echo $bulk ? '</form>' : '</div>'; // .studio-list
        $GLOBALS['__studio_ui_bulk'] = false;
        slate_data_list_script();   // expand/collapse
        studio_ui_filter_script();  // tab + search filtering + bulk selection
    }
}

if (!function_exists('studio_ui_filter_script')) {
    function studio_ui_filter_script(): void
    {
        if (defined('STUDIO_UI_FILTER_JS')) return;
        define('STUDIO_UI_FILTER_JS', true);
        ?>
        <script>
        (function () {
            document.querySelectorAll('[data-studio-list]').forEach(function (wrap) {
                var seg      = wrap.querySelector('.segmented');
                var search   = wrap.querySelector('.studio-search');
                var emptyNil = wrap.querySelector('.studio-empty[data-empty=none]');
                var emptyFil = wrap.querySelector('.studio-empty[data-empty=filtered]');
                var reset    = wrap.querySelector('.studio-empty-reset');
                var rows     = Array.prototype.slice.call(wrap.querySelectorAll('.data-row'));
                // Server may have pre-activated a tab from ?tab= — start there.
                var active   = seg && seg.querySelector('.segmented-item.is-active');
                var filter   = (active && active.getAttribute('data-filter-value')) || 'all';

                function apply() {
                    var q = (search && search.value || '').trim().toLowerCase();
                    var shown = 0;
                    rows.forEach(function (r) {
                        var okF = filter === 'all' || r.getAttribute('data-filter') === filter;
                        var okS = q === '' || (r.getAttribute('data-search') || '').indexOf(q) !== -1;
                        var vis = okF && okS;
                        r.style.display = vis ? '' : 'none';
                        if (vis) shown++;
                    });
                    // "None exist" is a server-side fact and never toggles here;
                    // only the filtered-to-zero state reacts to the controls.
                    if (emptyNil) emptyNil.hidden = rows.length !== 0;
                    if (emptyFil) emptyFil.hidden = !(rows.length > 0 && shown === 0);
                }

                function selectTab(btn) {
                    if (!seg || !btn) return;
                    seg.querySelectorAll('.segmented-item').forEach(function (x) {
                        var on = x === btn;
                        x.classList.toggle('is-active', on);
                        x.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    filter = btn.getAttribute('data-filter-value') || 'all';
                }

                function clearFilters() {
                    if (search) search.value = '';
                    filter = 'all';
                    if (seg) { selectTab(seg.querySelector('.segmented-item')); }
                    apply();
                    if (search) search.focus();
                }
                if (reset) reset.addEventListener('click', clearFilters);

                if (seg) seg.addEventListener('click', function (e) {
                    var b = e.target.closest('.segmented-item');
                    if (!b) return;
                    e.preventDefault();
                    selectTab(b);
                    apply();
                });
                if (search) search.addEventListener('input', apply);

                // Must run on load: ?tab= can pre-activate a non-first tab, and the
                // server renders every row regardless.
                apply();

                // ── Bulk selection (only when the list is a form with checkboxes) ──
                var bar      = wrap.querySelector('.studio-bulkbar');
                var selCount = wrap.querySelector('.studio-selcount');
                var selAll   = wrap.querySelector('.studio-check-all');
                function checks() { return Array.prototype.slice.call(wrap.querySelectorAll('.studio-check')); }
                function syncBulk() {
                    if (!bar) return;
                    var picked = checks().filter(function (c) { return c.checked; }).length;
                    bar.hidden = picked === 0;
                    if (selCount) selCount.textContent = picked + ' selected';
                }
                if (bar) {
                    wrap.addEventListener('change', function (e) {
                        if (e.target.classList.contains('studio-check')) syncBulk();
                    });
                    if (selAll) selAll.addEventListener('change', function () {
                        checks().forEach(function (c) {
                            var row = c.closest('.data-row');
                            if (row && row.style.display === 'none') return; // only visible rows
                            c.checked = selAll.checked;
                        });
                        syncBulk();
                    });
                }
            });

            // Confirm dialog for any submit button carrying data-confirm.
            document.addEventListener('click', function (e) {
                var b = e.target.closest('[data-confirm]');
                if (!b) return;
                if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
            });
        })();
        </script>
        <?php
    }
}
