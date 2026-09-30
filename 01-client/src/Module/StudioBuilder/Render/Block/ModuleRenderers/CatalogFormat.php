<?php
/**
 * Kohevo Studio — shared, escaping-safe formatting for provider catalogue rows.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class CatalogFormat
{
    public static function price(mixed $cents, mixed $currency): string
    {
        if (!is_int($cents) || $cents < 0) {
            return '';
        }
        $code = is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) === 1 ? strtoupper($currency) : '';
        return trim(number_format($cents / 100, 2, '.', ',') . ' ' . $code);
    }

    public static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? '';
        return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '');
    }

    /**
     * The shared "no data" state: a provider that was denied, failed, or is not
     * bound renders nothing publicly (no hint about licensing or module state)
     * and a short notice in authoring contexts.
     */
    public static function unavailable(BlockRenderScope $scope): string
    {
        return $scope->showsDiagnostics()
            ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_data_unavailable', 'Data unavailable in this context')) . '</div>'
            : '';
    }

    public static function empty(string $message): string
    {
        return '<p class="sb-empty">' . Html::e($message) . '</p>';
    }
}
