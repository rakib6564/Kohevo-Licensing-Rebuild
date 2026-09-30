<?php
/**
 * Kohevo Studio (studio-builder) — Builder command/query HTTP controller.
 *
 * The ONLY server surface the Phase 5 builder UI talks to (besides the canvas
 * and preview renders). It is deliberately thin:
 *
 *   transport checks (method, CSRF, same-origin, content type, size, rate)
 *     -> strict request-shape validation (unknown fields rejected — a
 *        `tenant_id` anywhere in a request is an error, never a hint)
 *     -> ONE StudioApplicationService call (which alone enforces tenant ->
 *        authentication -> entitlement -> RBAC -> ownership -> concurrency ->
 *        validation -> persistence -> audit)
 *     -> transport-safe view of the result
 *
 * It never touches a repository, a Studio service, or the database itself,
 * and never trusts a tenant, page ownership, revision ownership or permission
 * claim from the client: page and revision ids are only ever resolved by the
 * tenant-scoped application layer.
 *
 * Every document mutation is a canonical `DocumentOperation` (insert/move/
 * remove section or block, update props/style/visibility/bindings/layout,
 * settings, SEO) submitted through the single `operations` command — there is
 * no second, ad-hoc mutation protocol.
 *
 * Failures map to a small, stable, safe vocabulary:
 *   validation_error, authentication_error, authorization_error,
 *   entitlement_error, csrf_error, not_found, method_not_allowed,
 *   concurrency_conflict, payload_too_large, unsupported_media_type,
 *   rate_limited, server_error
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Application\StudioEditorViews;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioRequestId;

final class StudioAuthoringApi
{
    /** A 1 MiB document plus envelope; anything larger is refused before decoding. */
    public const MAX_BODY_BYTES = 1_572_864;
    public const MAX_OPERATIONS = 50;
    private const MAX_JSON_DEPTH = 64;

    /** action => [HTTP method, allowed request fields] */
    private const ACTIONS = [
        // Queries
        'bootstrap'    => ['GET', ['page']],
        'document'     => ['GET', ['page']],
        'status'       => ['GET', ['page']],
        'manifest'     => ['GET', []],
        'revisions'    => ['GET', ['page', 'limit']],
        'templates'    => ['GET', ['type']],
        'pages'        => ['GET', []],
        // Phase 6 queries
        'components'   => ['GET', []],
        'chrome'       => ['GET', ['page']],
        'tokens'       => ['GET', ['group']],
        // Phase 7 query: structured diff between two revisions of one page (review of AI drafts)
        'diff'         => ['GET', ['page', 'base', 'proposed']],
        // Commands
        'operations'   => ['POST', ['page_id', 'expected_revision_id', 'revision_kind', 'operations', 'summary']],
        'save_draft'   => ['POST', ['page_id', 'expected_revision_id', 'revision_kind', 'document', 'summary']],
        'publish'      => ['POST', ['page_id', 'expected_revision_id', 'summary']],
        'rollback'     => ['POST', ['page_id', 'target_revision_id', 'expected_revision_id', 'summary']],
        'create_page'  => ['POST', ['title', 'slug', 'page_type', 'route_mode', 'template_key']],
        'lock_acquire' => ['POST', ['page_id']],
        'lock_refresh' => ['POST', ['page_id', 'lock_token']],
        'lock_release' => ['POST', ['page_id', 'lock_token']],
        // Phase 6 commands (every document write carries expected_revision_id)
        'apply_template'   => ['POST', ['page_id', 'template_key', 'expected_revision_id']],
        'insert_template'  => ['POST', ['page_id', 'template_key', 'index', 'parent_id', 'expected_revision_id']],
        'save_template'    => ['POST', ['page_id', 'node_id', 'template_key', 'template_type', 'category', 'name', 'description', 'thumbnail_media_id']],
        'delete_template'  => ['POST', ['template_key']],
        'create_component' => ['POST', ['title', 'slug', 'page_id', 'section_id', 'expected_revision_id']],
        'detach_component' => ['POST', ['page_id', 'section_id', 'expected_revision_id']],
        'save_tokens'      => ['POST', ['group', 'tokens']],
        // Phase 8A JSON packages: export is a query (studio-builder.view); import is ONE command
        // whose `dry_run` decides between analysis (no write) and import into drafts.
        'export_package'   => ['GET', ['page', 'include_components', 'include_template', 'include_tokens']],
        'import_package'   => ['POST', ['package', 'dry_run', 'mode', 'target_page_id', 'expected_revision_id', 'media_map', 'component_map', 'include_tokens']],
        // Phase 8B: constrained HTML/CSS import — the same dry-run / import-into-draft command shape.
        'import_html'      => ['POST', ['html', 'css', 'title', 'slug', 'page_type', 'dry_run', 'mode', 'target_page_id', 'expected_revision_id', 'media_map']],
    ];

    /** Safe, fixed messages per public error code — never an exception message. */
    private const MESSAGES = [
        'authentication_error'   => 'Your session has ended. Please sign in again.',
        'authorization_error'    => 'You do not have permission to do this.',
        'entitlement_error'      => 'Kohevo Studio is not available on this site.',
        'csrf_error'             => 'The security check failed. Reload the builder and try again.',
        'not_found'              => 'This page or revision was not found.',
        'method_not_allowed'     => 'This request method is not allowed here.',
        'concurrency_conflict'   => 'This page was changed elsewhere. Reload to continue.',
        'validation_error'       => 'The change was rejected because it is not valid.',
        'payload_too_large'      => 'The request is too large.',
        'unsupported_media_type' => 'The request must be JSON.',
        'rate_limited'           => 'Too many requests. Please wait a moment.',
        'server_error'           => 'The server could not complete this request.',
    ];

    public function __construct(
        private readonly StudioApplicationService $app,
        private readonly ?\Closure $rateLimiter = null,
        private readonly ?\Closure $logger = null,
        /** fn(string $code, array $meta): void — records a security denial (Phase 9D); never gets a body or a secret. */
        private readonly ?\Closure $denialAudit = null,
    ) {}

    /**
     * Denials worth an audit row: a signed-in user was refused. Deliberately
     * NOT audited: 401 (anonymous volume, no actor), 404/405/413/415,
     * validation (422) and concurrency (409) — ordinary client mistakes, not
     * security signals — and read-only preview/canvas refusals.
     */
    private const AUDITED_DENIALS = ['csrf_error', 'authorization_error', 'entitlement_error', 'rate_limited'];

    public function handle(StudioApiRequest $request, StudioActor $actor): StudioApiResponse
    {
        $response = $this->respond($request, $actor);
        $this->auditDenial($request, $response);
        return $response;
    }

    private function respond(StudioApiRequest $request, StudioActor $actor): StudioApiResponse
    {
        try {
            return $this->dispatch($request, $actor);
        } catch (StudioValidationException $e) {
            return self::error(422, 'validation_error', ['errors' => self::safeIssues($e->errors())]);
        } catch (StudioException $e) {
            $response = $this->mapStudioException($e);
            if ($response->status >= 500) {
                $this->logFailure($request, $e);
            }
            return $response;
        } catch (\Throwable $e) {
            $this->logFailure($request, $e);
            return self::error(500, 'server_error');
        }
    }

    private function logFailure(StudioApiRequest $request, \Throwable $e): void
    {
        if ($this->logger !== null) {
            ($this->logger)(StudioLog::describe('api', $request->action, $e));
        }
    }

    /**
     * Runs after the response is decided — no Studio transaction is open here
     * (AuditLog::record can implicitly commit). The row names the denial class,
     * the endpoint action and method, the request id and, for a CSRF refusal,
     * whether it was a cross-site fetch or a bad token. Nothing from the body.
     */
    private function auditDenial(StudioApiRequest $request, StudioApiResponse $response): void
    {
        $code = $response->errorCode();
        if ($this->denialAudit === null || $code === null || !in_array($code, self::AUDITED_DENIALS, true)) {
            return;
        }
        $meta = [
            'status'     => $response->status,
            'method'     => $request->method,
            'action'     => preg_match('/^[a-z_]{1,32}$/', $request->action) === 1 ? $request->action : 'invalid',
            'request_id' => StudioRequestId::current(),
        ];
        if ($code === 'csrf_error') {
            $meta['reason'] = ($request->fetchSite !== null && !in_array($request->fetchSite, ['same-origin', 'none'], true)) ? 'cross_site' : 'token';
        }
        try {
            ($this->denialAudit)($code, $meta);
        } catch (\Throwable $ignored) {
            // Auditing must never change the response.
        }
    }

    private function dispatch(StudioApiRequest $request, StudioActor $actor): StudioApiResponse
    {
        $action = $request->action;
        if (preg_match('/^[a-z_]{1,32}$/', $action) !== 1 || !isset(self::ACTIONS[$action])) {
            return self::error(404, 'not_found');
        }
        [$method, $allowed] = self::ACTIONS[$action];
        if ($request->method !== $method) {
            return self::error(405, 'method_not_allowed', [], ['Allow' => $method]);
        }
        if (!$actor->isAuthenticated()) {
            return self::error(401, 'authentication_error');
        }

        if ($method === 'POST') {
            // Same-origin only: a cross-site request is refused even with a token.
            if ($request->fetchSite !== null && !in_array($request->fetchSite, ['same-origin', 'none'], true)) {
                return self::error(403, 'csrf_error');
            }
            if (!$request->csrfValid) {
                return self::error(403, 'csrf_error');
            }
            if (preg_match('~^application/json\s*(;|$)~i', trim($request->contentType)) !== 1) {
                return self::error(415, 'unsupported_media_type');
            }
            if (strlen($request->body) > self::MAX_BODY_BYTES) {
                return self::error(413, 'payload_too_large');
            }
            $input = self::decodeBody($request->body);
        } else {
            $input = $request->query;
        }

        if ($this->rateLimiter !== null && ($this->rateLimiter)($method, $action) !== true) {
            return self::error(429, 'rate_limited', [], ['Retry-After' => '10']);
        }

        self::rejectUnknownFields($input, $allowed);

        return match ($action) {
            'bootstrap'    => $this->bootstrap($actor, $input),
            'document'     => StudioApiResponse::ok(self::editorState($this->app->loadEditorDocument($actor, self::id($input, 'page')))),
            'status'       => StudioApiResponse::ok(['page' => $this->app->pageStatus($actor, self::id($input, 'page'))]),
            'manifest'     => StudioApiResponse::ok(['manifest' => $this->app->editorManifest($actor)]),
            'revisions'    => StudioApiResponse::ok(['revisions' => $this->app->listRevisions($actor, self::id($input, 'page'), self::optionalInt($input, 'limit') ?? 30)]),
            'templates'    => $this->templates($actor, $input),
            'pages'        => StudioApiResponse::ok(['pages' => $this->app->listPages($actor)]),
            'operations'   => $this->operations($actor, $input),
            'save_draft'   => $this->saveDraft($actor, $input),
            'publish'      => $this->publish($actor, $input),
            'rollback'     => $this->rollback($actor, $input),
            'create_page'  => $this->createPage($actor, $input),
            'lock_acquire' => StudioApiResponse::ok(['lock' => $this->app->acquireEditLock($actor, self::id($input, 'page_id'))]),
            'lock_refresh' => StudioApiResponse::ok(['lock' => $this->app->refreshEditLock($actor, self::id($input, 'page_id'), self::string($input, 'lock_token', 64))]),
            'lock_release' => StudioApiResponse::ok(['released' => $this->app->releaseEditLock($actor, self::id($input, 'page_id'), self::string($input, 'lock_token', 64))]),
            'components'   => StudioApiResponse::ok(['components' => $this->app->listGlobalComponents($actor)]),
            'chrome'       => StudioApiResponse::ok(['chrome' => $this->app->chromeBindings($actor, self::id($input, 'page'))]),
            'tokens'       => StudioApiResponse::ok(['tokens' => $this->app->designTokens($actor, self::tokenGroup($input))]),
            'diff'         => StudioApiResponse::ok(['review' => $this->app->diffRevisions($actor, self::id($input, 'page'), self::optionalInt($input, 'base'), self::optionalInt($input, 'proposed'))]),
            'apply_template'   => $this->applyTemplate($actor, $input),
            'insert_template'  => $this->insertTemplate($actor, $input),
            'save_template'    => $this->saveTemplate($actor, $input),
            'delete_template'  => StudioApiResponse::ok(['deleted' => StudioEditorViews::template($this->app->deleteTemplate($actor, self::templateKey($input)))]),
            'create_component' => $this->createComponent($actor, $input),
            'detach_component' => StudioApiResponse::ok(self::mutationResult($this->app->detachGlobalSection($actor, self::id($input, 'page_id'), self::nodeId($input, 'section_id'), self::expectedRevision($input)))),
            'save_tokens'      => $this->saveTokens($actor, $input),
            'export_package'   => $this->exportPackage($actor, $input),
            'import_package'   => $this->importPackage($actor, $input),
            'import_html'      => $this->importHtml($actor, $input),
        };
    }

    // ── Queries ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    private function bootstrap(StudioActor $actor, array $input): StudioApiResponse
    {
        $state = self::editorState($this->app->loadEditorDocument($actor, self::id($input, 'page')));
        $state['manifest'] = $this->app->editorManifest($actor);
        return StudioApiResponse::ok($state);
    }

    /** @param array<string, mixed> $input */
    private function templates(StudioActor $actor, array $input): StudioApiResponse
    {
        $type = self::optionalString($input, 'type', 32);
        if ($type !== null && preg_match('/^[a-z_]{1,32}$/', $type) !== 1) {
            throw self::invalid('type', 'invalid_field', 'type must be a template type key.');
        }
        return StudioApiResponse::ok(['templates' => $this->app->templateLibrary($actor, $type)]);
    }

    // ── Phase 6 commands ──────────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    private function applyTemplate(StudioActor $actor, array $input): StudioApiResponse
    {
        $result = $this->app->applyTemplate($actor, self::templateKey($input), self::id($input, 'page_id'), self::expectedRevision($input));
        return StudioApiResponse::ok(self::mutationResult($result));
    }

    /** @param array<string, mixed> $input */
    private function insertTemplate(StudioActor $actor, array $input): StudioApiResponse
    {
        $parentId = self::optionalString($input, 'parent_id', 64);
        if ($parentId !== null && preg_match('/^(sec|blk)_[a-z0-9]{16,32}$/', $parentId) !== 1) {
            throw self::invalid('parent_id', 'invalid_field', 'parent_id must be a section or block id.');
        }
        $result = $this->app->insertTemplate(
            $actor,
            self::id($input, 'page_id'),
            self::templateKey($input),
            self::index($input, 'index'),
            $parentId,
            self::expectedRevision($input),
        );
        return StudioApiResponse::ok(self::mutationResult($result));
    }

    /** @param array<string, mixed> $input */
    private function saveTemplate(StudioActor $actor, array $input): StudioApiResponse
    {
        $nodeId = self::optionalString($input, 'node_id', 64);
        if ($nodeId !== null && preg_match('/^(sec|blk)_[a-z0-9]{16,32}$/', $nodeId) !== 1) {
            throw self::invalid('node_id', 'invalid_field', 'node_id must be a section or block id.');
        }
        $type = self::string($input, 'template_type', 32);
        if (preg_match('/^[a-z_]{1,32}$/', $type) !== 1) {
            throw self::invalid('template_type', 'invalid_field', 'template_type must be a template type key.');
        }
        $thumb = null;
        if (array_key_exists('thumbnail_media_id', $input) && $input['thumbnail_media_id'] !== null) {
            $thumb = self::id($input, 'thumbnail_media_id');
        }
        $row = $this->app->saveTemplateFromPage(
            $actor,
            self::id($input, 'page_id'),
            $nodeId,
            self::templateKey($input),
            $type,
            self::optionalString($input, 'category', 64) ?? 'general',
            self::string($input, 'name', 191),
            self::optionalString($input, 'description', 1000),
            $thumb,
        );
        $library = $this->app->templateLibrary($actor, (string) $row['template_type']);
        foreach ($library as $view) {
            if ($view['template_key'] === (string) $row['template_key']) {
                return StudioApiResponse::ok(['template' => $view], 201);
            }
        }
        return StudioApiResponse::ok(['template' => StudioEditorViews::template($row)], 201);
    }

    /** @param array<string, mixed> $input */
    private function createComponent(StudioActor $actor, array $input): StudioApiResponse
    {
        $fromPage = array_key_exists('page_id', $input) && $input['page_id'] !== null ? self::id($input, 'page_id') : null;
        $sectionId = null;
        $expected = null;
        if ($fromPage !== null) {
            $sectionId = self::nodeId($input, 'section_id');
            $expected  = self::expectedRevision($input);
        }
        $result = $this->app->createGlobalComponent(
            $actor,
            self::string($input, 'title', 255),
            self::string($input, 'slug', 191),
            $fromPage,
            $sectionId,
            $expected,
        );
        $out = ['component' => $result['component']];
        if ($result['page'] !== null) {
            $out['page']     = StudioEditorViews::page($result['page']);
            $out['revision'] = is_array($result['revision']) && $result['revision'] !== [] ? StudioEditorViews::revision($result['revision']) : null;
            $out['document'] = isset($result['revision']['document_json']) ? CanonicalJson::decode((string) $result['revision']['document_json']) : null;
        }
        return StudioApiResponse::ok($out, 201);
    }

    /** @param array<string, mixed> $input */
    private function saveTokens(StudioActor $actor, array $input): StudioApiResponse
    {
        $tokens = $input['tokens'] ?? null;
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
        return StudioApiResponse::ok(['tokens' => $this->app->saveDesignTokens($actor, self::tokenGroup($input), $tokens)]);
    }

    // ── Phase 8A package commands ─────────────────────────────────────────

    /** @param array<string, mixed> $input */
    private function exportPackage(StudioActor $actor, array $input): StudioApiResponse
    {
        $result = $this->app->exportPackage(
            $actor,
            self::id($input, 'page'),
            self::flag($input, 'include_components', true),
            self::flag($input, 'include_template', true),
            self::flag($input, 'include_tokens', false),
        );
        return StudioApiResponse::ok($result);
    }

    /** @param array<string, mixed> $input */
    private function importPackage(StudioActor $actor, array $input): StudioApiResponse
    {
        $package = $input['package'] ?? null;
        if (!is_array($package) || $package === [] || array_is_list($package)) {
            throw self::invalid('package', 'invalid_package', 'package must be a Kohevo Studio package object.');
        }
        $dryRun = self::dryRun($input);
        $options = [
            'mode'             => self::optionalString($input, 'mode', 32) ?? 'create',
            'target_page_id'   => null,
            'expected_revision_id' => null,
            'media_map'        => self::mediaMap($input),
            'component_map'    => self::componentMap($input),
            'include_tokens'   => ($input['include_tokens'] ?? false) === true,
        ];
        self::importTarget($input, $options);
        return self::importResponse($this->app->importPackage($actor, $package, $options, $dryRun), $dryRun);
    }

    /**
     * Phase 8B: constrained HTML/CSS -> one draft page. Strict field types
     * here (the 1.5 MiB body cap bounds the transport); the source budgets
     * (512 KiB HTML / 128 KiB CSS -> `source_too_large` in the report),
     * parsing and every content rule live in the application layer's
     * converter, which runs only after authorization.
     *
     * @param array<string, mixed> $input
     */
    private function importHtml(StudioActor $actor, array $input): StudioApiResponse
    {
        $html = $input['html'] ?? null;
        if (!is_string($html)) {
            throw self::invalid('html', 'invalid_field', 'html must be a string.');
        }
        $css = $input['css'] ?? '';
        if ($css === null) {
            $css = '';
        }
        if (!is_string($css)) {
            throw self::invalid('css', 'invalid_field', 'css must be a string.');
        }
        $title = self::optionalString($input, 'title', 255);
        if ($title !== null && ($title !== trim($title) || preg_match('/[<>\x00-\x1F\x7F]/', $title) === 1)) {
            throw self::invalid('title', 'invalid_field', 'title must be plain text.');
        }
        $pageType = self::optionalString($input, 'page_type', 32) ?? 'page';
        if (!in_array($pageType, ['page', 'landing'], true)) {
            throw self::invalid('page_type', 'invalid_field', 'HTML import creates page or landing documents only.');
        }
        $dryRun = self::dryRun($input);
        $options = [
            'mode'                 => self::optionalString($input, 'mode', 32) ?? 'create',
            'target_page_id'       => null,
            'expected_revision_id' => null,
            'media_map'            => self::mediaMap($input),
        ];
        self::importTarget($input, $options);
        $slug = self::optionalString($input, 'slug', 191);
        // A new page needs its address; replacing a draft keeps the target's.
        if (($slug === null && $options['mode'] !== 'replace_draft') || ($slug !== null && preg_match(PageAddress::SLUG_PATTERN, $slug) !== 1)) {
            throw self::invalid('slug', 'invalid_field', 'slug must be lowercase letters, digits and single hyphens.');
        }
        $source = ['html' => $html, 'css' => $css, 'title' => $title, 'slug' => $slug, 'page_type' => $pageType];
        return self::importResponse($this->app->importHtml($actor, $source, $options, $dryRun), $dryRun);
    }

    /** @param array<string, mixed> $input */
    private static function dryRun(array $input): bool
    {
        $dryRun = $input['dry_run'] ?? null;
        if (!is_bool($dryRun)) {
            throw self::invalid('dry_run', 'required_field', 'dry_run must be true (analyse) or false (import into drafts).');
        }
        return $dryRun;
    }

    /**
     * `mode` and, for replace_draft, the explicit target: a page id resolved
     * tenant-scoped like every other builder command (the builder never
     * receives page uuids — see StudioEditorViews::page()) + expected_revision_id.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options receives target_page_id / expected_revision_id
     */
    private static function importTarget(array $input, array &$options): void
    {
        if ($options['mode'] === 'replace_draft') {
            $targetId = self::id($input, 'target_page_id');
            $expected = self::expectedRevision($input);
            if ($expected === null) {
                throw self::invalid('expected_revision_id', 'required_field', 'Replacing a draft requires expected_revision_id.');
            }
            $options['target_page_id'] = $targetId;
            $options['expected_revision_id'] = $expected;
        } elseif (array_key_exists('target_page_id', $input) || array_key_exists('expected_revision_id', $input)) {
            throw self::invalid('mode', 'invalid_field', 'target_page_id and expected_revision_id are only used with mode replace_draft.');
        }
    }

    /** @param array{report: array<string, mixed>, target: ?array<string, mixed>} $result */
    private static function importResponse(array $result, bool $dryRun): StudioApiResponse
    {
        $report = $result['report'];
        if (!$dryRun && ($report['committed'] ?? null) === null) {
            // A refused commit: nothing was written; the report says why.
            return self::error(422, 'validation_error', ['report' => $report]);
        }
        $out = ['report' => $report];
        if (is_array($result['target'] ?? null)) {
            // replace_draft: the builder adopts the new working draft like any other command result.
            $out += self::mutationResult($result['target']);
        }
        return StudioApiResponse::ok($out, $dryRun ? 200 : 201);
    }

    /**
     * `media_map`: source media path -> THIS site's media id (explicit mapping;
     * verified tenant-local by the application layer).
     *
     * @param array<string, mixed> $input
     * @return array<string, int>
     */
    private static function mediaMap(array $input): array
    {
        $map = $input['media_map'] ?? [];
        if ($map === null) {
            return [];
        }
        if (!is_array($map) || ($map !== [] && array_is_list($map)) || count($map) > 250) {
            throw self::invalid('media_map', 'invalid_field', 'media_map must be an object of media path => media id.');
        }
        $out = [];
        foreach ($map as $path => $id) {
            if (!is_string($path) || strlen($path) > 500 || !str_starts_with($path, '/uploads/') || str_contains($path, '..') || !is_int($id) || $id <= 0) {
                throw self::invalid('media_map', 'invalid_field', 'media_map maps /uploads/ paths to positive media ids.');
            }
            $out[$path] = $id;
        }
        return $out;
    }

    /**
     * `component_map`: source component uuid -> THIS site's component uuid.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function componentMap(array $input): array
    {
        $map = $input['component_map'] ?? [];
        if ($map === null) {
            return [];
        }
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        if (!is_array($map) || ($map !== [] && array_is_list($map)) || count($map) > 250) {
            throw self::invalid('component_map', 'invalid_field', 'component_map must be an object of source uuid => component uuid.');
        }
        $out = [];
        foreach ($map as $source => $target) {
            if (!is_string($source) || preg_match($uuid, $source) !== 1 || !is_string($target) || preg_match($uuid, $target) !== 1) {
                throw self::invalid('component_map', 'invalid_field', 'component_map maps component uuids to component uuids.');
            }
            $out[$source] = $target;
        }
        return $out;
    }

    /** A boolean query flag ('1'/'0'/'true'/'false'), with a default when absent. @param array<string, mixed> $input */
    private static function flag(array $input, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $value = $input[$key];
        return match (true) {
            $value === true, $value === '1', $value === 'true' => true,
            $value === false, $value === '0', $value === 'false' => false,
            default => throw self::invalid($key, 'invalid_field', "{$key} must be 1 or 0."),
        };
    }

    // ── Commands ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    private function operations(StudioActor $actor, array $input): StudioApiResponse
    {
        $pageId = self::id($input, 'page_id');
        $raw = $input['operations'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw self::invalid('operations', 'invalid_operations', 'operations must be a non-empty list.');
        }
        if (count($raw) > self::MAX_OPERATIONS) {
            throw self::invalid('operations', 'too_many_operations', 'At most ' . self::MAX_OPERATIONS . ' operations may be sent at once.');
        }
        $operations = [];
        foreach ($raw as $i => $op) {
            if (!is_array($op) || array_is_list($op) || array_diff(array_keys($op), ['op', 'payload']) !== []) {
                throw self::invalid("operations[{$i}]", 'invalid_operation', 'Each operation must be {op, payload}.');
            }
            $operations[] = DocumentOperation::fromArray($op);
        }

        $result = $this->app->applyDocumentOperation(
            $actor,
            $pageId,
            $operations,
            self::expectedRevision($input),
            self::optionalString($input, 'summary', 255),
            self::revisionKind($input),
        );
        return StudioApiResponse::ok(self::mutationResult($result));
    }

    /** @param array<string, mixed> $input */
    private function saveDraft(StudioActor $actor, array $input): StudioApiResponse
    {
        $document = $input['document'] ?? null;
        if (!is_array($document) || ($document !== [] && array_is_list($document))) {
            throw self::invalid('document', 'invalid_document', 'document must be a JSON object.');
        }
        $result = $this->app->saveDraft(
            $actor,
            self::id($input, 'page_id'),
            $document,
            self::expectedRevision($input),
            self::revisionKind($input),
            self::optionalString($input, 'summary', 255),
        );
        return StudioApiResponse::ok(self::mutationResult($result));
    }

    /** @param array<string, mixed> $input */
    private function publish(StudioActor $actor, array $input): StudioApiResponse
    {
        $expected = self::expectedRevision($input);
        if ($expected === null) {
            // publish() treats null as "skip the check"; the builder must always state what it publishes.
            throw self::invalid('expected_revision_id', 'required_field', 'expected_revision_id is required to publish.');
        }
        $result = $this->app->publish($actor, self::id($input, 'page_id'), $expected, self::optionalString($input, 'summary', 255));
        return StudioApiResponse::ok(self::mutationResult($result + ['deduplicated' => false]));
    }

    /** @param array<string, mixed> $input */
    private function rollback(StudioActor $actor, array $input): StudioApiResponse
    {
        $result = $this->app->rollback(
            $actor,
            self::id($input, 'page_id'),
            self::id($input, 'target_revision_id'),
            self::expectedRevision($input),
            self::optionalString($input, 'summary', 255),
        );
        return StudioApiResponse::ok(self::mutationResult($result + ['deduplicated' => false]));
    }

    /** @param array<string, mixed> $input */
    private function createPage(StudioActor $actor, array $input): StudioApiResponse
    {
        $created = $this->app->createPage(
            $actor,
            self::string($input, 'title', 255),
            self::string($input, 'slug', 191),
            self::optionalString($input, 'page_type', 32) ?? 'page',
            self::optionalString($input, 'route_mode', 32) ?? 'standalone',
        );
        $page = $created['page'];

        $templateKey = self::optionalString($input, 'template_key', 120);
        if ($templateKey !== null && $templateKey !== '') {
            // The page was created a moment ago: its initial revision is the expected one.
            $applied = $this->app->applyTemplate($actor, $templateKey, (int) $page['id'], (int) $created['revision']['id']);
            $page = $applied['page'];
        }
        return StudioApiResponse::ok(['page' => StudioEditorViews::page($page)], 201);
    }

    // ── Views ─────────────────────────────────────────────────────────────

    /**
     * @param array{page: array<string, mixed>, revision: ?array<string, mixed>, document: array<string, mixed>} $state
     * @return array<string, mixed>
     */
    private static function editorState(array $state): array
    {
        return ['document' => $state['document'], 'page' => $state['page'], 'revision' => $state['revision']];
    }

    /**
     * @param array<string, mixed> $result {revision, page, deduplicated, ...} rows from the application layer
     * @return array<string, mixed>
     */
    private static function mutationResult(array $result): array
    {
        $revision = is_array($result['revision'] ?? null) ? $result['revision'] : [];
        return [
            'deduplicated' => (bool) ($result['deduplicated'] ?? false),
            'document'     => isset($revision['document_json']) ? CanonicalJson::decode((string) $revision['document_json']) : null,
            'page'         => StudioEditorViews::page($result['page']),
            'revision'     => $revision !== [] ? StudioEditorViews::revision($revision) : null,
        ];
    }

    // ── Errors ────────────────────────────────────────────────────────────

    private function mapStudioException(StudioException $e): StudioApiResponse
    {
        return match ($e->errorCode()) {
            'STUDIO_CONCURRENCY_CONFLICT'    => self::error(409, 'concurrency_conflict', self::conflictDetails($e->details())),
            'STUDIO_AUTHENTICATION_REQUIRED' => self::error(401, 'authentication_error'),
            'STUDIO_PERMISSION_DENIED',
            'STUDIO_TENANT_SCOPE_REQUIRED'   => self::error(403, 'authorization_error'),
            'STUDIO_NOT_ENTITLED'            => self::error(403, 'entitlement_error'),
            'STUDIO_NOT_FOUND'               => self::error(404, 'not_found'),
            default                          => self::error(500, 'server_error'),
        };
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, int|null>
     */
    private static function conflictDetails(array $details): array
    {
        $out = [];
        foreach (['current_revision_id', 'expected_revision_id'] as $key) {
            if (array_key_exists($key, $details)) {
                $out[$key] = is_int($details[$key]) ? $details[$key] : null;
            }
        }
        return $out;
    }

    /**
     * Validation issues are produced by Studio's own validators (never by the
     * database or PHP), so they are returned — bounded — to explain a rejection.
     *
     * @param list<array{path?: mixed, code?: mixed, message?: mixed}> $issues
     * @return list<array{path: string, code: string, message: string}>
     */
    private static function safeIssues(array $issues): array
    {
        $out = [];
        foreach (array_slice($issues, 0, 20) as $issue) {
            $out[] = [
                'code'    => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($issue['code'] ?? 'invalid'))) ?: 'invalid',
                'message' => mb_substr((string) ($issue['message'] ?? ''), 0, 300, 'UTF-8'),
                'path'    => mb_substr((string) ($issue['path'] ?? '$'), 0, 200, 'UTF-8'),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    private static function error(int $status, string $code, array $details = [], array $headers = []): StudioApiResponse
    {
        return StudioApiResponse::error($status, $code, self::MESSAGES[$code] ?? self::MESSAGES['server_error'], $details, $headers);
    }

    // ── Input validation ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function decodeBody(string $body): array
    {
        try {
            $decoded = json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw self::invalid('$', 'invalid_json', 'The request body is not valid JSON.');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw self::invalid('$', 'invalid_json', 'The request body must be a JSON object.');
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $allowed
     */
    private static function rejectUnknownFields(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw self::invalid((string) $key, 'unknown_field', 'Unknown request field.');
            }
        }
    }

    /** @param array<string, mixed> $input */
    private static function id(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw self::invalid($key, 'invalid_id', "{$key} must be a positive integer.");
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function optionalInt(array $input, string $key): ?int
    {
        return array_key_exists($key, $input) ? self::id($input, $key) : null;
    }

    /** A non-negative position. @param array<string, mixed> $input */
    private static function index(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,5})$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 100000) {
            throw self::invalid($key, 'invalid_index', "{$key} must be a non-negative integer.");
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function nodeId(array $input, string $key): string
    {
        $value = self::string($input, $key, 64);
        if (preg_match('/^(sec|blk)_[a-z0-9]{16,32}$/', $value) !== 1) {
            throw self::invalid($key, 'invalid_field', "{$key} must be a section or block id.");
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function templateKey(array $input): string
    {
        $value = self::string($input, 'template_key', 120);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $value) !== 1) {
            throw self::invalid('template_key', 'invalid_template_key', 'template_key must be a slug.');
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function tokenGroup(array $input): string
    {
        $value = self::optionalString($input, 'group', 64) ?? 'default';
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) !== 1) {
            throw self::invalid('group', 'invalid_token_group', 'group must be a token group identifier.');
        }
        return $value;
    }

    /**
     * `expected_revision_id` must be PRESENT on every revision-writing command:
     * a positive id, or an explicit null only for a page that has no draft yet.
     *
     * @param array<string, mixed> $input
     */
    private static function expectedRevision(array $input): ?int
    {
        if (!array_key_exists('expected_revision_id', $input)) {
            throw self::invalid('expected_revision_id', 'required_field', 'expected_revision_id is required.');
        }
        return $input['expected_revision_id'] === null ? null : self::id($input, 'expected_revision_id');
    }

    /** @param array<string, mixed> $input */
    private static function revisionKind(array $input): string
    {
        $kind = $input['revision_kind'] ?? 'manual';
        if (!is_string($kind) || !in_array($kind, StudioApplicationService::EDITOR_REVISION_KINDS, true)) {
            throw self::invalid('revision_kind', 'invalid_revision_kind', 'revision_kind must be autosave or manual.');
        }
        return $kind;
    }

    /** @param array<string, mixed> $input */
    private static function string(array $input, string $key, int $maxLength): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw self::invalid($key, 'invalid_field', "{$key} must be a string of at most {$maxLength} characters.");
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function optionalString(array $input, string $key, int $maxLength): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        return self::string($input, $key, $maxLength);
    }

    private static function invalid(string $field, string $code, string $message): StudioValidationException
    {
        return new StudioValidationException([['path' => $field === '$' ? '$' : '$.' . $field, 'code' => $code, 'message' => $message]]);
    }
}
