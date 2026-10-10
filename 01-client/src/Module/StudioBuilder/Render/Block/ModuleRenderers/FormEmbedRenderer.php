<?php
/**
 * Kohevo Studio — `forms.embed` renderer.
 *
 * Puts ONE published form of the site on the page. The form itself is rendered
 * by the Forms module (`FormsAPI::renderContentBlock`, the same call its own
 * content block uses), so fields, validation, CSRF, spam guard, uploads and
 * submission stay owned by Forms: it POSTs to its own `/forms/<slug>` route and
 * Studio never accepts or stores a submission, so no second submission path
 * exists. The `forms.form_embed` provider has already re-checked, for the
 * active tenant, that the form exists and is published.
 *
 * The Editor canvas runs no script (`script-src 'none'`), so there it shows a
 * static preview card — the form's name, description and field labels — and
 * never calls the Forms renderer. Preview and Public get the live form.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class FormEmbedRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'forms.embed';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->rows('form');
        if ($rows === null || $rows === []) {
            return $scope->showsDiagnostics()
                ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_embed_form_missing', 'Choose a published form in the Inspector.')) . '</div>'
                : '';
        }
        $form = $rows[0];
        $slug = CatalogFormat::str($form, 'slug');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $slug) !== 1) {
            return CatalogFormat::unavailable($scope);
        }

        if ($scope->mode() === RenderMode::Editor) {
            return $this->previewCard($form);
        }
        return $this->liveForm($scope, $slug);
    }

    /** @param array<string, mixed> $form */
    private function previewCard(array $form): string
    {
        $desc = CatalogFormat::str($form, 'description');
        $labels = array_values(array_filter(explode("\n", CatalogFormat::str($form, 'field_labels')), static fn(string $l): bool => $l !== ''));
        $items = '';
        foreach ($labels as $label) {
            $items .= '<li>' . Html::e($label) . '</li>';
        }

        return '<div class="sb-form-card sb-embed-card">'
            . '<p class="sb-embed-card__kind">' . Html::e(Html::t('studio_embed_form_kind', 'Form')) . '</p>'
            . '<h3 class="sb-form-card__title">' . Html::e(CatalogFormat::str($form, 'title')) . '</h3>'
            . ($desc !== '' ? '<p class="sb-form-card__description">' . Html::text($desc) . '</p>' : '')
            . ($items !== '' ? '<ul class="sb-embed-card__fields">' . $items . '</ul>' : '')
            . '<p class="sb-embed-card__note">' . Html::e(Html::t('studio_embed_form_note', 'Visitors see the working form here. Preview the page to try it.')) . '</p>'
            . '</div>';
    }

    private function liveForm(BlockRenderScope $scope, string $slug): string
    {
        if (!class_exists('FormsAPI')) {
            return CatalogFormat::unavailable($scope);
        }
        try {
            $html = (string) \FormsAPI::renderContentBlock(['formSlug' => $slug]);
        } catch (\Throwable $failure) {
            return CatalogFormat::unavailable($scope);
        }
        if ($html === '') {
            return CatalogFormat::unavailable($scope);
        }

        // The form's own stylesheet and field logic, once per page (the Forms content block gets them from the page head).
        $assets = '';
        if ($scope->claimOnce('forms-assets')) {
            $v = defined('FormsAPI::ASSET_VERSION') ? '?v=' . rawurlencode((string) \FormsAPI::ASSET_VERSION) : '';
            $site = $scope->site();
            $assets = '<link rel="stylesheet" href="' . Html::e($site->absoluteUrl('plugins/forms/assets/css/public.css') . $v) . '">'
                . '<script src="' . Html::e($site->absoluteUrl('plugins/forms/assets/js/forms-logic.js') . $v) . '" defer></script>';
        }
        return $assets . $html;
    }
}
