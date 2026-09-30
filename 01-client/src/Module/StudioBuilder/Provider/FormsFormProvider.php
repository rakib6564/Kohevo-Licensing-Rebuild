<?php
/**
 * Kohevo Studio (studio-builder) — `forms.form` Data Provider.
 *
 * Inspection finding: `FormsAPI` has no "list all forms" catalogue method —
 * only `getForm(string $slug)` / `getFormById(int $id)`, single-item lookups.
 * Rather than inventing a listing capability that does not exist, this
 * provider is a single, parameterized lookup by `slug` (key is `forms.form`,
 * singular — not the plural `forms.forms` used only as an illustrative
 * example in the Phase 3 brief).
 *
 * `FormsAPI::getForm()` does not filter by status, so this provider adds that
 * business rule itself: only a `status = 'published'` form is ever returned —
 * a draft or archived form definition must never be embeddable in an
 * authored page. Only catalogue metadata is exposed, never `fields_json`
 * internals (validation formulas, webhook config, etc.).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Tenancy\TenantContext;

final class FormsFormProvider implements PublicDataProviderInterface
{
    public function key(): string
    {
        return 'forms.form';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'slug', 'type' => 'string', 'label' => 'Form slug', 'required' => true, 'max_length' => 80],
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

        $slug = (string) ($params['slug'] ?? '');
        if ($slug === '') {
            return [];
        }

        $form = \FormsAPI::getForm($slug);
        if ($form === null || ($form['status'] ?? null) !== 'published') {
            return [];
        }

        return [[
            'id'           => (int) $form['id'],
            'slug'         => (string) $form['slug'],
            'title'        => (string) $form['title'],
            'description'  => (string) ($form['description'] ?? ''),
            'submit_label' => (string) ($form['submit_label'] ?? 'Submit'),
        ]];
    }
}
