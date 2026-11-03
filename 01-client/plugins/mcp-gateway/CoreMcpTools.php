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
 * and README.md. slate_settings_set only accepts the keys listed in
 * writableSetting(), each checked the way its admin page checks it, since
 * Layer 2's guard only inspects the tool NAME and this tool's key is
 * caller-supplied. Mail servers, AI/webhook URLs, tokens, security and
 * plugin settings are changed from the admin UI only.
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
        $scopes['mcp-gateway.settings.write']      = 'Change general site settings (name, business details, currency, language, time and date)';
        $scopes['mcp-gateway.media.read']          = 'List media library files';
        $scopes['mcp-gateway.media.write']         = 'Upload, import, edit and trash media library files';
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
                'description' => 'Read one or more settings by key. Credentials (by key name) and encrypted values are returned as "[hidden]".',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Setting keys, e.g. "site_name", "booking.multislot_max"'],
                ], 'required' => ['keys'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.settings.write')) {
            $tools[] = [
                'name' => 'slate_settings_set',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Set one general setting: site_name, business_name, business_email, business_phone, business_address, business_hours, currency, currency_position, default_language, timezone, date_format, time_format or start_of_week. Other settings are changed from the admin UI.',
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
                'description' => 'List files in the media library (newest first). Each item has id, url, mime, size, alt_text, title, tags, folder_id and in_use.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'type'     => ['type' => 'string', 'enum' => ['all', 'image', 'video', 'audio', 'document'], 'description' => 'Default "all"'],
                    'search'   => ['type' => 'string', 'description' => 'Matches file name, alt text and title'],
                    'usage'    => ['type' => 'string', 'enum' => ['all', 'in_use', 'unused']],
                    'folder'   => ['type' => 'string', 'description' => 'A folder id, or "none" for files in no folder'],
                    'tag'      => ['type' => 'integer', 'description' => 'A tag id (see slate_media_folders)'],
                    'status'   => ['type' => 'string', 'enum' => ['', 'missing_alt', 'trash'], 'description' => '"missing_alt": images without alt text; "trash": trashed files'],
                    'sort'     => ['type' => 'string', 'enum' => ['newest', 'oldest', 'name', 'size']],
                    'page'     => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_get',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'One media library file by id, with the places it is used.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'],
                ], 'required' => ['id'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_folders',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'The media library folders and tags, with how many files each holds.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_stats',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Storage used by the media library: bytes and counts per type, smaller image copies, trash, unused files, files on disk and the largest files.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'mcp-gateway.media.write')) {
            $tools[] = [
                'name' => 'slate_media_upload',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Upload a file (image, document, video or audio, up to 10 MB) to the media library from base64-encoded content. If the same bytes are already in the library, that item is returned with "duplicate": true. For bigger files use slate_media_import_url.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'filename'       => ['type' => 'string', 'description' => 'Original filename; its extension must match the content'],
                    'content_base64' => ['type' => 'string', 'description' => 'Raw file bytes, base64-encoded'],
                    'folder_id'      => ['type' => 'integer', 'description' => 'Put the file in this folder'],
                ], 'required' => ['filename', 'content_base64'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_import_url',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Download a file from a public http(s) address into the media library (videos up to 256 MB, audio up to 128 MB, others up to 10 MB). Private and local addresses are refused.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'url'       => ['type' => 'string'],
                    'folder_id' => ['type' => 'integer', 'description' => 'Put the file in this folder'],
                ], 'required' => ['url'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_update',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Change a file\'s alt text, title, description, tags (replaces the list) or folder (null or 0 for no folder). Only the fields given are changed.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id'          => ['type' => 'integer'],
                    'alt_text'    => ['type' => 'string'],
                    'title'       => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'tags'        => ['type' => 'array', 'items' => ['type' => 'string']],
                    'folder_id'   => ['type' => ['integer', 'null']],
                ], 'required' => ['id'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_delete',
                'classification' => ['access' => 'destructive', 'requires_confirmation' => true],
                'description' => 'Move media library files to the trash (restorable with slate_media_restore for ' . Media::TRASH_DAYS . ' days, then removed). Files still used somewhere are refused.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id'  => ['type' => 'integer'],
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_media_restore',
                'classification' => ['access' => 'write', 'requires_confirmation' => false],
                'description' => 'Take media library files out of the trash.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ], 'required' => ['ids'], 'additionalProperties' => false],
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
                return Media::listAll(self::mediaFilters($args));

            case 'slate_media_get':
                self::requireScope($context, 'mcp-gateway.media.read');
                $item = Media::get((int)($args['id'] ?? 0));
                if (!$item) throw new InvalidArgumentException('No media file with that id.');
                return $item + ['used_in' => Media::usageDetails((int)$item['id'])];

            case 'slate_media_folders':
                self::requireScope($context, 'mcp-gateway.media.read');
                return ['folders' => Media::folders(), 'tags' => Media::tags()];

            case 'slate_media_stats':
                self::requireScope($context, 'mcp-gateway.media.read');
                return Media::storageStats() + ['counts' => Media::counts()];

            case 'slate_media_upload':
                self::requireScope($context, 'mcp-gateway.media.write');
                return self::mediaUpload((string)($args['filename'] ?? ''), (string)($args['content_base64'] ?? ''), (int)($args['folder_id'] ?? 0));

            case 'slate_media_import_url':
                self::requireScope($context, 'mcp-gateway.media.write');
                $res = Media::importUrl((string)($args['url'] ?? ''), ['folder_id' => max(0, (int)($args['folder_id'] ?? 0)), 'via' => 'mcp-gateway']);
                if (empty($res['id'])) throw new RuntimeException((string)($res['error'] ?? 'The file could not be imported.'));
                return $res;

            case 'slate_media_update':
                self::requireScope($context, 'mcp-gateway.media.write');
                return self::mediaUpdate($args);

            case 'slate_media_delete':
                self::requireScope($context, 'mcp-gateway.media.write');
                $ids = array_merge(isset($args['id']) ? [(int)$args['id']] : [], array_map('intval', (array)($args['ids'] ?? [])));
                if ($ids === []) throw new InvalidArgumentException('id or ids is required.');
                return Media::trash($ids);

            case 'slate_media_restore':
                self::requireScope($context, 'mcp-gateway.media.write');
                return ['restored' => Media::restore(array_map('intval', (array)($args['ids'] ?? [])))];

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

    /** Anything that looks like a credential, by key name. */
    private static function looksSensitive(string $key): bool {
        return (bool) preg_match('/pass|secret|token|api_?key|private|credential|webhook|signing/i', $key);
    }

    /**
     * The settings slate_settings_set may change, each normalised and checked
     * the way admin/settings.php does it. Returns the value to store, or
     * throws. Every other key (SMTP host, AI base URL, tokens, security,
     * permalinks, plugin settings, ...) is refused: repointing a server
     * setting can send stored credentials elsewhere, and a raw string would
     * bypass the page's validation and encryption.
     */
    private static function writableSetting(string $key, string $value): string {
        $text = static function (string $v, int $max): string {
            $v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/', '', $v) ?? '';
            $v = strip_tags(preg_replace('/<[^>]*>?/', '', $v) ?? '');
            return mb_substr(trim($v), 0, $max);
        };
        $bad = static fn (string $why): InvalidArgumentException => new InvalidArgumentException("Invalid value for {$key}: {$why}.");
        switch ($key) {
            case 'site_name':
                $v = $text(preg_replace('/\s+/', ' ', $value) ?? '', 120);
                if ($v === '') throw $bad('must not be empty');
                return $v;
            case 'business_name':    return $text($value, 190);
            case 'business_phone':   return $text($value, 64);
            case 'business_address': return $text($value, 500);
            case 'business_hours':   return $text($value, 500);
            case 'business_email':
                $v = trim($value);
                if ($v !== '' && (mb_strlen($v) > 190 || !filter_var($v, FILTER_VALIDATE_EMAIL))) throw $bad('not an email address');
                return $v;
            case 'currency':
                $v = strtoupper(trim($value));
                if (!preg_match('/^[A-Z]{3,8}$/', $v)) throw $bad('expected a currency code such as EUR');
                return $v;
            case 'currency_position':
                if (!in_array($value, ['before', 'after'], true)) throw $bad('expected "before" or "after"');
                return $value;
            case 'default_language':
                if (!preg_match('/^[a-z]{2}$/', $value)) throw $bad('expected a two-letter language code');
                return $value;
            case 'timezone':
                if (!in_array($value, \DateTimeZone::listIdentifiers(), true)) throw $bad('unknown timezone');
                return $value;
            case 'date_format':
            case 'time_format':
                if (!preg_match('/^[A-Za-z0-9 .,:\/\-]{1,32}$/', $value)) throw $bad('unsupported format characters');
                return $value;
            case 'start_of_week':
                if (!preg_match('/^[0-6]$/', $value)) throw $bad('expected 0 (Sunday) to 6');
                return $value;
        }
        throw new InvalidArgumentException("The setting '{$key}' can't be changed through MCP. Change it from the admin UI.");
    }

    private static function settingsGet(array $args): array {
        $keys = array_values(array_filter(array_map('strval', (array)($args['keys'] ?? []))));
        if (!$keys) throw new InvalidArgumentException('keys is required and must be non-empty.');
        $out = [];
        foreach ($keys as $key) {
            $v = Database::setting($key);
            $out[$key] = self::looksSensitive($key) || str_starts_with((string) $v, 'enc:v1:') ? '[hidden]' : $v;
        }
        return ['settings' => $out];
    }

    private static function settingsSet(array $args): array {
        $key = trim((string)($args['key'] ?? ''));
        if ($key === '') throw new InvalidArgumentException('key is required.');
        Database::setSetting($key, self::writableSetting($key, (string)($args['value'] ?? '')));
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
    private static function mediaUpload(string $filename, string $contentBase64, int $folderId = 0): array {
        if ($filename === '' || $contentBase64 === '') {
            throw new InvalidArgumentException('filename and content_base64 are required.');
        }
        $bytes = base64_decode($contentBase64, true);
        if ($bytes === false || $bytes === '') throw new InvalidArgumentException('content_base64 is not valid base64.');
        if (strlen($bytes) > Uploads::DEFAULT_MAX_BYTES) {
            throw new InvalidArgumentException('File too large (max ' . round(Uploads::DEFAULT_MAX_BYTES / 1024 / 1024) . ' MB). Use slate_media_import_url for bigger files.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mcpmedia');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('Could not stage the upload.');
        }
        try {
            // The same path as a browser upload: type and extension checks, duplicate check, SVG and photo clean-up, image sizes.
            $res = Media::ingestLocal($tmp, basename($filename), ['folder_id' => max(0, $folderId), 'via' => 'mcp-gateway']);
            if (empty($res['id'])) throw new InvalidArgumentException((string)($res['error'] ?? 'The file could not be uploaded.'));
            return $res;
        } finally {
            @unlink($tmp);
        }
    }

    /** slate_media_list arguments → Media::listAll() filters. */
    private static function mediaFilters(array $args): array {
        $folder = (string)($args['folder'] ?? 'all');
        $status = (string)($args['status'] ?? '');
        return [
            'type'     => (string)($args['type'] ?? 'all'),
            'search'   => (string)($args['search'] ?? ''),
            'usage'    => (string)($args['usage'] ?? 'all'),
            'folder'   => $folder === 'none' || ctype_digit($folder) ? $folder : 'all',
            'tag'      => max(0, (int)($args['tag'] ?? 0)),
            'alt'      => $status === 'missing_alt' ? 'missing' : '',
            'trash'    => $status === 'trash',
            'sort'     => (string)($args['sort'] ?? 'newest'),
            'page'     => max(1, (int)($args['page'] ?? 1)),
            'per_page' => max(1, min(100, (int)($args['per_page'] ?? 24))),
        ];
    }

    private static function mediaUpdate(array $args): array {
        $id = (int)($args['id'] ?? 0);
        if (!Media::get($id)) throw new InvalidArgumentException('No media file with that id.');
        $meta = array_intersect_key($args, array_flip(['alt_text', 'title', 'description']));
        foreach ($meta as $k => $v) {
            if (!is_string($v)) throw new InvalidArgumentException("$k must be a string.");
            $meta[$k] = mb_substr($v, 0, $k === 'description' ? 2000 : 255);
        }
        if ($meta !== []) {
            $r = Media::updateMeta($id, $meta);
            if (empty($r['ok'])) throw new RuntimeException((string)($r['error'] ?? 'Could not save.'));
        }
        if (array_key_exists('tags', $args)) {
            Media::setTags($id, array_map('strval', (array)$args['tags']));
        }
        if (array_key_exists('folder_id', $args)) {
            $f = (int)($args['folder_id'] ?? 0);
            if ($f > 0 && !Media::folderExists($f)) throw new InvalidArgumentException('No folder with that id.');
            Media::moveToFolder([$id], $f > 0 ? $f : null);
        }
        if ($meta === [] && !array_key_exists('tags', $args) && !array_key_exists('folder_id', $args)) {
            throw new InvalidArgumentException('Give at least one of alt_text, title, description, tags or folder_id.');
        }
        return ['ok' => true, 'item' => Media::get($id)];
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
