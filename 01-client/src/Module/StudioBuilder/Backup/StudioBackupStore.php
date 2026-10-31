<?php
/**
 * Kohevo Studio (studio-builder) — where a tenant's backup files live.
 *
 * `db_backups/studio/<tenant>/`, next to the Backups plugin's staging folder and denied to the web the same way: a backup
 * holds the site's code snippets and every draft, so it is only ever handed out by the admin screen, to a Studio
 * administrator. A backup is addressed by its file name, and a name is only accepted when it matches the shape this class
 * makes, so a request can never point at another path.
 *
 * Kinds: `manual` (made here), `safety` (made by a restore, before it writes), `upload` (brought in to restore).
 * Each kind keeps its newest files only; `safety` backups hold no media, so they stay small.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Backup;

final class StudioBackupStore
{
    public const KIND_MANUAL = 'manual';
    public const KIND_SAFETY = 'safety';
    public const KIND_UPLOAD = 'upload';

    /** kind => files kept */
    public const KEEP = [self::KIND_MANUAL => 10, self::KIND_SAFETY => 10, self::KIND_UPLOAD => 3];

    private const NAME = '/^studio-(manual|safety|upload)-\d{8}-\d{6}-[0-9a-f]{8}\.zip$/';

    private readonly string $dir;

    public function __construct(int $tenantId, ?string $root = null)
    {
        $this->dir = rtrim($root ?? SLATE_ROOT . '/db_backups', '/') . '/studio/' . $tenantId;
    }

    /** A new, unused file path of this kind. */
    public function newPath(string $kind): string
    {
        if (!array_key_exists($kind, self::KEEP)) {
            throw new \InvalidArgumentException("Unknown backup kind '{$kind}'.");
        }
        $this->ensureDir();
        return $this->dir . '/studio-' . $kind . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    }

    /** The file of a backup id, or null: a name of the wrong shape, or a file that is not there. */
    public function path(string $id): ?string
    {
        if (preg_match(self::NAME, $id) !== 1) {
            return null;
        }
        $path = $this->dir . '/' . $id;
        return is_file($path) ? $path : null;
    }

    /** @return list<array{id: string, kind: string, size: int, created_at: int}> newest first */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/studio-*.zip') ?: [] as $path) {
            $id = basename($path);
            if (preg_match(self::NAME, $id, $m) === 1) {
                $out[] = ['id' => $id, 'kind' => $m[1], 'size' => (int) filesize($path), 'created_at' => (int) filemtime($path)];
            }
        }
        usort($out, static fn(array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        return $out;
    }

    public function delete(string $id): bool
    {
        $path = $this->path($id);
        return $path !== null && @unlink($path);
    }

    /** Drop the oldest files of a kind beyond what it keeps. @return list<string> the ids removed */
    public function prune(string $kind): array
    {
        $keep = self::KEEP[$kind] ?? null;
        if ($keep === null) {
            return [];
        }
        $ofKind = array_values(array_filter($this->all(), static fn(array $b): bool => $b['kind'] === $kind));
        $removed = [];
        foreach (array_slice($ofKind, $keep) as $backup) {
            if ($this->delete($backup['id'])) {
                $removed[] = $backup['id'];
            }
        }
        return $removed;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('The backup folder could not be created.');
        }
        $root = dirname($this->dir, 2);
        $deny = $root . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\nDeny from all\n");
        }
    }
}
