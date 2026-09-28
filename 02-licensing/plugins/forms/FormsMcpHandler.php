<?php
/**
 * Forms — MCP AI Gateway integration.
 *
 * Read-only: lists forms and their submissions. Following
 * plugins/booking/BookingMcpHandler.php's shape. There's no dedicated
 * FormsAPI list method for submissions — this queries forms_submissions /
 * forms_definitions directly, tenant-scoped, the same way
 * admin/submissions.php does.
 */

declare(strict_types=1);

class FormsMcpHandler {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['forms.read']  = 'View forms and their submissions';
        $scopes['forms.write'] = 'Create or edit forms (no delete) and update submission status';
        return $scopes;
    }

    private static function has(array $context, string $scope): bool {
        return in_array($scope, (array)($context['scopes'] ?? []), true);
    }

    public static function filterTools(array $tools, array $context): array {
        if (self::has($context, 'forms.read')) {
            $tools[] = [
                'name' => 'slate_forms_list_definitions',
                'description' => 'List available forms (contact, quote, intake, etc.).',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ];
            $tools[] = [
                'name' => 'slate_forms_list_submissions',
                'description' => 'List submissions for a form, newest first.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'form_slug' => ['type' => 'string'],
                    'limit'     => ['type' => 'integer', 'description' => 'Default 50, max 200'],
                ], 'required' => ['form_slug'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'forms.write')) {
            $tools[] = [
                'name' => 'slate_forms_upsert_form',
                'description' => 'Create a new form, or update an existing one when id is given. Covers the core builder fields (title, fields, messages) — no delete, and advanced appearance/PDF/email styling is left at its defaults or whatever an existing form already has.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id'               => ['type' => 'integer', 'description' => 'Omit to create; provide to update.'],
                    'title'            => ['type' => 'string'],
                    'description'      => ['type' => 'string'],
                    'fields'           => [
                        'type' => 'array',
                        'description' => 'Field list. Each item: {type, label, required?, placeholder?, options? (for select/radio/checkboxes)}.',
                        'items' => ['type' => 'object', 'properties' => [
                            'type'        => ['type' => 'string', 'enum' => ['text','email','tel','url','number','date','time','textarea','select','radio','checkbox','checkboxes','rating','disclaimer','heading']],
                            'name'        => ['type' => 'string', 'description' => 'Optional machine name; derived from label if omitted.'],
                            'label'       => ['type' => 'string'],
                            'required'    => ['type' => 'boolean'],
                            'placeholder' => ['type' => 'string'],
                            'options'     => ['type' => 'array', 'items' => ['type' => 'string']],
                        ], 'required' => ['type']],
                    ],
                    'submit_label'     => ['type' => 'string'],
                    'success_message'  => ['type' => 'string'],
                    'notify_email'     => ['type' => 'string', 'description' => 'Email address notified on each new submission.'],
                    'status'           => ['type' => 'string', 'enum' => ['draft', 'published', 'archived'], 'description' => 'Default draft for a new form.'],
                ], 'required' => ['title'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_forms_update_submission_status',
                'description' => 'Mark a client-submitted form entry read/unread and/or set its status (new / in_progress / done).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'submission_id' => ['type' => 'integer'],
                    'read'          => ['type' => 'boolean', 'description' => 'true = mark read, false = mark unread. Omit to leave unchanged.'],
                    'status'        => ['type' => 'string', 'enum' => ['new', 'in_progress', 'done']],
                ], 'required' => ['submission_id'], 'additionalProperties' => false],
            ];
        }
        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;

        // Module Guard (07 §2, "Service/internal call" — defense-in-depth
        // against a caller that reaches the service directly): the MCP
        // gateway is an API surface like any other, and scope-based auth
        // (forms.read/forms.write) is an independent axis from license
        // entitlement — a valid scope must never substitute for it (07 §6).
        if (str_starts_with($name, 'slate_forms_') && !ModuleGuard::allows('forms')) {
            throw new RuntimeException('This module is not included in your current license.');
        }

        if ($name === 'slate_forms_list_definitions') {
            self::requireScope($context, 'forms.read');
            $rows = Database::rows(
                'SELECT id, slug, title FROM forms_definitions WHERE tenant_id = ? ORDER BY title',
                [current_tenant_id()]
            );
            return ['forms' => $rows];
        }

        if ($name === 'slate_forms_list_submissions') {
            self::requireScope($context, 'forms.read');
            $slug = trim((string)($args['form_slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException('form_slug is required.');
            $form = FormsAPI::getForm($slug);
            if (!$form) throw new InvalidArgumentException('Form not found.');
            $limit = max(1, min(200, (int)($args['limit'] ?? 50)));
            $rows = Database::rows(
                'SELECT id, ref, created_at, ip, submitter_email, status, read_at, data_json
                   FROM forms_submissions WHERE tenant_id = ? AND form_id = ?
               ORDER BY created_at DESC LIMIT ' . $limit,
                [current_tenant_id(), (int)$form['id']]
            );
            foreach ($rows as &$r) $r['data'] = json_decode((string)($r['data_json'] ?? '{}'), true) ?: [];
            return ['form' => ['id' => $form['id'], 'slug' => $form['slug'], 'title' => $form['title'] ?? $slug], 'submissions' => $rows];
        }

        if ($name === 'slate_forms_upsert_form') {
            self::requireScope($context, 'forms.write');
            return self::upsertForm($args);
        }

        if ($name === 'slate_forms_update_submission_status') {
            self::requireScope($context, 'forms.write');
            $tid = current_tenant_id();
            $id  = (int)($args['submission_id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('submission_id is required.');
            $row = Database::row('SELECT id FROM forms_submissions WHERE id = ? AND tenant_id = ?', [$id, $tid]);
            if (!$row) throw new InvalidArgumentException('Submission not found.');
            if (array_key_exists('read', $args)) {
                Database::update('forms_submissions', ['read_at' => !empty($args['read']) ? slate_db_now() : null], 'id = ? AND tenant_id = ?', [$id, $tid]);
            }
            if (!empty($args['status']) && in_array($args['status'], ['new', 'in_progress', 'done'], true)) {
                Database::update('forms_submissions', ['status' => (string)$args['status']], 'id = ? AND tenant_id = ?', [$id, $tid]);
            }
            return ['ok' => true, 'submission_id' => $id];
        }

        return null;
    }

    /** Core fields only (title/description/fields/messages/status) — advanced builder styling is left untouched. */
    private static function upsertForm(array $args): array {
        $tid   = current_tenant_id();
        $id    = (int)($args['id'] ?? 0);
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('title is required.');

        $existing = null;
        if ($id > 0) {
            $existing = Database::row('SELECT * FROM forms_definitions WHERE id = ? AND tenant_id = ?', [$id, $tid]);
            if (!$existing) throw new InvalidArgumentException('Form not found.');
        }

        $status = (string)($args['status'] ?? ($existing['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'published', 'archived'], true)) $status = 'draft';

        $row = [
            'tenant_id'         => $tid,
            'title'             => mb_substr($title, 0, 200),
            'slug'              => FormsAPI::slugify($title, $id > 0 ? $id : null),
            'description'       => trim((string)($args['description'] ?? '')) ?: null,
            'submit_label'      => trim((string)($args['submit_label'] ?? '')) ?: ($existing['submit_label'] ?? 'Submit'),
            'success_message'   => trim((string)($args['success_message'] ?? '')) ?: ($existing['success_message'] ?? null),
            'notify_email'      => trim((string)($args['notify_email'] ?? '')) ?: ($existing['notify_email'] ?? null),
            'status'            => $status,
        ];

        if (array_key_exists('fields', $args) && is_array($args['fields'])) {
            $norm = FormsAPI::normalizeFields($args['fields']);
            if (!empty($norm['errors'])) throw new InvalidArgumentException(implode(' ', $norm['errors']));
            $row['fields_json'] = json_encode($norm['fields'], JSON_UNESCAPED_UNICODE);
        } elseif ($id === 0) {
            $row['fields_json'] = json_encode([]);
        }

        if ($id > 0) {
            Database::update('forms_definitions', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
            AuditLog::record('forms.updated', (string)$id, ['via' => 'mcp']);
        } else {
            $id = Database::insert('forms_definitions', $row);
            AuditLog::record('forms.created', (string)$id, ['via' => 'mcp']);
        }

        return ['ok' => true, 'form_id' => $id, 'slug' => $row['slug']];
    }

    private static function requireScope(array $context, string $scope): void {
        if (!in_array($scope, (array)($context['scopes'] ?? []), true)) {
            throw new RuntimeException("This token does not grant the \"$scope\" scope.");
        }
    }
}
