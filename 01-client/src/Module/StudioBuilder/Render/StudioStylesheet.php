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
            . '.sb-platform-signature img{height:1rem;width:auto;vertical-align:middle}'
            . '.sb-layout-section{position:relative;width:100%}'
            . '.sb-layout-section--boxed{max-width:80rem;margin-left:auto;margin-right:auto}'
            . '.sb-layout-section--full{width:100%}'
            . '.sb-layout-section--narrow{max-width:48rem;margin-left:auto;margin-right:auto}'
            . '.sb-layout-section--min-screen{min-height:100vh}'
            . '.sb-layout-section--min-half-screen{min-height:50vh}'
            . '.sb-layout-section--min-sm{min-height:16rem}'
            . '.sb-layout-section--min-md{min-height:24rem}'
            . '.sb-layout-section--min-lg{min-height:36rem}'
            . '.sb-layout-section--min-xl{min-height:48rem}'
            . '.sb-container{width:100%;margin-left:auto;margin-right:auto}'
            . '.sb-container--compact{max-width:48rem}'
            . '.sb-container--constrained{max-width:75rem}'
            . '.sb-container--full{max-width:100%}'
            . '.sb-container--align-left{margin-left:0;margin-right:auto}'
            . '.sb-container--align-center{margin-left:auto;margin-right:auto}'
            . '.sb-container--align-right{margin-left:auto;margin-right:0}'
            . '.sb-flex{display:flex}'
            . '.sb-flex--row{flex-direction:row}'
            . '.sb-flex--column{flex-direction:column}'
            . '.sb-flex--row-reverse{flex-direction:row-reverse}'
            . '.sb-flex--column-reverse{flex-direction:column-reverse}'
            . '.sb-flex--wrap{flex-wrap:wrap}'
            . '.sb-flex--nowrap{flex-wrap:nowrap}'
            . '.sb-flex--wrap-reverse{flex-wrap:wrap-reverse}'
            . '.sb-flex--justify-start{justify-content:flex-start}'
            . '.sb-flex--justify-center{justify-content:center}'
            . '.sb-flex--justify-end{justify-content:flex-end}'
            . '.sb-flex--justify-between{justify-content:space-between}'
            . '.sb-flex--justify-around{justify-content:space-around}'
            . '.sb-flex--justify-evenly{justify-content:space-evenly}'
            . '.sb-flex--align-start{align-items:flex-start}'
            . '.sb-flex--align-center{align-items:center}'
            . '.sb-flex--align-end{align-items:flex-end}'
            . '.sb-flex--align-stretch{align-items:stretch}'
            . '.sb-flex--align-baseline{align-items:baseline}'
            . '.sb-grid{display:grid}'
            . '.sb-grid--align-start{align-items:flex-start}'
            . '.sb-grid--align-center{align-items:center}'
            . '.sb-grid--align-end{align-items:flex-end}'
            . '.sb-grid--align-stretch{align-items:stretch}'
            . '.sb-text{margin:0 0 1rem;line-height:1.6}'
            . '.sb-text--xs{font-size:.75rem}'
            . '.sb-text--sm{font-size:.875rem}'
            . '.sb-text--base{font-size:1rem}'
            . '.sb-text--lg{font-size:1.125rem}'
            . '.sb-text--xl{font-size:1.25rem}'
            . '.sb-text--lead{font-size:1.25rem;line-height:1.75;font-weight:400}'
            . '.sb-text--align-left{text-align:left}'
            . '.sb-text--align-center{text-align:center}'
            . '.sb-text--align-right{text-align:right}'
            . '.sb-text--align-justify{text-align:justify}'
            . '.sb-heading--xs{font-size:.875rem}'
            . '.sb-heading--sm{font-size:1rem}'
            . '.sb-heading--base{font-size:1.25rem}'
            . '.sb-heading--lg{font-size:1.5rem}'
            . '.sb-heading--xl{font-size:1.875rem}'
            . '.sb-heading--2xl{font-size:2.25rem}'
            . '.sb-heading--3xl{font-size:3rem}'
            . '.sb-heading--4xl{font-size:3.75rem}'
            . '.sb-heading--5xl{font-size:4.5rem}'
            . '.sb-heading--align-left{text-align:left}'
            . '.sb-heading--align-center{text-align:center}'
            . '.sb-heading--align-right{text-align:right}'
            . '.sb-button--sm{padding:.4rem .8rem;font-size:.875rem}'
            . '.sb-button--md{padding:.7rem 1.3rem;font-size:1rem}'
            . '.sb-button--lg{padding:.9rem 1.8rem;font-size:1.125rem}'
            . '.sb-font-normal{font-weight:400}.sb-font-medium{font-weight:500}.sb-font-semibold{font-weight:600}.sb-font-bold{font-weight:700}.sb-font-extrabold{font-weight:800}'
            . '.sb-uppercase{text-transform:uppercase}.sb-lowercase{text-transform:lowercase}.sb-capitalize{text-transform:capitalize}.sb-normal-case{text-transform:none}'
            . '.sb-leading-tight{line-height:1.25}.sb-leading-snug{line-height:1.375}.sb-leading-normal{line-height:1.5}.sb-leading-relaxed{line-height:1.625}.sb-leading-loose{line-height:2}'
            . '.sb-tracking-tighter{letter-spacing:-0.05em}.sb-tracking-tight{letter-spacing:-0.025em}.sb-tracking-normal{letter-spacing:0}.sb-tracking-wide{letter-spacing:0.025em}.sb-tracking-wider{letter-spacing:0.05em}.sb-tracking-widest{letter-spacing:0.1em}'
            . '.sb-radius-none{border-radius:0}.sb-radius-sm{border-radius:.25rem}.sb-radius-md{border-radius:.5rem}.sb-radius-lg{border-radius:1rem}.sb-radius-xl{border-radius:1.5rem}.sb-radius-2xl{border-radius:2rem}.sb-radius-full{border-radius:9999px}'
            . '.sb-shadow-none{box-shadow:none}.sb-shadow-sm{box-shadow:0 1px 2px 0 rgba(0,0,0,0.05)}.sb-shadow-md{box-shadow:0 4px 6px -1px rgba(0,0,0,0.1),0 2px 4px -2px rgba(0,0,0,0.1)}.sb-shadow-lg{box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1)}.sb-shadow-xl{box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1)}.sb-shadow-2xl{box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)}.sb-shadow-inner{box-shadow:inset 0 2px 4px 0 rgba(0,0,0,0.05)}'
            . '.sb-border{border:1px solid var(--sb-border-default,#e2e8f0)}.sb-border-2{border-width:2px}.sb-border-4{border-width:4px}.sb-border-none{border:none}.sb-border-dashed{border-style:dashed}'
            . '.sb-m-auto{margin:auto}.sb-mx-auto{margin-left:auto;margin-right:auto}.sb-my-auto{margin-top:auto;margin-bottom:auto}.sb-m-none{margin:0}.sb-my-none{margin-top:0;margin-bottom:0}'
            . '.sb-align-desktop-left{text-align:left}.sb-align-desktop-center{text-align:center}.sb-align-desktop-right{text-align:right}'
            . '.sb-align-tablet-left{text-align:left}.sb-align-tablet-center{text-align:center}.sb-align-tablet-right{text-align:right}'
            . '.sb-align-mobile-left{text-align:left}.sb-align-mobile-center{text-align:center}.sb-align-mobile-right{text-align:right}'
            . '.sb-query-loop{width:100%}'
            . '.sb-post-card{display:flex;flex-direction:column;border:1px solid var(--sb-border-default,#e2e8f0);border-radius:var(--sb-radius-md,.5rem);overflow:hidden;background:var(--sb-surface-secondary,#ffffff);transition:box-shadow .2s ease}'
            . '.sb-post-card:hover{box-shadow:var(--sb-shadow-md,0 4px 6px -1px rgba(0,0,0,0.1))}'
            . '.sb-post-card__media img{width:100%;height:12rem;object-fit:cover;display:block}'
            . '.sb-post-card__body{padding:1.25rem;display:flex;flex-direction:column;flex:1}'
            . '.sb-post-card__category{display:inline-block;font-size:.75rem;font-weight:600;text-transform:uppercase;color:var(--sb-color-accent,#6366f1);margin-bottom:.5rem}'
            . '.sb-post-card__title{margin:0 0 .5rem;font-size:1.25rem;line-height:1.4}'
            . '.sb-post-card__title a{color:inherit;text-decoration:none}'
            . '.sb-post-card__title a:hover{color:var(--sb-color-accent,#6366f1)}'
            . '.sb-post-card__excerpt{color:var(--sb-text-secondary,#475569);font-size:.875rem;line-height:1.5;margin:0 0 1rem;flex:1}'
            . '.sb-post-card__meta{display:flex;align-items:center;gap:.75rem;font-size:.75rem;color:var(--sb-text-muted,#94a3b8);margin-bottom:1rem}'
            . '.sb-pagination{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:2rem}'
            . '.sb-pagination__page,.sb-pagination__prev,.sb-pagination__next{display:inline-flex;align-items:center;justify-content:center;padding:.5rem .875rem;border-radius:var(--sb-radius-md,.5rem);border:1px solid var(--sb-border-default,#e2e8f0);font-size:.875rem;font-weight:500;text-decoration:none;color:inherit}'
            . '.sb-pagination__current{background:var(--sb-surface-accent,#6366f1);color:#fff;border-color:var(--sb-surface-accent,#6366f1)}'
            . '.sb-pagination__disabled{opacity:.5;pointer-events:none}';

        foreach (self::WIDTHS as $name => $max) {
            $css .= '.sb-w-' . $name . '{max-width:' . $max . ';margin-left:auto;margin-right:auto}';
        }
        foreach (self::GAP as $name => $value) {
            $css .= '.sb-gap-' . $name . '{gap:' . $value . '}';
        }
        foreach (self::PADDING as $name => $value) {
            $css .= '.sb-pad-' . $name . '{padding:' . $value . '}';
        }

        $css .= self::responsive('');
        foreach (self::MIN_WIDTH as $bp => $px) {
            $css .= '@media (min-width:' . $px . 'px){' . self::responsive($bp . '-') . '}';
        }
        foreach (self::HIDE_QUERIES as $bp => $query) {
            $css .= '@media ' . $query . '{.sb-hide-' . $bp . '{display:none!important}}';
        }
        $css .= '@media (min-width:1024px){.sb-hide-desktop{display:none!important}}';
        $css .= '@media (min-width:768px) and (max-width:1023.98px){.sb-hide-tablet{display:none!important}}';
        $css .= '@media (max-width:767.98px){.sb-hide-mobile{display:none!important}}';

        return self::$cache = $css;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
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
