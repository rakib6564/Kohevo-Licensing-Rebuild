<?php
/**
 * Kohevo Studio (studio-builder) — `membership.plans` Data Provider.
 *
 * Thin adapter over the existing `\MembershipAPI::plans(true)` (already
 * tenant-scoped via `current_tenant_id()` — verified by inspection of
 * `plugins/membership/MembershipAPI.php`). Never touches `membership_plans`
 * directly and never imports `MembershipMcpHandler` (a separate scope-based
 * trust boundary, not a safe substitute for the business service).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Tenancy\TenantContext;

final class MembershipPlansProvider implements PublicDataProviderInterface
{
    public function key(): string
    {
        return 'membership.plans';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([]);
    }

    public function requiredEntitlement(): ?string
    {
        return 'membership';
    }

    public function requiredPermission(): string
    {
        return StudioPermissions::VIEW;
    }

    public function maxResults(): int
    {
        return 100;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        if (!class_exists('MembershipAPI')) {
            return [];
        }

        $rows = \MembershipAPI::plans(true);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $out[] = [
                'id'             => (int) $row['id'],
                'name'           => \MembershipAPI::planName($row),
                'description'    => \MembershipAPI::planDescription($row),
                'plan_type'      => (string) ($row['plan_type'] ?? 'membership'),
                'price_cents'    => (int) ($row['price_cents'] ?? 0),
                'currency'       => (string) ($row['currency'] ?? 'USD'),
                'duration_days'  => (int) ($row['duration_days'] ?? 0),
            ];
        }
        return $out;
    }
}
