<?php
/**
 * Studio Freelancer Portfolio — Extension Plugin for Kohevo Studio.
 *
 * Implements an elegant, modern freelancer portfolio website kit:
 * - Registers custom widgets:
 *     - `portfolio.nav_header`: Luxury top navigation bar with dynamic links.
 *     - `portfolio.footer`: Luxury site footer with status and social links.
 *     - `portfolio.status_pill`: Live availability status indicator badge.
 *     - `portfolio.eyebrow`: Section category typography eyebrow.
 *     - `portfolio.reassurance_badges`: Trust badges for inquiries.
 *     - `portfolio.project_card`: Case study card with metric badges, tags, and link actions.
 *     - `portfolio.stat_highlight`: Gradient stat metric counters.
 *     - `portfolio.skill_grid`: Technical skill badges grouped by domain.
 *     - `portfolio.experience_timeline`: Career milestones and client history.
 *     - `portfolio.service_card`: Premium service offering cards.
 *     - `portfolio.testimonial_card`: Client review and endorsement cards.
 * - Dynamic data provider bridging custom content to visual ASTs.
 * - Multi-page canonical document generators:
 *     - `getPortfolioDocument(?int $tenantId = null)`
 *     - `getServicesDocument(?int $tenantId = null)`
 *     - `getCaseStudiesDocument(?int $tenantId = null)`
 *     - `getTestimonialsDocument(?int $tenantId = null)`
 *     - `getContactDocument(?int $tenantId = null)`
 * - Atomic multi-page publisher: `publishEntireSite(int $tenantId, ?int $userId = null)`.
 */

declare(strict_types=1);

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Sdk\Studio;
use Slate\Module\StudioBuilder\StudioPermissions;

class StudioFreelancerPortfolio extends Plugin
{
    public function boot(): void
    {
        // 1. Register `portfolio.nav_header`
        Studio::widgets()->register([
            'type'        => 'portfolio.nav_header',
            'version'     => 1,
            'label'       => 'Luxury Navigation Bar',
            'category'    => 'portfolio',
            'icon'        => 'compass',
            'schema'      => [
                ['key' => 'monogram', 'type' => 'string', 'label' => 'Brand Monogram', 'required' => false, 'default' => 'ER', 'max_length' => 10],
                ['key' => 'name', 'type' => 'string', 'label' => 'Full Name', 'required' => true, 'default' => 'Elena Rostova', 'max_length' => 100],
                ['key' => 'role', 'type' => 'string', 'label' => 'Title / Tagline', 'required' => false, 'default' => 'Principal Product Engineer', 'max_length' => 120],
                ['key' => 'cta_label', 'type' => 'string', 'label' => 'CTA Button Label', 'required' => false, 'default' => 'Book Intro ↗', 'max_length' => 60],
                ['key' => 'cta_href', 'type' => 'string', 'label' => 'CTA Link Target', 'required' => false, 'default' => '#contact', 'max_length' => 255],
            ],
            'renderer'    => [self::class, 'renderNavHeader'],
        ]);

        // 2. Register `portfolio.footer`
        Studio::widgets()->register([
            'type'        => 'portfolio.footer',
            'version'     => 1,
            'label'       => 'Luxury Portfolio Footer',
            'category'    => 'portfolio',
            'icon'        => 'layout',
            'schema'      => [
                ['key' => 'copyright', 'type' => 'string', 'label' => 'Copyright Text', 'required' => false, 'default' => '© 2026 Elena Rostova. Crafted with high-performance elegance.', 'max_length' => 200],
                ['key' => 'status', 'type' => 'string', 'label' => 'Availability Status', 'required' => false, 'default' => 'Available for Select Q4 Engagements', 'max_length' => 120],
                ['key' => 'location', 'type' => 'string', 'label' => 'Location / Work Mode', 'required' => false, 'default' => 'San Francisco, CA · Remote Worldwide', 'max_length' => 120],
            ],
            'renderer'    => [self::class, 'renderFooter'],
        ]);

        // 3. Register `portfolio.status_pill`
        Studio::widgets()->register([
            'type'        => 'portfolio.status_pill',
            'version'     => 1,
            'label'       => 'Live Status Indicator Pill',
            'category'    => 'portfolio',
            'icon'        => 'activity',
            'schema'      => [
                ['key' => 'text', 'type' => 'string', 'label' => 'Status Text', 'required' => true, 'default' => 'Available for Select Product Engagements · Q4 2026', 'max_length' => 120],
            ],
            'renderer'    => [self::class, 'renderStatusPill'],
        ]);

        // 4. Register `portfolio.eyebrow`
        Studio::widgets()->register([
            'type'        => 'portfolio.eyebrow',
            'version'     => 1,
            'label'       => 'Section Category Eyebrow',
            'category'    => 'portfolio',
            'icon'        => 'tag',
            'schema'      => [
                ['key' => 'text', 'type' => 'string', 'label' => 'Eyebrow Text', 'required' => true, 'default' => '// SELECTED WORK', 'max_length' => 100],
                ['key' => 'anchor_id', 'type' => 'string', 'label' => 'Smooth Scroll Anchor ID', 'required' => false, 'default' => '', 'max_length' => 50],
            ],
            'renderer'    => [self::class, 'renderEyebrow'],
        ]);

        // 5. Register `portfolio.reassurance_badges`
        Studio::widgets()->register([
            'type'        => 'portfolio.reassurance_badges',
            'version'     => 1,
            'label'       => 'Form Trust Badges',
            'category'    => 'portfolio',
            'icon'        => 'shield',
            'schema'      => [
                ['key' => 'badge1', 'type' => 'string', 'label' => 'Badge 1', 'required' => false, 'default' => 'Strict NDA Guaranteed', 'max_length' => 80],
                ['key' => 'badge2', 'type' => 'string', 'label' => 'Badge 2', 'required' => false, 'default' => 'Direct Reply Within 24h', 'max_length' => 80],
                ['key' => 'badge3', 'type' => 'string', 'label' => 'Badge 3', 'required' => false, 'default' => 'Senior Leadership Only', 'max_length' => 80],
            ],
            'renderer'    => [self::class, 'renderReassuranceBadges'],
        ]);

        // 6. Register `portfolio.project_card`
        Studio::widgets()->register([
            'type'        => 'portfolio.project_card',
            'version'     => 1,
            'label'       => 'Project Case Study Card',
            'category'    => 'portfolio',
            'icon'        => 'briefcase',
            'schema'      => [
                ['key' => 'title', 'type' => 'string', 'label' => 'Project Title', 'required' => true, 'default' => 'Solaya Analytics Platform', 'max_length' => 120],
                ['key' => 'category', 'type' => 'string', 'label' => 'Category / Subtitle', 'required' => false, 'default' => 'Fintech / SaaS Analytics', 'max_length' => 80],
                ['key' => 'year', 'type' => 'string', 'label' => 'Release Year', 'required' => false, 'default' => '2026', 'max_length' => 20],
                ['key' => 'description', 'type' => 'text', 'label' => 'Summary Description', 'required' => true, 'default' => 'End-to-end product design, tokenized design system, and responsive front-end implementation.', 'max_length' => 600],
                ['key' => 'image_url', 'type' => 'url', 'label' => 'Mockup / Cover Image URL', 'required' => false, 'default' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80'],
                ['key' => 'metric', 'type' => 'string', 'label' => 'Key Metric / Outcome', 'required' => false, 'default' => '+185% Daily Active Users', 'max_length' => 60],
                ['key' => 'tags', 'type' => 'string', 'label' => 'Tags (comma separated)', 'required' => false, 'default' => 'Product Architecture, React, TypeScript, High-Performance Canvas', 'max_length' => 200],
                ['key' => 'link_url', 'type' => 'url', 'label' => 'Case Study Link', 'required' => false, 'default' => '/case-studies'],
                ['key' => 'link_text', 'type' => 'string', 'label' => 'Link Label', 'required' => false, 'default' => 'View Case Study →', 'max_length' => 60],
            ],
            'renderer'    => [self::class, 'renderProjectCard'],
        ]);

        // 7. Register `portfolio.stat_highlight`
        Studio::widgets()->register([
            'type'        => 'portfolio.stat_highlight',
            'version'     => 1,
            'label'       => 'Stat Highlight',
            'category'    => 'portfolio',
            'icon'        => 'trending-up',
            'schema'      => [
                ['key' => 'number', 'type' => 'string', 'label' => 'Metric Number', 'required' => true, 'default' => '9+', 'max_length' => 40],
                ['key' => 'label', 'type' => 'string', 'label' => 'Metric Description', 'required' => true, 'default' => 'Years Crafting Products', 'max_length' => 120],
            ],
            'renderer'    => [self::class, 'renderStatHighlight'],
        ]);

        // 8. Register `portfolio.skill_grid`
        Studio::widgets()->register([
            'type'        => 'portfolio.skill_grid',
            'version'     => 1,
            'label'       => 'Skill Group Grid',
            'category'    => 'portfolio',
            'icon'        => 'cpu',
            'schema'      => [
                ['key' => 'group_title', 'type' => 'string', 'label' => 'Domain Title', 'required' => true, 'default' => 'Frontend & Web Engineering', 'max_length' => 80],
                ['key' => 'skills', 'type' => 'string', 'label' => 'Skills (comma separated)', 'required' => true, 'default' => 'TypeScript, React, Next.js, Tailwind CSS, Performance / CWV', 'max_length' => 300],
            ],
            'renderer'    => [self::class, 'renderSkillGrid'],
        ]);

        // 9. Register `portfolio.experience_timeline`
        Studio::widgets()->register([
            'type'        => 'portfolio.experience_timeline',
            'version'     => 1,
            'label'       => 'Experience Milestone',
            'category'    => 'portfolio',
            'icon'        => 'calendar',
            'schema'      => [
                ['key' => 'role', 'type' => 'string', 'label' => 'Role Title', 'required' => true, 'default' => 'Principal Product Designer & Engineer', 'max_length' => 120],
                ['key' => 'company', 'type' => 'string', 'label' => 'Company / Client', 'required' => true, 'default' => 'Independent Advisory & Venture Studios', 'max_length' => 120],
                ['key' => 'period', 'type' => 'string', 'label' => 'Timeframe', 'required' => true, 'default' => '2023 — Present', 'max_length' => 60],
                ['key' => 'description', 'type' => 'text', 'label' => 'Summary', 'required' => true, 'default' => 'Partnering with Series A/B founders to design high-impact product experiences and scalable UI systems.', 'max_length' => 500],
            ],
            'renderer'    => [self::class, 'renderExperienceTimeline'],
        ]);

        // 10. Register `portfolio.service_card`
        Studio::widgets()->register([
            'type'        => 'portfolio.service_card',
            'version'     => 1,
            'label'       => 'Service Offering Card',
            'category'    => 'portfolio',
            'icon'        => 'layers',
            'schema'      => [
                ['key' => 'number', 'type' => 'string', 'label' => 'Index Number', 'required' => false, 'default' => '01', 'max_length' => 10],
                ['key' => 'title', 'type' => 'string', 'label' => 'Service Title', 'required' => true, 'default' => 'Product Design & Architecture', 'max_length' => 120],
                ['key' => 'description', 'type' => 'text', 'label' => 'Deliverables & Description', 'required' => true, 'default' => 'User research, rapid wireframing, high-fidelity Figma prototypes, and multi-brand tokenized design systems.', 'max_length' => 500],
                ['key' => 'deliverables', 'type' => 'string', 'label' => 'Deliverables (comma separated)', 'required' => false, 'default' => '', 'max_length' => 400],
            ],
            'renderer'    => [self::class, 'renderServiceCard'],
        ]);

        // 11. Register `portfolio.testimonial_card`
        Studio::widgets()->register([
            'type'        => 'portfolio.testimonial_card',
            'version'     => 1,
            'label'       => 'Client Testimonial Card',
            'category'    => 'portfolio',
            'icon'        => 'message-square',
            'schema'      => [
                ['key' => 'quote', 'type' => 'text', 'label' => 'Client Quote', 'required' => true, 'default' => '', 'max_length' => 1000],
                ['key' => 'author', 'type' => 'string', 'label' => 'Client Name', 'required' => true, 'default' => '', 'max_length' => 120],
                ['key' => 'role', 'type' => 'string', 'label' => 'Client Role & Company', 'required' => true, 'default' => '', 'max_length' => 120],
                ['key' => 'rating', 'type' => 'number', 'label' => 'Star Rating (1-5)', 'required' => false, 'default' => 5, 'min' => 1, 'max' => 5],
                ['key' => 'avatar_url', 'type' => 'string', 'label' => 'Avatar URL', 'required' => false, 'default' => '', 'max_length' => 500],
            ],
            'renderer'    => [self::class, 'renderTestimonialCard'],
        ]);
    }

    // ── Renderers ────────────────────────────────────────────────────────────

    private static bool $stylesInjected = false;

    public static function resetStyles(): void
    {
        self::$stylesInjected = false;
    }

    public static function renderStylesOnce(): string
    {
        if (self::$stylesInjected) {
            return '';
        }
        self::$stylesInjected = true;
        $cssFile = __DIR__ . '/assets/css/portfolio.css';
        if (file_exists($cssFile)) {
            $cssContent = (string) file_get_contents($cssFile);
            return '<style id="sb-portfolio-styles">' . $cssContent . '</style>';
        }
        return '';
    }

    public static function renderNavHeader(BlockRenderScope $scope): string
    {
        $monogram = $scope->string('monogram', 'ER');
        $name     = $scope->string('name', 'Elena Rostova');
        $role     = $scope->string('role', 'Principal Product Engineer');
        $ctaLabel = $scope->string('cta_label', 'Book Intro ↗');
        $ctaHref  = $scope->string('cta_href', '#contact');

        // Dynamic site routes
        $base = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') : '';
        $workUrl = $base ? $base . '/portfolio' : '/portfolio';
        $servicesUrl = $base ? $base . '/services' : '/services';
        $casesUrl = $base ? $base . '/case-studies' : '/case-studies';
        $reviewsUrl = $base ? $base . '/testimonials' : '/testimonials';
        $contactUrl = $base ? $base . '/contact' : ($ctaHref ?: '#contact');

        return self::renderStylesOnce() . '<nav class="sb-portfolio-nav" data-sb-custom-widget="portfolio.nav_header">'
            . '<a href="' . Html::e($workUrl) . '" class="sb-portfolio-brand">'
            . '<div class="sb-portfolio-brand-avatar">' . Html::e($monogram) . '</div>'
            . '<div class="sb-portfolio-brand-info">'
            . '<span class="sb-portfolio-brand-name">' . Html::e($name) . '</span>'
            . '<span class="sb-portfolio-brand-role">' . Html::e($role) . '</span>'
            . '</div>'
            . '</a>'
            . '<ul class="sb-portfolio-nav-links">'
            . '<li><a href="' . Html::e($workUrl) . '" class="sb-portfolio-nav-link">Work</a></li>'
            . '<li><a href="' . Html::e($servicesUrl) . '" class="sb-portfolio-nav-link">Services</a></li>'
            . '<li><a href="' . Html::e($casesUrl) . '" class="sb-portfolio-nav-link">Case Studies</a></li>'
            . '<li><a href="' . Html::e($reviewsUrl) . '" class="sb-portfolio-nav-link">Reviews</a></li>'
            . '<li><a href="' . Html::e($contactUrl) . '" class="sb-portfolio-nav-link">Contact</a></li>'
            . '</ul>'
            . '<a href="' . Html::e($contactUrl) . '" class="sb-portfolio-nav-cta">' . Html::e($ctaLabel) . '</a>'
            . '</nav>';
    }

    public static function renderFooter(BlockRenderScope $scope): string
    {
        $copyright = $scope->string('copyright', '© 2026 Elena Rostova. Crafted with high-performance elegance.');
        $status    = $scope->string('status', 'Available for Select Q4 Engagements');
        $location  = $scope->string('location', 'San Francisco, CA · Remote Worldwide');

        return self::renderStylesOnce() . '<footer class="sb-portfolio-footer" data-sb-custom-widget="portfolio.footer">'
            . '<div class="sb-portfolio-footer-left">'
            . '<span class="sb-portfolio-footer-status"><span class="sb-portfolio-status-dot"></span> ' . Html::e($status) . '</span>'
            . '<span class="sb-portfolio-footer-copy">' . Html::e($copyright) . ' · ' . Html::e($location) . '</span>'
            . '</div>'
            . '<div class="sb-portfolio-footer-socials">'
            . '<a href="https://github.com" target="_blank" rel="noopener" class="sb-portfolio-social-link">GitHub ↗</a>'
            . '<a href="https://linkedin.com" target="_blank" rel="noopener" class="sb-portfolio-social-link">LinkedIn ↗</a>'
            . '<a href="https://twitter.com" target="_blank" rel="noopener" class="sb-portfolio-social-link">X / Twitter ↗</a>'
            . '<a href="mailto:contact@rakibhasaan.com" class="sb-portfolio-social-link">Email ↗</a>'
            . '</div>'
            . '</footer>';
    }

    public static function renderStatusPill(BlockRenderScope $scope): string
    {
        $text = $scope->string('text', 'Available for Select Product Engagements · Q4 2026');
        return self::renderStylesOnce() . '<div class="sb-portfolio-status-pill" data-sb-custom-widget="portfolio.status_pill">'
            . '<span class="sb-portfolio-status-dot"></span> ' . Html::e($text)
            . '</div>';
    }

    public static function renderEyebrow(BlockRenderScope $scope): string
    {
        $text     = $scope->string('text', '// SELECTED WORK');
        $anchorId = $scope->string('anchor_id');
        $idAttr   = $anchorId !== '' ? ' id="' . Html::e($anchorId) . '"' : '';

        return self::renderStylesOnce() . '<div' . $idAttr . ' class="sb-portfolio-eyebrow" data-sb-custom-widget="portfolio.eyebrow">'
            . Html::e($text)
            . '</div>';
    }

    public static function renderReassuranceBadges(BlockRenderScope $scope): string
    {
        $b1 = $scope->string('badge1', 'Strict NDA Guaranteed');
        $b2 = $scope->string('badge2', 'Direct Reply Within 24h');
        $b3 = $scope->string('badge3', 'Senior Leadership Only');

        return self::renderStylesOnce() . '<div class="sb-portfolio-form-reassurance" data-sb-custom-widget="portfolio.reassurance_badges">'
            . '<span class="sb-portfolio-reassurance-item">' . Html::e($b1) . '</span>'
            . '<span class="sb-portfolio-reassurance-item">' . Html::e($b2) . '</span>'
            . '<span class="sb-portfolio-reassurance-item">' . Html::e($b3) . '</span>'
            . '</div>';
    }

    public static function renderProjectCard(BlockRenderScope $scope): string
    {
        $title    = $scope->string('title', 'Project Title');
        $category = $scope->string('category');
        $year     = $scope->string('year', '2026');
        $desc     = $scope->string('description');
        $image    = $scope->string('image_url');
        $metric   = $scope->string('metric');
        $tagsRaw  = $scope->string('tags');
        $linkUrl  = $scope->string('link_url', '#');
        $linkText = $scope->string('link_text', 'View Case Study →');

        $tags = array_filter(array_map('trim', explode(',', $tagsRaw)));

        $html = self::renderStylesOnce() . '<article class="sb-portfolio-project-card" data-sb-custom-widget="portfolio.project_card">';
        if ($image !== '') {
            $html .= '<div class="sb-portfolio-project-thumb">';
            $html .= '<img src="' . Html::e($image) . '" alt="' . Html::e($title) . '" loading="lazy">';
            if ($metric !== '') {
                $html .= '<span class="sb-portfolio-project-metric-badge">' . Html::e($metric) . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '<div class="sb-portfolio-project-body">';
        $html .= '<div class="sb-portfolio-project-meta-row">';
        if ($category !== '') {
            $html .= '<div class="sb-portfolio-project-category">' . Html::e($category) . '</div>';
        }
        if ($year !== '') {
            $html .= '<div class="sb-portfolio-project-year">' . Html::e($year) . '</div>';
        }
        $html .= '</div>';
        $html .= '<h3 class="sb-portfolio-project-title">' . Html::e($title) . '</h3>';
        if ($desc !== '') {
            $html .= '<p class="sb-portfolio-project-desc">' . Html::e($desc) . '</p>';
        }
        if ($tags !== []) {
            $html .= '<div class="sb-portfolio-project-tags">';
            foreach ($tags as $tag) {
                $html .= '<span class="sb-portfolio-project-tag">' . Html::e($tag) . '</span>';
            }
            $html .= '</div>';
        }
        if ($linkUrl !== '') {
            $html .= '<a href="' . Html::e($linkUrl) . '" class="sb-portfolio-project-link">' . Html::e($linkText) . '</a>';
        }
        $html .= '</div>';
        $html .= '</article>';
        return $html;
    }

    public static function renderStatHighlight(BlockRenderScope $scope): string
    {
        $number = $scope->string('number', '0');
        $label  = $scope->string('label', 'Metric');

        return self::renderStylesOnce() . '<div class="sb-portfolio-stat-item" data-sb-custom-widget="portfolio.stat_highlight">'
            . '<div class="sb-portfolio-stat-number">' . Html::e($number) . '</div>'
            . '<div class="sb-portfolio-stat-label">' . Html::e($label) . '</div>'
            . '</div>';
    }

    public static function renderSkillGrid(BlockRenderScope $scope): string
    {
        $title    = $scope->string('group_title', 'Core Skills');
        $skillsRaw = $scope->string('skills');
        $skills   = array_filter(array_map('trim', explode(',', $skillsRaw)));

        $html = self::renderStylesOnce() . '<div class="sb-portfolio-skill-group" data-sb-custom-widget="portfolio.skill_grid">';
        $html .= '<h4 class="sb-portfolio-skill-group-title">' . Html::e($title) . '</h4>';
        $html .= '<div class="sb-portfolio-skill-pills">';
        foreach ($skills as $skill) {
            $html .= '<span class="sb-portfolio-skill-pill">' . Html::e($skill) . '</span>';
        }
        $html .= '</div></div>';
        return $html;
    }

    public static function renderExperienceTimeline(BlockRenderScope $scope): string
    {
        $role    = $scope->string('role', 'Role');
        $company = $scope->string('company', 'Company');
        $period  = $scope->string('period', '2024');
        $desc    = $scope->string('description');

        return self::renderStylesOnce() . '<div class="sb-portfolio-timeline-item" data-sb-custom-widget="portfolio.experience_timeline">'
            . '<div class="sb-portfolio-timeline-header">'
            . '<span class="sb-portfolio-timeline-role">' . Html::e($role) . '</span>'
            . '<span class="sb-portfolio-timeline-period">' . Html::e($period) . '</span>'
            . '</div>'
            . '<div class="sb-portfolio-timeline-company">' . Html::e($company) . '</div>'
            . '<p class="sb-portfolio-timeline-desc">' . Html::e($desc) . '</p>'
            . '</div>';
    }

    public static function renderServiceCard(BlockRenderScope $scope): string
    {
        $num          = $scope->string('number', '01');
        $title        = $scope->string('title', 'Service');
        $desc         = $scope->string('description');
        $deliverables = $scope->string('deliverables');

        $items = array_filter(array_map('trim', explode(',', $deliverables)));

        $html = self::renderStylesOnce() . '<div class="sb-portfolio-service-card" data-sb-custom-widget="portfolio.service_card">';
        $html .= '<div class="sb-portfolio-service-header">';
        $html .= '<span class="sb-portfolio-service-num">' . Html::e($num) . '</span>';
        $html .= '</div>';
        $html .= '<h3 class="sb-portfolio-service-title">' . Html::e($title) . '</h3>';
        if ($desc !== '') {
            $html .= '<p class="sb-portfolio-service-desc">' . Html::e($desc) . '</p>';
        }
        if ($items !== []) {
            $html .= '<ul class="sb-portfolio-service-checklist">';
            foreach ($items as $item) {
                $html .= '<li><span class="sb-portfolio-service-check-icon">✓</span> ' . Html::e($item) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
        return $html;
    }

    public static function renderTestimonialCard(BlockRenderScope $scope): string
    {
        $quote     = $scope->string('quote');
        $author    = $scope->string('author');
        $role      = $scope->string('role');
        $rating    = max(1, min(5, (int) ($scope->prop('rating') ?? 5)));
        $avatarUrl = $scope->string('avatar_url');

        $stars = str_repeat('★', $rating);

        $html = self::renderStylesOnce() . '<div class="sb-portfolio-testimonial-card" data-sb-custom-widget="portfolio.testimonial_card">';
        $html .= '<div class="sb-portfolio-testimonial-top">';
        $html .= '<div class="sb-portfolio-testimonial-rating">' . Html::e($stars) . '</div>';
        $html .= '<span class="sb-portfolio-verified-badge">✓ Verified Client</span>';
        $html .= '</div>';
        $html .= '<p class="sb-portfolio-testimonial-quote">“' . Html::e($quote) . '”</p>';
        $html .= '<div class="sb-portfolio-testimonial-author">';
        if ($avatarUrl !== '') {
            $html .= '<img class="sb-portfolio-testimonial-avatar" src="' . Html::e($avatarUrl) . '" alt="' . Html::e($author) . '" loading="lazy">';
        }
        $html .= '<div class="sb-portfolio-testimonial-info">';
        $html .= '<span class="sb-portfolio-testimonial-name">' . Html::e($author) . '</span>';
        $html .= '<span class="sb-portfolio-testimonial-role">' . Html::e($role) . '</span>';
        $html .= '</div></div></div>';
        return $html;
    }

    // ── Canonical AST Builders ───────────────────────────────────────────────

    public static function makeBlock(string $type, array $props, array $children = [], ?array $style = null): array
    {
        return [
            'id'         => CanonicalDocumentSchema::newBlockId(),
            'type'       => $type,
            'version'    => 1,
            'props'      => $props,
            'style'      => $style ?? CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'bindings'   => [],
            'children'   => $children,
        ];
    }

    public static function makeSection(array $blocks, string $label = 'Section', array $layout = []): array
    {
        $defaultLayout = CanonicalDocumentSchema::defaultSectionLayout();
        return [
            'id'         => CanonicalDocumentSchema::newSectionId(),
            'label'      => $label,
            'global_ref' => null,
            'layout'     => array_merge($defaultLayout, $layout),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks'     => $blocks,
        ];
    }

    // ── Dynamic Content Data Repository ──────────────────────────────────────

    private static function hasDatabase(): bool
    {
        return defined('DB_HOST') && constant('DB_HOST') !== '';
    }

    public static function getProfile(?int $tenantId = null): array
    {
        $defaultProfile = [
            'name'          => 'Elena Rostova',
            'monogram'      => 'ER',
            'role'          => 'Principal Product Engineer & Architect',
            'status'        => 'Available for Select Product Engagements · Q4 2026',
            'hero_heading'  => 'Designing & Engineering Category-Defining Digital Products.',
            'hero_subtext'  => 'I partner with venture-backed founders and forward-thinking product teams to craft high-impact web applications, fluid user interfaces, and scalable design systems that convert.',
            'stat_years'    => '9+',
            'stat_apps'     => '42+',
            'stat_val'      => '$180M+',
            'stat_delivery' => '100%',
            'location'      => 'San Francisco, CA · Remote Worldwide',
            'email'         => 'contact@rakibhasaan.com',
        ];

        if (!self::hasDatabase()) {
            return $defaultProfile;
        }

        try {
            $tid = $tenantId ?? (defined('TENANT_ID') ? TENANT_ID : 1);
            $saved = Database::setting('studio_profile', $tid);
            if ($saved) {
                $parsed = json_decode((string) $saved, true);
                if (is_array($parsed) && !empty($parsed['name'])) {
                    return array_merge($defaultProfile, $parsed);
                }
            }

            $siteName = (string) (Database::setting('site_name', $tid) ?: '');
            if ($siteName !== '') {
                $monogram = strtoupper(substr($siteName, 0, 1) . substr(strstr($siteName, ' ') ?: $siteName, 1, 1));
                $defaultProfile['name'] = $siteName;
                $defaultProfile['monogram'] = $monogram ?: 'ER';
            }
        } catch (\Throwable $e) {
            // Safe fallback
        }

        return $defaultProfile;
    }

    public static function getProjects(?int $tenantId = null): array
    {
        if (self::hasDatabase()) {
            try {
                $tid = $tenantId ?? (defined('TENANT_ID') ? TENANT_ID : 1);
                $saved = Database::setting('studio_content_portfolio', $tid);
                if ($saved) {
                    $items = json_decode((string) $saved, true);
                    if (is_array($items) && count($items) > 0) {
                        return $items;
                    }
                }
            } catch (\Throwable $e) {
                // Safe fallback
            }
        }

        return [
            [
                'title'       => 'Solaya Analytics Platform',
                'category'    => 'Fintech / SaaS Analytics',
                'year'        => '2026',
                'description' => 'Complete front-end architecture and visual analytics dashboard for a multi-tenant portfolio management system.',
                'image_url'   => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
                'metric'      => '+185% Daily Active Users',
                'tags'        => 'Product Architecture, React, TypeScript, High-Performance Canvas',
                'link_url'    => '/case-studies',
                'link_text'   => 'View Case Study →',
            ],
            [
                'title'       => 'Apex Enterprise Design System',
                'category'    => 'Multi-Brand UI System',
                'year'        => '2025',
                'description' => 'A unified design system spanning 45+ components with zero runtime CSS regressions, strict WCAG AAA compliance, and automated token synchronization.',
                'image_url'   => 'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?auto=format&fit=crop&w=1200&q=80',
                'metric'      => 'Adopted Across 14 Teams',
                'tags'        => 'Design Tokens, Figma, Tailwind CSS, Accessibility',
                'link_url'    => '/case-studies',
                'link_text'   => 'View Case Study →',
            ],
            [
                'title'       => 'Nexus AI Generative Workspace',
                'category'    => 'Generative UI & Collaboration',
                'year'        => '2025',
                'description' => 'Interactive visual node workspace enabling creative teams to orchestrate multimodal generative pipelines with real-time feedback.',
                'image_url'   => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=1200&q=80',
                'metric'      => 'Series A Funded ($14M)',
                'tags'        => 'Generative UI, WebSockets, Next.js, Micro-interactions',
                'link_url'    => '/case-studies',
                'link_text'   => 'View Case Study →',
            ],
            [
                'title'       => 'Chrono Practice Management',
                'category'    => 'SaaS Productivity',
                'year'        => '2024',
                'description' => 'Modern client portal, time-tracking, and automated billing software engineered specifically for elite independent consultants and boutique agencies.',
                'image_url'   => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
                'metric'      => '4.9/5.0 Average App Rating',
                'tags'        => 'Slate Engine, PostgreSQL, Stripe Invoicing, Responsive UI',
                'link_url'    => '/case-studies',
                'link_text'   => 'View Case Study →',
            ],
        ];
    }

    public static function getServicesList(?int $tenantId = null): array
    {
        if (self::hasDatabase()) {
            try {
                $tid = $tenantId ?? (defined('TENANT_ID') ? TENANT_ID : 1);
                $saved = Database::setting('studio_content_service', $tid);
                if ($saved) {
                    $items = json_decode((string) $saved, true);
                    if (is_array($items) && count($items) > 0) {
                        return $items;
                    }
                }
            } catch (\Throwable $e) {
                // Safe fallback
            }
        }

        return [
            [
                'number'       => '01',
                'title'        => 'Product Design & Architecture',
                'description'  => 'Deep UX research, interactive wireframing, high-fidelity Figma components, and ergonomic design systems tailored for rapid engineering velocity.',
                'deliverables' => 'Design System Tokens, Figma Component Library, Interactive Prototypes, UX Audit & Journey Maps',
            ],
            [
                'number'       => '02',
                'title'        => 'Full-Stack Web Engineering',
                'description'  => 'Clean, maintainable web applications engineered with modern TypeScript, React, PHP/Slate, and robust server architectures designed to handle traffic spikes.',
                'deliverables' => 'Production Web Application, Responsive Front-End, Scalable API Architecture, CI/CD & Deployment',
            ],
            [
                'number'       => '03',
                'title'        => 'Performance, SEO & Conversion',
                'description'  => 'Sub-second Core Web Vitals optimization, semantic accessibility, technical search optimization, and conversion-engineered landing pages.',
                'deliverables' => 'Sub-second CWV Score, WCAG AAA Compliance, Technical SEO Setup, High-Conversion UX Audit',
            ],
        ];
    }

    public static function getTestimonialsList(?int $tenantId = null): array
    {
        if (self::hasDatabase()) {
            try {
                $tid = $tenantId ?? (defined('TENANT_ID') ? TENANT_ID : 1);
                $saved = Database::setting('studio_content_testimonial', $tid);
                if ($saved) {
                    $items = json_decode((string) $saved, true);
                    if (is_array($items) && count($items) > 0) {
                        return $items;
                    }
                }
            } catch (\Throwable $e) {
                // Safe fallback
            }
        }

        return [
            [
                'author'     => 'Marcus Vance',
                'role'       => 'VP of Product, CloudScale Inc',
                'quote'      => 'Elena possesses the rare ability to bridge visionary product design with flawless engineering. She elevated our entire platform experience in weeks.',
                'rating'     => 5,
                'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80',
            ],
            [
                'author'     => 'Sarah Lin',
                'role'       => 'Founder & CEO, Nexus AI Labs',
                'quote'      => 'Shipped our MVP ahead of schedule. The design system she architected saved our core team hundreds of engineering hours.',
                'rating'     => 5,
                'avatar_url' => 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=400&q=80',
            ],
            [
                'author'     => 'David Miller',
                'role'       => 'Head of Engineering, Apex Global',
                'quote'      => 'An exceptional technical partner who delivered our multi-tenant application with sub-second page loads and zero accessibility defects.',
                'rating'     => 5,
                'avatar_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=400&q=80',
            ],
        ];
    }

    // ── 1. Portfolio Main Showcase Document ───────────────────────────────────

    public static function getPortfolioDocument(?int $tenantId = null): array
    {
        $profile = self::getProfile($tenantId);
        $projects = self::getProjects($tenantId);
        $services = self::getServicesList($tenantId);
        $testimonials = self::getTestimonialsList($tenantId);

        // 1. Hero Section
        $navHeader = self::makeBlock('portfolio.nav_header', [
            'monogram'  => $profile['monogram'],
            'name'      => $profile['name'],
            'role'      => $profile['role'],
            'cta_label' => 'Book Intro ↗',
            'cta_href'  => '/contact',
        ]);

        $statusPill = self::makeBlock('portfolio.status_pill', [
            'text' => $profile['status'],
        ]);

        $heroHeading = self::makeBlock('core.heading', [
            'text'  => $profile['hero_heading'],
            'level' => 'h1',
        ]);

        $heroSubtext = self::makeBlock('core.text', [
            'content' => $profile['hero_subtext'],
            'size'    => 'lg',
        ]);

        $btnWork = self::makeBlock('core.button', [
            'link'    => ['label' => 'Explore Selected Work ↓', 'href' => '#work', 'target' => '_self'],
            'variant' => 'primary',
            'size'    => 'lg',
        ]);

        $btnContact = self::makeBlock('core.button', [
            'link'    => ['label' => 'Book 15-Min Intro ↗', 'href' => '/contact', 'target' => '_self'],
            'variant' => 'outline',
            'size'    => 'lg',
        ]);

        $heroActions = self::makeBlock('layout.flex', [
            'direction' => 'row',
            'gap'       => 'md',
            'wrap'      => 'wrap',
        ], [$btnWork, $btnContact]);

        $stat1 = self::makeBlock('portfolio.stat_highlight', ['number' => $profile['stat_years'], 'label' => 'Years Crafting Products']);
        $stat2 = self::makeBlock('portfolio.stat_highlight', ['number' => $profile['stat_apps'], 'label' => 'Shipped Web Applications']);
        $stat3 = self::makeBlock('portfolio.stat_highlight', ['number' => $profile['stat_val'], 'label' => 'Client Venture Valuation']);
        $stat4 = self::makeBlock('portfolio.stat_highlight', ['number' => $profile['stat_delivery'], 'label' => 'On-Time Delivery Record']);

        $statsGrid = self::makeBlock('layout.grid', [
            'columns' => 4,
            'gap'     => 'md',
        ], [$stat1, $stat2, $stat3, $stat4]);

        $heroContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$navHeader, $statusPill, $heroHeading, $heroSubtext, $heroActions, $statsGrid]);

        $heroSection = self::makeSection([$heroContainer], 'Hero & Introduction');

        // 2. Selected Work Section
        $eyebrow1 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// SELECTED FLAGSHIP WORK',
            'anchor_id' => 'work',
        ]);

        $workHeading = self::makeBlock('core.heading', [
            'text'  => 'Selected Flagship Work',
            'level' => 'h2',
        ]);

        $workSubtext = self::makeBlock('core.text', [
            'content' => 'A curated selection of venture-backed software products, high-conversion web apps, and design systems built for scale.',
            'size'    => 'base',
        ]);

        $projectBlocks = [];
        foreach ($projects as $p) {
            $projectBlocks[] = self::makeBlock('portfolio.project_card', [
                'title'       => $p['title'] ?? 'Project',
                'category'    => $p['category'] ?? 'SaaS',
                'year'        => $p['year'] ?? '2026',
                'description' => $p['description'] ?? '',
                'image_url'   => $p['image_url'] ?? '',
                'metric'      => $p['metric'] ?? '',
                'tags'        => $p['tags'] ?? '',
                'link_url'    => $p['link_url'] ?? '/case-studies',
                'link_text'   => $p['link_text'] ?? 'View Case Study →',
            ]);
        }

        $projectsGrid = self::makeBlock('layout.grid', [
            'columns' => 2,
            'gap'     => 'lg',
        ], $projectBlocks);

        $workContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow1, $workHeading, $workSubtext, $projectsGrid]);

        $workSection = self::makeSection([$workContainer], 'Selected Work');

        // 3. Services Section
        $eyebrow2 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// AREAS OF EXPERTISE',
            'anchor_id' => 'services',
        ]);

        $servicesHeading = self::makeBlock('core.heading', [
            'text'  => 'Areas of Expertise',
            'level' => 'h2',
        ]);

        $serviceBlocks = [];
        foreach ($services as $s) {
            $serviceBlocks[] = self::makeBlock('portfolio.service_card', [
                'number'       => $s['number'] ?? '01',
                'title'        => $s['title'] ?? 'Service',
                'description'  => $s['description'] ?? '',
                'deliverables' => $s['deliverables'] ?? '',
            ]);
        }

        $servicesGrid = self::makeBlock('layout.grid', [
            'columns' => count($serviceBlocks) >= 3 ? 3 : 2,
            'gap'     => 'lg',
        ], $serviceBlocks);

        $servicesContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow2, $servicesHeading, $servicesGrid]);

        $servicesSection = self::makeSection([$servicesContainer], 'Services & Offerings');

        // 4. Skills & Systems Section
        $eyebrow3 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// CORE STACK & SYSTEMS',
            'anchor_id' => 'stack',
        ]);

        $skillsHeading = self::makeBlock('core.heading', [
            'text'  => 'Core Stack & Technologies',
            'level' => 'h2',
        ]);

        $skill1 = self::makeBlock('portfolio.skill_grid', [
            'group_title' => 'Frontend & Web Platforms',
            'skills'      => 'TypeScript, React, Next.js, Tailwind CSS, Web Components, Responsive Layouts, CWV Tuning',
        ]);

        $skill2 = self::makeBlock('portfolio.skill_grid', [
            'group_title' => 'Backend, Cloud & Databases',
            'skills'      => 'PHP 8.4+, Slate Platform, Node.js, MySQL, Redis, REST APIs, Secure Multi-Tenancy',
        ]);

        $skill3 = self::makeBlock('portfolio.skill_grid', [
            'group_title' => 'Product Design & Systems',
            'skills'      => 'Figma, Design Tokens, Component Architectures, WCAG AAA Accessibility, Rapid Prototyping',
        ]);

        $skillsGrid = self::makeBlock('layout.grid', [
            'columns' => 3,
            'gap'     => 'md',
        ], [$skill1, $skill2, $skill3]);

        $skillsContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow3, $skillsHeading, $skillsGrid]);

        $skillsSection = self::makeSection([$skillsContainer], 'Skills & Technologies');

        // 5. Testimonials Section
        $eyebrow4 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// CLIENT ENDORSEMENTS',
            'anchor_id' => 'testimonials',
        ]);

        $testHeading = self::makeBlock('core.heading', [
            'text'  => 'Client Endorsements',
            'level' => 'h2',
        ]);

        $testBlocks = [];
        foreach ($testimonials as $t) {
            $testBlocks[] = self::makeBlock('portfolio.testimonial_card', [
                'author'     => $t['author'] ?? 'Client',
                'role'       => $t['role'] ?? 'Leader',
                'quote'      => $t['quote'] ?? '',
                'rating'     => (int) ($t['rating'] ?? 5),
                'avatar_url' => $t['avatar_url'] ?? '',
            ]);
        }

        $testimonialsGrid = self::makeBlock('layout.grid', [
            'columns' => count($testBlocks) >= 2 ? 2 : 1,
            'gap'     => 'lg',
        ], $testBlocks);

        $testContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow4, $testHeading, $testimonialsGrid]);

        $testimonialsSection = self::makeSection([$testContainer], 'Client Testimonials');

        // 6. Contact Section
        $eyebrow5 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// GET IN TOUCH',
            'anchor_id' => 'contact',
        ]);

        $contactHeading = self::makeBlock('core.heading', [
            'text'  => "Let's Build Something Remarkable",
            'level' => 'h2',
        ]);

        $contactSubtext = self::makeBlock('core.text', [
            'content' => 'Have an ambitious product idea or need senior leadership for your web application? Fill out the brief below to start the conversation.',
            'size'    => 'base',
        ]);

        $fName = self::makeBlock('core.form_field', [
            'name' => 'client_name', 'field_type' => 'text', 'label' => 'Your Full Name', 'placeholder' => 'Jane Doe', 'required' => true,
        ]);
        $fEmail = self::makeBlock('core.form_field', [
            'name' => 'client_email', 'field_type' => 'email', 'label' => 'Work Email Address', 'placeholder' => 'jane@company.com', 'required' => true,
        ]);
        $fScope = self::makeBlock('core.form_field', [
            'name' => 'project_type', 'field_type' => 'select', 'label' => 'Engagement Type',
            'options' => 'Full-Stack Web Application, Product Design & Design Systems, Technical Advisory & Code Audit, Conversion & Performance Optimization',
            'required' => true,
        ]);
        $fBudget = self::makeBlock('core.form_field', [
            'name' => 'budget_range', 'field_type' => 'select', 'label' => 'Estimated Budget',
            'options' => '$5k – $10k, $10k – $25k, $25k+', 'required' => false,
        ]);
        $fMessage = self::makeBlock('core.form_field', [
            'name' => 'project_details', 'field_type' => 'textarea', 'label' => 'Project Overview & Timeline',
            'placeholder' => 'Tell me about what you are building, your timeline, and key goals...', 'required' => true,
        ]);

        $contactForm = self::makeBlock('core.form', [
            'form_name'      => 'freelancer_inquiry_form',
            'action'         => '#inquiry-sent',
            'method'         => 'post',
            'submit_text'    => 'Send Project Inquiry →',
            'submit_variant' => 'primary',
        ], [$fName, $fEmail, $fScope, $fBudget, $fMessage]);

        $reassurance = self::makeBlock('portfolio.reassurance_badges', []);

        $footerBlock = self::makeBlock('portfolio.footer', [
            'copyright' => "© " . date('Y') . " {$profile['name']}. Crafted with high-performance elegance.",
            'status'    => $profile['status'],
            'location'  => $profile['location'],
        ]);

        $contactContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow5, $contactHeading, $contactSubtext, $contactForm, $reassurance, $footerBlock]);

        $contactSection = self::makeSection([$contactContainer], 'Contact & Inquiries');

        $doc = CanonicalDocumentSchema::emptyDocument(
            'page',
            'default',
            "{$profile['name']} — Portfolio"
        );
        $doc['settings']['header_mode'] = 'hidden';
        $doc['settings']['footer_mode'] = 'hidden';
        $doc['seo']['description'] = "Portfolio of {$profile['name']} — {$profile['role']} specializing in high-impact web applications, design systems, and modern SaaS products.";
        $doc['sections'] = [
            $heroSection,
            $workSection,
            $servicesSection,
            $skillsSection,
            $testimonialsSection,
            $contactSection,
        ];

        return $doc;
    }

    // ── 2. Dedicated Services Page Document ───────────────────────────────────

    public static function getServicesDocument(?int $tenantId = null): array
    {
        $profile = self::getProfile($tenantId);
        $services = self::getServicesList($tenantId);

        $navHeader = self::makeBlock('portfolio.nav_header', [
            'monogram'  => $profile['monogram'],
            'name'      => $profile['name'],
            'role'      => $profile['role'],
            'cta_label' => 'Hire Me ↗',
            'cta_href'  => '/contact',
        ]);

        $eyebrow = self::makeBlock('portfolio.eyebrow', ['text' => '// SERVICES & ENGAGEMENT MODELS']);
        $heading = self::makeBlock('core.heading', ['text' => 'High-Impact Engineering & Product Services', 'level' => 'h1']);
        $subtext = self::makeBlock('core.text', ['content' => 'From greenfield architecture to fractional technical leadership, explore structured engagement models engineered for measurable ROI.', 'size' => 'lg']);

        $serviceBlocks = [];
        foreach ($services as $s) {
            $serviceBlocks[] = self::makeBlock('portfolio.service_card', [
                'number'       => $s['number'] ?? '01',
                'title'        => $s['title'] ?? 'Service',
                'description'  => $s['description'] ?? '',
                'deliverables' => $s['deliverables'] ?? '',
            ]);
        }

        $grid = self::makeBlock('layout.grid', ['columns' => count($serviceBlocks) >= 3 ? 3 : 2, 'gap' => 'lg'], $serviceBlocks);
        $container = self::makeBlock('layout.container', ['width' => 'constrained'], [$navHeader, $eyebrow, $heading, $subtext, $grid]);
        $mainSection = self::makeSection([$container], 'Services & Offerings');

        // Footer Section
        $footerBlock = self::makeBlock('portfolio.footer', [
            'copyright' => "© " . date('Y') . " {$profile['name']}. All rights reserved.",
            'status'    => $profile['status'],
            'location'  => $profile['location'],
        ]);
        $footerContainer = self::makeBlock('layout.container', ['width' => 'constrained'], [$footerBlock]);
        $footerSection = self::makeSection([$footerContainer], 'Footer');

        $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', "Services & Offerings — {$profile['name']}");
        $doc['settings']['header_mode'] = 'hidden';
        $doc['settings']['footer_mode'] = 'hidden';
        $doc['seo']['description'] = "Services provided by {$profile['name']}: Product Architecture, Full-Stack Web Engineering, Performance & SEO.";
        $doc['sections'] = [$mainSection, $footerSection];
        return $doc;
    }

    // ── 3. Dedicated Case Studies Page Document ───────────────────────────────

    public static function getCaseStudiesDocument(?int $tenantId = null): array
    {
        $profile = self::getProfile($tenantId);
        $projects = self::getProjects($tenantId);

        $navHeader = self::makeBlock('portfolio.nav_header', [
            'monogram'  => $profile['monogram'],
            'name'      => $profile['name'],
            'role'      => $profile['role'],
            'cta_label' => 'Discuss Project ↗',
            'cta_href'  => '/contact',
        ]);

        $eyebrow = self::makeBlock('portfolio.eyebrow', ['text' => '// DETAILED CASE STUDIES']);
        $heading = self::makeBlock('core.heading', ['text' => 'Engineering Deep Dives & Measurable Results', 'level' => 'h1']);
        $subtext = self::makeBlock('core.text', ['content' => 'Comprehensive technical breakdowns of enterprise SaaS applications, tokenized design systems, and generative AI tools.', 'size' => 'lg']);

        $projectBlocks = [];
        foreach ($projects as $p) {
            $projectBlocks[] = self::makeBlock('portfolio.project_card', [
                'title'       => $p['title'] ?? 'Case Study',
                'category'    => $p['category'] ?? 'Architecture',
                'year'        => $p['year'] ?? '2026',
                'description' => $p['description'] ?? '',
                'image_url'   => $p['image_url'] ?? '',
                'metric'      => $p['metric'] ?? '',
                'tags'        => $p['tags'] ?? '',
                'link_url'    => $p['link_url'] ?? '#',
                'link_text'   => 'Live Demo & Source ↗',
            ]);
        }

        $grid = self::makeBlock('layout.grid', ['columns' => 2, 'gap' => 'lg'], $projectBlocks);
        $container = self::makeBlock('layout.container', ['width' => 'constrained'], [$navHeader, $eyebrow, $heading, $subtext, $grid]);
        $mainSection = self::makeSection([$container], 'Case Studies');

        $footerBlock = self::makeBlock('portfolio.footer', [
            'copyright' => "© " . date('Y') . " {$profile['name']}. All rights reserved.",
            'status'    => $profile['status'],
            'location'  => $profile['location'],
        ]);
        $footerContainer = self::makeBlock('layout.container', ['width' => 'constrained'], [$footerBlock]);
        $footerSection = self::makeSection([$footerContainer], 'Footer');

        $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', "Case Studies — {$profile['name']}");
        $doc['settings']['header_mode'] = 'hidden';
        $doc['settings']['footer_mode'] = 'hidden';
        $doc['seo']['description'] = "Case studies and architectural breakdowns by {$profile['name']}.";
        $doc['sections'] = [$mainSection, $footerSection];
        return $doc;
    }

    // ── 4. Dedicated Testimonials Page Document ───────────────────────────────

    public static function getTestimonialsDocument(?int $tenantId = null): array
    {
        $profile = self::getProfile($tenantId);
        $testimonials = self::getTestimonialsList($tenantId);

        $navHeader = self::makeBlock('portfolio.nav_header', [
            'monogram'  => $profile['monogram'],
            'name'      => $profile['name'],
            'role'      => $profile['role'],
            'cta_label' => 'Get in Touch ↗',
            'cta_href'  => '/contact',
        ]);

        $eyebrow = self::makeBlock('portfolio.eyebrow', ['text' => '// CLIENT ENDORSEMENTS & SOCIAL PROOF']);
        $heading = self::makeBlock('core.heading', ['text' => 'What Founders & Engineering Leaders Say', 'level' => 'h1']);
        $subtext = self::makeBlock('core.text', ['content' => 'Real feedback from venture-backed founders, VP of Engineering leaders, and product design executives.', 'size' => 'lg']);

        $testBlocks = [];
        foreach ($testimonials as $t) {
            $testBlocks[] = self::makeBlock('portfolio.testimonial_card', [
                'author'     => $t['author'] ?? 'Client',
                'role'       => $t['role'] ?? 'Leader',
                'quote'      => $t['quote'] ?? '',
                'rating'     => (int) ($t['rating'] ?? 5),
                'avatar_url' => $t['avatar_url'] ?? '',
            ]);
        }

        $grid = self::makeBlock('layout.grid', ['columns' => count($testBlocks) >= 2 ? 2 : 1, 'gap' => 'lg'], $testBlocks);
        $container = self::makeBlock('layout.container', ['width' => 'constrained'], [$navHeader, $eyebrow, $heading, $subtext, $grid]);
        $mainSection = self::makeSection([$container], 'Testimonials');

        $footerBlock = self::makeBlock('portfolio.footer', [
            'copyright' => "© " . date('Y') . " {$profile['name']}. All rights reserved.",
            'status'    => $profile['status'],
            'location'  => $profile['location'],
        ]);
        $footerContainer = self::makeBlock('layout.container', ['width' => 'constrained'], [$footerBlock]);
        $footerSection = self::makeSection([$footerContainer], 'Footer');

        $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', "Client Reviews — {$profile['name']}");
        $doc['settings']['header_mode'] = 'hidden';
        $doc['settings']['footer_mode'] = 'hidden';
        $doc['seo']['description'] = "Client endorsements and reviews for {$profile['name']}.";
        $doc['sections'] = [$mainSection, $footerSection];
        return $doc;
    }

    // ── 5. Dedicated Contact & Inquiry Page Document ──────────────────────────

    public static function getContactDocument(?int $tenantId = null): array
    {
        $profile = self::getProfile($tenantId);

        $navHeader = self::makeBlock('portfolio.nav_header', [
            'monogram'  => $profile['monogram'],
            'name'      => $profile['name'],
            'role'      => $profile['role'],
            'cta_label' => 'Back to Work ↓',
            'cta_href'  => '/portfolio',
        ]);

        $eyebrow = self::makeBlock('portfolio.eyebrow', ['text' => '// PROJECT CONSULTATION & BRIEF']);
        $heading = self::makeBlock('core.heading', ['text' => "Let's Build Something Category-Defining", 'level' => 'h1']);
        $subtext = self::makeBlock('core.text', ['content' => 'Have an ambitious web application or looking for a fractional product engineer? Submit your brief below to receive a proposal within 24 hours.', 'size' => 'lg']);

        $fName = self::makeBlock('core.form_field', [
            'name' => 'client_name', 'field_type' => 'text', 'label' => 'Your Full Name', 'placeholder' => 'Jane Doe', 'required' => true,
        ]);
        $fEmail = self::makeBlock('core.form_field', [
            'name' => 'client_email', 'field_type' => 'email', 'label' => 'Work Email Address', 'placeholder' => 'jane@company.com', 'required' => true,
        ]);
        $fScope = self::makeBlock('core.form_field', [
            'name' => 'project_type', 'field_type' => 'select', 'label' => 'Engagement Scope',
            'options' => 'Full-Stack Web Application, Product Design & Design Systems, Technical Advisory & Code Audit, Conversion & Performance Optimization',
            'required' => true,
        ]);
        $fBudget = self::makeBlock('core.form_field', [
            'name' => 'budget_range', 'field_type' => 'select', 'label' => 'Estimated Budget',
            'options' => '$5k – $10k, $10k – $25k, $25k+', 'required' => false,
        ]);
        $fMessage = self::makeBlock('core.form_field', [
            'name' => 'project_details', 'field_type' => 'textarea', 'label' => 'Project Timeline & Goals',
            'placeholder' => 'Tell me about the product requirements, timeline, and key technical considerations...', 'required' => true,
        ]);

        $contactForm = self::makeBlock('core.form', [
            'form_name'      => 'contact_page_form',
            'action'         => '#inquiry-sent',
            'method'         => 'post',
            'submit_text'    => 'Submit Project Brief →',
            'submit_variant' => 'primary',
        ], [$fName, $fEmail, $fScope, $fBudget, $fMessage]);

        $reassurance = self::makeBlock('portfolio.reassurance_badges', []);

        $footerBlock = self::makeBlock('portfolio.footer', [
            'copyright' => "© " . date('Y') . " {$profile['name']}. All rights reserved.",
            'status'    => $profile['status'],
            'location'  => $profile['location'],
        ]);

        $container = self::makeBlock('layout.container', ['width' => 'constrained'], [$navHeader, $eyebrow, $heading, $subtext, $contactForm, $reassurance, $footerBlock]);
        $mainSection = self::makeSection([$container], 'Contact & Inquiries');

        $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', "Contact & Inquiries — {$profile['name']}");
        $doc['settings']['header_mode'] = 'hidden';
        $doc['settings']['footer_mode'] = 'hidden';
        $doc['seo']['description'] = "Get in touch with {$profile['name']} for product design, web engineering, and technical advisory.";
        $doc['sections'] = [$mainSection];
        return $doc;
    }

    // ── 6. Atomic Multi-Page Site Publisher ───────────────────────────────────

    /**
     * Creates and publishes all 5 pages for the tenant:
     * - `/portfolio` (Home / Showcase)
     * - `/services` (Dedicated Services Page)
     * - `/case-studies` (Dedicated Case Studies Page)
     * - `/testimonials` (Dedicated Client Reviews Page)
     * - `/contact` (Dedicated Contact & Inquiry Page)
     *
     * @return array<string, array{page_id: int, revision_id: int, path: string}>
     */
    public static function publishEntireSite(int $tenantId, ?int $userId = null): array
    {
        if (!Studio::widgets()->has('portfolio.project_card')) {
            $plugin = new self('studio-freelancer-portfolio', ['version' => '1.0.0'], __DIR__);
            $plugin->boot();
        }

        $rt = StudioRuntimeFactory::build();
        $uid = $userId ?? (int) (Database::value('SELECT id FROM users WHERE tenant_id = ? ORDER BY id ASC LIMIT 1', [$tenantId]) ?: 1);
        $actor = StudioActor::authenticated($uid, StudioPermissions::ALL);

        $pagesToBuild = [
            [
                'slug'     => 'portfolio',
                'title'    => 'Portfolio Showcase',
                'doc_fn'   => [self::class, 'getPortfolioDocument'],
                'mode'     => 'standalone',
                'summary'  => 'Publish dynamic portfolio showcase',
            ],
            [
                'slug'     => 'services',
                'title'    => 'Services & Deliverables',
                'doc_fn'   => [self::class, 'getServicesDocument'],
                'mode'     => 'standalone',
                'summary'  => 'Publish dedicated services & engagement models',
            ],
            [
                'slug'     => 'case-studies',
                'title'    => 'Case Studies & Technical Deep Dives',
                'doc_fn'   => [self::class, 'getCaseStudiesDocument'],
                'mode'     => 'standalone',
                'summary'  => 'Publish engineering case studies & metrics',
            ],
            [
                'slug'     => 'testimonials',
                'title'    => 'Client Endorsements & Founder Reviews',
                'doc_fn'   => [self::class, 'getTestimonialsDocument'],
                'mode'     => 'standalone',
                'summary'  => 'Publish verified client endorsements',
            ],
            [
                'slug'     => 'contact',
                'title'    => 'Project Consultation & Inquiries',
                'doc_fn'   => [self::class, 'getContactDocument'],
                'mode'     => 'standalone',
                'summary'  => 'Publish project inquiry & brief form',
            ],
        ];

        $results = [];

        $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $pagesToBuild, $tenantId, &$results): void {
            foreach ($pagesToBuild as $cfg) {
                $slug = $cfg['slug'];
                $existing = $rt->pages->findBySlug($slug, 'page');

                if ($existing !== null) {
                    $pageId = (int) $existing['id'];
                    $revId  = (int) ($existing['active_draft_revision_id'] ?? $existing['working_revision_id'] ?? 0);
                } else {
                    $created = $rt->app->createPage($actor, $cfg['title'], $slug, 'page', $cfg['mode']);
                    $pageId = (int) $created['page']['id'];
                    $revId  = (int) $created['revision']['id'];
                }

                $doc = call_user_func($cfg['doc_fn'], $tenantId);
                $saved = $rt->app->saveDraft($actor, $pageId, $doc, $revId, 'manual', $cfg['summary']);
                $newRevId = (int) $saved['revision']['id'];

                $published = $rt->app->publish($actor, $pageId, $newRevId, $cfg['summary']);

                $results[$slug] = [
                    'page_id'     => $pageId,
                    'revision_id' => $newRevId,
                    'path'        => '/' . $slug,
                ];
            }
        });

        return $results;
    }
}
