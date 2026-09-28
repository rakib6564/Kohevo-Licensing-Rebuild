<?php
/**
 * Media library — compatibility shim.
 *
 * The media library is now a CORE Slate service (includes/Media.php).
 * This plugin is retained as a thin shim so that:
 *   - existing call sites (shop, content-builder, seo) that use
 *     `MediaLibrary::register/isManagedPath/...` keep working,
 *   - the picker assets under assets/ keep resolving at their
 *     historical /plugins/media-library/assets/... URLs,
 *   - `PluginLoader::isActive('media-library')` stays truthy.
 *
 * Every data method delegates to the core `Media` class. The one piece
 * of real logic kept here is the shop/branding reference scan
 * (collectReferences), which is registered as the `media_usage` hook
 * so those legacy string references count as "in use" in the shared
 * library WITHOUT a data migration.
 *
 * The core nav item lives in admin/partials/header.php now, so this
 * plugin no longer adds its own (that would duplicate it).
 */

class MediaLibrary extends Plugin {

    /** Folders the legacy reference scan checks on disk (broken-ref diagnostics). */
    public const SCAN_FOLDERS = [
        'shop/products',
        'shop/products/gallery',
        'shop/products/variants',
        'branding',
    ];

    /** Kept for back-compat: shop reads MediaLibrary::ALLOWED_MIMES/EXTS. */
    public const ALLOWED_EXTS  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function boot(): void {
        // Report legacy shop/branding references to the core library so
        // files referenced by products/variants/logo show as "in use".
        Hook::addFilter('media_usage', [self::class, 'legacyUsageFilter']);
        Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);

        // Dashboard widget intentionally disabled — the dashboard surfaces the
        // operational widgets (Forms, etc.) instead of a media file list. The
        // Media library remains fully available from its own nav page.
        // Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    /** Hook: union shop/branding string references into the usage map. */
    public static function legacyUsageFilter(array $map): array {
        try {
            foreach (self::collectReferences()['by_path'] as $path => $n) {
                $map[$path] = ($map[$path] ?? 0) + (int)$n;
            }
        } catch (\Throwable $e) { /* never break the listing */ }
        return $map;
    }

    /** Dashboard widget — library size + recent uploads (reads media_files). */
    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('media.view') && !Auth::isSuperAdmin()) return $widgets;
        $tid = current_tenant_id();
        try {
            $files  = (int) Database::value("SELECT COUNT(*) FROM media_files WHERE tenant_id = ?", [$tid]);
            $bytes  = (int) Database::value("SELECT COALESCE(SUM(size_bytes),0) FROM media_files WHERE tenant_id = ?", [$tid]);
            $latest = Database::rows("SELECT original_name, path, mime, size_bytes, uploaded_at FROM media_files WHERE tenant_id = ? ORDER BY id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $fmt = static function (int $b): string {
            $units = ['B', 'KB', 'MB', 'GB', 'TB']; $s = (float)$b; $u = 0;
            while ($s >= 1024 && $u < count($units) - 1) { $s /= 1024; $u++; }
            return ($u === 0 ? (string)(int)$s : number_format($s, 1)) . ' ' . $units[$u];
        };
        $libUrl = SLATE_URL . '/admin/media.php';

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('media_library', 'Media') ?></h2>
                <a href="<?= e($libUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('media_files', 'Files') ?></div>
                    <div class="dwidget-kpi-v"><?= $files ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('media_storage', 'Storage') ?></div>
                    <div class="dwidget-kpi-v"><?= e($fmt($bytes)) ?></div>
                </div>
            </div>
            <?php if ($latest): ?>
                <div class="dlist">
                    <?php foreach ($latest as $m):
                        $name = (string)($m['original_name'] ?? '') ?: basename((string)$m['path']);
                        $ext  = strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: '?');
                        slate_dlist_row([
                            'avatar'       => $ext,
                            'avatar_color' => 'muted',
                            'title'        => $name,
                            'sub'          => (string)($m['mime'] ?? ''),
                            'amount'       => $fmt((int)$m['size_bytes']),
                            'time'         => $m['uploaded_at'] ? I18n::localDate('M j', strtotime($m['uploaded_at'])) : '',
                            'href'         => $libUrl,
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('media_no_files', 'No files yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ──────────────────────────────────────────────────────────
    // Back-compat API — delegates to the core Media service
    // ──────────────────────────────────────────────────────────

    /** @return array Flat list of media items (legacy shape). */
    public static function listAll(): array {
        return Media::listAll(['per_page' => 100000])['items'];
    }

    public static function register(string $path, array $meta): void {
        Media::register($path, $meta);
    }

    public static function unregister(string $path): void {
        Media::unregister($path);
    }

    public static function buildUsageMap(): array {
        return Media::buildUsageMapByPath();
    }

    public static function delete(string $path): array {
        return Media::deleteByPath($path);
    }

    public static function isManagedPath(string $path): bool {
        return Media::isManagedPath($path);
    }

    // ──────────────────────────────────────────────────────────
    // Legacy reference scan (shop products/variants/gallery + branding)
    //
    // Kept here because it encodes shop-specific knowledge. Feeds both
    // the `media_usage` hook (above) and the diagnostics panel on the
    // legacy plugin admin page. Returns:
    //   ['by_path' => ['/uploads/...'=>n,...], 'broken' => [...]]
    // ──────────────────────────────────────────────────────────
    public static function collectReferences(): array {
        $tid = current_tenant_id();
        $byPath = [];
        $broken = [];

        $diskPaths = [];
        foreach (self::SCAN_FOLDERS as $folder) {
            $dir = SLATE_ROOT . '/uploads/' . $folder;
            if (!is_dir($dir)) continue;
            $entries = @scandir($dir) ?: [];
            foreach ($entries as $name) {
                if ($name === '' || $name[0] === '.') continue;
                if (!is_file($dir . '/' . $name)) continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, self::ALLOWED_EXTS, true)) continue;
                $diskPaths['/uploads/' . $folder . '/' . $name] = true;
            }
        }

        $normalize = function ($p) {
            $p = trim((string)$p);
            if ($p === '') return '';
            if ($p[0] !== '/' && !preg_match('#^https?://#i', $p)) $p = '/' . $p;
            return $p;
        };
        $bump = function ($p) use (&$byPath) {
            if ($p !== '') $byPath[$p] = ($byPath[$p] ?? 0) + 1;
        };
        $flagBroken = function ($source, $id, $name, $p) use (&$broken, $diskPaths) {
            if ($p === '' || preg_match('#^https?://#i', $p)) return;
            if (!isset($diskPaths[$p])) {
                $broken[] = ['source' => $source, 'id' => $id, 'name' => $name, 'path' => $p];
            }
        };

        try {
            $rows = Database::rows(
                "SELECT id, name, image_url, gallery_urls FROM shop_products WHERE tenant_id = ?",
                [$tid]
            );
            foreach ($rows as $r) {
                $p = $normalize($r['image_url'] ?? '');
                if ($p !== '') { $bump($p); $flagBroken('product', (int)$r['id'], (string)$r['name'], $p); }
                $gallery = (string)($r['gallery_urls'] ?? '');
                if ($gallery !== '') {
                    $decoded = json_decode($gallery, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $g) {
                            if (!is_string($g)) continue;
                            $p = $normalize($g);
                            $bump($p);
                            $flagBroken('product.gallery', (int)$r['id'], (string)$r['name'], $p);
                        }
                    }
                }
            }
        } catch (\Throwable $e) { /* shop_products may not exist */ }

        try {
            $rows = Database::rows(
                "SELECT v.id, v.image_url, v.attribute, v.value, p.name AS product_name
                   FROM shop_product_variants v
                   JOIN shop_products p ON p.id = v.product_id
                  WHERE p.tenant_id = ?
                    AND v.image_url IS NOT NULL
                    AND v.image_url <> ''",
                [$tid]
            );
            foreach ($rows as $r) {
                $p = $normalize($r['image_url']);
                $bump($p);
                $label = $r['product_name'] . ' [' . $r['attribute'] . ': ' . $r['value'] . ']';
                $flagBroken('variant', (int)$r['id'], $label, $p);
            }
        } catch (\Throwable $e) { /* may not exist */ }

        try {
            $logo = Database::setting('brand_logo_path');
            if (is_string($logo) && $logo !== '') {
                $p = $normalize($logo);
                $bump($p);
                $flagBroken('branding', 0, 'Site logo', $p);
            }
        } catch (\Throwable $e) { /* unlikely */ }

        return ['by_path' => $byPath, 'broken' => $broken];
    }
}
