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
    public const SCHEMA_V = '4';

    /** Days a trashed file stays restorable before the daily job removes it. */
    public const TRASH_DAYS = 30;

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
    /** Video files a page can play in a <video> element. Stored with kind 'other' (the ENUM has no video value) and told apart by MIME. */
    public const VIDEO_EXTS = ['mp4', 'm4v', 'webm', 'ogv', 'mov'];
    public const VIDEO_MIMES = ['video/mp4', 'video/x-m4v', 'video/webm', 'video/ogg', 'video/quicktime'];
    /** A video is bigger than an image or a document: its own upload ceiling. */
    public const VIDEO_MAX_BYTES = 256 * 1024 * 1024;
    /** Audio files a page can play in an <audio> element (podcasts, music). Stored with kind 'other', told apart by MIME. */
    public const AUDIO_EXTS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav'];
    public const AUDIO_MIMES = [
        'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/x-aac', 'audio/aacp',
        'audio/ogg', 'audio/opus', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
    ];
    public const AUDIO_MAX_BYTES = 128 * 1024 * 1024;

    // ──────────────────────────────────────────────────────────
    // Config helpers
    // ──────────────────────────────────────────────────────────

    public static function imageMimes(): array {
        return array_merge(Uploads::IMAGE_MIMES, ['image/svg+xml']);
    }

    public static function docMimes(): array {
        return Uploads::DOC_MIMES;
    }

    public static function videoMimes(): array {
        return self::VIDEO_MIMES;
    }

    public static function allowedMimes(): array {
        return array_values(array_unique(array_merge(self::imageMimes(), self::docMimes(), self::videoMimes(), self::AUDIO_MIMES)));
    }

    public static function allowedExts(): array {
        return array_values(array_unique(array_merge(self::IMAGE_EXTS, self::DOC_EXTS, self::VIDEO_EXTS, self::AUDIO_EXTS)));
    }

    /** The upload ceiling for a file of this name: video and audio files are allowed to be bigger. */
    public static function maxBytesFor(string $name): int {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::VIDEO_EXTS, true)) return self::VIDEO_MAX_BYTES;
        if (in_array($ext, self::AUDIO_EXTS, true)) return self::AUDIO_MAX_BYTES;
        return Uploads::DEFAULT_MAX_BYTES;
    }

    /**
     * Is this media record (or MIME + extension) a playable audio file? An .m4a is often reported as video/mp4
     * (same container), so that pair counts as audio too.
     */
    public static function isAudio(array|string $rowOrMime, string $ext = ''): bool {
        if (is_array($rowOrMime)) {
            $ext = strtolower(pathinfo((string)($rowOrMime['path'] ?? ''), PATHINFO_EXTENSION));
            $rowOrMime = (string)($rowOrMime['mime'] ?? '');
        }
        $mime = strtolower(trim($rowOrMime));
        $ext = strtolower(ltrim($ext, '.'));
        return in_array($ext, self::AUDIO_EXTS, true)
            && (in_array($mime, self::AUDIO_MIMES, true) || ($ext === 'm4a' && $mime === 'video/mp4'));
    }

    /** Is this media record (or MIME + extension) a playable video file? */
    public static function isVideo(array|string $rowOrMime, string $ext = ''): bool {
        if (is_array($rowOrMime)) {
            $ext = strtolower(pathinfo((string)($rowOrMime['path'] ?? ''), PATHINFO_EXTENSION));
            $rowOrMime = (string)($rowOrMime['mime'] ?? '');
        }
        $mime = strtolower(trim($rowOrMime));
        return in_array($mime, self::VIDEO_MIMES, true)
            && in_array(strtolower(ltrim($ext, '.')), self::VIDEO_EXTS, true);
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
                    `alt_text`      VARCHAR(255) NULL DEFAULT '',
                    `title`         VARCHAR(255) NULL DEFAULT '',
                    `description`   TEXT NULL,
                    `variants`      TEXT NULL,
                    `file_version`  INT UNSIGNED NOT NULL DEFAULT 0,
                    `folder_id`     INT UNSIGNED NULL,
                    `file_hash`     CHAR(64) NULL,
                    `deleted_at`    DATETIME NULL,
                    `uploaded_by`   INT UNSIGNED NULL,
                    `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_path` (`tenant_id`, `path`),
                    KEY `tenant_kind` (`tenant_id`, `kind`),
                    KEY `tenant_uploaded_at` (`tenant_id`, `uploaded_at`),
                    KEY `tenant_folder` (`tenant_id`, `folder_id`),
                    KEY `tenant_hash` (`tenant_id`, `file_hash`),
                    KEY `tenant_deleted` (`tenant_id`, `deleted_at`)
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

            \Database::query(
                "CREATE TABLE IF NOT EXISTS `media_folders` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
                    `parent_id`   INT UNSIGNED NULL,
                    `name`        VARCHAR(120) NOT NULL,
                    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `tenant_parent` (`tenant_id`, `parent_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            \Database::query(
                "CREATE TABLE IF NOT EXISTS `media_tags` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
                    `name`        VARCHAR(60) NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_name` (`tenant_id`, `name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            \Database::query(
                "CREATE TABLE IF NOT EXISTS `media_file_tags` (
                    `media_id`    INT UNSIGNED NOT NULL,
                    `tag_id`      INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`media_id`, `tag_id`),
                    KEY `tag` (`tag_id`),
                    CONSTRAINT `fk_media_file_tags_media` FOREIGN KEY (`media_id`) REFERENCES `media_files`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_media_file_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `media_tags`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // Migration: ensure alt_text, title, and description columns exist
            try {
                $cols = array_column(\Database::rows("SHOW COLUMNS FROM `media_files`"), 'Field');
                if (!in_array('alt_text', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `alt_text` VARCHAR(255) NULL DEFAULT '' AFTER `folder`");
                }
                if (!in_array('title', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `title` VARCHAR(255) NULL DEFAULT '' AFTER `alt_text`");
                }
                if (!in_array('description', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `description` TEXT NULL AFTER `title`");
                }
                // v3: the WebP copies of an image (JSON width => path; '{}' once checked) and a counter
                // bumped whenever the file itself changes, so its URL changes too.
                if (!in_array('variants', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `variants` TEXT NULL AFTER `description`");
                }
                if (!in_array('file_version', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `file_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `variants`");
                }
                // v4: library folders (labels, files never move on disk), duplicate detection, trash.
                if (!in_array('folder_id', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `folder_id` INT UNSIGNED NULL AFTER `file_version`, ADD KEY `tenant_folder` (`tenant_id`, `folder_id`)");
                }
                if (!in_array('file_hash', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `file_hash` CHAR(64) NULL AFTER `folder_id`, ADD KEY `tenant_hash` (`tenant_id`, `file_hash`)");
                }
                if (!in_array('deleted_at', $cols, true)) {
                    \Database::query("ALTER TABLE `media_files` ADD COLUMN `deleted_at` DATETIME NULL AFTER `file_hash`, ADD KEY `tenant_deleted` (`tenant_id`, `deleted_at`)");
                }
            } catch (\Throwable $e) { /* ignore */ }

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
        return self::withTags([self::decorate($row, self::usageMap())])[0];
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
        return self::withTags([self::decorate($row, self::usageMap())])[0];
    }

    /**
     * List media. Filters:
     *   type    'all' | 'image' | 'video' | 'document' (anything that is neither)
     *   usage   'all' | 'in_use' | 'unused'
     *   search  matches original_name / path / alt_text / title
     *   sort    'newest' (default) | 'oldest' | 'name' | 'size'
     *   folder  'all' (default) | 'none' (unfiled) | a folder id
     *   tag     a tag id
     *   alt     'missing': images with no alt text
     *   trash   true: only trashed files (default: only files not in the trash)
     *   page, per_page  (pagination; per_page default 24)
     *
     * Filtering, sorting and paging run in SQL; the usage map is built once
     * and turned into a path list for the usage filter.
     *
     * Returns ['items'=>[...decorated...], 'total'=>int, 'page'=>int, 'pages'=>int].
     */
    public static function listAll(array $filters = []): array {
        $tid     = current_tenant_id();
        $type    = (string)($filters['type']   ?? 'all');
        $usage   = (string)($filters['usage']  ?? 'all');
        $search  = trim((string)($filters['search'] ?? ''));
        $sort    = (string)($filters['sort']   ?? 'newest');
        $perPage = max(1, (int)($filters['per_page'] ?? 24));
        $page    = max(1, (int)($filters['page'] ?? 1));
        $empty   = ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];

        [$where, $params] = self::typeWhere($type);
        array_unshift($where, 'tenant_id = ?');
        array_unshift($params, $tid);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $where[] = '(original_name LIKE ? OR path LIKE ? OR alt_text LIKE ? OR title LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $where[] = !empty($filters['trash']) ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        $folder = $filters['folder'] ?? 'all';
        if ($folder === 'none') {
            $where[] = 'folder_id IS NULL';
        } elseif ((is_int($folder) || (is_string($folder) && ctype_digit($folder))) && (int)$folder > 0) {
            $where[] = 'folder_id = ?';
            $params[] = (int)$folder;
        }
        if ((int)($filters['tag'] ?? 0) > 0) {
            $where[] = 'id IN (SELECT media_id FROM media_file_tags WHERE tag_id = ?)';
            $params[] = (int)$filters['tag'];
        }
        if (($filters['alt'] ?? '') === 'missing') {
            $where[] = "kind = 'image' AND (alt_text IS NULL OR alt_text = '')";
        }

        $usageMap = self::usageMap();
        if ($usage === 'in_use' || $usage === 'unused') {
            $used = self::usedPaths($usageMap);
            if ($usage === 'in_use' && $used === []) return $empty;
            if ($used !== []) {
                $where[] = 'path ' . ($usage === 'in_use' ? 'IN' : 'NOT IN') . ' (' . implode(',', array_fill(0, count($used), '?')) . ')';
                array_push($params, ...$used);
            }
        }
        $whereSql = implode(' AND ', $where);
        $orderSql = match ($sort) {
            'oldest' => 'uploaded_at ASC, id ASC',
            'name'   => 'original_name ASC, id ASC',
            'size'   => 'size_bytes DESC, id DESC',
            default  => 'uploaded_at DESC, id DESC',
        };

        try {
            $total = (int)\Database::value("SELECT COUNT(*) FROM media_files WHERE $whereSql", $params);
            $pages = max(1, (int)ceil($total / $perPage));
            if ($page > $pages) $page = $pages;
            $offset = ($page - 1) * $perPage;
            $rows = \Database::rows(
                "SELECT * FROM media_files WHERE $whereSql ORDER BY $orderSql LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset,
                $params
            );
        } catch (\Throwable $e) {
            return $empty;
        }

        $items = [];
        foreach ($rows as $r) $items[] = self::decorate($r, $usageMap);

        return ['items' => self::withTags($items), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Several records by id in one query (tenant-scoped), decorated, in the
     * order asked. Unknown or foreign ids are left out.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function getMany(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $i) => $i > 0)));
        if ($ids === []) return [];
        try {
            $rows = \Database::rows(
                'SELECT * FROM media_files WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge([current_tenant_id()], $ids)
            );
        } catch (\Throwable $e) { return []; }
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;
        $usageMap = self::usageMap();
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) $out[] = self::decorate($byId[$id], $usageMap);
        }
        return self::withTags($out);
    }

    /**
     * Tab/badge counts for the admin UI:
     *   ['all','image','video','audio','document','in_use','unused','missing_alt','trash'].
     * Everything but 'trash' leaves trashed files out.
     */
    public static function counts(): array {
        $tid = current_tenant_id();
        $c = ['all' => 0, 'image' => 0, 'video' => 0, 'audio' => 0, 'document' => 0, 'in_use' => 0, 'unused' => 0, 'missing_alt' => 0, 'trash' => 0];
        try {
            [$videoSql, $audioSql] = self::mediaTypeSql();
            $rows = \Database::rows(
                "SELECT CASE WHEN kind = 'image' THEN 'image'
                             WHEN $videoSql THEN 'video'
                             WHEN $audioSql THEN 'audio'
                             ELSE 'document' END AS t, COUNT(*) AS n
                   FROM media_files WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY t",
                array_merge(self::VIDEO_MIMES, self::AUDIO_MIMES, [$tid])
            );
            foreach ($rows as $r) {
                $c[(string)$r['t']] = (int)$r['n'];
                $c['all'] += (int)$r['n'];
            }
            $used = self::usedPaths(self::usageMap());
            if ($used !== []) {
                $c['in_use'] = (int)\Database::value(
                    'SELECT COUNT(*) FROM media_files WHERE tenant_id = ? AND deleted_at IS NULL AND path IN (' . implode(',', array_fill(0, count($used), '?')) . ')',
                    array_merge([$tid], $used)
                );
            }
            $c['missing_alt'] = (int)\Database::value(
                "SELECT COUNT(*) FROM media_files WHERE tenant_id = ? AND deleted_at IS NULL AND kind = 'image' AND (alt_text IS NULL OR alt_text = '')",
                [$tid]
            );
            $c['trash'] = (int)\Database::value("SELECT COUNT(*) FROM media_files WHERE tenant_id = ? AND deleted_at IS NOT NULL", [$tid]);
        } catch (\Throwable $e) { return $c; }
        $c['unused'] = $c['all'] - $c['in_use'];
        return $c;
    }

    /**
     * WHERE parts for a type filter. 'document' is everything that is
     * neither an image nor a playable video.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function typeWhere(string $type): array {
        [$videoSql, $audioSql] = self::mediaTypeSql();
        return match ($type) {
            'image'    => [["kind = 'image'"], []],
            'video'    => [[$videoSql], self::VIDEO_MIMES],
            'audio'    => [[$audioSql], self::AUDIO_MIMES],
            'document' => [["kind <> 'image' AND NOT ($videoSql) AND NOT ($audioSql)"], array_merge(self::VIDEO_MIMES, self::AUDIO_MIMES)],
            default    => [[], []],
        };
    }

    /**
     * SQL conditions for "a playable video" and "a playable audio file" (the first binds VIDEO_MIMES, the second
     * AUDIO_MIMES). An .m4a reported as video/mp4 is audio, not video.
     *
     * @return array{0: string, 1: string}
     */
    private static function mediaTypeSql(): array {
        $video = implode(',', array_fill(0, count(self::VIDEO_MIMES), '?'));
        $audio = implode(',', array_fill(0, count(self::AUDIO_MIMES), '?'));
        return [
            "kind = 'other' AND mime IN ($video) AND LOWER(path) NOT LIKE '%.m4a'",
            "kind = 'other' AND (mime IN ($audio) OR (mime = 'video/mp4' AND LOWER(path) LIKE '%.m4a'))",
        ];
    }

    /** Paths with at least one reference. @return list<string> */
    private static function usedPaths(array $usageMap): array {
        $used = [];
        foreach ($usageMap as $path => $n) {
            if ((int)$n > 0) $used[] = (string)$path;
        }
        return $used;
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
            'alt_text'      => (string)($row['alt_text'] ?? ''),
            'title'         => (string)($row['title'] ?? ''),
            'description'   => (string)($row['description'] ?? ''),
            'variants'      => self::variantUrls($row),
            'thumb_url'     => self::thumbUrl($row),
            'file_version'  => (int)($row['file_version'] ?? 0),
            'folder_id'     => isset($row['folder_id']) && $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
            'deleted_at'    => isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            'tags'          => [],
            'uploaded_at'   => (string)($row['uploaded_at'] ?? ''),
            'in_use'        => ($usageMap[$path] ?? 0) > 0,
            'usage_count'   => (int)($usageMap[$path] ?? 0),
        ];
    }

    /**
     * Update metadata (alt_text, title, description) on a media record.
     * Tenant-scoped.
     */
    public static function updateMeta(int $id, array $meta): array {
        $tid = current_tenant_id();
        $fields = [];
        $params = [];
        if (array_key_exists('alt_text', $meta)) {
            $fields[] = '`alt_text` = ?';
            $params[] = trim((string)$meta['alt_text']);
        }
        if (array_key_exists('title', $meta)) {
            $fields[] = '`title` = ?';
            $params[] = trim((string)$meta['title']);
        }
        if (array_key_exists('description', $meta)) {
            $fields[] = '`description` = ?';
            $params[] = trim((string)$meta['description']);
        }
        if ($fields === []) {
            return ['ok' => false, 'error' => 'No metadata fields provided'];
        }
        $params[] = $tid;
        $params[] = $id;
        try {
            \Database::query(
                "UPDATE `media_files` SET " . implode(', ', $fields) . ", `updated_at` = NOW() WHERE `tenant_id` = ? AND `id` = ?",
                $params
            );
            $item = self::get($id);
            // Pages that bake this file's alt text into cached HTML rebuild on their next view.
            if ($item && class_exists('Hook')) \Hook::doAction('media_updated', $id, $item);
            return ['ok' => true, 'item' => $item];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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
        // A video or audio file gets its own ceiling unless the caller set one; everything else keeps the default.
        $name = is_array($_FILES[$field] ?? null) && is_string($_FILES[$field]['name'] ?? null) ? $_FILES[$field]['name'] : '';
        $res = Uploads::handle($field, $folder, [
            'allowed_mimes' => $opts['allowed_mimes'] ?? self::allowedMimes(),
            'allowed_exts'  => $opts['allowed_exts']  ?? self::allowedExts(),
            'max_bytes'     => (int)($opts['max_bytes'] ?? self::maxBytesFor($name)),
        ]);
        if (empty($res['ok'])) return $res;
        return self::finishIngest((string)$res['path'], (string)($res['mime'] ?? ''), (string)($res['original'] ?? ''), (int)($res['size'] ?? 0), $opts);
    }

    /**
     * Add a file that is already on this server (a finished chunked upload, a download, bytes sent over MCP) to
     * the library, with the same checks as a browser upload: allowed extension and type, size limit, then
     * duplicate check, SVG clean-up, photo clean-up and image sizes. The file at $absPath is moved or copied in;
     * the caller still owns (and should delete) whatever is left at $absPath.
     *
     * @param array<string, mixed> $opts allowed_mimes, allowed_exts, max_bytes, folder_id, allow_duplicate, via
     * @return array<string, mixed> the media record (with 'duplicate' => true when the bytes were already there), or ['ok' => false, 'error' => …]
     */
    public static function ingestLocal(string $absPath, string $originalName, array $opts = []): array {
        $originalName = basename(str_replace('\\', '/', $originalName));
        clearstatcache(true, $absPath);
        if (!is_file($absPath) || ($size = (int)@filesize($absPath)) <= 0) {
            return ['ok' => false, 'error' => __('core_err_no_file_submitted', 'No file submitted.')];
        }
        $max = (int)($opts['max_bytes'] ?? self::maxBytesFor($originalName));
        if ($size > $max) {
            return ['ok' => false, 'error' => 'File too large (max ' . round($max / 1024 / 1024) . ' MB).'];
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $opts['allowed_exts'] ?? self::allowedExts(), true)) {
            return ['ok' => false, 'error' => __('core_err_file_extension_not_allowed', 'File extension not allowed.')];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absPath) ?: 'application/octet-stream';
        if (!in_array($mime, $opts['allowed_mimes'] ?? self::allowedMimes(), true)) {
            return ['ok' => false, 'error' => __('core_err_file_type_not_allowed', 'File type not allowed.')];
        }
        $folder  = self::FOLDER . '/' . date('Y/m');
        $dir     = Uploads::publicUploadDir($folder);
        $newName = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!@rename($absPath, $dir . '/' . $newName) && !@copy($absPath, $dir . '/' . $newName)) {
            return ['ok' => false, 'error' => __('core_err_could_not_save_uploaded_file', 'Could not save uploaded file.')];
        }
        @chmod($dir . '/' . $newName, 0644);
        return self::finishIngest('/uploads/' . $folder . '/' . $newName, $mime, $originalName, $size, $opts);
    }

    /**
     * Download a file from a public web address into the library ("Import from URL"). Only http(s) addresses that
     * resolve to public hosts are fetched (see RemoteFile); the file then goes through ingestLocal() like any upload.
     * A name without a usable extension gets one from the file's type.
     *
     * @param array<string, mixed> $opts as ingestLocal(), plus 'resolve' (a host => IPs callable, for tests)
     * @return array<string, mixed>
     */
    public static function importUrl(string $url, array $opts = []): array {
        $maxAll = max(self::VIDEO_MAX_BYTES, self::AUDIO_MAX_BYTES, Uploads::DEFAULT_MAX_BYTES);
        $res = RemoteFile::download($url, (int)($opts['max_bytes'] ?? $maxAll), $opts['resolve'] ?? null);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => self::remoteError((string)($res['error'] ?? ''))];
        }
        $file = (string)$res['file'];
        try {
            $name = trim((string)$res['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name === '' || !in_array($ext, self::allowedExts(), true)) {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: '';
                $guess = self::extForMime($mime);
                if ($guess === '') {
                    return ['ok' => false, 'error' => __('core_err_file_type_not_allowed', 'File type not allowed.')];
                }
                $stem = $name !== '' ? pathinfo($name, PATHINFO_FILENAME) : '';
                $name = ($stem !== '' ? $stem : 'download') . '.' . $guess;
            }
            $item = self::ingestLocal($file, $name, $opts + ['via' => 'url']);
            if (!empty($item['id'])) \AuditLog::record('media.imported_url', (string)($item['path'] ?? ''), ['id' => (int)$item['id'], 'url' => mb_substr((string)$res['url'], 0, 500)]);
            return $item;
        } finally {
            @unlink($file);
        }
    }

    /** A file extension for a type the library accepts, or ''. */
    public static function extForMime(string $mime): string {
        return match (strtolower($mime)) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv', 'video/quicktime' => 'mov', 'video/x-m4v' => 'm4v',
            'audio/mpeg', 'audio/mp3' => 'mp3', 'audio/mp4', 'audio/x-m4a' => 'm4a', 'audio/aac', 'audio/x-aac', 'audio/aacp' => 'aac',
            'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => 'wav',
            default => '',
        };
    }

    private static function remoteError(string $code): string {
        return match (true) {
            $code === 'http_only', $code === 'invalid_url', $code === 'host_missing', $code === 'credentials_in_url'
                => __('media_url_err_invalid', 'Enter a full web address that starts with http:// or https://.'),
            $code === 'private_address', $code === 'port_not_allowed'
                => __('media_url_err_private', 'That address is not a public website, so it cannot be imported.'),
            $code === 'host_unresolvable' => __('media_url_err_host', 'That website could not be found.'),
            $code === 'too_large' => __('media_url_err_large', 'That file is too large to import.'),
            $code === 'too_many_redirects' => __('media_url_err_redirects', 'That address redirects too many times.'),
            str_starts_with($code, 'http_') => sprintf(__('media_url_err_http', 'The website answered with an error (%s).'), substr($code, 5)),
            default => __('media_url_err_fetch', 'The file could not be downloaded.'),
        };
    }

    /**
     * The steps every new file goes through once it sits in /uploads: duplicate check, SVG and photo clean-up,
     * register, folder and hash, image sizes.
     *
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    private static function finishIngest(string $path, string $mime, string $originalName, int $size, array $opts): array {
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // The same bytes already in the library: hand back that item instead of a second copy.
        $hash = (string)@hash_file('sha256', SLATE_ROOT . $path);
        $existing = $hash !== '' && empty($opts['allow_duplicate']) ? self::findByHash($hash) : null;
        if ($existing) {
            Uploads::remove($path);
            return $existing + ['duplicate' => true];
        }

        // Harden SVG: as XML it can carry <script>, event handlers, and
        // javascript: URLs that run in this origin when the file URL is
        // opened directly — stored XSS. Sanitize in place; if it can't be
        // parsed cleanly, reject and delete rather than store a live payload.
        if ($ext === 'svg' || $mime === 'image/svg+xml') {
            if (!self::sanitizeSvgFile(SLATE_ROOT . $path)) {
                Uploads::remove($path);
                return ['ok' => false, 'error' => __('core_err_could_not_process_svg_file', 'Could not process SVG file.')];
            }
        }

        $isImage = self::kindForMime($mime, $ext) === 'image';
        // A phone photo: turn it upright and drop its EXIF (GPS position) before anyone can download it.
        if ($isImage && self::stripMetadataEnabled()) {
            ImageProcessor::normalize(SLATE_ROOT . $path, $mime);
        }

        $w = null; $h = null;
        if ($isImage) {
            $info = @getimagesize(SLATE_ROOT . $path);
            if (is_array($info)) { $w = $info[0] ?? null; $h = $info[1] ?? null; }
        }

        clearstatcache(true, SLATE_ROOT . $path);
        $id = self::register($path, [
            'mime'          => $mime,
            'size_bytes'    => (int)(@filesize(SLATE_ROOT . $path) ?: $size),
            'width'         => $w,
            'height'        => $h,
            'original_name' => $originalName !== '' ? $originalName : basename($path),
        ]);

        if ($id > 0) {
            $folderId = (int)($opts['folder_id'] ?? 0);
            try {
                \Database::query(
                    "UPDATE media_files SET file_hash = ?, folder_id = ? WHERE tenant_id = ? AND id = ?",
                    [$hash !== '' ? $hash : null, $folderId > 0 && self::folderExists($folderId) ? $folderId : null, current_tenant_id(), $id]
                );
            } catch (\Throwable $e) { /* optional */ }
        }
        if ($id > 0 && $isImage && self::sizesEnabled()) {
            self::generateVariants($id);
        }

        \AuditLog::record('media.uploaded', $path, ['id' => $id] + (isset($opts['via']) ? ['via' => (string)$opts['via']] : []));
        return self::get($id) ?? ['ok' => true, 'id' => $id, 'path' => $path];
    }

    // ──────────────────────────────────────────────────────────
    // Image sizes, replace and edit
    // ──────────────────────────────────────────────────────────

    /** Make WebP copies of new images (setting media_image_sizes, on unless set to '0'). */
    public static function sizesEnabled(): bool {
        try { return \Database::setting('media_image_sizes') !== '0'; } catch (\Throwable $e) { return true; }
    }

    /** Upright photos and strip their EXIF on upload (setting media_strip_metadata, on unless '0'). */
    public static function stripMetadataEnabled(): bool {
        try { return \Database::setting('media_strip_metadata') !== '0'; } catch (\Throwable $e) { return true; }
    }

    /**
     * width => URL of each WebP copy of a row, narrowest first.
     *
     * @return array<int, string>
     */
    public static function variantUrls(array $row): array {
        $map = json_decode((string)($row['variants'] ?? ''), true);
        if (!is_array($map)) return [];
        $out = [];
        foreach ($map as $w => $rel) {
            if ((int)$w > 0 && is_string($rel) && self::isManagedPath($rel)) {
                $out[(int)$w] = self::versioned(self::url($rel), $row);
            }
        }
        ksort($out);
        return $out;
    }

    /** The smallest copy for a grid thumbnail, or the file itself. */
    public static function thumbUrl(array $row): string {
        $v = self::variantUrls($row);
        return $v !== [] ? reset($v) : self::versioned(self::url((string)($row['path'] ?? '')), $row);
    }

    /** A file's URL with ?v=N once the file has been replaced or edited, so caches fetch the new one. */
    public static function versioned(string $url, array $row): string {
        $v = (int)($row['file_version'] ?? 0);
        return $url !== '' && $v > 0 ? $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $v : $url;
    }

    /**
     * (Re)build the WebP copies of one image and record them. A row with no
     * copies needed (small image, GIF, SVG) is marked '{}' so it is not
     * offered again. Returns the number of copies written. With $notify, pages
     * that use the image are told (media_updated) so they pick up the srcset.
     */
    public static function generateVariants(int $id, bool $notify = true): int {
        $row = self::rawRow($id);
        if (!$row || ($row['kind'] ?? '') !== 'image' || !self::isManagedPath((string)$row['path'])) return 0;
        self::removeVariantFiles($row);
        $made = ImageProcessor::makeVariants(SLATE_ROOT, (string)$row['path'], (string)$row['mime']);
        try {
            \Database::query(
                "UPDATE media_files SET variants = ? WHERE tenant_id = ? AND id = ?",
                [json_encode((object)$made, JSON_UNESCAPED_SLASHES), current_tenant_id(), $id]
            );
        } catch (\Throwable $e) { return 0; }
        if ($notify && $made !== [] && class_exists('Hook')) {
            $item = self::get($id);
            if ($item) \Hook::doAction('media_updated', $id, $item);
        }
        return count($made);
    }

    /** Images that have not been checked for copies yet (uploaded before sizes existed). */
    public static function pendingVariantsCount(): int {
        try {
            return (int)\Database::value(
                "SELECT COUNT(*) FROM media_files WHERE tenant_id = ? AND kind = 'image' AND variants IS NULL AND deleted_at IS NULL",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) { return 0; }
    }

    /**
     * Build copies for up to $limit unchecked images (one batch per request,
     * so a slow host never times out). Returns ['done' => images checked,
     * 'files' => copies written, 'left' => still unchecked].
     */
    public static function generateMissing(int $limit = 20): array {
        $done = 0; $files = 0;
        try {
            $ids = array_column(\Database::rows(
                "SELECT id FROM media_files WHERE tenant_id = ? AND kind = 'image' AND variants IS NULL AND deleted_at IS NULL ORDER BY id LIMIT " . max(1, min(100, $limit)),
                [current_tenant_id()]
            ), 'id');
        } catch (\Throwable $e) { $ids = []; }
        foreach ($ids as $id) {
            $files += self::generateVariants((int)$id);
            $done++;
        }
        return ['done' => $done, 'files' => $files, 'left' => self::pendingVariantsCount()];
    }

    /**
     * Swap the file behind a media item for a new upload of the same type,
     * keeping its id, path and every reference. The new file is written to
     * the same path; its copies are rebuilt and its URL gets a new ?v=.
     */
    public static function replaceFile(int $id, string $field): array {
        $row = self::rawRow($id);
        if (!$row || !self::isManagedPath((string)$row['path'])) {
            return ['ok' => false, 'error' => __('core_err_media_not_found', 'Media not found.')];
        }
        $path = (string)$row['path'];
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $res = Uploads::handle($field, trim(dirname(substr($path, strlen('/uploads/'))), '/'), [
            'allowed_exts'  => $ext === 'jpeg' || $ext === 'jpg' ? ['jpg', 'jpeg'] : [$ext],
            // The same format as the file it replaces: a PNG renamed .jpg is refused.
            'allowed_mimes' => (string)$row['mime'] !== ''
                ? [(string)$row['mime']]
                : array_values(array_filter(self::allowedMimes(), static fn($m) => self::kindForMime((string)$m, $ext) === (string)$row['kind'])),
            'max_bytes'     => self::isVideo($row) ? self::VIDEO_MAX_BYTES : Uploads::DEFAULT_MAX_BYTES,
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? __('media_replace_type', 'Choose a file of the same type.'))];
        }
        $newAbs = SLATE_ROOT . (string)$res['path'];
        $mime = (string)($res['mime'] ?? $row['mime']);
        if ($ext === 'svg' && !self::sanitizeSvgFile($newAbs)) {
            Uploads::remove((string)$res['path']);
            return ['ok' => false, 'error' => __('core_err_could_not_process_svg_file', 'Could not process SVG file.')];
        }
        if (!@rename($newAbs, SLATE_ROOT . $path)) {
            Uploads::remove((string)$res['path']);
            return ['ok' => false, 'error' => __('core_err_could_not_save_uploaded_file', 'Could not save uploaded file.')];
        }
        $hash = (string)@hash_file('sha256', SLATE_ROOT . $path);
        if ($row['kind'] === 'image' && self::stripMetadataEnabled()) {
            ImageProcessor::normalize(SLATE_ROOT . $path, $mime);
        }
        try {
            \Database::query("UPDATE media_files SET file_hash = ? WHERE tenant_id = ? AND id = ?", [$hash !== '' ? $hash : null, current_tenant_id(), $id]);
        } catch (\Throwable $e) { /* optional */ }
        self::fileChanged($id, $path, $mime);
        \AuditLog::record('media.replaced', $path, ['id' => $id]);
        return ['ok' => true, 'item' => self::get($id)];
    }

    /**
     * Rotate, flip or crop an image (see ImageProcessor::transform for $ops).
     * In place, the item keeps its id and references and gets a new ?v=;
     * with $asCopy a new library item is made and the original is untouched.
     */
    public static function editImage(int $id, array $ops, bool $asCopy = false): array {
        $row = self::rawRow($id);
        if (!$row || $row['kind'] !== 'image' || !self::isManagedPath((string)$row['path']) || !ImageProcessor::supports((string)$row['mime'])) {
            return ['ok' => false, 'error' => __('media_edit_unsupported', 'This file cannot be edited here.')];
        }
        $path = (string)$row['path'];
        if ($asCopy) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $dir = Uploads::publicUploadDir(self::FOLDER . '/' . date('Y/m'));
            $newRel = '/uploads/' . self::FOLDER . '/' . date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
            if (!@copy(SLATE_ROOT . $path, $dir . '/' . basename($newRel))) {
                return ['ok' => false, 'error' => __('core_err_could_not_save_uploaded_file', 'Could not save uploaded file.')];
            }
            if (ImageProcessor::transform(SLATE_ROOT . $newRel, (string)$row['mime'], $ops) === null) {
                Uploads::remove($newRel);
                return ['ok' => false, 'error' => __('media_edit_failed', 'The image could not be edited.')];
            }
            $name = (string)($row['original_name'] ?: basename($path));
            $newId = self::register($newRel, [
                'mime'          => (string)$row['mime'],
                'original_name' => pathinfo($name, PATHINFO_FILENAME) . '-edited.' . pathinfo($name, PATHINFO_EXTENSION),
            ]);
            if ($newId <= 0) {
                Uploads::remove($newRel);
                return ['ok' => false, 'error' => __('media_edit_failed', 'The image could not be edited.')];
            }
            try {
                \Database::query("UPDATE media_files SET alt_text = ?, title = ? WHERE tenant_id = ? AND id = ?",
                    [(string)($row['alt_text'] ?? ''), (string)($row['title'] ?? ''), current_tenant_id(), $newId]);
            } catch (\Throwable $e) { /* optional */ }
            self::fileChanged($newId, $newRel, (string)$row['mime'], false);
            \AuditLog::record('media.edited', $newRel, ['id' => $newId, 'from' => $id]);
            return ['ok' => true, 'item' => self::get($newId)];
        }
        if (ImageProcessor::transform(SLATE_ROOT . $path, (string)$row['mime'], $ops) === null) {
            return ['ok' => false, 'error' => __('media_edit_failed', 'The image could not be edited.')];
        }
        self::fileChanged($id, $path, (string)$row['mime']);
        \AuditLog::record('media.edited', $path, ['id' => $id]);
        return ['ok' => true, 'item' => self::get($id)];
    }

    /** After the bytes of a file changed: new size and dimensions, new copies, new ?v=, and tell listeners. */
    private static function fileChanged(int $id, string $path, string $mime, bool $bump = true): void {
        $abs = SLATE_ROOT . $path;
        clearstatcache(true, $abs);
        $info = @getimagesize($abs);
        try {
            \Database::query(
                "UPDATE media_files SET mime = ?, size_bytes = ?, width = ?, height = ?"
                . ($bump ? ", file_version = file_version + 1" : '') . " WHERE tenant_id = ? AND id = ?",
                [$mime, (int)(@filesize($abs) ?: 0), is_array($info) ? (int)$info[0] : null, is_array($info) ? (int)$info[1] : null, current_tenant_id(), $id]
            );
        } catch (\Throwable $e) { /* best effort */ }
        $row = self::rawRow($id);
        if ($row && $row['kind'] === 'image') {
            if (self::sizesEnabled()) {
                self::generateVariants($id, false);
            } else {
                self::removeVariantFiles($row);
                try { \Database::query("UPDATE media_files SET variants = NULL WHERE tenant_id = ? AND id = ?", [current_tenant_id(), $id]); } catch (\Throwable $e) {}
            }
        }
        $item = self::get($id);
        if ($item && class_exists('Hook')) \Hook::doAction('media_updated', $id, $item);
    }

    /** Delete the WebP copy files recorded on a row. */
    private static function removeVariantFiles(array $row): void {
        $map = json_decode((string)($row['variants'] ?? ''), true);
        foreach (is_array($map) ? $map : [] as $rel) {
            if (is_string($rel) && self::isManagedPath($rel)) Uploads::remove($rel);
        }
    }

    // ──────────────────────────────────────────────────────────
    // Folders, tags, trash
    // ──────────────────────────────────────────────────────────

    /**
     * Every folder of the tenant, ordered for a tree (parents first, then by
     * name), each with 'count' = files directly in it (not trashed) and
     * 'depth' (0 for top level).
     *
     * @return list<array{id: int, parent_id: ?int, name: string, count: int, depth: int}>
     */
    public static function folders(): array {
        $tid = current_tenant_id();
        try {
            $rows = \Database::rows("SELECT id, parent_id, name FROM media_folders WHERE tenant_id = ? ORDER BY name, id", [$tid]);
            $counts = [];
            foreach (\Database::rows(
                "SELECT folder_id, COUNT(*) AS n FROM media_files WHERE tenant_id = ? AND deleted_at IS NULL AND folder_id IS NOT NULL GROUP BY folder_id",
                [$tid]
            ) as $r) $counts[(int)$r['folder_id']] = (int)$r['n'];
        } catch (\Throwable $e) { return []; }
        $children = [];
        $ids = [];
        foreach ($rows as $r) $ids[(int)$r['id']] = true;
        foreach ($rows as $r) {
            // A parent that no longer exists puts the folder at the top level.
            $parent = $r['parent_id'] !== null && isset($ids[(int)$r['parent_id']]) ? (int)$r['parent_id'] : 0;
            $children[$parent][] = $r;
        }
        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $children, $counts): void {
            foreach ($children[$parent] ?? [] as $r) {
                $id = (int)$r['id'];
                $out[] = ['id' => $id, 'parent_id' => $parent ?: null, 'name' => (string)$r['name'], 'count' => $counts[$id] ?? 0, 'depth' => $depth];
                if ($depth < 8) $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    /** Create a folder (optionally inside another). Returns its id, or 0. */
    public static function createFolder(string $name, ?int $parentId = null): int {
        $name = self::cleanName($name, 120);
        if ($name === '') return 0;
        $tid = current_tenant_id();
        if ($parentId !== null && $parentId > 0 && !self::folderExists($parentId)) return 0;
        try {
            \Database::query(
                "INSERT INTO media_folders (tenant_id, parent_id, name) VALUES (?, ?, ?)",
                [$tid, $parentId && $parentId > 0 ? $parentId : null, $name]
            );
            return (int)\Database::value("SELECT MAX(id) FROM media_folders WHERE tenant_id = ? AND name = ?", [$tid, $name]);
        } catch (\Throwable $e) { return 0; }
    }

    public static function renameFolder(int $id, string $name): bool {
        $name = self::cleanName($name, 120);
        if ($name === '' || !self::folderExists($id)) return false;
        try {
            \Database::query("UPDATE media_folders SET name = ? WHERE tenant_id = ? AND id = ?", [$name, current_tenant_id(), $id]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Remove a folder. Its files and sub-folders move up to its parent (or
     * the top level); no file is deleted.
     */
    public static function deleteFolder(int $id): bool {
        $tid = current_tenant_id();
        try {
            $row = \Database::row("SELECT parent_id FROM media_folders WHERE tenant_id = ? AND id = ?", [$tid, $id]);
            if (!$row) return false;
            $parent = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            \Database::query("UPDATE media_files SET folder_id = ? WHERE tenant_id = ? AND folder_id = ?", [$parent, $tid, $id]);
            \Database::query("UPDATE media_folders SET parent_id = ? WHERE tenant_id = ? AND parent_id = ?", [$parent, $tid, $id]);
            \Database::delete('media_folders', 'tenant_id = ? AND id = ?', [$tid, $id]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    public static function folderExists(int $id): bool {
        try {
            return $id > 0 && (bool)\Database::value("SELECT 1 FROM media_folders WHERE tenant_id = ? AND id = ?", [current_tenant_id(), $id]);
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Put files in a folder (null = no folder). Only a label: nothing moves
     * on disk, so every page and link keeps working. Returns rows changed.
     *
     * @param list<int> $ids
     */
    public static function moveToFolder(array $ids, ?int $folderId): int {
        $ids = self::cleanIds($ids);
        if ($ids === [] || ($folderId !== null && !self::folderExists($folderId))) return 0;
        try {
            return \Database::query(
                'UPDATE media_files SET folder_id = ? WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge([$folderId, current_tenant_id()], $ids)
            )->rowCount();
        } catch (\Throwable $e) { return 0; }
    }

    /**
     * Every tag with the number of files (not trashed) that carry it.
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    public static function tags(): array {
        try {
            $rows = \Database::rows(
                "SELECT t.id, t.name, COUNT(f.id) AS n
                   FROM media_tags t
                   LEFT JOIN media_file_tags ft ON ft.tag_id = t.id
                   LEFT JOIN media_files f ON f.id = ft.media_id AND f.deleted_at IS NULL
                  WHERE t.tenant_id = ?
                  GROUP BY t.id, t.name
                  ORDER BY t.name",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) { return []; }
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'count' => (int)$r['n']], $rows);
    }

    /**
     * Replace a file's tags with $names (comma-separated string or list).
     * New names become tags; tags no file uses any more are removed.
     */
    public static function setTags(int $id, array|string $names): bool {
        if (!self::rawRow($id)) return false;
        $tagIds = self::tagIds(self::splitTags($names));
        try {
            \Database::delete('media_file_tags', 'media_id = ?', [$id]);
            foreach ($tagIds as $tagId) {
                \Database::query("INSERT IGNORE INTO media_file_tags (media_id, tag_id) VALUES (?, ?)", [$id, $tagId]);
            }
        } catch (\Throwable $e) { return false; }
        self::pruneTags();
        return true;
    }

    /**
     * Add one or more tags to several files, keeping the tags they have.
     *
     * @param list<int> $ids
     */
    public static function addTags(array $ids, array|string $names): int {
        $ids = array_values(array_filter(self::cleanIds($ids), static fn(int $i) => self::rawRow($i) !== null));
        $tagIds = self::tagIds(self::splitTags($names));
        if ($ids === [] || $tagIds === []) return 0;
        try {
            foreach ($ids as $id) {
                foreach ($tagIds as $tagId) {
                    \Database::query("INSERT IGNORE INTO media_file_tags (media_id, tag_id) VALUES (?, ?)", [$id, $tagId]);
                }
            }
        } catch (\Throwable $e) { return 0; }
        return count($ids);
    }

    /**
     * Move files to the trash. A file still in use is refused, as on delete.
     * Returns ['trashed' => n, 'refused' => n].
     *
     * @param list<int> $ids
     */
    public static function trash(array $ids): array {
        $out = ['trashed' => 0, 'refused' => 0];
        $map = self::buildUsageMapByPath();
        foreach (self::cleanIds($ids) as $id) {
            $row = self::rawRow($id);
            if (!$row || $row['deleted_at'] !== null) continue;
            if (($map[(string)$row['path']] ?? 0) > 0) { $out['refused']++; continue; }
            try {
                \Database::query("UPDATE media_files SET deleted_at = NOW() WHERE tenant_id = ? AND id = ?", [current_tenant_id(), $id]);
                \AuditLog::record('media.trashed', (string)$row['path'], ['id' => $id]);
                $out['trashed']++;
            } catch (\Throwable $e) { /* skip */ }
        }
        return $out;
    }

    /** @param list<int> $ids */
    public static function restore(array $ids): int {
        $ids = self::cleanIds($ids);
        if ($ids === []) return 0;
        try {
            $n = \Database::query(
                'UPDATE media_files SET deleted_at = NULL WHERE tenant_id = ? AND deleted_at IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge([current_tenant_id()], $ids)
            )->rowCount();
        } catch (\Throwable $e) { return 0; }
        if ($n > 0) \AuditLog::record('media.restored', '', ['ids' => $ids]);
        return $n;
    }

    /**
     * Delete for good files trashed more than $days ago (the daily job), or
     * every trashed file with $days = 0 ("Empty trash"). A file that became
     * used again while in the trash is kept. Returns files removed.
     */
    public static function purgeTrash(int $days = self::TRASH_DAYS): int {
        try {
            $ids = array_column(\Database::rows(
                "SELECT id FROM media_files WHERE tenant_id = ? AND deleted_at IS NOT NULL AND deleted_at <= NOW() - INTERVAL ? DAY",
                [current_tenant_id(), max(0, $days)]
            ), 'id');
        } catch (\Throwable $e) { return 0; }
        $n = 0;
        foreach ($ids as $id) {
            if (!empty(self::delete((int)$id)['ok'])) $n++;
        }
        return $n;
    }

    /** daily_cron listener: the trash keeps files for TRASH_DAYS days. */
    public static function dailyCron(): void {
        self::ensureSchema();
        $n = self::purgeTrash();
        if ($n > 0 && function_exists('slate_log')) slate_log("Media: removed $n file(s) from the trash", 'info');
        ChunkedUpload::cleanup();
    }

    /**
     * What the library takes up on disk, for the Storage box and the MCP stats tool.
     *
     * 'types' has count and bytes per type (originals only, trash left out); 'sizes' is the WebP copies (bytes read
     * from disk); 'trash' and 'unused' are what could be freed; 'files_on_disk' counts originals plus copies (each
     * one is an inode on shared hosting); 'largest' lists the biggest files.
     *
     * @return array{total_bytes: int, files_on_disk: int, types: array<string, array{count: int, bytes: int}>, sizes: array{count: int, bytes: int}, trash: array{count: int, bytes: int}, unused: array{count: int, bytes: int}, largest: list<array<string, mixed>>}
     */
    public static function storageStats(int $largest = 8): array {
        $tid = current_tenant_id();
        $out = [
            'total_bytes' => 0, 'files_on_disk' => 0,
            'types' => ['image' => ['count' => 0, 'bytes' => 0], 'video' => ['count' => 0, 'bytes' => 0], 'audio' => ['count' => 0, 'bytes' => 0], 'document' => ['count' => 0, 'bytes' => 0]],
            'sizes' => ['count' => 0, 'bytes' => 0], 'trash' => ['count' => 0, 'bytes' => 0], 'unused' => ['count' => 0, 'bytes' => 0],
            'largest' => [],
        ];
        try {
            [$videoSql, $audioSql] = self::mediaTypeSql();
            $rows = \Database::rows(
                "SELECT CASE WHEN kind = 'image' THEN 'image'
                             WHEN $videoSql THEN 'video'
                             WHEN $audioSql THEN 'audio'
                             ELSE 'document' END AS t, COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS b
                   FROM media_files WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY t",
                array_merge(self::VIDEO_MIMES, self::AUDIO_MIMES, [$tid])
            );
            foreach ($rows as $r) {
                $out['types'][(string)$r['t']] = ['count' => (int)$r['n'], 'bytes' => (int)$r['b']];
            }
            $t = \Database::row("SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS b FROM media_files WHERE tenant_id = ? AND deleted_at IS NOT NULL", [$tid]);
            $out['trash'] = ['count' => (int)($t['n'] ?? 0), 'bytes' => (int)($t['b'] ?? 0)];

            foreach (\Database::rows("SELECT variants FROM media_files WHERE tenant_id = ? AND variants IS NOT NULL AND variants <> ''", [$tid]) as $r) {
                $v = json_decode((string)$r['variants'], true);
                foreach (is_array($v) ? $v : [] as $rel) {
                    if (!is_string($rel) || !is_file(SLATE_ROOT . $rel)) continue;
                    $out['sizes']['count']++;
                    $out['sizes']['bytes'] += (int)@filesize(SLATE_ROOT . $rel);
                }
            }

            $used = self::usedPaths(self::usageMap());
            $notUsed = $used === [] ? '' : ' AND path NOT IN (' . implode(',', array_fill(0, count($used), '?')) . ')';
            $u = \Database::row("SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS b FROM media_files WHERE tenant_id = ? AND deleted_at IS NULL$notUsed", array_merge([$tid], $used));
            $out['unused'] = ['count' => (int)($u['n'] ?? 0), 'bytes' => (int)($u['b'] ?? 0)];

            $big = \Database::rows(
                "SELECT * FROM media_files WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY size_bytes DESC, id DESC LIMIT " . max(1, min(50, $largest)),
                [$tid]
            );
            $map = self::usageMap();
            foreach ($big as $r) {
                $d = self::decorate($r, $map);
                $out['largest'][] = ['id' => $d['id'], 'original_name' => $d['original_name'], 'size_bytes' => $d['size_bytes'], 'mime' => $d['mime'], 'in_use' => $d['in_use']];
            }
        } catch (\Throwable $e) { return $out; }

        $originals = 0;
        foreach ($out['types'] as $tp) {
            $out['total_bytes'] += $tp['bytes'];
            $originals += $tp['count'];
        }
        $out['total_bytes'] += $out['trash']['bytes'] + $out['sizes']['bytes'];
        $out['files_on_disk'] = $originals + $out['trash']['count'] + $out['sizes']['count'];
        return $out;
    }

    /**
     * A file already in the library with exactly these bytes (not trashed), or null.
     */
    public static function findByHash(string $hash): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) return null;
        try {
            $id = (int)\Database::value(
                "SELECT id FROM media_files WHERE tenant_id = ? AND file_hash = ? AND deleted_at IS NULL ORDER BY id LIMIT 1",
                [current_tenant_id(), $hash]
            );
        } catch (\Throwable $e) { return null; }
        return $id > 0 ? self::get($id) : null;
    }

    /**
     * Attach each record's tags ([['id'=>…,'name'=>…], …]) in one query.
     *
     * @param list<array<string, mixed>> $items decorated records
     * @return list<array<string, mixed>>
     */
    private static function withTags(array $items): array {
        $ids = array_values(array_filter(array_map(static fn($i) => (int)($i['id'] ?? 0), $items)));
        if ($ids === []) return $items;
        $by = [];
        try {
            foreach (\Database::rows(
                'SELECT ft.media_id, t.id, t.name FROM media_file_tags ft JOIN media_tags t ON t.id = ft.tag_id
                  WHERE ft.media_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.name',
                $ids
            ) as $r) $by[(int)$r['media_id']][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
        } catch (\Throwable $e) { return $items; }
        foreach ($items as &$item) $item['tags'] = $by[(int)$item['id']] ?? [];
        return $items;
    }

    /** @return list<string> unique (case-insensitive), trimmed, at most 60 characters each, at most 20 */
    private static function splitTags(array|string $names): array {
        $list = is_array($names) ? $names : explode(',', $names);
        $out = [];
        foreach ($list as $n) {
            $n = self::cleanName((string)$n, 60);
            if ($n !== '' && !isset($out[mb_strtolower($n)])) $out[mb_strtolower($n)] = $n;
        }
        return array_slice(array_values($out), 0, 20);
    }

    /** @return list<int> ids of these tag names, creating the missing ones */
    private static function tagIds(array $names): array {
        $tid = current_tenant_id();
        $ids = [];
        foreach ($names as $name) {
            try {
                \Database::query("INSERT IGNORE INTO media_tags (tenant_id, name) VALUES (?, ?)", [$tid, $name]);
                $id = (int)\Database::value("SELECT id FROM media_tags WHERE tenant_id = ? AND name = ?", [$tid, $name]);
                if ($id > 0) $ids[] = $id;
            } catch (\Throwable $e) { /* skip */ }
        }
        return array_values(array_unique($ids));
    }

    private static function pruneTags(): void {
        try {
            \Database::query(
                "DELETE t FROM media_tags t LEFT JOIN media_file_tags ft ON ft.tag_id = t.id WHERE t.tenant_id = ? AND ft.tag_id IS NULL",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) { /* optional */ }
    }

    private static function cleanName(string $name, int $max): string {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        return function_exists('mb_substr') ? mb_substr($name, 0, $max) : substr($name, 0, $max);
    }

    /** @return list<int> */
    private static function cleanIds(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
    }

    /** Is this file name one of the WebP copies (<name>-<width>w.webp)? */
    public static function isVariantFile(string $name): bool {
        return preg_match('/-(' . implode('|', ImageProcessor::WIDTHS) . ')w\.webp$/i', $name) === 1;
    }

    /** The undecorated row (tenant-scoped). */
    private static function rawRow(int $id): ?array {
        try {
            $row = \Database::row("SELECT * FROM media_files WHERE tenant_id = ? AND id = ?", [current_tenant_id(), $id]);
        } catch (\Throwable $e) { return null; }
        return $row ?: null;
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
        // A DOCTYPE can declare entities the browser expands into markup
        // (a <script> hidden inside an entity survives a tree walk), so an
        // SVG with any DOCTYPE or ENTITY declaration is refused outright.
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $svg) === 1) return false;

        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        $ok   = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$doc->documentElement || $doc->doctype !== null) return false;
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
        // Processing instructions (such as xml-stylesheet) can pull in content; drop them all.
        foreach (iterator_to_array($xpath->query('//processing-instruction()')) as $pi) {
            $pi->parentNode?->removeChild($pi);
        }
        // Strip dangerous attributes from everything that remains. Attributes
        // are judged by their local name, never the prefix as written, so
        // `x:href` with `x` bound to the XLink namespace counts as `href`.
        foreach (iterator_to_array($xpath->query('//*')) as $el) {
            if (!$el->hasAttributes()) continue;
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->localName ?? $attr->nodeName);
                $val  = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', html_entity_decode($attr->nodeValue ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if (str_starts_with($name, 'on')
                    || in_array($name, ['href','src','from','to','values'], true)
                       && (str_starts_with($val, 'javascript:') || str_starts_with($val, 'vbscript:') || str_starts_with($val, 'data:text/html'))) {
                    $el->removeAttributeNode($attr);
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

        self::flushUsageCache();
        $row = \Database::row("SELECT id FROM media_files WHERE tenant_id = ? AND path = ?", [$tid, $path]);
        return (int)($row['id'] ?? 0);
    }

    /** Remove the media row for a path (usage rows cascade). */
    public static function unregister(string $path): void {
        $tid = current_tenant_id();
        try {
            \Database::delete('media_files', 'tenant_id = ? AND path = ?', [$tid, $path]);
        } catch (\Throwable $e) { /* ignore */ }
        self::flushUsageCache();
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
        self::flushUsageCache();
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
        self::flushUsageCache();
    }

    /** Clear all usage rows for a given object (call before re-attaching). */
    public static function detachByObject(string $context, string $objectType, $objectId): void {
        $tid = current_tenant_id();
        try {
            \Database::delete('media_usage',
                'tenant_id = ? AND context = ? AND object_type = ? AND object_id = ?',
                [$tid, $context, $objectType, (string)$objectId]);
        } catch (\Throwable $e) { /* ignore */ }
        self::flushUsageCache();
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
     * Where a file is used, for the details panel. One entry per place:
     *   ['source' => area (e.g. 'Studio', 'shop'), 'label' => what uses it,
     *    'url' => an admin link to it, or ''].
     * `media_usage` rows come first; plugins that count usage through the
     * `media_usage` filter list their places through the
     * `media_usage_details` filter (args: the list, the media record).
     *
     * @return list<array{source: string, label: string, url: string}>
     */
    public static function usageDetails(int $mediaId): array {
        $rec = self::get($mediaId);
        if (!$rec) return [];
        $list = [];
        foreach (self::usageFor($mediaId) as $u) {
            $what = trim((string)$u['object_type'] . ' ' . ((string)$u['object_id'] !== '' ? '#' . $u['object_id'] : ''));
            $list[] = [
                'source' => (string)$u['context'],
                'label'  => $what . ((string)$u['field'] !== '' ? ' · ' . $u['field'] : ''),
                'url'    => '',
            ];
        }
        if (class_exists('Hook')) {
            $filtered = \Hook::applyFilters('media_usage_details', $list, $rec);
            if (is_array($filtered)) $list = $filtered;
        }
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e) || trim((string)($e['label'] ?? '')) === '') continue;
            $out[] = [
                'source' => (string)($e['source'] ?? ''),
                'label'  => (string)$e['label'],
                'url'    => (string)($e['url'] ?? ''),
            ];
        }
        return $out;
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

    /** Per-request copy of buildUsageMapByPath(), keyed by tenant. */
    private static array $usageMapCache = [];

    /**
     * The usage map for listing and decorating, built once per request.
     * Writes through this class drop it; a plugin's own writes (a Studio
     * save, a product edit) happen in other requests. Safe delete never
     * reads it: deletePath() asks buildUsageMapByPath() fresh.
     */
    private static function usageMap(): array {
        $tid = (int)current_tenant_id();
        return self::$usageMapCache[$tid] ??= self::buildUsageMapByPath();
    }

    /** Forget the cached usage map (after a write, or in long-running processes). */
    public static function flushUsageCache(): void {
        self::$usageMapCache = [];
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
        if (!$rec) return ['ok' => false, 'error' => __('core_err_media_not_found', 'Media not found.')];
        return self::deletePath($rec['path']);
    }

    /** Delete by path. Refuses if the file is referenced anywhere. */
    public static function deleteByPath(string $path): array {
        return self::deletePath($path);
    }

    private static function deletePath(string $path): array {
        if (!self::isManagedPath($path)) {
            return ['ok' => false, 'error' => __('core_err_path_is_not_managed_by_the_media_library', 'Path is not managed by the media library.')];
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
        try {
            $row = \Database::row("SELECT variants FROM media_files WHERE tenant_id = ? AND path = ?", [current_tenant_id(), $path]);
        } catch (\Throwable $e) { $row = null; }
        if (!Uploads::remove($path)) {
            return ['ok' => false, 'error' => __('core_err_file_could_not_be_deleted_may_already_be_gone', 'File could not be deleted (may already be gone).')];
        }
        if ($row) self::removeVariantFiles($row);
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
            // The WebP copies of an image belong to it, not to the library on their own.
            if (self::isVariantFile($name)) continue;

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
