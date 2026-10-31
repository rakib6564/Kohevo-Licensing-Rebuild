<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the form submit pipeline.
 *
 * Autoloader only, no database. Reuses sbdc_block / sbdc_render from StudioBuilderDeviceColumnsTest (loaded first,
 * alphabetically).
 *
 * A `core.form` without an Action URL becomes a Forms form on publish (StudioFormSync) and posts to its route; its fields
 * render every type with real options, and share a row by their width.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormFieldRenderer;
use Slate\Module\StudioBuilder\Service\StudioFormSync;

function sbfp_field(array $props, array $responsive = []): array
{
    return sbdc_block('core.form_field', $props + ['name' => 'f', 'label' => 'F'], $responsive);
}

function sbfp_form(array $props = [], array $fields = []): array
{
    return sbdc_block('core.form', $props, [], $fields);
}

unit('form pipeline: a Studio form maps to a Forms definition with its fields in order and its after-submit settings', function (): void {
    $form = sbfp_form(['form_name' => 'contact_form', 'submit_text' => 'Send', 'success_message' => 'Got it', 'redirect_url' => '/thanks', 'notify_email' => 'owner@example.test'], [
        sbfp_field(['name' => 'Full Name', 'label' => 'Name', 'required' => true, 'placeholder' => 'Jane']),
        sbdc_block('core.container', [], [], [sbfp_field(['name' => 'topic', 'label' => 'Topic', 'field_type' => 'radio', 'options' => "Sales, Support\nBilling"])]),
        sbfp_field(['name' => 'agree', 'label' => 'I agree', 'field_type' => 'checkbox', 'required' => true]),
    ]);
    $def = StudioFormSync::definitionFor($form, 'Contact');
    assert_eq(StudioFormSync::slugFor($form['id']), $def['slug']);
    assert_true(preg_match('/^sb-[a-z0-9]+$/', $def['slug']) === 1, 'a slug Forms accepts: ' . $def['slug']);
    assert_eq('Contact · contact form', $def['title']);
    assert_eq([
        ['type' => 'text', 'name' => 'full_name', 'label' => 'Name', 'required' => true, 'placeholder' => 'Jane'],
        ['type' => 'radio', 'name' => 'topic', 'label' => 'Topic', 'required' => false, 'options' => ['Sales', 'Support', 'Billing']],
        ['type' => 'checkbox', 'name' => 'agree', 'label' => 'I agree', 'required' => true],
    ], $def['fields'], 'fields inside a container count too');
    assert_eq(['Send', 'Got it', '/thanks', 'owner@example.test'], [$def['submit_label'], $def['success_message'], $def['redirect_url'], $def['notify_email']]);

    $bad = StudioFormSync::definitionFor(sbfp_form(['redirect_url' => 'javascript:alert(1)', 'notify_email' => 'not an email']));
    assert_eq([null, null], [$bad['redirect_url'], $bad['notify_email']], 'an unsafe redirect and a broken address are dropped');
    $offsite = StudioFormSync::definitionFor(sbfp_form(['redirect_url' => '//evil.test/x']));
    assert_eq(null, $offsite['redirect_url'], 'a protocol-relative address is not a same-site path');

    $doc = ['containers' => [['blocks' => [sbfp_form(), sbfp_form(['action' => 'https://example.test/hook'])]]]];
    assert_eq(1, count(StudioFormSync::formsIn($doc)), 'a form with its own Action URL is left alone');
});

unit('form pipeline: a form without an Action URL posts to Forms, with a honeypot, a status line and the runtime\'s words', function (): void {
    $form = sbfp_form(['success_message' => 'Merci'], [sbfp_field(['name' => 'email', 'label' => 'Email', 'field_type' => 'email', 'required' => true])]);
    $html = sbdc_render($form);
    $slug = StudioFormSync::slugFor($form['id']);
    assert_true(str_contains($html, '<form class="sb-form" action="https://example.test/forms/' . $slug . '" method="post"'), $html);
    assert_true(str_contains($html, 'data-sb-form-slug="' . $slug . '"') && str_contains($html, 'enctype="multipart/form-data"'), 'the runtime finds it, and files can go');
    assert_true(str_contains($html, 'name="hp_field" tabindex="-1"') && str_contains($html, 'class="sb-hp" aria-hidden="true"'), 'honeypot out of sight');
    assert_true(str_contains($html, 'role="status" aria-live="polite" data-sb-form-status data-sb-success="Merci" hidden'), 'status line');
    assert_true(str_contains($html, 'data-sb-label-sending="Sending…"'), 'translated runtime words');
    assert_true(str_contains($html, 'autocomplete="email"') && str_contains($html, 'data-sb-field-error hidden'), 'an email field and its error slot');

    $pill = sbdc_render(sbfp_form(['layout' => 'inline', 'success_message' => 'Merci'], [sbfp_field(['name' => 'email', 'label' => 'Email', 'field_type' => 'email'])]));
    assert_true(str_contains($pill, '</form><div class="sb-form__status" role="status"'), 'an inline form keeps its status line under it, outside the pill: ' . $pill);

    $custom = sbdc_render(sbfp_form(['action' => 'https://example.test/hook']));
    assert_true(str_contains($custom, 'action="https://example.test/hook"') && !str_contains($custom, 'data-sb-form-slug'), 'an Action URL posts there as before');
});

unit('form pipeline: radio and checkbox groups use their options, one checkbox is a tick, and every new type renders', function (): void {
    $radio = sbdc_render(sbfp_field(['name' => 'topic', 'label' => 'Topic', 'field_type' => 'radio', 'options' => 'Sales, Support', 'required' => true, 'default_value' => 'Support']));
    assert_eq(2, substr_count($radio, 'type="radio" name="topic"'), 'one radio per option: ' . $radio);
    assert_true(str_contains($radio, '<fieldset class="sb-form-choices"><legend>Topic <span class="sb-required" aria-hidden="true">*</span></legend>'), 'a group with its legend');
    assert_true(str_contains($radio, 'value="Support" checked required>'), 'the default is checked, and the group is required');

    $boxes = sbdc_render(sbfp_field(['name' => 'extras', 'label' => 'Extras', 'field_type' => 'checkboxes', 'options' => "A\nB\nC"]));
    assert_eq(3, substr_count($boxes, 'type="checkbox" name="extras[]"'), 'several checkboxes post a list');

    $tick = sbdc_render(sbfp_field(['name' => 'agree', 'label' => 'I agree', 'field_type' => 'checkbox']));
    assert_true(str_contains($tick, '<input type="checkbox" name="agree"') && str_contains($tick, 'value="1"'), 'one acceptance tick');

    foreach (['date', 'time', 'file'] as $type) {
        assert_true(str_contains(sbdc_render(sbfp_field(['field_type' => $type])), 'type="' . $type . '"'), $type);
    }
    $hidden = sbdc_render(sbfp_field(['name' => 'source', 'field_type' => 'hidden', 'default_value' => 'landing']));
    assert_true(str_contains($hidden, 'sb-form-hidden') && str_contains($hidden, '<input type="hidden" name="source" value="landing">'), $hidden);
    assert_eq('field_2nd', FormFieldRenderer::fieldName('2nd'), 'a name Forms accepts always starts with a letter');
});

unit('form pipeline: fields share a row by their width, and a phone stacks them unless set per device', function (): void {
    $half = sbdc_render(sbfp_field(['width' => '50']));
    assert_true((bool) preg_match('/class="[^"]*\bsb-fw\b[^"]*"[^>]*style="--sb-fw-d:.5;--sb-fw-t:.5;--sb-fw-m:1"/', $half), $half);
    $set = sbdc_render(sbfp_field(['width' => '33'], ['mobile' => ['props' => ['width' => '50']], 'tablet' => ['props' => ['width' => '100']]]));
    assert_true(str_contains($set, '--sb-fw-d:.3333;--sb-fw-t:1;--sb-fw-m:.5'), $set);
    \Slate\Module\StudioBuilder\Render\StudioStylesheet::resetCache();
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-form>.sb-fw{flex-basis:calc((100% + 1rem)*var(--sb-fw-d,1) - 1rem)}'), 'a share of the row less its share of the gap');
});

unit('form pipeline: Forms answers a Studio form in JSON and hands it a token, and the runtime submits in place', function (): void {
    $router = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/forms/public/router.php');
    assert_true(str_contains($router, "if (\$action === 'token')") && str_contains($router, 'FormsSpamGuard::timeTrapValue()'), 'a token and a time-trap stamp');
    assert_true(str_contains($router, "'Cache-Control: private, no-store, max-age=0'"), 'never cached');
    assert_true(str_contains($router, "forms_public_json(['ok' => false"), 'errors as JSON');
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "qsa(root, 'form[data-sb-form-slug]')") && str_contains($js, "'/token'") && str_contains($js, "Accept: 'application/json'"), 'the runtime');
    assert_true(str_contains($js, "setAttribute('aria-invalid', 'true')"), 'errors are announced on the field');
});
