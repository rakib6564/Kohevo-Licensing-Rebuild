<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8A import report.
 *
 * The structured, deterministic answer to a dry run (and to a commit): every
 * issue has a stable code, a severity (error blocks the commit, warning does
 * not), the package item it concerns and a JSON path. Ordering is stable —
 * issues by item position, then path, then code — so the same package and
 * options always produce the same report.
 *
 * Stable issue codes:
 *   invalid_package, unknown_package_key, unknown_item_type, forbidden_tenant_id,
 *   invalid_document, unknown_block_type, invalid_block_version, unresolved_media,
 *   unresolved_template, unresolved_global_component, unentitled_module,
 *   route_collision, reserved_route, template_collision, system_template_protected,
 *   permission_denied, concurrency_conflict, target_not_found, page_type_mismatch,
 *   invalid_tokens, tokens_skipped, tokens_replace_live, component_unpublished
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package;

final class StudioImportReport
{
    /** Validator codes mapped onto the import vocabulary (anything else is invalid_document). */
    public const VALIDATOR_CODES = [
        'unknown_block_type'               => 'unknown_block_type',
        'invalid_block_type'               => 'unknown_block_type',
        'invalid_block_version'            => 'invalid_block_version',
        'block_module_not_entitled'        => 'unentitled_module',
        'block_permission_denied'          => 'permission_denied',
        'cross_tenant_or_missing_media'    => 'unresolved_media',
        'cross_tenant_or_missing_template' => 'unresolved_template',
        'cross_tenant_or_missing_partial'  => 'unresolved_global_component',
        'forbidden_tenant_id'              => 'forbidden_tenant_id',
    ];

    public const UNRESOLVED_CODES = ['unresolved_media', 'unresolved_template', 'unresolved_global_component'];

    /** @var list<array{severity: string, code: string, item: ?string, path: string, message: string, detail?: string}> */
    private array $issues = [];

    /** @var array<string, int> item key => position in the package */
    private array $order = [];

    /** @var list<array<string, mixed>> */
    private array $plannedActions = [];

    /** @var list<array{item: string, kind: string, document: array<string, mixed>}> */
    private array $previews = [];

    /** @var array<string, mixed> */
    private array $dependencies = [];

    /** @var array<string, int> */
    private array $kindCounts = ['page' => 0, 'global_component' => 0, 'template' => 0, 'tokens' => 0];

    private int $sections = 0;
    private int $blocks = 0;

    public function __construct(
        public readonly string $mode,
        public readonly bool $dryRun,
        public readonly string $packageHash,
    ) {}

    public function setItemOrder(string $key, int $position): void
    {
        $this->order[$key] = $position;
    }

    public function countItem(string $kind): void
    {
        if (isset($this->kindCounts[$kind])) {
            $this->kindCounts[$kind]++;
        }
    }

    public function error(string $code, ?string $item, string $path, string $message, ?string $detail = null): void
    {
        $this->add('error', $code, $item, $path, $message, $detail);
    }

    public function warning(string $code, ?string $item, string $path, string $message, ?string $detail = null): void
    {
        $this->add('warning', $code, $item, $path, $message, $detail);
    }

    /** @param array{severity: string, code: string, item: ?string, path: string, message: string} $issue */
    public function addIssue(array $issue): void
    {
        $this->add($issue['severity'], $issue['code'], $issue['item'], $issue['path'], $issue['message'], $issue['detail'] ?? null);
    }

    /**
     * Canonical validator issues (from StudioValidationException::errors()) for one item.
     *
     * @param list<array<string, mixed>> $errors
     */
    public function validatorErrors(?string $item, string $pathPrefix, array $errors): void
    {
        foreach ($errors as $e) {
            $raw  = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($e['code'] ?? 'invalid'))) ?: 'invalid';
            $code = self::VALIDATOR_CODES[$raw] ?? 'invalid_document';
            $path = (string) ($e['path'] ?? '$');
            $path = $pathPrefix . (str_starts_with($path, '$') ? substr($path, 1) : '.' . $path);
            $this->add('error', $code, $item, $path, (string) ($e['message'] ?? ''), $code === $raw ? null : $raw);
        }
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'error') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $action */
    public function plan(array $action): void
    {
        $this->plannedActions[] = $action;
    }

    /** @param array<string, mixed> $document */
    public function preview(string $item, string $kind, array $document): void
    {
        $counts = PackageDocumentMapper::counts($document);
        $this->sections += $counts['sections'];
        $this->blocks   += $counts['blocks'];
        $this->previews[] = ['item' => $item, 'kind' => $kind, 'document' => $document];
    }

    /** @param array<string, mixed> $dependencies */
    public function setDependencies(array $dependencies): void
    {
        $this->dependencies = $dependencies;
    }

    /**
     * @param array<string, mixed>|null $committed what a commit created (null for a dry run / refused commit)
     * @return array<string, mixed>
     */
    public function toArray(?array $committed = null): array
    {
        $issues = $this->sortedIssues();
        $errors = count(array_filter($issues, static fn(array $i): bool => $i['severity'] === 'error'));
        $unresolved = count(array_filter($issues, static fn(array $i): bool => in_array($i['code'], self::UNRESOLVED_CODES, true)));
        $canCommit = $errors === 0;

        return [
            // A dry run is "ok" when it could be analysed (validity is `valid` / `can_commit`); a commit is ok once committed.
            'ok'                => $this->dryRun ? true : $committed !== null,
            'dry_run'           => $this->dryRun,
            'source_kind'       => 'kohevo_json',
            'mode'              => $this->mode,
            'package_hash'      => $this->packageHash,
            'valid'             => $canCommit,
            'can_commit'        => $canCommit && $this->dryRun,
            'summary'           => [
                'items_count'             => array_sum($this->kindCounts),
                'pages_count'             => $this->kindCounts['page'],
                'templates_count'         => $this->kindCounts['template'],
                'global_components_count' => $this->kindCounts['global_component'],
                'tokens_count'            => $this->kindCounts['tokens'],
                'sections_count'          => $this->sections,
                'blocks_count'            => $this->blocks,
                'warnings_count'          => count($issues) - $errors,
                'errors_count'            => $errors,
                'unresolved_refs_count'   => $unresolved,
            ],
            'issues'            => $issues,
            'dependencies'      => $this->dependencies,
            'planned_actions'   => $this->plannedActions,
            'preview_documents' => $canCommit ? $this->previews : [],
            'committed'         => $committed,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sortedIssues(): array
    {
        $issues = $this->issues;
        usort($issues, function (array $a, array $b): int {
            $pa = $a['item'] === null ? -1 : ($this->order[$a['item']] ?? PHP_INT_MAX);
            $pb = $b['item'] === null ? -1 : ($this->order[$b['item']] ?? PHP_INT_MAX);
            return [$pa, $a['path'], $a['code'], $a['severity'], $a['message']] <=> [$pb, $b['path'], $b['code'], $b['severity'], $b['message']];
        });
        return array_values(array_unique($issues, SORT_REGULAR));
    }

    private function add(string $severity, string $code, ?string $item, string $path, string $message, ?string $detail): void
    {
        $issue = [
            'severity' => $severity === 'error' ? 'error' : 'warning',
            'code'     => $code,
            'item'     => $item,
            'path'     => mb_substr($path, 0, 200, 'UTF-8'),
            'message'  => mb_substr($message, 0, 300, 'UTF-8'),
        ];
        if ($detail !== null && $detail !== '') {
            $issue['detail'] = $detail;
        }
        $this->issues[] = $issue;
    }
}
