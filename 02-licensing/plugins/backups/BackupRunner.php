<?php
/**
 * Backups — the resumable backup state machine.
 *
 * No shell exec is available on shared hosting (see
 * docs/13-Operations/shared-hosting-compatibility.md), and there is no
 * Queue/worker system in Slate yet — so this follows the codebase's existing
 * idiom for chunked background work (GoogleCalendarSync::runCron()'s
 * `LIMIT 50` retry sweep, CoachingAPI::deliverScheduled()'s `LIMIT 200`):
 * a DB row (`backups_runs`) carries a `status` that doubles as the resume
 * step, advanced by one bounded chunk of work per `frequent_cron` tick
 * (~every 5 minutes). Each phase persists exactly enough progress to pick
 * back up where it left off — a dump/zip/upload can span many ticks without
 * ever needing one long-running HTTP request.
 *
 * Steps: pending -> dumping -> zipping -> uploading -> pruning -> done
 *                                                              \-> failed
 *
 * Local staging lives under db_backups/run-<id>/ (denied to the web via
 * .htaccess, same hardening Uploads.php applies to uploads/) and is deleted
 * as soon as the upload to Drive succeeds — or on failure — so a live DB
 * dump full of customer PII never sits on disk longer than necessary.
 */

class BackupRunner
{
    // Soft wall-clock budget per tick. Deliberately time-based rather than a
    // fixed row/file count (the existing LIMIT-50/LIMIT-200 convention) —
    // dump/zip work sizes vary far more per item than those two jobs do.
    private const TICK_BUDGET_SECONDS = 18;
    private const DUMP_ROW_BATCH       = 500;

    // ── Settings ──────────────────────────────────────────────────

    public static function isEnabled(): bool {
        return (string)(Database::setting('backups.enabled') ?? '0') === '1';
    }

    public static function retentionCount(): int {
        return max(1, (int)(Database::setting('backups.retention_count') ?? 14));
    }

    private static function localDir(int $runId): string {
        $root = SLATE_ROOT . '/db_backups';
        if (!is_dir($root)) @mkdir($root, 0755, true);
        $htaccess = $root . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $dir = $root . '/run-' . $runId;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── Entry points ──────────────────────────────────────────────

    /** daily_cron listener. */
    public static function runDailyCron(): void {
        if (!self::isEnabled() || !GoogleDriveClient::isConnected()) return;
        self::startRun();
    }

    /** frequent_cron listener — advances whatever's in progress by one bounded chunk. */
    public static function runFrequentCron(): void {
        if (!self::isEnabled()) return;
        $tid = current_tenant_id();
        $run = Database::row(
            "SELECT * FROM backups_runs WHERE tenant_id = ? AND status NOT IN ('done','failed') ORDER BY id ASC LIMIT 1",
            [$tid]
        );
        if ($run) self::advance((int)$run['id']);
    }

    /** Starts a new run if none is already in progress. Returns the new run id, or null. */
    public static function startRun(): ?int {
        $tid = current_tenant_id();
        $active = Database::value(
            "SELECT id FROM backups_runs WHERE tenant_id = ? AND status NOT IN ('done','failed') LIMIT 1", [$tid]
        );
        if ($active) return null;
        return Database::insert('backups_runs', [
            'tenant_id'  => $tid,
            'status'     => 'pending',
            'started_at' => slate_db_now(),
        ]);
    }

    /** Manual "Run backup now" — starts (if idle) and advances once synchronously for instant feedback. */
    public static function runNow(): array {
        if (!GoogleDriveClient::isConfigured()) {
            return ['ok' => false, 'error' => 'Connect Google Drive first.'];
        }
        $tid = current_tenant_id();
        $active = Database::row(
            "SELECT id FROM backups_runs WHERE tenant_id = ? AND status NOT IN ('done','failed') LIMIT 1", [$tid]
        );
        $id = $active ? (int)$active['id'] : self::startRun();
        if ($id === null) return ['ok' => false, 'error' => 'A backup is already running.'];
        self::advance($id);
        return ['ok' => true, 'run_id' => $id];
    }

    public static function advance(int $runId): void {
        // Scoped like the queries that produce $runId (runFrequentCron, runNow),
        // so a run id from one tenant can never be advanced under another.
        $run = Database::row("SELECT * FROM backups_runs WHERE id = ? AND tenant_id = ?", [$runId, current_tenant_id()]);
        if (!$run || in_array($run['status'], ['done', 'failed'], true)) return;

        try {
            switch ($run['status']) {
                case 'pending':   self::beginDump($run); break;
                case 'dumping':   self::stepDump($run);  break;
                case 'zipping':   self::stepZip($run);   break;
                case 'uploading': self::stepUpload($run); break;
                case 'pruning':   self::stepPrune($run); break;
            }
        } catch (\Throwable $e) {
            self::fail($run, $e->getMessage());
        }
    }

    private static function fail(array $run, string $message): void {
        slate_log('BackupRunner: run ' . $run['id'] . ' failed: ' . $message, 'error');
        Database::update('backups_runs', [
            'status'      => 'failed',
            'error'       => $message,
            'finished_at' => slate_db_now(),
        ], 'id = ?', [(int)$run['id']]);
        if (!empty($run['local_path'])) self::rrmdir((string)$run['local_path']);
        AuditLog::record('backups.failed', (string)$run['id'], ['error' => $message]);
    }

    // ── Phase 1: DB dump (pure PHP — no mysqldump on shared hosting) ────

    private static function beginDump(array $run): void {
        $dir = self::localDir((int)$run['id']);
        file_put_contents($dir . '/dump.sql', ''); // truncate/create
        $tables = Database::rows(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        );
        $tableNames = array_map(fn($r) => (string)$r['TABLE_NAME'], $tables);

        Database::update('backups_runs', [
            'status'        => 'dumping',
            'local_path'    => $dir,
            'progress_json' => json_encode(['tables' => $tableNames, 'table_idx' => 0, 'offset' => 0]),
        ], 'id = ?', [(int)$run['id']]);

        $run = Database::row("SELECT * FROM backups_runs WHERE id = ? AND tenant_id = ?", [(int)$run['id'], (int)$run['tenant_id']]);
        self::stepDump($run); // give this tick real work instead of just bookkeeping
    }

    private static function stepDump(array $run): void {
        $progress = json_decode((string)$run['progress_json'], true) ?: [];
        $tables    = $progress['tables']    ?? [];
        $tableIdx  = (int)($progress['table_idx'] ?? 0);
        $offset    = (int)($progress['offset']     ?? 0);

        $fh = fopen($run['local_path'] . '/dump.sql', 'ab');
        if ($fh === false) throw new \RuntimeException('Could not open dump.sql for writing.');

        $start = microtime(true);
        while ($tableIdx < count($tables) && (microtime(true) - $start) < self::TICK_BUDGET_SECONDS) {
            $table = $tables[$tableIdx];

            if ($offset === 0) {
                $create = Database::row("SHOW CREATE TABLE `{$table}`");
                fwrite($fh, "\n-- Table: {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" . ($create['Create Table'] ?? '') . ";\n\n");
            }

            $rows = Database::rows("SELECT * FROM `{$table}` LIMIT ? OFFSET ?", [self::DUMP_ROW_BATCH, $offset]);
            foreach ($rows as $row) {
                $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($row)));
                $vals = implode(',', array_map([self::class, 'sqlLiteral'], array_values($row)));
                fwrite($fh, "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals});\n");
            }
            $offset += count($rows);
            if (count($rows) < self::DUMP_ROW_BATCH) { $tableIdx++; $offset = 0; }
        }
        fclose($fh);

        // Persist how far dumping actually got BEFORE attempting the next
        // phase — beginZip() is cheap/local and unlikely to fail, but if it
        // ever does, the next tick must see "dumping is done" rather than
        // re-opening dump.sql and appending duplicate INSERTs for the last
        // table it already finished.
        Database::update('backups_runs', [
            'progress_json' => json_encode(['tables' => $tables, 'table_idx' => $tableIdx, 'offset' => $offset]),
        ], 'id = ?', [(int)$run['id']]);

        if ($tableIdx >= count($tables)) {
            $run = Database::row("SELECT * FROM backups_runs WHERE id = ? AND tenant_id = ?", [(int)$run['id'], (int)$run['tenant_id']]);
            self::beginZip($run);
        }
    }

    private static function sqlLiteral($v): string {
        if ($v === null) return 'NULL';
        if (is_int($v)) return (string)$v;
        if (is_float($v)) return sprintf('%.17g', $v);
        return Database::get()->quote((string)$v);
    }

    // ── Phase 2: zip (dump.sql + uploads/ tree) ─────────────────────────

    private static function beginZip(array $run): void {
        $entries = [['disk' => $run['local_path'] . '/dump.sql', 'entry' => 'dump.sql']];

        $uploadsRoot = SLATE_ROOT . '/uploads';
        if (is_dir($uploadsRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $rel = 'uploads/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($uploadsRoot))), '/');
                $entries[] = ['disk' => $file->getPathname(), 'entry' => $rel];
            }
        }

        Database::update('backups_runs', [
            'status'        => 'zipping',
            'progress_json' => json_encode(['remaining' => $entries]),
        ], 'id = ?', [(int)$run['id']]);

        $run = Database::row("SELECT * FROM backups_runs WHERE id = ? AND tenant_id = ?", [(int)$run['id'], (int)$run['tenant_id']]);
        self::stepZip($run);
    }

    private static function stepZip(array $run): void {
        $progress  = json_decode((string)$run['progress_json'], true) ?: [];
        $remaining = $progress['remaining'] ?? [];
        $zipPath   = $run['local_path'] . '/backup.zip';

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, file_exists($zipPath) ? 0 : \ZipArchive::CREATE);
        if ($opened !== true) throw new \RuntimeException('Could not open backup.zip (code ' . $opened . ').');

        $start = microtime(true);
        while ($remaining && (microtime(true) - $start) < self::TICK_BUDGET_SECONDS) {
            $entry = array_shift($remaining);
            if (is_file($entry['disk'])) $zip->addFile($entry['disk'], $entry['entry']);
        }
        if (!$zip->close()) throw new \RuntimeException('Could not finalize backup.zip.');

        // Persist first — if beginUpload() throws (e.g. Drive isn't
        // connected), the next tick must see "zipping is done, nothing left
        // to add" rather than re-scanning and re-adding files that are
        // already in the archive.
        Database::update('backups_runs', [
            'progress_json' => json_encode(['remaining' => $remaining]),
        ], 'id = ?', [(int)$run['id']]);

        if (!$remaining) {
            $run = Database::row("SELECT * FROM backups_runs WHERE id = ? AND tenant_id = ?", [(int)$run['id'], (int)$run['tenant_id']]);
            self::beginUpload($run, $zipPath);
        }
    }

    // ── Phase 3: upload to Google Drive (resumable) ─────────────────────

    private static function beginUpload(array $run, string $zipPath): void {
        $token = GoogleDriveClient::accessToken();
        if (!$token) throw new \RuntimeException('Google Drive isn\'t connected (no valid access token).');
        $folderId = GoogleDriveClient::ensureFolder($token);
        if (!$folderId) throw new \RuntimeException('Could not find/create the Kohevo Backups folder in Drive.');

        $totalBytes = (int)filesize($zipPath);
        $filename   = 'slate-backup-' . date('Y-m-d_His') . '.zip';
        $sessionUri = GoogleDriveClient::startResumableUpload($token, $folderId, $filename, $totalBytes);
        if (!$sessionUri) throw new \RuntimeException('Could not start the Drive upload session.');

        Database::update('backups_runs', [
            'status'          => 'uploading',
            'drive_file_name' => $filename,
            'progress_json'   => json_encode(['session_uri' => $sessionUri, 'bytes_sent' => 0, 'total_bytes' => $totalBytes]),
        ], 'id = ?', [(int)$run['id']]);

        $run = Database::row("SELECT * FROM backups_runs WHERE id = ? AND tenant_id = ?", [(int)$run['id'], (int)$run['tenant_id']]);
        self::stepUpload($run);
    }

    private static function stepUpload(array $run): void {
        $progress   = json_decode((string)$run['progress_json'], true) ?: [];
        $sessionUri = (string)($progress['session_uri'] ?? '');
        $bytesSent  = (int)($progress['bytes_sent']  ?? 0);
        $totalBytes = (int)($progress['total_bytes'] ?? 0);
        $zipPath    = $run['local_path'] . '/backup.zip';

        $start = microtime(true);
        $fileId = null;
        while ($bytesSent < $totalBytes && (microtime(true) - $start) < self::TICK_BUDGET_SECONDS) {
            $len = min(GoogleDriveClient::CHUNK_SIZE, $totalBytes - $bytesSent);
            $result = GoogleDriveClient::uploadChunk($sessionUri, $zipPath, $bytesSent, $len, $totalBytes);
            if ($result['error'] !== null) throw new \RuntimeException($result['error']);
            $bytesSent = $result['next_offset'];
            if ($result['done']) { $fileId = $result['file_id']; break; }
        }

        if ($fileId !== null || $bytesSent >= $totalBytes) {
            Database::update('backups_runs', [
                'status'        => 'pruning',
                'drive_file_id' => $fileId,
                'size_bytes'    => $totalBytes,
            ], 'id = ?', [(int)$run['id']]);
            self::rrmdir((string)$run['local_path']); // upload confirmed — stop holding a PII copy on disk
            $run = Database::row("SELECT * FROM backups_runs WHERE id = ? AND tenant_id = ?", [(int)$run['id'], (int)$run['tenant_id']]);
            self::stepPrune($run);
            return;
        }
        Database::update('backups_runs', [
            'progress_json' => json_encode(['session_uri' => $sessionUri, 'bytes_sent' => $bytesSent, 'total_bytes' => $totalBytes]),
        ], 'id = ?', [(int)$run['id']]);
    }

    // ── Phase 4: retention pruning ───────────────────────────────────────

    private static function stepPrune(array $run): void {
        $token = GoogleDriveClient::accessToken();
        $folderId = (string)(Database::setting('backups.google_folder_id') ?? '');
        if ($token && $folderId !== '') {
            $files = GoogleDriveClient::listFilesInFolder($token, $folderId); // oldest-first
            $keep  = self::retentionCount();
            if (count($files) > $keep) {
                foreach (array_slice($files, 0, count($files) - $keep) as $old) {
                    GoogleDriveClient::deleteFile($token, (string)$old['id']);
                }
            }
        }

        Database::update('backups_runs', [
            'status'      => 'done',
            'finished_at' => slate_db_now(),
        ], 'id = ?', [(int)$run['id']]);
        AuditLog::record('backups.completed', (string)$run['id'], [
            'size_bytes'    => $run['size_bytes'] ?? null,
            'drive_file_id' => $run['drive_file_id'] ?? null,
        ]);
    }
}
