<?php
/**
 * Kohevo Studio — the prebuilt Coming Soon and Maintenance templates: ordinary builder pages in the site's own theme
 * colours and fonts, with the logo and name coming from dynamic tags, so they carry the brand and stay editable.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Meta\SiteMode;

final class SiteModeTemplates
{
    public const KEYS = [SiteMode::COMING_SOON => 'site-coming-soon', SiteMode::MAINTENANCE => 'site-maintenance'];

    /** The document of a prebuilt template. @return array<string, mixed> */
    public static function document(string $mode): array
    {
        $soon = $mode === SiteMode::COMING_SOON;
        $name = $soon ? \__('studio_site_mode_coming_soon', 'Coming Soon') : \__('studio_site_mode_maintenance', 'Maintenance');
        $document = CanonicalDocumentSchema::emptyDocument('system', 'default', $name);
        $document['seo']['robots'] = 'noindex,nofollow';
        $document['settings']['template_type'] = 'maintenance';
        $document['settings']['header_mode'] = 'hidden';
        $document['settings']['footer_mode'] = 'hidden';

        $block = static fn(string $type, array $props, array $style = [], array $dynamic = []): array => [
            'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
            'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ] + ($dynamic !== [] ? ['dynamic' => $dynamic] : []);
        $center = ['align' => ['base' => 'center'], 'text_token' => 'text.inverse'];
        $large = $center + ['typography' => ['size' => '56px', 'line_height' => '1.1']];

        $document['containers'] = [CanonicalDocumentSchema::newContainer([
            'metadata' => ['label' => $name],
            'style' => [
                'surface_token' => 'surface.inverse', 'text_token' => 'text.inverse',
                'layout' => ['content_width' => 'boxed', 'content_size' => 'narrow', 'gutter' => 'site', 'padding_y' => 'lg', 'display' => 'flex', 'direction' => 'column', 'justify' => 'center', 'align' => 'center', 'gap' => '20px'],
                'dimensions' => ['min_height' => '100vh'],
            ],
            'children' => [
                $block('core.image', ['rounded' => false], [], ['media' => ['tag' => 'site_logo']]),
                $block('core.text', ['content' => ''], $center, ['content' => ['tag' => 'site_name']]),
                $block('core.heading', ['level' => 'h1', 'text' => $soon ? \__('studio_site_mode_soon_title', 'Something new is on its way') : \__('studio_site_mode_maint_title', 'We’ll be right back')], $large),
                $block('core.text', ['content' => $soon ? \__('studio_site_mode_soon_text', 'We are putting the finishing touches on our new site. Please check back soon.') : \__('studio_site_mode_maint_text', 'We are making a few improvements and will be back shortly. Thanks for your patience.')], $center),
                $block('core.text', ['content' => '', 'size' => 'sm'], $center, ['content' => ['tag' => 'site_name', 'before' => '© ' . date('Y') . ' ']]),
            ],
        ])];
        return $document;
    }

    /** Create and publish a prebuilt template, or find it; returns its page id. */
    public static function install(StudioApplicationService $app, StudioActor $actor, string $mode): int
    {
        $key = self::KEYS[$mode];
        $existing = (int) \Slate\Data\Database::value("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND status <> 'archived'", [current_tenant_id(), 'template-' . $key]);
        if ($existing > 0) {
            return $existing;
        }
        $document = self::document($mode);
        $name = $mode === SiteMode::COMING_SOON ? \__('studio_site_mode_coming_soon', 'Coming Soon') : \__('studio_site_mode_maintenance', 'Maintenance');
        $app->saveTemplate($actor, $key, 'page_template', 'maintenance', $name, "Theme maintenance template: {$name}", $document);
        $page = $app->createPage($actor, "[Theme] {$name}", 'template-' . $key, 'system', 'standalone');
        $id = (int) $page['page']['id'];
        $draft = $app->saveDraft($actor, $id, $document, (int) $page['revision']['id'], 'manual', "Theme template created: {$name}");
        $app->publish($actor, $id, (int) $draft['revision']['id'], "Initial publish of {$name}");
        return $id;
    }
}
