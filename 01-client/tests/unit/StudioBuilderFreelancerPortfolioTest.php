<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Freelancer Portfolio Extension & Widgets.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-testimonial-widget/StudioTestimonialWidget.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Sdk\Studio;
use Slate\Module\StudioBuilder\Sdk\WidgetSdk;
use Slate\Tenancy\TenantContext;

function s_port_scope(array $block, string $children = ''): BlockRenderScope
{
    $tenants = new TenantContext();
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    $theme = new ResolvedTheme('default', []);
    $collector = new RenderCollector();
    $media = new CoreMediaResolver($tenants);
    return new BlockRenderScope($block, $context, $media, $theme, $collector, $children, []);
}

unit('freelancer portfolio: plugin boots and registers all custom widgets', function (): void {
    WidgetSdk::reset();
    StudioFreelancerPortfolio::resetStyles();

    $plugin = new StudioFreelancerPortfolio(
        'studio-freelancer-portfolio',
        ['version' => '1.0.0'],
        dirname(__DIR__, 2) . '/plugins/studio-freelancer-portfolio'
    );
    $plugin->boot();

    assert_true(Studio::widgets()->has('portfolio.nav_header'), 'Registers portfolio.nav_header');
    assert_true(Studio::widgets()->has('portfolio.footer'), 'Registers portfolio.footer');
    assert_true(Studio::widgets()->has('portfolio.project_card'), 'Registers portfolio.project_card');
    assert_true(Studio::widgets()->has('portfolio.stat_highlight'), 'Registers portfolio.stat_highlight');
    assert_true(Studio::widgets()->has('portfolio.skill_grid'), 'Registers portfolio.skill_grid');
    assert_true(Studio::widgets()->has('portfolio.experience_timeline'), 'Registers portfolio.experience_timeline');
    assert_true(Studio::widgets()->has('portfolio.service_card'), 'Registers portfolio.service_card');
    assert_true(Studio::widgets()->has('portfolio.testimonial_card'), 'Registers portfolio.testimonial_card');

    assert_eq('portfolio', Studio::widgets()->get('portfolio.project_card')->category());
    assert_eq('Project Case Study Card', Studio::widgets()->get('portfolio.project_card')->label());
    assert_eq('Luxury Navigation Bar', Studio::widgets()->get('portfolio.nav_header')->label());
    assert_eq('Luxury Portfolio Footer', Studio::widgets()->get('portfolio.footer')->label());
});

unit('freelancer portfolio: custom project card renders with tags, metrics, and links', function (): void {
    StudioFreelancerPortfolio::resetStyles();

    $scope = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.project_card',
        'version' => 1,
        'props'   => [
            'title'       => 'Solaya Analytics Platform',
            'category'    => 'Fintech / SaaS Analytics',
            'description' => 'Complete front-end architecture and visual analytics dashboard.',
            'image_url'   => 'https://images.example.test/mockup.jpg',
            'metric'      => '+185% Daily Active Users',
            'tags'        => 'Product Architecture, React, TypeScript',
            'link_url'    => 'https://example.test/case-study',
            'link_text'   => 'View Case Study →',
        ],
    ]);

    $widget = Studio::widgets()->get('portfolio.project_card');
    $renderer = $widget->toBlockRenderer();
    $html = $renderer->render($scope);

    assert_true(str_contains($html, 'sb-portfolio-styles'), 'Contains portfolio stylesheet');
    assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.project_card"'), 'Contains widget data attribute');
    assert_true(str_contains($html, 'Solaya Analytics Platform'), 'Contains title');
    assert_true(str_contains($html, 'Fintech / SaaS Analytics'), 'Contains category');
    assert_true(str_contains($html, '+185% Daily Active Users'), 'Contains metric badge');
    assert_true(str_contains($html, 'React'), 'Contains tag pill');
    assert_true(str_contains($html, 'https://example.test/case-study'), 'Contains case study URL');
});

unit('freelancer portfolio: stat highlight, skill grid, timeline, service, and testimonial render properly', function (): void {
    StudioFreelancerPortfolio::resetStyles();

    $scopeStat = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.stat_highlight',
        'version' => 1,
        'props'   => [
            'number' => '42+',
            'label'  => 'Shipped Web Applications',
        ],
    ]);
    $htmlStat = Studio::widgets()->get('portfolio.stat_highlight')->toBlockRenderer()->render($scopeStat);
    assert_true(str_contains($htmlStat, '42+'), 'Contains stat number');
    assert_true(str_contains($htmlStat, 'Shipped Web Applications'), 'Contains stat label');

    $scopeSkill = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.skill_grid',
        'version' => 1,
        'props'   => [
            'group_title' => 'Frontend & Web Platforms',
            'skills'      => 'TypeScript, React, Next.js',
        ],
    ]);
    $htmlSkill = Studio::widgets()->get('portfolio.skill_grid')->toBlockRenderer()->render($scopeSkill);
    assert_true(str_contains($htmlSkill, 'Frontend') && str_contains($htmlSkill, 'Web Platforms'), 'Contains domain title');
    assert_true(str_contains($htmlSkill, 'TypeScript'), 'Contains skill pill');
    assert_true(str_contains($htmlSkill, 'React'), 'Contains skill pill');

    $scopeTimeline = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.experience_timeline',
        'version' => 1,
        'props'   => [
            'role'        => 'Principal Product Designer',
            'company'     => 'Acme Studio',
            'period'      => '2023 — Present',
            'description' => 'Leading product design systems.',
        ],
    ]);
    $htmlTimeline = Studio::widgets()->get('portfolio.experience_timeline')->toBlockRenderer()->render($scopeTimeline);
    assert_true(str_contains($htmlTimeline, 'Principal Product Designer'), 'Contains role');
    assert_true(str_contains($htmlTimeline, 'Acme Studio'), 'Contains company');

    $scopeService = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.service_card',
        'version' => 1,
        'props'   => [
            'number'      => '01',
            'title'       => 'Product Architecture',
            'description' => 'Design systems and UX.',
        ],
    ]);
    $htmlService = Studio::widgets()->get('portfolio.service_card')->toBlockRenderer()->render($scopeService);
    assert_true(str_contains($htmlService, '01'), 'Contains service index');
    assert_true(str_contains($htmlService, 'Product Architecture'), 'Contains service title');

    $scopeTestimonial = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.testimonial_card',
        'version' => 1,
        'props'   => [
            'author'  => 'Marcus Vance',
            'role'    => 'VP of Product, CloudScale Inc',
            'quote'   => 'Elena bridged design and engineering with flawless execution.',
            'rating'  => 5,
        ],
    ]);
    $htmlTestimonial = Studio::widgets()->get('portfolio.testimonial_card')->toBlockRenderer()->render($scopeTestimonial);
    assert_true(str_contains($htmlTestimonial, 'Marcus Vance'), 'Contains author');
    assert_true(str_contains($htmlTestimonial, 'CloudScale Inc'), 'Contains role');
    assert_true(str_contains($htmlTestimonial, '★★★★★'), 'Contains stars');
    assert_true(str_contains($htmlTestimonial, 'Verified Client'), 'Contains verified client badge');

    $scopeNav = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.nav_header',
        'version' => 1,
        'props'   => [
            'monogram'  => 'ER',
            'name'      => 'Elena Rostova',
            'role'      => 'Principal Product Engineer',
            'cta_label' => 'Book Intro ↗',
            'cta_href'  => '#contact',
        ],
    ]);
    $htmlNav = Studio::widgets()->get('portfolio.nav_header')->toBlockRenderer()->render($scopeNav);
    assert_true(str_contains($htmlNav, 'sb-portfolio-nav'), 'Contains nav header class');
    assert_true(str_contains($htmlNav, 'ER'), 'Contains brand monogram');
    assert_true(str_contains($htmlNav, 'Elena Rostova'), 'Contains brand name');
    assert_true(str_contains($htmlNav, 'Book Intro ↗'), 'Contains CTA button');

    $scopeFooter = s_port_scope([
        'id'      => CanonicalDocumentSchema::newBlockId(),
        'type'    => 'portfolio.footer',
        'version' => 1,
        'props'   => [
            'copyright' => '© 2026 Elena Rostova',
            'status'    => 'Available for Select Q4 Engagements',
            'location'  => 'San Francisco, CA',
        ],
    ]);
    $htmlFooter = Studio::widgets()->get('portfolio.footer')->toBlockRenderer()->render($scopeFooter);
    assert_true(str_contains($htmlFooter, 'sb-portfolio-footer'), 'Contains footer class');
    assert_true(str_contains($htmlFooter, '© 2026 Elena Rostova'), 'Contains copyright');
    assert_true(str_contains($htmlFooter, 'San Francisco, CA'), 'Contains location');
});

unit('freelancer portfolio: canonical document generator yields valid, complete structure', function (): void {
    $doc = StudioFreelancerPortfolio::getPortfolioDocument();

    assert_eq('1.0', $doc['schema_version'], 'Schema version 1.0');
    assert_eq('page', $doc['document_type'], 'Page document type');
    assert_true(isset($doc['sections']) && count($doc['sections']) >= 6, 'Contains 6 rich portfolio sections');

    // Section 1: Hero
    assert_eq('Hero & Introduction', $doc['sections'][0]['label']);
    // Section 2: Work
    assert_eq('Selected Work', $doc['sections'][1]['label']);
    // Section 3: Services
    assert_eq('Services & Offerings', $doc['sections'][2]['label']);
    // Section 4: Skills
    assert_eq('Skills & Technologies', $doc['sections'][3]['label']);
    // Section 5: Testimonials
    assert_eq('Client Testimonials', $doc['sections'][4]['label']);
    // Section 6: Contact
    assert_eq('Contact & Inquiries', $doc['sections'][5]['label']);

    // Validate with DocumentValidator using StudioRegistry
    $registry = \Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions::studioRegistry();
    $result = DocumentValidator::validate($doc, $registry);
    assert_true($result->isValid(), 'Portfolio document satisfies canonical schema validator');

    // Clean up registry so subsequent tests in the suite have a pristine baseline
    WidgetSdk::reset();
});

