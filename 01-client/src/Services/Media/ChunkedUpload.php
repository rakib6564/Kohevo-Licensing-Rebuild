<?php
/**
 * Slate — large uploads sent in pieces, so a video bigger than the server's upload limit still gets through.
 *
 *   start()  checks the name and size against the library's rules and hands back a token and a piece size that fits
 *            under this server's upload_max_filesize / post_max_size.
 *   append() adds the next piece. Pieces arrive in order: each must start where the file so far ends, so a retried
 *            piece is either accepted once or refused, never written twice.
 *   finish() hands the whole file to Media::ingestLocal(), which checks it again like any upload.
 *
 * A part lives in data/media-chunks/<tenant>/ (no web access) as <token>.part plus <token>.json, owned by the user
 * who started it. cleanup() (daily cron) removes parts nobody finished within a day.
 */

declare(strict_types=1);

namespace Slate\Services\Media;

final class ChunkedUpload
{
    public const MIN_CHUNK = 512 * 1024;
    public const MAX_CHUNK = 8 * 1024 * 1024;
    public const MAX_AGE = 86400;

    /** The piece size for this server: under both PHP upload limits, with room for the form around it. */
    public static function chunkSize(): int
    {
        $limit = min(self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size')));
        if ($limit <= 0) {
            $limit = self::MAX_CHUNK;
        }
        return max(self::MIN_CHUNK, min(self::MAX_CHUNK, $limit - 64 * 1024));
    }

    /** '8M' → 8388608; '0' or '' (no limit) → 0. */
    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @return array{ok: true, token: string, chunk_size: int}|array{ok: false, error: string}
     */
    public static function start(string $name, int $size, int $userId): array
    {
        $name = self::cleanName($name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !in_array($ext, Media::allowedExts(), true)) {
            return ['ok' => false, 'error' => __('core_err_file_extension_not_allowed', 'File extension not allowed.')];
        }
        $max = Media::maxBytesFor($name);
        if ($size <= 0) {
            return ['ok' => false, 'error' => __('core_err_no_file_submitted', 'No file submitted.')];
        }
        if ($size > $max) {
            return ['ok' => false, 'error' => 'File too large (max ' . round($max / 1024 / 1024) . ' MB).'];
        }
        $dir = self::dir();
        if ($dir === null) {
            return ['ok' => false, 'error' => 'Could not prepare the upload.'];
        }
        $token = bin2hex(random_bytes(16));
        $meta = ['name' => $name, 'size' => $size, 'user' => $userId, 'started' => time()];
        if (@file_put_contents($dir . '/' . $token . '.json', json_encode($meta)) === false || @file_put_contents($dir . '/' . $token . '.part', '') === false) {
            @unlink($dir . '/' . $token . '.json');
            return ['ok' => false, 'error' => 'Could not prepare the upload.'];
        }
        return ['ok' => true, 'token' => $token, 'chunk_size' => self::chunkSize()];
    }

    /**
     * Add one piece read from $chunkFile, which must start at byte $offset of the whole file.
     *
     * @return array{ok: true, received: int, size: int}|array{ok: false, error: string}
     */
    public static function append(string $token, int $userId, int $offset, string $chunkFile): array
    {
        $meta = self::meta($token, $userId);
        if ($meta === null) {
            return ['ok' => false, 'error' => 'This upload has expired. Please start again.'];
        }
        $part = self::dir() . '/' . $token . '.part';
        clearstatcache(true, $part);
        $have = (int) @filesize($part);
        $len = (int) @filesize($chunkFile);
        if ($offset !== $have) {
            return ['ok' => false, 'error' => 'Piece out of order.', 'received' => $have];
        }
        if ($len <= 0 || $len > self::MAX_CHUNK || $have + $len > $meta['size']) {
            return ['ok' => false, 'error' => 'Piece size is wrong.'];
        }
        $in = @fopen($chunkFile, 'rb');
        $out = @fopen($part, 'ab');
        if ($in === false || $out === false) {
            return ['ok' => false, 'error' => 'Could not save the piece.'];
        }
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        if ($copied !== $len) {
            // Put the file back to where it was, so the piece can be sent again.
            $fh = @fopen($part, 'r+b');
            if ($fh !== false) {
                ftruncate($fh, $have);
                fclose($fh);
            }
            return ['ok' => false, 'error' => 'Could not save the piece.'];
        }
        return ['ok' => true, 'received' => $have + $len, 'size' => (int) $meta['size']];
    }

    /**
     * Turn the finished part into a library item.
     *
     * @param array<string, mixed> $opts passed on to Media::ingestLocal() (folder_id, …)
     * @return array<string, mixed> the media record, or ['ok' => false, 'error' => …]
     */
    public static function finish(string $token, int $userId, array $opts = []): array
    {
        $meta = self::meta($token, $userId);
        if ($meta === null) {
            return ['ok' => false, 'error' => 'This upload has expired. Please start again.'];
        }
        $base = self::dir() . '/' . $token;
        clearstatcache(true, $base . '.part');
        if ((int) @filesize($base . '.part') !== (int) $meta['size']) {
            return ['ok' => false, 'error' => 'The upload is not complete.'];
        }
        try {
            return Media::ingestLocal($base . '.part', (string) $meta['name'], $opts);
        } finally {
            @unlink($base . '.part');
            @unlink($base . '.json');
        }
    }

    public static function abort(string $token, int $userId): void
    {
        if (self::meta($token, $userId) !== null) {
            @unlink(self::dir() . '/' . $token . '.part');
            @unlink(self::dir() . '/' . $token . '.json');
        }
    }

    /** Remove parts started more than $maxAge seconds ago, for every tenant. Returns how many were removed. */
    public static function cleanup(int $maxAge = self::MAX_AGE): int
    {
        $root = self::root();
        if (!is_dir($root)) {
            return 0;
        }
        $n = 0;
        foreach (glob($root . '/*/*.json') ?: [] as $json) {
            if (@filemtime($json) < time() - $maxAge) {
                @unlink(substr($json, 0, -5) . '.part');
                @unlink($json);
                $n++;
            }
        }
        foreach (glob($root . '/*/*.part') ?: [] as $orphan) {
            if (!is_file(substr($orphan, 0, -5) . '.json') && @filemtime($orphan) < time() - $maxAge) {
                @unlink($orphan);
            }
        }
        return $n;
    }

    /** @return array{name: string, size: int, user: int, started: int}|null */
    private static function meta(string $token, int $userId): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || ($dir = self::dir()) === null) {
            return null;
        }
        $meta = json_decode((string) @file_get_contents($dir . '/' . $token . '.json'), true);
        if (!is_array($meta) || (int) ($meta['user'] ?? -1) !== $userId || (int) ($meta['started'] ?? 0) < time() - self::MAX_AGE) {
            return null;
        }
        return ['name' => (string) $meta['name'], 'size' => (int) $meta['size'], 'user' => (int) $meta['user'], 'started' => (int) $meta['started']];
    }

    private static function root(): string
    {
        return SLATE_ROOT . '/data/media-chunks';
    }

    private static function dir(): ?string
    {
        $dir = self::root() . '/' . (int) current_tenant_id();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return $dir;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        return function_exists('mb_substr') ? mb_substr(trim($name), 0, 200) : substr(trim($name), 0, 200);
    }
}
