<?php
/**
 * Kohevo Studio (studio-builder) — `forms.form_embed` Data Provider.
 *
 * Feeds the `forms.embed` block: ONE published form of the active tenant,
 * looked up by id (a slug can be renamed, an id cannot). Thin adapter over the
 * existing `\FormsAPI::getFormById()`, which is already tenant-scoped; the
 * tenant of the returned row is checked again here, and a draft or archived
 * form is never returned, so an authored page cannot expose one.
 *
 * Only what the block needs is exposed: the slug (the Forms module renders the
 * form itself), its title and description, and the visible field labels for the
 * script-less editor preview. Never `fields_json` internals (formulas, webhook
 * configuration, spam settings).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class FormsFormEmbedProvider implements PublicDataProviderInterface, ParamChoicesProviderInterface
{
    private const MAX_LABELS = 12;
    private const HIDDEN_TYPES = ['hidden', 'step', 'heading'];

    public function key(): string
    {
        return 'forms.form_embed';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'id', 'type' => 'number', 'label' => 'Form', 'required' => true, 'integer_only' => true, 'min' => 1],
        ]);
    }

    public function requiredEntitlement(): ?string
    {
        return 'forms';
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
        if (!class_exists('FormsAPI')) {
            return [];
        }
        $id = (int) ($params['id'] ?? 0);
        if ($id < 1) {
            return [];
        }

        $form = \FormsAPI::getFormById($id);
        if ($form === null || ($form['status'] ?? null) !== 'published') {
            return [];
        }
        if (isset($form['tenant_id']) && (int) $form['tenant_id'] !== $tenants->id()) {
            return [];
        }

        return [[
            'id'           => (int) $form['id'],
            'slug'         => (string) $form['slug'],
            'title'        => (string) $form['title'],
            'description'  => (string) ($form['description'] ?? ''),
            'submit_label' => (string) ($form['submit_label'] ?? ''),
            'field_labels' => self::fieldLabels($form['fields'] ?? []),
        ]];
    }

    public function paramChoices(string $param): array
    {
        if ($param !== 'id' || !class_exists('FormsAPI')) {
            return [];
        }
        $out = [];
        foreach (\FormsAPI::publishedChoices() as $row) {
            $out[] = ['value' => (int) $row['id'], 'label' => (string) $row['title']];
            if (count($out) >= self::MAX_CHOICES) {
                break;
            }
        }
        return $out;
    }

    /** The labels of the fields a visitor fills in, one per line (a required field ends with " *"). */
    private static function fieldLabels(mixed $fields): string
    {
        $lines = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (!is_array($field) || in_array($field['type'] ?? '', self::HIDDEN_TYPES, true)) {
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', (string) ($field['label'] ?? '')) ?? '');
            if ($label === '') {
                continue;
            }
            $lines[] = mb_substr($label, 0, 80, 'UTF-8') . (!empty($field['required']) ? ' *' : '');
            if (count($lines) >= self::MAX_LABELS) {
                break;
            }
        }
        return implode("\n", $lines);
    }
}
