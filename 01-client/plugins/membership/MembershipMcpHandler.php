<?php
/**
 * Membership — MCP AI Gateway integration.
 *
 * Exposes plan/member read access and plan assignment to the MCP gateway,
 * following plugins/booking/BookingMcpHandler.php's shape exactly. Assigning
 * a plan reuses MembershipAPI::manualActivate() — the same call the admin
 * "Manual / offline activation" form and the admin/add-member.php page use —
 * so this has no separate code path to drift from what a staff member can
 * already do by hand.
 */

declare(strict_types=1);

class MembershipMcpHandler {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['membership.read']  = 'View membership plans, members, and subscriptions';
        $scopes['membership.write'] = 'Assign a plan to a member or cancel a subscription';
        $scopes['membership.manage_plans'] = 'Create or edit membership plans (no delete)';
        return $scopes;
    }

    private static function has(array $context, string $scope): bool {
        return in_array($scope, (array)($context['scopes'] ?? []), true);
    }

    public static function filterTools(array $tools, array $context): array {
        if (self::has($context, 'membership.read')) {
            $tools[] = [
                'name' => 'slate_membership_list_plans',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'List membership plans (base membership, insurance, course).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'active_only' => ['type' => 'boolean', 'description' => 'Default true'],
                ], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_membership_list_members',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'List members (customers) with their current plan/subscription status.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Match against name or email'],
                    'limit'  => ['type' => 'integer', 'description' => 'Default 100, max 200'],
                ], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_membership_member_status',
                'classification' => ['access' => 'read', 'requires_confirmation' => false],
                'description' => 'Detailed membership status for one customer: active subscription, days left, insurance, profile completeness.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'customer_id' => ['type' => 'integer'],
                ], 'required' => ['customer_id'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'membership.write')) {
            $tools[] = [
                'name' => 'slate_membership_assign_plan',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Manually assign a plan to an existing customer (offline/cash-equivalent activation). Same effect as the admin "Manual activation" form.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'customer_id'   => ['type' => 'integer'],
                    'plan_id'       => ['type' => 'integer'],
                    'note'          => ['type' => 'string'],
                    'add_insurance' => ['type' => 'boolean'],
                ], 'required' => ['customer_id', 'plan_id'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_membership_cancel_subscription',
                'classification' => ['access' => 'destructive', 'requires_confirmation' => true],
                'description' => 'Cancel a subscription by id.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'subscription_id' => ['type' => 'integer'],
                ], 'required' => ['subscription_id'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'membership.manage_plans')) {
            $tools[] = [
                'name' => 'slate_membership_upsert_plan',
                'classification' => ['access' => 'write', 'requires_confirmation' => true],
                'description' => 'Create a new membership plan, or update an existing one when id is given. No delete — deactivate with is_active=false instead.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id'               => ['type' => 'integer', 'description' => 'Omit to create a new plan; provide to update an existing one.'],
                    'name'             => ['type' => 'string', 'description' => 'Plan name (English/primary).'],
                    'name_fr'          => ['type' => 'string', 'description' => 'French name, optional.'],
                    'description'      => ['type' => 'string'],
                    'description_fr'   => ['type' => 'string'],
                    'plan_type'        => ['type' => 'string', 'enum' => ['membership', 'insurance', 'course'], 'description' => 'Default membership.'],
                    'course_id'        => ['type' => 'integer', 'description' => 'Booking service id this plan gates access to. Only used when plan_type=course.'],
                    'price'            => ['type' => 'number', 'description' => 'Price in major currency units, e.g. 49.99.'],
                    'currency'         => ['type' => 'string', 'description' => 'Defaults to the tenant\'s global currency setting.'],
                    'duration_days'    => ['type' => 'integer', 'description' => 'Default 365.'],
                    'session_quota'    => ['type' => 'integer', 'description' => '0 = unlimited sessions.'],
                    'grace_days'       => ['type' => 'integer'],
                    'insurance_mode'   => ['type' => 'string', 'enum' => ['none', 'optional', 'required']],
                    'is_active'        => ['type' => 'boolean', 'description' => 'Default true.'],
                    'sort_order'       => ['type' => 'integer'],
                ], 'required' => ['name'], 'additionalProperties' => false],
            ];
        }
        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;

        // Module Guard (07 §2, "Service/internal call" — defense-in-depth
        // against a caller that reaches the service directly): the MCP
        // gateway is an API surface like any other, and scope-based auth
        // (membership.read/membership.write/...) is an independent axis
        // from license entitlement — a valid scope must never substitute
        // for it (07 §6).
        if (str_starts_with($name, 'slate_membership_') && !ModuleGuard::allows('membership')) {
            throw new RuntimeException('This module is not included in your current license.');
        }

        if ($name === 'slate_membership_list_plans') {
            self::requireScope($context, 'membership.read');
            $activeOnly = !array_key_exists('active_only', $args) || !empty($args['active_only']);
            return ['plans' => MembershipAPI::plans($activeOnly)];
        }

        if ($name === 'slate_membership_list_members') {
            self::requireScope($context, 'membership.read');
            $tid    = current_tenant_id();
            $search = trim((string)($args['search'] ?? ''));
            $limit  = max(1, min(200, (int)($args['limit'] ?? 100)));
            $where  = 'c.tenant_id = ?';
            $params = [$tid];
            if ($search !== '') {
                $where .= ' AND (c.name LIKE ? OR c.email LIKE ?)';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }
            $rows = Database::rows(
                "SELECT c.id, c.name, c.email, mp.onboarding_complete,
                        s.id AS sub_id, s.status AS sub_status, s.expires_at,
                        pl.name AS plan_name, pl.plan_type
                   FROM customers c
                   LEFT JOIN membership_profiles mp ON mp.customer_id = c.id AND mp.tenant_id = c.tenant_id
                   LEFT JOIN membership_subscriptions s ON s.id = (
                        SELECT s2.id FROM membership_subscriptions s2
                         WHERE s2.customer_id = c.id AND s2.tenant_id = c.tenant_id AND s2.status = 'active'
                           AND (s2.expires_at IS NULL OR COALESCE(s2.grace_until, s2.expires_at) >= NOW())
                      ORDER BY s2.expires_at DESC, s2.id DESC LIMIT 1)
                   LEFT JOIN membership_plans pl ON pl.id = s.plan_id
                  WHERE $where
               ORDER BY c.id DESC LIMIT $limit",
                $params
            );
            return ['members' => $rows];
        }

        if ($name === 'slate_membership_member_status') {
            self::requireScope($context, 'membership.read');
            $cid = (int)($args['customer_id'] ?? 0);
            if ($cid <= 0) throw new InvalidArgumentException('customer_id is required.');
            return ['status' => MembershipAPI::status($cid), 'profile' => MembershipAPI::profile($cid)];
        }

        if ($name === 'slate_membership_assign_plan') {
            self::requireScope($context, 'membership.write');
            $cid    = (int)($args['customer_id'] ?? 0);
            $planId = (int)($args['plan_id'] ?? 0);
            if ($cid <= 0 || $planId <= 0) throw new InvalidArgumentException('customer_id and plan_id are required.');
            $customer = Database::row('SELECT id FROM customers WHERE id = ? AND tenant_id = ?', [$cid, current_tenant_id()]);
            if (!$customer) throw new InvalidArgumentException('Customer not found.');
            $subId = MembershipAPI::manualActivate($cid, $planId, (string)($args['note'] ?? ''), !empty($args['add_insurance']));
            if (!$subId) throw new RuntimeException('Could not activate — check the plan.');
            return ['ok' => true, 'subscription_id' => $subId];
        }

        if ($name === 'slate_membership_cancel_subscription') {
            self::requireScope($context, 'membership.write');
            $subId = (int)($args['subscription_id'] ?? 0);
            if ($subId <= 0) throw new InvalidArgumentException('subscription_id is required.');
            $sub = MembershipAPI::subscription($subId);
            if (!$sub) throw new InvalidArgumentException('Subscription not found.');
            $ok = MembershipAPI::cancelSubscription($subId, false);
            return ['ok' => $ok];
        }

        if ($name === 'slate_membership_upsert_plan') {
            self::requireScope($context, 'membership.manage_plans');
            return self::upsertPlan($args);
        }

        return null;
    }

    /** Same field validation membership/admin/plans.php's "save" action applies. */
    private static function upsertPlan(array $args): array {
        $tid = current_tenant_id();
        $id  = (int)($args['id'] ?? 0);
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('name is required.');

        $type = (string)($args['plan_type'] ?? 'membership');
        if (!array_key_exists($type, MembershipAPI::planTypes())) $type = 'membership';
        $courseId = ($type === 'course' && !empty($args['course_id'])) ? (int)$args['course_id'] : null;
        $insMode  = (string)($args['insurance_mode'] ?? 'none');
        if (!array_key_exists($insMode, MembershipAPI::insuranceModes())) $insMode = 'none';

        if ($id > 0) {
            $existing = Database::row('SELECT id FROM membership_plans WHERE id = ? AND tenant_id = ?', [$id, $tid]);
            if (!$existing) throw new InvalidArgumentException('Plan not found.');
        }

        $row = [
            'tenant_id'          => $tid,
            'name'               => mb_substr($name, 0, 160),
            'name_fr'            => trim((string)($args['name_fr'] ?? '')) !== '' ? mb_substr(trim((string)$args['name_fr']), 0, 160) : null,
            'description'        => trim((string)($args['description'] ?? '')) !== '' ? (string)$args['description'] : null,
            'description_fr'     => trim((string)($args['description_fr'] ?? '')) !== '' ? (string)$args['description_fr'] : null,
            'plan_type'          => $type,
            'course_id'          => $courseId,
            'price_cents'        => max(0, (int) round(((float)($args['price'] ?? 0)) * 100)),
            'currency'           => strtoupper(mb_substr(trim((string)($args['currency'] ?? MembershipAPI::currency())) ?: 'USD', 0, 8)),
            'duration_days'      => max(1, (int)($args['duration_days'] ?? 365)),
            'session_quota'      => max(0, (int)($args['session_quota'] ?? 0)),
            'grace_days'         => max(0, (int)($args['grace_days'] ?? 0)),
            'insurance_mode'     => $insMode,
            'requires_insurance' => $insMode === 'required' ? 1 : 0,
            'is_active'          => array_key_exists('is_active', $args) ? (!empty($args['is_active']) ? 1 : 0) : 1,
            'sort_order'         => (int)($args['sort_order'] ?? 0),
        ];

        if ($id > 0) {
            Database::update('membership_plans', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
            AuditLog::record('membership.plan_updated', (string)$id, ['via' => 'mcp']);
        } else {
            $id = Database::insert('membership_plans', $row);
            AuditLog::record('membership.plan_created', (string)$id, ['via' => 'mcp']);
        }

        return ['ok' => true, 'plan_id' => $id, 'plan' => MembershipAPI::plan($id)];
    }

    private static function requireScope(array $context, string $scope): void {
        if (!in_array($scope, (array)($context['scopes'] ?? []), true)) {
            throw new RuntimeException("This token does not grant the \"$scope\" scope.");
        }
    }
}
