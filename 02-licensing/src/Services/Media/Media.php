<?php
/**
 * Slate — Media (core shared media library).
 *
 * Built-in, WordPress-style media library shared by every plugin
 * (shop, booking, membership, …). Promoted from the former
 * `media-library` plugin so media lives in core and any feature can
 * upload, browse, pick, and safely reference files.
 *
 * Design:
 *   - `media_files` is the source of truth (DB-authoritative). New
 *     uploads land in /uploads/media/<Y>/<m>/. Files already on disk
 *     (shop products, branding, …) are surfaced by the importer
 *     (importKnown()) — nothing on disk is moved.
 *   - Usage tracking is pluggable: precise references live in
 *     `media_usage` (written via attach()/detach()); plugins may also
 *     report derived/legacy references via the `media_usage` Hook
 *     filter (a path => count map) without writing rows. "In use" and
 *     safe-delete merge both sources, keyed by path.
 *   - Everything is tenant-scoped via current_tenant_id().
 *
 * The legacy `MediaLibrary` plugin class is kept as a thin delegating
 * shim over this service (so content-builder / seo keep working and
 * the picker assets keep resolving).
 */

declare(strict_types=1);

namespace Slate\Services\Media;

// Phase 1 A3: migrated from includes/Media.php into Slate\Services\Media. The old
// global name `Media` resolves via a class_alias in src/compat/aliases.php.
// Behavior identical — cross-namespace static calls are qualified to the global
// aliases (\\Database::, \\AuditLog::, \Auth::, \\Hook::) and global classes to
// \DOMDocument/\DOMXPath/\finfo/\RecursiveIteratorIterator/\RecursiveDirectoryIterator.
// The sibling `Uploads` (same namespace) stays bareword.
class Media {

    /** Bumped when the schema changes so ensureSchema() re-runs. */
    public const SCHEMA_V = '1';

    /** Central folder (under /uploads/) for new uploads. */
    public const FOLDER = 'media';

    /**
     * Upload folders the importer scans to surface pre-existing files,
     * and the set of folders isManagedPath() treats as library media.
     * Files are registered in place; they are never moved.
     *
     * Deliberately limited to reusable media: the central /uploads/media
     * folder, shop product images (recurses into gallery/variants), and
     * branding. We do NOT auto-import private per-record uploads (form
     * submission attachments/e-signatures, member avatars) — those are
     * not library media. Plugins that want a file in the library upload
     * it through Media::upload() (into /uploads/media) or register it
     * explicitly.
     */
    public const KNOWN_IMPORT_FOLDERS = [
        'media',
        'shop/products',
        'branding',
    ];

    public const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    public const DOC_EXTS   = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

    // ──────────────────────────────────────────────────────────
    // Config helpers
    // ──────────────────────────────────────────────────────────

    public static function imageMimes(): array {
        return array_merge(Uploads::IMAGE_MIMES, ['image/svg+xml']);
    }

    public static function docMimes(): array {
        return Uploads::DOC_MIMES;
    }

    public static function allowedMimes(): array {
        return array_values(array_unique(array_merge(self::imageMimes(), self::docMimes())));
    }

    public static function allowedExts(): array {
        return array_values(array_unique(array_merge(self::IMAGE_EXTS, self::DOC_EXTS)));
    }

    /** image | document | other — from MIME (preferred) or extension. */
    public static function kindForMime(string $mime, string $ext = ''): string {
        $mime = strtolower(trim($mime));
        $ext  = strtolower(ltrim($ext, '.'));
        if ($mime !== '') {
            if (in_array($mime, self::imageMimes(), true) || str_starts_with($mime, 'image/')) return 'image';
            if (in_array($mime, self::docMimes(), true)) return 'document';
        }
        if ($ext !== '') {
            if (in_array($ext, self::IMAGE_EXTS, true)) return 'image';
            if (in_array($ext, self::DOC_EXTS, true))   return 'document';
        }
        return 'other';
    }

    // ──────────────────────────────────────────────────────────
    // Schema (self-installing on existing sites)
    // ──────────────────────────────────────────────────────────

    /**
     * Ensure the media tables exist. schema.sql only runs at fresh
     * install, so existing sites self-provision here on first use.
     * Guarded by a static flag (once per request) and a settings flag
     * (skip the DDL once provisioned). Mirrors Auth's login_attempts
     * pattern. Defensive: never throws to the caller.
     */
    public static function ensureSchema(): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            if (\Database::setting('media_schema_v') === self::SCHEMA_V) return;
        } catch (\Throwable $e) {
            // settings table missing → not installed yet; bail quietly.
            return;
        }

        try {
            \Database::query(
                "CREATE TABLE IF NOT EXISTS `media_files` (
                    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
                    `path`          VARCHAR(500) NOT NULL,
                    `kind`          ENUM('image','document','other') NOT NULL DEFAULT 'image',
                    `mime`          VARCHAR(120) NOT NULL DEFAULT '',
                    `size_bytes`    INT UNSIGNED NOT NULL DEFAULT 0,
                    `width`         INT UNSIGNED NULL,
                    `height`        INT UNSIGNED NULL,
                    `original_name` VARCHAR(255) NULL,
                    `folder`        VARCHAR(190) NOT NULL DEFAULT '',
                    `uploaded_by`   INT UNSIGNED NULL,
                    `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_path` (`tenant_id`, `path`),
                    KEY `tenant_kind` (`tenant_id`, `kind`),
                    KEY `tenant_uploaded_at` (`tenant_id`, `uploaded_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            \Database::query(
                "CREATE TABLE IF NOT EXISTS `media_usage` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
                    `media_id`    INT UNSIGNED NOT NULL,
                    `context`     VARCHAR(64)  NOT NULL,
                    `object_type` VARCHAR(64)  NOT NULL DEFAULT '',
                    `object_id`   VARCHAR(64)  NOT NULL DEFAULT '',
                    `field`       VARCHAR(64)  NOT NULL DEFAULT '',
                    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `usage_unique` (`tenant_id`, `media_id`, `context`, `object_type`, `object_id`, `field`),
                    KEY `media` (`media_id`),
                    CONSTRAINT `fk_media_usage_media` FOREIGN KEY (`media_id`) REFERENCES `media_files`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // Carry over rows from the old plugin cache, if present.
            // Idempotent: the unique (tenant_id, path) matches the source.
            try {
                \Database::query(
                    "INSERT INTO media_files
                        (tenant_id, path, kind, mime, size_bytes, width, height, original_name, folder, uploaded_by, uploaded_at)
                     SELECT
                        tenant_id, path,
                        CASE WHEN mime LIKE 'image/%' THEN 'image'
                             WHEN mime <> '' THEN 'document'
                             ELSE 'image' END,
                        mime, size_bytes, width, height, original_name,
                        SUBSTRING_INDEX(SUBSTRING(path, 10), '/', 1),
                        uploaded_by, uploaded_at
                     FROM medialibrary_files
                     ON DUPLICATE KEY UPDATE
                        mime          = VALUES(mime),
                        size_bytes    = VALUES(size_bytes),
                        width         = VALUES(width),
                        height        = VALUES(height),
                        original_name = VALUES(original_name)"
                );
            } catch (\Throwable $e) {
                // Old table may not exist — fine, the importer covers it.
            }

            // Surface files already on disk so nothing disappears at upgrade.
            try { self::importKnown(); } catch (\Throwable $e) { /* best-effort */ }

            \Database::setSetting('media_schema_v', self::SCHEMA_V);
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('Media: ensureSchema failed: ' . $e->getMessage(), 'error');
            }
        }
    }

    // ──────────────────────────────────────────────────────────
    // Read
    // ──────────────────────────────────────────────────────────

    /** One media record by id (tenant-scoped), decorated, or null. */
    public static function get(int $id): ?array {
        $tid = current_tenant_id();
        try {
            $row = \Database::row(
                "SELECT * FROM media_files WHERE tenant_id = ? AND id = ?",
                [$tid, $id]
            );
        } catch (\Throwable $e) { return null; }
        if (!$row) return null;
        return self::decorate($row, self::buildUsageMapByPath());
    }

    /** One media record by path (tenant-scoped), decorated, or null. */
    public static function findByPath(string $path): ?array {
        $tid = current_tenant_id();
        try {
            $row = \Database::row(
                "SELECT * FROM media_files WHERE tenant_id = ? AND path = ?",
                [$tid, $path]
            );
        } catch (\Throwable $e) { return null; }
        if (!$row) return null;
        return self::decorate($row, self::buildUsageMapByPath());
    }

    /**
     * List media. Filters:
     *   type    'all' | 'image' | 'document'
     *   usage   'all' | 'in_use' | 'unused'
     *   search  matches original_name / path
     *   page, per_page  (pagination; per_page default 24)
     *
     * Returns ['items'=>[...decorated...], 'total'=>int, 'page'=>int, 'pages'=>int].
     */
    public static function listAll(array $filters = []): array {
        $tid     = current_tenant_id();
        $type    = (string)($filters['type']   ?? 'all');
        $usage   = (string)($filters['usage']  ?? 'all');
        $search  = trim((string)($filters['search'] ?? ''));
        $perPage = max(1, (int)($filters['per_page'] ?? 24));
        $page    = max(1, (int)($filters['page'] ?? 1));

        $where  = ['tenant_id = ?'];
        $params = [$tid];
        if ($type === 'image') { $where[] = "kind = 'image'"; }
        elseif ($type === 'document') { $where[] = "kind <> 'image'"; }
        if ($search !== '') {
            $where[] = '(original_name LIKE ? OR path LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $usageMap = self::buildUsageMapByPath();

        try {
            $rows = \Database::rows(
                "SELECT * FROM media_files WHERE $whereSql ORDER BY uploaded_at DESC, id DESC",
                $params
            );
        } catch (\Throwable $e) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }

        // Decorate, then apply the usage filter (needs the merged map).
        $items = [];
        foreach ($rows as $r) {
            $d = self::decorate($r, $usageMap);
            if ($usage === 'in_use'  && !$d['in_use']) continue;
            if ($usage === 'unused'  &&  $d['in_use']) continue;
            $items[] = $d;
        }

        $total = count($items);
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Tab/badge counts for the admin UI:
     *   ['all','image','document','in_use','unused'].
     */
    public static function counts(): array {
        $tid = current_tenant_id();
        $c = ['all' => 0, 'image' => 0, 'document' => 0, 'in_use' => 0, 'unused' => 0];
        try {
            $rows = \Database::rows(
                "SELECT path, kind FROM media_files WHERE tenant_id = ?",
                [$tid]
            );
        } catch (\Throwable $e) { return $c; }
        $usageMap = self::buildUsageMapByPath();
        foreach ($rows as $r) {
            $c['all']++;
            if (($r['kind'] ?? 'image') === 'image') $c['image']++; else $c['document']++;
            if (($usageMap[$r['path']] ?? 0) > 0) $c['in_use']++; else $c['unused']++;
        }
        return $c;
    }

    /** Shape a raw row for callers: add url, in_use, usage_count, int casts. */
    private static function decorate(array $row, array $usageMap): array {
        $path = (string)($row['path'] ?? '');
        return [
            'id'            => (int)($row['id'] ?? 0),
            'path'          => $path,
            'url'           => self::url($path),
            'kind'          => (string)($row['kind'] ?? 'image'),
            'mime'          => (string)($row['mime'] ?? ''),
            'size_bytes'    => (int)($row['size_bytes'] ?? 0),
            'width'         => isset($row['width'])  && $row['width']  !== null ? (int)$row['width']  : null,
            'height'        => isset($row['height']) && $row['height'] !== null ? (int)$row['height'] : null,
            'original_name' => (string)($row['original_name'] ?? basename($path)),
            'folder'        => (string)($row['folder'] ?? ''),
            'uploaded_at'   => (string)($row['uploaded_at'] ?? ''),
            'in_use'        => ($usageMap[$path] ?? 0) > 0,
            'usage_count'   => (int)($usageMap[$path] ?? 0),
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Upload + register
    // ──────────────────────────────────────────────────────────

    /**
     * Handle an upload from $_FILES[$field] into /uploads/media/<Y>/<m>/,
     * register it, and return the full media record (or ['ok'=>false,...]).
     * Allowed mimes/exts default to the core sets; callers may narrow.
     */
    public static function upload(string $field, array $opts = []): array {
        $folder = self::FOLDER . '/' . date('Y/m');
        $res = Uploads::handle($field, $folder, [
            'allowed_mimes' => $opts['allowed_mimes'] ?? self::allowedMimes(),
            'allowed_exts'  => $opts['allowed_exts']  ?? self::allowedExts(),
            'max_bytes'     => (int)($opts['max_bytes'] ?? Uploads::DEFAULT_MAX_BYTES),
        ]);
        if (empty($res['ok'])) return $res;

        $path = (string)$res['path'];
        $mime = (string)($res['mime'] ?? '');
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Harden SVG: as XML it can carry <script>, event handlers, and
        // javascript: URLs that run in this origin when the file URL is
        // opened directly — stored XSS. Sanitize in place; if it can't be
        // parsed cleanly, reject and delete rather than store a live payload.
        if ($ext === 'svg' || $mime === 'image/svg+xml') {
            if (!self::sanitizeSvgFile(SLATE_ROOT . $path)) {
                Uploads::remove($path);
                return ['ok' => false, 'error' => 'Could not process SVG file.'];
            }
        }

        $w = null; $h = null;
        if (self::kindForMime($mime, $ext) === 'image') {
            $info = @getimagesize(SLATE_ROOT . $path);
            if (is_array($info)) { $w = $info[0] ?? null; $h = $info[1] ?? null; }
        }

        $id = self::register($path, [
            'mime'          => $mime,
            'size_bytes'    => (int)($res['size'] ?? 0),
            'width'         => $w,
            'height'        => $h,
            'original_name' => $res['original'] ?? basename($path),
        ]);

        \AuditLog::record('media.uploaded', $path, ['id' => $id]);
        return self::get($id) ?? ($res + ['id' => $id]);
    }

    /**
     * Strip active content from an SVG file in place. Returns true if the
     * file parsed and was rewritten safely, false if it could not be parsed
     * (the caller then rejects the upload). Removes script/foreignObject/
     * embed-style elements, all on* event-handler attributes, and
     * javascript:/data:text/html URLs. Parsing uses LIBXML_NONET so no
     * external entity or DTD is ever fetched (blocks XXE/SSRF).
     */
    public static function sanitizeSvgFile(string $absPath): bool {
        if (!class_exists('DOMDocument')) return false;
        $svg = @file_get_contents($absPath);
        if ($svg === false || trim($svg) === '') return false;

        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        $ok   = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$doc->documentElement) return false;
        if (strtolower($doc->documentElement->localName ?? '') !== 'svg') return false;

        $blockedTags = [
            'script','foreignobject','iframe','embed','object',
            'audio','video','animate','animatetransform','animatemotion',
            'set','handler','use',
        ];
        $xpath = new \DOMXPath($doc);

        // Remove blocked elements (snapshot first — we mutate the tree).
        foreach (iterator_to_array($xpath->query('//*')) as $el) {
            $local = strtolower($el->localName ?? $el->nodeName);
            if (in_array($local, $blockedTags, true)) {
                $el->parentNode?->removeChild($el);
            }
        }
        // Strip dangerous attributes from everything that remains.
        foreach (iterator_to_array($xpath->query('//*')) as $el) {
            if (!$el->hasAttributes()) continue;
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                $val  = strtolower(preg_replace('/\s+/', '', $attr->nodeValue ?? ''));
                if (str_starts_with($name, 'on')
                    || in_array($name, ['href','xlink:href','src','from','to','values'], true)
                       && (str_starts_with($val, 'javascript:') || str_starts_with($val, 'data:text/html'))) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
        }

        $out = $doc->saveXML();
        if ($out === false) return false;
        return @file_put_contents($absPath, $out) !== false;
    }

    /**
     * Upsert a media row keyed on (tenant, path); returns its id.
     * Idempotent — safe to call for an already-registered file.
     */
    public static function register(string $path, array $meta = []): int {
        $tid  = current_tenant_id();
        $mime = (string)($meta['mime'] ?? '');
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $kind = self::kindForMime($mime, $ext);
        $folder = self::folderOf($path);

        try {
            \Database::query(
                "INSERT INTO media_files
                    (tenant_id, path, kind, mime, size_bytes, width, height, original_name, folder, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    kind = VALUES(kind), mime = VALUES(mime), size_bytes = VALUES(size_bytes),
                    width = VALUES(width), height = VALUES(height),
                    original_name = VALUES(original_name), folder = VALUES(folder)",
                [
                    $tid, $path, $kind, $mime,
                    (int)($meta['size_bytes'] ?? 0),
                    $meta['width']  ?? null,
                    $meta['height'] ?? null,
                    (string)($meta['original_name'] ?? basename($path)),
                    $folder,
                    class_exists('Auth') ? \Auth::userId() : null,
                ]
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) slate_log('Media::register failed: ' . $e->getMessage(), 'warning');
            return 0;
        }

        $row = \Database::row("SELECT id FROM media_files WHERE tenant_id = ? AND path = ?", [$tid, $path]);
        return (int)($row['id'] ?? 0);
    }

    /** Remove the media row for a path (usage rows cascade). */
    public static function unregister(string $path): void {
        $tid = current_tenant_id();
        try {
            \Database::delete('media_files', 'tenant_id = ? AND path = ?', [$tid, $path]);
        } catch (\Throwable $e) { /* ignore */ }
    }

    // ──────────────────────────────────────────────────────────
    // Usage tracking (pluggable)
    // ──────────────────────────────────────────────────────────

    /**
     * Record that a media file is used. $ctx keys:
     *   context (required, e.g. 'shop'), object_type, object_id, field.
     * Idempotent via the unique key.
     */
    public static function attach(int $mediaId, array $ctx): void {
        if ($mediaId <= 0) return;
        $tid = current_tenant_id();
        try {
            \Database::query(
                "INSERT INTO media_usage (tenant_id, media_id, context, object_type, object_id, field)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE created_at = created_at",
                [
                    $tid, $mediaId,
                    (string)($ctx['context'] ?? ''),
                    (string)($ctx['object_type'] ?? ''),
                    (string)($ctx['object_id'] ?? ''),
                    (string)($ctx['field'] ?? ''),
                ]
            );
        } catch (\Throwable $e) { /* ignore */ }
    }

    /** Convenience: resolve/register a path to an id, then attach(). */
    public static function attachByPath(string $path, array $ctx): void {
        $path = trim($path);
        if ($path === '' || !self::isManagedPath($path)) return;
        $row = \Database::row("SELECT id FROM media_files WHERE tenant_id = ? AND path = ?",
            [current_tenant_id(), $path]);
        $id = (int)($row['id'] ?? 0);
        if ($id === 0) $id = self::register($path);
        if ($id > 0) self::attach($id, $ctx);
    }

    /** Remove usage rows. Empty $ctx clears all usage for the media id. */
    public static function detach(int $mediaId, array $ctx = []): void {
        if ($mediaId <= 0) return;
        $tid = current_tenant_id();
        $where  = ['tenant_id = ?', 'media_id = ?'];
        $params = [$tid, $mediaId];
        foreach (['context', 'object_type', 'object_id', 'field'] as $k) {
            if (isset($ctx[$k])) { $where[] = "$k = ?"; $params[] = (string)$ctx[$k]; }
        }
        try {
            \Database::delete('media_usage', implode(' AND ', $where), $params);
        } catch (\Throwable $e) { /* ignore */ }
    }

    /** Clear all usage rows for a given object (call before re-attaching). */
    public static function detachByObject(string $context, string $objectType, $objectId): void {
        $tid = current_tenant_id();
        try {
            \Database::delete('media_usage',
                'tenant_id = ? AND context = ? AND object_type = ? AND object_id = ?',
                [$tid, $context, $objectType, (string)$objectId]);
        } catch (\Throwable $e) { /* ignore */ }
    }

    /** Precise usage rows for a media id (from the media_usage table). */
    public static function usageFor(int $mediaId): array {
        $tid = current_tenant_id();
        try {
            return \Database::rows(
                "SELECT context, object_type, object_id, field, created_at
                   FROM media_usage WHERE tenant_id = ? AND media_id = ?",
                [$tid, $mediaId]
            );
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Path => reference-count map for the current tenant. Combines
     * `media_usage` rows (joined to file paths) with the `media_usage`
     * Hook filter, so plugins can report derived/legacy references
     * (e.g. shop scanning its product image_url columns) without
     * writing rows. This is the authoritative "in use" signal.
     */
    public static function buildUsageMapByPath(): array {
        $tid = current_tenant_id();
        $map = [];
        try {
            $rows = \Database::rows(
                "SELECT f.path AS path, COUNT(*) AS c
                   FROM media_usage u
                   JOIN media_files f ON f.id = u.media_id
                  WHERE u.tenant_id = ?
                  GROUP BY f.path",
                [$tid]
            );
            foreach ($rows as $r) {
                $map[(string)$r['path']] = (int)$r['c'];
            }
        } catch (\Throwable $e) { /* tables may not exist yet */ }

        if (class_exists('Hook')) {
            $filtered = \Hook::applyFilters('media_usage', $map);
            if (is_array($filtered)) $map = $filtered;
        }
        return $map;
    }

    /** media_id => count (table only; no hook). For diagnostics. */
    public static function buildUsageMap(): array {
        $tid = current_tenant_id();
        $map = [];
        try {
            $rows = \Database::rows(
                "SELECT media_id, COUNT(*) AS c FROM media_usage WHERE tenant_id = ? GROUP BY media_id",
                [$tid]
            );
            foreach ($rows as $r) $map[(int)$r['media_id']] = (int)$r['c'];
        } catch (\Throwable $e) { /* ignore */ }
        return $map;
    }

    public static function usageCountByPath(string $path): int {
        return (int)(self::buildUsageMapByPath()[$path] ?? 0);
    }

    // ──────────────────────────────────────────────────────────
    // Delete (safe — refuses if in use)
    // ──────────────────────────────────────────────────────────

    /** Delete by media id. Refuses if the file is referenced anywhere. */
    public static function delete(int $id): array {
        $rec = self::get($id);
        if (!$rec) return ['ok' => false, 'error' => 'Media not found.'];
        return self::deletePath($rec['path']);
    }

    /** Delete by path. Refuses if the file is referenced anywhere. */
    public static function deleteByPath(string $path): array {
        return self::deletePath($path);
    }

    private static function deletePath(string $path): array {
        if (!self::isManagedPath($path)) {
            return ['ok' => false, 'error' => 'Path is not managed by the media library.'];
        }
        $count = self::usageCountByPath($path);
        if ($count > 0) {
            return [
                'ok'          => false,
                'usage_count' => $count,
                'error'       => sprintf(
                    'Cannot delete: this file is used in %d place%s. Remove those references first.',
                    $count, $count === 1 ? '' : 's'
                ),
            ];
        }
        if (!Uploads::remove($path)) {
            return ['ok' => false, 'error' => 'File could not be deleted (may already be gone).'];
        }
        self::unregister($path);
        \AuditLog::record('media.deleted', $path);
        return ['ok' => true, 'error' => null, 'usage_count' => 0];
    }

    // ──────────────────────────────────────────────────────────
    // Paths / URLs
    // ──────────────────────────────────────────────────────────

    public static function url(string $path): string {
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return SLATE_URL . '/' . ltrim($path, '/');
    }

    /** Folder of a /uploads/<folder>/<file> path (the part after uploads/). */
    private static function folderOf(string $path): string {
        $p = ltrim($path, '/');
        if (str_starts_with($p, 'uploads/')) $p = substr($p, strlen('uploads/'));
        $dir = trim(dirname($p), '/.');
        return $dir === '' ? '' : $dir;
    }

    /**
     * Is $path a media path we manage? Rejects traversal/backslashes;
     * accepts any file under /uploads/<KNOWN_IMPORT_FOLDERS>/ (nested
     * allowed, e.g. media/2026/06/abc.webp). Used for delete-target and
     * attach validation.
     */
    public static function isManagedPath(string $path): bool {
        if ($path === '') return false;
        if (str_contains($path, '..') || str_contains($path, '\\')) return false;
        if (preg_match('#^https?://#i', $path)) return false;
        foreach (self::KNOWN_IMPORT_FOLDERS as $folder) {
            $prefix = '/uploads/' . $folder . '/';
            if (str_starts_with($path, $prefix) && strlen($path) > strlen($prefix)) {
                return true;
            }
        }
        return false;
    }

    // ──────────────────────────────────────────────────────────
    // Importer — surface files already on disk (idempotent)
    // ──────────────────────────────────────────────────────────

    public static function importKnown(): array {
        $tot = ['scanned' => 0, 'added' => 0, 'skipped' => 0];
        foreach (self::KNOWN_IMPORT_FOLDERS as $folder) {
            $r = self::importFolder($folder);
            $tot['scanned'] += $r['scanned'];
            $tot['added']   += $r['added'];
            $tot['skipped'] += $r['skipped'];
        }
        return $tot;
    }

    /**
     * Walk /uploads/<folder>/ recursively and register any allowed file
     * not already known. Files are registered in place (never moved).
     */
    public static function importFolder(string $folder): array {
        $tid = current_tenant_id();
        $stats = ['scanned' => 0, 'added' => 0, 'skipped' => 0];
        $base  = SLATE_ROOT . '/uploads/' . trim($folder, '/');
        if (!is_dir($base)) return $stats;

        // Existing paths for this tenant — avoid a query per file.
        $known = [];
        try {
            foreach (\Database::rows("SELECT path FROM media_files WHERE tenant_id = ?", [$tid]) as $r) {
                $known[(string)$r['path']] = true;
            }
        } catch (\Throwable $e) { return $stats; }

        $allowed = self::allowedExts();
        $finfo   = class_exists('finfo') ? new \finfo(FILEINFO_MIME_TYPE) : null;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isFile()) continue;
            $name = $entry->getFilename();
            if ($name === '' || $name[0] === '.') continue;
            $ext = strtolower($entry->getExtension());
            if (!in_array($ext, $allowed, true)) continue;

            $stats['scanned']++;
            $full = $entry->getPathname();
            $rel  = '/uploads/' . trim($folder, '/') . '/'
                  . ltrim(str_replace('\\', '/', substr($full, strlen($base))), '/');

            if (isset($known[$rel])) { $stats['skipped']++; continue; }

            $mime = $finfo ? ($finfo->file($full) ?: '') : '';
            $w = null; $h = null;
            if (self::kindForMime($mime, $ext) === 'image') {
                $info = @getimagesize($full);
                if (is_array($info)) {
                    $w = $info[0] ?? null; $h = $info[1] ?? null;
                    if ($mime === '' && !empty($info['mime'])) $mime = $info['mime'];
                }
            }
            self::register($rel, [
                'mime'          => $mime,
                'size_bytes'    => @filesize($full) ?: 0,
                'width'         => $w,
                'height'        => $h,
                'original_name' => $name,
            ]);
            $known[$rel] = true;
            $stats['added']++;
        }
        return $stats;
    }

    // ──────────────────────────────────────────────────────────
    // Picker — on-demand asset enqueue for any admin page
    // ──────────────────────────────────────────────────────────

    /**
     * Make the media picker available on the current admin page. Call
     * BEFORE including admin/partials/header.php. Emits the picker CSS
     * and JS into <head> via the admin_head hook. Assets are served
     * from the (retained) media-library plugin so existing hardcoded
     * references and subdir installs keep working.
     *
     * JS API: SlateMedia.open({types, multiple, onPick}) and the legacy
     * MediaPicker.open({mode, onPick}) + data-mlp-target/-mode buttons.
     */
    public static function enqueuePicker(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        if (!class_exists('Hook')) return;
        \Hook::addAction('admin_head', function (): void {
            $css = plugin_url('media-library', 'assets/css/picker.css');
            $js  = plugin_url('media-library', 'assets/js/picker.js');
            $v   = defined('SLATE_VERSION') ? SLATE_VERSION : '1';
            echo '<link rel="stylesheet" href="' . e($css) . '?v=' . e($v) . '">' . "\n";
            echo '<script src="' . e($js) . '?v=' . e($v) . '" defer></script>' . "\n";
        });
    }
}
