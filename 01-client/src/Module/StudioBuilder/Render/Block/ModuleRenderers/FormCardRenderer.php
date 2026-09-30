<?php
/**
 * Kohevo Studio — `forms.form_card` renderer.
 *
 * Shows a PUBLISHED form's public catalogue metadata (the `forms.form`
 * provider never returns drafts) and links to the Forms module's own public
 * route `/forms/<slug>`. The actual form (fields, validation, submission,
 * CSRF, uploads) stays owned by the Forms module — Studio does not render or
 * accept form fields itself, so no second submission path exists.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class FormCardRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'forms.form_card';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->rows('form');
        if ($rows === null || $rows === []) {
            return CatalogFormat::unavailable($scope);
        }
        $form = $rows[0];
        $slug = CatalogFormat::str($form, 'slug');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $slug) !== 1) {
            return CatalogFormat::unavailable($scope);
        }

        $label = $scope->string('button_label');
        if ($label === '') {
            $label = Html::t('studio_open_form', 'Open form');
        }
        $desc = $scope->bool('show_description', true) ? CatalogFormat::str($form, 'description') : '';

        return '<div class="sb-form-card">'
            . '<h3 class="sb-form-card__title">' . Html::e(CatalogFormat::str($form, 'title')) . '</h3>'
            . ($desc !== '' ? '<p class="sb-form-card__description">' . Html::text($desc) . '</p>' : '')
            . '<p class="sb-button-row"><a class="sb-button sb-button--primary" href="' . Html::e($scope->site()->absoluteUrl('forms/' . rawurlencode($slug))) . '">' . Html::e($label) . '</a></p>'
            . '</div>';
    }
}
