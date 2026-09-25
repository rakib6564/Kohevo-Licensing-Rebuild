<?php
/**
 * MCP Gateway — token issuance, JSON-RPC/MCP dispatch, and the
 * multi-layer restriction that keeps password changes, role deletion,
 * user deletion, live payment/email secrets, and self-deactivation
 * permanently out of reach of any token, no matter how it's scoped.
 *
 * See README.md for the full boundary rationale — this file implements
 * it, that file explains why. Do not add a tool here (or in any
 * <Plugin>McpHandler.php that hooks slate_mcp_tools/slate_mcp_call_tool)
 * that performs any of those five actions: that is Layer 1 of the
 * restriction. isBlocked() below is Layer 2, a backstop that refuses a
 * matching tool NAME even if Layer 1 is ever missed by mistake.
 */

declare(strict_types=1);

use Slate\Kernel\Http\ApiRouter;

class McpGatewayAPI {
    private const SCHEMA_V = '1';
    private static bool $schemaChecked = false;

    // ── Schema ──────────────────────────────────────────────────

    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            if (Database::setting('mcp-gateway.schema_v') === self::SCHEMA_V) return;
            $sql = trim((string)@file_get_contents(__DIR__ . '/install.sql'));
            if ($sql === '') return;
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
                if (trim($statement) !== '') Database::query(trim($statement));
            }
            Database::setSetting('mcp-gateway.schema_v', self::SCHEMA_V);
        } catch (Throwable $e) {
            if (function_exists('slate_log')) slate_log('MCP Gateway schema failed: ' . $e->getMessage(), 'warning');
        }
    }

    // ── Scopes ──────────────────────────────────────────────────

    /** Base scope catalog. Other plugins extend this via slate_mcp_scopes. */
    public static function availableScopes(): array {
        $scopes = [];
        return Hook::applyFilters('slate_mcp_scopes', $scopes);
    }

    // ── Tokens ──────────────────────────────────────────────────

    public static function createToken(string $label, array $scopes, string $expiresAt = ''): array {
        self::ensureSchema();
        $label = mb_substr(trim($label), 0, 120);
        if ($label === '') throw new InvalidArgumentException('A token label is required.');
        $available = self::availableScopes();
        $allowedScopes = array_is_list($available) ? $available : array_keys($available);
        $scopes = array_values(array_intersect($allowedScopes, array_unique(array_map('strval', $scopes))));
        if (!$scopes) throw new InvalidArgumentException('Select at least one scope.');
        $expires = null;
        if (trim($expiresAt) !== '') {
            $time = strtotime($expiresAt . ' 23:59:59');
            if (!$time || $time <= time()) throw new InvalidArgumentException('Expiry must be a future date.');
            $expires = gmdate('Y-m-d H:i:s', $time);
        }
        $raw = 'mcpg_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefix = substr($raw, 0, 16);
        $id = Database::insert('mcp_tokens', [
            'tenant_id' => current_tenant_id(), 'label' => $label, 'token_prefix' => $prefix,
            'token_hash' => hash('sha256', $raw), 'scopes_json' => json_encode($scopes),
            'created_by' => (int)Auth::userId(), 'expires_at' => $expires,
        ]);
        AuditLog::record('mcp-gateway.token_created', 'token#' . $id, ['scopes' => $scopes]);
        return ['id' => $id, 'token' => $raw, 'prefix' => $prefix, 'scopes' => $scopes, 'expires_at' => $expires];
    }

    public static function listTokens(): array {
        self::ensureSchema();
        $rows = Database::rows(
            'SELECT id, label, token_prefix, scopes_json, created_by, last_used_at, expires_at, revoked_at, created_at
               FROM mcp_tokens WHERE tenant_id = ? ORDER BY id DESC',
            [current_tenant_id()]
        );
        foreach ($rows as &$row) $row['scopes'] = self::decode((string)$row['scopes_json']);
        return $rows;
    }

    public static function revokeToken(int $id): void {
        self::ensureSchema();
        Database::update('mcp_tokens', ['revoked_at' => slate_db_now()], 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
        AuditLog::record('mcp-gateway.token_revoked', 'token#' . $id);
    }

    public static function authenticate(string $rawToken): ?array {
        self::ensureSchema();
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) > 256) return null;
        // anti-drift-ignore: TENANT — token_hash is globally unique (UNIQUE KEY
        // mcp_tokens_hash); this lookup is how tenant_id is DISCOVERED for the
        // request, the same way a login flow finds a user by email before a
        // tenant/session context exists to scope by.
        $row = Database::row(
            'SELECT * FROM mcp_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
            [hash('sha256', $rawToken)]
        );
        if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $rawToken))) return null;
        Database::update('mcp_tokens', ['last_used_at' => slate_db_now()], 'id = ?', [(int)$row['id']]);
        return ['tenant_id' => (int)$row['tenant_id'], 'scopes' => self::decode((string)$row['scopes_json']), 'token_id' => (int)$row['id']];
    }

    // ── /api/v1/mcp entry point ─────────────────────────────────

    public static function handleApiRoute(string $subPath, string $method, array $auth): void {
        if (!defined('MCP_GATEWAY_ENABLED') || !MCP_GATEWAY_ENABLED) {
            ApiRouter::respondError('MCP gateway is disabled on this install.', 'DISABLED', 503);
            return;
        }
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if (empty($auth['authenticated']) || ($auth['type'] ?? '') !== 'mcp_token') {
            ApiRouter::respondError('Unauthorized or expired MCP token.', 'UNAUTHORIZED', 401);
            return;
        }

        $context = [
            'tenant_id' => (int)$auth['tenant_id'],
            'scopes'    => (array)($auth['scopes'] ?? []),
            'token_id'  => (int)($auth['token_id'] ?? 0),
        ];
        $payload  = json_decode((string)file_get_contents('php://input'), true);
        $response = self::handleRpc(is_array($payload) ? $payload : [], $context);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── MCP JSON-RPC ─────────────────────────────────────────────

    private static function handleRpc(array $request, array $context): array {
        $id = $request['id'] ?? null;
        if (($request['jsonrpc'] ?? '') !== '2.0' || !is_string($request['method'] ?? null)) {
            return self::rpcError($id, -32600, 'Invalid JSON-RPC request.');
        }
        try {
            $method = $request['method'];
            if ($method === 'initialize') {
                return self::rpcResult($id, [
                    'protocolVersion' => '2025-03-26',
                    'capabilities'    => ['tools' => (object)[]],
                    'serverInfo'      => ['name' => 'slate-mcp-gateway', 'version' => '1.0.0'],
                ]);
            }
            if ($method === 'notifications/initialized') return self::rpcResult($id, (object)[]);
            if ($method === 'tools/list') return self::rpcResult($id, ['tools' => self::tools($context)]);
            if ($method !== 'tools/call') return self::rpcError($id, -32601, 'Method not found.');

            $params    = is_array($request['params'] ?? null) ? $request['params'] : [];
            $name      = (string)($params['name'] ?? '');
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $result    = with_tenant($context['tenant_id'], static fn() => self::dispatch($context, $name, $arguments));

            return self::rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'isError' => false,
            ]);
        } catch (Throwable $e) {
            return self::rpcResult($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        }
    }

    private static function tools(array $context): array {
        $tools = [
            [
                'name'        => 'mcp_ping',
                'description' => 'Health check — confirms the gateway, auth, rate-limit, and audit pipeline are working end to end.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ],
        ];
        return Hook::applyFilters('slate_mcp_tools', $tools, $context);
    }

    // ── In-app AI assistant support (admin chat box) ──────────────────────
    // The admin chat runs as the signed-in admin, not an external token —
    // it gets the full tool set (every scope any active plugin registers)
    // rather than whatever a specific token was scoped to.

    /** All scope keys any active plugin has registered. */
    public static function allScopeKeys(): array {
        $scopes = self::availableScopes();
        return array_is_list($scopes) ? $scopes : array_keys($scopes);
    }

    /** The full MCP tool catalog, as if called by a token holding every scope. */
    public static function toolsForAdmin(): array {
        return self::tools(['tenant_id' => current_tenant_id(), 'scopes' => self::allScopeKeys(), 'token_id' => 0]);
    }

    /**
     * Execute one tool call as the signed-in admin (full scopes, current
     * tenant), reusing the exact same audited dispatch path a real MCP
     * token call goes through — same denylist, same rate limit, same audit
     * log entries (mcp-gateway.<name>), so admin-chat actions are just as
     * visible in Audit Log as an external AI agent's would be.
     */
    public static function runAsAdmin(string $name, array $args): array {
        $context = ['tenant_id' => current_tenant_id(), 'scopes' => self::allScopeKeys(), 'token_id' => 0];
        $result  = self::dispatch($context, $name, $args);
        return is_array($result) ? $result : ['result' => $result];
    }

    /**
     * Every tool call, allowed or not, passes through here. Order matters:
     * the denylist guard runs before the tool is even looked up, so it
     * catches a bad tool name regardless of what registered it or whether
     * it "exists" yet.
     */
    private static function dispatch(array $context, string $name, array $args): mixed {
        if (self::isBlocked($name)) {
            AuditLog::record('mcp-gateway.blocked_attempt', $name, ['token_id' => $context['token_id']]);
            throw new RuntimeException(
                'This action is permanently restricted and cannot be performed through the MCP gateway ' .
                '(password changes, role/user deletion, live payment or email secret keys, and disabling ' .
                'the gateway itself are never exposed here — see plugins/mcp-gateway/README.md).'
            );
        }

        self::enforceRateLimit($context);

        // Every attempt is audited, success or failure — including a
        // content-based refusal (e.g. slate_settings_set on a secret-looking
        // key) that isn't caught by the name-based Layer 2 guard above. Log,
        // then re-throw so the JSON-RPC error path still fires.
        try {
            if ($name === 'mcp_ping') {
                $result = ['ok' => true, 'time' => slate_db_now()];
            } else {
                // Strict: a handler's thrown validation/not-found error must
                // reach the caller as-is, not get swallowed into a generic
                // "not available" message. See Hook::applyFiltersStrict().
                $handled = Hook::applyFiltersStrict('slate_mcp_call_tool', null, $name, $args, $context);
                if ($handled === null) throw new InvalidArgumentException('The requested tool is not available for this token.');
                $result = is_array($handled) ? $handled : ['result' => $handled];
            }
        } catch (Throwable $e) {
            AuditLog::record('mcp-gateway.' . $name . '.failed', '', [
                'token_id' => $context['token_id'], 'args' => self::redact($args), 'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        AuditLog::record('mcp-gateway.' . $name, '', ['token_id' => $context['token_id'], 'args' => self::redact($args)]);
        return $result;
    }

    /**
     * Layer 2 of the restriction (see file docblock). Matches on the tool
     * NAME, deliberately broad — it is meant to catch a careless future
     * tool before it ships, not to be a precise allowlist. Layer 1 (never
     * registering such a tool in the first place) is what actually keeps
     * these off the menu; this is the backstop.
     */
    private static function isBlocked(string $name): bool {
        return (bool) preg_match(
            '/password|reset_password|role.*delete|delete.*role|user.*delete|delete.*user|' .
            'stripe.*secret|smtp.*pass|mcp[-_]?gateway.*(deactivate|disable)/i',
            $name
        );
    }

    private static function enforceRateLimit(array $context): void {
        self::ensureSchema();
        $tokenId = (int)$context['token_id'];
        $limit   = (int)(Database::setting('mcp-gateway.rate_limit_per_min') ?: 60);
        if ($limit <= 0 || $tokenId <= 0) return;

        Database::insert('mcp_action_log', [
            'tenant_id'    => $context['tenant_id'],
            'token_id'     => $tokenId,
            'action'       => 'call',
            'attempted_at' => slate_db_now(),
        ]);
        // Opportunistic prune — cheap on an indexed column, keeps the table
        // from growing unbounded without needing a separate cron job.
        // anti-drift-ignore: TENANT — rows here are just rate-limit counters,
        // nothing confidential; pruning globally (not per-tenant) is deliberate
        // so an idle tenant's stale rows don't linger forever either.
        Database::query("DELETE FROM mcp_action_log WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)");

        // Window arithmetic stays in SQL (TIMESTAMPDIFF against the DB's own
        // clock), not PHP time() — the same clock-skew lesson Auth's login
        // throttle already learned the hard way (see src/Services/Auth/Auth.php).
        // anti-drift-ignore: TENANT — scoped by token_id, which already belongs
        // to exactly one tenant (minted per-tenant in mcp_tokens); adding
        // tenant_id here would be redundant, not a safety gap.
        $count = (int) Database::value(
            "SELECT COUNT(*) FROM mcp_action_log WHERE token_id = ? AND TIMESTAMPDIFF(SECOND, attempted_at, UTC_TIMESTAMP()) <= 60",
            [$tokenId]
        );
        if ($count > $limit) {
            throw new RuntimeException('Rate limit exceeded — too many MCP actions in the last minute. Try again shortly.');
        }
    }

    /** Never write raw password/secret/token-shaped argument values to the audit log. */
    private static function redact(array $args): array {
        // A generic {key, value} shape (e.g. slate_settings_set) where the
        // key NAME looks sensitive means the sibling value is a secret too,
        // even though the "value" key itself doesn't look sensitive.
        $siblingValueIsSensitive = is_string($args['key'] ?? null) && preg_match('/password|secret/i', $args['key']);
        $out = [];
        foreach ($args as $k => $v) {
            if (is_string($k) && preg_match('/password|secret|token/i', $k)) { $out[$k] = '[redacted]'; continue; }
            if ($siblingValueIsSensitive && $k === 'value') { $out[$k] = '[redacted]'; continue; }
            $out[$k] = is_scalar($v) || $v === null ? $v : (is_array($v) ? '[array]' : '[object]');
        }
        return $out;
    }

    private static function decode(string $json): array {
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
        catch (Throwable $e) { return []; }
    }

    private static function rpcResult(mixed $id, mixed $result): array { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; }
    private static function rpcError(mixed $id, int $code, string $message): array { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]; }
}
