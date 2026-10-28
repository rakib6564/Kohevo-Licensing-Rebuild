<?php
/**
 * Kohevo Studio — the builder's own image picker, transport side.
 *
 * Pure helpers for `admin/media-api.php`: how a request is reduced to a safe media query, and how a
 * core media record is shaped for the editor. Nothing here touches the database, the session or
 * `$_FILES`; the endpoint does the IO and calls the core Media service (tenant-scoped, SVG-sanitising),
 * so there is no second media store and no path or URL ever comes from the client.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

use Slate\Services\Media\Media;

final class StudioMediaApi
{
    public const PER_PAGE         = 24;
    public const MAX_IDS          = 24;
    public const MAX_SEARCH       = 100;
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
    /** The Video widget's file picker: a video is far bigger than an image. */
    public const MAX_VIDEO_UPLOAD_BYTES = Media::VIDEO_MAX_BYTES;
    /** What the picker lists and accepts: images (every image field) or video files (the Video widget). */
    public const TYPES = ['image', 'video'];
    public const VIDEO_EXTS  = Media::VIDEO_EXTS;
    public const VIDEO_MIMES = Media::VIDEO_MIMES;

    /**
     * @param array<string, mixed> $get the raw query string
     * @return array{search: string, page: int, ids: list<int>, type: string}
     */
    public static function listQuery(array $get): array
    {
        $search = is_string($get['q'] ?? null) ? trim($get['q']) : '';
        $search = function_exists('mb_substr') ? mb_substr($search, 0, self::MAX_SEARCH) : substr($search, 0, self::MAX_SEARCH);

        $page = filter_var($get['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);

        $ids = [];
        if (is_string($get['ids'] ?? null) && $get['ids'] !== '') {
            foreach (explode(',', $get['ids']) as $raw) {
                $id = filter_var(trim($raw), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
                if (count($ids) >= self::MAX_IDS) {
                    break;
                }
            }
        }

        return ['search' => $search, 'page' => $page === false ? 1 : $page, 'ids' => $ids, 'type' => self::type($get['type'] ?? null)];
    }

    /** `image` unless the request asked for `video`. */
    public static function type(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::TYPES, true) ? $raw : 'image';
    }

    /**
     * One core media record as the editor sees it: an image (or, for `video`, a playable video file), an id, a URL to
     * show it and its facts.
     *
     * @param array<string, mixed> $row a decorated Media record
     * @return array<string, mixed>|null null for anything that is not a usable item of that type
     */
    public static function shapeItem(array $row, string $type = 'image'): ?array
    {
        $id  = (int) ($row['id'] ?? 0);
        $url = (string) ($row['url'] ?? '');
        if ($id <= 0 || !self::isType($row, $type) || preg_match('~^(https?://[^\s"\'<>]+|/[^/\s"\'<>][^\s"\'<>]*)$~i', $url) !== 1) {
            return null;
        }
        $dim = static fn(mixed $v): ?int => is_int($v) && $v > 0 ? $v : null;

        return [
            'id'            => $id,
            'url'           => $url,
            'original_name' => (string) ($row['original_name'] ?? ''),
            'alt_text'      => (string) ($row['alt_text'] ?? ''),
            'mime'          => (string) ($row['mime'] ?? ''),
            'size_bytes'    => max(0, (int) ($row['size_bytes'] ?? 0)),
            'width'         => $dim($row['width'] ?? null),
            'height'        => $dim($row['height'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isType(array $row, string $type): bool
    {
        if ($type !== 'video') {
            return ($row['kind'] ?? '') === 'image';
        }
        $ext = strtolower(pathinfo((string) ($row['path'] ?? $row['url'] ?? ''), PATHINFO_EXTENSION));
        return ($row['kind'] ?? '') === 'other'
            && in_array(strtolower((string) ($row['mime'] ?? '')), self::VIDEO_MIMES, true)
            && in_array($ext, self::VIDEO_EXTS, true);
    }

    /**
     * @param array<string, mixed> $result Media::listAll() output
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function shapeList(array $result, string $type = 'image'): array
    {
        $items = [];
        foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $row) {
            $item = is_array($row) ? self::shapeItem($row, $type) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return [
            'items' => $items,
            'total' => max(0, (int) ($result['total'] ?? 0)),
            'page'  => max(1, (int) ($result['page'] ?? 1)),
            'pages' => max(1, (int) ($result['pages'] ?? 1)),
        ];
    }
}
