<?php
/**
 * Kohevo Studio (studio-builder) — Public-audience Data Provider marker.
 *
 * A published page is rendered for ANONYMOUS visitors, who hold no Studio
 * permission at all. A provider may execute in that public render context
 * only if it implements this marker — an explicit, reviewable declaration
 * that everything it returns is already public catalogue data (active
 * services, public plans, published forms): no customer data, no per-visitor
 * state, no admin-only fields.
 *
 * Providers that do NOT implement it still work in the authoring (Editor /
 * Preview) context, gated by the actor's own `requiredPermission()`, but are
 * never executed for a public request.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

interface PublicDataProviderInterface extends DataProviderInterface
{
}
