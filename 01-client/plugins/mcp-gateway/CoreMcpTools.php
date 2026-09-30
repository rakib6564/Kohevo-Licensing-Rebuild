<?php
/**
 * MCP Gateway — Phase 1 core tools (settings, media, notifications, audit,
 * debug, cron, tests). Owned by the gateway itself, but registered through
 * the exact same slate_mcp_scopes / slate_mcp_tools / slate_mcp_call_tool
 * filters a third-party plugin would use — the gateway dogfoods its own
 * extension point rather than special-casing these in the dispatcher.
 *
 * Nothing here writes a password/secret field, deletes a role or user, or
 * touches this plugin's own active status — see McpGatewayAPI::isBlocked()
 * and README.md. slate_settings_set additionally refuses any key that
 * looks like a secret, since Layer 2's guard only inspects the tool NAME
 * and this tool's key is caller-supplied.
 */

declare(strict_types=1);

use Slate\Data\MigrationRunner;

class CoreMcpTools {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['mcp-gateway.settings.read']      = 'Read site/plugin settings (secret-looking values are hidden)';
        $scopes['mcp-gateway.settings.write']      = 'Change non-secret site/plugin settings';
        $scopes['mcp-gateway.media.read']          = 'List media library files';
        $scopes['mcp-gateway.media.write']         = 'Upload and delete media library files';
        $scopes['mcp-gateway.notifications.write'] = 'Send an admin notification';
        $scopes['mcp-gateway.audit.read']          = 'Search the audit log';
        $scopes['mcp-gateway.debug.read']          = 'Tail logs, check migration status, inspect DB schema (read-only)';
        $scopes['mcp-gateway.cron.write']          = 'Trigger a scheduled cron job on demand';
        $scopes['mcp-gateway.tests.run']           = 'Run the automated test suite';
        return $scopes;
    }

    private static function has(array $context, string $scope): bool {
        return in_array($scope, (array)($context['scopes'] ?? []), true);
    }

    public static function filterTools(array $tools, array $context): array {
        if (self::has($context, 'mcp-gateway.settings.read')) {
            $tools[] = [
                'name' => 'slate_settings_get',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Read one or more settings by key. Values whose key looks like a password/secret are returned as "[hidden]".',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Setting keys, e.g. "site_name", "booking.multislot_max"'],
                ], 'required' => ['keys'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.settings.write')) {
            $tools[] = [
                'name' => 'slate_settings_set',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Set a single setting by key. Refused if the key looks like a password/secret field.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'key'   => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                ], 'required' => ['key', 'value'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.media.read')) {
            $tools[] = [
                'name' => 'slate_media_list',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'List files in the media library.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'type'     => ['type' => 'string', 'enum' => ['all', 'image', 'document'], 'description' => 'Default "all"'],
                    'search'   => ['type' => 'string'],
                    'page'     => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.media.write')) {
            $tools[] = [
                'name' => 'slate_media_upload',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Upload an image or document to the media library from base64-encoded content.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'filename'       => ['type' => 'string', 'description' => 'Original filename, used only for its extension'],
                    'content_base64' => ['type' => 'string', 'description' => 'Raw file bytes, base64-encoded'],
                ], 'required' => ['filename', 'content_base64'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_delete',
                'classification' => ['access' => 'destructive', 'requires_confirmation' => true],
                'description' => 'Delete a media library file by id. Refused if the file is still referenced anywhere.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'],
                ], 'required' => ['id'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.notifications.write')) {
            $tools[] = [
                'name' => 'slate_notifications_send',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Send a notification into the admin notifications inbox.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'],
                    'body'  => ['type' => 'string'],
                    'url'   => ['type' => 'string'],
                    'icon'  => ['type' => 'string'],
                ], 'required' => ['title'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.audit.read')) {
            $tools[] = [
                'name' => 'slate_audit_search',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Search the audit log.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'action'  => ['type' => 'string', 'description' => 'Exact action key, e.g. "mcp-gateway.token_created"'],
                    'user_id' => ['type' => 'integer'],
                    'from'    => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS'],
                    'to'      => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS'],
                    'limit'   => ['type' => 'integer'],
                ], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.debug.read')) {
            $tools[] = [
                'name' => 'slate_logs_tail',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Tail the application log (data/slate.log).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'lines' => ['type' => 'integer', 'description' => 'Default 200, max 1000'],
                    'level' => ['type' => 'string', 'description' => 'Filter to lines containing this level, e.g. "error"'],
                ], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_migration_status',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Show which core database migrations are applied vs pending.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ];
            $tools[] = [
                'name' => 'slate_db_schema_inspect',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Read-only schema introspection via information_schema. Omit "table" to list all tables; pass it to list that table\'s columns.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'table' => ['type' => 'string'],
                ], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.cron.write')) {
            $tools[] = [
                'name' => 'slate_cron_trigger',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Run a scheduled cron job on demand (the same hooks cron.php fires).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'job' => ['type' => 'string', 'enum' => ['frequent', 'daily']],
                ], 'required' => ['job'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.tests.run')) {
            $tools[] = [
                'name' => 'slate_tests_run',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Run the automated test suite (unit, integration, or smoke) and return pass/fail plus output. May take a while.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'suite' => ['type' => 'string', 'enum' => ['unit', 'integration', 'smoke', 'all'], 'description' => 'Default "all"'],
                ], 'additionalProperties' => false],
            ];
        }
        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;

        switch ($name) {
            case 'slate_settings_get':
                self::requireScope($context, 'mcp-gateway.settings.read');
                return self::settingsGet($args);

            case 'slate_settings_set':
                self::requireScope($context, 'mcp-gateway.settings.write');
                return self::settingsSet($args);

            case 'slate_media_list':
                self::requireScope($context, 'mcp-gateway.media.read');
                return Media::listAll([
                    'type' => $args['type'] ?? 'all', 'search' => $args['search'] ?? '',
                    'page' => (int)($args['page'] ?? 1), 'per_page' => (int)($args['per_page'] ?? 24),
                ]);

            case 'slate_media_upload':
                self::requireScope($context, 'mcp-gateway.media.write');
                return self::mediaUpload((string)($args['filename'] ?? ''), (string)($args['content_base64'] ?? ''));

            case 'slate_media_delete':
                self::requireScope($context, 'mcp-gateway.media.write');
                return Media::delete((int)($args['id'] ?? 0));

            case 'slate_notifications_send':
                self::requireScope($context, 'mcp-gateway.notifications.write');
                $title = trim((string)($args['title'] ?? ''));
                if ($title === '') throw new InvalidArgumentException('title is required.');
                Notifications::add($title, [
                    'body' => (string)($args['body'] ?? ''), 'url' => (string)($args['url'] ?? ''),
                    'icon' => (string)($args['icon'] ?? 'sparkles'),
                ]);
                return ['ok' => true];

            case 'slate_audit_search':
                self::requireScope($context, 'mcp-gateway.audit.read');
                return ['entries' => AuditLog::search([
                    'action' => $args['action'] ?? null, 'user_id' => $args['user_id'] ?? null,
                    'from' => $args['from'] ?? null, 'to' => $args['to'] ?? null,
                    'limit' => (int)($args['limit'] ?? 100),
                ])];

            case 'slate_logs_tail':
                self::requireScope($context, 'mcp-gateway.debug.read');
                return self::logsTail((int)($args['lines'] ?? 200), (string)($args['level'] ?? ''));

            case 'slate_migration_status':
                self::requireScope($context, 'mcp-gateway.debug.read');
                $runner = new MigrationRunner(Database::get(), SLATE_ROOT . '/db/migrations');
                return ['status' => $runner->status(), 'pending' => $runner->pending()];

            case 'slate_db_schema_inspect':
                self::requireScope($context, 'mcp-gateway.debug.read');
                return self::schemaInspect((string)($args['table'] ?? ''));

            case 'slate_cron_trigger':
                self::requireScope($context, 'mcp-gateway.cron.write');
                $job = (string)($args['job'] ?? '');
                if (!in_array($job, ['frequent', 'daily'], true)) throw new InvalidArgumentException('job must be "frequent" or "daily".');
                Hook::doAction($job . '_cron');
                return ['ok' => true, 'job' => $job];

            case 'slate_tests_run':
                self::requireScope($context, 'mcp-gateway.tests.run');
                return self::testsRun((string)($args['suite'] ?? 'all'));
        }

        return null;
    }

    private static function requireScope(array $context, string $scope): void {
        if (!in_array($scope, (array)($context['scopes'] ?? []), true)) {
            throw new RuntimeException("This token does not grant the \"$scope\" scope.");
        }
    }

    /** Anything that looks like a password/secret field, by key name. */
    private static function looksSensitive(string $key): bool {
        return (bool) preg_match('/pass|secret/i', $key);
    }

    private static function settingsGet(array $args): array {
        $keys = array_values(array_filter(array_map('strval', (array)($args['keys'] ?? []))));
        if (!$keys) throw new InvalidArgumentException('keys is required and must be non-empty.');
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::looksSensitive($key) ? '[hidden]' : Database::setting($key);
        }
        return ['settings' => $out];
    }

    private static function settingsSet(array $args): array {
        $key = trim((string)($args['key'] ?? ''));
        if ($key === '') throw new InvalidArgumentException('key is required.');
        if (self::looksSensitive($key)) {
            throw new RuntimeException('Refusing to set a setting whose key looks like a password/secret field. Change it from the admin UI instead.');
        }
        Database::setSetting($key, (string)($args['value'] ?? ''));
        AuditLog::record('mcp-gateway.settings_set', $key);
        return ['ok' => true, 'key' => $key];
    }

    /**
     * Writes base64-decoded bytes into the media library. Can't reuse
     * Media::upload()/Uploads::handle() — both require a real $_FILES entry
     * (move_uploaded_file() only works on an actual HTTP-uploaded tmp file).
     * This mirrors their validation (allowlisted mime/ext, size cap, SVG
     * sanitization, randomized filename, hardened folder) for raw bytes
     * instead.
     */
    private static function mediaUpload(string $filename, string $contentBase64): array {
        if ($filename === '' || $contentBase64 === '') {
            throw new InvalidArgumentException('filename and content_base64 are required.');
        }
        $bytes = base64_decode($contentBase64, true);
        if ($bytes === false || $bytes === '') throw new InvalidArgumentException('content_base64 is not valid base64.');
        if (strlen($bytes) > Uploads::DEFAULT_MAX_BYTES) {
            throw new InvalidArgumentException('File too large (max ' . round(Uploads::DEFAULT_MAX_BYTES / 1024 / 1024) . ' MB).');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, Media::allowedExts(), true)) {
            throw new InvalidArgumentException('File extension not allowed.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mcpmedia');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('Could not stage the upload.');
        }
        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmp) ?: 'application/octet-stream';
            if (!in_array($mime, Media::allowedMimes(), true)) {
                throw new InvalidArgumentException('File type not allowed.');
            }

            $folder = Media::FOLDER . '/' . date('Y/m');
            $dir    = Uploads::publicUploadDir($folder); // creates dir + drops hardening .htaccess
            $newName = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
            $absPath = $dir . '/' . $newName;
            if (!@copy($tmp, $absPath)) throw new RuntimeException('Could not save the uploaded file.');
            @chmod($absPath, 0644);

            $relPath = '/uploads/' . $folder . '/' . $newName;

            if ($ext === 'svg' || $mime === 'image/svg+xml') {
                if (!Media::sanitizeSvgFile($absPath)) {
                    Uploads::remove($relPath);
                    throw new RuntimeException('Could not process SVG file.');
                }
            }

            $w = null; $h = null;
            if (Media::kindForMime($mime, $ext) === 'image') {
                $info = @getimagesize($absPath);
                if (is_array($info)) { $w = $info[0] ?? null; $h = $info[1] ?? null; }
            }

            $id = Media::register($relPath, [
                'mime' => $mime, 'size_bytes' => strlen($bytes), 'width' => $w, 'height' => $h,
                'original_name' => basename($filename),
            ]);
            AuditLog::record('media.uploaded', $relPath, ['id' => $id, 'via' => 'mcp-gateway']);
            return Media::get($id) ?? ['id' => $id, 'path' => $relPath];
        } finally {
            @unlink($tmp);
        }
    }

    private static function logsTail(int $lines, string $level): array {
        $lines = max(1, min(1000, $lines));
        $path  = SLATE_ROOT . '/data/slate.log';
        if (!is_file($path)) return ['lines' => []];
        // Bounded tail: read only the last ~2MB of the file, not the whole
        // thing, so a large log can't blow up a single MCP response.
        $maxBytes = 2 * 1024 * 1024;
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'r');
        if ($fh === false) return ['lines' => []];
        if ($size > $maxBytes) fseek($fh, $size - $maxBytes);
        $content = stream_get_contents($fh);
        fclose($fh);
        $all = explode("\n", trim((string)$content));
        if ($level !== '') {
            $needle = '[' . strtoupper($level) . ']';
            $all = array_values(array_filter($all, static fn($l) => stripos($l, $needle) !== false));
        }
        return ['lines' => array_slice($all, -$lines)];
    }

    private static function schemaInspect(string $table): array {
        $table = trim($table);
        if ($table === '') {
            $rows = Database::rows(
                "SELECT table_name AS name, table_rows AS approx_rows
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
               ORDER BY table_name", []
            );
            return ['tables' => $rows];
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new InvalidArgumentException('Invalid table name.');
        $cols = Database::rows(
            "SELECT column_name AS name, column_type AS type, is_nullable, column_key, column_default
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
           ORDER BY ordinal_position",
            [$table]
        );
        if (!$cols) throw new InvalidArgumentException('Table not found.');
        return ['table' => $table, 'columns' => $cols];
    }

    /**
     * Shells the existing dependency-free test harness (tests/run.sh and
     * friends) rather than reimplementing test discovery. Bounded runtime —
     * an MCP call sits inside a web request, so this is killed and reported
     * as timed-out rather than left to run indefinitely.
     */
    private static function testsRun(string $suite): array {
        $scripts = [
            'unit'        => 'php tests/unit/run.php',
            'integration' => 'php tests/integration/run.php',
            'smoke'       => 'php tests/smoke.php',
        ];
        if ($suite === '' || $suite === 'all') {
            $cmd = 'bash tests/run.sh';
        } elseif (isset($scripts[$suite])) {
            $cmd = $scripts[$suite];
        } else {
            throw new InvalidArgumentException('suite must be one of unit, integration, smoke, all.');
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, SLATE_ROOT);
        if (!is_resource($proc)) throw new RuntimeException('Could not start the test process.');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + 120; // 2 minutes
        $timedOut = false;
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string)stream_get_contents($pipes[1]);
            $out .= (string)stream_get_contents($pipes[2]);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) { $timedOut = true; proc_terminate($proc); break; }
            if (strlen($out) > 200000) { proc_terminate($proc); break; } // cap output
            usleep(100000);
        }
        fclose($pipes[1]); fclose($pipes[2]);
        $exitCode = $timedOut ? null : proc_close($proc);

        return [
            'suite'     => $suite,
            'timed_out' => $timedOut,
            'exit_code' => $exitCode,
            'passed'    => $exitCode === 0,
            'output'    => mb_substr($out, -20000), // tail only — keep the response bounded
        ];
    }
}
