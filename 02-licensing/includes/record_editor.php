<?php
/**
 * Slate — shared "record editor" UI kit.
 *
 * A self-contained, modern editor toolkit: identity hero, section cards,
 * iOS toggles, day/time rows, list rows, a back link and an action footer.
 * Any admin page can build a consistent editor without copy-pasting markup
 * or CSS. Markup uses the `.pv-*` namespace defined in slate_editor_css().
 *
 *   require_once SLATE_ROOT . '/includes/record_editor.php';
 *   slate_editor_css();                                    // once, near top
 *   slate_edit_open(['title_fallback' => 'New record']);
 *     // <form> …
 *     slate_edit_backlink(['back_href' => …, 'back_label' => …]);
 *     slate_edit_hero([...]);                              // toggle OR status-pill mode
 *     slate_edit_card_open(['icon' => 'user', 'eyebrow' => 'General', 'title' => '…']);
 *       // …core .field markup…
 *     slate_edit_card_close();
 *     slate_edit_actionbar(['buttons_html' => …]);
 *     // </form>
 *   slate_edit_close();
 *   slate_editor_js();                                     // once, at end
 *
 * The hero supports two modes:
 *   - Toggle mode  — pass 'toggle_name' + 'active' + 'status_on'/'status_off';
 *                    renders an iOS switch and a live on/off pill (driven by
 *                    the #is_active checkbox).
 *   - Pill mode    — pass 'status_text' + 'status_tone' (on|warn|off) and an
 *                    optional 'status_map' (assoc keyed by a <select> value);
 *                    renders a read-only/driven pill (driven by #status).
 *
 * JS conventions: the live name/title field has id="name" (initials avatar)
 * or id="title"; the active toggle has id="is_active"; a status <select> has
 * id="status". All helpers HTML-escape plain-text args; *_html slots take raw
 * HTML (caller escapes user data).
 *
 * Plugins typically expose this via a thin shim (e.g. plugins/<x>/admin/
 * _editor_ui.php) that aliases prefixed names to these functions.
 */
if (!defined('SLATE_ROOT')) exit;

/** Inline line-icon set used by hero/card chips. Unknown name → 'tag'. */
if (!function_exists('slate_edit_icon')) {
    function slate_edit_icon(string $name, int $size = 16): string {
        static $icons = [
            'user'       => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'coffee'     => '<path d="M4 8h13v5a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8z"/><path d="M17 9h2a2 2 0 0 1 0 4h-2"/><path d="M7 2v2M11 2v2"/>',
            'tag'        => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.4"/>',
            'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'map-pin'    => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
            'box'        => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v9"/>',
            'folder'     => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'list'       => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'percent'    => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
            'gift'       => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
            'mail'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'check'      => '<path d="M20 6 9 17l-5-5"/>',
            'bell'       => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
            'link'       => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>',
            'clipboard'  => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
            'arrow-left' => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
            'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        ];
        $path = $icons[$name] ?? $icons['tag'];
        return '<svg viewBox="0 0 24 24" width="' . (int)$size . '" height="' . (int)$size . '" '
             . 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" '
             . 'stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}

/**
 * iOS-style toggle. Returns markup (does not echo).
 * $opts: tag ('label'|'span', default 'label'), id, value (default '1'), title.
 */
if (!function_exists('slate_edit_switch')) {
    function slate_edit_switch(string $name, bool $checked, array $opts = []): string {
        $tag   = ($opts['tag'] ?? 'label') === 'span' ? 'span' : 'label';
        $value = (string)($opts['value'] ?? '1');
        $attr  = '';
        if (!empty($opts['id']))    $attr  .= ' id="' . e((string)$opts['id']) . '"';
        $title = !empty($opts['title']) ? ' title="' . e((string)$opts['title']) . '"' : '';
        return '<' . $tag . ' class="switch"' . $title . '>'
             . '<input type="checkbox" name="' . e($name) . '" value="' . e($value) . '"'
             . $attr . ($checked ? ' checked' : '') . '>'
             . '<span class="switch-track"></span></' . $tag . '>';
    }
}

/** Open/close the editor shell. $a: title_fallback. */
if (!function_exists('slate_edit_open')) {
    function slate_edit_open(array $a = []): void {
        echo '<div class="pv-edit" data-title-fallback="'
           . e((string)($a['title_fallback'] ?? 'New record')) . '">';
    }
}
if (!function_exists('slate_edit_close')) {
    function slate_edit_close(): void { echo '</div>'; }
}

/**
 * Identity hero — toggle mode or pill mode (see file header). $a:
 *   icon, initials, title, ref (int|null → #0001 chip), stats,
 *   [toggle] active, toggle_name, toggle_id, toggle_title, status_on, status_off,
 *   [pill]   status_text, status_tone ('on'|'warn'|'off'), status_map (assoc).
 */
if (!function_exists('slate_edit_hero')) {
    function slate_edit_hero(array $a): void {
        $ref       = $a['ref'] ?? null;
        $stats     = (array)($a['stats'] ?? []);
        $icon      = (string)($a['icon'] ?? '');
        $hasToggle = array_key_exists('toggle_name', $a) || array_key_exists('active', $a);

        echo '<div class="pv-hero"><div class="pv-hero-row">';
        echo   '<span class="pv-avatar' . ($icon !== '' ? ' is-icon' : '') . '" data-avatar>'
             . ($icon !== '' ? slate_edit_icon($icon, 24) : e((string)($a['initials'] ?? '–')))
             . '</span>';
        echo   '<div class="pv-id"><h1 data-title>' . e((string)($a['title'] ?? '')) . '</h1>';
        echo     '<div class="pv-id-meta">';
        if ($ref !== null && $ref !== '') {
            echo     '<span class="pv-chip-mono">#' . e(str_pad((string)(int)$ref, 4, '0', STR_PAD_LEFT)) . '</span>';
        }
        if ($hasToggle) {
            $active  = !empty($a['active']);
            $onText  = (string)($a['status_on']  ?? 'Active');
            $offText = (string)($a['status_off'] ?? 'Inactive');
            echo     '<span class="pv-status ' . ($active ? 'on' : 'off') . '" data-status'
                   . ' data-on-text="' . e($onText) . '" data-off-text="' . e($offText) . '">'
                   . '<span class="dot"></span><span data-status-text>' . e($active ? $onText : $offText) . '</span></span>';
        } else {
            $tone = (string)($a['status_tone'] ?? 'off');
            $text = (string)($a['status_text'] ?? '');
            $map  = !empty($a['status_map']) ? e(json_encode($a['status_map'])) : '';
            echo     '<span class="pv-status ' . e($tone) . '" data-status'
                   . ($map !== '' ? ' data-status-map="' . $map . '"' : '')
                   . '><span class="dot"></span><span data-status-text>' . e($text) . '</span></span>';
        }
        echo     '</div></div>';
        if ($hasToggle) {
            echo slate_edit_switch(
                (string)($a['toggle_name'] ?? 'is_active'), !empty($a['active']),
                ['tag' => 'label', 'id' => (string)($a['toggle_id'] ?? 'is_active'), 'title' => (string)($a['toggle_title'] ?? 'Active')]
            );
        }
        // Optional action buttons rendered in the header (top-right), instead of
        // a separate sticky bottom bar. Wraps below the title on narrow screens.
        $heroActions = (string)($a['actions_html'] ?? '');
        if ($heroActions !== '') echo '<div class="pv-hero-actions">' . $heroActions . '</div>';
        echo '</div>';
        if ($stats) {
            echo '<div class="pv-stats">';
            foreach ($stats as $st) {
                echo '<div class="pv-stat"><div class="pv-stat-k">' . e((string)($st[0] ?? ''))
                   . '</div><div class="pv-stat-v">' . ($st[1] ?? '') . '</div></div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}

/** Section card. $a: icon, eyebrow, title, aside_html (raw, optional). */
if (!function_exists('slate_edit_card_open')) {
    function slate_edit_card_open(array $a): void {
        echo '<div class="pv-card"><div class="pv-card-head">';
        if (!empty($a['icon'])) echo '<span class="pv-card-ico">' . slate_edit_icon((string)$a['icon']) . '</span>';
        echo '<div class="pv-card-titles">';
        if (!empty($a['eyebrow'])) echo '<div class="pv-card-eyebrow">' . e((string)$a['eyebrow']) . '</div>';
        echo '<div class="pv-card-title">' . e((string)($a['title'] ?? '')) . '</div></div>';
        if (!empty($a['aside_html'])) echo $a['aside_html'];
        echo '</div><div class="pv-card-body">';
    }
}
if (!function_exists('slate_edit_card_close')) {
    function slate_edit_card_close(): void { echo '</div></div>'; }
}
/** Muted helper note inside a card body. Takes RAW HTML (links allowed). */
if (!function_exists('slate_edit_card_note')) {
    function slate_edit_card_note(string $html): void { echo '<p class="pv-card-note">' . $html . '</p>'; }
}

/** Open/close the two-up weekly-grid wrapper for day rows. */
if (!function_exists('slate_edit_days_open')) {
    function slate_edit_days_open(): void { echo '<div class="pv-days">'; }
}
if (!function_exists('slate_edit_days_close')) {
    function slate_edit_days_close(): void { echo '</div>'; }
}

/**
 * One toggle + label + time-range row.
 * $a: toggle_name, checked (bool), label, times_html (raw HTML).
 */
if (!function_exists('slate_edit_day_row')) {
    function slate_edit_day_row(array $a): void {
        $on = !empty($a['checked']);
        echo '<div class="pv-day' . ($on ? '' : ' is-off') . '" data-day-row>'
           . '<label class="pv-day-name">'
           . slate_edit_switch((string)($a['toggle_name'] ?? ''), $on, ['tag' => 'span'])
           . ' ' . e((string)($a['label'] ?? '')) . '</label>'
           . '<div class="pv-day-times">' . ($a['times_html'] ?? '') . '</div></div>';
    }
}

/**
 * Toggleable list row with reveal-on-check extra fields.
 * $a: name, value, checked (bool), label, fields_html (raw, optional).
 */
if (!function_exists('slate_edit_toggle_row')) {
    function slate_edit_toggle_row(array $a): void {
        echo '<div class="pv-svc' . (!empty($a['checked']) ? ' is-on' : '') . '" data-svc>'
           . '<label class="pv-svc-top">'
           . slate_edit_switch((string)($a['name'] ?? ''), !empty($a['checked']), ['tag' => 'span', 'value' => $a['value'] ?? '1'])
           . '<span class="pv-svc-name">' . e((string)($a['label'] ?? '')) . '</span></label>';
        if (!empty($a['fields_html'])) echo '<div class="pv-svc-fields">' . $a['fields_html'] . '</div>';
        echo '</div>';
    }
}

/** Top back link, above the hero. $a: back_href, back_label. */
if (!function_exists('slate_edit_backlink')) {
    function slate_edit_backlink(array $a): void {
        if (empty($a['back_href'])) return;
        echo '<a href="' . e((string)$a['back_href']) . '" class="pv-back">'
           . slate_edit_icon('arrow-left', 14) . ' '
           . e((string)($a['back_label'] ?? 'Back')) . '</a>';
    }
}

/**
 * Bottom action footer. Sticks to the viewport bottom on desktop. $a:
 *   buttons_html — primary first → far right.
 *   left_html    — optional contextual content shown on the left (links/hint),
 *                  which fills the bar instead of leaving an empty band.
 */
if (!function_exists('slate_edit_actionbar')) {
    function slate_edit_actionbar(array $a): void {
        $left = (string)($a['left_html'] ?? '');
        echo '<div class="pv-actionbar' . ($left !== '' ? ' has-left' : '') . '">';
        if ($left !== '') echo '<div class="pv-actionbar-left">' . $left . '</div>';
        echo '<div class="pv-actionbar-right">' . ($a['buttons_html'] ?? '') . '</div>';
        echo '</div>';
    }
}

/**
 * Tab strip for splitting a long editor into sections. Pair with panels:
 *
 *   slate_edit_tabs([
 *     ['id' => 'general', 'label' => 'General', 'icon' => 'box'],
 *     ['id' => 'fields',  'label' => 'Fields',  'icon' => 'list'],
 *   ]);
 *   echo '<div class="pv-panel" data-panel="general">…</div>';
 *   echo '<div class="pv-panel" data-panel="fields" hidden>…</div>';
 *
 * The JS shows one panel at a time, remembers the tab in the URL hash, and
 * auto-opens whichever panel contains a .field-error after a failed save.
 * $tabs: list of ['id', 'label', 'icon'(optional)].
 */
if (!function_exists('slate_edit_tabs')) {
    function slate_edit_tabs(array $tabs): void {
        echo '<div class="pv-tabs" role="tablist">';
        foreach ($tabs as $t) {
            $id  = (string)($t['id'] ?? '');
            $ico = !empty($t['icon']) ? '<span class="pv-tab-ico">' . slate_edit_icon((string)$t['icon'], 14) . '</span>' : '';
            echo '<button type="button" class="pv-tab" role="tab" data-tab="' . e($id) . '" aria-selected="false">'
               . $ico . '<span>' . e((string)($t['label'] ?? '')) . '</span></button>';
        }
        echo '</div>';
    }
}

// ── Styles (emit once per response) ──────────────────────────────────
if (!function_exists('slate_editor_css')) {
    function slate_editor_css(): void {
        if (defined('SLATE_EDITOR_CSS_EMITTED')) return;
        define('SLATE_EDITOR_CSS_EMITTED', true);
        ?>
<style>
/* ── Slate record editor — modern, tech-flavoured. Scoped to .pv-edit ── */
.pv-edit { max-width: var(--content-max); margin-inline: auto; }
.pv-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 500; color: var(--muted);
    margin-bottom: 12px;
}
.pv-back:hover { color: var(--text); }

/* Identity hero */
.pv-hero {
    position: relative; overflow: hidden;
    border: 1px solid var(--border); border-radius: var(--radius-lg);
    background:
        radial-gradient(120% 160% at 100% 0%, rgba(37,99,235,0.07), transparent 55%),
        var(--surface);
    padding: 18px; margin-bottom: var(--space-4);
}
.pv-hero::before {
    content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .6;
    background-image: radial-gradient(var(--border-strong) 1px, transparent 1.4px);
    background-size: 17px 17px;
    -webkit-mask-image: linear-gradient(110deg, #000, transparent 52%);
            mask-image: linear-gradient(110deg, #000, transparent 52%);
}
.pv-hero-row { position: relative; display: flex; align-items: center; gap: 14px; }
.pv-avatar {
    width: 56px; height: 56px; border-radius: 16px; flex: none;
    display: grid; place-items: center; font-weight: 700; font-size: 20px;
    background: linear-gradient(140deg, var(--accent), var(--accent-deep)); color: #fff;
    box-shadow: 0 6px 18px rgba(37,99,235,0.30), inset 0 1px 0 rgba(255,255,255,.28);
    letter-spacing: -.02em; text-transform: uppercase;
}
/* Icon variant — soft accent chip with the entity glyph (no deep gradient box). */
.pv-avatar.is-icon { background: var(--accent-soft); color: var(--accent); box-shadow: none; }
.pv-avatar.is-icon svg { width: 24px; height: 24px; }
.pv-id { display: flex; flex-direction: column; gap: 5px; min-width: 0; flex: 1; }
.pv-id h1 { margin: 0; font-size: 20px; letter-spacing: -.02em; line-height: 1.15; }
.pv-id-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.pv-chip-mono {
    font-family: var(--font-mono); font-size: 11px; color: var(--muted);
    background: var(--surface-2); border: 1px solid var(--border);
    padding: 2px 7px; border-radius: 6px;
}
.pv-status { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; }
.pv-status .dot { width: 7px; height: 7px; border-radius: 999px; }
.pv-status.on   { color: var(--success); } .pv-status.on .dot   { background: var(--success); box-shadow: 0 0 0 3px var(--success-soft); }
.pv-status.warn { color: var(--warning); } .pv-status.warn .dot { background: var(--warning); box-shadow: 0 0 0 3px var(--warning-soft); }
.pv-status.off  { color: var(--muted);   } .pv-status.off .dot  { background: var(--subtle); }

/* Header action buttons (top-right of the hero). */
.pv-hero-actions { flex: none; display: flex; align-items: center; gap: 9px; flex-wrap: wrap; justify-content: flex-end; }
.pv-hero-actions form { display: inline; margin: 0; }
.pv-hero-actions .btn { white-space: nowrap; }
@media (max-width: 640px) {
    /* Stack actions onto their own full-width row below the title on phones. */
    .pv-hero-row { flex-wrap: wrap; }
    .pv-hero-actions { order: 3; width: 100%; justify-content: stretch; gap: 8px; }
    .pv-hero-actions .btn,
    .pv-hero-actions form { flex: 1 1 auto; }
    .pv-hero-actions .btn { width: 100%; justify-content: center; }
}

/* Stat strip */
.pv-stats {
    position: relative; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px;
    margin-top: 16px; background: var(--border);
    border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
}
.pv-stat { background: var(--surface); padding: 11px 14px; }
.pv-stat-k { font-family: var(--font-mono); font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: var(--subtle); }
.pv-stat-v { font-size: 20px; font-weight: 700; letter-spacing: -.02em; margin-top: 3px; font-variant-numeric: tabular-nums; }
.pv-stat-v small { font-size: 12px; color: var(--muted); font-weight: 500; margin-left: 2px; }

/* Tab strip — segmented control on a sunken track */
.pv-tabs {
    display: flex; gap: 3px; align-items: center;
    flex-wrap: nowrap; overflow-x: auto;
    padding: 4px; margin-bottom: var(--space-4);
    background: var(--surface-2); border: 1px solid var(--border); border-radius: 11px;
}
.pv-tab {
    display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; flex: none;
    border: 0; background: transparent; cursor: pointer;
    padding: 7px 13px; border-radius: 8px;
    font-size: 12.5px; font-weight: 600; color: var(--muted);
    transition: background .14s ease, color .14s ease, box-shadow .14s ease;
}
.pv-tab:hover { color: var(--text); }
.pv-tab.is-active { background: var(--surface); color: var(--accent); box-shadow: 0 1px 2px rgba(15,17,23,.07); }
.pv-tab-ico { display: inline-flex; }
.pv-tab-ico svg { width: 14px; height: 14px; }
.pv-tab.has-error::after { content: ""; width: 6px; height: 6px; border-radius: 999px; background: var(--danger, #DC2626); }
.pv-panel[hidden] { display: none; }

/* Section card */
.pv-card {
    border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface);
    box-shadow: 0 1px 2px rgba(15,17,23,.04); margin-bottom: var(--space-4); overflow: hidden;
}
.pv-card-head { display: flex; align-items: center; gap: 11px; padding: 13px 16px; border-bottom: 1px solid var(--border); }
.pv-card-ico { width: 30px; height: 30px; border-radius: 9px; flex: none; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent-deep); }
.pv-card-ico svg { width: 16px; height: 16px; }
.pv-card-titles { flex: 1; min-width: 0; }
.pv-card-eyebrow { font-family: var(--font-mono); font-size: 10px; letter-spacing: .1em; text-transform: uppercase; color: var(--subtle); }
.pv-card-title { font-size: 14px; font-weight: 600; letter-spacing: -.01em; }
.pv-card-body { padding: 16px; }
.pv-card-note { font-size: 12px; color: var(--muted); margin: 0 0 12px; }

/* Day rows (hours + breaks) */
.pv-day { display: grid; grid-template-columns: 150px 1fr; gap: 12px; align-items: center; padding: 11px 0; border-bottom: 1px dashed var(--border); }
.pv-day:first-child { padding-top: 0; }
.pv-day:last-child { border-bottom: 0; padding-bottom: 0; }
.pv-day-name { display: flex; align-items: center; gap: 11px; font-weight: 600; font-size: 13px; cursor: pointer; }
.pv-day-times { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pv-day-times .sep { color: var(--subtle); font-family: var(--font-mono); }
.pv-day-times input[type="time"] { width: auto; font-family: var(--font-mono); }
.pv-day-times input[type="text"] { flex: 1; min-width: 120px; }
.pv-day.is-off { opacity: .45; }
.pv-day.is-off .pv-day-times { filter: grayscale(1); }

/* Services toggles */
.pv-svc { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; margin-bottom: 8px; transition: border-color .15s ease, background .15s ease; }
.pv-svc:last-child { margin-bottom: 0; }
.pv-svc.is-on { border-color: var(--accent); background: var(--accent-soft); }
.pv-svc-top { display: flex; align-items: center; gap: 11px; cursor: pointer; }
.pv-svc-name { font-size: 13px; font-weight: 600; flex: 1; min-width: 0; }
.pv-svc-fields { display: none; gap: 6px; margin-top: 10px; padding-left: 53px; }
.pv-svc.is-on .pv-svc-fields { display: flex; }
.pv-svc-fields input { flex: 1; min-width: 0; }

/* Bottom action footer — a normal, in-flow split bar at the end of the
   form. Used to be position:sticky to the viewport bottom on desktop
   (>=768px); dropped per feedback that a floating white bar detached
   from the form while scrolling read as visually awkward rather than
   helpful. Same static styling at every width now. */
.pv-actionbar {
    display: flex; align-items: center; justify-content: flex-end; gap: 14px;
    margin-top: var(--space-4); padding: 12px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: 0 1px 2px rgba(15,17,23,0.04);
}
.pv-actionbar.has-left { justify-content: space-between; }
.pv-actionbar-left { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; min-width: 0; font-size: 12.5px; color: var(--muted); }
.pv-actionbar-left a { color: var(--text-2); font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
.pv-actionbar-left a:hover { color: var(--accent); }
.pv-actionbar-left a svg { width: 14px; height: 14px; }
.pv-actionbar-right { display: flex; flex-direction: row-reverse; align-items: center; gap: 10px; }
.pv-actionbar .btn { min-width: 124px; justify-content: center; }
.pv-actionbar .btn-primary { box-shadow: 0 6px 16px rgba(37,99,235,0.22); }

/* Overrides / inline list rows */
.pv-ov { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 11px 14px; }
.pv-ov-date { font-family: var(--font-mono); font-weight: 600; }

/* ── Modern soft-filled inputs + compact spacing (scoped) ──────── */
.pv-edit .field { margin-bottom: 11px; }
.pv-edit .field-row { margin-bottom: 11px; gap: 11px; }
.pv-edit .field-label { font-size: 11.5px; font-weight: 600; color: var(--text-2); margin-bottom: 5px; }
.pv-edit .field-hint { margin-top: 6px; font-size: 11px; line-height: 1.45; }
.pv-edit .pv-card { margin-bottom: 12px; }
.pv-edit .pv-card-body { padding: 14px; }
.pv-edit .pv-card-note { margin-bottom: 10px; }

main.content .pv-edit input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="hidden"]),
main.content .pv-edit select,
main.content .pv-edit textarea {
    background: var(--surface-2);
    border: 1px solid transparent;
    border-radius: 9px;
    min-height: 36px;
    padding: 8px 11px;
    font-size: 13px;
    color: var(--text);
    transition: background .14s ease, border-color .14s ease, box-shadow .14s ease;
}
main.content .pv-edit input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="hidden"]):hover,
main.content .pv-edit select:hover,
main.content .pv-edit textarea:hover { background: var(--surface-sunken); border-color: transparent; }
main.content .pv-edit input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="hidden"]):focus,
main.content .pv-edit select:focus,
main.content .pv-edit textarea:focus {
    background: var(--surface);
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--ring);
}
.pv-edit textarea { min-height: 70px; resize: vertical; line-height: 1.5; }

/* Tighter weekly-hours / breaks rows */
.pv-day { grid-template-columns: 128px 1fr; padding: 8px 0; }
.pv-day-name { gap: 10px; font-size: 12.5px; }

.pv-days { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 2px 28px; }
.pv-days .pv-day { display: flex; align-items: center; gap: 10px; }
.pv-days .pv-day-name { flex: none; width: 94px; }
.pv-days .pv-day-times { flex: 1; flex-wrap: nowrap; justify-content: flex-end; gap: 6px; }
.pv-days .pv-day-times .sep { flex: none; }
.pv-days .pv-day-times input[type="time"] { width: 100px; flex: none; }

/* ── Compact scale — tighter, smaller chrome on desktop/tablet ─── */
.pv-edit { font-size: 13px; }
.pv-hero { padding: 13px 14px; }
.pv-avatar { width: 44px; height: 44px; border-radius: 12px; font-size: 16px; box-shadow: 0 4px 12px rgba(37,99,235,.26), inset 0 1px 0 rgba(255,255,255,.28); }
.pv-id h1 { font-size: 17px; }
.pv-id-meta { gap: 7px; }
.pv-status { font-size: 11.5px; }
.pv-chip-mono { font-size: 10.5px; padding: 1px 6px; }
.pv-stats { margin-top: 12px; }
.pv-stat { padding: 9px 12px; }
.pv-stat-k { font-size: 9.5px; }
.pv-stat-v { font-size: 17px; }
.pv-edit .pv-card { margin-bottom: 10px; border-radius: 11px; }
.pv-card-head { padding: 9px 13px; gap: 9px; }
.pv-card-ico { width: 26px; height: 26px; border-radius: 7px; }
.pv-card-ico svg { width: 15px; height: 15px; }
.pv-card-eyebrow { font-size: 9px; }
.pv-card-title { font-size: 13px; }
.pv-edit .pv-card-body { padding: 12px 13px; }
.pv-edit .pv-card-note { font-size: 11.5px; margin-bottom: 9px; }
.pv-edit .field { margin-bottom: 9px; }
.pv-edit .field-row { margin-bottom: 9px; gap: 10px; }
.pv-edit .field-label { font-size: 11px; margin-bottom: 4px; }
.pv-edit .field-hint { margin-top: 5px; font-size: 10.5px; }

main.content .pv-edit input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="hidden"]),
main.content .pv-edit select,
main.content .pv-edit textarea { min-height: 33px; padding: 6px 10px; font-size: 12.5px; border-radius: 8px; }
.pv-edit textarea { min-height: 60px; }

/* Smaller toggles */
.pv-edit .switch-track { width: 36px; height: 20px; }
.pv-edit .switch-track::after { width: 16px; height: 16px; }
.pv-edit .switch input:checked + .switch-track::after { transform: translateX(16px); }

/* Denser rows + services + buttons */
.pv-day { padding: 6px 0; }
.pv-day-name { font-size: 12.5px; gap: 9px; }
.pv-days .pv-day-name { width: 120px; }
.pv-svc { padding: 8px 10px; margin-bottom: 6px; }
.pv-svc-name { font-size: 12.5px; }
.pv-svc-fields { padding-left: 47px; margin-top: 8px; }
.pv-actionbar { gap: 10px; padding: 12px 14px; }
.pv-edit .btn { min-height: 34px; padding: 6px 12px; font-size: 12.5px; }

@media (max-width: 600px) {
    .pv-stats { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .pv-day { grid-template-columns: 1fr; gap: 9px; align-items: start; }
    .pv-days .pv-day { flex-direction: column; align-items: stretch; gap: 6px; }
    .pv-days .pv-day-name { width: auto; }
    .pv-days .pv-day-times { flex-wrap: wrap; justify-content: flex-start; }
    .pv-days .pv-day-times input[type="time"] { width: auto; flex: 1 1 42%; min-width: 0; }
    .pv-svc-fields { padding-left: 0; }
    .pv-actionbar { flex-direction: column-reverse; align-items: stretch; gap: 10px; }
    .pv-actionbar-right { flex-direction: column; align-items: stretch; }
    .pv-actionbar-right .btn { width: 100%; min-width: 0; }
    .pv-actionbar-left { justify-content: center; }
    main.content .pv-edit input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="hidden"]),
    main.content .pv-edit select,
    main.content .pv-edit textarea { min-height: 38px; font-size: 14px; padding: 8px 11px; }
}

@media (max-width: 767px) {
    .pv-hero { padding: 12px; border-radius: 14px; }
    .pv-avatar { width: 42px; height: 42px; border-radius: 12px; font-size: 16px; }
    .pv-id h1 { font-size: 16px; }
    .pv-stats { margin-top: 12px; }
    .pv-stat { padding: 9px 11px; }
    .pv-stat-v { font-size: 16px; }
    .pv-card { border-radius: 13px; }
    /* Tabs wrap into tidy, content-width chips on phones. The app hides
       scrollbars globally, so a sideways-scrolling strip hid the later tabs
       (e.g. forms' Notifications / Webhooks / Spam & Security). Natural width +
       left-aligned reads as a clean chip group, not a stretched ragged grid. */
    .pv-tabs { flex-wrap: wrap; overflow-x: visible; gap: 5px; padding: 5px; }
    .pv-tab { flex: 0 0 auto; padding: 8px 12px; font-size: 12.5px; }
    .pv-tab.is-active { box-shadow: 0 1px 3px rgba(15,17,23,.10); }
}
</style>
        <?php
    }
}

// ── Behaviour (emit once per response, after the markup) ─────────────
if (!function_exists('slate_editor_js')) {
    function slate_editor_js(): void {
        if (defined('SLATE_EDITOR_JS_EMITTED')) return;
        define('SLATE_EDITOR_JS_EMITTED', true);
        ?>
<script>
(function () {
    var root = document.querySelector('.pv-edit');
    if (!root) return;

    // Dim day/break rows when their toggle is off.
    root.querySelectorAll('[data-day-row]').forEach(function (row) {
        var box = row.querySelector('input[type="checkbox"]');
        if (!box) return;
        var sync = function () { row.classList.toggle('is-off', !box.checked); };
        box.addEventListener('change', sync); sync();
    });

    // Reveal extra fields when a list row is toggled on.
    root.querySelectorAll('[data-svc]').forEach(function (row) {
        var box = row.querySelector('input[type="checkbox"]');
        if (!box) return;
        var sync = function () { row.classList.toggle('is-on', box.checked); };
        box.addEventListener('change', sync); sync();
    });

    // Live identity preview: avatar initials + title track the name/title field.
    var fallback  = root.getAttribute('data-title-fallback') || 'New record';
    var nameInput = root.querySelector('#name') || root.querySelector('#title');
    var avatar    = root.querySelector('[data-avatar]');
    var title     = root.querySelector('[data-title]');
    if (nameInput) {
        nameInput.addEventListener('input', function () {
            var v = nameInput.value.trim();
            if (avatar && !avatar.classList.contains('is-icon')) avatar.textContent = v ? v.slice(0, 2).toUpperCase() : '–';
            if (title)  title.textContent  = v || fallback;
        });
    }

    // Tab strip: show one [data-panel] at a time. Remembers the tab in the
    // URL hash, and auto-opens whichever panel holds a .field-error.
    var tabsWrap = root.querySelector('.pv-tabs');
    if (tabsWrap) {
        var tabs   = Array.prototype.slice.call(tabsWrap.querySelectorAll('[data-tab]'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('[data-panel]'));
        var activate = function (id) {
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-tab') === id;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(function (p) { p.hidden = (p.getAttribute('data-panel') !== id); });
            try { history.replaceState(null, '', '#' + id); } catch (e) {}
        };
        tabs.forEach(function (t) {
            t.addEventListener('click', function () { activate(t.getAttribute('data-tab')); });
        });
        // Flag tabs whose panel has an error, and pick the initial tab.
        var initial = null;
        for (var i = 0; i < panels.length; i++) {
            var pid = panels[i].getAttribute('data-panel');
            if (panels[i].querySelector('.field-error')) {
                if (!initial) initial = pid;
                var tb = tabsWrap.querySelector('[data-tab="' + pid + '"]');
                if (tb) tb.classList.add('has-error');
            }
        }
        var hashId = (location.hash || '').slice(1);
        if (!initial && hashId && tabsWrap.querySelector('[data-tab="' + hashId + '"]')) initial = hashId;
        if (!initial && tabs[0]) initial = tabs[0].getAttribute('data-tab');
        if (initial) activate(initial);
    }

    var status = root.querySelector('[data-status]');

    // Toggle mode: status pill follows the #is_active checkbox.
    var active = root.querySelector('#is_active');
    if (active && status && status.hasAttribute('data-on-text')) {
        var statusText = status.querySelector('[data-status-text]');
        var onT  = status.getAttribute('data-on-text')  || 'Active';
        var offT = status.getAttribute('data-off-text') || 'Inactive';
        var su = function () {
            status.classList.toggle('on', active.checked);
            status.classList.toggle('off', !active.checked);
            if (statusText) statusText.textContent = active.checked ? onT : offT;
        };
        active.addEventListener('change', su); su();
    }

    // Pill mode: status pill follows a #status <select> via data-status-map.
    var statusSel = root.querySelector('#status');
    if (statusSel && status && status.hasAttribute('data-status-map')) {
        var map = {};
        try { map = JSON.parse(status.getAttribute('data-status-map') || '{}'); } catch (e) {}
        var txt = status.querySelector('[data-status-text]');
        var syncSel = function () {
            var m = map[statusSel.value] || { tone: 'off', text: statusSel.value };
            status.classList.remove('on', 'off', 'warn');
            status.classList.add(m.tone || 'off');
            if (txt) txt.textContent = m.text || statusSel.value;
        };
        statusSel.addEventListener('change', syncSel); syncSel();
    }
})();
</script>
        <?php
    }
}
