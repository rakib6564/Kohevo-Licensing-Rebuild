<?php
/**
 * Kohevo Studio — `core.table` renderer: a simple data table.
 *
 * Authoring model: one header line and one line per row, cells separated by `|`. Rows are padded or trimmed to
 * the header's column count (at most MAX_COLUMNS), so a ragged row can never produce a broken table.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class TableRenderer implements BlockRendererInterface
{
    public const MAX_COLUMNS = 8;

    public function type(): string
    {
        return 'core.table';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /** @return list<string> */
    private static function cells(string $line): array
    {
        return array_slice(array_map('trim', explode('|', $line)), 0, self::MAX_COLUMNS);
    }

    public function render(BlockRenderScope $scope): string
    {
        $header = self::cells($scope->string('header'));
        if (count($header) === 1 && $header[0] === '') {
            $header = [];
        }

        $rows = [];
        foreach ((array) $scope->prop('rows', []) as $row) {
            $line = is_array($row) && is_string($row['cells'] ?? null) ? $row['cells'] : '';
            if (trim($line) !== '') {
                $rows[] = self::cells($line);
            }
        }
        $columns = $header !== [] ? count($header) : max(array_map('count', $rows) ?: [0]);
        if ($columns === 0) {
            return '';
        }

        $head = '';
        if ($header !== []) {
            $head = '<thead><tr>';
            foreach ($header as $cell) {
                $head .= '<th scope="col">' . Html::e($cell) . '</th>';
            }
            $head .= '</tr></thead>';
        }

        $body = '<tbody>';
        foreach ($rows as $cells) {
            $body .= '<tr>';
            for ($i = 0; $i < $columns; $i++) {
                $body .= '<td>' . Html::e($cells[$i] ?? '') . '</td>';
            }
            $body .= '</tr>';
        }
        $body .= '</tbody>';

        $caption = trim($scope->string('caption'));
        return '<div' . Html::classAttr(['sb-table-wrap', $scope->bool('striped', true) ? 'sb-table-wrap--striped' : '']) . '>'
            . '<table class="sb-table">'
            . ($caption !== '' ? '<caption class="sb-table__caption">' . Html::e($caption) . '</caption>' : '')
            . $head . $body . '</table></div>';
    }
}
