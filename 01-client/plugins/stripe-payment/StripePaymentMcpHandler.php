<?php
/**
 * Stripe Payment — MCP AI Gateway integration.
 *
 * READ-ONLY, deliberately. No refund/charge/secret-key tool is exposed here
 * — see plugins/mcp-gateway/README.md. Following
 * plugins/booking/BookingMcpHandler.php's shape.
 */

declare(strict_types=1);

class StripePaymentMcpHandler {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['stripe.read'] = 'View payment charges and webhook health (read-only — no refunds, no key changes)';
        return $scopes;
    }

    private static function has(array $context, string $scope): bool {
        return in_array($scope, (array)($context['scopes'] ?? []), true);
    }

    public static function filterTools(array $tools, array $context): array {
        if (self::has($context, 'stripe.read')) {
            $tools[] = [
                'name' => 'slate_stripe_list_charges',
                'description' => 'List recent payment charges.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string'],
                    'limit'  => ['type' => 'integer', 'description' => 'Default 100'],
                ], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_stripe_get_charge',
                'description' => 'Read one charge by id.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'],
                ], 'required' => ['id'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_stripe_webhook_health',
                'description' => 'Recent Stripe webhook delivery health (last outcomes, failure counts).',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ];
        }
        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;

        if ($name === 'slate_stripe_list_charges') {
            self::requireScope($context, 'stripe.read');
            $filters = [];
            if (!empty($args['status'])) $filters['status'] = (string)$args['status'];
            return ['charges' => StripePaymentAPI::listCharges($filters, max(1, min(500, (int)($args['limit'] ?? 100))))];
        }

        if ($name === 'slate_stripe_get_charge') {
            self::requireScope($context, 'stripe.read');
            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('id is required.');
            $charge = StripePaymentAPI::getCharge($id);
            if (!$charge) throw new InvalidArgumentException('Charge not found.');
            return ['charge' => $charge];
        }

        if ($name === 'slate_stripe_webhook_health') {
            self::requireScope($context, 'stripe.read');
            return ['health' => StripePaymentAPI::webhookHealth()];
        }

        return null;
    }

    private static function requireScope(array $context, string $scope): void {
        if (!in_array($scope, (array)($context['scopes'] ?? []), true)) {
            throw new RuntimeException("This token does not grant the \"$scope\" scope.");
        }
    }
}
