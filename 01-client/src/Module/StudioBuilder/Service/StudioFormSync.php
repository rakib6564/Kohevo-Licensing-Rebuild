<?php
/**
 * Kohevo Studio — a Studio form block becomes a form of the Forms module when its page is published.
 *
 * Forms owns everything after the visitor presses Submit: validation, CSRF, the spam guard, uploads, storing the
 * submission, the notification email and the webhooks. A `core.form` with no Action URL of its own posts to Forms' route
 * for a form whose slug is derived from the block (`sb-<block id>`); publishing the page writes that form from the block's
 * fields, so the two never drift. Editing the form in the Forms admin is overwritten by the next publish of the page, so
 * the form says it is managed by Studio (its description).
 *
 * A form with its own Action URL is left alone (it posts where the author said). Without the Forms module nothing is
 * synced, and the form says so in the editor.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormFieldRenderer;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class StudioFormSync
{
    /** The Forms slug of a Studio form block. */
    public static function slugFor(string $blockId): string
    {
        $id = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $blockId));
        return 'sb-' . substr($id !== '' ? $id : 'form', 0, 60);
    }

    /** True when Studio forms can post to Forms on this install. */
    public static function available(): bool
    {
        return class_exists('FormsAPI');
    }

    /**
     * Every Studio-managed form in a document (a `core.form` without an Action URL), at any depth.
     *
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    public static function formsIn(array $document): array
    {
        $out = [];
        $walk = static function (array $blocks) use (&$walk, &$out): void {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? '') === 'core.form' && trim((string) ($block['props']['action'] ?? '')) === '') {
                    $out[] = $block;
                    continue;
                }
                $walk(is_array($block['children'] ?? null) ? $block['children'] : []);
            }
        };
        $walk((array) (DocumentUpgrader::structure($document)['containers'] ?? []));
        return $out;
    }

    /**
     * The Forms definition of one Studio form block: its fields (the `core.form_field` blocks inside it, in order) and
     * what happens after a submit.
     *
     * @param array<string, mixed> $form
     * @return array{slug: string, title: string, fields: list<array<string, mixed>>, submit_label: string, success_message: string, redirect_url: ?string, notify_email: ?string}
     */
    public static function definitionFor(array $form, string $pageTitle = ''): array
    {
        $props = is_array($form['props'] ?? null) ? $form['props'] : [];
        $fields = [];
        $walk = static function (array $blocks) use (&$walk, &$fields): void {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? '') === 'core.form_field') {
                    $p = is_array($block['props'] ?? null) ? $block['props'] : [];
                    $type = in_array($p['field_type'] ?? 'text', FormFieldRenderer::FIELD_TYPES, true) ? (string) ($p['field_type'] ?? 'text') : 'text';
                    $field = [
                        'type'     => $type,
                        'name'     => FormFieldRenderer::fieldName((string) ($p['name'] ?? 'field')),
                        'label'    => (string) ($p['label'] ?? ''),
                        'required' => ($p['required'] ?? false) === true,
                    ];
                    if (($p['placeholder'] ?? '') !== '') {
                        $field['placeholder'] = (string) $p['placeholder'];
                    }
                    if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                        $field['options'] = FormFieldRenderer::options((string) ($p['options'] ?? ''));
                    }
                    $fields[] = $field;
                    continue;
                }
                $walk(is_array($block['children'] ?? null) ? $block['children'] : []);
            }
        };
        $walk(is_array($form['children'] ?? null) ? $form['children'] : []);

        $name = trim((string) ($props['form_name'] ?? ''));
        $redirect = trim((string) ($props['redirect_url'] ?? ''));
        $email = trim((string) ($props['notify_email'] ?? ''));
        return [
            'slug'            => self::slugFor((string) ($form['id'] ?? '')),
            'title'           => mb_substr(trim(($pageTitle !== '' ? $pageTitle . ' · ' : '') . ($name !== '' ? str_replace('_', ' ', $name) : 'Form')), 0, 200),
            'fields'          => $fields,
            'submit_label'    => mb_substr(trim((string) ($props['submit_text'] ?? 'Submit')) ?: 'Submit', 0, 80),
            'success_message' => trim((string) ($props['success_message'] ?? '')),
            // Same-site paths and http(s) addresses only; anything else is dropped rather than stored.
            'redirect_url'    => $redirect !== '' && (str_starts_with($redirect, '/') && !str_starts_with($redirect, '//') || FieldSchema::isSafeUrl($redirect) && preg_match('#^https?://#i', $redirect) === 1) ? $redirect : null,
            'notify_email'    => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
        ];
    }

    /**
     * Write every Studio form of a published document into Forms (insert, or update the one with its slug).
     *
     * @param array<string, mixed> $document
     * @return int how many forms were written
     */
    public static function sync(int $tenantId, array $document, string $pageTitle = ''): int
    {
        if (!self::available() || $tenantId <= 0) {
            return 0;
        }
        $forms = self::formsIn($document);
        if ($forms === []) {
            return 0;
        }
        \FormsAPI::ensureSchema();
        $written = 0;
        foreach ($forms as $form) {
            $def = self::definitionFor($form, $pageTitle);
            $fields = \FormsAPI::normalizeFields($def['fields'])['fields'];
            $row = [
                'title'           => $def['title'],
                'description'     => 'Managed by Kohevo Studio: edit this form on its page, then publish the page.',
                'fields_json'     => (string) json_encode($fields, JSON_UNESCAPED_UNICODE),
                'submit_label'    => $def['submit_label'],
                'success_message' => $def['success_message'] !== '' ? $def['success_message'] : null,
                'redirect_url'    => $def['redirect_url'],
                'notify_email'    => $def['notify_email'],
                'status'          => 'published',
            ];
            $id = Database::value('SELECT id FROM forms_definitions WHERE tenant_id = ? AND slug = ?', [$tenantId, $def['slug']]);
            if ($id !== null && $id !== false) {
                Database::update('forms_definitions', $row, 'id = ? AND tenant_id = ?', [(int) $id, $tenantId]);
            } else {
                Database::insert('forms_definitions', $row + ['tenant_id' => $tenantId, 'slug' => $def['slug']]);
            }
            $written++;
        }
        return $written;
    }
}
