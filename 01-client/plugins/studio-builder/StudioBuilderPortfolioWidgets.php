<?php
/**
 * Kohevo Studio — Rakib Hasan Portfolio Widget Extension.
 *
 * Registers custom StudioBuilder widget for the complete portfolio experience.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder;

use Slate\Kernel\Event\Hook;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Sdk\WidgetSdk;

final class StudioBuilderPortfolioWidgets
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        if (class_exists(Hook::class)) {
            Hook::addFilter('studio_register_widgets', [self::class, 'onRegisterWidgets']);
        }
    }

    public static function onRegisterWidgets(WidgetSdk $sdk): void
    {
        if (!$sdk->has('portfolio.rakib_showcase')) {
            $sdk->register([
                'type'        => 'portfolio.rakib_showcase',
                'version'     => 1,
                'label'       => 'Rakib Hasan Portfolio Showcase',
                'category'    => 'portfolio',
                'icon'        => 'layout',
                'schema'      => [
                    ['key' => 'title', 'type' => 'string', 'label' => 'Portfolio Title', 'required' => false, 'default' => 'Rakib Hasan — Full-Stack Developer & Designer'],
                    ['key' => 'brand_text', 'type' => 'string', 'label' => 'Brand Header', 'required' => false, 'default' => 'Portfolio / 2026'],
                    ['key' => 'first_name', 'type' => 'string', 'label' => 'First Name', 'required' => false, 'default' => 'rakib'],
                    ['key' => 'last_name', 'type' => 'string', 'label' => 'Last Name', 'required' => false, 'default' => 'hasan'],
                    ['key' => 'role', 'type' => 'text', 'label' => 'Role / Headline', 'required' => false, 'default' => "Freelance developer\n& designer"],
                    ['key' => 'vertical_text', 'type' => 'string', 'label' => 'Side Accent Text', 'required' => false, 'default' => 'Digital design • Development'],
                    ['key' => 'location', 'type' => 'text', 'label' => 'Location', 'required' => false, 'default' => "Based in\nBangladesh"],
                    ['key' => 'coordinates', 'type' => 'text', 'label' => 'Coordinates', 'required' => false, 'default' => "23.8103° N\n90.4125° E"],
                    ['key' => 'domain', 'type' => 'string', 'label' => 'Domain Name', 'required' => false, 'default' => 'rakibhasaan.com'],
                    ['key' => 'domain_url', 'type' => 'string', 'label' => 'Domain URL', 'required' => false, 'default' => 'https://rakibhasaan.com'],
                    ['key' => 'email', 'type' => 'string', 'label' => 'Contact Email', 'required' => false, 'default' => 'hello@rakibhasaan.com'],
                ],
                'renderer'    => [self::class, 'renderShowcase'],
            ]);
        }
    }

    public static function renderShowcase(BlockRenderScope $scope): string
    {
        $bodyFile = __DIR__ . '/assets/portfolio_body.html';

        if (!file_exists($bodyFile)) {
            return '<div class="portfolio-error">Portfolio template asset missing.</div>';
        }

        $html = (string) file_get_contents($bodyFile);

        // Copy portrait photo src to #face for scriptless editor canvas
        if (preg_match('/<img class="a photo"[^>]*src="([^"]+)"/i', $html, $matches)) {
            $photoSrc = $matches[1];
            $html = str_replace('<img id="face"', '<img id="face" src="' . $photoSrc . '"', $html);
        }

        // Interpolate editable props
        $brandText    = htmlspecialchars($scope->string('brand_text', 'Portfolio / 2026'), ENT_QUOTES, 'UTF-8');
        $firstName    = htmlspecialchars($scope->string('first_name', 'rakib'), ENT_QUOTES, 'UTF-8');
        $lastName     = htmlspecialchars($scope->string('last_name', 'hasan'), ENT_QUOTES, 'UTF-8');
        $role         = nl2br(htmlspecialchars($scope->string('role', "Freelance developer\n& designer"), ENT_QUOTES, 'UTF-8'));
        $verticalText = htmlspecialchars($scope->string('vertical_text', 'Digital design • Development'), ENT_QUOTES, 'UTF-8');
        $location     = nl2br(htmlspecialchars($scope->string('location', "Based in\nBangladesh"), ENT_QUOTES, 'UTF-8'));
        $coordinates  = nl2br(htmlspecialchars($scope->string('coordinates', "23.8103° N\n90.4125° E"), ENT_QUOTES, 'UTF-8'));
        $domain       = htmlspecialchars($scope->string('domain', 'rakibhasaan.com'), ENT_QUOTES, 'UTF-8');
        $domainUrl    = htmlspecialchars($scope->string('domain_url', 'https://rakibhasaan.com'), ENT_QUOTES, 'UTF-8');
        $email        = htmlspecialchars($scope->string('email', 'hello@rakibhasaan.com'), ENT_QUOTES, 'UTF-8');

        $html = str_replace(
            '<span class="e-pf a c t" style="left:7%;top:4%">Portfolio / 2026</span>',
            '<span class="e-pf a c t" style="left:7%;top:4%">' . $brandText . '</span>',
            $html
        );
        $html = str_replace(
            '<h1 class="e-name a c" style="left:6.9%;top:15.6%">rakib<br>hasan</h1>',
            '<h1 class="e-name a c" style="left:6.9%;top:15.6%">' . $firstName . '<br>' . $lastName . '</h1>',
            $html
        );
        $html = str_replace(
            '<p class="e-role a c t role" style="left:7%;top:25.4%">Freelance developer<br>&amp; designer</p>',
            '<p class="e-role a c t role" style="left:7%;top:25.4%">' . $role . '</p>',
            $html
        );
        $html = str_replace(
            '<span class="e-vt a t v" style="left:92.5%;top:23.6%">Digital design • Development</span>',
            '<span class="e-vt a t v" style="left:92.5%;top:23.6%">' . $verticalText . '</span>',
            $html
        );
        $html = str_replace(
            '<p class="e-based a c t" style="left:6.8%;top:81.1%;font-size:1.45cqw">Based in<br>Bangladesh</p>',
            '<p class="e-based a c t" style="left:6.8%;top:81.1%;font-size:1.45cqw">' . $location . '</p>',
            $html
        );
        $html = str_replace(
            '<p class="e-coord a c t" style="right:8.9%;top:94.6%;text-align:right;font-size:1.4cqw">23.8103° N<br>90.4125° E</p>',
            '<p class="e-coord a c t" style="right:8.9%;top:94.6%;text-align:right;font-size:1.4cqw">' . $coordinates . '</p>',
            $html
        );
        $html = str_replace(
            '<a class="e-dom a c t dom" href="https://rakibhasaan.com" style="left:16.8%;top:94.5%">rakibhasaan.com</a>',
            '<a class="e-dom a c t dom" href="' . $domainUrl . '" style="left:16.8%;top:94.5%">' . $domain . '</a>',
            $html
        );
        $html = str_replace(
            '<a href="mailto:hello@rakibhasaan.com" data-m>hello@rakibhasaan.com</a>',
            '<a href="mailto:' . $email . '" data-m>' . $email . '</a>',
            $html
        );
        $html = str_replace(
            '<a href="mailto:hello@rakibhasaan.com">hello@rakibhasaan.com</a>',
            '<a href="mailto:' . $email . '">' . $email . '</a>',
            $html
        );

        return self::stylesheetTag() . $html;
    }

    /**
     * `<link>` to the static portfolio stylesheet (self-hosted Outfit fonts
     * resolve relative to it). Needed because the builder canvas renders in
     * Editor mode, where tenant CSS is intentionally not emitted.
     */
    public static function stylesheetTag(): string
    {
        $file = __DIR__ . '/assets/portfolio/portfolio.css';
        if (!is_file($file)) {
            return '';
        }
        $url = '';
        try {
            if (\function_exists('plugin_url')) {
                $url = (string) \plugin_url('studio-builder', 'assets/portfolio/portfolio.css');
            }
        } catch (\Throwable $ignored) {
        }
        if ($url === '') {
            $base = defined('SLATE_URL') ? (string) SLATE_URL : '';
            $url = ($base !== '' ? rtrim($base, '/') : '') . '/plugins/studio-builder/assets/portfolio/portfolio.css';
        }
        $v = substr((string) @hash_file('sha256', $file), 0, 12);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url . '?v=' . $v, ENT_QUOTES) . '">';
    }
}
