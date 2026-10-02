<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 7: Advanced UI, Interactions, Forms & Media.
 *
 * Verifies Sprint 7 requirements (Section 63 & Sections 22, 23, 24, 30):
 *  - BlockRegistry registers core.modal, layout.offcanvas, core.form, core.form_field, core.gallery, core.video.
 *  - DocumentValidator accepts valid interactions and animation on blocks and rejects invalid schemas.
 *  - ModalRenderer outputs dialog markup, trigger button, accessibility attributes, and children.
 *  - OffcanvasRenderer outputs drawer markup, position class, trigger button, and accessibility attributes.
 *  - FormRenderer and FormFieldRenderer output form elements, inputs, textareas, selects, and submit button.
 *  - GalleryRenderer and VideoRenderer output responsive media grids and sanitized embed markup.
 *  - DocumentRenderer compiles animation and interaction classes and data attributes.
 *  - StudioStylesheet contains keyframes, modal, offcanvas, form, and reduced motion CSS rules.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormFieldRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\GalleryRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ModalRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\OffcanvasRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\VideoRenderer;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

function s7_scope(array $block, string $children = ''): BlockRenderScope
{
    $tenants = new TenantContext();
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    $theme = new ResolvedTheme('default', []);
    $collector = new RenderCollector();
    $media = new CoreMediaResolver($tenants);
    return new BlockRenderScope($block, $context, $media, $theme, $collector, $children, []);
}

unit('sprint7 unit: BlockRegistry registers core.modal, layout.offcanvas, core.form, core.form_field, core.gallery, core.video', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();

    $expectedTypes = [
        'core.modal',
        'layout.offcanvas',
        'core.form',
        'core.form_field',
        'core.gallery',
        'core.video',
    ];

    foreach ($expectedTypes as $type) {
        $def = $registry->get($type);
        assert_true($def !== null, "Block type '{$type}' is registered in BlockRegistry");
        assert_eq(1, $def->version());
    }

    assert_true($registry->get('core.modal')->allowsChildren(), 'core.modal allows children');
    assert_true($registry->get('layout.offcanvas')->allowsChildren(), 'layout.offcanvas allows children');
    assert_true($registry->get('core.form')->allowsChildren(), 'core.form allows children');
    assert_false($registry->get('core.form_field')->allowsChildren(), 'core.form_field does not allow children');
    assert_false($registry->get('core.gallery')->allowsChildren(), 'core.gallery does not allow children');
    assert_false($registry->get('core.video')->allowsChildren(), 'core.video does not allow children');
});

unit('sprint7 unit: DocumentValidator accepts valid interactions and animation schemas and rejects invalid ones', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();

    $validBlock = [
        'id'           => CanonicalDocumentSchema::newBlockId(),
        'type'         => 'core.heading',
        'version'      => 1,
        'props'        => ['text' => 'Animated Heading', 'level' => 'h2'],
        'style'        => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility'   => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'     => [],
        'children'     => [],
        'animation'    => [
            'type'     => 'fade_up',
            'duration' => 500,
        ],
        'interactions' => [
            'trigger'  => 'hover',
            'animation'=> ['type' => 'scale_up'],
        ],
    ];

    $doc = CanonicalDocumentSchema::emptyDocument();
    $doc['sections'][] = [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Section 1',
        'global_ref' => null,
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => [$validBlock],
    ];

    $result = DocumentValidator::validate($doc, $registry);
    assert_true($result->isValid(), 'Document with valid animation and interactions is valid');

    // Invalid interaction trigger
    $doc['sections'][0]['blocks'][0]['interactions']['trigger'] = 'invalid_trigger_name';
    $resInvalid = DocumentValidator::validate($doc, $registry);
    assert_false($resInvalid->isValid(), 'Invalid interaction trigger is rejected');
    $codes = array_column($resInvalid->errors(), 'code');
    assert_true(in_array('invalid_interaction_trigger', $codes, true), 'Flags invalid_interaction_trigger');

    // Invalid animation type
    $doc['sections'][0]['blocks'][0]['interactions']['trigger'] = 'hover';
    $doc['sections'][0]['blocks'][0]['animation']['type'] = 'fly_to_moon';
    $resInvalidAnim = DocumentValidator::validate($doc, $registry);
    assert_false($resInvalidAnim->isValid(), 'Invalid animation type is rejected');
    $codesAnim = array_column($resInvalidAnim->errors(), 'code');
    assert_true(in_array('invalid_animation_type', $codesAnim, true), 'Flags invalid_animation_type');
});

unit('sprint7 unit: ModalRenderer outputs dialog markup, trigger button, and aria attributes', function (): void {
    $renderer = new ModalRenderer();
    assert_eq('core.modal', $renderer->type());
    assert_false($renderer->isDynamic());

    $block = [
        'id'       => CanonicalDocumentSchema::newBlockId(),
        'type'     => 'core.modal',
        'version'  => 1,
        'props'    => [
            'modal_id'        => 'contact-modal',
            'title'           => 'Get in Touch',
            'size'            => 'md',
            'trigger_text'    => 'Open Contact Form',
            'trigger_variant' => 'primary',
        ],
    ];

    $scope = s7_scope($block, '<p>Modal Body Content</p>');
    $html = $renderer->render($scope);

    assert_true(str_contains($html, 'data-sb-modal-open="contact-modal"'), 'Contains modal open trigger');
    assert_true(str_contains($html, 'Open Contact Form'), 'Contains trigger text');
    assert_true(str_contains($html, 'id="contact-modal"'), 'Contains modal ID');
    assert_true(str_contains($html, 'role="dialog"'), 'Contains role="dialog"');
    assert_true(str_contains($html, 'aria-modal="true"'), 'Contains aria-modal="true"');
    assert_true(str_contains($html, 'Get in Touch'), 'Contains modal title');
    assert_true(str_contains($html, 'data-sb-modal-close'), 'Contains close button');
    assert_true(str_contains($html, '<p>Modal Body Content</p>'), 'Contains rendered children');
});

unit('sprint7 unit: OffcanvasRenderer outputs drawer markup, position class, trigger button, and accessibility attributes', function (): void {
    $renderer = new OffcanvasRenderer();
    assert_eq('layout.offcanvas', $renderer->type());
    assert_false($renderer->isDynamic());

    $block = [
        'id'       => CanonicalDocumentSchema::newBlockId(),
        'type'     => 'layout.offcanvas',
        'version'  => 1,
        'props'    => [
            'drawer_id'       => 'mobile-nav-drawer',
            'title'           => 'Navigation Menu',
            'position'        => 'left',
            'trigger_text'    => '☰ Menu',
            'trigger_variant' => 'outline',
        ],
    ];

    $scope = s7_scope($block, '<ul><li>Home</li><li>About</li></ul>');
    $html = $renderer->render($scope);

    assert_true(str_contains($html, 'data-sb-offcanvas-open="mobile-nav-drawer"'), 'Contains offcanvas trigger');
    assert_true(str_contains($html, '☰ Menu'), 'Contains trigger text');
    assert_true(str_contains($html, 'id="mobile-nav-drawer"'), 'Contains drawer ID');
    assert_true(str_contains($html, 'sb-offcanvas--left'), 'Contains position class');
    assert_true(str_contains($html, 'role="dialog"'), 'Contains role="dialog"');
    assert_true(str_contains($html, 'Navigation Menu'), 'Contains title');
    assert_true(str_contains($html, 'data-sb-offcanvas-close'), 'Contains close button');
    assert_true(str_contains($html, '<ul><li>Home</li><li>About</li></ul>'), 'Contains children');
});

unit('sprint7 unit: FormRenderer and FormFieldRenderer output form elements, fields, and submit button', function (): void {
    $formR = new FormRenderer();
    $fieldR = new FormFieldRenderer();

    // Form field text
    $f1 = [
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'core.form_field',
        'version' => 1,
        'props'   => [
            'name'        => 'full_name',
            'label'       => 'Full Name',
            'field_type'  => 'text',
            'placeholder' => 'Jane Doe',
            'required'    => true,
        ],
    ];
    $scope1 = s7_scope($f1);
    $html1 = $fieldR->render($scope1);
    assert_true(str_contains($html1, 'name="full_name"'), 'Contains field name');
    assert_true(str_contains($html1, 'Full Name'), 'Contains label');
    assert_true(str_contains($html1, 'placeholder="Jane Doe"'), 'Contains placeholder');
    assert_true(str_contains($html1, 'required'), 'Contains required attribute');

    // Form field select
    $f2 = [
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'core.form_field',
        'version' => 1,
        'props'   => [
            'name'        => 'service_tier',
            'label'       => 'Service Tier',
            'field_type'  => 'select',
            'options'     => 'Starter, Professional, Enterprise',
            'placeholder' => 'Choose a plan',
        ],
    ];
    $scope2 = s7_scope($f2);
    $html2 = $fieldR->render($scope2);
    assert_true(str_contains($html2, '<select name="service_tier"'), 'Contains select tag');
    assert_true(str_contains($html2, '<option value="Professional">Professional</option>'), 'Contains option value');

    // Form container
    $formBlock = [
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'core.form',
        'version' => 1,
        'props'   => [
            'action'         => '/api/lead-capture',
            'method'         => 'post',
            'form_name'      => 'marketing_lead_form',
            'submit_text'    => 'Request Demo',
            'submit_variant' => 'primary',
        ],
    ];
    $childrenHtml = $html1 . $html2;
    $scopeForm = s7_scope($formBlock, $childrenHtml);
    $htmlForm = $formR->render($scopeForm);

    assert_true(str_contains($htmlForm, 'action="/api/lead-capture"'), 'Contains form action');
    assert_true(str_contains($htmlForm, 'method="post"'), 'Contains post method');
    assert_true(str_contains($htmlForm, 'data-sb-form="marketing_lead_form"'), 'Contains data-sb-form attribute');
    assert_true(str_contains($htmlForm, 'name="full_name"'), 'Contains child field 1');
    assert_true(str_contains($htmlForm, 'name="service_tier"'), 'Contains child field 2');
    assert_true(str_contains($htmlForm, 'Request Demo'), 'Contains submit button');
});

unit('sprint7 unit: GalleryRenderer and VideoRenderer output responsive grid and sanitized embed markup', function (): void {
    $galleryR = new GalleryRenderer();
    $videoR = new VideoRenderer();

    // Gallery
    $galBlock = [
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'core.gallery',
        'version' => 1,
        'props'   => [
            'columns'      => '4',
            'gap'          => 'sm',
            'aspect_ratio' => '16:9',
            'rounded'      => true,
            'images'       => [
                ['url' => 'https://images.example.test/img1.jpg', 'alt' => 'Hero Banner', 'caption' => 'Studio Launch'],
                ['url' => 'https://images.example.test/img2.jpg', 'alt' => 'Product Demo', 'caption' => 'UI Workflow'],
            ],
        ],
    ];
    $scopeGal = s7_scope($galBlock);
    $htmlGal = $galleryR->render($scopeGal);
    assert_true(str_contains($htmlGal, 'sb-gallery--cols-4'), 'Contains cols-4 class');
    assert_true(str_contains($htmlGal, 'sb-gallery--gap-sm'), 'Contains gap-sm class');
    assert_true(str_contains($htmlGal, 'sb-gallery--rounded'), 'Contains rounded class');
    assert_true(str_contains($htmlGal, 'src="https://images.example.test/img1.jpg"'), 'Contains image 1 src');
    assert_true(str_contains($htmlGal, 'Studio Launch'), 'Contains caption');

    // Video - YouTube sanitization
    $vidBlock = [
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'core.video',
        'version' => 1,
        'props'   => [
            'url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'aspect_ratio' => '16:9',
            'autoplay'     => false,
            'controls'     => true,
        ],
    ];
    $scopeVid = s7_scope($vidBlock);
    $htmlVid = $videoR->render($scopeVid);
    assert_true(str_contains($htmlVid, 'sb-video--aspect-16-9'), 'Contains aspect-16-9 class');
    assert_true(str_contains($htmlVid, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'Converts to privacy-enhanced youtube-nocookie embed');
    assert_true(str_contains($htmlVid, 'allowfullscreen'), 'Contains allowfullscreen');

    // Video - Unsafe scheme rejected
    $badUrl = VideoRenderer::buildEmbedUrl('javascript:alert(1)', false, true, false, false);
    assert_null($badUrl, 'Rejects javascript: URLs');
});

unit('sprint7 unit: DocumentRenderer compiles animation and interaction classes and attributes', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $renderers = BlockRendererRegistry::withCoreRenderers();
    $themes = new ThemeResolver();
    $theme = $themes->resolve('default');
    $media = new CoreMediaResolver(new TenantContext());
    $docRenderer = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext()));

    $block = [
        'id'           => CanonicalDocumentSchema::newBlockId(),
        'type'         => 'core.heading',
        'version'      => 1,
        'props'        => ['text' => 'Animated Heading', 'level' => 'h2'],
        'style'        => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility'   => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'     => [],
        'children'     => [],
        'animation'    => [
            'type'     => 'fade_up',
        ],
        'interactions' => [
            'trigger'  => 'hover',
        ],
    ];

    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    $collector = new RenderCollector();
    $html = $docRenderer->renderBlock($block, $context, $theme, $collector, false);

    assert_true(str_contains($html, 'sb-animate-fade-up'), 'Contains sb-animate-fade-up class: ' . $html);
    assert_true(str_contains($html, 'sb-interaction-hover'), 'Contains sb-interaction-hover class: ' . $html);
    assert_true(str_contains($html, 'data-sb-interaction-trigger="hover"'), 'Contains data-sb-interaction-trigger attribute: ' . $html);
});

unit('sprint7 unit: StudioStylesheet contains animation keyframes, modal, offcanvas, form, and reduced-motion rules', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();

    assert_true(str_contains($css, '@keyframes sb-fade-in'), 'Contains sb-fade-in keyframes');
    assert_true(str_contains($css, '@keyframes sb-fade-up'), 'Contains sb-fade-up keyframes');
    assert_true(str_contains($css, '.sb-animate-fade-up'), 'Contains .sb-animate-fade-up rule');
    assert_true(str_contains($css, '.sb-interaction-hover'), 'Contains .sb-interaction-hover rule');
    assert_true(str_contains($css, 'prefers-reduced-motion:reduce'), 'Contains prefers-reduced-motion media query');
    assert_true(str_contains($css, '.sb-modal'), 'Contains .sb-modal rule');
    assert_true(str_contains($css, '.sb-offcanvas'), 'Contains .sb-offcanvas rule');
    assert_true(str_contains($css, '.sb-form'), 'Contains .sb-form rule');
    assert_true(str_contains($css, '.sb-gallery'), 'Contains .sb-gallery rule');
    assert_true(str_contains($css, '.sb-video-wrapper'), 'Contains .sb-video-wrapper rule');
});
