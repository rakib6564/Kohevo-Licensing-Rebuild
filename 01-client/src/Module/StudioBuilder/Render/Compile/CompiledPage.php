<?php
/**
 * Kohevo Studio (studio-builder) — A compiled page artifact (DERIVED output).
 *
 * Maps 1:1 onto the existing `studiobuilder_compilations` columns — no new
 * table or column:
 *
 *   revision_id            source revision the artifact was compiled from
 *   compile_mode           'published' (the only mode ever persisted)
 *   compiled_html          static body (header + main + footer); dynamic
 *                          nodes appear only as nonce markers
 *   compiled_css           base stylesheet + theme `:root` vars + token utilities
 *   dynamic_manifest_json  {nonce, nodes[], theme{group,tokens}} — the deferred
 *                          dynamic nodes, re-rendered on every request
 *   head_assets_json       {seo{…}, document_fingerprint, inputs{…}}
 *   content_hash           SHA-256 of every compile input (document, theme,
 *                          chrome partial revisions, site, registry, compiler)
 *   compiler_version       COMPILER_VERSION
 *
 * A compilation is never an authoring source: nothing reads a document back
 * out of it, and a stale/invalid one is simply recompiled from the revision.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Compile;

final class CompiledPage
{
    /**
     * @param array{nonce: string, nodes: list<array<string, mixed>>, theme: array{group: string, tokens: array<string, string>}} $dynamicManifest
     * @param array<string, mixed> $headAssets
     */
    public function __construct(
        public readonly int $pageId,
        public readonly int $revisionId,
        public readonly string $compileMode,
        public readonly string $html,
        public readonly string $css,
        public readonly array $dynamicManifest,
        public readonly array $headAssets,
        public readonly string $contentHash,
        public readonly string $compilerVersion,
    ) {}

    public function hasDynamicNodes(): bool
    {
        return ($this->dynamicManifest['nodes'] ?? []) !== [];
    }

    /** @return array<string, mixed> column => value (tenant_id is forced by the repository) */
    public function toRow(): array
    {
        return [
            'page_id'               => $this->pageId,
            'revision_id'           => $this->revisionId,
            'compile_mode'          => $this->compileMode,
            'compiled_html'         => $this->html,
            'compiled_css'          => $this->css,
            'dynamic_manifest_json' => json_encode($this->dynamicManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'head_assets_json'      => json_encode($this->headAssets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'content_hash'          => $this->contentHash,
            'compiler_version'      => $this->compilerVersion,
        ];
    }

    /**
     * Rehydrate a stored row; null when it is malformed (it is then recompiled).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $manifest = json_decode((string) ($row['dynamic_manifest_json'] ?? ''), true, 64);
        $head     = json_decode((string) ($row['head_assets_json'] ?? ''), true, 16);
        if (
            !is_array($manifest) || !is_array($head)
            || !is_string($manifest['nonce'] ?? null) || preg_match('/^[a-f0-9]{16,64}$/', $manifest['nonce']) !== 1
            || !is_array($manifest['nodes'] ?? null) || !array_is_list($manifest['nodes'])
            || !is_array($manifest['theme'] ?? null)
            || !isset($row['page_id'], $row['revision_id'], $row['compiled_html'], $row['compiled_css'], $row['content_hash'])
        ) {
            return null;
        }
        return new self(
            (int) $row['page_id'],
            (int) $row['revision_id'],
            (string) ($row['compile_mode'] ?? 'published'),
            (string) $row['compiled_html'],
            (string) $row['compiled_css'],
            $manifest,
            $head,
            (string) $row['content_hash'],
            (string) ($row['compiler_version'] ?? ''),
        );
    }
}
