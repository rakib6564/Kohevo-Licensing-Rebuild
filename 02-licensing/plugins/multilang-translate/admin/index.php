<?php
/**
 * Slate Multi Language Visual Translation — admin page.
 *
 * Rebuilt on the shared admin design system (Session 10) so this looks and
 * behaves like every other Slate list page (Studio's Instructors/Classes,
 * Content Builder's Pages) instead of its own one-off spreadsheet chrome:
 * segmented tabs + search over an expandable .data-list (slate_data_row），
 * slate_stat_card() for the summary row, and slate_pagination() for paging.
 * The per-language edit cells (.mlt-cell) keep their exact markup/data
 * attributes from the old grid, so assets/js/admin.js's save/autosave wiring
 * needed no changes — only how a row is framed changed, not how it saves.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MultilangTranslate.php';

Auth::require();
Auth::requirePerm('mlt.view');

$pageTitle  = __('translations', 'Translations');
$currentNav = 'settings-translations';
$tid = current_tenant_id();

MultilangTranslate::ensureSchema();
MLT_LangRepo::ensureDefault($tid);

$targets  = MLT_LangRepo::targets($tid);      // every added non-default language = translatable columns (enabled or not — enabled only gates the live switcher)
$default  = Database::row("SELECT * FROM multilangtranslate_languages WHERE tenant_id = ? AND is_default = 1", [$tid]);
$scanManualUrls = implode("\n", MLT_Crawler::manualUrls($tid));

$search = trim((string)($_GET['q'] ?? ''));
$filter = ($_GET['filter'] ?? 'all') === 'untranslated' ? 'untranslated' : 'all';
$page   = max(1, (int)($_GET['page'] ?? $_GET['p'] ?? 1));
$perPage = 100;
$targetCodes = array_column($targets, 'code');

$rows  = MLT_StringRepo::grid($tid, $targetCodes, $search, $filter, $perPage, ($page - 1) * $perPage);
$total = MLT_StringRepo::totalCount($tid, $search);
// Unfiltered count, for the "Total strings" stat card specifically -- $total
// above is search-filtered (it also drives pagination for the current
// grid view, which is correct there), but the stat card is labeled as a
// site-wide total and must not silently show "2" just because someone
// left text in the search box.
$grandTotal = $search === '' ? $total : MLT_StringRepo::totalCount($tid);
$untranslatedTotal = MLT_StringRepo::untranslatedCount($tid, $targetCodes, $search);
$rowCountForFilter = $filter === 'untranslated' ? $untranslatedTotal : $total;
$pages = max(1, (int)ceil($rowCountForFilter / $perPage));

$reports = [];
foreach ($targets as $t) $reports[$t['code']] = MLT_StringRepo::report($tid, $t['code']);

require SLATE_ROOT . '/admin/partials/header.php';
?>
<link rel="stylesheet" href="<?= e(SLATE_URL) ?>/plugins/multilang-translate/assets/css/admin.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/admin.css') ?>">

<div class="page-header">
    <div><h1><?= __('translations', 'Translations') ?></h1>
        <p class="page-header-sub">
            <?= __('source_readonly_note', 'Source language') ?>:
            <span class="mlt-flag"><?= e($default['flag'] ?? '🇬🇧') ?></span> <?= e($default['name'] ?? 'English') ?>
            · <?= __('source_readonly', 'read-only') ?>
        </p>
    </div>
</div>

<?php
// Same strip admin/settings.php renders (includes/ui_components.php), with
// this page marked active -- Translations lives in the settings area now,
// not as its own separate top-level nav item.
if (function_exists('slate_render_settings_tabs')) slate_render_settings_tabs('translations');
?>

<div class="mlt-wrap" data-csrf="<?= e(csrf_token()) ?>" data-tenant="<?= (int)$tid ?>">

    <div class="mlt-toolbar">
        <button type="button" class="btn btn-primary" id="mlt-scan-btn"><?= slate_admin_nav_icon('search') ?> <?= __('scan', 'Scan site') ?></button>
        <button type="button" class="btn" id="mlt-scanurls-btn" title="<?= e(__('scan_urls_title', "Add pages Scan can't find on its own")) ?>"><?= slate_admin_nav_icon('link') ?> <?= __('scan_urls', 'Scan URLs') ?></button>
        <span class="mlt-spacer"></span>
        <button type="button" class="btn" id="mlt-findreplace-btn"><?= slate_admin_nav_icon('repeat') ?> <?= __('find_replace', 'Find & replace') ?></button>
        <button type="button" class="btn" id="mlt-import-btn"><?= slate_admin_nav_icon('upload') ?> <?= __('import', 'Import') ?></button>
        <button type="button" class="btn" id="mlt-export-btn"><?= slate_admin_nav_icon('download') ?> <?= __('export', 'Export') ?></button>
        <button type="button" class="btn" id="mlt-addlang-btn" <?= Auth::can('mlt.manage') ? '' : 'disabled' ?>><?= slate_admin_nav_icon('plus') ?> <?= __('add_language', 'Add language') ?></button>
        <button type="button" class="btn" id="mlt-customize-btn"><?= slate_admin_nav_icon('palette') ?> <?= __('customize_switcher', 'Customize switcher') ?></button>
        <button type="button" class="btn btn-secondary" id="mlt-save-btn"><?= __('save', 'Save') ?></button>
        <button type="button" class="btn btn-success" id="mlt-publish-btn"><?= __('publish', 'Publish') ?></button>
    </div>

    <section class="mlt-scan-progress" id="mlt-scan-progress" hidden aria-live="polite">
        <div class="mlt-scan-progress-head"><div><strong><?= __('scan_progress', 'Site scan progress') ?></strong><span id="mlt-scan-status" class="mlt-scan-status">Ready</span></div><strong id="mlt-scan-percent">0%</strong></div>
        <div class="mlt-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Site scan progress"><span id="mlt-scan-progress-fill" class="mlt-progress-fill" style="width:0%"></span></div>
        <div class="mlt-scan-progress-meta"><span><strong id="mlt-scan-processed">0</strong> / <strong id="mlt-scan-total">0</strong> URLs processed</span><span><strong id="mlt-scan-pages">0</strong> pages harvested</span><span><strong id="mlt-scan-new-strings">0</strong> new strings</span><span><strong id="mlt-scan-strings">0</strong> total strings</span></div>
        <div id="mlt-scan-errors" class="mlt-scan-errors" hidden></div>
    </section>

    <div class="dash-stats mlt-stats">
        <?php slate_stat_card([
            'icon'  => 'file-text',
            'number' => (string)$grandTotal,
            'label'  => __('total_rows', 'Total strings'),
        ]); ?>
        <?php foreach ($targets as $t): $r = $reports[$t['code']];
            $allDone = $r['total'] > 0 && $r['not_translated'] === 0;
        ?>
        <div class="dash-stat mlt-lang-stat<?= $allDone ? ' is-success' : '' ?><?= $t['enabled'] ? '' : ' is-off' ?>" data-locale="<?= e($t['code']) ?>">
            <div class="dash-stat-top">
                <span class="dash-stat-badge mlt-flag-badge" aria-hidden="true"><?= e($t['flag']) ?></span>
                <span class="dash-stat-num"><?= (int)$r['published'] ?></span>
            </div>
            <div class="dash-stat-k">
                <?= e($t['name']) ?>
                <?php if (!$t['enabled']): ?><span class="mlt-badge-off"><?= __('off_switcher', 'off switcher') ?></span><?php endif; ?>
            </div>
            <div class="dash-stat-s"><?= (int)$r['not_translated'] ?> <?= __('not_translated', 'not translated yet') ?></div>
            <?php if (Auth::can('mlt.manage')): ?>
            <div class="mlt-lang-stat-actions">
                <label class="mlt-th-toggle">
                    <input type="checkbox" class="mlt-lang-toggle" data-id="<?= (int)$t['id'] ?>" <?= $t['enabled'] ? 'checked' : '' ?>>
                    <?= __('enabled_on_switcher', 'On switcher') ?>
                </label>
                <button type="button" class="mlt-lang-remove" data-id="<?= (int)$t['id'] ?>" title="<?= e(__('remove_language', 'Remove language')) ?>">×</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$targets): ?>
        <div class="dash-stat mlt-lang-stat-empty"><?= __('no_target_languages', 'Add a language to start translating.') ?></div>
        <?php endif; ?>
    </div>

    <div class="mlt-listbar">
        <div class="segmented" role="tablist" id="mlt-tabs">
            <button type="button" class="segmented-item<?= $filter === 'all' ? ' is-active' : '' ?>" data-filter-value="all" role="tab" aria-selected="<?= $filter === 'all' ? 'true' : 'false' ?>">
                <?= __('all_rows', 'All') ?> <span class="seg-count"><?= (int)$total ?></span>
            </button>
            <button type="button" class="segmented-item<?= $filter === 'untranslated' ? ' is-active' : '' ?>" data-filter-value="untranslated" role="tab" aria-selected="<?= $filter === 'untranslated' ? 'true' : 'false' ?>">
                <?= __('untranslated_only', 'Untranslated') ?> <span class="seg-count"><?= (int)$untranslatedTotal ?></span>
            </button>
        </div>
        <input type="search" id="mlt-search" class="mlt-search" placeholder="<?= e(__('search', 'Search…')) ?>" value="<?= e($search) ?>" aria-label="<?= e(__('search', 'Search…')) ?>">
    </div>

    <div class="data-list" id="mlt-list">
        <?php foreach ($rows as $row):
            $doneCount = 0;
            foreach ($row['translations'] as $tr) if ($tr['status'] === 'published') $doneCount++;
            $targetN = count($targets);
            if ($targetN === 0) {
                $badge = null;
            } elseif ($doneCount === $targetN) {
                $badge = [__('published', 'Published'), 'active'];
            } elseif ($doneCount > 0) {
                $badge = [$doneCount . '/' . $targetN . ' ' . __('published', 'published'), 'warning'];
            } else {
                $badge = [__('not_translated', 'Not translated'), 'danger'];
            }

            $detail = [];
            foreach ($targets as $t) {
                $tr = $row['translations'][$t['code']];
                $cellHtml = '<textarea class="mlt-cell mlt-status-' . e($tr['status']) . '"'
                          . ' data-string-id="' . (int)$row['id'] . '" data-locale="' . e($t['code']) . '" rows="1">'
                          . e($tr['translated_text']) . '</textarea>';
                $detail[e($t['flag']) . ' ' . e($t['name'])] = ['html' => $cellHtml];
            }

            slate_data_row([
                'title'        => $row['source_text'],
                'meta'         => e((string)$row['source_area']) . ' · ×' . (int)$row['occurrences'],
                'avatar'       => mb_strtoupper(mb_substr((string)($row['source_area'] ?: '?'), 0, 2)),
                'avatar_color' => 'muted',
                'badge'        => $badge,
                'detail'       => $detail,
            ]);
        endforeach; ?>
        <?php if (!$rows): ?>
        <div class="mlt-empty"><?= __('no_strings_yet', 'No strings yet — click "Scan site" to harvest text from every page.') ?></div>
        <?php endif; ?>
    </div>
    <?php slate_data_list_script(); ?>

    <?php slate_pagination($page, $pages, ['q' => $search, 'filter' => $filter], [
        'total'    => $rowCountForFilter,
        'per_page' => $perPage,
        'label'    => __('strings', 'strings'),
    ]); ?>
</div>

<!-- Add language modal -->
<div class="mlt-modal" id="mlt-modal-addlang" hidden>
    <div class="mlt-modal-box">
        <h3><?= __('add_language', 'Add a language') ?></h3>
        <label><?= __('language_code', 'Language code (ISO 639-1)') ?>
            <input type="text" id="mlt-newlang-code" maxlength="5" placeholder="fr, de, es…">
        </label>
        <label><?= __('display_name', 'Display name (optional — auto-filled)') ?>
            <input type="text" id="mlt-newlang-name" placeholder="French">
        </label>
        <p class="mlt-modal-hint"><?= __('add_language_hint', 'Every existing row will get a blank input for this language, ready to fill in or import.') ?></p>
        <div class="mlt-modal-actions">
            <button type="button" class="btn" data-close><?= __('cancel', 'Cancel') ?></button>
            <button type="button" class="btn btn-primary" id="mlt-newlang-confirm"><?= __('add', 'Add') ?></button>
        </div>
    </div>
</div>

<!-- Find & replace modal -->
<div class="mlt-modal" id="mlt-modal-findreplace" hidden>
    <div class="mlt-modal-box">
        <h3><?= __('find_replace', 'Find & replace') ?></h3>
        <label><?= __('language', 'Language') ?>
            <select id="mlt-fr-locale"><?php foreach ($targets as $t): ?><option value="<?= e($t['code']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label><?= __('find', 'Find') ?> <input type="text" id="mlt-fr-find"></label>
        <label><?= __('replace_with', 'Replace with') ?> <input type="text" id="mlt-fr-replace"></label>
        <label class="mlt-inline-check"><input type="checkbox" id="mlt-fr-case"> <?= __('case_sensitive', 'Case sensitive') ?></label>
        <div class="mlt-modal-actions">
            <button type="button" class="btn" data-close><?= __('cancel', 'Cancel') ?></button>
            <button type="button" class="btn btn-primary" id="mlt-fr-confirm"><?= __('replace_all', 'Replace all') ?></button>
        </div>
    </div>
</div>

<!-- Scan URLs modal -->
<div class="mlt-modal" id="mlt-modal-scanurls" hidden>
    <div class="mlt-modal-box">
        <h3><?= __('scan_urls_title2', 'Extra pages for Scan to visit') ?></h3>
        <p class="mlt-modal-hint"><?= __('scan_urls_hint', "Scan can only find pages listed in the site's own menus, plus the homepage, admin, and customer dashboards. It has no way to discover a page like a registration flow that isn't in any menu, or pages on a completely different site/domain. Paste one full URL per line here and Scan will fetch and harvest each one too — same site or a different one entirely.") ?></p>
        <textarea id="mlt-scanurls-text" rows="8" placeholder="https://example.com/customer/register.php&#10;https://cmsurveyors.com/&#10;https://cmsurveyors.com/rates.php"><?= e($scanManualUrls) ?></textarea>
        <p class="mlt-modal-hint"><?= __('scan_urls_hint2', "For a different domain, the page is fetched anonymously (like a real visitor sees it) — your login session is never sent to another site.") ?></p>
        <div class="mlt-modal-actions">
            <button type="button" class="btn" data-close><?= __('cancel', 'Cancel') ?></button>
            <button type="button" class="btn btn-primary" id="mlt-scanurls-save"><?= __('save_urls', 'Save URLs') ?></button>
        </div>
    </div>
</div>

<!-- Import modal -->
<div class="mlt-modal" id="mlt-modal-import" hidden>
    <div class="mlt-modal-box">
        <h3><?= __('import_csv', 'Import CSV') ?></h3>
        <p class="mlt-modal-hint"><?= __('import_hint', 'Header row: source_text, then one column per language code (fr, de…). Matches rows by source text.') ?></p>
        <label><?= __('import_target_column', 'Language column to import') ?>
            <select id="mlt-import-locale">
                <option value="all"><?= __('import_all_columns', 'All columns in the file') ?></option>
                <?php foreach ($targets as $t): ?>
                <option value="<?= e($t['code']) ?>"><?= e($t['flag']) ?> <?= e($t['name']) ?> (<?= e($t['code']) ?>) <?= __('only', '— only') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="mlt-modal-hint"><?= __('import_target_hint', 'Pick one language to only update that column, even if the file has others — everything else in the file is ignored.') ?></p>
        <input type="file" id="mlt-import-file" accept=".csv">
        <div class="mlt-modal-actions">
            <button type="button" class="btn" data-close><?= __('cancel', 'Cancel') ?></button>
            <button type="button" class="btn btn-primary" id="mlt-import-confirm"><?= __('upload_import', 'Upload & import') ?></button>
        </div>
    </div>
</div>

<!-- Export modal -->
<div class="mlt-modal" id="mlt-modal-export" hidden>
    <div class="mlt-modal-box">
        <h3><?= __('export_csv', 'Export CSV') ?></h3>
        <p class="mlt-modal-hint"><?= __('export_ai_hint', 'Every export includes id + source_text (English) for every row, whatever you pick below — so this file also works as a "send to an AI to translate" export. Add a target language\'s column (blank if untranslated), hand the file to any AI, get it filled in, then re-import.') ?></p>
        <div class="mlt-modal-hint"><?= __('select_columns', 'Include these existing language columns:') ?></div>
        <?php foreach ($targets as $t): ?>
        <label class="mlt-inline-check"><input type="checkbox" class="mlt-export-lang" value="<?= e($t['code']) ?>" checked> <?= e($t['flag']) ?> <?= e($t['name']) ?> (<?= e($t['code']) ?>)</label><br>
        <?php endforeach; ?>

        <label class="mlt-export-extra-label"><?= __('export_extra_lang', "Also include a blank column for a language you haven't added yet") ?>
            <input type="text" id="mlt-export-extra-lang" placeholder="<?= __('export_extra_placeholder', 'e.g. es, it — comma separated ISO codes') ?>">
        </label>
        <p class="mlt-modal-hint"><?= __('export_extra_hint', "That column exports empty for an AI to fill in. After you re-import the finished file, click Add Language with the same code — its translations will already be there.") ?></p>

        <div class="mlt-modal-actions">
            <button type="button" class="btn" id="mlt-export-copy-prompt"><?= slate_admin_nav_icon('copy') ?> <?= __('copy_ai_prompt', 'Copy AI prompt') ?></button>
            <button type="button" class="btn" data-close><?= __('cancel', 'Cancel') ?></button>
            <a class="btn btn-primary" id="mlt-export-confirm" href="#"><?= __('download', 'Download') ?></a>
        </div>
    </div>
</div>

<!-- Customize switcher modal -->
<div class="mlt-modal" id="mlt-modal-customize" hidden>
    <div class="mlt-modal-box">
        <h3><?= __('customize_switcher', 'Customize language switcher') ?></h3>
        <p class="mlt-modal-hint"><?= __('customize_switcher_hint', 'These settings shape the floating widget shown on the public site. Choose "Header bar" to show the switcher as a menu item in the admin and customer header bars instead of a floating button — the customer portal always uses its header bar for this either way.') ?></p>
        <label><input type="checkbox" id="mlt-cz-enabled"> <?= __('switcher_enabled', 'Show switcher on site') ?></label>
        <label><?= __('style', 'Style') ?>
            <select id="mlt-cz-style">
                <option value="dropdown"><?= __('dropdown', 'Dropdown') ?></option>
                <option value="flags"><?= __('flags_only', 'Flags only') ?></option>
                <option value="list"><?= __('inline_list', 'Inline list') ?></option>
            </select>
        </label>
        <label><?= __('position', 'Position') ?>
            <select id="mlt-cz-position">
                <option value="bottom-right"><?= __('bottom_right', 'Bottom right') ?></option>
                <option value="bottom-left"><?= __('bottom_left', 'Bottom left') ?></option>
                <option value="top-right"><?= __('top_right', 'Top right') ?></option>
                <option value="top-left"><?= __('top_left', 'Top left') ?></option>
                <option value="header"><?= __('switcher_position_header', 'Header bar (admin + customer, as a menu item)') ?></option>
            </select>
        </label>
        <label><?= __('background_color', 'Background color') ?> <input type="color" id="mlt-cz-bg" value="#111827"></label>
        <label><?= __('text_color', 'Text color') ?> <input type="color" id="mlt-cz-fg" value="#ffffff"></label>
        <label><?= __('accent_color', 'Accent color') ?> <input type="color" id="mlt-cz-accent" value="#111111"></label>
        <div class="mlt-modal-actions">
            <button type="button" class="btn" data-close><?= __('cancel', 'Cancel') ?></button>
            <button type="button" class="btn btn-primary" id="mlt-cz-confirm"><?= __('save_settings', 'Save settings') ?></button>
        </div>
    </div>
</div>

<div id="mlt-toast" class="mlt-toast" hidden></div>

<script>
window.MLT_SETTINGS = <?= json_encode([
    'switcher_enabled'  => Database::setting('multilang-translate.switcher_enabled') !== '0',
    'switcher_style'    => Database::setting('multilang-translate.switcher_style') ?: 'dropdown',
    'switcher_position' => Database::setting('multilang-translate.switcher_position') ?: 'bottom-right',
    'switcher_bg'       => Database::setting('multilang-translate.switcher_bg') ?: '#111827',
    'switcher_fg'       => Database::setting('multilang-translate.switcher_fg') ?: '#ffffff',
    'switcher_accent'   => Database::setting('multilang-translate.switcher_accent') ?: '#111111',
]) ?>;
window.MLT_API = "<?= e(SLATE_URL) ?>/plugins/multilang-translate/admin/api.php";
</script>
<script src="<?= e(SLATE_URL) ?>/plugins/multilang-translate/assets/js/admin.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
