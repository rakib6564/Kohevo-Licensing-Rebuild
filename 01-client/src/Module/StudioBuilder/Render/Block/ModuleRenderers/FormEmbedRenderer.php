<?php
/**
 * Kohevo Studio — `forms.embed` renderer.
 *
 * Puts ONE published form of the site on the page, in a frame onto the Forms module's own `/forms/<slug>?embed=1`
 * page, so fields, validation, CSRF, spam guard, uploads and submission stay owned by Forms: it POSTs to its own
 * `/forms/<slug>` route and Studio never accepts or stores a submission, so no second submission path exists. The `forms.form_embed` provider has already re-checked, for the
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
use Slate\Module\StudioBuilder\Render\EmbedStyle;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class FormEmbedRenderer implements BlockRendererInterface
{
    /** Room for a short form before its page has loaded and sized the frame. */
    private const MIN_HEIGHT = 420;

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
        return $this->liveForm($scope, $slug, CatalogFormat::str($form, 'title'));
    }

    /** @param array<string, mixed> $form */
    private function previewCard(array $form): string
    {
        $desc = CatalogFormat::str($form, 'description');
        $submit = CatalogFormat::str($form, 'submit_label');
        $labels = array_values(array_filter(explode("\n", CatalogFormat::str($form, 'field_labels')), static fn(string $l): bool => $l !== ''));
        $kinds = explode("\n", CatalogFormat::str($form, 'field_kinds'));
        $fields = '';
        foreach ($labels as $i => $label) {
            $control = ($kinds[$i] ?? '') === 'textarea' ? 'sb-embed-input sb-embed-input--area' : 'sb-embed-input';
            $fields .= '<div class="field"><span class="field-label">' . Html::e($label) . '</span><span class="' . $control . '"></span></div>';
        }

        return '<div class="sb-form-card sb-embed-card forms-public-card">'
            . '<p class="sb-embed-card__kind">' . Html::e(Html::t('studio_embed_form_kind', 'Form')) . '</p>'
            . '<h3 class="sb-form-card__title">' . Html::e(CatalogFormat::str($form, 'title')) . '</h3>'
            . ($desc !== '' ? '<p class="sb-form-card__description">' . Html::text($desc) . '</p>' : '')
            . $fields
            . '<p class="forms-submit-row"><span class="btn btn-primary">' . Html::e($submit !== '' ? $submit : Html::t('studio_embed_form_submit', 'Send')) . '</span></p>'
            . '<p class="sb-embed-card__note">' . Html::e(Html::t('studio_embed_form_note', 'Visitors see the working form here. Preview the page to try it.')) . '</p>'
            . '</div>';
    }

    /**
     * The form is the Forms module's own public page in a same-origin frame. Studio's public pages are anonymous and
     * cacheable (no session cookie), so a CSRF-protected form cannot be rendered into one: its token would belong to no
     * visitor. The frame is a page of its own that starts its own session, is never cached, validates, stores and
     * notifies by itself, and sizes the frame from inside (same origin). Booking embeds the same way.
     */
    private function liveForm(BlockRenderScope $scope, string $slug, string $title): string
    {
        if (!class_exists('FormsAPI')) {
            return CatalogFormat::unavailable($scope);
        }
        $style = EmbedStyle::publish($scope, $scope->themeRootCss());
        $src = $scope->site()->absoluteUrl('forms/' . rawurlencode($slug)) . '?embed=1'
            . ($scope->string('look', 'card') === 'flat' ? '&chrome=0' : '')
            . ($style !== '' ? '&' . EmbedStyle::PARAM . '=' . $style : '');
        return '<iframe class="sb-embed-frame sb-embed-frame--form" src="' . Html::e($src) . '"'
            . ' title="' . Html::e($title !== '' ? $title : Html::t('studio_embed_form_kind', 'Form')) . '"'
            . ' style="min-height:' . self::MIN_HEIGHT . 'px" loading="lazy"></iframe>';
    }
}
