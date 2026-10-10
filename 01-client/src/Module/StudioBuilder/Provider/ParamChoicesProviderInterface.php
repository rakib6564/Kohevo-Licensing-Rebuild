<?php
/**
 * Kohevo Studio (studio-builder) — Provider parameter choices.
 *
 * A provider whose parameters are all ids of something the tenant owns (a form,
 * a service) can offer the editor the list to pick from, so the Inspector shows
 * a dropdown instead of asking the author to type an id. The choices ride in the editor
 * manifest next to the parameter schema; they are display data only — the
 * value that is stored is re-checked by the provider on every render, so a
 * stale or forged id never reaches another tenant's data.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

interface ParamChoicesProviderInterface extends DataProviderInterface
{
    public const MAX_CHOICES = 200;

    /**
     * The values an author may pick for one parameter of this provider, for the ACTIVE tenant.
     *
     * @return list<array{value: int, label: string}>
     */
    public function paramChoices(string $param): array;
}
