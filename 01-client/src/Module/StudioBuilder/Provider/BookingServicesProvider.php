<?php
/**
 * Kohevo Studio (studio-builder) — `booking.services` Data Provider.
 *
 * Thin adapter over the existing `\BookingAPI::getActiveServices()` (already
 * tenant-scoped via `current_tenant_id()` — verified by inspection of
 * `plugins/booking/BookingAPI.php`). Never touches `booking_services`
 * directly and never imports `BookingMcpHandler` (a different, scope-based
 * trust boundary that also performs its own direct writes — not a safe
 * substitute for the underlying business service; see the Phase 3 audit).
 *
 * Maps each raw `booking_services` row to a minimal, transport-neutral shape
 * — no internal template/tax/fee columns are exposed.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Tenancy\TenantContext;

final class BookingServicesProvider implements PublicDataProviderInterface
{
    public function key(): string
    {
        return 'booking.services';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([]);
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
        return 100;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        if (!class_exists('BookingAPI')) {
            return [];
        }

        $rows = \BookingAPI::getActiveServices();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $out[] = [
                'id'                => (int) $row['id'],
                'name'              => \BookingAPI::serviceName($row),
                'description'       => \BookingAPI::serviceDescription($row),
                'duration_minutes'  => (int) ($row['duration_min'] ?? 0),
                'price_cents'       => (int) ($row['price_cents'] ?? 0),
                'currency'          => (string) ($row['currency'] ?? 'USD'),
            ];
        }
        return $out;
    }
}
