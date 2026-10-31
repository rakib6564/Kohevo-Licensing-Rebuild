<?php
/**
 * Kohevo Studio — Template Repository (`studiobuilder_templates`).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

final class TemplateRepository extends StudioRepository
{
    protected string $table = 'studiobuilder_templates';

    /**
     * Find a Studio template by template_key within the active tenant scope.
     */
    public function findByKey(string $templateKey): ?array
    {
        return $this->query()
            ->where('template_key', $templateKey)
            ->first();
    }

    /**
     * The tenant's own templates, for a backup. System templates come with the plugin.
     *
     * @return list<array<string, mixed>>
     */
    public function allCustom(): array
    {
        return $this->query()->where('is_system', 0)->orderBy('id', 'ASC')->get();
    }
}
