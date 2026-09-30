<?php
/**
 * Kohevo Studio (studio-builder) — Base stylesheet.
 *
 * Deterministic CSS generated only from the canonical schema's own enums
 * (breakpoints, spacing scale, container widths, alignments). Authored
 * documents select these classes symbolically; they never contribute CSS
 * text. Colours/fonts always come from theme custom properties (`--sb-*`).
 *
 * Breakpoints (schema 1.0 buckets): base < 640px <= sm < 768px <= md < 1024px <= lg.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

final class StudioStylesheet
{
    public const VERSION = '2';

    private const MIN_WIDTH = ['sm' => 640, 'md' => 768, 'lg' => 1024];

    private const HIDE_QUERIES = [
        'base' => '(max-width:639.98px)',
        'sm'   => '(min-width:640px) and (max-width:767.98px)',
        'md'   => '(min-width:768px) and (max-width:1023.98px)',
        'lg'   => '(min-width:1024px)',
    ];

    private const PADDING = ['none' => '0', 'xs' => '.5rem', 'sm' => '1rem', 'md' => '2rem', 'lg' => '4rem', 'xl' => '6rem', '2xl' => '8rem'];
    private const GAP     = ['none' => '0', 'xs' => '.25rem', 'sm' => '.5rem', 'md' => '1rem', 'lg' => '1.5rem', 'xl' => '2rem', '2xl' => '3rem'];
    private const WIDTHS  = ['narrow' => '42rem', 'normal' => '64rem', 'wide' => '80rem', 'full' => 'none'];
    private const ALIGN   = ['left' => 'left', 'center' => 'center', 'right' => 'right', 'justify' => 'justify'];

    private static ?string $cache = null;

    public static function css(): string
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $css = '*,*::before,*::after{box-sizing:border-box}'
            . 'body.sb-body{margin:0;background:var(--sb-surface-page);color:var(--sb-text-primary);font-family:var(--sb-font-body);line-height:1.6}'
            . '.sb-body h1,.sb-body h2,.sb-body h3,.sb-body h4,.sb-body h5,.sb-body h6{font-family:var(--sb-font-heading);line-height:1.2;margin:0 0 .5em}'
            . '.sb-body img{max-width:100%;height:auto}'
            // Buttons are links too: the generic link colour must not reach them (it would equal
            // the primary button's background and hide the label), so they keep their variant colours.
            . '.sb-body a:not(.sb-button){color:var(--sb-color-accent)}'
            . '.sb-main{display:block}'
            . '.sb-section__inner{margin:0 auto;padding-left:1rem;padding-right:1rem;display:grid}'
            . '.sb-section__inner>*{min-width:0}'
            . '.sb-site-header,.sb-site-footer{padding:1rem;border-color:var(--sb-border-default)}'
            . '.sb-site-header{border-bottom:1px solid var(--sb-border-default)}'
            . '.sb-site-footer{border-top:1px solid var(--sb-border-default);color:var(--sb-text-muted);font-size:.875rem}'
            . '.sb-site-brand{display:inline-flex;align-items:center;gap:.5rem;font-weight:600;text-decoration:none;color:var(--sb-text-primary)}'
            . '.sb-site-brand img{height:2rem;width:auto}'
            . '.sb-hero{display:grid;gap:2rem;align-items:center}'
            . '.sb-hero--with-media{grid-template-columns:repeat(auto-fit,minmax(min(100%,20rem),1fr))}'
            . '.sb-hero__eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:.8rem;margin:0 0 .5rem}'
            . '.sb-hero__heading{font-size:clamp(2rem,5vw,3.5rem)}'
            . '.sb-hero__media img{display:block;width:100%;border-radius:var(--sb-radius-lg);object-fit:cover}'
            . '.sb-button-row{margin:1rem 0 0}'
            . '.sb-button{display:inline-block;padding:.7rem 1.3rem;border-radius:var(--sb-radius-md);text-decoration:none;font-weight:600;border:2px solid var(--sb-color-accent)}'
            . '.sb-button--primary{background:var(--sb-surface-accent);color:var(--sb-text-inverse)}'
            . '.sb-button--secondary{background:var(--sb-surface-secondary);color:var(--sb-text-primary);border-color:var(--sb-surface-secondary)}'
            . '.sb-button--outline{background:transparent;color:var(--sb-color-accent)}'
            . '.sb-button--ghost{background:transparent;border-color:transparent;color:var(--sb-color-accent)}'
            . '.sb-button--full{display:block;text-align:center}'
            . '.sb-image{margin:0}.sb-image img{display:block;width:100%;object-fit:cover}'
            . '.sb-image--rounded img{border-radius:var(--sb-radius-md)}'
            . '.sb-image--ratio-16-9 img{aspect-ratio:16/9}.sb-image--ratio-4-3 img{aspect-ratio:4/3}'
            . '.sb-image--ratio-1-1 img{aspect-ratio:1/1}.sb-image--ratio-3-4 img{aspect-ratio:3/4}'
            . '.sb-image figcaption{font-size:.875rem;color:var(--sb-text-muted);margin-top:.5rem}'
            . '.sb-feature-list__items,.sb-catalog__list{list-style:none;margin:0;padding:0;display:grid;gap:1rem}'
            . '.sb-feature,.sb-catalog__item,.sb-form-card{padding:1.25rem;border-radius:var(--sb-radius-md)}'
            . '.sb-feature--bordered,.sb-catalog__item,.sb-form-card{border:1px solid var(--sb-border-default)}'
            . '.sb-catalog__meta{color:var(--sb-text-muted);display:flex;gap:1rem;margin:.25rem 0 0}'
            . '.sb-catalog__price{font-weight:600;color:var(--sb-text-primary)}'
            . '.sb-stack{display:flex}.sb-stack--vertical{flex-direction:column}.sb-stack--horizontal{flex-direction:row;flex-wrap:wrap}'
            . '.sb-empty{color:var(--sb-text-muted)}'
            . '.sb-unavailable{padding:.75rem 1rem;border:1px dashed #b45309;color:#92400e;background:#fffbeb;border-radius:6px;font-size:.875rem}'
            . '.sb-preview-banner{position:sticky;top:0;z-index:10;padding:.5rem 1rem;background:#1e293b;color:#fff;font:600 .8rem/1.4 system-ui,sans-serif;text-align:center}'
            . '.sb-platform-signature{padding:.75rem 1rem;text-align:center;font-size:.75rem;color:#6b7280}'
            . '.sb-platform-signature img{height:1rem;width:auto;vertical-align:middle}';

        foreach (self::WIDTHS as $name => $max) {
            $css .= '.sb-w-' . $name . '{max-width:' . $max . ';margin-left:auto;margin-right:auto}';
        }
        foreach (self::GAP as $name => $value) {
            $css .= '.sb-gap-' . $name . '{gap:' . $value . '}';
        }

        $css .= self::responsive('');
        foreach (self::MIN_WIDTH as $bp => $px) {
            $css .= '@media (min-width:' . $px . 'px){' . self::responsive($bp . '-') . '}';
        }
        foreach (self::HIDE_QUERIES as $bp => $query) {
            $css .= '@media ' . $query . '{.sb-hide-' . $bp . '{display:none!important}}';
        }

        return self::$cache = $css;
    }

    private static function responsive(string $prefix): string
    {
        $css = '';
        for ($n = 1; $n <= 12; $n++) {
            $css .= '.sb-' . $prefix . 'cols-' . $n . '{grid-template-columns:repeat(' . $n . ',minmax(0,1fr))}';
        }
        foreach (self::PADDING as $name => $value) {
            $css .= '.sb-' . $prefix . 'py-' . $name . '{padding-top:' . $value . ';padding-bottom:' . $value . '}';
        }
        foreach (self::ALIGN as $name => $value) {
            $css .= '.sb-' . $prefix . 'align-' . $name . '{text-align:' . $value . '}';
        }
        return $css;
    }

    /** Breakpoint prefix for a responsive utility (`base` has none). */
    public static function prefix(string $breakpoint): ?string
    {
        if ($breakpoint === 'base') {
            return '';
        }
        return in_array($breakpoint, CanonicalDocumentSchema::ALLOWED_BREAKPOINTS, true) ? $breakpoint . '-' : null;
    }
}
