<?php
/**
 * Kohevo Studio — `theme.repeater` renderer: one repeater field of the page being shown. With children, they are drawn
 * once per row, and a Field value inside reads that row's sub-fields. Without children it draws the rows itself, as a
 * list or an accordion.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\RepeatingChildrenInterface;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RichTextSanitizer;

final class RepeaterRenderer implements BlockRendererInterface, RepeatingChildrenInterface
{
    public function type(): string
    {
        return 'theme.repeater';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function childContexts(array $block, RenderContext $context): array
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        [$field, $rows] = self::rowsOf($context, is_string($props['field'] ?? null) ? $props['field'] : '');
        $parent = $context->attribute('page_meta');
        $contexts = [];
        foreach ($rows as $row) {
            $contexts[] = $context->withAttributes(['page_meta' => ['fields' => MetaFieldTypes::subfields($field ?? []), 'values' => $row] + (is_array($parent) ? $parent : [])]);
        }
        // The author can still pick and style the row on a page with no rows yet.
        return $contexts === [] && $context->emitsNodeMetadata() ? [$context] : $contexts;
    }

    public function render(BlockRenderScope $scope): string
    {
        $key = $scope->string('field');
        [$field, $rows] = self::rowsOf($scope->context(), $key);
        $children = $scope->childrenHtml();
        if ($children !== '') {
            return '<div class="sb-repeater">' . $children . '</div>';
        }
        if ($rows === []) {
            return $scope->showsDiagnostics() ? '<em>' . Html::e($key === '' ? 'Choose a repeater field.' : 'No rows in "' . $key . '" on this page.') . '</em>' : '';
        }
        $subs = array_values(array_map(static fn(array $f): string => (string) $f['key'], MetaFieldTypes::subfields($field ?? [])));
        $title = $scope->string('title_field') ?: ($subs[0] ?? '');
        if ($scope->string('display', 'list') === 'accordion') {
            $body = $scope->string('body_field') ?: ($subs[1] ?? '');
            $items = [];
            foreach ($rows as $row) {
                $items[] = ['heading' => is_scalar($row[$title] ?? null) ? (string) $row[$title] : '', 'body' => self::rich($row[$body] ?? '')];
            }
            return AccordionRenderer::markup($scope, $items, $scope->bool('allow_multiple'));
        }
        $list = '';
        foreach ($rows as $row) {
            $list .= '<li>' . Html::e(is_scalar($row[$title] ?? null) ? (string) $row[$title] : '') . '</li>';
        }
        return '<ul class="sb-repeater__list">' . $list . '</ul>';
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: list<array<string, mixed>>} the field and its rows on the page shown
     */
    private static function rowsOf(RenderContext $context, string $key): array
    {
        $meta = $context->attribute('page_meta');
        $meta = is_array($meta) ? $meta : [];
        foreach (is_array($meta['fields'] ?? null) ? $meta['fields'] : [] as $f) {
            if (is_array($f) && ($f['key'] ?? null) === $key && MetaFieldTypes::normalize((string) ($f['type'] ?? '')) === 'repeater') {
                $value = is_array($meta['values'] ?? null) ? ($meta['values'][$key] ?? null) : null;
                return [$f, is_array($value) ? array_values(array_filter($value, 'is_array')) : []];
            }
        }
        return [null, []];
    }

    /** Rich text as stored, re-cleaned; a plain value keeps its line breaks. */
    public static function rich(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return preg_match('/<[a-z][^>]*>/i', $value) === 1 ? RichTextSanitizer::sanitize($value, true) : nl2br(Html::e($value), false);
    }
}
