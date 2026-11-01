<?php
/**
 * Widget design-control audit — step 1 of 2: render every registered widget as the public renderer would, once plain
 * and once per style probe, into /tmp-style HTML files that the browser step (ui/e2e-audit) measures.
 *
 *   php tests/audit/render-widgets.php <out-dir>
 *
 * Needs no database: the same autoloader-only harness as the unit suite. Not part of CI.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('SLATE_TESTING', true);
require __DIR__ . '/../../src/autoload.php';
require __DIR__ . '/../unit/harness.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\PageDocumentAssembler;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Render\Compile\DynamicSlotResolver;
use Slate\Tenancy\TenantContext;

$out = $argv[1] ?? '';
if ($out === '') { fwrite(STDERR, "usage: php tests/audit/render-widgets.php <out-dir>\n"); exit(2); }
if (!is_dir($out) && !mkdir($out, 0775, true)) { fwrite(STDERR, "cannot create {$out}\n"); exit(2); }

/** A picture for every media id, so image widgets render (an SVG data URI: no network, no file). */
final class AuditMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        $svg = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#7788aa"/></svg>');
        return new ResolvedMedia($mediaId, 'data:image/svg+xml,' . $svg, 1200, 800);
    }
}

$tenants   = new TenantContext();
$registry  = ModuleBlockDefinitions::studioRegistry();
$renderers = BlockRendererRegistry::withStudioRenderers();
$media     = new AuditMedia();
$documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
$compiler  = new StudioCompiler($tenants, $registry, $renderers, $documents, new ThemeResolver(null, static fn(): array => ['accent' => '#ff5500']), new ChromeResolver(), $media);
$assembler = new PageDocumentAssembler(null);
$slots     = new DynamicSlotResolver($documents);
$site      = new SiteContext('https://audit.test', 'Audit', '', '', 'en');
$tenantId  = 424242;
$ctx       = RenderContext::forPublic($tenantId, $site, static fn(): bool => true);
$page      = new PageAddress(5, '11111111-2222-4333-8444-555555555555', 'Audit', 'audit', 'page', 'published', 'standalone', 9, 9);

/** The probes: ONE style property each, in the shape the Style tab writes. Keep in step with ui/e2e-audit/probes.mjs. */
$probes = json_decode((string) file_get_contents(__DIR__ . '/probes.json'), true, 512, JSON_THROW_ON_ERROR);

/** A plausible value for one field, so a widget renders something visible (defaults leave many empty). */
function audit_sample(array $field, int $n = 1): mixed
{
    $key = (string) $field['key'];
    return match ($field['type']) {
        'string'    => str_contains($key, 'url') || $key === 'embed' ? 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' : ucfirst(str_replace('_', ' ', $key)) . ' ' . $n,
        'text'      => 'Sample paragraph of text for the ' . $key . ' field, long enough to look real. ' . $n,
        'rich_text' => '<p>Sample rich text paragraph ' . $n . '.</p>',
        'url'       => '/sample-' . $n,
        'link'      => ['label' => 'Sample link ' . $n, 'href' => '/sample-' . $n, 'target' => '_self'],
        'media_ref' => ['media_id' => $n, 'alt' => 'Sample image ' . $n],
        'number'    => $field['default'] ?? 3,
        'boolean'   => (bool) ($field['default'] ?? false),
        'enum'      => $field['default'] ?? ($field['allowed_values'][0] ?? null),
        'token_ref' => null,
        'repeater'  => array_map(static function (int $i) use ($field): array {
            $item = [];
            foreach ($field['item_schema'] ?? [] as $f) {
                $item[$f['key']] = audit_sample($f, $i);
            }
            return $item;
        }, [1, 2, 3]),
        'object'    => array_merge(is_array($field['default'] ?? null) ? $field['default'] : [], array_filter(array_map(static fn(array $f) => audit_sample($f, $n), array_column($field['properties'] ?? [], null, 'key')), static fn($v) => $v !== null)),
        default     => $field['default'] ?? null,
    };
}

/** Render one block alone and return its markup (the <main> contents) plus the page CSS, or null when it cannot render. */
$renderOne = static function (array $block) use ($tenants, $tenantId, $compiler, $assembler, $slots, $ctx, $page): ?string {
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Audit');
    $doc['containers'] = [CanonicalDocumentSchema::newContainer(['id' => 'blk_audit0000000000000000', 'metadata' => ['label' => 'a'], 'children' => [$block]])];
    try {
        $compiled = $tenants->runAs($tenantId, static fn() => $compiler->compile($page, ['id' => 9, 'page_id' => 5, 'document_json' => CanonicalJson::encode($doc)], $ctx));
        $html = $assembler->assemble($compiled, $slots->fill($compiled, $ctx), $ctx);
    } catch (\Throwable) {
        return null;
    }
    return preg_match('~<main[^>]*>(.*)</main>~s', $html, $m) === 1 ? $m[1] : $html;
};

$report = [];
$propEffects = [];
foreach ($registry->all() as $definition) {
    $type = $definition->type();
    $manifest = $definition->toEditorManifest();
    $props = [];
    foreach ($manifest['field_schema'] as $field) {
        $default = $field['default'] ?? null;
        $empty = $default === null || $default === '' || $default === [] || $default === '<p></p>';
        $value = $empty ? audit_sample($field) : $default;
        if ($value !== null) {
            $props[$field['key']] = $value;
        }
    }

    $blocks = [];
    foreach (array_merge(['base' => null], $probes) as $probeName => $style) {
        $block = [
            'bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => $props,
            'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1,
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        ];
        if ($style !== null) {
            $block['style'] = array_replace_recursive($block['style'], $style);
        }
        $block['_probe'] = (string) $probeName;
        $blocks[] = $block;
    }
    $blockIds = array_map(static fn(array $b): string => $b['id'], $blocks);
    foreach ($blocks as &$b) { unset($b['_probe']); } unset($b);

    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Audit ' . $type);
    $doc['containers'] = [];
    foreach ($blocks as $i => $b) {
        $doc['containers'][] = CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'probe-' . $i], 'children' => [$b]]);
    }
    try {
        $compiled = $tenants->runAs($tenantId, static fn() => $compiler->compile($page, ['id' => 9, 'page_id' => 5, 'document_json' => CanonicalJson::encode($doc)], $ctx));
        $html = $assembler->assemble($compiled, $slots->fill($compiled, $ctx), $ctx);
    } catch (\Throwable $e) {
        $report[$type] = ['error' => $e->getMessage()];
        continue;
    }
    file_put_contents($out . '/' . $type . '.html', $html);

    // Which content controls change nothing? Re-render with each field changed to another valid value; identical markup = a dead control.
    $fixedId = 'blk_auditfixed00000000000000';
    $mk = static fn(array $p): array => ['bindings' => [], 'children' => [], 'id' => $fixedId, 'props' => $p, 'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility()];
    $baseHtml = $renderOne($mk($props));
    if ($baseHtml !== null) {
        foreach ($manifest['field_schema'] as $field) {
            $variants = [];
            switch ($field['type']) {
                case 'enum':
                    foreach ($field['allowed_values'] as $v) { if ($v !== ($props[$field['key']] ?? null)) { $variants[] = $v; } }
                    break;
                case 'boolean':
                    $variants[] = !($props[$field['key']] ?? false);
                    break;
                case 'string': case 'text':
                    $variants[] = 'Changed ' . $field['key'] . ' value';
                    break;
                case 'rich_text':
                    $variants[] = '<p>Changed rich text</p>';
                    break;
                case 'number':
                    $v = (int) ($props[$field['key']] ?? 3);
                    $variants[] = min((int) ($field['max'] ?? $v + 1), max((int) ($field['min'] ?? 0), $v === (int) ($field['max'] ?? PHP_INT_MAX) ? $v - 1 : $v + 1));
                    break;
                case 'token_ref':
                    $variants[] = 'surface.accent';
                    $variants[] = 'text.accent';
                    break;
                default:
                    break;
            }
            $effects = [];
            foreach ($variants as $v) {
                $changed = $renderOne($mk(array_merge($props, [$field['key'] => $v])));
                $effects[] = ['value' => is_scalar($v) ? (string) $v : json_encode($v), 'changes' => $changed !== null && $changed !== $baseHtml];
            }
            if ($effects !== []) {
                $propEffects[$type][$field['key']] = ['type' => $field['type'], 'label' => $field['label'] ?? $field['key'], 'tries' => $effects, 'dead' => count(array_filter($effects, static fn($e) => $e['changes'])) === 0];
            }
        }
    }
    $report[$type] = ['probes' => array_keys(array_merge(['base' => 1], $probes)), 'containers' => count($doc['containers'])];
}
file_put_contents($out . '/props.json', json_encode($propEffects, JSON_PRETTY_PRINT));
file_put_contents($out . '/index.json', json_encode(['widgets' => $report, 'probes' => array_keys($probes)], JSON_PRETTY_PRINT));
echo count($report) . " widgets rendered into {$out}\n";
