<?php
/**
 * Slate Multi Language Visual Translation — AJAX API.
 * All actions POST except `export` (GET, streams a CSV download).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MultilangTranslate.php';

Auth::require();
$tid = current_tenant_id();
MultilangTranslate::ensureSchema();

function mlt_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// Export is a GET download; everything else is a CSRF-checked POST.
if ($action === 'export') {
    Auth::requirePerm('mlt.import_export');
    $locales = array_filter((array)($_GET['locales'] ?? []));
    // Sanitize: this can now include freeform-typed codes for a language
    // that hasn't been added yet (the "export a blank column for an AI to
    // fill in" flow), so don't trust it blindly — keep it to plausible
    // ISO-ish codes only, same shape LangRepo::add() itself requires.
    $locales = array_values(array_unique(array_filter($locales, fn($c) => preg_match('/^[a-z]{2}(-[a-z]{2})?$/', strtolower(trim((string)$c))))));
    $locales = array_map(fn($c) => strtolower(trim($c)), $locales);
    if (!$locales) $locales = array_column(MLT_LangRepo::targets($tid), 'code');
    $csv = MLT_StringRepo::exportCsv($tid, $locales);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="translations-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF" . $csv; // BOM for Excel UTF-8
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') mlt_json(['error' => 'POST required'], 405);
if (!csrf_verify($_POST['_csrf'] ?? null)) mlt_json(['error' => 'CSRF check failed'], 403);

switch ($action) {

    case 'scan_start':
        Auth::requirePerm('mlt.view');
        $queue = MLT_Crawler::discoveryQueue($tid);
        mlt_json([
            'ok' => true,
            'total' => count($queue),
            'queue' => $queue,
            'initial_strings' => MLT_StringRepo::totalCount($tid),
        ]);
        break;

    case 'scan_batch':
        Auth::requirePerm('mlt.view');
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!is_array($items)) mlt_json(['error' => 'Invalid scan batch'], 422);
        $result = MLT_Crawler::scanBatch($tid, array_slice($items, 0, 2));
        $result['harvested_strings'] = MLT_StringRepo::totalCount($tid);
        AuditLog::record('mlt.scan_batch', json_encode($result));
        mlt_json(['ok' => true] + $result);
        break;

    case 'scan':
        Auth::requirePerm('mlt.view');
        $result = MLT_Crawler::run($tid);
        AuditLog::record('mlt.scanned', json_encode($result));
        mlt_json(['ok' => true] + $result);
        break;

    case 'save_scan_urls':
        Auth::requirePerm('mlt.manage');
        $raw = (string)($_POST['urls'] ?? '');
        // Cap length defensively — this is admin-entered but still worth a
        // sane ceiling so a paste mistake can't bloat the settings row.
        if (strlen($raw) > 20000) mlt_json(['error' => 'That\'s too much text — keep it to a reasonable list of URLs.'], 422);
        MLT_Crawler::saveManualUrls($tid, $raw);
        $saved = MLT_Crawler::manualUrls($tid);
        AuditLog::record('mlt.scan_urls_saved', 'count=' . count($saved));
        mlt_json(['ok' => true, 'count' => count($saved)]);
        break;

    case 'save_cell':
        Auth::requirePerm('mlt.manage');
        $stringId = (int)($_POST['string_id'] ?? 0);
        $locale   = trim((string)($_POST['locale'] ?? ''));
        $text     = (string)($_POST['text'] ?? '');
        if (!$stringId || $locale === '') mlt_json(['error' => 'Missing params'], 422);
        MLT_StringRepo::saveCell($tid, $stringId, $locale, $text, 'draft');
        mlt_json(['ok' => true]);
        break;

    case 'save_batch':
        Auth::requirePerm('mlt.manage');
        $cells = json_decode((string)($_POST['cells'] ?? '[]'), true) ?: [];
        $n = 0;
        foreach ($cells as $c) {
            $sid = (int)($c['string_id'] ?? 0);
            $loc = trim((string)($c['locale'] ?? ''));
            if (!$sid || $loc === '') continue;
            MLT_StringRepo::saveCell($tid, $sid, $loc, (string)($c['text'] ?? ''), 'draft');
            $n++;
        }
        mlt_json(['ok' => true, 'saved' => $n]);
        break;

    case 'publish':
        Auth::requirePerm('mlt.manage');
        $locale = trim((string)($_POST['locale'] ?? '')) ?: null;
        $n = MLT_StringRepo::publish($tid, $locale);
        AuditLog::record('mlt.published', $locale ?? 'all');
        mlt_json(['ok' => true, 'published' => $n]);
        break;

    case 'add_language':
        Auth::requirePerm('mlt.manage');
        try {
            $id = MLT_LangRepo::add($tid, (string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''));
            AuditLog::record('mlt.language_added', (string)($_POST['code'] ?? ''));
            mlt_json(['ok' => true, 'id' => $id]);
        } catch (\InvalidArgumentException $e) {
            mlt_json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            mlt_json(['error' => 'That language may already exist.'], 422);
        }
        break;

    case 'toggle_language':
        Auth::requirePerm('mlt.manage');
        MLT_LangRepo::toggle($tid, (int)($_POST['id'] ?? 0), !empty($_POST['enabled']) && $_POST['enabled'] !== '0');
        mlt_json(['ok' => true]);
        break;

    case 'delete_language':
        Auth::requirePerm('mlt.manage');
        MLT_LangRepo::delete($tid, (int)($_POST['id'] ?? 0));
        AuditLog::record('mlt.language_removed', (string)($_POST['id'] ?? ''));
        mlt_json(['ok' => true]);
        break;

    case 'find_replace':
        Auth::requirePerm('mlt.manage');
        $n = MLT_StringRepo::findReplace(
            $tid,
            trim((string)($_POST['locale'] ?? '')),
            (string)($_POST['find'] ?? ''),
            (string)($_POST['replace'] ?? ''),
            !empty($_POST['case_sensitive'])
        );
        mlt_json(['ok' => true, 'replaced' => $n]);
        break;

    case 'ignore_row':
        Auth::requirePerm('mlt.manage');
        MLT_StringRepo::ignore($tid, (int)($_POST['string_id'] ?? 0), !empty($_POST['ignored']));
        mlt_json(['ok' => true]);
        break;

    case 'import':
        Auth::requirePerm('mlt.import_export');
        if (empty($_FILES['file']['tmp_name'])) mlt_json(['error' => 'No file uploaded'], 422);
        $csv = file_get_contents($_FILES['file']['tmp_name']);
        // Optional: restrict the import to one language column even if the
        // CSV has several — everything else in the file is left untouched.
        $onlyLocale = trim((string)($_POST['locale'] ?? ''));
        $onlyLocales = $onlyLocale !== '' && $onlyLocale !== 'all' ? [$onlyLocale] : [];
        [$updated, $skipped] = MLT_StringRepo::importCsv($tid, $csv, $onlyLocales);
        AuditLog::record('mlt.imported', "updated=$updated skipped=$skipped" . ($onlyLocale !== '' ? " locale=$onlyLocale" : ''));
        mlt_json(['ok' => true, 'updated' => $updated, 'skipped' => $skipped]);
        break;

    case 'save_switcher_settings':
        Auth::requirePerm('mlt.manage');
        $plugin = PluginLoader::activePlugins()['multilang-translate'] ?? null;
        $fields = ['switcher_enabled', 'switcher_style', 'switcher_position', 'switcher_bg', 'switcher_fg', 'switcher_accent'];
        foreach ($fields as $f) {
            if (!isset($_POST[$f])) continue;
            $val = (string)$_POST[$f];
            if ($plugin) $plugin->setSetting($f, $val);
            else Database::setSetting('multilang-translate.' . $f, $val);
        }
        mlt_json(['ok' => true]);
        break;

    default:
        mlt_json(['error' => 'Unknown action'], 400);
}
