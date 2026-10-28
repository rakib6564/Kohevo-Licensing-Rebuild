<?php
/**
 * Kohevo Studio — `core.form` renderer.
 *
 * A form with no Action URL of its own is a Forms form (StudioFormSync writes it on publish): it posts to Forms' route
 * `/forms/sb-<block id>`, and Forms validates, guards against spam, stores and notifies. Studio's public pages are
 * cached and carry no session, so the page cannot hold a CSRF token: studio-runtime.js fetches one from Forms when the
 * visitor starts on the form, submits in place and shows Forms' answer (the success message, or each field's error).
 * Without JavaScript the post still reaches Forms, which shows its own page of the form to finish there.
 *
 * A form with an Action URL posts there, as it always did.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Service\StudioFormSync;

final class FormRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.form';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $action = $scope->string('action');
        $method = strtolower($scope->string('method', 'post')) === 'get' ? 'get' : 'post';
        $formName = $scope->string('form_name', 'contact_form');
        $submitText = $scope->string('submit_text', 'Submit');
        $submitVariant = $scope->string('submit_variant', 'primary');
        if (!in_array($submitVariant, ['primary', 'secondary', 'outline'], true)) {
            $submitVariant = 'primary';
        }
        $actions = '<div class="sb-form__actions">'
            . '<button type="submit" class="sb-button sb-button--' . $submitVariant . '">' . Html::e($submitText) . '</button>'
            . '</div>';

        $formClass = 'sb-form' . ($scope->string('labels', 'visible') === 'hidden' ? ' sb-form--labels-hidden' : '')
            . ($scope->string('layout', 'stacked') === 'inline' ? ' sb-form--inline' : '');

        if (trim($action) !== '') {
            return '<form class="' . $formClass . '" action="' . Html::e($action) . '" method="' . $method . '" data-sb-form="' . Html::e($formName) . '">'
                . '<input type="hidden" name="form_name" value="' . Html::e($formName) . '">'
                . $scope->childrenHtml() . $actions . '</form>';
        }

        $slug = StudioFormSync::slugFor($scope->nodeId());
        $note = '';
        if ($scope->showsDiagnostics()) {
            $note = '<p class="sb-form__note" role="note">' . Html::e(StudioFormSync::available()
                ? Html::t('studio_form_note', 'Submissions go to Forms › Submissions once the page is published.')
                : Html::t('studio_form_note_off', 'Turn on the Forms module so this form can receive submissions.')) . '</p>';
        }
        $success = $scope->string('success_message', '');
        return '<form class="' . $formClass . '" action="' . Html::e($scope->site()->absoluteUrl('forms/' . $slug)) . '" method="post"'
            . ' data-sb-form="' . Html::e($formName) . '" data-sb-form-slug="' . Html::e($slug) . '" enctype="multipart/form-data"'
            // The runtime's own words, translated here so a French page stays French.
            . ' data-sb-label-sending="' . Html::e(Html::t('studio_form_sending', 'Sending…')) . '"'
            . ' data-sb-label-failed="' . Html::e(Html::t('studio_form_failed', 'The form could not be sent. Check your connection and try again.')) . '">'
            . $note
            // Honeypot: hidden from people and assistive technology; Forms drops a submission that fills it.
            . '<div class="sb-hp" aria-hidden="true"><label>Website <input type="text" name="hp_field" tabindex="-1" autocomplete="off"></label></div>'
            . '<input type="hidden" name="return_to" value="" data-sb-return-to>'
            . $scope->childrenHtml()
            . '<div class="sb-form__status" role="status" aria-live="polite" data-sb-form-status'
            . ($success !== '' ? ' data-sb-success="' . Html::e($success) . '"' : '') . ' hidden></div>'
            . $actions . '</form>';
    }
}
