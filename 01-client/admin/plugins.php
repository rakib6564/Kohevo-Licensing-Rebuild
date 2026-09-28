<?php
/**
 * Slate — Plugin manager.
 *
 * Session 2: full UI on top of the PluginLoader API. Super-admins
 * can upload a plugin ZIP, then activate, deactivate, or uninstall
 * each plugin from the card-row actions.
 *
 * All state-changing endpoints are POST with CSRF + permission checks
 * and audit-log entries.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
Auth::requirePerm('plugins.manage');

$pageTitle  = __('plugins', 'Plugins');
$currentNav = 'plugins';

// 10 MB upload cap (also gated by PHP's upload_max_filesize / post_max_size).
const SLATE_PLUGIN_UPLOAD_MAX = 10 * 1024 * 1024;

// ── System plugins ───────────────────────────────────────────
// Protected, always-on plugins that power core features and must never be
// deactivated or uninstalled from the UI. A plugin opts in with "system": true
// in its plugin.json; this core list guarantees the bundled essentials stay
// protected even if a stored manifest predates that flag.
const SLATE_SYSTEM_PLUGINS = ['media-library'];
if (!function_exists('slate_is_system_plugin')) {
    function slate_is_system_plugin(string $slug, array $manifest = []): bool {
        return in_array($slug, SLATE_SYSTEM_PLUGINS, true) || !empty($manifest['system']);
    }
}

// Shared payment infrastructure and commercial modules managed by Client Admin
// through the existing plugins.manage boundary.
if (!function_exists('slate_is_client_managed_plugin')) {
    function slate_is_client_managed_plugin(string $slug): bool {
        if ($slug === 'stripe-payment') return true;
        if (class_exists('\Slate\Services\Installation\CommercialModuleRegistry')) {
            $comm = \Slate\Services\Installation\CommercialModuleRegistry::definitions();
            if (isset($comm[$slug])) return true;
        }
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// GET: download an installed plugin as a ZIP (on-the-fly packaging)
//
// Streams plugins/<slug>/ as <slug>-v<version>.zip — the exact thing
// you can re-upload via the form below. Read-only, gated by the page's
// plugins.manage permission plus a CSRF token in the link so it can't
// be triggered cross-site. Must run before header.php so we can emit
// binary and exit cleanly.
// ─────────────────────────────────────────────────────────────
if (($_GET['_action'] ?? '') === 'download') {
    if (!csrf_verify($_GET['_csrf'] ?? '')) {
        http_response_code(400);
        exit(__('csrf_failed', 'Security check failed.'));
    }

    $slug = trim((string)($_GET['slug'] ?? ''));
    if ($slug === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug)) {
        http_response_code(400);
        exit(__('invalid_slug', 'Invalid plugin slug.'));
    }

    // Must be a known plugin row…
    $row = null;
    foreach (PluginLoader::listAll() as $p) {
        if (($p['slug'] ?? '') === $slug) { $row = $p; break; }
    }

    // …and resolve to a real directory that lives inside plugins/ (no traversal).
    $pluginsRoot = realpath(SLATE_ROOT . '/plugins');
    $realDir     = realpath(SLATE_ROOT . '/plugins/' . $slug);
    if (!$row || !$pluginsRoot || !$realDir
        || strpos($realDir, $pluginsRoot . DIRECTORY_SEPARATOR) !== 0
        || !is_dir($realDir)) {
        http_response_code(404);
        exit(__('plugin_not_found', 'Plugin not found.'));
    }

    $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string)($row['version'] ?? '')) ?: '0';

    // Build the archive in a temp file, then stream it.
    $tmp = tempnam(sys_get_temp_dir(), 'slate_plugin_');
    if ($tmp === false) {
        http_response_code(500);
        exit(__('archive_failed', 'Could not create the archive.'));
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        http_response_code(500);
        exit(__('archive_failed', 'Could not create the archive.'));
    }

    // Same exclusions as bin/package-plugin.php — keep the two in sync.
    $skipDirs  = ['.git', 'node_modules', 'vendor', '.idea', '.vscode', '__MACOSX'];
    $skipFiles = ['.DS_Store', 'Thumbs.db', '.gitignore', '.gitkeep'];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        /** @var SplFileInfo $entry */
        $rel   = ltrim(str_replace($realDir, '', $entry->getPathname()), '/\\');
        $parts = explode('/', str_replace('\\', '/', $rel));
        foreach ($parts as $part) {
            if (in_array($part, $skipDirs, true)) continue 2;
        }
        if (in_array(basename($rel), $skipFiles, true)) continue;

        $entryName = $slug . '/' . str_replace('\\', '/', $rel);
        if ($entry->isDir()) {
            $zip->addEmptyDir($entryName);
        } else {
            $zip->addFile($entry->getPathname(), $entryName);
        }
    }
    $zip->close();

    AuditLog::record('plugin.downloaded', $slug);

    $downloadName = $slug . '-v' . $version . '.zip';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ─────────────────────────────────────────────────────────────
// POST handlers — must run before header.php (output buffering)
// ─────────────────────────────────────────────────────────────
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        // ─── Upload ────────────────────────────────────────────
        if ($action === 'upload') {
            // Installing plugin code touches the shared filesystem for every
            // tenant — genuinely platform-level, not a per-tenant permission.
            if (!Auth::isPlatformSuperAdmin()) {
                $flash = ['type' => 'error', 'msg' => __('only_super_admin', 'Only super-admins can upload plugins.')];
            } elseif (empty($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] === UPLOAD_ERR_NO_FILE) {
                $flash = ['type' => 'error', 'msg' => __('no_file', 'No file selected.')];
            } else {
                $file = $_FILES['plugin_zip'];
                $err  = $file['error'];

                if ($err !== UPLOAD_ERR_OK) {
                    $msg = match ($err) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                            __('upload_too_large', 'Upload too large. Max 10 MB.'),
                        UPLOAD_ERR_PARTIAL =>
                            __('upload_partial', 'Upload was interrupted. Try again.'),
                        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                            __('upload_server_error', 'Server could not store the upload.'),
                        default => __('upload_failed', 'Upload failed.'),
                    };
                    $flash = ['type' => 'error', 'msg' => $msg];
                } elseif ($file['size'] > SLATE_PLUGIN_UPLOAD_MAX) {
                    $flash = ['type' => 'error',
                              'msg'  => __('upload_too_large', 'Upload too large. Max 10 MB.')];
                } elseif (!is_uploaded_file($file['tmp_name'])) {
                    $flash = ['type' => 'error', 'msg' => __('upload_invalid', 'Invalid upload.')];
                } else {
                    // Cheap MIME / extension sanity check
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if ($ext !== 'zip') {
                        $flash = ['type' => 'error',
                                  'msg' => __('upload_not_zip', 'File must be a .zip archive.')];
                    } else {
                        $res = PluginLoader::install($file['tmp_name']);
                        if ($res['ok']) {
                            $slug = $res['slug'] ?? '?';
                            AuditLog::record('plugin.installed', $slug);
                            $flash = ['type' => 'success',
                                      'msg' => sprintf(
                                          __('plugin_installed', 'Plugin "%s" installed. Click Activate to enable it.'),
                                          $slug)];
                        } else {
                            $flash = ['type' => 'error', 'msg' => $res['error']];
                        }
                    }
                }
            }
        }

        // ─── Activate ──────────────────────────────────────────
        elseif ($action === 'activate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $canManageLifecycle = Auth::isPlatformSuperAdmin()
                || (slate_is_client_managed_plugin($slug) && Auth::can('plugins.manage'));
            if (!$canManageLifecycle) {
                $flash = ['type' => 'error', 'msg' => __('only_super_admin', 'Only super-admins can activate plugins.')];
            } else {
                if ($slug === '') {
                    $flash = ['type' => 'error', 'msg' => __('missing_slug', 'Missing plugin slug.')];
                } else {
                    $isCommercial = false;
                    if (class_exists('\Slate\Services\Installation\CommercialModuleRegistry')) {
                        $comm = \Slate\Services\Installation\CommercialModuleRegistry::definitions();
                        $isCommercial = isset($comm[$slug]);
                    }
                    if ($isCommercial && class_exists('\Slate\Services\Licensing\EntitlementService') && \Slate\Services\Licensing\EntitlementService::remoteConfigured()) {
                        if (!\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, $slug)) {
                            $flash = ['type' => 'error', 'msg' => sprintf(__('module_not_licensed', 'Plugin "%s" is not included in your active license. Please update your license in Central.'), $slug)];
                            $res = ['ok' => false];
                        } else {
                            $res = PluginLoader::activate($slug);
                        }
                    } else {
                        $res = PluginLoader::activate($slug);
                    }
                    if (!empty($res['ok'])) {
                        AuditLog::record('plugin.activated', $slug);
                        $flash = ['type' => 'success',
                                  'msg' => sprintf(__('plugin_activated', 'Plugin "%s" activated.'), $slug)];
                    } elseif (empty($flash)) {
                        $flash = ['type' => 'error', 'msg' => $res['error'] ?? 'Activation failed.'];
                    }
                }
            }
        }

        // ─── Deactivate ────────────────────────────────────────
        elseif ($action === 'deactivate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $canManageLifecycle = Auth::isPlatformSuperAdmin()
                || (slate_is_client_managed_plugin($slug) && Auth::can('plugins.manage'));
            if (!$canManageLifecycle) {
                $flash = ['type' => 'error', 'msg' => __('only_super_admin', 'Only super-admins can deactivate plugins.')];
            } else {
                if ($slug === '') {
                    $flash = ['type' => 'error', 'msg' => __('missing_slug', 'Missing plugin slug.')];
                } elseif (slate_is_system_plugin($slug)) {
                    $flash = ['type' => 'error', 'msg' => __('system_plugin_locked', 'This is a system plugin and can\'t be deactivated.')];
                } else {
                    $res = PluginLoader::deactivate($slug);
                    if ($res['ok']) {
                        AuditLog::record('plugin.deactivated', $slug);
                        $flash = ['type' => 'success',
                                  'msg' => sprintf(__('plugin_deactivated', 'Plugin "%s" deactivated.'), $slug)];
                    } else {
                        $flash = ['type' => 'error', 'msg' => $res['error']];
                    }
                }
            }
        }

        // ─── Uninstall ─────────────────────────────────────────
        elseif ($action === 'uninstall') {
            // Removes code + data for every tenant — genuinely platform-level.
            if (!Auth::isPlatformSuperAdmin()) {
                $flash = ['type' => 'error', 'msg' => __('only_super_admin', 'Only super-admins can uninstall plugins.')];
            } else {
                $slug = trim((string)($_POST['slug'] ?? ''));
                if ($slug === '') {
                    $flash = ['type' => 'error', 'msg' => __('missing_slug', 'Missing plugin slug.')];
                } elseif (slate_is_system_plugin($slug)) {
                    $flash = ['type' => 'error', 'msg' => __('system_plugin_locked', 'This is a system plugin and can\'t be uninstalled.')];
                } else {
                    $res = PluginLoader::uninstall($slug);
                    if ($res['ok']) {
                        AuditLog::record('plugin.uninstalled', $slug);
                        $flash = ['type' => 'success',
                                  'msg' => sprintf(__('plugin_uninstalled', 'Plugin "%s" uninstalled. All data removed.'), $slug)];
                    } else {
                        $flash = ['type' => 'error', 'msg' => $res['error']];
                    }
                }
            }
        }

        // ─── Save capabilities ───────────────────────────────
        elseif ($action === 'save_capabilities') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $caps = PluginLoader::capabilities($slug);
            if (!$slug || empty($caps)) {
                $flash = ['type' => 'error', 'msg' => __('no_capabilities', 'Plugin does not define capabilities.')];
            } else {
                $enabledPost = (array)($_POST['capabilities'] ?? []);
                foreach ($caps as $capKey => $capDef) {
                    $isEnabled = !empty($enabledPost[$capKey]);
                    PluginLoader::setCapabilityEnabled($slug, (string)$capKey, $isEnabled);
                }
                AuditLog::record('plugin.capabilities_updated', $slug, ['enabled' => array_keys($enabledPost)]);
                $flash = ['type' => 'success', 'msg' => sprintf(__('capabilities_saved', 'Capabilities updated for %s.'), $slug)];
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// Page render
// ─────────────────────────────────────────────────────────────
require __DIR__ . '/partials/header.php';

// Auto-discover and register any unindexed plugins on disk so they appear in the UI
try {
    foreach (PluginLoader::discoverOnDisk() as $diskPlugin) {
        $slug = $diskPlugin['slug'];
        if (!Database::row("SELECT id FROM plugins WHERE slug = ?", [$slug])) {
            Database::insert('plugins', [
                'slug'          => $slug,
                'name'          => $diskPlugin['name'],
                'version'       => $diskPlugin['version'],
                'status'        => PluginLoader::STATUS_INSTALLED,
                'manifest_json' => json_encode($diskPlugin['manifest']),
                'installed_at'  => slate_db_now(),
            ]);
        }
    }
} catch (\Throwable $e) {
    // Database table not ready or error - safe fallback
}

// Auto-sync remote license entitlements from Central if configured and cache is older than 60s
if (class_exists('\Slate\Services\Licensing\EntitlementService') && \Slate\Services\Licensing\EntitlementService::remoteConfigured()) {
    try {
        $serverUrl  = env('LICENSE_SERVER_URL', '');
        $publicKey  = env('LICENSE_SERVER_PUBLIC_KEY', '');
        $product    = env('LICENSE_PRODUCT', '');
        $licenseKey = env('LICENSE_KEY', '');
        $installId  = \Slate\Services\Installation\InstallationService::currentInstallationId();

        if ($serverUrl && $publicKey && $product && $licenseKey && $installId) {
            $store  = new \Slate\Services\Licensing\SlateLicenseCacheStore((int) TENANT_ID);
            $cached = $store->load();
            $lastChecked = isset($cached['remote_checked_at']) ? strtotime((string)$cached['remote_checked_at']) : 0;
            if (time() - $lastChecked > 60) {
                require_once SLATE_ROOT . '/plugins/licensing/client/RemoteLicenseClient.php';
                $parts = parse_url(SLATE_URL);
                $domain = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
                $port = isset($parts['port']) ? (int) $parts['port'] : null;
                if ((($parts['scheme'] ?? '') === 'http' && $port === 80) || (($parts['scheme'] ?? '') === 'https' && $port === 443)) $port = null;
                if ($port !== null) $domain .= ':' . $port;

                $client = new \RemoteLicenseClient([
                    'server_url'  => $serverUrl,
                    'public_key'  => $publicKey,
                    'product'     => $product,
                    'license_key' => $licenseKey,
                    'install_id'  => $installId,
                    'domain'      => $domain,
                    'app_version' => SLATE_VERSION,
                ], $store);
                $client->checkIn();
            }
        }
    } catch (\Throwable $e) {
        // Safe fallback
    }
}

$plugins         = PluginLoader::listAll();
$canUpload       = Auth::isPlatformSuperAdmin();
$canUninstall    = Auth::isPlatformSuperAdmin();
$hasExampleZip   = file_exists(SLATE_ROOT . '/plugins/_dist/hello-world-v1.0.0.zip');
?>

<?php
// Status tallies for the header stat strip.
$countActive = $countInactive = $countInstalled = 0;
foreach ($plugins as $p) {
    switch ($p['status']) {
        case 'active':    $countActive++;    break;
        case 'inactive':  $countInactive++;  break;
        case 'installed': $countInstalled++; break;
    }
}
$csrf = csrf_token();
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('plugins', 'Plugins')],
]); ?>

<style>
/* ════════════════════════════════════════════════════════════
   Plugins — modern "tech console" surface. Scoped to .plug-*;
   reuses the global design tokens (--accent, --surface, …) so it
   stays in lockstep with the rest of the admin theme.
   ════════════════════════════════════════════════════════════ */
.plug-wrap { display: flex; flex-direction: column; gap: 14px; }

/* ── Hero ──────────────────────────────────────────────────── */
/* Light, minimal hero — matches the dashboard: soft accent wash + faint
   dot-grid on a surface card, with the counts as clean stat chips. */
.plug-hero {
    position: relative; overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 20px;
    background:
        radial-gradient(120% 150% at 96% -25%, color-mix(in srgb, var(--accent) 13%, transparent), transparent 55%),
        var(--surface);
    padding: 22px 24px;
}
.plug-hero::after {
    content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .5;
    background-image: radial-gradient(color-mix(in srgb, var(--accent) 22%, transparent) 1px, transparent 1.4px);
    background-size: 20px 20px;
    -webkit-mask-image: linear-gradient(115deg, transparent 42%, #000);
            mask-image: linear-gradient(115deg, transparent 42%, #000);
}
.plug-hero-row {
    position: relative; z-index: 1;
    display: flex; align-items: center; justify-content: space-between;
    gap: 20px; flex-wrap: wrap;
}
.plug-hero-eyebrow {
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.16em;
    text-transform: uppercase; color: var(--accent); font-weight: 600; margin: 0 0 10px;
    display: inline-flex; align-items: center; gap: 7px;
}
.plug-hero-eyebrow::before {
    content: ""; width: 6px; height: 6px; border-radius: 999px;
    background: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
}
.plug-hero h1 {
    margin: 0; font-family: var(--font-display);
    font-size: 24px; font-weight: 750; letter-spacing: -0.03em; color: var(--text);
}
.plug-hero p { margin: 7px 0 0; color: var(--muted); font-size: 13.5px; max-width: 52ch; }

/* Stat chips on the right */
.plug-hero-counts { display: inline-flex; gap: 10px; flex-wrap: wrap; }
.plug-hcount {
    min-width: 76px; padding: 10px 14px; text-align: center;
    border: 1px solid var(--border); border-radius: 13px; background: var(--surface);
}
.plug-hcount-v {
    font-family: var(--font-display);
    font-size: 22px; font-weight: 700; line-height: 1; color: var(--text);
    font-variant-numeric: tabular-nums;
}
.plug-hcount-k {
    font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--subtle); margin-top: 5px;
}
.plug-hcount.is-active { border-color: color-mix(in srgb, var(--success) 32%, var(--border)); }
.plug-hcount.is-active   .plug-hcount-v { color: var(--success); }
.plug-hcount.is-inactive .plug-hcount-v { color: var(--muted); }

/* ── Tab toolbar (filter by status) ────────────────────────── */
.plug-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.plug-tabs {
    display: inline-flex; align-items: center; gap: 2px;
    padding: 3px; border: 1px solid var(--border);
    background: var(--surface-2); border-radius: var(--radius-full);
}
.plug-tab {
    display: inline-flex; align-items: center; gap: 6px;
    font: inherit; font-size: 12.5px; font-weight: 600; color: var(--muted);
    padding: 6px 12px; border: 0; background: transparent; border-radius: var(--radius-full);
    cursor: pointer; white-space: nowrap; line-height: 1;
    transition: background .14s ease, color .14s ease, box-shadow .14s ease;
}
.plug-tab:hover { color: var(--text); }
.plug-tab.is-on { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
.plug-tab-dot { width: 6px; height: 6px; border-radius: 999px; background: var(--subtle); }
.plug-tab[data-tab="active"]    .plug-tab-dot { background: var(--success); }
.plug-tab[data-tab="inactive"]  .plug-tab-dot { background: var(--warning); }
.plug-tab[data-tab="installed"] .plug-tab-dot { background: var(--accent); }
.plug-tab[data-tab="all"]       .plug-tab-dot { display: none; }
.plug-tab-n {
    font-family: var(--font-mono); font-size: 10.5px; font-weight: 600; color: var(--muted);
    background: var(--surface-sunken); border-radius: 999px;
    padding: 1px 6px; min-width: 18px; text-align: center;
}
.plug-tab.is-on .plug-tab-n { background: var(--accent-soft); color: var(--accent); }

.plug-search {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 7px;
    padding: 6px 12px; border: 1px solid var(--border); border-radius: var(--radius-full);
    background: var(--surface); color: var(--subtle); min-width: 190px;
    transition: border-color .14s ease, box-shadow .14s ease;
}
.plug-search:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--ring); color: var(--muted); }
.plug-search svg { width: 15px; height: 15px; flex: none; }
.plug-search input {
    border: 0; outline: none; background: transparent; font: inherit;
    font-size: 12.5px; color: var(--text); width: 100%; min-width: 0;
}
.plug-search input::placeholder { color: var(--subtle); }

/* ── Upload dropzone ───────────────────────────────────────── */
.plug-upload {
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    background: var(--surface);
    padding: 18px 20px;
}
.plug-upload-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap; margin-bottom: 14px;
}
.plug-upload-title { display: flex; align-items: center; gap: 10px; }
.plug-upload-title h2 { margin: 0; font-size: 15px; font-weight: 650; letter-spacing: -0.01em; }
.plug-upload-ico {
    width: 34px; height: 34px; flex: none; border-radius: 10px;
    display: grid; place-items: center;
    background: var(--accent-soft); color: var(--accent);
}
.plug-upload-form { display: flex; flex-direction: column; gap: 12px; }
.plug-dropzone {
    display: flex; align-items: center; gap: 14px;
    border: 1.5px dashed var(--border-stronger);
    border-radius: var(--radius-lg);
    background: var(--surface-2);
    padding: 16px 18px;
    cursor: pointer;
    transition: border-color .14s ease, background .14s ease;
}
.plug-dropzone:hover,
.plug-dropzone.is-drag { border-color: var(--accent); background: var(--accent-soft); }
.plug-dropzone:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--ring); }
.plug-dropzone input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.plug-drop-ico {
    width: 40px; height: 40px; flex: none; border-radius: 11px;
    display: grid; place-items: center;
    background: var(--surface); border: 1px solid var(--border); color: var(--muted);
}
.plug-drop-text { min-width: 0; flex: 1; }
.plug-drop-strong { display: block; font-size: 13.5px; font-weight: 600; color: var(--text); }
.plug-drop-strong b { color: var(--accent); }
.plug-drop-sub { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.plug-drop-file {
    display: none; font-family: var(--font-mono); font-size: 12px;
    color: var(--text); margin-top: 4px; word-break: break-all;
}
.plug-dropzone.has-file .plug-drop-sub { display: none; }
.plug-dropzone.has-file .plug-drop-file { display: block; }
.plug-upload-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

/* ── Plugin card grid ──────────────────────────────────────── */
.plug-grid {
    display: grid; gap: 10px;
    /* min(100%, 300px) keeps a single card from forcing horizontal overflow
       when the grid container is narrower than 300px (small phones). */
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
}
.plug-card {
    position: relative;
    display: flex; flex-direction: column;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    padding: 13px 14px 12px;
    transition: border-color .14s ease, box-shadow .14s ease, transform .14s ease;
}
.plug-card.is-hidden { display: none; }
.plug-card:hover { border-color: var(--border-stronger); box-shadow: var(--shadow-md); transform: translateY(-1px); }
/* Left status spine — subtle; only the meaningful "on" states get colour. */
.plug-card::before {
    content: ""; position: absolute; left: 0; top: 12px; bottom: 12px; width: 3px;
    border-radius: 999px; background: transparent;
}
.plug-card.is-active::before       { background: var(--success); }
.plug-card.is-system-plugin::before { background: var(--accent); }

.plug-card-top { display: flex; align-items: center; gap: 11px; }
/* Neutral monochrome avatar — initials on a soft chip, no rainbow gradients. */
.plug-avatar {
    width: 38px; height: 38px; flex: none; border-radius: 10px;
    display: grid; place-items: center;
    font-weight: 700; font-size: 13px; letter-spacing: -0.02em; text-transform: uppercase;
    color: var(--muted);
    background: var(--surface-2);
    border: 1px solid var(--border);
}
.plug-card.is-active .plug-avatar { color: var(--text-2, var(--text)); }
.plug-card.is-system-plugin .plug-avatar {
    color: var(--accent); background: var(--accent-soft); border-color: transparent;
}
.plug-id { min-width: 0; flex: 1; }
.plug-name {
    font-size: 14px; font-weight: 650; letter-spacing: -0.01em; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.plug-slug {
    font-family: var(--font-mono); font-size: 11px; color: var(--muted); margin-top: 1px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.plug-pill {
    flex: none; display: inline-flex; align-items: center; gap: 5px;
    font-size: 10.5px; font-weight: 600; letter-spacing: 0.01em;
    padding: 3px 8px 3px 6px; border-radius: 999px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--muted);
}
.plug-pill::before { content: ""; width: 6px; height: 6px; border-radius: 999px; background: var(--subtle); }
.plug-pill.is-active   { background: var(--success-soft); color: #15803D; border-color: transparent; }
.plug-pill.is-active::before   { background: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.16); }
.plug-pill.is-inactive { background: var(--warning-soft); color: #B45309; border-color: transparent; }
.plug-pill.is-inactive::before { background: var(--warning); }
.plug-pill.is-installed { background: var(--accent-soft); color: var(--accent-deep); border-color: transparent; }
.plug-pill.is-installed::before { background: var(--accent); }

.plug-desc {
    margin: 10px 0 0; font-size: 12px; line-height: 1.5; color: var(--text-2);
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.plug-meta {
    display: flex; flex-wrap: wrap; gap: 5px 6px; margin-top: 10px;
}
.plug-tag {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 10.5px; color: var(--muted);
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 6px; padding: 2px 7px;
}
.plug-tag b { color: var(--text-2); font-weight: 600; }
.plug-tag.is-ver { font-family: var(--font-mono); }
.plug-tag a { color: var(--accent); text-decoration: none; }
.plug-tag a:hover { text-decoration: underline; }

.plug-actions {
    display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
    margin-top: 12px; padding-top: 11px; border-top: 1px solid var(--border);
}
.plug-actions form { margin: 0; }
.plug-actions .plug-spacer { flex: 1; }
/* Subtle icon-led action button matching the global .btn metrics. */
.plug-act {
    display: inline-flex; align-items: center; gap: 5px;
    font: inherit; font-size: 12px; font-weight: 600; line-height: 1;
    padding: 7px 11px; border-radius: var(--radius-sm);
    border: 1px solid var(--border-strong); background: var(--surface); color: var(--text-2);
    cursor: pointer; text-decoration: none;
    transition: background .12s ease, border-color .12s ease, color .12s ease;
}
.plug-act:hover { background: var(--surface-2); border-color: var(--border-stronger); color: var(--text); text-decoration: none; }
.plug-act svg { width: 14px; height: 14px; }
.plug-act-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.plug-act-primary:hover { background: var(--accent-deep); border-color: var(--accent-deep); color: #fff; }
.plug-act-danger { color: var(--danger); border-color: var(--border-strong); }
.plug-act-danger:hover { background: var(--danger-soft); border-color: var(--danger-soft); color: var(--danger); }
/* Locked "Required" action for system plugins (non-interactive). */
.plug-act-locked { color: var(--muted); background: var(--surface-2); border-color: var(--border); cursor: default; }
.plug-act-locked:hover { background: var(--surface-2); border-color: var(--border); color: var(--muted); }
/* System pill — neutral/brand, distinct from the status pills. */
.plug-pill.is-system { background: var(--accent-soft); color: var(--accent-deep); border-color: transparent; }
.plug-pill.is-system::before { background: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 16%, transparent); }
.plug-card.is-system-plugin { border-color: color-mix(in srgb, var(--accent) 22%, var(--border)); }

/* ── Empty state ───────────────────────────────────────────── */
.plug-empty {
    border: 1px dashed var(--border-stronger); border-radius: var(--radius-xl);
    background: var(--surface); padding: 44px 24px; text-align: center;
}
.plug-empty-ico {
    width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 16px;
    display: grid; place-items: center;
    background: var(--accent-soft); color: var(--accent);
}
.plug-empty h3 { margin: 0 0 4px; font-size: 16px; font-weight: 650; }
.plug-empty p { margin: 0 auto; max-width: 40ch; color: var(--muted); font-size: 13px; }

/* No-results (filter/search yielded nothing) */
.plug-noresults {
    display: none; text-align: center; color: var(--muted); font-size: 13px;
    padding: 36px 20px; border: 1px dashed var(--border-stronger);
    border-radius: var(--radius-lg); background: var(--surface);
}
.plug-noresults.is-on { display: block; }
.plug-noresults b { color: var(--text); font-weight: 600; }

/* ── Responsive ────────────────────────────────────────────────
   Aligns with the admin shell's 768px mobile breakpoint. */
@media (max-width: 767px) {
    .plug-hero { padding: 14px 16px; }
    .plug-hero h1 { font-size: 17px; }
    .plug-hero p { font-size: 12px; }
    .plug-upload { padding: 14px 16px; }
    .plug-upload-head { margin-bottom: 12px; }

    /* Toolbar: tabs take a full row and WRAP (scrollbars are hidden app-wide,
       so a sideways-scrolling strip would just look cut off); search drops
       onto its own full-width row. */
    .plug-toolbar { flex-wrap: wrap; gap: 8px; }
    .plug-tabs {
        flex: 1 1 100%;
        flex-wrap: wrap;
        justify-content: flex-start;
    }
    .plug-tab { flex: 0 0 auto; }
    .plug-search { margin-left: 0; flex: 1 1 100%; width: 100%; min-width: 0; }
}

@media (max-width: 600px) {
    .plug-hero-counts { display: none; }
    .plug-grid { grid-template-columns: minmax(0, 1fr); }

    /* Card actions: unwrap the inline <form>s so their buttons join the flex
       row and lay out two-up (full-width when alone), keeping tap targets big. */
    .plug-actions { gap: 8px; }
    .plug-actions > form { display: contents; }
    .plug-actions .plug-act { flex: 1 1 calc(50% - 4px); justify-content: center; }
    .plug-actions .plug-spacer { display: none; }
}
</style>

<div class="plug-wrap">

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- ── Hero ─────────────────────────────────────────────── -->
    <section class="plug-hero">
        <div class="plug-hero-row">
            <div>
                <div class="plug-hero-eyebrow"><?= __('plugins', 'Plugins') ?> · <?= __('extensions', 'Extensions') ?></div>
                <h1><?= __('plugins_hero_title', 'Extend your platform') ?></h1>
                <p><?= __('plugins_subtitle', 'Add features by installing and activating plugins.') ?></p>
            </div>
            <div class="plug-hero-counts">
                <div class="plug-hcount">
                    <div class="plug-hcount-v"><?= count($plugins) ?></div>
                    <div class="plug-hcount-k"><?= __('total', 'Total') ?></div>
                </div>
                <div class="plug-hcount is-active">
                    <div class="plug-hcount-v"><?= $countActive ?></div>
                    <div class="plug-hcount-k"><?= __('active', 'Active') ?></div>
                </div>
                <div class="plug-hcount is-inactive">
                    <div class="plug-hcount-v"><?= $countInactive + $countInstalled ?></div>
                    <div class="plug-hcount-k"><?= __('inactive', 'Inactive') ?></div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Upload ───────────────────────────────────────────── -->
    <?php if ($canUpload): ?>
    <section class="plug-upload">
        <div class="plug-upload-head">
            <div class="plug-upload-title">
                <span class="plug-upload-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </span>
                <h2><?= __('upload_plugin', 'Upload a plugin') ?></h2>
            </div>
            <?php if ($hasExampleZip): ?>
                <a href="<?= e(SLATE_URL) ?>/plugins/_dist/hello-world-v1.0.0.zip" class="plug-act">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <?= __('download_example', 'Download example plugin') ?>
                </a>
            <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data" class="plug-upload-form" id="plug-upload-form">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="upload">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= SLATE_PLUGIN_UPLOAD_MAX ?>">

            <label class="plug-dropzone" id="plug-dropzone" for="plugin_zip">
                <span class="plug-drop-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v9"/>
                    </svg>
                </span>
                <span class="plug-drop-text">
                    <span class="plug-drop-strong"><b><?= __('choose_file', 'Choose a file') ?></b> <?= __('or_drag_drop', 'or drag &amp; drop it here') ?></span>
                    <span class="plug-drop-sub"><?= __('upload_hint', 'Upload a .zip file containing a single plugin folder. Max 10 MB.') ?></span>
                    <span class="plug-drop-file" id="plug-drop-file"></span>
                </span>
                <input type="file" id="plugin_zip" name="plugin_zip" accept=".zip,application/zip" required>
            </label>

            <div class="plug-upload-foot">
                <button type="submit" class="plug-act plug-act-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <?= __('upload_and_install', 'Upload &amp; install') ?>
                </button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <!-- ── Installed plugins ────────────────────────────────── -->
    <?php if (empty($plugins)): ?>
        <div class="plug-empty">
            <div class="plug-empty-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v9"/>
                </svg>
            </div>
            <h3><?= __('no_plugins_installed', 'No plugins installed') ?></h3>
            <p><?= __('no_plugins_intro', 'Kohevo is a clean shell right now. Upload your first plugin above.') ?></p>
        </div>
    <?php else: ?>
        <div class="plug-toolbar" id="plug-toolbar">
            <div class="plug-tabs" role="tablist" aria-label="<?= __('filter_by_status', 'Filter by status') ?>">
                <button type="button" class="plug-tab is-on" data-tab="all" role="tab" aria-selected="true">
                    <span class="plug-tab-dot"></span><?= __('all', 'All') ?>
                    <span class="plug-tab-n"><?= count($plugins) ?></span>
                </button>
                <button type="button" class="plug-tab" data-tab="active" role="tab" aria-selected="false">
                    <span class="plug-tab-dot"></span><?= __('active', 'Active') ?>
                    <span class="plug-tab-n"><?= $countActive ?></span>
                </button>
                <button type="button" class="plug-tab" data-tab="inactive" role="tab" aria-selected="false">
                    <span class="plug-tab-dot"></span><?= __('inactive', 'Inactive') ?>
                    <span class="plug-tab-n"><?= $countInactive ?></span>
                </button>
                <?php if ($countInstalled > 0): ?>
                <button type="button" class="plug-tab" data-tab="installed" role="tab" aria-selected="false">
                    <span class="plug-tab-dot"></span><?= __('installed', 'Installed') ?>
                    <span class="plug-tab-n"><?= $countInstalled ?></span>
                </button>
                <?php endif; ?>
            </div>
            <label class="plug-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
                </svg>
                <input type="search" id="plug-search-input" placeholder="<?= __('search_plugins', 'Search plugins…') ?>"
                       autocomplete="off" aria-label="<?= __('search_plugins', 'Search plugins…') ?>">
            </label>
        </div>

        <div class="plug-grid" id="plug-grid">
            <?php foreach ($plugins as $p):
                $status   = $p['status'];
                $manifest = json_decode($p['manifest_json'] ?? '{}', true) ?: [];
                $author   = $manifest['author']      ?? '';
                $desc     = $manifest['description'] ?? '';
                $homepage = $manifest['homepage']    ?? '';
                $downloadUrl = SLATE_URL . '/admin/plugins.php?_action=download&slug='
                             . rawurlencode($p['slug']) . '&_csrf=' . rawurlencode($csrf);
                $haystack = strtolower($p['name'] . ' ' . $p['slug'] . ' ' . $author . ' ' . $desc);
                $isSystem = slate_is_system_plugin($p['slug'], $manifest);
            ?>
            <article class="plug-card is-<?= e($status) ?><?= $isSystem ? ' is-system-plugin' : '' ?>"
                     data-status="<?= e($status) ?>"
                     data-search="<?= e($haystack) ?>">
                <div class="plug-card-top">
                    <span class="plug-avatar" aria-hidden="true"><?= e(mb_substr($p['name'], 0, 2)) ?></span>
                    <div class="plug-id">
                        <div class="plug-name"><?= e($p['name']) ?></div>
                        <div class="plug-slug"><?= e($p['slug']) ?> · v<?= e($p['version']) ?></div>
                    </div>
                    <?php if ($isSystem): ?>
                        <span class="plug-pill is-system" title="<?= e(__('system_plugin_hint', 'Built-in — always on, can\'t be removed')) ?>"><?= __('system', 'System') ?></span>
                    <?php else: ?>
                        <span class="plug-pill is-<?= e($status) ?>"><?= e(__($status, ucfirst($status))) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($desc !== ''): ?>
                    <p class="plug-desc"><?= e($desc) ?></p>
                <?php endif; ?>

                <div class="plug-meta">
                    <?php if ($author !== ''): ?>
                        <span class="plug-tag"><?= __('author', 'Author') ?>: <b><?= e($author) ?></b></span>
                    <?php endif; ?>
                    <?php if (!empty($p['installed_at'])): ?>
                        <span class="plug-tag"><?= __('installed', 'Installed') ?>: <b><?= e(substr((string)$p['installed_at'], 0, 10)) ?></b></span>
                    <?php endif; ?>
                    <?php if ($homepage !== ''): ?>
                        <span class="plug-tag"><a href="<?= e($homepage) ?>" target="_blank" rel="noopener"><?= __('homepage', 'Homepage') ?> ↗</a></span>
                    <?php endif; ?>
                    <?php if (!empty($manifest['capabilities'])): ?>
                        <span class="plug-tag" style="background:var(--accent-soft);color:var(--accent);font-weight:600;">
                            <?= count($manifest['capabilities']) ?> <?= __('capabilities', 'Capabilities') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="plug-actions">
                    <?php if (!empty($manifest['capabilities']) && $status === 'active'): ?>
                        <button type="button" class="plug-act" onclick="document.getElementById('cap-modal-<?= e($p['slug']) ?>').showModal()" title="<?= __('configure_capabilities', 'Configure capabilities') ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                            </svg>
                            <?= __('capabilities', 'Capabilities') ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($isSystem && $status === 'active'): ?>
                        <button type="button" class="plug-act plug-act-locked" disabled
                                title="<?= e(__('system_plugin_hint', 'Built-in — always on, can\'t be removed')) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                            </svg>
                            <?= __('required', 'Required') ?>
                        </button>
                    <?php elseif ($status === 'active'): ?>
                        <?php if (Auth::isPlatformSuperAdmin() || (slate_is_client_managed_plugin((string) $p['slug']) && Auth::can('plugins.manage'))): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="deactivate">
                            <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                            <button type="submit" class="plug-act"><?= __('deactivate', 'Deactivate') ?></button>
                        </form>
                        <?php endif; ?>
                    <?php elseif ($status === 'inactive' || $status === 'installed'): ?>
                        <?php if (Auth::isPlatformSuperAdmin() || (slate_is_client_managed_plugin((string) $p['slug']) && Auth::can('plugins.manage'))): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="activate">
                            <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                            <button type="submit" class="plug-act plug-act-primary"><?= __('activate', 'Activate') ?></button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <a class="plug-act" href="<?= e($downloadUrl) ?>"
                       title="<?= e(sprintf(__('download_plugin_zip', 'Download %s as a ZIP'), $p['name'])) ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <?= __('download_zip', 'Download ZIP') ?>
                    </a>

                    <?php if ($canUninstall && $status !== 'active' && !$isSystem): ?>
                        <span class="plug-spacer"></span>
                        <form method="post"
                              onsubmit="return confirm(<?= e(json_encode(
                                  sprintf(__('confirm_uninstall',
                                      "Uninstall %s? This will delete all of this plugin's data and cannot be undone."),
                                      $p['slug']))) ?>);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="uninstall">
                            <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                            <button type="submit" class="plug-act plug-act-danger" title="<?= __('uninstall', 'Uninstall') ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                                <?= __('uninstall', 'Uninstall') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>

            <?php if (!empty($manifest['capabilities'])): ?>
            <dialog id="cap-modal-<?= e($p['slug']) ?>" class="slate-modal" style="border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;max-width:540px;width:90vw;background:var(--surface);color:var(--text);box-shadow:var(--shadow-xl);">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="save_capabilities">
                    <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                        <h2 style="margin:0;font-size:17px;font-weight:700;"><?= e($p['name']) ?> · <?= __('capabilities', 'Capabilities') ?></h2>
                        <button type="button" onclick="this.closest('dialog').close()" style="background:none;border:none;cursor:pointer;font-size:22px;line-height:1;color:var(--muted);">&times;</button>
                    </div>
                    <p class="text-sm text-muted" style="margin-bottom:18px;"><?= __('capabilities_intro', 'Enable or disable modular capabilities independently. Disabled capabilities deactivate admin navigation and bypass runtime hooks.') ?></p>
                    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:22px;">
                        <?php foreach ($manifest['capabilities'] as $capKey => $capDef):
                            $isEnabled = PluginLoader::isCapabilityEnabled($p['slug'], (string)$capKey);
                        ?>
                            <label style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface-2);cursor:pointer;">
                                <input type="checkbox" name="capabilities[<?= e($capKey) ?>]" value="1" <?= $isEnabled ? 'checked' : '' ?> style="margin-top:3px;">
                                <div>
                                    <div style="font-weight:600;font-size:13.5px;"><?= e($capDef['name']) ?></div>
                                    <div class="text-sm text-muted" style="margin-top:2px;font-size:12px;"><?= e($capDef['description']) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" onclick="this.closest('dialog').close()" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></button>
                        <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Save changes') ?></button>
                    </div>
                </form>
            </dialog>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="plug-noresults" id="plug-noresults">
            <?= __('no_plugins_match', 'No plugins match this filter.') ?>
        </div>
    <?php endif; ?>

</div>

<?php if ($canUpload): ?>
<script>
(function () {
    var zone = document.getElementById('plug-dropzone');
    var input = document.getElementById('plugin_zip');
    var label = document.getElementById('plug-drop-file');
    if (!zone || !input) return;

    function show() {
        if (input.files && input.files.length) {
            label.textContent = input.files[0].name;
            zone.classList.add('has-file');
        } else {
            zone.classList.remove('has-file');
        }
    }
    input.addEventListener('change', show);

    ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-drag'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-drag'); });
    });
    zone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            show();
        }
    });
})();
</script>
<?php endif; ?>

<!-- Tab + search filtering for the plugin grid -->
<script>
(function () {
    var toolbar = document.getElementById('plug-toolbar');
    var grid    = document.getElementById('plug-grid');
    if (!toolbar || !grid) return;

    var tabs    = Array.prototype.slice.call(toolbar.querySelectorAll('.plug-tab'));
    var search  = document.getElementById('plug-search-input');
    var none    = document.getElementById('plug-noresults');
    var cards   = Array.prototype.slice.call(grid.querySelectorAll('.plug-card'));
    var filter  = 'all';

    function apply() {
        var q = (search && search.value || '').trim().toLowerCase();
        var shown = 0;
        cards.forEach(function (card) {
            var okStatus = filter === 'all' || card.getAttribute('data-status') === filter;
            var okSearch = q === '' || (card.getAttribute('data-search') || '').indexOf(q) !== -1;
            var visible  = okStatus && okSearch;
            card.classList.toggle('is-hidden', !visible);
            if (visible) shown++;
        });
        if (none) none.classList.toggle('is-on', shown === 0);
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            filter = tab.getAttribute('data-tab') || 'all';
            tabs.forEach(function (t) {
                var on = t === tab;
                t.classList.toggle('is-on', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            apply();
        });
    });

    if (search) search.addEventListener('input', apply);
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
