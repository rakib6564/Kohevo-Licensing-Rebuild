<?php
/**
 * Membership — `membership.plan` Studio data provider: one sellable plan of this tenant, picked by id.
 *
 * Thin adapter over `\MembershipAPI::sellablePlans()` (active, tenant-scoped, insurance left out
 * because it is only ever an add-on). A plan that is inactive, gone, insurance or another tenant's
 * answers nothing.
 */

declare(strict_types=1);

namespace Slate\Plugin\Membership\Studio;

use Slate\Module\StudioBuilder\Provider\ParamChoicesProviderInterface;
use Slate\Module\StudioBuilder\Provider\PublicDataProviderInterface;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class MembershipPlanProvider implements PublicDataProviderInterface, ParamChoicesProviderInterface
{
    public function key(): string
    {
        return 'membership.plan';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'id', 'type' => 'number', 'label' => 'Plan', 'required' => false, 'integer_only' => true, 'min' => 1],
        ]);
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
        return 1;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id < 1 || !class_exists('MembershipAPI')) {
            return [];
        }
        foreach (\MembershipAPI::sellablePlans() as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) !== $id) {
                continue;
            }
            if (isset($row['tenant_id']) && (int) $row['tenant_id'] !== $tenants->id()) {
                return [];
            }
            return [[
                'id'            => $id,
                'name'          => \MembershipAPI::planName($row),
                'description'   => \MembershipAPI::planDescription($row),
                'price_cents'   => (int) ($row['price_cents'] ?? 0),
                'currency'      => (string) ($row['currency'] ?? 'USD'),
                'duration_days' => (int) ($row['duration_days'] ?? 0),
            ]];
        }
        return [];
    }

    public function paramChoices(string $param): array
    {
        if ($param !== 'id' || !class_exists('MembershipAPI')) {
            return [];
        }
        $out = [];
        foreach (\MembershipAPI::sellablePlans() as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $name = \MembershipAPI::planName($row);
            $out[] = ['value' => (int) $row['id'], 'label' => $name !== '' ? $name : '#' . (int) $row['id']];
            if (count($out) >= self::MAX_CHOICES) {
                break;
            }
        }
        return $out;
    }
}
