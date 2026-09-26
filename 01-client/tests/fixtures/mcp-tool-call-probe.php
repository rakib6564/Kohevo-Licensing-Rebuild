<?php
/**
 * Execute one MCP AI-gateway tool call (McpGatewayAPI::runAsAdmin(), full
 * scopes) in a CHILD process — same rationale as api-request-probe.php and
 * ModuleGuardTest.php's own probes: a fresh process is required because
 * SLATE_TESTING is a define() that, once set, cannot be un-set for the rest
 * of a PHP process — running this in-process inside the shared integration
 * test runner (which defines SLATE_TESTING globally so every OTHER existing
 * Forms/Membership/Booking suite stays unaffected by this phase) would
 * permanently bypass ModuleGuard for the whole test run, not just this call.
 *
 * Usage: php tests/fixtures/mcp-tool-call-probe.php <toolName> <jsonArgs>
 *
 * Prints:
 *     STATUS OK
 *     <json result>
 * or:
 *     STATUS ERROR
 *     <exception message>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$toolName = (string) ($argv[1] ?? '');
$args     = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];

$root = dirname(__DIR__, 2);

// Phase 6/7 Guard test toggle — see docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md
// §3a and ModuleGuard.php's own docblock. By default this probe bypasses both
// guards exactly like every other non-licensing integration test (SLATE_TESTING,
// D19 LOCKED); McpModuleGuardTest.php sets SLATE_LICENSE_GUARD_LIVE=1 to instead
// exercise the real Module Guard end-to-end, mirroring ModuleGuardTest.php's
// own admin/public/api probes.
if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

// A real MCP tool call always arrives over an HTTP POST to the gateway's own
// API route — set here so ModuleGuard::isBypassed()'s CLI-without-
// REQUEST_METHOD check (meant for genuine console/ops tooling) does not
// itself mask the Module Guard result this probe exists to observe.
$_SERVER['REQUEST_METHOD'] = 'POST';

require $root . '/config.php';
require_once $root . '/plugins/mcp-gateway/McpGatewayAPI.php'; // once: the gateway's own boot loads it when active

try {
    $result = \McpGatewayAPI::runAsAdmin($toolName, $args);
    fwrite(STDOUT, "STATUS OK\n" . json_encode($result));
} catch (\Throwable $e) {
    fwrite(STDOUT, "STATUS ERROR\n" . $e->getMessage());
}
