<?php
/**
 * Kohevo Studio — MCP AI Gateway integration (Phase 7).
 *
 * Registers the Studio scopes and tools on the gateway's extension points
 * (`slate_mcp_scopes` / `slate_mcp_tools` / `slate_mcp_call_tool`), exactly
 * like every other `<Plugin>McpHandler.php`, and hands every call to
 * `Slate\Module\StudioBuilder\Mcp\StudioMcpAdapter`. This file owns only
 * plugin wiring: the module guard (a valid scope never substitutes for the
 * Studio entitlement — 07 §6), the Studio-aware rate limits, and the
 * production closures the adapter needs (session actor, issuer authority,
 * preview URL, logger). It never touches a Studio table, repository or
 * service, and it registers NO publish tool — publishing stays a human
 * action in the Builder against the exact reviewed revision.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Http\StudioApiRateLimiter;
use Slate\Module\StudioBuilder\Mcp\IssuerAuthority;
use Slate\Module\StudioBuilder\Mcp\StudioMcpAdapter;
use Slate\Module\StudioBuilder\Mcp\StudioMcpScopes;
use Slate\Module\StudioBuilder\Mcp\StudioMcpToolCatalog;
use Slate\Module\StudioBuilder\Mcp\StudioMcpToolException;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

class StudioBuilderMcpHandler
{
    /** Gateway settings keys (per-token, per-minute) for the two costlier Studio classes; reads use the gateway's own limit. */
    public const SETTING_WRITE_PER_MIN  = 'mcp-gateway.studio_rate_limit_write_per_min';
    public const SETTING_RENDER_PER_MIN = 'mcp-gateway.studio_rate_limit_render_per_min';
    /** Defaults derived from the gateway's own 60/min baseline: half for draft writes, a quarter for renders/diffs. */
    public const DEFAULT_WRITE_PER_MIN  = 30;
    public const DEFAULT_RENDER_PER_MIN = 15;

    private static ?StudioMcpAdapter $adapter = null;
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return; // idempotent: booted plugins and test harnesses may both call this
        }
        self::$registered = true;
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    /** @param array<string, string> $scopes @return array<string, string> */
    public static function filterScopes(array $scopes): array
    {
        foreach (StudioMcpScopes::LABELS as $scope => $label) {
            $scopes[$scope] = $label;
        }
        return $scopes;
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>       $context
     * @return list<array<string, mixed>>
     */
    public static function filterTools(array $tools, array $context): array
    {
        try {
            foreach (self::adapter()->tools($context) as $tool) {
                $tools[] = $tool;
            }
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('Studio MCP tool listing failed: ' . get_class($e), 'error');
            }
        }
        return $tools;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $context
     */
    public static function callTool($result, string $name, array $args, array $context): mixed
    {
        if ($result !== null || !StudioMcpAdapter::handles($name)) {
            return $result;
        }
        // Module Guard first (07 §2 "service/internal call"): scope and
        // entitlement are independent axes, and the application layer will
        // check the entitlement again per tenant.
        if (!ModuleGuard::allows(StudioBuilder::ENTITLEMENT)) {
            throw new RuntimeException('This module is not included in your current license.');
        }
        return self::adapter()->call($name, $args, $context);
    }

    public static function adapter(): StudioMcpAdapter
    {
        if (self::$adapter === null) {
            self::$adapter = new StudioMcpAdapter(
                StudioRuntimeFactory::build()->app,
                static fn(): StudioActor => StudioActor::fromCurrentSession(),
                static fn(int $userId, int $tenantId): array => IssuerAuthority::studioPermissionsForUser($userId, $tenantId),
                static fn(): int => current_tenant_id(),
                static fn(int $pageId, int $revisionId): string => plugin_url('studio-builder', 'admin/preview.php') . '?page=' . $pageId . '&revision=' . $revisionId,
                static function (string $class, array $context): void { self::enforceRateLimit($class, $context); },
                static function (string $message): void {
                    if (function_exists('slate_log')) {
                        slate_log($message, 'error');
                    }
                },
            );
        }
        return self::$adapter;
    }

    /** Tests may swap the adapter (e.g. to inject a fake session actor); null restores production wiring. */
    public static function useAdapter(?StudioMcpAdapter $adapter): void
    {
        self::$adapter = $adapter;
    }

    /**
     * Studio-aware limits on top of the gateway's generic per-token limit:
     * an external token gets a separate per-minute budget for draft writes
     * and for renders/diffs (counted in the gateway's own action log, on the
     * database clock); the admin assistant — a web session — uses the
     * builder's session limiter, which an external client can never reach.
     *
     * @param array<string, mixed> $context
     */
    public static function enforceRateLimit(string $class, array $context): void
    {
        $origin = (string) ($context['origin'] ?? '');
        if ($origin === StudioActor::ORIGIN_MCP_TOKEN) {
            if ($class === StudioMcpToolCatalog::RATE_READ || !class_exists('McpGatewayAPI')) {
                return; // reads: the gateway's generic limit is the budget
            }
            [$setting, $default] = $class === StudioMcpToolCatalog::RATE_RENDER
                ? [self::SETTING_RENDER_PER_MIN, self::DEFAULT_RENDER_PER_MIN]
                : [self::SETTING_WRITE_PER_MIN, self::DEFAULT_WRITE_PER_MIN];
            if (!McpGatewayAPI::withinClassRateLimit($context, 'studio.' . $class, $setting, $default)) {
                throw new StudioMcpToolException('rate_limited', 429, ['class' => $class]);
            }
            return;
        }
        if ($origin === StudioActor::ORIGIN_ADMIN_ASSISTANT) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                return; // no session storage (CLI probe) — the gateway's own guards still apply
            }
            if (!isset($_SESSION['studio_assistant_rate'])) {
                $_SESSION['studio_assistant_rate'] = null;
            }
            $method = $class === StudioMcpToolCatalog::RATE_READ ? 'GET' : 'POST';
            if (!StudioApiRateLimiter::system()->hit($_SESSION['studio_assistant_rate'], $method)) {
                throw new StudioMcpToolException('rate_limited', 429, ['class' => $class]);
            }
        }
    }
}
