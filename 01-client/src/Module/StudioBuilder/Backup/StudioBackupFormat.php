<?php
/**
 * Kohevo Studio (studio-builder) — the whole-site backup file ("1.0").
 *
 * A package (`Package/StudioPackageFormat`) carries one page to another site, without files. A backup is the rest of the
 * job: one zip holding everything Studio keeps for a tenant, so the site can be put back or moved.
 *
 *   manifest.json    format, version, created_at, site, with_media, counts
 *   pages/0001.json  id uuid title slug page_type status route_mode working published meta
 *   templates.json   the site's own templates (system ones come with the plugin)
 *   tokens.json      token group => stored overrides
 *   settings.json    SETTING_KEYS that are set
 *   media.json       the media library rows: id path kind mime alt_text title description original_name sha256
 *   files/uploads/…  the files themselves, when the backup was taken with media
 *
 * Pure: no database, no I/O. It answers "is this shape well formed?"; every document is still validated by
 * DocumentValidator on restore. A backup is untrusted input like any upload, whoever made it.
 *
 * Settings are an allowlist on purpose: anything else under `studio_` (an API key, a connection) is not site content
 * and stays out of a file people download and pass around. `studio_page_meta_<id>` travels with its page.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Backup;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

final class StudioBackupFormat
{
    public const FORMAT  = 'kohevo-studio-backup';
    public const VERSION = '1.0';

    public const ENTRY_MANIFEST  = 'manifest.json';
    public const ENTRY_TEMPLATES = 'templates.json';
    public const ENTRY_TOKENS    = 'tokens.json';
    public const ENTRY_SETTINGS  = 'settings.json';
    public const ENTRY_MEDIA     = 'media.json';

    public const MAX_PAGES      = 5000;
    public const MAX_TEMPLATES  = 2000;
    public const MAX_MEDIA      = 50000;
    /** One JSON entry, unpacked. The media index of a large library is the biggest. */
    public const MAX_JSON_BYTES = 67108864;
    /** One media file, unpacked. */
    public const MAX_FILE_BYTES = 1073741824;

    public const SETTING_OG_MEDIA = 'studio_seo_og_media_id';

    /** @var list<string> */
    public const SETTING_KEYS = [
        'studio_menus',
        'studio_redirects',
        'studio_disabled_blocks',
        'studio_custom_post_types',
        'studio_meta_fields',
        'studio_url_trailing_slash',
        'studio_seo_title_format',
        'studio_seo_description',
        self::SETTING_OG_MEDIA,
        'studio_seo_og_image_url',
        'studio_seo_robots',
        'studio_code_custom_css',
        'studio_code_ga4_id',
        'studio_code_gtm_id',
        'studio_code_head_snippet',
        'studio_code_footer_snippet',
        'studio_code_site_verification',
    ];

    public static function pageEntry(int $number): string
    {
        return sprintf('pages/%04d.json', $number);
    }

    /** Where a media file sits in the zip. `$path` is a managed `/uploads/…` path, so the name is never user-made. */
    public static function fileEntry(string $path): string
    {
        return 'files/' . ltrim($path, '/');
    }

    /** @return list<string> what is wrong with a manifest; empty when it can be read */
    public static function manifestProblems(mixed $manifest): array
    {
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            return ['This file is not a Kohevo Studio backup.'];
        }
        if (($manifest['version'] ?? null) !== self::VERSION) {
            return ['This backup was made by a newer version of Studio.'];
        }
        $problems = [];
        if (!is_array($manifest['counts'] ?? null)) {
            $problems[] = 'The backup has no counts.';
        }
        $pages = $manifest['counts']['pages'] ?? null;
        if (!is_int($pages) || $pages < 0 || $pages > self::MAX_PAGES) {
            $problems[] = 'The backup page count is not valid.';
        }
        return $problems;
    }

    /** @return ?string what is wrong with a page record, or null */
    public static function pageProblem(mixed $page): ?string
    {
        if (!is_array($page)) {
            return 'The page record is not an object.';
        }
        foreach (['uuid', 'title', 'slug', 'page_type', 'status', 'route_mode'] as $key) {
            if (!is_string($page[$key] ?? null) || $page[$key] === '') {
                return "The page record has no {$key}.";
            }
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $page['uuid']) !== 1) {
            return 'The page record has an invalid uuid.';
        }
        if (!in_array($page['page_type'], CanonicalDocumentSchema::ALLOWED_DOCUMENT_TYPES, true)) {
            return "The page type '{$page['page_type']}' is not known.";
        }
        if (!is_array($page['working'] ?? null) || !(is_array($page['published'] ?? null) || ($page['published'] ?? null) === null)) {
            return 'The page record has no document.';
        }
        return null;
    }

    /** @return ?string what is wrong with a media row, or null. The path check itself is `Media::isManagedPath()`. */
    public static function mediaProblem(mixed $row): ?string
    {
        if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || !is_string($row['path'] ?? null) || $row['path'] === '') {
            return 'The media row is not valid.';
        }
        return null;
    }
}
