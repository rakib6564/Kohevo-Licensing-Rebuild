<?php
/**
 * Studio Freelancer Portfolio — Extension Plugin for Kohevo Studio.
 *
 * Implements an elegant, modern freelancer portfolio website kit:
 * - Registers custom widgets:
 *     - `portfolio.project_card`: Sleek case study preview with metric badges, tags, and link actions.
 *     - `portfolio.stat_highlight`: Animated/gradient stat metric counters.
 *     - `portfolio.skill_grid`: Technical skill badges grouped by domain.
 *     - `portfolio.experience_timeline`: Career milestones and client history.
 *     - `portfolio.service_card`: Premium service offering cards.
 * - Provides canonical document generator `getPortfolioDocument()` for instant visual page building.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Sdk\Studio;

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
                ['key' => 'badge1', 'type' => 'string', 'label' => 'Badge 1', 'required' => false, 'default' => '🔒 Strict NDA Guaranteed', 'max_length' => 80],
                ['key' => 'badge2', 'type' => 'string', 'label' => 'Badge 2', 'required' => false, 'default' => '⚡ Direct Reply Within 24h', 'max_length' => 80],
                ['key' => 'badge3', 'type' => 'string', 'label' => 'Badge 3', 'required' => false, 'default' => '🤝 Senior Leadership Only', 'max_length' => 80],
            ],
            'renderer'    => [self::class, 'renderReassuranceBadges'],
        ]);

        // 3. Register `portfolio.project_card`
        Studio::widgets()->register([
            'type'        => 'portfolio.project_card',
            'version'     => 1,
            'label'       => 'Project Case Study Card',
            'category'    => 'portfolio',
            'icon'        => 'briefcase',
            'schema'      => [
                ['key' => 'title', 'type' => 'string', 'label' => 'Project Title', 'required' => true, 'default' => 'Solaya Analytics Platform', 'max_length' => 120],
                ['key' => 'category', 'type' => 'string', 'label' => 'Category / Subtitle', 'required' => false, 'default' => 'Fintech / SaaS Architecture', 'max_length' => 80],
                ['key' => 'year', 'type' => 'string', 'label' => 'Release Year', 'required' => false, 'default' => '2026', 'max_length' => 20],
                ['key' => 'description', 'type' => 'text', 'label' => 'Summary Description', 'required' => true, 'default' => 'End-to-end product design, tokenized design system, and responsive front-end implementation.', 'max_length' => 600],
                ['key' => 'image_url', 'type' => 'url', 'label' => 'Mockup / Cover Image URL', 'required' => false, 'default' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80'],
                ['key' => 'metric', 'type' => 'string', 'label' => 'Key Metric / Outcome', 'required' => false, 'default' => '+185% Daily Active Users', 'max_length' => 60],
                ['key' => 'tags', 'type' => 'string', 'label' => 'Tags (comma separated)', 'required' => false, 'default' => 'Product Design, React, TypeScript, Design Systems', 'max_length' => 200],
                ['key' => 'link_url', 'type' => 'url', 'label' => 'Case Study Link', 'required' => false, 'default' => '#'],
                ['key' => 'link_text', 'type' => 'string', 'label' => 'Link Label', 'required' => false, 'default' => 'View Case Study →', 'max_length' => 60],
            ],
            'renderer'    => [self::class, 'renderProjectCard'],
        ]);

        // 4. Register `portfolio.stat_highlight`
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

        // 5. Register `portfolio.skill_grid`
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

        // 6. Register `portfolio.experience_timeline`
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

        // 7. Register `portfolio.service_card`
        Studio::widgets()->register([
            'type'        => 'portfolio.service_card',
            'version'     => 1,
            'label'       => 'Service Offering Card',
            'category'    => 'portfolio',
            'icon'        => 'layers',
            'schema'      => [
                ['key' => 'number', 'type' => 'string', 'label' => 'Index Number', 'required' => false, 'default' => '01', 'max_length' => 10],
                ['key' => 'title', 'type' => 'string', 'label' => 'Service Title', 'required' => true, 'default' => 'Product Design & Design Systems', 'max_length' => 120],
                ['key' => 'description', 'type' => 'text', 'label' => 'Deliverables & Description', 'required' => true, 'default' => 'User research, rapid wireframing, high-fidelity Figma prototypes, and multi-brand tokenized design systems.', 'max_length' => 500],
                ['key' => 'deliverables', 'type' => 'string', 'label' => 'Deliverables (comma separated)', 'required' => false, 'default' => '', 'max_length' => 400],
            ],
            'renderer'    => [self::class, 'renderServiceCard'],
        ]);

        // 8. Register `portfolio.testimonial_card`
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

        return self::renderStylesOnce() . '<nav class="sb-portfolio-nav" data-sb-custom-widget="portfolio.nav_header">'
            . '<a href="#" class="sb-portfolio-brand">'
            . '<div class="sb-portfolio-brand-avatar">' . Html::e($monogram) . '</div>'
            . '<div class="sb-portfolio-brand-info">'
            . '<span class="sb-portfolio-brand-name">' . Html::e($name) . '</span>'
            . '<span class="sb-portfolio-brand-role">' . Html::e($role) . '</span>'
            . '</div>'
            . '</a>'
            . '<ul class="sb-portfolio-nav-links">'
            . '<li><a href="#work" class="sb-portfolio-nav-link">Selected Work</a></li>'
            . '<li><a href="#services" class="sb-portfolio-nav-link">Expertise</a></li>'
            . '<li><a href="#stack" class="sb-portfolio-nav-link">Tech Stack</a></li>'
            . '<li><a href="#testimonials" class="sb-portfolio-nav-link">Endorsements</a></li>'
            . '</ul>'
            . '<a href="' . Html::e($ctaHref) . '" class="sb-portfolio-nav-cta">' . Html::e($ctaLabel) . '</a>'
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
            . '<a href="mailto:elena@example.com" class="sb-portfolio-social-link">Email ↗</a>'
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
        $b1 = $scope->string('badge1', '🔒 Strict NDA Guaranteed');
        $b2 = $scope->string('badge2', '⚡ Direct Reply Within 24h');
        $b3 = $scope->string('badge3', '🤝 Senior Leadership Only');

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

    /**
     * Helper to construct a canonical block structure.
     */
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

    /**
     * Helper to construct a canonical section structure.
     */
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

    /**
     * Generates the complete, elegant freelancer portfolio canonical document.
     * Ready for direct saving and publishing via StudioApplicationService.
     *
     * @return array<string, mixed>
     */
    public static function getPortfolioDocument(): array
    {
        // ── 1. Hero Section ──────────────────────────────────────────────────
        $navHeader = self::makeBlock('portfolio.nav_header', [
            'monogram'  => 'ER',
            'name'      => 'Elena Rostova',
            'role'      => 'Principal Product Engineer',
            'cta_label' => 'Book Intro ↗',
            'cta_href'  => '#contact',
        ]);

        $statusPill = self::makeBlock('portfolio.status_pill', [
            'text' => 'Available for Select Product Engagements · Q4 2026',
        ]);

        $heroHeading = self::makeBlock('core.heading', [
            'text'  => 'Designing & Engineering Category-Defining Digital Products.',
            'level' => 'h1',
        ]);

        $heroSubtext = self::makeBlock('core.text', [
            'content' => 'I partner with venture-backed founders and forward-thinking product teams to craft high-impact web applications, fluid user interfaces, and scalable design systems that convert.',
            'size'    => 'lg',
        ]);

        $btnWork = self::makeBlock('core.button', [
            'link'    => [
                'label'  => 'Explore Selected Work ↓',
                'href'   => '#work',
                'target' => '_self',
            ],
            'variant' => 'primary',
            'size'    => 'lg',
        ]);

        $btnContact = self::makeBlock('core.button', [
            'link'    => [
                'label'  => 'Book 15-Min Intro ↗',
                'href'   => '#contact',
                'target' => '_self',
            ],
            'variant' => 'outline',
            'size'    => 'lg',
        ]);

        $heroActions = self::makeBlock('layout.flex', [
            'direction' => 'row',
            'gap'       => 'md',
            'wrap'      => 'wrap',
        ], [$btnWork, $btnContact]);

        // Stat Highlights
        $stat1 = self::makeBlock('portfolio.stat_highlight', ['number' => '9+', 'label' => 'Years Crafting Products']);
        $stat2 = self::makeBlock('portfolio.stat_highlight', ['number' => '42+', 'label' => 'Shipped Web Applications']);
        $stat3 = self::makeBlock('portfolio.stat_highlight', ['number' => '$180M+', 'label' => 'Client Venture Valuation']);
        $stat4 = self::makeBlock('portfolio.stat_highlight', ['number' => '100%', 'label' => 'On-Time Delivery Record']);

        $statsGrid = self::makeBlock('layout.grid', [
            'columns' => 4,
            'gap'     => 'md',
        ], [$stat1, $stat2, $stat3, $stat4]);

        $heroContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$navHeader, $statusPill, $heroHeading, $heroSubtext, $heroActions, $statsGrid]);

        $heroSection = self::makeSection([$heroContainer], 'Hero & Introduction');

        // ── 2. Featured Projects / Work Section ──────────────────────────────
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

        // Project Cards
        $proj1 = self::makeBlock('portfolio.project_card', [
            'title'       => 'Solaya Analytics Platform',
            'category'    => 'Fintech / SaaS Analytics',
            'year'        => '2026',
            'description' => 'Complete front-end architecture and visual analytics dashboard for a multi-tenant portfolio management system.',
            'image_url'   => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
            'metric'      => '+185% Daily Active Users',
            'tags'        => 'Product Architecture, React, TypeScript, High-Performance Canvas',
            'link_url'    => '#solaya-case-study',
            'link_text'   => 'View Case Study →',
        ]);

        $proj2 = self::makeBlock('portfolio.project_card', [
            'title'       => 'Apex Enterprise Design System',
            'category'    => 'Multi-Brand UI System',
            'year'        => '2025',
            'description' => 'A unified design system spanning 45+ components with zero runtime CSS regressions, strict WCAG AAA compliance, and automated token synchronization.',
            'image_url'   => 'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?auto=format&fit=crop&w=1200&q=80',
            'metric'      => 'Adopted Across 14 Teams',
            'tags'        => 'Design Tokens, Figma, Tailwind CSS, Accessibility',
            'link_url'    => '#apex-case-study',
            'link_text'   => 'View Case Study →',
        ]);

        $proj3 = self::makeBlock('portfolio.project_card', [
            'title'       => 'Nexus AI Generative Workspace',
            'category'    => 'Generative UI & Collaboration',
            'year'        => '2025',
            'description' => 'Interactive visual node workspace enabling creative teams to orchestrate multimodal generative pipelines with real-time feedback.',
            'image_url'   => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=1200&q=80',
            'metric'      => 'Series A Funded ($14M)',
            'tags'        => 'Generative UI, WebSockets, Next.js, Micro-interactions',
            'link_url'    => '#nexus-case-study',
            'link_text'   => 'View Case Study →',
        ]);

        $proj4 = self::makeBlock('portfolio.project_card', [
            'title'       => 'Chrono Practice Management',
            'category'    => 'SaaS Productivity',
            'year'        => '2024',
            'description' => 'Modern client portal, time-tracking, and automated billing software engineered specifically for elite independent consultants and boutique agencies.',
            'image_url'   => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
            'metric'      => '4.9 ★ Average App Rating',
            'tags'        => 'Slate Engine, PostgreSQL, Stripe Invoicing, Responsive UI',
            'link_url'    => '#chrono-case-study',
            'link_text'   => 'View Case Study →',
        ]);

        $projectsGrid = self::makeBlock('layout.grid', [
            'columns' => 2,
            'gap'     => 'lg',
        ], [$proj1, $proj2, $proj3, $proj4]);

        $workContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow1, $workHeading, $workSubtext, $projectsGrid]);

        $workSection = self::makeSection([$workContainer], 'Selected Work');

        // ── 3. Services & Capabilities Section ───────────────────────────────
        $eyebrow2 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// AREAS OF EXPERTISE',
            'anchor_id' => 'services',
        ]);

        $servicesHeading = self::makeBlock('core.heading', [
            'text'  => 'Areas of Expertise',
            'level' => 'h2',
        ]);

        $service1 = self::makeBlock('portfolio.service_card', [
            'number'       => '01',
            'title'        => 'Product Design & Architecture',
            'description'  => 'Deep UX research, interactive wireframing, high-fidelity Figma components, and ergonomic design systems tailored for rapid engineering velocity.',
            'deliverables' => 'Design System Tokens, Figma Component Library, Interactive Prototypes, UX Audit & Journey Maps',
        ]);

        $service2 = self::makeBlock('portfolio.service_card', [
            'number'       => '02',
            'title'        => 'Full-Stack Web Engineering',
            'description'  => 'Clean, maintainable web applications engineered with modern TypeScript, React, PHP/Slate, and robust server architectures designed to handle traffic spikes.',
            'deliverables' => 'Production Web Application, Responsive Front-End, Scalable API Architecture, CI/CD & Deployment',
        ]);

        $service3 = self::makeBlock('portfolio.service_card', [
            'number'       => '03',
            'title'        => 'Performance, SEO & Conversion',
            'description'  => 'Sub-second Core Web Vitals optimization, semantic accessibility, technical search optimization, and conversion-engineered landing pages.',
            'deliverables' => 'Sub-second CWV Score, WCAG AAA Compliance, Technical SEO Setup, High-Conversion UX Audit',
        ]);

        $servicesGrid = self::makeBlock('layout.grid', [
            'columns' => 3,
            'gap'     => 'lg',
        ], [$service1, $service2, $service3]);

        $servicesContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow2, $servicesHeading, $servicesGrid]);

        $servicesSection = self::makeSection([$servicesContainer], 'Services & Offerings');

        // ── 4. Technical Stack & Skills ──────────────────────────────────────
        $eyebrow3 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// CORE STACK & SYSTEMS',
            'anchor_id' => 'stack',
        ]);

        $skillsHeading = self::makeBlock('core.heading', [
            'text'  => 'Core Stack & Technologies',
            'level' => 'h2',
        ]);

        $skillGroup1 = self::makeBlock('portfolio.skill_grid', [
            'group_title' => 'Frontend & Web Platforms',
            'skills'      => 'TypeScript, React, Next.js, Tailwind CSS, Web Components, Responsive Layouts, CWV Tuning',
        ]);

        $skillGroup2 = self::makeBlock('portfolio.skill_grid', [
            'group_title' => 'Backend, Cloud & Databases',
            'skills'      => 'PHP 8.3+, Slate Platform, Node.js, MySQL, Redis, REST APIs, Secure Multi-Tenancy',
        ]);

        $skillGroup3 = self::makeBlock('portfolio.skill_grid', [
            'group_title' => 'Product Design & Systems',
            'skills'      => 'Figma, Design Tokens, Component Architectures, WCAG AAA Accessibility, Rapid Prototyping',
        ]);

        $skillsGrid = self::makeBlock('layout.grid', [
            'columns' => 3,
            'gap'     => 'md',
        ], [$skillGroup1, $skillGroup2, $skillGroup3]);

        $skillsContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow3, $skillsHeading, $skillsGrid]);

        $skillsSection = self::makeSection([$skillsContainer], 'Skills & Technologies');

        // ── 5. Client Endorsements & Testimonials ────────────────────────────
        $eyebrow4 = self::makeBlock('portfolio.eyebrow', [
            'text'      => '// CLIENT ENDORSEMENTS',
            'anchor_id' => 'testimonials',
        ]);

        $testHeading = self::makeBlock('core.heading', [
            'text'  => 'Client Endorsements',
            'level' => 'h2',
        ]);

        $testimonial1 = self::makeBlock('portfolio.testimonial_card', [
            'author'     => 'Marcus Vance',
            'role'       => 'VP of Product, CloudScale Inc',
            'quote'      => 'Elena possesses the rare ability to bridge visionary product design with flawless engineering. She elevated our entire platform experience in weeks.',
            'rating'     => 5,
            'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80',
        ]);

        $testimonial2 = self::makeBlock('portfolio.testimonial_card', [
            'author'     => 'Sarah Lin',
            'role'       => 'Founder & CEO, Nexus AI Labs',
            'quote'      => 'Shipped our MVP ahead of schedule. The design system she architected saved our core team hundreds of engineering hours.',
            'rating'     => 5,
            'avatar_url' => 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=400&q=80',
        ]);

        $testimonialsGrid = self::makeBlock('layout.grid', [
            'columns' => 2,
            'gap'     => 'lg',
        ], [$testimonial1, $testimonial2]);

        $testContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow4, $testHeading, $testimonialsGrid]);

        $testimonialsSection = self::makeSection([$testContainer], 'Client Testimonials');

        // ── 6. Project Inquiry / Consultation Contact ────────────────────────
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

        // Form Fields
        $fName = self::makeBlock('core.form_field', [
            'name'        => 'client_name',
            'field_type'  => 'text',
            'label'       => 'Your Full Name',
            'placeholder' => 'Jane Doe',
            'required'    => true,
        ]);

        $fEmail = self::makeBlock('core.form_field', [
            'name'        => 'client_email',
            'field_type'  => 'email',
            'label'       => 'Work Email Address',
            'placeholder' => 'jane@company.com',
            'required'    => true,
        ]);

        $fScope = self::makeBlock('core.form_field', [
            'name'        => 'project_type',
            'field_type'  => 'select',
            'label'       => 'Engagement Type',
            'options'     => 'Full-Stack Web Application, Product Design & Design Systems, Technical Advisory & Code Audit, Conversion & Performance Optimization',
            'required'    => true,
        ]);

        $fBudget = self::makeBlock('core.form_field', [
            'name'        => 'budget_range',
            'field_type'  => 'select',
            'label'       => 'Estimated Budget',
            'options'     => '$5k – $10k, $10k – $25k, $25k+',
            'required'    => false,
        ]);

        $fMessage = self::makeBlock('core.form_field', [
            'name'        => 'project_details',
            'field_type'  => 'textarea',
            'label'       => 'Project Overview & Timeline',
            'placeholder' => 'Tell me about what you are building, your timeline, and key goals...',
            'required'    => true,
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
            'copyright' => '© 2026 Elena Rostova. Crafted with high-performance elegance.',
            'status'    => 'Available for Select Q4 Engagements',
            'location'  => 'San Francisco, CA · Remote Worldwide',
        ]);

        $contactContainer = self::makeBlock('layout.container', [
            'width' => 'constrained',
        ], [$eyebrow5, $contactHeading, $contactSubtext, $contactForm, $reassurance, $footerBlock]);

        $contactSection = self::makeSection([$contactContainer], 'Contact & Inquiries');

        // ── 7. Document Assembly ─────────────────────────────────────────────
        $doc = CanonicalDocumentSchema::emptyDocument(
            'page',
            'default',
            'Elena Rostova — Principal Product Designer & Full-Stack Engineer'
        );

        $doc['settings']['header_mode'] = 'hidden';
        $doc['settings']['footer_mode'] = 'hidden';
        $doc['seo']['description'] = 'Portfolio of Elena Rostova — Principal Product Designer & Senior Design Engineer specializing in high-impact web applications, design systems, and modern SaaS products.';
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
}
