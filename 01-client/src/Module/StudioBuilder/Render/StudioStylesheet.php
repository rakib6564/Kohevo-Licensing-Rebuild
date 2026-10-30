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
use Slate\Module\StudioBuilder\Document\StyleSurface;

final class StudioStylesheet
{
    public const VERSION = '54';

    private const MIN_WIDTH = ['sm' => 640, 'md' => 768, 'lg' => 1024];

    private const HIDE_QUERIES = [
        'base' => '(max-width:639.98px)',
        'sm'   => '(min-width:640px) and (max-width:767.98px)',
        'md'   => '(min-width:768px) and (max-width:1023.98px)',
        'lg'   => '(min-width:1024px)',
    ];

    // Padding and gap sizes follow the site's three Spacing tokens (Site settings). Each size is a
    // fixed multiple of one token, so with the default tokens (.75rem, 1.5rem, 3rem) every size is
    // what it has always been, and editing a token scales the sizes built on it.
    private const PADDING = [
        'none' => '0',
        'xs'   => 'calc(var(--sb-space-sm,.75rem)*2/3)',
        'sm'   => 'calc(var(--sb-space-sm,.75rem)*4/3)',
        'md'   => 'calc(var(--sb-space-md,1.5rem)*4/3)',
        'lg'   => 'calc(var(--sb-space-lg,3rem)*4/3)',
        'xl'   => 'calc(var(--sb-space-lg,3rem)*2)',
        '2xl'  => 'calc(var(--sb-space-lg,3rem)*8/3)',
    ];
    private const GAP = [
        'none' => '0',
        'xs'   => 'calc(var(--sb-space-sm,.75rem)/3)',
        'sm'   => 'calc(var(--sb-space-sm,.75rem)*2/3)',
        'md'   => 'calc(var(--sb-space-md,1.5rem)*2/3)',
        'lg'   => 'calc(var(--sb-space-lg,3rem)/2)',
        'xl'   => 'calc(var(--sb-space-lg,3rem)*2/3)',
        '2xl'  => 'var(--sb-space-lg,3rem)',
    ];
    /** Section widths => [Site settings › Layout token, its default]; `full` is no limit. */
    private const WIDTHS  = ['default' => ['content', '80rem'], 'narrow' => ['narrow', '42rem'], 'normal' => ['normal', '64rem'], 'wide' => ['wide', '80rem'], 'full' => null];
    private const ALIGN   = ['left' => 'left', 'center' => 'center', 'right' => 'right', 'justify' => 'justify'];

    private static ?string $cache = null;

    public static function css(): string
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $css = '*,*::before,*::after{box-sizing:border-box}'
            // Site settings › Text: body text (its colour, size and line height), the headings' colour and line height,
            // and the space blocks leave between them (`type.flow`, `type.heading_gap`). The defaults are the look every
            // site had before these settings existed. A heading's colour is zero-weight so any block colour wins over it.
            . 'body.sb-body{margin:0;background:var(--sb-surface-page);color:var(--sb-text-body,var(--sb-text-primary));font-family:var(--sb-font-body);font-size:var(--sb-type-body_size,1rem);line-height:var(--sb-type-body_leading,1.6);-webkit-font-smoothing:var(--sb-type-smoothing,auto);overflow-x:clip}'
            . '.sb-body h1,.sb-body h2,.sb-body h3,.sb-body h4,.sb-body h5,.sb-body h6{font-family:var(--sb-font-heading);line-height:var(--sb-type-heading_leading,1.2);margin:0 0 var(--sb-type-heading_gap,.5em);text-wrap:pretty}'
            . ':where(.sb-body) :where(h1,h2,h3,h4,h5,h6){color:var(--sb-text-heading,inherit)}'
            . '.sb-body img{max-width:100%;height:auto}'
            // Buttons are links too: the generic link colour must not reach them (it would equal
            // the primary button's background and hide the label), so they keep their variant colours.
            // A linked heading keeps the heading's colour (`.sb-heading__link` alone was outweighed by this rule).
            . '.sb-body a:not(.sb-button):not(.sb-heading__link){color:var(--sb-color-accent)}'
            . '.sb-main{display:block}'
            . '.sb-section__inner{margin:0 auto;padding-left:var(--sb-type-section_gutter,1rem);padding-right:var(--sb-type-section_gutter,1rem);display:grid}'
            . '.sb-section__inner>*{min-width:0}'
            // The default chrome's padding and rule; a custom header or footer partial renders exactly as designed.
            . '.sb-site-header:not(.sb-site-header--partial),.sb-site-footer:not(.sb-site-footer--partial){padding:1rem;border-color:var(--sb-border-default)}'
            . '.sb-site-header:not(.sb-site-header--partial){border-bottom:1px solid var(--sb-border-default)}'
            . '.sb-site-footer:not(.sb-site-footer--partial){border-top:1px solid var(--sb-border-default);color:var(--sb-text-muted);font-size:.875rem}'
            . '.sb-site-brand{display:inline-flex;align-items:center;gap:.5rem;font-weight:600;text-decoration:none;color:var(--sb-text-primary)}'
            . '.sb-site-brand img{height:2rem;width:auto}'
            . '.sb-hero{display:grid;gap:2rem;align-items:center}'
            . '.sb-hero--with-media{grid-template-columns:repeat(auto-fit,minmax(min(100%,20rem),1fr))}'
            . '.sb-hero__eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:.8rem;margin:0 0 .5rem}'
            . '.sb-hero__heading{font-size:clamp(2rem,5vw,3.5rem)}'
            . '.sb-hero__media img{display:block;width:100%;border-radius:var(--sb-radius-lg);object-fit:cover}'
            . '.sb-button-row{margin:var(--sb-type-flow,1rem) 0 0}'
            . '.sb-button{font-style:inherit;display:inline-block;padding:.7rem 1.3rem;border-radius:var(--sb-radius-md);text-decoration:none;font-weight:600;border:2px solid var(--sb-color-accent)}'
            . '.sb-button--primary{background:var(--sb-surface-accent);color:var(--sb-text-inverse)}'
            . '.sb-button--secondary{background:var(--sb-surface-secondary);color:var(--sb-text-primary);border-color:var(--sb-surface-secondary)}'
            . '.sb-button--outline{background:transparent;color:var(--sb-color-accent)}'
            . '.sb-button--ghost{background:transparent;border-color:transparent;color:var(--sb-color-accent)}'
            . '.sb-button--full{display:block;text-align:center}'
            // A button's icon sits on the text line at the text's size; a linked heading keeps the heading's own look.
            . '.sb-button__icon{display:inline-flex;vertical-align:-.125em}.sb-button__icon svg{width:1em;height:1em}'
            . '.sb-heading__link{color:inherit;text-decoration:inherit}'
            . '.sb-image{margin:0}.sb-image img{display:block;width:100%;object-fit:cover}'
            // An Image block's size (Inspector > Content > Dimensions) is set on its wrapper: a height or an aspect ratio there is the picture's
            // box, and the wrapper's object-fit and object-position reach the picture (cover unless the author picks another; a focal
            // point set on the image itself still wins).
            . '.sb-block--core-image{object-fit:cover}'
            . '.sb-block--core-image>.sb-image{height:100%;display:flex;flex-direction:column;object-fit:inherit;object-position:inherit}'
            . '.sb-block--core-image>.sb-image>img{flex:1 1 auto;min-height:0;height:100%;object-fit:inherit;object-position:inherit}'
            // A linked image: the link takes the picture's place in the box, so sizes and fit still reach the picture.
            . '.sb-image__link{display:block}.sb-block--core-image>.sb-image>.sb-image__link{flex:1 1 auto;min-height:0;object-fit:inherit;object-position:inherit}'
            . '.sb-block--core-image>.sb-image>.sb-image__link>img{height:100%;object-fit:inherit;object-position:inherit}'
            // A Container's layers, behind its children: a background video, the overlay (custom properties its style sets,
            // so a hover state can change them), then the shape dividers, which can be brought in front instead.
            . '.sb-block--core-container::before{content:"";display:var(--sb-ov-display,none);position:absolute;inset:0;z-index:-1;pointer-events:none;border-radius:inherit;background:var(--sb-ov-bg,transparent);opacity:var(--sb-ov-op,1);mix-blend-mode:var(--sb-ov-blend,normal);transition:inherit}'
            . '.sb-layered{isolation:isolate}:where(.sb-layered){position:relative}'
            . '.sb-bg-video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:-2;pointer-events:none;border-radius:inherit}'
            . '@media (prefers-reduced-motion:reduce){.sb-bg-video{display:none}}'
            . '.sb-shape{position:absolute;left:0;right:0;overflow:hidden;line-height:0;pointer-events:none;z-index:-1;color:var(--sb-surface-primary,#fff)}'
            . '.sb-shape--front{z-index:2}.sb-shape--top{top:-1px}.sb-shape--bottom{bottom:-1px;transform:rotate(180deg)}'
            . '.sb-shape svg{display:block;position:relative;left:50%;width:var(--sb-shape-w,100%);height:var(--sb-shape-h,60px);transform:translateX(-50%);fill:currentColor}'
            . '.sb-shape--flip svg{transform:translateX(-50%) scaleX(-1)}'
            // A text block's drop cap: its first letter spans about three lines.
            . '.sb-dropcap::first-letter{float:left;font-size:3.4em;line-height:.85;margin:.06em .12em 0 0;font-weight:600}'
            . '.sb-image--rounded img{border-radius:var(--sb-radius-md)}'
            . '.sb-image--ratio-16-9 img{aspect-ratio:16/9}.sb-image--ratio-4-3 img{aspect-ratio:4/3}'
            . '.sb-image--ratio-1-1 img{aspect-ratio:1/1}.sb-image--ratio-3-4 img{aspect-ratio:3/4}'
            . '.sb-image figcaption{font-size:.875rem;color:var(--sb-text-muted);margin-top:.5rem}'
            // Icon, list, quote and link elements (P2b).
            . ':where(.sb-block--core-icon){color:var(--sb-color-accent)}.sb-icon{display:flex;margin:0 0 var(--sb-type-flow,1rem)}'
            // The Icon's views: stacked fills its frame with the icon colour (the icon takes the secondary colour), framed outlines it.
            . '.sb-icon__link{display:inline-flex;color:inherit}.sb-icon__frame{display:inline-flex;padding:.5em;line-height:0}'
            . '.sb-icon--stacked .sb-icon__frame{background:currentColor}:where(.sb-icon--stacked .sb-icon__svg){color:var(--sb-surface-primary,#fff)}'
            . '.sb-icon--framed .sb-icon__frame{border:2px solid currentColor}.sb-icon--circle .sb-icon__frame{border-radius:50%}'
            . '.sb-icon--center{justify-content:center}.sb-icon--right{justify-content:flex-end}'
            . '.sb-icon__svg{display:block;width:2rem;height:2rem}'
            . '.sb-icon--sm .sb-icon__svg{width:1.25rem;height:1.25rem}.sb-icon--lg .sb-icon__svg{width:3rem;height:3rem}.sb-icon--xl .sb-icon__svg{width:4.5rem;height:4.5rem}'
            . '.sb-list{margin:0 0 var(--sb-type-flow,1rem);padding-left:1.4rem;line-height:var(--sb-type-body_leading,1.6)}.sb-list__item{margin:.25rem 0}'
            . '.sb-list--none{list-style:none;padding-left:0}'
            . '.sb-list--check{list-style:none;padding-left:0}'
            . '.sb-list--check .sb-list__item{position:relative;padding-left:1.7rem}'
            // The tick and the dash are drawn in `currentColor` of their own, so the list's Markers part can recolour them.
            . '.sb-list--check .sb-list__item::before{content:"";position:absolute;left:.15rem;top:.35em;width:.9rem;height:.5rem;color:var(--sb-color-accent);border-left:2px solid;border-bottom:2px solid;transform:rotate(-45deg) scale(.9)}'
            . '.sb-list--dash{list-style:none;padding-left:0}'
            . '.sb-list--dash .sb-list__item{position:relative;padding-left:1.4rem}'
            . '.sb-list--dash .sb-list__item::before{content:"";position:absolute;left:0;top:.8em;width:.8rem;height:2px;color:var(--sb-color-accent);background:currentColor}'
            . '.sb-list--cols{display:grid;column-gap:1.5rem}.sb-list--cols>.sb-list__item{margin:0 0 .25rem}'
            . '.sb-list--inline{display:flex;flex-wrap:wrap;column-gap:1rem;row-gap:.25rem;list-style:none;padding-left:0}.sb-list--inline>.sb-list__item{margin:0}'
            . '.sb-list--divided:not(.sb-list--inline)>.sb-list__item+.sb-list__item{border-top:1px solid var(--sb-border-default);padding-top:.5rem;margin-top:.5rem}'
            . '.sb-list--divided.sb-list--inline>.sb-list__item+.sb-list__item{border-left:1px solid var(--sb-border-default);padding-left:1rem}'
            // A tick or a dash sits beside the text, so it moves with the room a line between the items adds.
            . '.sb-list--divided:not(.sb-list--inline)>.sb-list__item+.sb-list__item::before{margin-top:.5rem}'
            . '.sb-list--divided.sb-list--inline:is(.sb-list--check,.sb-list--dash)>.sb-list__item+.sb-list__item{padding-left:2.7rem}'
            . '.sb-list--divided.sb-list--inline:is(.sb-list--check,.sb-list--dash)>.sb-list__item+.sb-list__item::before{margin-left:1rem}'
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
            . '.sb-table{width:100%;border-collapse:collapse;text-align:inherit}'
            . '.sb-table th,.sb-table td{padding:.6rem .8rem;border-bottom:1px solid var(--sb-border-default)}'
            . '.sb-table th{font-weight:600;text-align:inherit}.sb-table__caption{caption-side:top;text-align:inherit;font-weight:600;padding:0 0 .5rem}'
            . '.sb-table-wrap--striped tbody tr:nth-child(even){background:var(--sb-surface-secondary)}'
            . '.sb-countdown[hidden]{display:none}.sb-countdown{margin:0 0 1rem}.sb-countdown__label{margin:0 0 .5rem;font-weight:600}'
            . '.sb-countdown__date{margin:0;color:var(--sb-text-muted)}'
            . '.sb-countdown__units{display:none;flex-wrap:wrap;gap:1rem}'
            . '.sb-countdown[data-sb-live] .sb-countdown__units{display:flex}.sb-countdown[data-sb-live] .sb-countdown__date{display:none}'
            . '.sb-countdown__unit{display:flex;flex:1 1 0;flex-direction:column;align-items:center;min-width:3.5rem}'
            . '.sb-countdown--inline .sb-countdown__unit{flex:0 1 auto;flex-direction:row;align-items:baseline;gap:.35em;min-width:0}'
            . '.sb-countdown__num{font-size:2rem;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.1}'
            . '.sb-countdown__name{font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--sb-text-muted)}'
            . '.sb-countdown__done{display:none;margin:.5rem 0 0;font-weight:600}.sb-countdown[data-sb-finished] .sb-countdown__done{display:block}'
            . '.sb-countdown--done-center .sb-countdown__done{text-align:center}.sb-countdown--done-right .sb-countdown__done{text-align:right}.sb-countdown--done-justify .sb-countdown__done{text-align:justify}'
            . ':where(.sb-block--core-divider){border-color:var(--sb-border-default)}'
            . '.sb-divider{box-sizing:content-box;height:0;margin:1rem 0;border:0;border-top-style:solid;border-top-color:inherit}'
            . '.sb-divider--solid{border-top-style:solid}.sb-divider--dashed{border-top-style:dashed}.sb-divider--dotted{border-top-style:dotted}'
            . '.sb-divider--thin{border-top-width:1px}.sb-divider--medium{border-top-width:2px}.sb-divider--thick{border-top-width:4px}'
            . '.sb-divider--w-full{width:100%}.sb-divider--w-wide{width:75%}.sb-divider--w-narrow{width:50%}.sb-divider--w-short{width:25%}'
            . '.sb-divider--left{margin-left:0;margin-right:auto}.sb-divider--center{margin-left:auto;margin-right:auto}.sb-divider--right{margin-left:auto;margin-right:0}'
            . '.sb-spacer{display:block;width:100%}'
            . '.sb-spacer--xs{height:.5rem}.sb-spacer--sm{height:1rem}.sb-spacer--md{height:2rem}.sb-spacer--lg{height:3.5rem}.sb-spacer--xl{height:5.5rem}.sb-spacer--2xl{height:8rem}'
            . '@media (max-width:767.98px){.sb-spacer--xs{height:.25rem}.sb-spacer--sm{height:.5rem}.sb-spacer--md{height:1rem}.sb-spacer--lg{height:2rem}.sb-spacer--xl{height:3rem}.sb-spacer--2xl{height:4.5rem}}'
            // The pin shows while the frame has not loaded, and in the editing canvas, whose policy blocks third-party frames.
            . '.sb-map{position:relative;margin:0 0 1rem;border-radius:var(--sb-radius-md);overflow:hidden;background:var(--sb-border-default)}'
            . '.sb-map::before{content:"";position:absolute;left:50%;top:50%;width:1.25rem;height:1.25rem;margin:-.9rem 0 0 -.625rem;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:var(--sb-color-accent)}'
            . '.sb-map iframe{position:relative;z-index:1;display:block;width:100%;height:100%;border:0}'
            . '.sb-map--sm{height:15.5rem}.sb-map--md{height:25rem}.sb-map--lg{height:34rem}'
            . '@media (max-width:767.98px){.sb-map--md{height:20rem}.sb-map--lg{height:26rem}}'
            . '.sb-nav{margin:0 0 var(--sb-type-flow,1rem)}.sb-nav__list{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.25rem 1.5rem}'
            . '.sb-nav--vertical .sb-nav__list{flex-direction:column;gap:.25rem}'
            . '.sb-nav--center .sb-nav__list{justify-content:center}.sb-nav--right .sb-nav__list{justify-content:flex-end}'
            . '.sb-nav--vertical.sb-nav--center{text-align:center}.sb-nav--vertical.sb-nav--right{text-align:right}'
            // `.sb-body a:not(.sb-button)` colours every link in the accent; a menu link takes the surrounding text colour, so it must outrank that rule.
            . '.sb-nav__link{text-decoration:none;display:inline-block;padding:.35rem 0}'
            // Links that change colour on hover fade rather than snap; :where() leaves an author's own transition in charge.
            . ':where(.sb-nav__link,.sb-post-card__title,.sb-post-card__title a,.sb-shop-card__title,.sb-shop-card__title a,.sb-shop-card__link){transition:color .2s ease,background-color .2s ease}'
            . '.sb-body .sb-nav a.sb-nav__link{color:inherit}.sb-body .sb-nav a.sb-nav__link:hover{color:var(--sb-color-accent)}'
            . '.sb-nav--underline .sb-nav__link{text-decoration:underline;text-underline-offset:.25em}'
            . '.sb-nav--pill .sb-nav__link{padding:.35rem .9rem;border-radius:999px}.sb-nav--pill .sb-nav__link:hover{background:var(--sb-border-default)}'
            // Submenus (NavMenuRenderer). The arrow (`.sb-nav__caret`) sits in the link or the parent button, by the label.
            // Horizontal: a card that drops down on hover or keyboard focus (CSS alone, so the canvas shows it), its own
            // submenus opening to the side; `data-open` is the runtime's toggle, `data-closed` its Escape. The arrow button
            // only shows for keyboard focus there. Vertical (a drawer): indented, folded away by the runtime once it runs
            // (so without scripts every link shows), opened by the arrow button at the end of the row.
            . '.sb-nav__item{position:relative}.sb-nav__item--parent{display:flex;align-items:center;flex-wrap:wrap}'
            . '.sb-nav__toggle,.sb-nav__parent{font:inherit;color:inherit;background:none;border:0;margin:0;cursor:pointer;text-transform:inherit;letter-spacing:inherit}'
            . '.sb-nav__parent{display:inline-flex;align-items:center;padding:.35rem 0;text-align:inherit}'
            . '.sb-nav__caret{display:inline-block;flex:none;width:0;height:0;margin-left:.45em;border-style:solid;border-width:.3em .28em 0;border-color:currentColor transparent transparent;transition:transform .2s}'
            . '.sb-nav__toggle{display:inline-flex;align-items:center;justify-content:center;padding:.35rem .5rem;line-height:1}.sb-nav__toggle::before{content:"";border-style:solid;border-width:.3em .28em 0;border-color:currentColor transparent transparent;transition:transform .2s}'
            . '.sb-nav__sub{list-style:none;margin:0;padding:0}'
            . '.sb-nav--horizontal .sb-nav__toggle:not(:focus-visible){position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
            . '.sb-nav--horizontal .sb-nav__sub{position:absolute;top:100%;left:0;z-index:60;min-width:15rem;padding:.5rem;background:var(--sb-surface-primary,#fff);color:var(--sb-text-primary,#1a1a1a);border:1px solid rgba(0,0,0,.06);border-radius:.85rem;box-shadow:0 18px 40px -12px rgba(0,0,0,.22),0 2px 8px rgba(0,0,0,.06);text-align:left;font-size:.95rem;line-height:1.4;text-transform:none;letter-spacing:normal;opacity:0;visibility:hidden;transform:translateY(.5rem);transition:opacity .18s,transform .18s,visibility .18s}'
            . '.sb-nav--horizontal .sb-nav__sub::before{content:"";position:absolute;left:0;right:0;top:-.75rem;height:.75rem}'
            . '.sb-nav--horizontal .sb-nav__item--parent:is(:hover,:focus-within,[data-open]):not([data-closed])>.sb-nav__sub{opacity:1;visibility:visible;transform:none}'
            . '.sb-nav--horizontal .sb-nav__list>.sb-nav__item--parent:is(:hover,:focus-within,[data-open]):not([data-closed])>:is(.sb-nav__link,.sb-nav__parent) .sb-nav__caret{transform:rotate(180deg)}'
            . '.sb-nav--horizontal .sb-nav__sub .sb-nav__sub{top:-.55rem;left:calc(100% + .5rem)}.sb-nav--horizontal .sb-nav__sub .sb-nav__sub::before{top:0;bottom:0;left:-.75rem;right:auto;width:.75rem;height:auto}'
            . '.sb-nav--right .sb-nav__list>.sb-nav__item>.sb-nav__sub,.sb-nav__list>.sb-nav__item[data-flip]>.sb-nav__sub{left:auto;right:0}'
            . '.sb-nav--horizontal .sb-nav__sub .sb-nav__item[data-flip]>.sb-nav__sub{left:auto;right:calc(100% + .5rem)}.sb-nav--horizontal .sb-nav__sub .sb-nav__item[data-flip]>.sb-nav__sub::before{left:auto;right:-.75rem}'
            . '.sb-nav--horizontal .sb-nav__sub .sb-nav__item{display:block}'
            . '.sb-nav--horizontal .sb-nav__sub .sb-nav__link{display:flex;align-items:center;justify-content:space-between;width:100%;padding:.6rem .9rem;border-radius:.55rem;white-space:nowrap;transition:background-color .15s,color .15s}'
            . '.sb-nav--horizontal .sb-nav__sub .sb-nav__link:hover,.sb-nav--horizontal .sb-nav__sub .sb-nav__item--parent:is(:hover,:focus-within)>.sb-nav__link{background:color-mix(in srgb,var(--sb-color-accent) 9%,transparent);color:var(--sb-color-accent)}'
            . '.sb-nav--horizontal .sb-nav__sub .sb-nav__caret{margin-left:1.25rem;transform:rotate(-90deg)}'
            . '.sb-nav--pill .sb-nav__sub .sb-nav__link{border-radius:.55rem}'
            . '.sb-nav--vertical .sb-nav__item--parent>:is(.sb-nav__link,.sb-nav__parent){flex:1}.sb-nav--vertical .sb-nav__item--parent>.sb-nav__link .sb-nav__caret{display:none}'
            . '.sb-nav--vertical .sb-nav__toggle{align-self:stretch;padding:0 .6rem}'
            . '.sb-nav--vertical .sb-nav__sub{flex-basis:100%;padding:0 0 .35rem 1rem}.sb-nav--vertical .sb-nav__sub .sb-nav__link{display:block;padding:.45rem 0}'
            . '.sb-nav--vertical.sb-nav--center .sb-nav__item--parent{justify-content:center}.sb-nav--vertical.sb-nav--center .sb-nav__sub{padding-left:0}'
            . '.sb-nav--vertical.sb-nav--right .sb-nav__item--parent{justify-content:flex-end}.sb-nav--vertical.sb-nav--right .sb-nav__sub{padding:0 1rem .35rem 0}'
            . 'html[data-sb-ready] .sb-nav--vertical .sb-nav__item--parent:not([data-open])>.sb-nav__sub{display:none}'
            . '.sb-nav--vertical .sb-nav__item--parent[data-open]>.sb-nav__parent>.sb-nav__caret,.sb-nav--vertical .sb-nav__item--parent[data-open]>.sb-nav__toggle::before{transform:rotate(180deg)}'
            . self::navWidgetCss()
            . '.sb-link-row{margin:0 0 var(--sb-type-flow,1rem)}.sb-link-row--center{text-align:center}.sb-link-row--right{text-align:right}'
            // In a row (a horizontal flex or a grid) the gap spaces the items; a link's, button's, text's, menu's or
            // icon's own margin would push it off the row's centre line (a header's button sat lower than its menu).
            . '.sb-flex--row>.sb-block>:is(.sb-link-row,.sb-button-row,.sb-pay,.sb-text,.sb-nav,.sb-icon),.sb-grid>.sb-block>:is(.sb-link-row,.sb-button-row,.sb-pay,.sb-text,.sb-nav,.sb-icon){margin:0}'
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
            . '.sb-stack{display:flex}.sb-stack--vertical{flex-direction:column}.sb-stack--horizontal{flex-direction:row;flex-wrap:wrap}.sb-stack--horizontal.sb-stack--nowrap{flex-wrap:nowrap}'
            . '.sb-embed-card__kind{margin:0 0 .25rem;font-size:.75rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--sb-text-muted)}'
            . '.sb-embed-card__fields{margin:.75rem 0 0;padding-left:1.25rem;color:var(--sb-text-muted)}'
            . '.sb-embed-card__note{margin:.75rem 0 0;font-size:.8125rem;color:var(--sb-text-muted)}'
            . '.sb-embed-frame{width:100%;border:0;display:block}'
            . '.sb-pay{margin:var(--sb-type-flow,1rem) 0 0}.sb-pay__button{cursor:pointer;border:0;font:inherit}'
            . '.sb-pay--amount{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.75rem}.sb-pay__amount{display:flex;flex-direction:column;gap:.35rem;font-size:.875rem;font-weight:600}'
            . '.sb-pay__amount-input{width:9rem;min-height:2.75rem;padding:.5rem .75rem;border:1px solid var(--sb-border-default);border-radius:var(--sb-radius-md);font:inherit;font-size:1rem;background:var(--sb-surface-primary);color:inherit}'
            . '.sb-pay-card__button{margin:.5rem 0}.sb-pay-card__summary{margin:0;font-weight:600;color:var(--sb-text-primary)}'
            // The Forms stylesheet reads its colours from variables it defines only on its own standalone page (`.forms-public`).
            // Embedded in a Studio page there is no such ancestor, so the submit button lost its background; map them to the site tokens.
            . '.sb-block--forms-embed{--f-accent:var(--sb-color-accent);--f-accent-d:color-mix(in srgb,var(--sb-color-accent) 85%,#000);--f-ink:var(--sb-text-primary);--f-muted:var(--sb-text-muted);--f-line:#e4e7ec;--f-line-2:#eef1f5;--f-surface:#fff;--f-soft:#f7f8fa;--f-radius:14px;--f-radius-s:11px;--f-ring:rgba(17,17,17,.14)}'
            . '.sb-empty{color:var(--sb-text-muted)}'
            . '.sb-unavailable{padding:.75rem 1rem;border:1px dashed #b45309;color:#92400e;background:#fffbeb;border-radius:6px;font-size:.875rem}'
            . '.sb-preview-banner{position:sticky;top:0;z-index:10;padding:.5rem 1rem;background:#1e293b;color:#fff;font:600 .8rem/1.4 system-ui,sans-serif;text-align:center}'
            . '.sb-platform-signature{padding:.75rem 1rem;text-align:center;font-size:.75rem;color:#6b7280}'
            . '.sb-platform-signature img{height:1rem;width:auto;vertical-align:middle}'
            . '.sb-layout-section{position:relative;width:100%}'
            . '.sb-layout-section--boxed{max-width:var(--sb-layout-wide,80rem);margin-left:auto;margin-right:auto}'
            . '.sb-layout-section--full{width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}'
            . '.sb-layout-section--narrow{max-width:var(--sb-layout-narrow,42rem);margin-left:auto;margin-right:auto}'
            . '.sb-layout-section--min-screen{min-height:100vh}'
            . '.sb-layout-section--min-half-screen{min-height:50vh}'
            . '.sb-layout-section--min-sm{min-height:16rem}'
            . '.sb-layout-section--min-md{min-height:24rem}'
            . '.sb-layout-section--min-lg{min-height:36rem}'
            . '.sb-layout-section--min-xl{min-height:48rem}'
            . '.sb-container{width:100%;margin-left:auto;margin-right:auto}'
            . '.sb-container--compact{max-width:var(--sb-layout-narrow,42rem)}'
            . '.sb-container--constrained{max-width:var(--sb-layout-wide,80rem)}'
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
            . '.sb-text{margin:0 0 var(--sb-type-flow,1rem);line-height:var(--sb-type-body_leading,1.6)}'
            . '.sb-text--xs{font-size:.75rem}'
            . '.sb-text--sm{font-size:.875rem}'
            . '.sb-text--base{font-size:var(--sb-type-body_size,1rem)}'
            // "Same as around it": the text takes the size and line height of the block it sits in.
            . '.sb-text--inherit{font-size:inherit;line-height:inherit}'
            . '.sb-text--lg{font-size:1.125rem}'
            . '.sb-text--xl{font-size:1.25rem}'
            . ':where(.sb-hl){color:var(--sb-color-accent)}'
            . '.sb-hl--italic{font-style:italic}.sb-hl--underline{text-decoration:underline;text-underline-offset:.15em}'
            . '.sb-hl--w-normal{font-weight:400}.sb-hl--w-medium{font-weight:500}.sb-hl--w-bold{font-weight:700}'
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
            . self::typographyInheritCss()
            . '.sb-uppercase{text-transform:uppercase}.sb-lowercase{text-transform:lowercase}.sb-capitalize{text-transform:capitalize}.sb-normal-case{text-transform:none}'
            . '.sb-leading-tight{line-height:1.25}.sb-leading-snug{line-height:1.375}.sb-leading-normal{line-height:1.5}.sb-leading-relaxed{line-height:1.625}.sb-leading-loose{line-height:2}'
            . '.sb-tracking-tighter{letter-spacing:-0.05em}.sb-tracking-tight{letter-spacing:-0.025em}.sb-tracking-normal{letter-spacing:0}.sb-tracking-wide{letter-spacing:0.025em}.sb-tracking-wider{letter-spacing:0.05em}.sb-tracking-widest{letter-spacing:0.1em}'
            . '.sb-radius-none{border-radius:0}.sb-radius-sm{border-radius:var(--sb-radius-sm,.25rem)}.sb-radius-md{border-radius:var(--sb-radius-md,.5rem)}.sb-radius-lg{border-radius:var(--sb-radius-lg,1rem)}.sb-radius-xl{border-radius:1.5rem}.sb-radius-2xl{border-radius:2rem}.sb-radius-full{border-radius:var(--sb-radius-full,9999px)}'
            . '.sb-shadow-none{box-shadow:none}.sb-shadow-sm{box-shadow:var(--sb-shadow-sm,0 1px 2px 0 rgba(0,0,0,0.05))}.sb-shadow-md{box-shadow:var(--sb-shadow-md,0 4px 6px -1px rgba(0,0,0,0.1),0 2px 4px -2px rgba(0,0,0,0.1))}.sb-shadow-lg{box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1)}.sb-shadow-xl{box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1)}.sb-shadow-2xl{box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)}.sb-shadow-inner{box-shadow:inset 0 2px 4px 0 rgba(0,0,0,0.05)}'
            . '.sb-border{border:1px solid var(--sb-border-default,#e2e8f0)}.sb-border-2{border-width:2px}.sb-border-4{border-width:4px}.sb-border-none{border:none}.sb-border-solid{border-style:solid}.sb-border-dashed{border-style:dashed}.sb-border-dotted{border-style:dotted}.sb-border-double{border-style:double}.sb-border-groove{border-style:groove}'
            . '.sb-m-auto{margin:auto}.sb-mx-auto{margin-left:auto;margin-right:auto}.sb-my-auto{margin-top:auto;margin-bottom:auto}.sb-m-none{margin:0}.sb-my-none{margin-top:0;margin-bottom:0}'
            . '.sb-query-loop{width:100%}'
            . '@media (max-width:767.98px){.sb-query-loop .sb-query-loop__grid{grid-template-columns:minmax(0,1fr)}}'
            . '.sb-post-card{display:flex;flex-direction:column;border:1px solid var(--sb-border-default,#e2e8f0);border-radius:var(--sb-radius-md,.5rem);overflow:hidden;background:var(--sb-surface-secondary,#ffffff);transition:box-shadow .2s ease}'
            . '.sb-post-card:hover{box-shadow:var(--sb-shadow-md,0 4px 6px -1px rgba(0,0,0,0.1))}'
            // A card may leave overflow open (the hover margin of a lifted card needs it), so the image rounds itself.
            . '.sb-post-card__media{overflow:hidden;border-top-left-radius:inherit;border-top-right-radius:inherit}'
            . '.sb-post-card__media img{width:100%;height:12rem;object-fit:cover;display:block}'
            . '.sb-post-card__body{padding:1.25rem;display:flex;flex-direction:column;flex:1}'
            . '.sb-post-card__category{display:inline-block;font-size:.75rem;font-weight:600;text-transform:uppercase;color:var(--sb-color-accent,#6366f1);margin-bottom:.5rem}'
            . '.sb-post-card__title{margin:0 0 .5rem;font-size:1.25rem;line-height:1.4}'
            . '.sb-post-card__title a{color:inherit;text-decoration:none}'
            . '.sb-post-card__title a:hover{color:var(--sb-color-accent,#6366f1)}'
            . '.sb-post-card__excerpt{color:var(--sb-text-muted,#57534e);font-size:.875rem;line-height:1.5;margin:0 0 1rem;flex:1}'
            . '.sb-post-card__meta{display:flex;align-items:center;gap:.75rem;font-size:.75rem;color:var(--sb-text-muted,#94a3b8);margin-bottom:1rem}'
            . '.sb-pagination{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:2rem}'
            . '.sb-pagination__page,.sb-pagination__prev,.sb-pagination__next{display:inline-flex;align-items:center;justify-content:center;padding:.5rem .875rem;border-radius:var(--sb-radius-md,.5rem);border:1px solid var(--sb-border-default,#e2e8f0);font-size:.875rem;font-weight:500;text-decoration:none;color:inherit}'
            . '.sb-pagination__current{background:var(--sb-surface-accent,#6366f1);color:#fff;border-color:var(--sb-surface-accent,#6366f1)}'
            . '.sb-pagination__disabled{opacity:.5;pointer-events:none}'
            . '.sb-post-title{margin:0 0 1rem;font-size:2.25rem;line-height:1.25;font-weight:700}'
            . '.sb-post-title--align-center{text-align:center}.sb-post-title--align-right{text-align:right}'
            . '.sb-post-content{font-size:1.125rem;line-height:1.75;color:var(--sb-text-primary,#111111);margin-bottom:2rem}'
            . '.sb-post-meta{display:flex;align-items:center;gap:.75rem;font-size:.875rem;color:var(--sb-text-muted,#64748b);margin-bottom:1.5rem}'
            . '.sb-archive-title{font-size:2rem;line-height:1.3;font-weight:700;margin:0 0 1.5rem}'
            . '.sb-search-form{width:100%;max-width:36rem;margin:0 auto 2rem}'
            . '.sb-search-input-wrap{display:flex;align-items:center;border:1px solid var(--sb-border-default,#cbd5e1);border-radius:var(--sb-radius-md,.5rem);overflow:hidden;background:#fff}'
            . '.sb-search-input{flex:1;border:none;padding:.75rem 1rem;font-size:1rem;outline:none}'
            . '.sb-search-button{font-style:inherit;background:var(--sb-surface-accent,#6366f1);color:#fff;border:none;padding:.75rem 1.5rem;font-weight:600;cursor:pointer}'
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
            . '@keyframes sb-fade-up{from{opacity:0;translate:0 24px}to{opacity:1;translate:0 0}}'
            . '@keyframes sb-fade-down{from{opacity:0;translate:0 -24px}to{opacity:1;translate:0 0}}'
            . '@keyframes sb-scale-up{from{opacity:0;scale:.92}to{opacity:1;scale:1}}'
            . '@keyframes sb-slide-in{from{translate:-100% 0}to{translate:0 0}}'
            . '@keyframes sb-move-left{from{opacity:0;translate:48px 0}to{opacity:1;translate:0 0}}'
            . '@keyframes sb-move-right{from{opacity:0;translate:-48px 0}to{opacity:1;translate:0 0}}'
            . '@keyframes sb-reveal-left{from{clip-path:inset(0 100% 0 0)}to{clip-path:inset(0 0 0 0)}}'
            . '@keyframes sb-reveal-up{from{clip-path:inset(100% 0 0 0)}to{clip-path:inset(0 0 0 0)}}'
            . '.sb-interaction-hover{transition:transform .25s ease,box-shadow .25s ease}'
            . '@media ' . StyleSurface::HOVER_MOTION . '{.sb-interaction-hover:hover{transform:translateY(-3px) scale(1.02)}}'
            // Keyboard parity: a hover-only lift leaves the block unreachable
            // for keyboard users, so :focus-visible gets the same affordance.
            . '.sb-interaction-focus{transition:transform .25s ease,box-shadow .25s ease,outline-color .25s ease}'
            . '.sb-interaction-focus:focus-visible{transform:translateY(-3px) scale(1.02);outline:2px solid currentColor;outline-offset:3px}'
            // Click: the block's chosen animation plays on each click (studio-runtime.js sets data-sb-play),
            // never on load; a block with no animation presses in while held.
            . '.sb-interaction-click{transition:transform .15s ease}'
            . '.sb-interaction-click:not([class*="sb-animate-"]):active{transform:scale(.97)}'
            . '.sb-interaction-click[class*="sb-animate-"]:not([data-sb-play]){animation:none}'
            // Load: the chosen animation plays once as the page loads (its .sb-animate-* rule); with none, the block fades in.
            . '.sb-interaction-load:not([class*="sb-animate-"]){animation:sb-fade-in var(--sb-anim-duration,.5s) var(--sb-anim-easing,ease) var(--sb-anim-delay,0s) both}'
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
            // Scroll: tied to the scroll position for as long as the block crosses the screen. With no
            // animation the block drifts gently against the scroll; with one, the animation follows the
            // scroll. Browsers without scroll timelines get the drift from studio-runtime.js instead.
            . '@keyframes sb-scroll-drift{from{translate:0 32px}to{translate:0 -32px}}'
            . '@supports (animation-timeline:view()){'
            . '.sb-interaction-scroll{animation-timeline:view();animation-range:cover 0% cover 100%;animation-fill-mode:both}'
            . '.sb-interaction-scroll:not([class*="sb-animate-"]){animation:sb-scroll-drift linear both;animation-timeline:view();animation-range:cover 0% cover 100%}'
            . '.sb-interaction-scroll[class*="sb-animate-"]{animation-range:entry 0% cover 50%}'
            . '}'
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
            // Tab position "bottom": the strip sits under the panels, and its rule and indicator flip to the top edge.
            . '.sb-tabs-block--bottom{display:flex;flex-direction:column-reverse}'
            . '.sb-tabs-block--bottom>.sb-tabs{border-bottom:0;border-top:1px solid var(--sb-border-default,#e2e8f0);margin:1.25rem 0 0}'
            . '.sb-tabs-block--bottom .sb-tabs__tab{border-bottom:0;border-top:2px solid transparent}'
            . '.sb-tabs-block--bottom .sb-tabs__tab[aria-selected="true"]{border-top-color:currentColor}'
            . '.sb-accordion{border:1px solid var(--sb-border-default,#e2e8f0);border-radius:.5rem;overflow:hidden}'
            . '.sb-accordion__item+.sb-accordion__item{border-top:1px solid var(--sb-border-default,#e2e8f0)}'
            . '.sb-accordion__button{width:100%;display:flex;justify-content:space-between;align-items:center;gap:1rem;background:none;border:0;padding:1rem 1.25rem;font:inherit;color:inherit;text-align:inherit;cursor:pointer}'
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
            // Slides are <figure>s and quotes <blockquote>s: the browser's own side margins (40px) would push
            // each slide off its place, so they are zeroed. With several per view, each takes its share less the gaps.
            . '.sb-carousel__slide{flex:0 0 100%;min-width:0;margin:0}'
            . '[data-sb-per-view="2"]>.sb-carousel__track>.sb-carousel__slide{flex-basis:calc((100% - 1rem)/2)}'
            . '[data-sb-per-view="3"]>.sb-carousel__track>.sb-carousel__slide{flex-basis:calc((100% - 2rem)/3)}'
            . '.sb-carousel__image{display:block;width:100%;height:auto}'
            . '.sb-carousel__quote{margin:0}'
            . '.sb-carousel__author{display:block;margin-top:.75rem}'
            . '.sb-carousel__caption{margin-top:.5rem}'
            . '.sb-carousel__nav{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:1rem}'
            . '.sb-carousel__dots{display:flex;align-items:center;gap:.5rem}'
            . '.sb-carousel__btn{width:2.25rem;height:2.25rem;border-radius:50%;border:1px solid var(--sb-border-default,#e2e8f0);background:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:inherit}'
            . '.sb-carousel__btn:disabled{opacity:.35;cursor:default}'
            . '.sb-carousel__btn:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
            // The pause control only does something with the runtime running, which marks the carousel ready.
            . '.sb-carousel__pause{display:none;font-size:.7rem}[data-sb-car-ready] .sb-carousel__pause{display:inline-flex}'
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
            . '.sb-offcanvas--top .sb-offcanvas__panel,.sb-offcanvas--bottom .sb-offcanvas__panel{left:0;right:0;width:auto;max-width:none;max-height:80vh}'
            . '.sb-offcanvas--top .sb-offcanvas__panel{bottom:auto;box-shadow:0 4px 16px rgba(0,0,0,.15)}'
            . '.sb-offcanvas--bottom .sb-offcanvas__panel{top:auto;box-shadow:0 -4px 16px rgba(0,0,0,.15)}'
            . '.sb-offcanvas__header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb}'
            . '.sb-offcanvas__close{background:none;border:none;font-size:1.5rem;text-decoration:none;cursor:pointer;line-height:1;color:#6b7280}'
            . '.sb-offcanvas__body{padding:1.25rem;flex:1;overflow-y:auto}'
            . '.sb-offcanvas__header--bare{justify-content:flex-end;border-bottom:0;padding-bottom:0}'
            // The drawer trigger: an icon (a hamburger) with or without its text; `plain` drops the button chrome.
            . '.sb-offcanvas-trigger--icon{display:inline-flex;align-items:center;gap:.5rem;line-height:1}'
            . '.sb-offcanvas-trigger__icon{flex:none;display:block}'
            . '.sb-button.sb-button--plain{background:none;border:0;padding:0;border-radius:0;color:inherit}'
            . '.sb-form{display:flex;flex-flow:row wrap;gap:1rem}.sb-form>*{flex:0 0 100%;min-width:0}'
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
            . '.sb-video--aspect-16-9{aspect-ratio:16/9}.sb-video--aspect-4-3{aspect-ratio:4/3}.sb-video--aspect-1-1{aspect-ratio:1/1}.sb-video--aspect-9-16{aspect-ratio:9/16}'
            . '.sb-video-wrapper iframe,.sb-video-wrapper video{width:100%;height:100%;border:0}'
            . '.sb-video-wrapper video{display:block;background:#000}'
            // The image overlay: a poster button that the runtime swaps for the player (or opens in a lightbox).
            . '.sb-video__overlay{position:absolute;inset:0;display:block;width:100%;height:100%;margin:0;padding:0;border:0;background:#000;cursor:pointer;color:#fff}'
            . '.sb-video__overlay img{display:block;width:100%;height:100%;object-fit:cover}'
            // Centred by inset + auto margins, not a transform, so a Play icon part may scale or resize it and stay centred;
            // the hover look is :where() so the part's own colours (and its hover) win.
            . '.sb-video__play{position:absolute;inset:0;margin:auto;display:flex;align-items:center;justify-content:center;width:4.5rem;height:4.5rem;border-radius:50%;background:rgba(0,0,0,.55);transition:background-color .2s,transform .2s}'
            . '.sb-video__play svg{width:2rem;height:2rem;margin-left:.2rem}'
            . ':where(.sb-video__overlay:hover,.sb-video__overlay:focus-visible) .sb-video__play{background:rgba(0,0,0,.8)}'
            . '@media ' . StyleSurface::HOVER_MOTION . '{:where(.sb-video__overlay:hover,.sb-video__overlay:focus-visible) .sb-video__play{transform:scale(1.06)}}'
            . '.sb-video__overlay:focus-visible{outline:3px solid #fff;outline-offset:-3px}'
            // Audio ── a card with its own play button; the provider's player opens under it on click.
            . '.sb-audio{display:flex;flex-direction:column;gap:.75rem;padding:1.5rem;background:var(--sb-surface-accent);color:var(--sb-text-inverse);border-radius:var(--sb-radius-md)}'
            . '.sb-audio--empty{background:var(--sb-surface-secondary);color:var(--sb-text-primary)}'
            . '.sb-audio__head{display:flex;align-items:center;justify-content:space-between;gap:1rem}.sb-audio__text{min-width:0}'
            . '.sb-audio__title{margin:0;font-size:1.5rem;font-weight:600;line-height:1.25;color:inherit}.sb-audio__subtitle{margin:.25rem 0 0;color:inherit;opacity:.9}'
            . '.sb-audio__play{flex:none;display:flex;align-items:center;justify-content:center;width:3.5rem;height:3.5rem;padding:0;border:0;border-radius:50%;background:#fff;color:var(--sb-color-accent);cursor:pointer;transition:transform .2s ease,box-shadow .2s ease}'
            . '.sb-audio__play[hidden]{display:none}'
            . '.sb-audio__play:focus-visible{outline:3px solid #fff;outline-offset:3px}'
            . '@media ' . StyleSurface::HOVER_MOTION . '{.sb-audio__play:hover{transform:scale(1.06)}}'
            . '.sb-audio__icon-pause{display:none}.sb-audio__icon-play,.sb-audio__icon-pause{line-height:0}'
            . '.sb-audio[data-sb-audio-playing] .sb-audio__icon-pause{display:block}.sb-audio[data-sb-audio-playing] .sb-audio__icon-play{display:none}'
            . '.sb-audio__bar{display:flex;align-items:center;gap:1rem}.sb-audio__seek{flex:1;min-width:0;margin:0;accent-color:currentColor}.sb-audio__time{flex:none;font-variant-numeric:tabular-nums;font-size:.875rem}'
            . '.sb-audio__embed{display:block;width:100%;border:0;border-radius:.5rem}'
            . '.sb-body a.sb-audio__link{align-self:flex-start;color:inherit;font-size:.875rem;text-decoration:underline;text-underline-offset:.2em}'
            // Marquee ── two identical groups, moved by half the track, so the loop has no seam.
            . '.sb-marquee{overflow:hidden;max-width:100%}'
            . '.sb-marquee__track{display:flex;width:max-content;direction:ltr;animation:sb-marquee 30s linear infinite}'
            . '.sb-marquee--right .sb-marquee__track{animation-direction:reverse}.sb-marquee--pause:hover .sb-marquee__track{animation-play-state:paused}'
            . '.sb-marquee__group{display:flex;flex:none}.sb-marquee__text{flex:none;padding-inline-end:1em;white-space:nowrap}'
            . '@keyframes sb-marquee{to{translate:-50%}}'
            . '@media (prefers-reduced-motion:reduce){.sb-marquee__track{width:auto;justify-content:center;animation:none}.sb-marquee [aria-hidden="true"]{display:none}.sb-marquee__group{flex:0 1 auto;min-width:0}.sb-marquee__text{flex:0 1 auto;padding-inline-end:0;white-space:normal;text-align:center}}'
            . '.sb-video-lightbox__frame{width:min(92vw,151vh)}.sb-video-lightbox__frame.sb-video--aspect-4-3{width:min(92vw,113vh)}'
            . '.sb-video-lightbox__frame.sb-video--aspect-1-1{width:min(92vw,85vh)}.sb-video-lightbox__frame.sb-video--aspect-9-16{width:min(92vw,48vh)}';

        foreach (self::WIDTHS as $name => $token) {
            // The widths follow the site's Layout tokens (Site settings): `default` is its content width; `full` is no limit.
            $limit = $token === null ? 'none' : 'var(--sb-layout-' . $token[0] . ',' . $token[1] . ')';
            $css .= '.sb-w-' . $name . '{max-width:' . $limit . ';margin-left:auto;margin-right:auto}';
        }
        // A custom width (DocumentRenderer::customWidthVars): the phone's size, then each wider device's own (responsive()).
        $css .= '.sb-w-custom{max-width:var(--sb-cw,none);margin-left:auto;margin-right:auto}';
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
        $css .= self::scrollEffectsCss();
        $css .= self::deviceCountsCss();
        $css .= self::formCss();
        $css .= self::shopCss();
        // Per-device alignment, desktop first: the desktop value is the base, a tablet value
        // overrides it at tablet width and below, a mobile value overrides both on a phone.
        $css .= self::alignRules('desktop');
        $css .= '@media (max-width:1023.98px){' . self::alignRules('tablet') . '}';
        $css .= '@media (max-width:767.98px){' . self::alignRules('mobile') . '}';

        return self::$cache = $css;
    }

    private static function alignRules(string $device): string
    {
        $css = '';
        foreach (CanonicalDocumentSchema::ALLOWED_ALIGNMENTS as $align) {
            $css .= '.sb-align-' . $device . '-' . $align . '{text-align:' . $align . '}';
        }
        return $css;
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
        if ($prefix !== '') {
            $css .= '.sb-w-custom.sb-' . $prefix . 'cw{max-width:var(--sb-cw-' . rtrim($prefix, '-') . ')}';
        }
        return $css;
    }

    /** Breakpoint prefix for a responsive utility (`base` has none). */
    /**
     * `sb-ty-*` marks (see DocumentRenderer::typographyMarkers) make a block's own text elements inherit the typography
     * that was set on its wrapper. The selectors reach three levels down but never through a nested block, so a container's
     * font size does not rewrite the headings of the blocks inside it. `!important` because the rules it must beat are theme
     * and element rules of any specificity.
     */
    /** Form controls, table cells, lists and the theme blocks' own wrappers carry browser or stylesheet values of their own, so they are listed too. */
    private const OWN_TEXT = 'ul,ol,dl,dt,dd,label,button,input,select,textarea,th,td,caption,summary,small,cite,time,.sb-post-content,.sb-post-meta,.sb-empty';

    /** The text elements a text colour reaches. It must not repaint the links inside a paragraph; a button's label and a link block's link are the links that follow it. */
    public const COLOR_TEXT = ':is(h1,h2,h3,h4,h5,h6,p,blockquote,figcaption,span,li,a.sb-button,a.sb-link,' . self::OWN_TEXT . ')';

    private static function typographyInheritCss(): string
    {
        $text = ':is(h1,h2,h3,h4,h5,h6,p,blockquote,figcaption,a,span,li,' . self::OWN_TEXT . ')';
        $colorText = self::COLOR_TEXT;
        $props = ['size' => 'font-size', 'color' => 'color', 'lh' => 'line-height', 'ls' => 'letter-spacing', 'ff' => 'font-family', 'fw' => 'font-weight', 'tt' => 'text-transform'];
        $css = '';
        foreach ($props as $mark => $prop) {
            $root = '.sb-ty-' . $mark;
            $sel  = $mark === 'color' ? $colorText : $text;
            $css .= $root . '>' . $sel . ',' . $root . '>:not(.sb-block)>' . $sel . ',' . $root . '>:not(.sb-block)>:not(.sb-block)>' . $sel
                . '{' . $prop . ':inherit !important}';
        }
        // The accordion's header button sits deeper than the three levels above reach, and an accordion holds no nested blocks.
        foreach ($props as $mark => $prop) {
            $css .= '.sb-ty-' . $mark . ' :is(.sb-accordion__heading,.sb-accordion__button){' . $prop . ':inherit !important}';
        }
        return $css;
    }

    /**
     * The Nav menu's built-in menu button, hover pointers and divider (NavMenuRenderer).
     *
     * The menu is one list. Below its breakpoint (`.sb-nav--toggle-*`) the list folds away and `.sb-nav__bar` shows; the
     * open state is the bar's checkbox, so the same rules serve a page with and without the runtime. The folded rules are
     * written once per breakpoint, and they undo the row's dropdown cards: submenus sit in the panel, indented, as in a
     * vertical menu. A pointer is drawn by `::before`/`::after`, so it takes no room and never moves the row, and it shows on
     * hover (pointers that can hover only), keyboard focus and the current page.
     */
    private static function navWidgetCss(): string
    {
        $css = '.sb-nav{--sb-nav-pointer:var(--sb-color-accent)}'
            . '.sb-nav__bar{display:none;position:relative;z-index:1}.sb-nav--tgl-left .sb-nav__bar{justify-content:flex-start}.sb-nav--tgl-center .sb-nav__bar{justify-content:center}.sb-nav--tgl-right .sb-nav__bar{justify-content:flex-end}'
            . '.sb-nav__bar>span{position:relative;display:inline-flex}'
            . '.sb-nav__check{position:absolute;inset:0;z-index:1;width:100%;height:100%;margin:0;opacity:0;cursor:pointer}html[data-sb-ready] .sb-nav__check{display:none}'
            . '.sb-nav__burger{display:inline-flex;align-items:center;gap:.6rem;min-width:2.75rem;min-height:2.75rem;justify-content:center;padding:.35rem;font:inherit;color:inherit;background:none;border:0;border-radius:.4rem;cursor:pointer}'
            . '.sb-nav__icon,.sb-nav__icon::before,.sb-nav__icon::after{display:block;width:1.5rem;height:2px;border-radius:2px;background:currentColor;transition:transform .2s ease,background-color .2s ease}'
            . '.sb-nav__icon{position:relative}.sb-nav__icon::before,.sb-nav__icon::after{content:"";position:absolute;left:0}.sb-nav__icon::before{top:-.45rem}.sb-nav__icon::after{top:.45rem}'
            . '.sb-nav__icon--dots{width:.3rem;height:.3rem;margin:0 .6rem;border-radius:50%}.sb-nav__icon--dots::before,.sb-nav__icon--dots::after{width:.3rem;height:.3rem;border-radius:50%}.sb-nav__icon--dots::before{top:-.5rem}.sb-nav__icon--dots::after{top:.5rem}'
            . '.sb-nav__check:checked~.sb-nav__burger .sb-nav__icon{background:transparent}'
            . '.sb-nav__check:checked~.sb-nav__burger :is(.sb-nav__icon--hamburger,.sb-nav__icon--dots)::before,.sb-nav__check:checked~.sb-nav__burger :is(.sb-nav__icon--hamburger,.sb-nav__icon--dots)::after{top:0;left:-.6rem;width:1.5rem;height:2px;border-radius:2px;background:currentColor}'
            . '.sb-nav__check:checked~.sb-nav__burger .sb-nav__icon--hamburger::before,.sb-nav__check:checked~.sb-nav__burger .sb-nav__icon--hamburger::after{left:0}'
            . '.sb-nav__check:checked~.sb-nav__burger .sb-nav__icon::before{transform:rotate(45deg)}.sb-nav__check:checked~.sb-nav__burger .sb-nav__icon::after{transform:rotate(-45deg)}'
            . '.sb-nav__backdrop{display:none}'
            // Divider
            . '.sb-nav--divider.sb-nav--horizontal .sb-nav__list{column-gap:0}.sb-nav--divider.sb-nav--horizontal .sb-nav__list>.sb-nav__item{padding:0 .75rem}.sb-nav--divider.sb-nav--horizontal .sb-nav__list>.sb-nav__item+.sb-nav__item{border-left:1px solid var(--sb-border-default)}'
            . '.sb-nav--divider.sb-nav--vertical .sb-nav__list{row-gap:0}.sb-nav--divider.sb-nav--vertical .sb-nav__list>.sb-nav__item+.sb-nav__item{border-top:1px solid var(--sb-border-default)}';

        // Pointers
        $link = '.sb-nav__list>.sb-nav__item>:is(.sb-nav__link,.sb-nav__parent)';
        $lit = ':is(:focus-visible,[aria-current="page"])';
        $css .= ':is(.sb-nav--pointer-underline,.sb-nav--pointer-overline,.sb-nav--pointer-double_line,.sb-nav--pointer-framed,.sb-nav--pointer-background) ' . $link . '{position:relative}'
            . ':is(.sb-nav--pointer-framed,.sb-nav--pointer-background) ' . $link . '{padding-left:.75rem;padding-right:.75rem}';
        $shape = [
            'underline'   => ['after', 'left:0;right:0;bottom:0;height:2px;background:var(--sb-nav-pointer)'],
            'overline'    => ['before', 'left:0;right:0;top:0;height:2px;background:var(--sb-nav-pointer)'],
            'framed'      => ['after', 'inset:0;border:2px solid var(--sb-nav-pointer);border-radius:.3rem'],
            'background'  => ['after', 'inset:0;z-index:-1;background:color-mix(in srgb,var(--sb-nav-pointer) 16%,transparent);border-radius:.3rem'],
        ];
        foreach ($shape as $name => [$pseudo, $rule]) {
            $css .= '.sb-nav--pointer-' . $name . ' ' . $link . '::' . $pseudo . '{content:"";position:absolute;pointer-events:none;' . $rule . '}';
        }
        $css .= '.sb-nav--pointer-background ' . $link . '{isolation:isolate}'
            . '.sb-nav--pointer-double_line ' . $link . '::before,.sb-nav--pointer-double_line ' . $link . '::after{content:"";position:absolute;pointer-events:none;left:0;right:0;height:2px;background:var(--sb-nav-pointer)}'
            . '.sb-nav--pointer-double_line ' . $link . '::before{top:0}.sb-nav--pointer-double_line ' . $link . '::after{bottom:0}';
        $marks = static fn(string $state): string => implode(',', array_merge(
            ...array_map(static fn(string $pseudo): array => ['[class*="sb-nav--pointer-"] ' . $link . $state . '::' . $pseudo], ['before', 'after']),
        ));
        $css .= '[class*="sb-nav--pointer-"] ' . $link . '::before,[class*="sb-nav--pointer-"] ' . $link . '::after{opacity:0}'
            . '.sb-nav--anim-fade ' . $link . '::before,.sb-nav--anim-fade ' . $link . '::after{transition:opacity .2s ease}'
            . '.sb-nav--anim-slide ' . $link . '::before,.sb-nav--anim-slide ' . $link . '::after{transform:translateY(.35em);transition:opacity .2s ease,transform .2s ease}'
            . '.sb-nav--anim-grow ' . $link . '::before,.sb-nav--anim-grow ' . $link . '::after{transform:scaleX(0);transition:opacity .2s ease,transform .2s ease}'
            . ':is(.sb-nav--pointer-framed,.sb-nav--pointer-background).sb-nav--anim-grow ' . $link . '::after{transform:scale(.85)}'
            . $marks($lit) . '{opacity:1;transform:none}'
            . '@media (hover:hover){' . $marks(':hover') . '{opacity:1;transform:none}.sb-body .sb-nav--pointer-text a.sb-nav__link:hover{color:var(--sb-nav-pointer)}}'
            . '.sb-body .sb-nav--pointer-text a.sb-nav__link:is(:focus-visible,[aria-current="page"]),.sb-nav--pointer-text .sb-nav__parent:focus-visible{color:var(--sb-nav-pointer)}'
            . '@media (prefers-reduced-motion:reduce){[class*="sb-nav--anim-"] ' . $link . '::before,[class*="sb-nav--anim-"] ' . $link . '::after{transition:none;transform:none}.sb-nav__icon,.sb-nav__icon::before,.sb-nav__icon::after{transition:none}}';

        foreach (['always' => null, 'tablet' => '(max-width:1023.98px)', 'mobile' => '(max-width:767.98px)'] as $when => $query) {
            $rules = self::navFoldedCss('.sb-nav--toggle-' . $when);
            $css .= $query === null ? $rules : '@media ' . $query . '{' . $rules . '}';
        }
        return $css;
    }

    /** The rules of a menu folded behind its button; `$n` selects the menus this breakpoint folds. */
    private static function navFoldedCss(string $n): string
    {
        $open = $n . ' .sb-nav__bar:has(.sb-nav__check:checked)';
        $drop = $n . '.sb-nav--menu-dropdown .sb-nav__list';
        $side = $n . '.sb-nav--menu-offcanvas .sb-nav__list';
        return $n . '{position:relative}' . $n . ' .sb-nav__bar{display:flex}'
            . $n . ' .sb-nav__list{display:none;flex-direction:column;flex-wrap:nowrap;justify-content:flex-start;gap:0;background:var(--sb-surface-primary,#fff);color:var(--sb-text-primary,#1a1a1a);text-align:left}'
            . $open . '~.sb-nav__list{display:flex}'
            . $drop . '{position:absolute;top:var(--sb-nav-top,100%);z-index:60;min-width:16rem;padding:.5rem 1rem;box-shadow:0 18px 40px -12px rgba(0,0,0,.22)}'
            . $n . '.sb-nav--tgl-left.sb-nav--menu-dropdown .sb-nav__list{left:0}'
            . $n . '.sb-nav--tgl-right.sb-nav--menu-dropdown .sb-nav__list{right:0}'
            . $n . '.sb-nav--tgl-center.sb-nav--menu-dropdown .sb-nav__list{left:50%;transform:translateX(-50%)}'
            // The runtime measures the window (--sb-nav-left, --sb-nav-vw); without it the panel keeps the menu's own width.
            . $n . '.sb-nav--menu-full.sb-nav--menu-dropdown .sb-nav__list{left:var(--sb-nav-left);width:var(--sb-nav-vw,auto);box-sizing:border-box;transform:none}'
            . $side . '{position:fixed;top:0;bottom:0;right:0;z-index:10000;box-sizing:border-box;width:22rem;max-width:85vw;padding:4.5rem 1.25rem 1.5rem;overflow-y:auto;box-shadow:-4px 0 16px rgba(0,0,0,.15)}'
            . $n . '.sb-nav--tgl-left.sb-nav--menu-offcanvas .sb-nav__list{left:0;right:auto;box-shadow:4px 0 16px rgba(0,0,0,.15)}'
            . $n . '.sb-nav--menu-offcanvas .sb-nav__bar:has(.sb-nav__check:checked)~.sb-nav__backdrop{display:block;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5)}'
            . $n . '.sb-nav--menu-offcanvas .sb-nav__bar:has(.sb-nav__check:checked){z-index:10001}'
            . $n . '.sb-nav--menu-offcanvas .sb-nav__check:checked~.sb-nav__burger{background:var(--sb-surface-primary,#fff);color:var(--sb-text-primary,#1a1a1a)}'
            . $n . ' .sb-nav__list>.sb-nav__item{padding:0;border:0}' . $n . ' .sb-nav__list>.sb-nav__item+.sb-nav__item{border-top:1px solid var(--sb-border-default)}'
            . $n . ' .sb-nav__link,' . $n . ' .sb-nav__parent{display:block;padding:.65rem 0}'
            . $n . ' .sb-nav__list>.sb-nav__item>:is(.sb-nav__link,.sb-nav__parent)::before,' . $n . ' .sb-nav__list>.sb-nav__item>:is(.sb-nav__link,.sb-nav__parent)::after{display:none}'
            . $n . ' .sb-nav__item--parent>:is(.sb-nav__link,.sb-nav__parent){flex:1}' . $n . ' .sb-nav__item--parent>.sb-nav__parent{display:inline-flex;justify-content:space-between}' . $n . ' .sb-nav__item--parent>.sb-nav__link .sb-nav__caret{display:none}'
            . $n . ' .sb-nav__toggle:not(:focus-visible),' . $n . ' .sb-nav__toggle{position:static;width:auto;height:auto;align-self:stretch;padding:0 .75rem;overflow:visible;clip:auto;white-space:normal}'
            . $n . ' .sb-nav__sub{position:static;flex-basis:100%;min-width:0;padding:0 0 .35rem 1rem;background:none;border:0;box-shadow:none;opacity:1;visibility:visible;transform:none;transition:none}'
            . $n . ' .sb-nav__sub::before,' . $n . ' .sb-nav__sub .sb-nav__sub::before{display:none}'
            . $n . ' .sb-nav__sub .sb-nav__item{display:flex}'
            . $n . ' .sb-nav__sub .sb-nav__link{display:block;width:auto;padding:.45rem 0;white-space:normal}'
            . 'html[data-sb-ready] ' . $n . ' .sb-nav__item--parent:not([data-open])>.sb-nav__sub{display:none}'
            . $n . ' .sb-nav__item--parent[data-open]>.sb-nav__parent>.sb-nav__caret,' . $n . ' .sb-nav__item--parent[data-open]>.sb-nav__toggle::before{transform:rotate(180deg)}';
    }

    /**
     * Sticky and parallax (ScrollEffects). A sticky node is plain `position: sticky`, so it sticks without JavaScript;
     * studio-runtime.js adds `is-stuck` (for the shadow and the stuck background) and `is-hidden` (hide while scrolling
     * down). A header or footer holding a sticky section lets it stick to the page, not inside its own wrapper.
     * A parallax background is painted by `::after`, taller than the node by the room it moves in; the runtime moves it.
     */
    public static function scrollEffectsCss(): string
    {
        $css = '.sb-parallax-bg{position:relative;isolation:isolate;overflow:hidden}'
            . '.sb-parallax-bg::after{content:"";position:absolute;left:0;right:0;top:calc(var(--sb-pbg-room,20%)*-1);bottom:calc(var(--sb-pbg-room,20%)*-1);z-index:-2;pointer-events:none;border-radius:inherit;'
            . 'background-image:var(--sb-pbg-image,none);background-size:var(--sb-pbg-size,cover);background-repeat:var(--sb-pbg-repeat,no-repeat);background-position:var(--sb-pbg-position,center);'
            . 'transform:translate3d(0,var(--sb-parallax-y,0px),0)}'
            . '.sb-sticky{position:sticky;z-index:40;transition:box-shadow .2s ease,background-color .2s ease,transform .3s ease}'
            . '.sb-sticky--top{top:var(--sb-sticky-offset,0px)}.sb-sticky--bottom{bottom:var(--sb-sticky-offset,0px)}'
            . '.sb-sticky.is-stuck[data-sb-sticky-effect~="shadow"]{box-shadow:0 8px 24px -8px rgba(15,23,42,.25)}'
            . '.sb-sticky.is-stuck[data-sb-sticky-effect~="background"]{background-color:var(--sb-stuck-bg)!important}'
            . '.sb-sticky.is-hidden{transform:translate3d(0,calc(-100% - var(--sb-sticky-offset,0px)),0)}'
            // Shrink: the runtime's `is-shrunk` tightens the header's sections and logo once the page has scrolled a little.
            . '.sb-sticky[data-sb-sticky-effect~="shrink"] :is(.sb-section,.sb-section img){transition:padding .2s ease,max-height .2s ease}'
            . '.sb-sticky.is-shrunk .sb-section{padding-top:.35rem!important;padding-bottom:.35rem!important}.sb-sticky.is-shrunk .sb-section img{max-height:2.25rem;width:auto}'
            . '.sb-site-header--partial:has(>.sb-sticky),.sb-site-footer--partial:has(>.sb-sticky){display:contents}'
            . '@media (prefers-reduced-motion:reduce){.sb-sticky,.sb-sticky :is(.sb-section,.sb-section img){transition:none!important}}';
        foreach (['desktop' => '(min-width:1024px)', 'tablet' => '(min-width:768px) and (max-width:1023.98px)', 'mobile' => '(max-width:767.98px)'] as $device => $query) {
            $css .= '@media ' . $query . '{.sb-sticky.sb-sticky-off-' . $device . '{position:relative;top:auto;bottom:auto;transform:none}}';
        }
        return $css;
    }

    /**
     * Counts that differ per device (DeviceColumns): the element carries --sb-<family>-d/-t/-m, and these rules pick one
     * per media query. They come after every column utility and are `!important`, so a count set per device wins over the
     * block's own column classes (`.sb-gallery--cols-6`, `.sb-md-cols-3`).
     */
    public static function deviceCountsCss(): string
    {
        $rule = static fn (string $d): string => '.sb-dcols{grid-template-columns:repeat(var(--sb-dc-' . $d . '),minmax(0,1fr))!important}'
            . '.sb-dtc{column-count:var(--sb-tc-' . $d . ')}'
            . '.sb-dpv{--sb-pv:var(--sb-pv-' . $d . ')}';
        return $rule('d')
            . '.sb-dpv>.sb-carousel__track>.sb-carousel__slide{flex-basis:calc((100% - (var(--sb-pv) - 1)*1rem)/var(--sb-pv))!important}'
            . '@media (max-width:1023.98px){' . $rule('t') . '}'
            . '@media (max-width:767.98px){' . $rule('m') . '}';
    }

    /**
     * The Shop plugin's widgets (plugins/shop/studio-widgets.php): the product grid and cards, the single product parts,
     * and the cart, checkout and order markup Shop's endpoints put into the cart widgets. Colours and radii come from
     * the site's tokens, so a shop looks like the rest of its site; the widgets' style parts override any of it.
     */
    public static function shopCss(): string
    {
        return '.sb-shop-sr{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}'
            . '.sb-shop-products__heading{margin:0 0 1rem}'
            . '.sb-shop-products__grid{list-style:none;margin:0;padding:0;display:grid;gap:1.5rem;grid-template-columns:repeat(var(--sb-shop-cols,3),minmax(0,1fr))}'
            . '@media (max-width:1023.98px){.sb-shop-products__grid{grid-template-columns:repeat(min(var(--sb-shop-cols,3),2),minmax(0,1fr))}}'
            . '@media (max-width:567.98px){.sb-shop-products__grid{grid-template-columns:minmax(0,1fr)}}'
            . '.sb-shop-card{display:flex;flex-direction:column;min-width:0}'
            . '.sb-shop-card__media{position:relative;display:block;aspect-ratio:1;overflow:hidden;border-radius:var(--sb-radius-md);background:var(--sb-surface-secondary)}'
            . '.sb-shop-card__image{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s ease}'
            . '@media ' . StyleSurface::HOVER_MOTION . '{.sb-shop-card__media:hover .sb-shop-card__image{transform:scale(1.03)}}'
            . '.sb-shop-card__placeholder{display:block;width:100%;height:100%}'
            . '.sb-shop-card__badge{position:absolute;top:.6rem;left:.6rem;padding:.2rem .55rem;border-radius:999px;font-size:.75rem;font-weight:600;background:var(--sb-text-primary);color:var(--sb-surface-primary,#fff)}'
            . '.sb-shop-card__badge--sale{background:var(--sb-color-accent)}'
            . '.sb-shop-card__body{display:flex;flex-direction:column;gap:.35rem;padding:.75rem 0 0;flex:1}'
            . '.sb-shop-card__title{margin:0;font-size:1.05rem;line-height:1.3}.sb-shop-card__title a{color:inherit;text-decoration:none}'
            . '.sb-shop-card__add{margin-top:auto}'
            . '.sb-shop-card__icon{display:inline-flex;align-items:center;justify-content:center;width:2.75rem;height:2.75rem;border-radius:999px;background:var(--sb-color-accent);color:#fff}'
            . '.sb-shop-card__icon svg{width:1.3rem;height:1.3rem}'
            . '.sb-shop-card__link{margin-top:auto;color:var(--sb-color-accent);font-weight:600;text-decoration:none}.sb-shop-card__link:hover{text-decoration:underline}'
            . '.sb-shop-price{margin:0;font-weight:600;color:var(--sb-text-primary);display:flex;flex-wrap:wrap;align-items:baseline;gap:.5rem}'
            . '.sb-shop-price__regular{color:var(--sb-text-muted);font-weight:400}.sb-shop-price__sale{text-decoration:none;color:var(--sb-color-accent)}'
            . '.sb-shop-price__vat{font-size:.8rem;font-weight:400;color:var(--sb-text-muted)}'
            . '.sb-shop-filter{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 1.25rem}'
            . '.sb-shop-filter__link,.sb-shop-pager__link{padding:.35rem .8rem;border:1px solid var(--sb-border-default);border-radius:999px;color:inherit;text-decoration:none;font-size:.9rem}'
            . '.sb-shop-filter__link[aria-current],.sb-shop-pager__link[aria-current]{background:var(--sb-text-primary);border-color:var(--sb-text-primary);color:var(--sb-surface-primary,#fff)}'
            . '.sb-shop-pager{display:flex;flex-wrap:wrap;gap:.4rem;justify-content:center;margin:2rem 0 0}'
            . '.sb-shop-add{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin:.5rem 0 0}'
            . '.sb-shop-add__qty{width:4.5rem;padding:.55rem .5rem;font:inherit;border:1px solid var(--sb-border-default);border-radius:var(--sb-radius-sm,.375rem)}'
            . '.sb-shop-add__button{cursor:pointer;border:0;font:inherit}.sb-shop-add[aria-busy="true"] .sb-shop-add__button{opacity:.6;cursor:progress}'
            . '.sb-shop-add--sold-out .sb-shop-add__button{opacity:.55;cursor:not-allowed}'
            . '.sb-shop-add__message{flex-basis:100%;font-size:.875rem;min-height:0}.sb-shop-add__message:empty{display:none}'
            . '.sb-shop-add__options{flex-basis:100%;display:grid;gap:.75rem}'
            . '.sb-shop-add__option{margin:0;display:grid;gap:.3rem}'
            . '.sb-shop-add__option-label{font-weight:600;font-size:.9rem;color:var(--sb-text-primary)}'
            . '.sb-shop-add__select{max-width:22rem;padding:.6rem .7rem;font:inherit;color:inherit;background:var(--sb-surface-primary,#fff);border:1px solid var(--sb-border-default);border-radius:var(--sb-radius-sm,.375rem)}'
            . '.sb-shop-add__variant{margin:0;display:flex;align-items:center;gap:1rem;min-height:1.5rem}'
            . '.sb-shop-add__price{font-weight:700;font-size:1.1rem;color:var(--sb-text-primary)}'
            . '.sb-shop-add__reset{padding:0;border:0;background:none;font:inherit;font-size:.85rem;color:var(--sb-text-muted);text-decoration:underline;cursor:pointer}'
            . '.sb-shop-add__button:disabled{opacity:.55;cursor:not-allowed}'
            . '.sb-shop-add__message[data-sb-state="error"]{color:#b91c1c}'
            . '.sb-shop-gallery{display:flex;flex-direction:column;gap:.75rem}'
            . '.sb-shop-gallery__main,.sb-shop-gallery__thumb{margin:0;overflow:hidden;border-radius:var(--sb-radius-md);background:var(--sb-surface-secondary);cursor:zoom-in}'
            . '.sb-shop-gallery__main img,.sb-shop-gallery__thumb img{width:100%;height:100%;object-fit:cover;display:block}'
            . '.sb-shop-gallery--square .sb-shop-gallery__main{aspect-ratio:1}.sb-shop-gallery--portrait .sb-shop-gallery__main{aspect-ratio:4/5}.sb-shop-gallery--landscape .sb-shop-gallery__main{aspect-ratio:4/3}'
            . '.sb-shop-gallery__main--empty{aspect-ratio:1}'
            . '.sb-shop-gallery__thumbs{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.5rem}.sb-shop-gallery__thumb{aspect-ratio:1}'
            . '.sb-block--shop-product-title{font-size:2.25rem;line-height:1.2}.sb-shop-title{margin:0 0 .5rem;font-size:1em;line-height:inherit;letter-spacing:inherit}'
            . '.sb-shop-description>p{margin:0 0 var(--sb-type-flow,1rem)}'
            . '.sb-shop-stock{margin:0;font-weight:600;font-size:.9rem}.sb-shop-stock--in{color:#047857}.sb-shop-stock--low{color:#b45309}.sb-shop-stock--out{color:#b91c1c}'
            . '.sb-shop-meta{margin:0;display:grid;gap:.25rem;font-size:.9rem}.sb-shop-meta__row{display:flex;gap:.5rem}'
            . '.sb-shop-meta dt{color:var(--sb-text-muted)}.sb-shop-meta dd{margin:0}'
            . '.sb-shop-mini__link{display:inline-flex;align-items:center;gap:.4rem;color:inherit;text-decoration:none;font-weight:600}'
            . '.sb-shop-mini__count{min-width:1.4rem;height:1.4rem;padding:0 .35rem;border-radius:999px;display:inline-grid;place-items:center;font-size:.75rem;background:var(--sb-color-accent);color:#fff}'
            . '.sb-shop-mini__count[data-count="0"]{background:var(--sb-surface-secondary);color:var(--sb-text-muted)}'
            . '.sb-shop-mini__total{font-weight:400;color:var(--sb-text-muted)}'
            . '.sb-shop-fragment[data-sb-fragment-state="loading"]{opacity:.7}'
            . '.sb-shop-notice{padding:.75rem 1rem;border-radius:var(--sb-radius-md);background:var(--sb-surface-secondary);margin:0 0 1rem}'
            . '.sb-shop-error{display:block;color:#b91c1c;font-size:.85rem;margin:.25rem 0 0}'
            . '.sb-shop-cart__table{width:100%;border-collapse:collapse}'
            . '.sb-shop-cart__table :is(th,td){padding:.75rem .5rem;border-bottom:1px solid var(--sb-border-default);text-align:left;vertical-align:middle}'
            . '.sb-shop-cart__table th{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:var(--sb-text-muted);font-weight:600}'
            . '.sb-shop-cart__product{display:flex;align-items:center;gap:.75rem}'
            . '.sb-shop-cart__image{width:64px;height:64px;object-fit:cover;border-radius:var(--sb-radius-sm,.375rem);flex:none}'
            . '.sb-shop-cart__options{display:block;font-size:.85rem;color:var(--sb-text-muted)}'
            . '.sb-shop-cart__title{color:inherit;font-weight:600;text-decoration:none}.sb-shop-cart__note{display:block;font-size:.8rem;color:#b45309}'
            . '.sb-shop-cart__qty-form{display:flex;gap:.35rem;align-items:center}'
            . '.sb-shop-cart__qty-input{width:4rem;padding:.4rem;font:inherit;border:1px solid var(--sb-border-default);border-radius:var(--sb-radius-sm,.375rem)}'
            . '.sb-shop-cart__update,.sb-shop-cart__remove{font:inherit;background:none;border:0;cursor:pointer;color:var(--sb-text-muted);text-decoration:underline}'
            . '.sb-shop-cart__remove{font-size:1.25rem;text-decoration:none}'
            . '.sb-shop-cart__totals{margin:1.25rem 0 0;margin-left:auto;max-width:22rem;display:flex;flex-direction:column;gap:.35rem}'
            . '.sb-shop-cart__subtotal{display:flex;justify-content:space-between;margin:0;font-size:1.1rem}'
            . '.sb-shop-cart__vat,.sb-shop-cart__free{margin:0;font-size:.85rem;color:var(--sb-text-muted)}'
            . '@media (max-width:767.98px){.sb-shop-cart__table thead{display:none}.sb-shop-cart__table tr{display:grid;grid-template-columns:1fr auto;gap:.25rem .75rem;padding:.75rem 0;border-bottom:1px solid var(--sb-border-default)}'
            . '.sb-shop-cart__table td{border:0;padding:0}.sb-shop-cart__product{grid-column:1/-1}.sb-shop-cart__totals{max-width:none}}'
            . '.sb-shop-checkout__form{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,22rem);gap:2rem;align-items:start}'
            . '@media (max-width:767.98px){.sb-shop-checkout__form{grid-template-columns:minmax(0,1fr)}}'
            . '.sb-shop-checkout fieldset{border:0;margin:0 0 1.25rem;padding:0}.sb-shop-checkout legend{font-weight:700;margin:0 0 .5rem;padding:0}'
            . '.sb-shop-checkout__field{margin:0 0 .75rem;display:flex;flex-direction:column;gap:.3rem}.sb-shop-checkout__field label{font-weight:500;font-size:.9rem}'
            . '.sb-shop-checkout__field :is(input,select,textarea){font:inherit;padding:.6rem .7rem;border:1px solid var(--sb-border-default);border-radius:var(--sb-radius-sm,.375rem);background:var(--sb-surface-primary,#fff);color:inherit}'
            . '.sb-shop-checkout__field [aria-invalid="true"]{border-color:#b91c1c}'
            . '.sb-shop-checkout__option{display:flex;gap:.5rem;align-items:center;margin:0 0 .4rem}'
            . '.sb-shop-checkout__hint,.sb-shop-checkout__secure{font-size:.85rem;color:var(--sb-text-muted);margin:0 0 .5rem}'
            . '.sb-shop-summary{padding:1.25rem;border:1px solid var(--sb-border-default);border-radius:var(--sb-radius-md);background:var(--sb-surface-secondary)}'
            . '.sb-shop-summary__title{margin:0 0 .75rem;font-size:1.1rem}.sb-shop-summary__lines{list-style:none;margin:0 0 .75rem;padding:0}'
            . ':is(.sb-shop-summary__line,.sb-shop-summary__row,.sb-shop-summary__total,.sb-shop-order__line,.sb-shop-order__row,.sb-shop-order__total){display:flex;justify-content:space-between;gap:1rem;margin:0 0 .35rem}'
            . '.sb-shop-summary__total,.sb-shop-order__total{padding-top:.5rem;border-top:1px solid var(--sb-border-default);font-size:1.05rem}'
            . '.sb-shop-checkout__pay{width:100%;cursor:pointer;border:0;font:inherit}.sb-shop-checkout__form[aria-busy="true"] .sb-shop-checkout__pay{opacity:.6;cursor:progress}'
            . '.sb-shop-order__meta{display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem;margin:1rem 0}.sb-shop-order__meta dt{color:var(--sb-text-muted)}.sb-shop-order__meta dd{margin:0}'
            . '.sb-shop-order__lines{list-style:none;margin:0 0 .5rem;padding:0}';
    }

    /**
     * A Studio form's fields share a row by their width (`--sb-fw-d/-t/-m`, a fraction of the row): the basis takes back
     * its share of the gap, so two halves and the gap between them fill the row exactly. The rest is the states the
     * runtime sets: a field's error, the status line, the honeypot kept out of sight.
     */
    public static function formCss(): string
    {
        $w = static fn (string $d): string => '.sb-form>.sb-fw{flex-basis:calc((100% + 1rem)*var(--sb-fw-' . $d . ',1) - 1rem)}';
        return $w('d') . '@media (max-width:1023.98px){' . $w('t') . '}' . '@media (max-width:767.98px){' . $w('m') . '}'
            . '.sb-form>.sb-form-hidden{display:none}'
            . '.sb-hp{position:absolute!important;left:-9999px;width:1px;height:1px;overflow:hidden}'
            . '.sb-form-choices{border:0;margin:0;padding:0;display:flex;flex-direction:column;gap:.375rem}'
            . '.sb-form-choices legend{font-weight:500;font-size:.875rem;margin-bottom:.25rem;padding:0}'
            . '.sb-form-check{display:flex;align-items:center;gap:.5rem;font-weight:400}'
            . '.sb-form-error{font-size:.8rem;color:#b91c1c}'
            . '.sb-form-group.is-invalid :is(input,select,textarea){border-color:#b91c1c}'
            . '.sb-form__status{padding:.75rem 1rem;border-radius:.5rem;font-size:.9rem;background:var(--sb-surface-secondary)}'
            . '.sb-form__status[data-sb-state="success"]{background:#ecfdf5;color:#065f46}'
            . '.sb-form__status[data-sb-state="error"]{background:#fef2f2;color:#991b1b}'
            . '.sb-form__note{font-size:.8rem;color:var(--sb-text-muted);margin:.5rem 0 0}'
            . '.sb-form[aria-busy="true"] .sb-form__actions .sb-button{opacity:.6;cursor:progress}'
            // Hidden labels: out of sight, still read out (each field keeps its accessible name).
            . '.sb-form--labels-hidden :is(.sb-form-group>label,.sb-form-choices>legend){position:absolute!important;width:1px;height:1px;margin:0;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}'
            // Inline: the fields share the row and the button keeps its own width beside them, on every device; a status
            // line takes a row of its own. After the width rules above, so it wins over a field's width.
            . '.sb-form--inline{flex-wrap:wrap;align-items:flex-end}'
            . '.sb-form--inline>.sb-block{flex:1 1 0;min-width:0}'
            . '.sb-form--inline>.sb-form__actions{flex:0 0 auto;margin-top:0}'
            . '.sb-form--inline>.sb-form__actions .sb-button{width:auto}'
            . '.sb-form--inline+.sb-form__status{margin:.5rem 0 0}';
    }

    public static function prefix(string $breakpoint): ?string
    {
        if ($breakpoint === 'base') {
            return '';
        }
        return in_array($breakpoint, CanonicalDocumentSchema::ALLOWED_BREAKPOINTS, true) ? $breakpoint . '-' : null;
    }
}
