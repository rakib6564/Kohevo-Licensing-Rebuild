<?php
/**
 * Kohevo Studio (studio-builder) — the composed section preset catalogue.
 *
 * Pure and deterministic: no database, no clock, no randomness. Each entry is a real,
 * editable `section_preset` document built only from registered blocks and their declared
 * props (heading, text, button, grid, feature list, accordion, form, ...). Inserting one
 * copies it into the page through the template pipeline, which re-mints every id.
 *
 * Ids are derived from the preset key, so rebuilding the catalogue produces byte-identical
 * documents and the seeder can tell "unchanged" from "changed" by comparing JSON.
 *
 * Bump VERSION when copy or structure changes; tests pin that every preset validates,
 * normalizes idempotently and renders. Text is neutral placeholder copy (English) that the
 * site owner replaces; there are no images because media references are tenant-specific.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Presets;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

final class SectionPresetCatalog
{
    public const VERSION = 1;

    /** Template keys are `system-section-<slug>`: reserved, immutable, copyable. */
    public const KEY_PREFIX = 'system-section-';

    /** Category slugs in display order; labels are translated by the UI/service. */
    public const CATEGORIES = ['hero', 'features', 'content', 'social-proof', 'pricing', 'cta', 'team', 'contact', 'footer'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $memo = null;

    /**
     * @return list<array{key: string, slug: string, name: string, category: string, description: string, document: array<string, mixed>}>
     */
    public static function all(): array
    {
        if (self::$memo === null) {
            self::$memo = self::build();
        }
        return array_values(self::$memo);
    }

    // ── catalogue ───────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        $presets = [];
        $add = static function (string $slug, string $name, string $category, string $description, array $layout, callable $blocks) use (&$presets): void {
            $key = self::KEY_PREFIX . $slug;
            $n = 0;
            $ctx = static function () use ($key, &$n): string {
                $n++;
                return 'blk_' . substr(hash('sha256', $key . ':' . $n), 0, 24);
            };
            $document = CanonicalDocumentSchema::emptyDocument('section_preset', 'default', $name);
            $document['sections'] = [[
                'id'         => 'sec_' . substr(hash('sha256', $key . ':section'), 0, 24),
                'label'      => $name,
                'global_ref' => null,
                'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), $layout),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'blocks'     => $blocks($ctx),
            ]];
            $presets[$key] = [
                'key' => $key, 'slug' => $slug, 'name' => $name, 'category' => $category,
                'description' => $description, 'document' => $document,
            ];
        };

        // ── Hero ────────────────────────────────────────────────────────────────
        $add('hero-classic', 'Hero — Classic', 'hero', 'Headline, short pitch and one button.', ['padding_y' => ['base' => 'xl', 'md' => '2xl']],
            static fn(callable $id): array => [
                self::block($id, 'core.hero', [
                    'eyebrow' => 'Welcome',
                    'heading' => 'A clear promise for your customers',
                    'subheading' => 'Say in one or two sentences what you offer, who it is for and why it is worth their time.',
                    'primary_cta' => ['label' => 'Get started', 'href' => '/contact'],
                ]),
            ]);

        $add('hero-centered', 'Hero — Centered', 'hero', 'Centered headline with two buttons.', ['padding_y' => ['base' => 'xl', 'md' => '2xl'], 'width' => 'narrow'],
            static fn(callable $id): array => [
                self::block($id, 'layout.container', ['width' => 'compact', 'alignment' => 'center', 'padding' => 'none'], [
                    self::text($id, 'Introducing', 'sm', 'center'),
                    self::heading($id, 'Build something your customers will love', 'h1', 'center'),
                    self::text($id, 'A short supporting line that explains the value in plain words.', 'lead', 'center'),
                    self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'wrap', 'justify' => 'center', 'align' => 'center', 'gap' => 'md'], [
                        self::button($id, 'Get started', '/contact', 'primary', 'lg'),
                        self::button($id, 'Learn more', '#features', 'outline', 'lg'),
                    ]),
                ]),
            ]);

        $add('hero-split', 'Hero — Split', 'hero', 'Pitch on the left, key points on the right.', ['padding_y' => ['base' => 'xl', 'md' => '2xl']],
            static fn(callable $id): array => [
                self::block($id, 'layout.grid', ['columns' => 2, 'gap' => 'xl', 'align' => 'center'], [
                    self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'none'], [
                        self::heading($id, 'Everything your business needs, in one place', 'h1'),
                        self::text($id, 'Describe the main benefit and invite visitors to take the next step.', 'lead'),
                        self::button($id, 'Book a call', '/contact', 'primary', 'lg'),
                    ]),
                    self::card($id, [
                        self::heading($id, 'Why customers choose us', 'h3'),
                        self::block($id, 'core.rich_text', ['content' => '<ul><li>Fast, friendly service</li><li>Clear, honest pricing</li><li>Support whenever you need it</li></ul>']),
                    ]),
                ]),
            ]);

        // ── Features ────────────────────────────────────────────────────────────
        $threeFeatures = [
            ['heading' => 'Simple to start', 'body' => 'Explain the first benefit in a sentence or two.'],
            ['heading' => 'Built to last', 'body' => 'Explain the second benefit in a sentence or two.'],
            ['heading' => 'Always supported', 'body' => 'Explain the third benefit in a sentence or two.'],
        ];
        $add('features-grid', 'Features — Grid', 'features', 'Heading with three feature cards.', [],
            static fn(callable $id): array => [
                self::heading($id, 'Why choose us', 'h2', 'center'),
                self::text($id, 'Three reasons customers stay with us.', 'lead', 'center'),
                self::block($id, 'core.feature_list', ['title' => '', 'columns' => 3, 'items' => $threeFeatures]),
            ]);

        $add('features-split', 'Features — Split', 'features', 'Message and button beside a feature list.', [],
            static fn(callable $id): array => [
                self::block($id, 'layout.grid', ['columns' => 2, 'gap' => 'xl', 'align' => 'center'], [
                    self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'none'], [
                        self::heading($id, 'Designed around how you work', 'h2'),
                        self::text($id, 'Use this space to explain the idea behind your product or service.', 'base'),
                        self::button($id, 'See how it works', '/about', 'secondary', 'md'),
                    ]),
                    self::block($id, 'core.feature_list', ['title' => '', 'columns' => 1, 'items' => $threeFeatures]),
                ]),
            ]);

        $add('features-list', 'Features — Checklist', 'features', 'A single-column list of benefits.', ['width' => 'narrow'],
            static fn(callable $id): array => [
                self::heading($id, 'What is included', 'h2'),
                self::block($id, 'core.rich_text', ['content' => '<ul><li>Everything you need to get started</li><li>Onboarding and training</li><li>Regular updates</li><li>Friendly support</li></ul>']),
            ]);

        $add('services-overview', 'Services Overview', 'features', 'Your main services as linked cards.', [],
            static fn(callable $id): array => [
                self::heading($id, 'Our services', 'h2', 'center'),
                self::text($id, 'Pick the service that fits you best.', 'lead', 'center'),
                self::block($id, 'core.feature_list', ['title' => '', 'columns' => 3, 'items' => [
                    ['heading' => 'Service one', 'body' => 'A short description of what this service includes.', 'url' => '/services'],
                    ['heading' => 'Service two', 'body' => 'A short description of what this service includes.', 'url' => '/services'],
                    ['heading' => 'Service three', 'body' => 'A short description of what this service includes.', 'url' => '/services'],
                ]]),
            ]);

        $add('stats', 'Numbers', 'features', 'Four headline figures.', ['padding_y' => ['base' => 'lg', 'md' => 'lg'], 'background_token' => 'surface.secondary'],
            static fn(callable $id): array => [
                self::block($id, 'core.stats', ['items' => [
                    ['value' => 10, 'label' => 'Years of experience', 'suffix' => '+'],
                    ['value' => 500, 'label' => 'Happy customers', 'suffix' => '+'],
                    ['value' => 98, 'label' => 'Satisfaction', 'suffix' => '%'],
                    ['value' => 24, 'label' => 'Hour response', 'suffix' => 'h'],
                ]]),
            ]);

        // ── Content ─────────────────────────────────────────────────────────────
        $add('about', 'About Us', 'content', 'Your story beside a few facts.', [],
            static fn(callable $id): array => [
                self::block($id, 'layout.grid', ['columns' => 2, 'gap' => 'xl', 'align' => 'center'], [
                    self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'none'], [
                        self::heading($id, 'About us', 'h2'),
                        self::text($id, 'Tell visitors who you are, how you started and what you stand for.', 'base'),
                        self::text($id, 'A second paragraph is a good place for your approach or your team.', 'base'),
                    ]),
                    self::block($id, 'core.stats', ['items' => [
                        ['value' => 2015, 'label' => 'Founded'],
                        ['value' => 12, 'label' => 'Team members'],
                    ]]),
                ]),
            ]);

        $add('faq', 'FAQ', 'content', 'Questions and answers that expand.', ['width' => 'narrow'],
            static fn(callable $id): array => [
                self::heading($id, 'Frequently asked questions', 'h2', 'center'),
                self::block($id, 'core.accordion', ['allow_multiple' => false, 'items' => [
                    ['heading' => 'How does it work?', 'body' => 'Give a short, direct answer.'],
                    ['heading' => 'How much does it cost?', 'body' => 'Give a short, direct answer.'],
                    ['heading' => 'Can I cancel at any time?', 'body' => 'Give a short, direct answer.'],
                    ['heading' => 'How do I get in touch?', 'body' => 'Give a short, direct answer.'],
                ]]),
            ]);

        $add('gallery', 'Gallery', 'content', 'A heading and a photo grid to fill.', [],
            static fn(callable $id): array => [
                self::heading($id, 'Gallery', 'h2', 'center'),
                self::text($id, 'Add your photos from the media library.', 'lead', 'center'),
                self::block($id, 'core.gallery', ['columns' => '3', 'gap' => 'md', 'aspect_ratio' => '4:3', 'rounded' => true, 'images' => []]),
            ]);

        // ── Social proof ────────────────────────────────────────────────────────
        $add('testimonials', 'Testimonials', 'social-proof', 'Customer quotes in a slider.', ['background_token' => 'surface.secondary'],
            static fn(callable $id): array => [
                self::heading($id, 'What customers say', 'h2', 'center'),
                self::block($id, 'core.carousel', ['slides' => [
                    ['quote' => 'A short, honest quote from a happy customer.', 'author' => 'Customer name', 'caption' => 'Role, company'],
                    ['quote' => 'A second quote about what it was like to work with you.', 'author' => 'Customer name', 'caption' => 'Role, company'],
                    ['quote' => 'A third quote that mentions a specific result.', 'author' => 'Customer name', 'caption' => 'Role, company'],
                ]]),
            ]);

        // ── Pricing ─────────────────────────────────────────────────────────────
        $plan = static fn(callable $id, string $name, string $price, string $bullets, string $cta, string $variant): array => self::card($id, [
            self::heading($id, $name, 'h3'),
            self::heading($id, $price, 'h2'),
            self::block($id, 'core.rich_text', ['content' => $bullets]),
            self::button($id, $cta, '/contact', $variant, 'md', true),
        ]);
        $add('pricing', 'Pricing', 'pricing', 'Three plans side by side.', [],
            static fn(callable $id): array => [
                self::heading($id, 'Simple pricing', 'h2', 'center'),
                self::text($id, 'Choose a plan. Change it whenever you like.', 'lead', 'center'),
                self::block($id, 'layout.grid', ['columns' => 3, 'gap' => 'lg', 'align' => 'stretch'], [
                    $plan($id, 'Starter', '$19', '<ul><li>For individuals</li><li>Core features</li><li>Email support</li></ul>', 'Choose Starter', 'outline'),
                    $plan($id, 'Pro', '$49', '<ul><li>For growing teams</li><li>Everything in Starter</li><li>Priority support</li></ul>', 'Choose Pro', 'primary'),
                    $plan($id, 'Business', '$99', '<ul><li>For larger teams</li><li>Everything in Pro</li><li>Dedicated contact</li></ul>', 'Contact us', 'outline'),
                ]),
            ]);

        // ── Call to action ──────────────────────────────────────────────────────
        $add('cta', 'Call to Action', 'cta', 'A closing banner with one button.', ['padding_y' => ['base' => 'xl', 'md' => 'xl'], 'background_token' => 'surface.inverse'],
            static fn(callable $id): array => [
                self::block($id, 'layout.container', ['width' => 'compact', 'alignment' => 'center', 'padding' => 'none'], [
                    self::heading($id, 'Ready to get started?', 'h2', 'center', 'text.inverse'),
                    self::text($id, 'Tell us what you need and we will get back to you within a day.', 'lead', 'center', 'text.inverse'),
                    self::button($id, 'Contact us', '/contact', 'primary', 'lg'),
                ]),
            ]);

        // ── Team ────────────────────────────────────────────────────────────────
        $person = static fn(callable $id, string $name, string $role): array => self::card($id, [
            self::heading($id, $name, 'h3'),
            self::text($id, $role, 'sm'),
            self::text($id, 'A one-line introduction for this person.', 'base'),
        ]);
        $add('team', 'Team', 'team', 'Three team members.', [],
            static fn(callable $id): array => [
                self::heading($id, 'Meet the team', 'h2', 'center'),
                self::block($id, 'layout.grid', ['columns' => 3, 'gap' => 'lg', 'align' => 'stretch'], [
                    $person($id, 'Team member', 'Role'),
                    $person($id, 'Team member', 'Role'),
                    $person($id, 'Team member', 'Role'),
                ]),
            ]);

        // ── Contact ─────────────────────────────────────────────────────────────
        $add('contact', 'Contact', 'contact', 'Details beside a contact form.', [],
            static fn(callable $id): array => [
                self::block($id, 'layout.grid', ['columns' => 2, 'gap' => 'xl', 'align' => 'start'], [
                    self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'none'], [
                        self::heading($id, 'Get in touch', 'h2'),
                        self::text($id, 'Send us a message and we will reply as soon as we can.', 'base'),
                        self::block($id, 'core.rich_text', ['content' => '<p>hello@example.com<br>+00 000 000 000</p>']),
                    ]),
                    self::block($id, 'core.form', ['action' => '', 'method' => 'post', 'form_name' => 'contact_form', 'submit_text' => 'Send message', 'submit_variant' => 'primary'], [
                        self::field($id, 'name', 'Your name', 'text', true),
                        self::field($id, 'email', 'Email', 'email', true),
                        self::field($id, 'message', 'Message', 'textarea', true),
                    ]),
                ]),
            ]);

        // ── Footer ──────────────────────────────────────────────────────────────
        $add('footer', 'Footer', 'footer', 'Links in columns with a copyright line.', ['padding_y' => ['base' => 'lg', 'md' => 'lg'], 'background_token' => 'surface.secondary'],
            static fn(callable $id): array => [
                self::block($id, 'layout.grid', ['columns' => 3, 'gap' => 'lg', 'align' => 'start'], [
                    self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'none'], [
                        self::heading($id, 'Your business', 'h3'),
                        self::text($id, 'A short line about what you do.', 'sm'),
                    ]),
                    self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'none'], [
                        self::heading($id, 'Company', 'h4'),
                        self::block($id, 'core.rich_text', ['content' => '<p><a href="/about">About</a><br><a href="/services">Services</a><br><a href="/contact">Contact</a></p>']),
                    ]),
                    self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'none'], [
                        self::heading($id, 'Legal', 'h4'),
                        self::block($id, 'core.rich_text', ['content' => '<p><a href="/privacy">Privacy</a><br><a href="/terms">Terms</a></p>']),
                    ]),
                ]),
                self::text($id, '© Your business. All rights reserved.', 'xs', 'center'),
            ]);

        return $presets;
    }

    // ── builders ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $props
     * @param list<array<string, mixed>> $children
     * @param array<string, mixed> $style
     * @return array<string, mixed>
     */
    private static function block(callable $id, string $type, array $props, array $children = [], array $style = []): array
    {
        return [
            'id'         => $id(),
            'type'       => $type,
            'version'    => 1,
            'props'      => $props,
            'style'      => array_merge(CanonicalDocumentSchema::defaultBlockStyle(), $style),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'bindings'   => [],
            'children'   => $children,
        ];
    }

    /** @return array<string, mixed> */
    private static function heading(callable $id, string $text, string $level, string $align = 'left', ?string $textToken = null): array
    {
        return self::block($id, 'core.heading', ['text' => $text, 'level' => $level], [], self::textStyle($align, $textToken));
    }

    /** @return array<string, mixed> */
    private static function text(callable $id, string $content, string $size, string $align = 'left', ?string $textToken = null): array
    {
        return self::block($id, 'core.text', ['content' => $content, 'size' => $size, 'align' => $align], [], self::textStyle($align, $textToken));
    }

    /** @return array<string, mixed> */
    private static function button(callable $id, string $label, string $href, string $variant, string $size, bool $fullWidth = false): array
    {
        return self::block($id, 'core.button', [
            'link' => ['label' => $label, 'href' => $href], 'variant' => $variant, 'size' => $size, 'full_width' => $fullWidth,
        ]);
    }

    /**
     * A bordered content card.
     *
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private static function card(callable $id, array $children): array
    {
        return self::block($id, 'layout.container', ['width' => 'full', 'alignment' => 'left', 'padding' => 'md'], $children, [
            'surface_token' => 'surface.secondary', 'radius_token' => 'radius.md', 'shadow_token' => 'shadow.sm',
        ]);
    }

    /** @return array<string, mixed> */
    private static function field(callable $id, string $name, string $label, string $type, bool $required): array
    {
        return self::block($id, 'core.form_field', [
            'name' => $name, 'label' => $label, 'field_type' => $type, 'placeholder' => '', 'required' => $required, 'help_text' => '', 'options' => '',
        ]);
    }

    /** @return array<string, mixed> */
    private static function textStyle(string $align, ?string $textToken): array
    {
        $style = ['align' => ['base' => $align]];
        if ($textToken !== null) {
            $style['text_token'] = $textToken;
        }
        return $style;
    }
}
