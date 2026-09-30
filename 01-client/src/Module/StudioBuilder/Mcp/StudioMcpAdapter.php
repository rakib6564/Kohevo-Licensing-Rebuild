<?php
/**
 * Kohevo Studio (studio-builder) — The Studio MCP adapter (Phase 7).
 *
 * The ONLY bridge between the MCP gateway (external AI clients, the admin
 * assistant) and Studio. It owns exactly five things:
 *
 *   1. MCP input validation (allowlisted fields — a `tenant_id` anywhere is
 *      an error, never a hint — types, id shapes, operation count, payload
 *      size, the mandatory `expected_revision_id`);
 *   2. the gateway scope check (mcp_token) — a scope reveals a tool, it never
 *      replaces the Studio permission;
 *   3. construction of the explicit `StudioActor` (origin mcp_token: token ∩
 *      issuer's current permissions, never super admin; origin
 *      admin_assistant: the signed-in human, re-labelled);
 *   4. mapping each tool onto ONE existing `StudioApplicationService`
 *      command — the same commands the Builder uses, which alone enforce
 *      tenant → authentication → entitlement → RBAC → concurrency →
 *      validation → persistence → audit;
 *   5. safe, bounded response projection (views, outlines, structured diffs;
 *      no rows, no SQL, no classes, no paths, no secrets).
 *
 * It contains no document algorithm, touches no repository, service or
 * table, and exposes no publish: `StudioApplicationService::publish()` is
 * never called from here (asserted statically by the Phase 7 unit tests).
 * Tenant identity comes from the gateway's authenticated context (already
 * applied to the TenantContext before this adapter runs) and is re-checked
 * here; it is never read from tool arguments.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Mcp;

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Application\StudioEditorViews;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;

final class StudioMcpAdapter
{
    /** Same bound as the builder API: a 1 MiB document plus envelope. */
    public const MAX_ARGS_BYTES = 1_572_864;
    public const MAX_JSON_DEPTH = 64;
    /** Rendered preview HTML returned to a model is truncated beyond this. */
    public const MAX_HTML_CHARS = 200_000;

    /**
     * @param \Closure(): StudioActor                        $sessionActor       the signed-in human (admin_assistant origin)
     * @param \Closure(int, int): list<string>               $issuerPermissions  (userId, tenantId) => the issuer's CURRENT Studio permissions
     * @param \Closure(): int                                $currentTenantId    the tenant the process is scoped to right now
     * @param null|\Closure(int, int): string                $previewUrl         (pageId, revisionId) => human preview URL
     * @param null|\Closure(string, array<string, mixed>): void $rateLimiter     (rate class, context) — throws StudioMcpToolException when exhausted
     * @param null|\Closure(string): void                    $logger
     */
    public function __construct(
        private readonly StudioApplicationService $app,
        private readonly \Closure $sessionActor,
        private readonly \Closure $issuerPermissions,
        private readonly \Closure $currentTenantId,
        private readonly ?\Closure $previewUrl = null,
        private readonly ?\Closure $rateLimiter = null,
        private readonly ?\Closure $logger = null,
    ) {}

    public static function handles(string $name): bool
    {
        return str_starts_with($name, 'studio_');
    }

    // ── Catalog (tools/list) ─────────────────────────────────────────────

    /**
     * The Studio tools visible to a caller. mcp_token: every tool whose scope
     * the token holds (authority is still decided per call). admin_assistant:
     * every tool the signed-in human's CURRENT Studio permissions allow —
     * the gateway's "all scopes" context grants nothing here. Any other or
     * missing origin: nothing.
     *
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public function tools(array $context): array
    {
        $origin = (string) ($context['origin'] ?? '');
        $out = [];
        if ($origin === StudioActor::ORIGIN_MCP_TOKEN) {
            $scopes = array_values(array_filter((array) ($context['scopes'] ?? []), 'is_string'));
            foreach (StudioMcpToolCatalog::tools() as $name => $tool) {
                if (in_array($tool['scope'], $scopes, true)) {
                    $out[] = StudioMcpToolCatalog::descriptor($name);
                }
            }
            return $out;
        }
        if ($origin === StudioActor::ORIGIN_ADMIN_ASSISTANT) {
            $actor = ($this->sessionActor)();
            if (!$actor->isAuthenticated()) {
                return [];
            }
            foreach (StudioMcpToolCatalog::tools() as $name => $tool) {
                if ($actor->can($tool['permission'])) {
                    $out[] = StudioMcpToolCatalog::descriptor($name);
                }
            }
        }
        return $out;
    }

    // ── Dispatch (tools/call) ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $context the gateway's authenticated context (tenant_id, scopes, token_id, origin, issuer_user_id)
     * @return array<string, mixed>
     */
    public function call(string $name, array $args, array $context): array
    {
        if (!self::handles($name)) {
            throw new \LogicException('StudioMcpAdapter only handles studio_* tools.');
        }
        $tool = StudioMcpToolCatalog::get($name);
        if ($tool === null) {
            // studio_publish, studio_insert_row, … : never registered, never callable.
            throw new StudioMcpToolException('tool_not_available', 404, ['tool' => self::safeName($name)]);
        }

        $origin = (string) ($context['origin'] ?? '');
        if (!in_array($origin, StudioActor::AI_ORIGINS, true)) {
            throw new StudioMcpToolException('authorization_error', 403, ['reason' => 'unknown_origin']);
        }
        $tenantId = (int) ($context['tenant_id'] ?? 0);
        if ($tenantId <= 0 || ($this->currentTenantId)() !== $tenantId) {
            throw new StudioMcpToolException('authorization_error', 403, ['reason' => 'tenant_scope']);
        }
        if ($origin === StudioActor::ORIGIN_MCP_TOKEN) {
            $scopes = array_values(array_filter((array) ($context['scopes'] ?? []), 'is_string'));
            if (!in_array($tool['scope'], $scopes, true)) {
                throw new StudioMcpToolException('authorization_error', 403, ['reason' => 'missing_scope', 'required_scope' => $tool['scope']]);
            }
        }

        if ($this->rateLimiter !== null) {
            ($this->rateLimiter)($tool['rate'], $context);
        }

        try {
            $this->validateEnvelope($name, $args);
            $actor = $this->actorFor($context, $tenantId);
            return $this->dispatch($name, $args, $actor);
        } catch (StudioMcpToolException $e) {
            throw $e;
        } catch (StudioValidationException $e) {
            throw new StudioMcpToolException('validation_error', 422, ['errors' => self::safeIssues($e->errors())]);
        } catch (StudioException $e) {
            throw self::mapStudioException($e);
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                ($this->logger)('Studio MCP tool failure: ' . get_class($e));
            }
            throw new StudioMcpToolException('server_error', 500);
        }
    }

    // ── Actor construction ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $context
     */
    public function actorFor(array $context, int $tenantId): StudioActor
    {
        $origin = (string) ($context['origin'] ?? '');
        if ($origin === StudioActor::ORIGIN_MCP_TOKEN) {
            $tokenId  = (int) ($context['token_id'] ?? 0);
            $issuerId = (int) ($context['issuer_user_id'] ?? 0);
            if ($tokenId <= 0 || $issuerId <= 0) {
                throw new StudioMcpToolException('authentication_error', 401, ['reason' => 'token_identity']);
            }
            $scopePermissions  = StudioMcpScopes::permissionsForScopes((array) ($context['scopes'] ?? []));
            $issuerPermissions = ($this->issuerPermissions)($issuerId, $tenantId);
            $effective = array_values(array_intersect($scopePermissions, array_values(array_filter($issuerPermissions, 'is_string'))));
            return StudioActor::forMcpToken($tokenId, $issuerId, $effective);
        }
        if ($origin === StudioActor::ORIGIN_ADMIN_ASSISTANT) {
            $actor = ($this->sessionActor)()->asAdminAssistant();
            if (!$actor->isAuthenticated()) {
                throw new StudioMcpToolException('authentication_error', 401);
            }
            return $actor;
        }
        throw new StudioMcpToolException('authorization_error', 403, ['reason' => 'unknown_origin']);
    }

    // ── Tool → application command ───────────────────────────────────────

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function dispatch(string $name, array $a, StudioActor $actor): array
    {
        $includeDocument = self::bool($a, 'include_document');
        return match ($name) {
            'studio_list_pages'     => ['pages' => $this->app->listPages($actor)],
            'studio_get_page'       => ['page' => $this->app->pageStatus($actor, self::id($a, 'page_id'))],
            'studio_get_revisions'  => ['revisions' => $this->app->listRevisions($actor, self::id($a, 'page_id'), self::optionalInt($a, 'limit') ?? 30)],
            'studio_get_templates'  => ['templates' => $this->app->templateLibrary($actor, self::optionalString($a, 'type', 32))],
            'studio_get_components' => ['components' => $this->app->listGlobalComponents($actor)],
            'studio_get_chrome'     => ['chrome' => $this->app->chromeBindings($actor, self::id($a, 'page_id'))],
            'studio_get_tokens'     => ['tokens' => $this->app->designTokens($actor, self::optionalString($a, 'group', 64) ?? 'default')],
            'studio_get_manifest'   => ['manifest' => $this->app->editorManifest($actor)],
            'studio_get_structure'  => $this->structure($actor, self::id($a, 'page_id')),
            'studio_get_document'   => $this->document($actor, self::id($a, 'page_id')),
            'studio_preview'        => $this->preview($actor, self::id($a, 'page_id'), self::optionalInt($a, 'revision_id'), self::bool($a, 'include_html')),
            'studio_diff'           => $this->app->diffRevisions($actor, self::id($a, 'page_id'), self::optionalInt($a, 'base_revision_id'), self::optionalInt($a, 'proposed_revision_id')),

            'studio_create_page'    => $this->createPage($actor, $a, $includeDocument),
            'studio_apply_operations' => self::mutation($this->app->applyDocumentOperation(
                $actor,
                self::id($a, 'page_id'),
                self::operations($a),
                self::expectedRevision($a),
                self::optionalString($a, 'summary', 255),
                'manual',
            ), $includeDocument),
            'studio_insert_template' => self::mutation($this->app->insertTemplate(
                $actor,
                self::id($a, 'page_id'),
                self::templateKey($a, 'template_key'),
                self::index($a, 'index'),
                self::optionalNodeId($a, 'parent_id'),
                self::expectedRevision($a),
            ), $includeDocument),
            'studio_apply_template' => self::mutation($this->app->applyTemplate(
                $actor,
                self::templateKey($a, 'template_key'),
                self::id($a, 'page_id'),
                self::expectedRevision($a),
            ), $includeDocument),
            'studio_create_global_component' => $this->createComponent($actor, $a, $includeDocument),
            'studio_detach_global_component' => self::mutation($this->app->detachGlobalSection(
                $actor,
                self::id($a, 'page_id'),
                self::nodeId($a, 'section_id', 'sec'),
                self::expectedRevision($a),
            ), $includeDocument),
            'studio_rollback' => self::mutation($this->app->rollback(
                $actor,
                self::id($a, 'page_id'),
                self::id($a, 'target_revision_id'),
                self::expectedRevision($a),
                self::optionalString($a, 'summary', 255),
            ) + ['deduplicated' => false], $includeDocument),
            'studio_archive_page' => ['page' => StudioEditorViews::page($this->app->archivePage($actor, self::id($a, 'page_id')))],
            'studio_save_template' => $this->saveTemplate($actor, $a),
            'studio_save_tokens'   => ['tokens' => $this->app->saveDesignTokens($actor, self::optionalString($a, 'group', 64) ?? 'default', self::tokens($a))],
            default => throw new StudioMcpToolException('tool_not_available', 404, ['tool' => self::safeName($name)]),
        };
    }

    /** @return array<string, mixed> */
    private function structure(StudioActor $actor, int $pageId): array
    {
        $state = $this->app->loadEditorDocument($actor, $pageId);
        return ['page' => $state['page'], 'revision' => $state['revision'], 'outline' => self::outline($state['document'])];
    }

    /** @return array<string, mixed> */
    private function document(StudioActor $actor, int $pageId): array
    {
        $state = $this->app->loadEditorDocument($actor, $pageId);
        return ['page' => $state['page'], 'revision' => $state['revision'], 'document' => $state['document']];
    }

    /** @return array<string, mixed> */
    private function preview(StudioActor $actor, int $pageId, ?int $revisionId, bool $includeHtml): array
    {
        $page = $this->app->pageStatus($actor, $pageId);
        $targetId = $revisionId ?? $page['active_draft_revision_id'] ?? $page['published_revision_id'];
        if (!is_int($targetId) || $targetId <= 0) {
            throw new StudioMcpToolException('not_found', 404);
        }
        $revision = $this->app->findRevision($actor, $pageId, $targetId);
        $render = $this->app->renderPreview($actor, $pageId, $targetId);
        $html = $render->html;
        $out = [
            'page'     => $page,
            'revision' => $revision,
            'preview'  => [
                'url'          => $this->previewUrl !== null ? ($this->previewUrl)($pageId, $targetId) : null,
                'mode'         => 'preview',
                'public'       => false,
                'cache'        => 'no-store',
                'robots'       => 'noindex, nofollow',
                'requires_signed_in_human' => true,
                'html_bytes'   => strlen($html),
                'html_sha256'  => hash('sha256', $html),
                'side_effects' => 'none',
            ],
        ];
        if ($includeHtml) {
            $out['html'] = mb_substr($html, 0, self::MAX_HTML_CHARS, 'UTF-8');
            $out['html_truncated'] = mb_strlen($html, 'UTF-8') > self::MAX_HTML_CHARS;
        }
        return $out;
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function createPage(StudioActor $actor, array $a, bool $includeDocument): array
    {
        $created = $this->app->createPage(
            $actor,
            self::string($a, 'title', 255),
            self::string($a, 'slug', 191),
            self::optionalString($a, 'page_type', 32) ?? 'page',
            self::optionalString($a, 'route_mode', 32) ?? 'standalone',
        );
        $result = ['page' => $created['page'], 'revision' => $created['revision'], 'deduplicated' => false];
        $templateKey = self::optionalString($a, 'template_key', 120);
        if ($templateKey !== null && $templateKey !== '') {
            self::assertTemplateKey($templateKey, 'template_key');
            $result = $this->app->applyTemplate($actor, $templateKey, (int) $created['page']['id'], (int) $created['revision']['id']);
        }
        return self::mutation($result, $includeDocument);
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function createComponent(StudioActor $actor, array $a, bool $includeDocument): array
    {
        $fromPage = array_key_exists('page_id', $a) && $a['page_id'] !== null ? self::id($a, 'page_id') : null;
        $sectionId = null;
        $expected = null;
        if ($fromPage !== null) {
            $sectionId = self::nodeId($a, 'section_id', 'sec');
            $expected  = self::expectedRevision($a);
        }
        $result = $this->app->createGlobalComponent($actor, self::string($a, 'title', 255), self::string($a, 'slug', 191), $fromPage, $sectionId, $expected);
        $out = ['component' => $result['component']];
        if (is_array($result['page'] ?? null)) {
            $out += self::mutation(['page' => $result['page'], 'revision' => $result['revision'] ?? [], 'deduplicated' => false], $includeDocument);
        }
        return $out;
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function saveTemplate(StudioActor $actor, array $a): array
    {
        $type = self::string($a, 'template_type', 32);
        if (preg_match('/^[a-z_]{1,32}$/', $type) !== 1) {
            throw self::invalid('template_type', 'invalid_field', 'template_type must be a template type key.');
        }
        $row = $this->app->saveTemplateFromPage(
            $actor,
            self::id($a, 'page_id'),
            self::optionalNodeId($a, 'node_id'),
            self::templateKey($a, 'template_key'),
            $type,
            self::optionalString($a, 'category', 64) ?? 'general',
            self::string($a, 'name', 191),
            self::optionalString($a, 'description', 1000),
            null,
        );
        return ['template' => StudioEditorViews::template($row)];
    }

    // ── Projections ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $result {revision, page, deduplicated, …} from the application layer
     * @return array<string, mixed>
     */
    private static function mutation(array $result, bool $includeDocument): array
    {
        $revision = is_array($result['revision'] ?? null) ? $result['revision'] : [];
        $document = isset($revision['document_json']) ? CanonicalJson::decode((string) $revision['document_json']) : null;
        $out = [
            'page'         => StudioEditorViews::page($result['page']),
            'revision'     => $revision !== [] ? StudioEditorViews::revision($revision) : null,
            'deduplicated' => (bool) ($result['deduplicated'] ?? false),
            'outline'      => $document !== null ? self::outline($document) : null,
            'published'    => false,
            'next_step'    => 'A human reviews this draft (preview + studio_diff) and publishes it from the Studio Builder. No tool publishes.',
        ];
        if ($includeDocument && $document !== null) {
            $out['document'] = $document;
        }
        return $out;
    }

    /**
     * A bounded structural outline of a canonical document: ids, types and
     * labels only — enough to address nodes in the next operation, without
     * shipping every property back to the model. Labels are content.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function outline(array $document): array
    {
        $sections = [];
        foreach ((is_array($document['sections'] ?? null) ? $document['sections'] : []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $sections[] = [
                'id'         => (string) ($section['id'] ?? ''),
                'label'      => mb_substr((string) ($section['label'] ?? ''), 0, 80, 'UTF-8'),
                'global_ref' => isset($section['global_ref']) && is_string($section['global_ref']) ? $section['global_ref'] : null,
                'blocks'     => self::outlineBlocks(is_array($section['blocks'] ?? null) ? $section['blocks'] : []),
            ];
        }
        return [
            'document_type' => (string) ($document['document_type'] ?? ''),
            'template_key'  => (string) ($document['template_key'] ?? ''),
            'sections'      => $sections,
        ];
    }

    /** @param list<mixed> $blocks @return list<array<string, mixed>> */
    private static function outlineBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $label = null;
            foreach (['text', 'heading', 'title', 'label'] as $key) {
                if (is_string($props[$key] ?? null) && trim($props[$key]) !== '') {
                    $label = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $props[$key])), 0, 80, 'UTF-8');
                    break;
                }
            }
            $entry = ['id' => (string) ($block['id'] ?? ''), 'type' => (string) ($block['type'] ?? ''), 'label' => $label];
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== []) {
                $entry['children'] = self::outlineBlocks($children);
            }
            $out[] = $entry;
        }
        return $out;
    }

    // ── Errors ───────────────────────────────────────────────────────────

    private static function mapStudioException(StudioException $e): StudioMcpToolException
    {
        $details = $e->details();
        return match ($e->errorCode()) {
            'STUDIO_CONCURRENCY_CONFLICT' => new StudioMcpToolException('concurrency_conflict', 409, [
                'current_revision_id'  => is_int($details['current_revision_id'] ?? null) ? $details['current_revision_id'] : null,
                'expected_revision_id' => is_int($details['expected_revision_id'] ?? null) ? $details['expected_revision_id'] : null,
                'resolution'           => 'Re-read the page (studio_get_page / studio_get_document) and decide again with the current revision id. Do not retry blindly.',
            ]),
            'STUDIO_AUTHENTICATION_REQUIRED' => new StudioMcpToolException('authentication_error', 401),
            'STUDIO_PERMISSION_DENIED'       => new StudioMcpToolException('authorization_error', 403, is_string($details['required_permission'] ?? null) ? ['required_permission' => $details['required_permission']] : []),
            'STUDIO_TENANT_SCOPE_REQUIRED'   => new StudioMcpToolException('authorization_error', 403, ['reason' => 'tenant_scope']),
            'STUDIO_NOT_ENTITLED'            => new StudioMcpToolException('entitlement_error', 403),
            'STUDIO_NOT_FOUND'               => new StudioMcpToolException('not_found', 404),
            'STUDIO_VALIDATION_FAILED'       => new StudioMcpToolException('validation_error', 422, ['errors' => self::safeIssues(is_array($details['errors'] ?? null) ? $details['errors'] : [])]),
            default                          => new StudioMcpToolException('server_error', 500),
        };
    }

    /**
     * @param list<array{path?: mixed, code?: mixed, message?: mixed}> $issues
     * @return list<array{path: string, code: string, message: string}>
     */
    private static function safeIssues(array $issues): array
    {
        $out = [];
        foreach (array_slice($issues, 0, 20) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $out[] = [
                'code'    => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($issue['code'] ?? 'invalid'))) ?: 'invalid',
                'message' => mb_substr((string) ($issue['message'] ?? ''), 0, 300, 'UTF-8'),
                'path'    => mb_substr((string) ($issue['path'] ?? '$'), 0, 200, 'UTF-8'),
            ];
        }
        return $out;
    }

    private static function safeName(string $name): string
    {
        return mb_substr((string) preg_replace('/[^a-zA-Z0-9_.-]/', '', $name), 0, 64);
    }

    // ── Input validation ─────────────────────────────────────────────────

    /** @param array<string, mixed> $args */
    private function validateEnvelope(string $name, array $args): void
    {
        if ($args !== [] && array_is_list($args)) {
            throw self::invalid('$', 'invalid_arguments', 'Tool arguments must be a JSON object.');
        }
        $encoded = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw self::invalid('$', 'invalid_arguments', 'Tool arguments could not be encoded.');
        }
        if (strlen($encoded) > self::MAX_ARGS_BYTES) {
            throw new StudioMcpToolException('payload_too_large', 413);
        }
        if (self::depth($args) > self::MAX_JSON_DEPTH) {
            throw self::invalid('$', 'invalid_arguments', 'Tool arguments are nested too deeply.');
        }
        $allowed = StudioMcpToolCatalog::allowedFields($name);
        foreach (array_keys($args) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw self::invalid((string) $key, 'unknown_field', 'Unknown argument.');
            }
        }
    }

    private static function depth(mixed $value, int $current = 1): int
    {
        if (!is_array($value)) {
            return $current;
        }
        $max = $current;
        foreach ($value as $v) {
            if (is_array($v)) {
                $max = max($max, self::depth($v, $current + 1));
                if ($max > self::MAX_JSON_DEPTH) {
                    return $max;
                }
            }
        }
        return $max;
    }

    /**
     * @param array<string, mixed> $a
     * @return list<DocumentOperation>
     */
    private static function operations(array $a): array
    {
        $raw = $a['operations'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw self::invalid('operations', 'invalid_operations', 'operations must be a non-empty list.');
        }
        if (count($raw) > StudioMcpToolCatalog::MAX_OPERATIONS) {
            throw self::invalid('operations', 'too_many_operations', 'At most ' . StudioMcpToolCatalog::MAX_OPERATIONS . ' operations may be sent at once.');
        }
        $operations = [];
        foreach ($raw as $i => $op) {
            if (!is_array($op) || array_is_list($op) || array_diff(array_keys($op), ['op', 'payload']) !== []) {
                throw self::invalid("operations[{$i}]", 'invalid_operation', 'Each operation must be {op, payload}.');
            }
            $operation = DocumentOperation::fromArray($op);
            self::assertIdShapes($operation, $i);
            $operations[] = $operation;
        }
        return $operations;
    }

    /** Node ids named in an operation payload must have the canonical shape before the applier sees them. */
    private static function assertIdShapes(DocumentOperation $op, int $i): void
    {
        $checks = [
            'section_id' => '/^sec_[a-z0-9]{16,32}$/',
            'block_id'   => '/^blk_[a-z0-9]{16,32}$/',
            'parent_id'  => '/^(sec|blk)_[a-z0-9]{16,32}$/',
        ];
        foreach ($checks as $key => $pattern) {
            if (array_key_exists($key, $op->payload)) {
                $value = $op->payload[$key];
                if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                    throw self::invalid("operations[{$i}].payload.{$key}", 'invalid_field', "{$key} must be a canonical node id.");
                }
            }
        }
        foreach (['index', 'to_index'] as $key) {
            if (array_key_exists($key, $op->payload) && (!is_int($op->payload[$key]) || $op->payload[$key] < 0 || $op->payload[$key] > 100000)) {
                throw self::invalid("operations[{$i}].payload.{$key}", 'invalid_index', "{$key} must be a non-negative integer.");
            }
        }
    }

    /** @param array<string, mixed> $a */
    private static function id(array $a, string $key): int
    {
        $value = $a[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw self::invalid($key, 'invalid_id', "{$key} must be a positive integer.");
        }
        return $value;
    }

    /** @param array<string, mixed> $a */
    private static function optionalInt(array $a, string $key): ?int
    {
        return array_key_exists($key, $a) && $a[$key] !== null ? self::id($a, $key) : null;
    }

    /** @param array<string, mixed> $a */
    private static function index(array $a, string $key): int
    {
        $value = $a[$key] ?? null;
        if (!is_int($value) || $value < 0 || $value > 100000) {
            throw self::invalid($key, 'invalid_index', "{$key} must be a non-negative integer.");
        }
        return $value;
    }

    /** @param array<string, mixed> $a */
    private static function nodeId(array $a, string $key, string $prefix = 'sec|blk'): string
    {
        $value = self::string($a, $key, 64);
        if (preg_match('/^(' . $prefix . ')_[a-z0-9]{16,32}$/', $value) !== 1) {
            throw self::invalid($key, 'invalid_field', "{$key} must be a canonical node id.");
        }
        return $value;
    }

    /** @param array<string, mixed> $a */
    private static function optionalNodeId(array $a, string $key): ?string
    {
        return array_key_exists($key, $a) && $a[$key] !== null ? self::nodeId($a, $key) : null;
    }

    /** @param array<string, mixed> $a */
    private static function templateKey(array $a, string $key): string
    {
        $value = self::string($a, $key, 120);
        self::assertTemplateKey($value, $key);
        return $value;
    }

    private static function assertTemplateKey(string $value, string $key): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $value) !== 1) {
            throw self::invalid($key, 'invalid_template_key', "{$key} must be a slug.");
        }
    }

    /**
     * `expected_revision_id` must be PRESENT on every revision-writing tool:
     * a positive id, or an explicit null only for a page without a draft.
     *
     * @param array<string, mixed> $a
     */
    private static function expectedRevision(array $a): ?int
    {
        if (!array_key_exists('expected_revision_id', $a)) {
            throw self::invalid('expected_revision_id', 'required_field', 'expected_revision_id is required.');
        }
        return $a['expected_revision_id'] === null ? null : self::id($a, 'expected_revision_id');
    }

    /** @param array<string, mixed> $a @return array<string, ?string> */
    private static function tokens(array $a): array
    {
        $tokens = $a['tokens'] ?? null;
        if (!is_array($tokens) || ($tokens !== [] && array_is_list($tokens))) {
            throw self::invalid('tokens', 'invalid_tokens', 'tokens must be a JSON object of token ref => value.');
        }
        foreach ($tokens as $ref => $value) {
            if (!is_string($ref) || preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $ref) !== 1) {
                throw self::invalid('tokens', 'invalid_tokens', 'token refs must be symbolic names.');
            }
            if ($value !== null && (!is_string($value) || strlen($value) > 200)) {
                throw self::invalid('tokens.' . $ref, 'invalid_token_value', 'token values must be short strings or null.');
            }
        }
        return $tokens;
    }

    /** @param array<string, mixed> $a */
    private static function string(array $a, string $key, int $maxLength): string
    {
        $value = $a[$key] ?? null;
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw self::invalid($key, 'invalid_field', "{$key} must be a string of at most {$maxLength} characters.");
        }
        return $value;
    }

    /** @param array<string, mixed> $a */
    private static function optionalString(array $a, string $key, int $maxLength): ?string
    {
        if (!array_key_exists($key, $a) || $a[$key] === null) {
            return null;
        }
        return self::string($a, $key, $maxLength);
    }

    /** @param array<string, mixed> $a */
    private static function bool(array $a, string $key): bool
    {
        if (!array_key_exists($key, $a) || $a[$key] === null) {
            return false;
        }
        if (!is_bool($a[$key])) {
            throw self::invalid($key, 'invalid_field', "{$key} must be a boolean.");
        }
        return $a[$key];
    }

    private static function invalid(string $field, string $code, string $message): StudioValidationException
    {
        return new StudioValidationException([['path' => $field === '$' ? '$' : '$.' . $field, 'code' => $code, 'message' => $message]]);
    }
}
