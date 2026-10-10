<?php
/**
 * Kohevo Studio (studio-builder) — `booking.embed_target` Data Provider.
 *
 * Feeds the `booking.embed` block with WHAT to embed: one active service of the
 * active tenant (`id` given), or the whole booking page (`id` absent). Thin
 * adapter over the existing, tenant-scoped `\BookingAPI::getService()`; an
 * unknown, foreign or inactive service id returns no row, so the block renders
 * nothing instead of a link to a service the tenant does not offer.
 *
 * The row for the whole page has `id` 0 and `scope` "page" — the renderer needs
 * a row either way to know the binding resolved.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class BookingEmbedTargetProvider implements PublicDataProviderInterface, ParamChoicesProviderInterface
{
    public function key(): string
    {
        return 'booking.embed_target';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'id', 'type' => 'number', 'label' => 'Service (none: your whole booking page)', 'required' => false, 'integer_only' => true, 'min' => 1],
        ]);
    }

    public function requiredEntitlement(): ?string
    {
        return 'booking';
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
        if (!class_exists('BookingAPI')) {
            return [];
        }
        $id = (int) ($params['id'] ?? 0);
        if ($id < 1) {
            return [['id' => 0, 'scope' => 'page', 'name' => '', 'description' => '', 'duration_minutes' => 0, 'price_cents' => 0, 'currency' => '']];
        }

        $service = \BookingAPI::getService($id);
        if (!is_array($service) || empty($service['is_active'])) {
            return [];
        }
        if (isset($service['tenant_id']) && (int) $service['tenant_id'] !== $tenants->id()) {
            return [];
        }

        return [[
            'id'               => (int) $service['id'],
            'scope'            => 'service',
            'name'             => \BookingAPI::serviceName($service),
            'description'      => \BookingAPI::serviceDescription($service),
            'duration_minutes' => (int) ($service['duration_min'] ?? 0),
            'price_cents'      => (int) ($service['price_cents'] ?? 0),
            'currency'         => (string) ($service['currency'] ?? 'USD'),
        ]];
    }

    public function paramChoices(string $param): array
    {
        if ($param !== 'id' || !class_exists('BookingAPI')) {
            return [];
        }
        $out = [];
        foreach (\BookingAPI::getActiveServices() as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $name = \BookingAPI::serviceName($row);
            $out[] = ['value' => (int) $row['id'], 'label' => $name !== '' ? $name : '#' . (int) $row['id']];
            if (count($out) >= self::MAX_CHOICES) {
                break;
            }
        }
        return $out;
    }
}
