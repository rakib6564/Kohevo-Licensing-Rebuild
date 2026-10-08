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
    public const VERSION = '4';

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
            // Icon, list, quote and link elements (P2b).
            . '.sb-icon{display:flex;color:var(--sb-color-accent);margin:0 0 1rem}'
            . '.sb-icon--center{justify-content:center}.sb-icon--right{justify-content:flex-end}'
            . '.sb-icon__svg{display:block;width:2rem;height:2rem}'
            . '.sb-icon--sm .sb-icon__svg{width:1.25rem;height:1.25rem}.sb-icon--lg .sb-icon__svg{width:3rem;height:3rem}.sb-icon--xl .sb-icon__svg{width:4.5rem;height:4.5rem}'
            . '.sb-list{margin:0 0 1rem;padding-left:1.4rem;line-height:1.6}.sb-list__item{margin:.25rem 0}'
            . '.sb-list--none{list-style:none;padding-left:0}'
            . '.sb-list--check{list-style:none;padding-left:0}'
            . '.sb-list--check .sb-list__item{position:relative;padding-left:1.7rem}'
            . '.sb-list--check .sb-list__item::before{content:"";position:absolute;left:.15rem;top:.35em;width:.9rem;height:.5rem;border-left:2px solid var(--sb-color-accent);border-bottom:2px solid var(--sb-color-accent);transform:rotate(-45deg) scale(.9)}'
            . '.sb-quote{margin:0 0 1.5rem}.sb-quote--center{text-align:center}'
            . '.sb-quote__text{margin:0;padding:0 0 0 1.1rem;border-left:3px solid var(--sb-color-accent);font-size:1.25rem;line-height:1.5}'
            . '.sb-quote--center .sb-quote__text{border-left:0;padding:0}'
            . '.sb-quote__text p{margin:0}'
            . '.sb-quote__caption{margin-top:.75rem;font-size:.9rem;color:var(--sb-text-muted)}'
            . '.sb-quote__author{font-weight:600;color:var(--sb-text-primary)}.sb-quote__role::before{content:" · "}'
            . '.sb-card{border-radius:var(--sb-radius-md);margin:0 0 1rem}'
            . '.sb-card--outlined{border:1px solid var(--sb-border-default)}'
            . '.sb-card--filled{background:var(--sb-surface-secondary)}'
            . '.sb-card--shadow{box-shadow:0 6px 24px rgba(0,0,0,.12)}'
            . '.sb-card--pad-sm{padding:.75rem}.sb-card--pad-md{padding:1.25rem}.sb-card--pad-lg{padding:2rem}'
            . '.sb-table-wrap{overflow-x:auto;margin:0 0 1rem}'
            . '.sb-table{width:100%;border-collapse:collapse;text-align:left}'
            . '.sb-table th,.sb-table td{padding:.6rem .8rem;border-bottom:1px solid var(--sb-border-default)}'
            . '.sb-table th{font-weight:600}.sb-table__caption{caption-side:top;text-align:left;font-weight:600;padding:0 0 .5rem}'
            . '.sb-table-wrap--striped tbody tr:nth-child(even){background:var(--sb-surface-secondary)}'
            . '.sb-countdown{margin:0 0 1rem}.sb-countdown__label{margin:0 0 .5rem;font-weight:600}'
            . '.sb-countdown__date{margin:0;color:var(--sb-text-muted)}'
            . '.sb-countdown__units{display:none;gap:1rem}'
            . '.sb-countdown[data-sb-live] .sb-countdown__units{display:flex}.sb-countdown[data-sb-live] .sb-countdown__date{display:none}'
            . '.sb-countdown__unit{display:flex;flex-direction:column;align-items:center;min-width:3.5rem}'
            . '.sb-countdown__num{font-size:2rem;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.1}'
            . '.sb-countdown__name{font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--sb-text-muted)}'
            . '.sb-countdown__done{display:none;margin:.5rem 0 0;font-weight:600}.sb-countdown[data-sb-finished] .sb-countdown__done{display:block}'
            . '.sb-link-row{margin:0 0 1rem}.sb-link-row--center{text-align:center}.sb-link-row--right{text-align:right}'
            . '.sb-link{color:var(--sb-color-accent);font-weight:600}.sb-link--plain{text-decoration:none}'
            . '.sb-link--arrow{text-decoration:none}.sb-link--arrow::after{content:" \\2192"}'
            . '.sb-feature-list__items,.sb-catalog__list{list-style:none;margin:0;padding:0;display:grid;gap:1rem}'
            . '.sb-feature,.sb-catalog__item,.sb-form-card{padding:1.25rem;border-radius:var(--sb-radius-md)}'
            . '.sb-feature--bordered,.sb-catalog__item,.sb-form-card{border:1px solid var(--sb-border-default)}'
            // Stats — `.sb-stats` must be the grid itself, because the responsive
            // `.sb-cols-N` utilities only set `grid-template-columns`. The value
            // uses tabular figures so the count-up cannot change the cell width
            // as digits change.
            . '.sb-stats{display:grid;gap:1.5rem 1rem;margin:0}'
            . '.sb-stat{display:flex;flex-direction:column;gap:.35rem;min-width:0}'
            . '.sb-stat__value{font-size:clamp(2rem,4vw,2.75rem);line-height:1.05;font-weight:700;letter-spacing:-.02em;color:var(--sb-text-primary);font-variant-numeric:tabular-nums}'
            . '.sb-stat__label{font-size:.875rem;line-height:1.4;color:var(--sb-text-muted)}'
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
            . '.sb-hl{color:var(--sb-color-accent)}'
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
            . '.sb-pagination__disabled{opacity:.5;pointer-events:none}'
            . '.sb-post-title{margin:0 0 1rem;font-size:2.25rem;line-height:1.25;font-weight:700}'
            . '.sb-post-title--align-center{text-align:center}.sb-post-title--align-right{text-align:right}'
            . '.sb-post-content{font-size:1.125rem;line-height:1.75;color:var(--sb-text-default,#1e293b);margin-bottom:2rem}'
            . '.sb-post-meta{display:flex;align-items:center;gap:.75rem;font-size:.875rem;color:var(--sb-text-muted,#64748b);margin-bottom:1.5rem}'
            . '.sb-archive-title{font-size:2rem;line-height:1.3;font-weight:700;margin:0 0 1.5rem}'
            . '.sb-search-form{width:100%;max-width:36rem;margin:0 auto 2rem}'
            . '.sb-search-input-wrap{display:flex;align-items:center;border:1px solid var(--sb-border-default,#cbd5e1);border-radius:var(--sb-radius-md,.5rem);overflow:hidden;background:#fff}'
            . '.sb-search-input{flex:1;border:none;padding:.75rem 1rem;font-size:1rem;outline:none}'
            . '.sb-search-button{background:var(--sb-surface-accent,#6366f1);color:#fff;border:none;padding:.75rem 1.5rem;font-weight:600;cursor:pointer}'
            . '.sb-animate-fade-in{animation:sb-fade-in var(--sb-anim-duration,.4s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-fade-up{animation:sb-fade-up var(--sb-anim-duration,.5s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-fade-down{animation:sb-fade-down var(--sb-anim-duration,.5s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-scale-up{animation:sb-scale-up var(--sb-anim-duration,.4s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-slide-in{animation:sb-slide-in var(--sb-anim-duration,.5s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-move-left{animation:sb-move-left var(--sb-anim-duration,.5s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-move-right{animation:sb-move-right var(--sb-anim-duration,.5s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-reveal-left{animation:sb-reveal-left var(--sb-anim-duration,.7s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '.sb-animate-reveal-up{animation:sb-reveal-up var(--sb-anim-duration,.7s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
            . '@keyframes sb-fade-in{from{opacity:0}to{opacity:1}}'
            . '@keyframes sb-fade-up{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}'
            . '@keyframes sb-fade-down{from{opacity:0;transform:translateY(-24px)}to{opacity:1;transform:translateY(0)}}'
            . '@keyframes sb-scale-up{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}'
            . '@keyframes sb-slide-in{from{transform:translateX(-100%)}to{transform:translateX(0)}}'
            . '@keyframes sb-move-left{from{opacity:0;transform:translateX(48px)}to{opacity:1;transform:translateX(0)}}'
            . '@keyframes sb-move-right{from{opacity:0;transform:translateX(-48px)}to{opacity:1;transform:translateX(0)}}'
            . '@keyframes sb-reveal-left{from{clip-path:inset(0 100% 0 0)}to{clip-path:inset(0 0 0 0)}}'
            . '@keyframes sb-reveal-up{from{clip-path:inset(100% 0 0 0)}to{clip-path:inset(0 0 0 0)}}'
            . '.sb-interaction-hover{transition:transform .25s ease,box-shadow .25s ease}'
            . '.sb-interaction-hover:hover{transform:translateY(-3px) scale(1.02)}'
            // Keyboard parity: a hover-only lift leaves the block unreachable
            // for keyboard users, so :focus-visible gets the same affordance.
            . '.sb-interaction-focus{transition:transform .25s ease,box-shadow .25s ease,outline-color .25s ease}'
            . '.sb-interaction-focus:focus-visible{transform:translateY(-3px) scale(1.02);outline:2px solid currentColor;outline-offset:3px}'
            // Click/load are CSS-only affordances; the behavioural part of a
            // click trigger needs the Phase 2 runtime, so nothing here claims
            // to deliver it.
            . '.sb-interaction-click,.sb-interaction-load{transition:transform .25s ease,box-shadow .25s ease}'
            // Scroll-driven reveal. Guarded by @supports so browsers without
            // scroll-driven animations show the block NORMALLY rather than
            // leaving it stuck at opacity:0 — a content-invisible fallback is
            // worse than no animation at all.
            . '@supports (animation-timeline:view()){'
            . '.sb-interaction-viewport-enter{animation-timeline:view();animation-range:entry 5% cover 26%;animation-fill-mode:both;}'
            . '.sb-interaction-viewport-enter.sb-animate-fade-up{animation-name:sb-fade-up}'
            . '.sb-interaction-viewport-enter.sb-animate-fade-in{animation-name:sb-fade-in}'
            . '.sb-interaction-viewport-enter.sb-animate-fade-down{animation-name:sb-fade-down}'
            . '.sb-interaction-viewport-enter.sb-animate-scale-up{animation-name:sb-scale-up}'
            . '.sb-interaction-viewport-enter.sb-animate-slide-in{animation-name:sb-slide-in}'
            . '.sb-interaction-viewport-enter.sb-animate-move-left{animation-name:sb-move-left}'
            . '.sb-interaction-viewport-enter.sb-animate-move-right{animation-name:sb-move-right}'
            . '.sb-interaction-viewport-enter.sb-animate-reveal-left{animation-name:sb-reveal-left}'
            . '.sb-interaction-viewport-enter.sb-animate-reveal-up{animation-name:sb-reveal-up}'
            . '}'
            // `scroll` is a continuous/parallax trigger; it is exposed in the
            // inspector but intentionally renders no CSS until the runtime
            // ships, so nothing here can imply behaviour that does not exist.
            . '@media (prefers-reduced-motion:reduce){[class*="sb-animate-"],[class*="sb-interaction-"]{animation:none!important;transition:none!important;transform:none!important;clip-path:none!important}}'
            // ── Interaction runtime (Phase 2) ────────────────────────────────────
            // Styles for the behaviours `studio-runtime.js` adds on top of the
            // server-rendered markup. Everything here is progressive: with the
            // runtime absent the page still reads correctly through the base
            // rules above, which is why nothing here is load-bearing.
            //
            // Tabs / accordion ── ARIA `hidden` does the work; these rules only
            // give the visible chrome a consistent look.
            . '.sb-tabs{display:flex;gap:.25rem;border-bottom:1px solid var(--sb-border-default,#e2e8f0);margin-bottom:1.25rem;flex-wrap:wrap}'
            . '.sb-tabs__tab{background:none;border:0;border-bottom:2px solid transparent;padding:.6rem .95rem;font:inherit;color:inherit;cursor:pointer;opacity:.65;transition:opacity .15s ease,border-color .15s ease}'
            . '.sb-tabs__tab[aria-selected="true"]{opacity:1;border-bottom-color:currentColor}'
            . '.sb-tabs__tab:focus-visible{outline:2px solid currentColor;outline-offset:-2px;border-radius:2px}'
            . '.sb-accordion{border:1px solid var(--sb-border-default,#e2e8f0);border-radius:.5rem;overflow:hidden}'
            . '.sb-accordion__item+.sb-accordion__item{border-top:1px solid var(--sb-border-default,#e2e8f0)}'
            . '.sb-accordion__button{width:100%;display:flex;justify-content:space-between;align-items:center;gap:1rem;background:none;border:0;padding:1rem 1.25rem;font:inherit;color:inherit;text-align:left;cursor:pointer}'
            . '.sb-accordion__button:focus-visible{outline:2px solid currentColor;outline-offset:-2px}'
            . '.sb-accordion__icon{flex:none;transition:transform .2s ease}'
            . '.sb-accordion__button[aria-expanded="true"] .sb-accordion__icon{transform:rotate(180deg)}'
            . '.sb-accordion__panel{padding:0 1.25rem 1.25rem;overflow:hidden}'
            // Without script, `[hidden]` keeps the browser's default `display:none`
            // — the closed rows really are gone for everyone, including screen
            // readers. Once the runtime boots it swaps in a height-0 state so it can
            // animate the open/close; it is safe to un-hide the box at that point
            // because the runtime also sets `aria-hidden="true"` on closed panels.
            . 'html[data-sb-ready] .sb-accordion__panel{transition:height .26s ease}'
            . 'html[data-sb-ready] .sb-accordion__panel[hidden]{display:block;height:0;padding-top:0;padding-bottom:0}'
            // Carousel ── the track is translated by the runtime; this only sizes
            // it. `scrollbar-width:none` keeps a stray scrollbar out of the UI.
            . '.sb-carousel{position:relative;overflow:hidden}'
            . '.sb-carousel__track{display:flex;gap:1rem;transition:transform .45s cubic-bezier(.22,1,.36,1);will-change:transform;scrollbar-width:none}'
            . '.sb-carousel__track::-webkit-scrollbar{display:none}'
            . '.sb-carousel__slide{flex:0 0 100%;min-width:0}'
            . '.sb-carousel__nav{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:1rem}'
            . '.sb-carousel__btn{width:2.25rem;height:2.25rem;border-radius:50%;border:1px solid var(--sb-border-default,#e2e8f0);background:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:inherit}'
            . '.sb-carousel__btn:disabled{opacity:.35;cursor:default}'
            . '.sb-carousel__btn:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
            . '.sb-carousel__dot{width:.55rem;height:.55rem;padding:0;border:0;border-radius:50%;background:currentColor;opacity:.3;cursor:pointer}'
            // Two active-state spellings must both light up: the server marks the
            // first dot with `aria-current` so a no-JS page is truthful, while the
            // runtime rebuilds the dots and switches to `aria-selected` + `.is-active`.
            . '.sb-carousel__dot[aria-current="true"],.sb-carousel__dot[aria-selected="true"],.sb-carousel__dot.is-active{opacity:1}'
            // Lightbox ── hidden until the runtime opens it.
            . '.sb-lightbox{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.9)}'
            // The runtime marks an open overlay with `[data-sb-open]`, NOT a class.
            // `:target` still serves the no-JS case, so both selectors stay.
            . '.sb-lightbox:target,.sb-lightbox[data-sb-open]{display:flex}'
            . '.sb-lightbox__figure{margin:0;max-width:92vw;max-height:88vh;display:flex;flex-direction:column;gap:.75rem}'
            . '.sb-lightbox__image{max-width:92vw;max-height:80vh;object-fit:contain}'
            . '.sb-lightbox__caption{color:#fff;text-align:center;font-size:.9rem}'
            . '.sb-lightbox__close,.sb-lightbox__nav{position:absolute;background:rgba(255,255,255,.1);border:0;color:#fff;cursor:pointer;width:2.75rem;height:2.75rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:1.25rem}'
            . '.sb-lightbox__close{top:1rem;right:1rem}'
            . '.sb-lightbox__nav--prev{left:1rem}.sb-lightbox__nav--next{right:1rem}'
            . '.sb-lightbox__close:focus-visible,.sb-lightbox__nav:focus-visible{outline:2px solid #fff;outline-offset:2px}'
            // Scroll lock while an overlay owns the screen. `overflow:hidden` on
            // <html> only — locking <body> as well causes a jump on iOS.
            . 'html.sb-scroll-lock{overflow:hidden}'
            // `scroll-margin-top` so a sticky header never covers the heading an
            // in-page anchor scrolled to.
            . '[id]{scroll-margin-top:5rem}'
            . '@media (prefers-reduced-motion:reduce){.sb-carousel__track{transition:none!important}.sb-accordion__icon{transition:none!important}}'
            . '.sb-modal{display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center}'
            . '.sb-modal:target,.sb-modal[data-sb-open]{display:flex}'
            . '.sb-modal__backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px)}'
            . '.sb-modal__dialog{position:relative;background:#fff;border-radius:.5rem;max-width:36rem;width:90%;z-index:10000;box-shadow:0 20px 25px -5px rgba(0,0,0,.1);overflow:hidden}'
            . '.sb-modal--sm .sb-modal__dialog{max-width:24rem}.sb-modal--lg .sb-modal__dialog{max-width:48rem}.sb-modal--full .sb-modal__dialog{max-width:95vw;height:90vh}'
            . '.sb-modal__header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb}'
            . '.sb-modal__close{background:none;border:none;font-size:1.5rem;text-decoration:none;cursor:pointer;line-height:1;color:#6b7280}'
            . '.sb-modal__body{padding:1.25rem}'
            . '.sb-offcanvas{display:none;position:fixed;inset:0;z-index:9999}'
            . '.sb-offcanvas:target,.sb-offcanvas[data-sb-open]{display:block}'
            . '.sb-offcanvas__backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5)}'
            . '.sb-offcanvas__panel{position:fixed;top:0;bottom:0;width:22rem;max-width:85vw;background:#fff;z-index:10000;display:flex;flex-direction:column;box-shadow:-4px 0 16px rgba(0,0,0,.15);transition:transform .3s ease}'
            . '.sb-offcanvas--right .sb-offcanvas__panel{right:0}'
            . '.sb-offcanvas--left .sb-offcanvas__panel{left:0;box-shadow:4px 0 16px rgba(0,0,0,.15)}'
            . '.sb-offcanvas__header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb}'
            . '.sb-offcanvas__close{background:none;border:none;font-size:1.5rem;text-decoration:none;cursor:pointer;line-height:1;color:#6b7280}'
            . '.sb-offcanvas__body{padding:1.25rem;flex:1;overflow-y:auto}'
            . '.sb-form{display:flex;flex-direction:column;gap:1rem}'
            . '.sb-form-group{display:flex;flex-direction:column;gap:.375rem}'
            . '.sb-form-group label{font-weight:500;font-size:.875rem}'
            . '.sb-form-group input,.sb-form-group textarea,.sb-form-group select{padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:.375rem;font-size:.875rem;width:100%;box-sizing:border-box}'
            . '.sb-form-group textarea{min-height:6rem;resize:vertical}'
            . '.sb-form-group input[type="checkbox"],.sb-form-group input[type="radio"]{width:auto}'
            . '.sb-form-help{font-size:.75rem;color:#6b7280}'
            . '.sb-form__actions{margin-top:.5rem}'
            . '.sb-gallery{display:grid}'
            . '.sb-gallery--cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}'
            . '.sb-gallery--cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}'
            . '.sb-gallery--cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}'
            . '.sb-gallery--cols-6{grid-template-columns:repeat(6,minmax(0,1fr))}'
            . '.sb-gallery--gap-none{gap:0}.sb-gallery--gap-xs{gap:.25rem}.sb-gallery--gap-sm{gap:.5rem}.sb-gallery--gap-md{gap:1rem}.sb-gallery--gap-lg{gap:1.5rem}'
            . '.sb-gallery--rounded .sb-gallery__item{border-radius:.5rem}'
            . '.sb-gallery__item{margin:0;overflow:hidden;position:relative}'
            . '.sb-gallery__item img{width:100%;height:100%;object-fit:cover;display:block}'
            . '.sb-gallery__item--aspect-1-1{aspect-ratio:1/1}.sb-gallery__item--aspect-4-3{aspect-ratio:4/3}.sb-gallery__item--aspect-16-9{aspect-ratio:16/9}'
            . '.sb-video-wrapper{position:relative;width:100%;overflow:hidden;border-radius:.5rem}'
            . '.sb-video--aspect-16-9{aspect-ratio:16/9}.sb-video--aspect-4-3{aspect-ratio:4/3}.sb-video--aspect-1-1{aspect-ratio:1/1}'
            . '.sb-video-wrapper iframe,.sb-video-wrapper video{width:100%;height:100%;border:0}';

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
