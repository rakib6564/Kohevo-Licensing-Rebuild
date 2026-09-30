<?php
/**
 * MCP Gateway — Phase 3: business reporting.
 *
 * A single read-only tool that aggregates numbers already sitting in each
 * installed plugin's own tables (bookings, membership, payments, forms) plus
 * the audit log, into one structured report. Returns JSON, not prose or a
 * PDF — the calling agent writes the narrative; this just gets it real
 * numbers to write about.
 *
 * Each section is independently try/caught: a plugin that isn't installed
 * (its table doesn't exist) just yields a null section instead of failing
 * the whole report — the same defensive pattern plugins/membership/admin/plans.php
 * uses when checking whether Booking is installed before offering a link to it.
 */

declare(strict_types=1);

class ReportingMcpTools {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['mcp-gateway.reports.read'] = 'Generate an aggregated business report (bookings, memberships, payments, forms, activity)';
        return $scopes;
    }

    public static function filterTools(array $tools, array $context): array {
        if (in_array('mcp-gateway.reports.read', (array)($context['scopes'] ?? []), true)) {
            $tools[] = [
                'name' => 'slate_business_report',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Aggregated business report over a trailing window: bookings, membership revenue/activations, payments, form submissions, and admin/agent activity. Returns structured data for the caller to summarize.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'period_days' => ['type' => 'integer', 'description' => 'Trailing window in days. Default 30.'],
                ], 'additionalProperties' => false],
            ];
        }
        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;
        if ($name !== 'slate_business_report') return null;

        if (!in_array('mcp-gateway.reports.read', (array)($context['scopes'] ?? []), true)) {
            throw new RuntimeException('This token does not grant the "mcp-gateway.reports.read" scope.');
        }

        $days = max(1, min(365, (int)($args['period_days'] ?? 30)));
        $tid  = current_tenant_id();

        return [
            'period_days' => $days,
            'since'       => gmdate('Y-m-d H:i:s', time() - $days * 86400),
            // Phase 11: a licensed module's data is reported only while that
            // module is entitled — the same null an uninstalled module yields.
            'bookings'    => ModuleGuard::allows('booking') ? self::bookingsSection($tid, $days) : null,
            'membership'  => ModuleGuard::allows('membership') ? self::membershipSection($tid, $days) : null,
            'payments'    => self::paymentsSection($days),
            'forms'       => ModuleGuard::allows('forms') ? self::formsSection($tid, $days) : null,
            'activity'    => self::activitySection($tid, $days),
        ];
    }

    private static function bookingsSection(int $tid, int $days): ?array {
        try {
            $row = Database::row(
                "SELECT COUNT(*) AS total,
                        SUM(status = 'confirmed') AS confirmed,
                        SUM(status = 'cancelled') AS cancelled,
                        SUM(status = 'no_show')   AS no_show,
                        SUM(status = 'completed') AS completed,
                        COALESCE(SUM(paid_cents), 0) AS revenue_cents
                   FROM booking_appointments
                  WHERE tenant_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)",
                [$tid, $days]
            );
            if (!$row) return null;
            return array_map(static fn($v) => $v === null ? 0 : (int)$v, $row);
        } catch (Throwable $e) {
            return null; // Booking not installed
        }
    }

    private static function membershipSection(int $tid, int $days): ?array {
        try {
            $active = (int) Database::value(
                "SELECT COUNT(*) FROM membership_subscriptions
                  WHERE tenant_id = ? AND status = 'active'
                    AND (expires_at IS NULL OR COALESCE(grace_until, expires_at) >= NOW())",
                [$tid]
            );
            $new = Database::row(
                "SELECT COUNT(*) AS new_subscriptions, COALESCE(SUM(amount_cents), 0) AS revenue_cents
                   FROM membership_subscriptions
                  WHERE tenant_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)",
                [$tid, $days]
            );
            return [
                'active_subscriptions'  => $active,
                'new_subscriptions'     => (int)($new['new_subscriptions'] ?? 0),
                'new_revenue_cents'     => (int)($new['revenue_cents'] ?? 0),
            ];
        } catch (Throwable $e) {
            return null; // Membership not installed
        }
    }

    private static function paymentsSection(int $days): ?array {
        try {
            if (!class_exists('StripePaymentAPI')) return null;
            $charges = StripePaymentAPI::listCharges(['days' => $days], 500);
            $succeeded = array_values(array_filter($charges, static fn($c) => ($c['status'] ?? '') === 'succeeded'));
            return [
                'charge_count'     => count($charges),
                'succeeded_count'  => count($succeeded),
                'revenue_cents'    => array_sum(array_map(static fn($c) => (int)($c['amount_cents'] ?? 0), $succeeded)),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function formsSection(int $tid, int $days): ?array {
        try {
            $count = Database::value(
                "SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)",
                [$tid, $days]
            );
            return ['submission_count' => (int)$count];
        } catch (Throwable $e) {
            return null; // Forms not installed
        }
    }

    private static function activitySection(int $tid, int $days): array {
        $rows = Database::rows(
            "SELECT action, COUNT(*) AS n FROM audit_log
              WHERE tenant_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
           GROUP BY action ORDER BY n DESC LIMIT 15",
            [$tid, $days]
        );
        $total = Database::value(
            "SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)",
            [$tid, $days]
        );
        return ['total_actions' => (int)$total, 'top_actions' => $rows];
    }
}
