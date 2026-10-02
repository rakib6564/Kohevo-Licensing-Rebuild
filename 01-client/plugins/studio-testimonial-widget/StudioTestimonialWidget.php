<?php
/**
 * Example third-party plugin extending Kohevo Studio Builder.
 *
 * Demonstrates Sprint 8 Developer Widget SDK (Section 31 & 64):
 * - Registers custom widget `acme.testimonial` without modifying any core Studio files
 * - Declares typed FieldSchema for props (author, role, company, quote, avatar_url, rating)
 * - Implements server renderer with accessible markup
 * - Implements version migration hook (v1 -> v2)
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Sdk\Studio;

class StudioTestimonialWidget extends Plugin
{
    public function boot(): void
    {
        // Register custom widget via developer SDK
        Studio::widgets()->register([
            'type'        => 'acme.testimonial',
            'version'     => 1,
            'label'       => 'Testimonial Card',
            'category'    => 'marketing',
            'icon'        => 'quote',
            'schema'      => [
                ['key' => 'author', 'type' => 'string', 'label' => 'Author Name', 'required' => true, 'default' => 'Alex Rivera', 'max_length' => 120],
                ['key' => 'role', 'type' => 'string', 'label' => 'Job Title', 'required' => false, 'default' => 'VP of Engineering', 'max_length' => 120],
                ['key' => 'company', 'type' => 'string', 'label' => 'Company Name', 'required' => false, 'default' => 'InnovateLabs', 'max_length' => 120],
                ['key' => 'quote', 'type' => 'text', 'label' => 'Testimonial Quote', 'required' => true, 'default' => 'Kohevo Studio has completely transformed how our team publishes websites.', 'max_length' => 1000],
                ['key' => 'avatar_url', 'type' => 'url', 'label' => 'Avatar URL', 'required' => false, 'default' => null],
                ['key' => 'rating', 'type' => 'number', 'label' => 'Star Rating (1-5)', 'required' => false, 'default' => 5, 'min' => 1, 'max' => 5, 'integer_only' => true],
            ],
            'renderer'    => [self::class, 'renderTestimonial'],
            'migration'   => [self::class, 'migrateTestimonial'],
        ]);
    }

    public static function renderTestimonial(BlockRenderScope $scope): string
    {
        $author   = $scope->string('author', 'Anonymous');
        $role     = $scope->string('role');
        $company  = $scope->string('company');
        $quote    = $scope->string('quote');
        $avatar   = $scope->string('avatar_url');
        $rating   = max(1, min(5, (int) $scope->prop('rating', 5)));

        $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);

        $html = '<figure class="sb-widget-testimonial" data-sb-custom-widget="acme.testimonial">';
        $html .= '<blockquote class="sb-widget-testimonial__quote"><p>' . Html::e($quote) . '</p></blockquote>';
        $html .= '<div class="sb-widget-testimonial__rating" aria-label="' . $rating . ' out of 5 stars">' . Html::e($stars) . '</div>';
        $html .= '<figcaption class="sb-widget-testimonial__author">';
        if ($avatar !== '') {
            $html .= '<img class="sb-widget-testimonial__avatar" src="' . Html::e($avatar) . '" alt="' . Html::e($author) . '" loading="lazy">';
        }
        $html .= '<div class="sb-widget-testimonial__meta">';
        $html .= '<strong class="sb-widget-testimonial__name">' . Html::e($author) . '</strong>';
        if ($role !== '' || $company !== '') {
            $info = $role . ($role !== '' && $company !== '' ? ', ' : '') . $company;
            $html .= '<span class="sb-widget-testimonial__title">' . Html::e($info) . '</span>';
        }
        $html .= '</div>';
        $html .= '</figcaption>';
        $html .= '</figure>';

        return $html;
    }

    public static function migrateTestimonial(int $fromVersion, int $toVersion, array $props): array
    {
        if ($fromVersion === 1 && $toVersion === 2) {
            $props['is_verified'] = true;
        }
        return $props;
    }
}
