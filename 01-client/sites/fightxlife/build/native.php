<?php
// The FightxLife design as native Studio style: each class name the build gives a block maps to the block's own style,
// device overrides, hover state, parts and props. SEC() applies the map and drops every class it covers; a class with
// no entry (or listed in GAP) keeps its name and its rules in gap.css.
//
// Breakpoints: the design used 1050, 780 and 560px; Studio has tablet (≤1023px) and phone (≤767px). 1050 rules → tablet,
// 560 rules → phone, and a 780 rule → phone when no 560 rule overrides it.
declare(strict_types=1);

const PINK = '#e9008a';
const PINK_DEEP = '#d4007e'; // the brand pink where small text has to reach 4.5:1 against white
const PINK_SOFT = '#fde4f1';
const INK = '#0c0905';
const BODY = '#6b6468';
const PINK_GRAD = 'linear-gradient(140deg,#ff4dae 0%,#e9008a 100%)';

function px(int|float|string $v): string { return is_string($v) ? $v : $v . 'px'; }
/** Sides in CSS order; null leaves a side out. */
function sides(array $v): array
{
    [$t, $r, $b, $l] = match (count($v)) { 1 => [$v[0], $v[0], $v[0], $v[0]], 2 => [$v[0], $v[1], $v[0], $v[1]], 3 => [$v[0], $v[1], $v[2], $v[1]], default => $v };
    return array_map('px', array_filter(['top' => $t, 'right' => $r, 'bottom' => $b, 'left' => $l], static fn($x) => $x !== null));
}
function pad(...$v): array { return ['padding' => sides($v)]; }
function mar(...$v): array { return ['margin' => sides($v)]; }
function ty(?string $size = null, ?string $weight = null, ?string $lh = null, ?string $ls = null, ?string $ff = null): array
{
    return ['typography' => array_filter(['size' => $size, 'weight' => $weight, 'line_height' => $lh, 'letter_spacing' => $ls, 'font_family' => $ff], static fn($x) => $x !== null)];
}
const SYNE = 'Syne, Arial, sans-serif';
function col(string $c): array { return ['color' => $c]; }
function bg(string $c): array { return ['background' => ['color' => $c]]; }
function grad(string $g): array { return ['background' => ['gradient' => $g]]; }
function dim(array $d): array { return ['dimensions' => array_map(static fn($x) => is_int($x) ? $x . 'px' : $x, $d)]; }
function radius(string $r): array { return ['border' => ['radius' => $r]]; }
/** Rounds what sits at the top of a card: cards leave overflow open, so a lifted card's hover margin isn't clipped. */
function top_radius(string $r): array { return ['border' => ['radius_corners' => ['tl' => $r, 'tr' => $r]]]; }
function line(string $side, string $color, string $w = '1px'): array { return ['border' => [$side => ['width' => $w, 'style' => 'solid', 'color' => $color]]]; }
function outline(string $color, string $r, string $w = '1px'): array { return ['border' => ['width' => $w, 'style' => 'solid', 'color' => $color, 'radius' => $r]]; }
function shadow(int $x, int $y, int $blur, string $c, int $spread = 0): array { return ['shadow' => ['x' => "{$x}px", 'y' => "{$y}px", 'blur' => "{$blur}px", 'spread' => "{$spread}px", 'color' => $c]]; }
function lay(array $l): array { return ['layout' => $l]; }
function flexc(string $justify = 'center', string $align = 'center'): array { return lay(['display' => 'flex', 'justify' => $justify, 'align' => $align]); }
function place(string $mode, array $at = []): array { return ['position' => ['mode' => $mode] + array_map('px', $at)]; }
function center(): array { return ['align' => ['base' => 'center']]; }
function S(array ...$parts): array { return array_replace_recursive([], ...$parts); }
/** A hover zoom on an image whose frame clips it. */
function zoom(float $scale, int $ms = 350, array $style = []): array { return ['s' => array_replace_recursive($style, ['effects' => ['transition' => ['duration_ms' => $ms, 'easing' => 'ease']]]), 'h' => ['effects' => ['transform' => ['scale' => $scale]]]]; }
/** An icon tile: a coloured square (or disc) with the icon centred. */
function tile(int $size, string $r, string $bg, ?string $fg = PINK): array { return S(dim(['width' => $size, 'height' => $size]), radius($r), bg($bg), $fg !== null ? col($fg) : [], flexc()); }

/**
 * class => fragment. Fragment keys: s (style), t / m (tablet / phone style), h (hover style), parts (style_parts),
 * props, tp / mp (tablet / phone props), thide / mhide, center (children centred).
 * "ancestor child" keys apply to a block with class `child` inside a block (or section) with class `ancestor`.
 */
function native_map(): array
{
    // v2.php adds the V2 design's classes (native_v2) when it is the build that runs.
    return array_replace_recursive(native_map_base(), native_more(), function_exists('native_v2') ? native_v2() : []);
}

function native_map_base(): array
{
    $V = static fn(int $t, int $b): array => pad($t, null, $b, null);
    return [
        // ── Sections ──
        'yg-content'          => ['s' => $V(92, 92), 'm' => $V(60, 60)],
        'fx-top-sec'          => ['s' => S(bg(INK), col('#d8d0d4'), ty('13px'), $V(8, 8))],
        // Room under the hero picture, so it does not sit on the next section.
        'yg-hero-sec'         => ['s' => S(bg('#f8f8f8'), dim(['overflow' => 'hidden']), pad(null, null, 90, null)), 't' => pad(null, null, 70, null), 'm' => pad(null, null, 48, null)],
        'yg-features-sec'     => ['s' => $V(74, 78), 'm' => $V(47, 47)],
        'yg-features-sec--page' => ['s' => $V(101, 81), 'm' => $V(48, 48)],
        'yg-about-sec'        => ['s' => S(bg('#ffffff'), place('relative'), dim(['overflow' => 'hidden']), $V(78, 94)), 'm' => $V(44, 58)],
        'yg-about-sec--page'  => ['s' => pad(66, null, null, null)],
        'yg-why-sec'          => ['s' => S(bg('#f8f8f8'), dim(['overflow' => 'hidden']), $V(88, 88)), 'm' => $V(58, 58)],
        'yg-team-sec'         => ['s' => S(bg('#f8f8f8'), $V(90, 100)), 'm' => $V(58, 58)],
        'yg-testi-sec'        => ['s' => S(bg('#fef1f8'), $V(91, 96)), 'm' => $V(58, 58)],
        'yg-news-sec'         => ['s' => S(bg('#fde6f2'), $V(47, 47)), 'm' => $V(34, 34)],
        'yg-consult-sec'      => ['s' => S(bg('#fdeaf4'), $V(88, 88)), 'm' => $V(58, 58), 'center' => true],
        'yg-consult'          => ['s' => S(lay(['content_max' => 'min(720px, calc(100% - 64px))', 'align' => 'center'])), 'center' => true],
        'yg-contact-sec'      => ['s' => $V(96, 109), 'm' => $V(60, 72)],
        'yg-map-sec'          => ['s' => $V(0, 92)],
        'yg-detail-sec'       => ['s' => pad(100, null, null, null), 'm' => pad(48, null, null, null)],
        'yg-single-sec'       => ['s' => pad(100, null, null, null), 'm' => pad(45, null, null, null)],
        // A banner's content sat 17px above centre (a transform); padding below does the same.
        'yg-banner'           => ['s' => S(bg('#f6f6f6'), dim(['min_height' => 304]), lay(['display' => 'flex', 'direction' => 'column', 'justify' => 'center']), pad(null, null, 34, null)),
                                  'm' => S(dim(['min_height' => '210px']), pad(null, null, 14, null))],
        'yg-banner--centered' => ['s' => S(dim(['min_height' => 396]), pad(null, null, 32, null)), 'm' => S(dim(['min_height' => '285px'])), 'center' => true],
        'fx-banner--photo'    => ['s' => S(col('#ffffff'), ['background' => ['overlay' => ['color' => 'rgba(12,9,5,.55)']]]), 'm' => dim(['min_height' => '190px'])],
        'yg-foot-main'        => ['s' => S(grad('linear-gradient(135deg,#f2189a,#c8007a)'), bg('#e9008a'), col('#ffffff'), $V(85, 75)), 'm' => $V(58, 50)],
        'yg-foot-bottom'      => ['s' => S(bg('#b5006e'), col('rgba(255,255,255,.83)'), ty('13px'), $V(20, 20))],
        'fx-white'            => ['s' => bg('#ffffff')],
        'fx-soft'             => ['s' => bg('#f8f8f8')],
        'fx-values'           => ['s' => pad(90, null, null, null), 'm' => pad(48, null, null, null)],
        'fx-pb0'              => ['s' => pad(null, null, 0, null), 'm' => pad(null, null, 0, null)],
        'fx-pt0'              => ['s' => pad(0, null, null, null), 'm' => pad(0, null, null, null)],
        'fx-logos-sec'        => [],
        'yg-header-sec'       => [],
        'fx-legal'            => ['s' => lay(['content_max' => 'min(860px, calc(100% - 64px))', 'display' => 'flex', 'direction' => 'column', 'gap' => '0px'])],
        'fx-body'             => ['s' => lay(['display' => 'flex', 'direction' => 'column', 'gap' => '0px'])],

        // ── Section heads ──
        'yg-eyebrow'          => ['s' => S(col(PINK), ty('14px', 'bold', '1.2', '.04em'), mar(0, null, 12, null))],
        'yg-head'             => ['s' => S(dim(['max_width' => '700px']), mar(0, 'auto', 42, 'auto')), 'm' => mar(null, null, 24, null)],
        'yg-head--left'       => ['s' => mar(null, null, null, 0)],
        'yg-head--center'     => ['center' => true],
        'yg-h2'               => ['s' => ty('clamp(32px,3.3vw,43px)', null, '1.18', '-.025em'), 'm' => ty('31px')],
        'yg-lead'             => ['s' => S(dim(['max_width' => '640px']), mar(15, 'auto', 0, 'auto'), col(BODY), ty('16px', null, '1.65')), 'm' => ty('15px')],
        'yg-head--left yg-lead' => ['s' => mar(null, null, null, 0)],
        'yg-consult yg-lead'  => ['s' => S(dim(['max_width' => '600px']), mar(0, 'auto', 23, 'auto'))],
        'yg-consult yg-h2'    => ['s' => S(ty('clamp(32px,3.4vw,44px)'), mar(null, null, 13, null))],
        'yg-center'           => ['s' => S(flexc(), mar(39, null, null, null))],

        // ── Page banner ──
        'yg-banner__title'    => ['s' => S(dim(['max_width' => '900px']), ty('clamp(34px,4vw,50px)', null, '1.16', '-.025em')), 'm' => ty('31px')],
        'yg-banner--centered yg-banner__title' => ['s' => S(dim(['max_width' => '1170px']), mar(null, 'auto', null, 'auto'), ty('clamp(32px,3.4vw,44px)')), 'm' => ty('29px')],
        'fx-banner--photo yg-banner__title' => ['s' => col('#ffffff')],
        'yg-crumbs'           => ['s' => S(lay(['gap' => '11px']), mar(15, null, null, null), col('#999999'), ty('14px')), 'm' => S(ty('12px'), mar(11, null, null, null))],
        'yg-crumb__home'      => ['s' => S(col(PINK), ty(null, '400'))],
        'fx-banner--photo yg-crumb__home' => ['s' => col('#ffffff')],
        'yg-crumb__sep'       => ['s' => col('#c4c4c4')],
        'fx-banner--photo yg-crumb__sep'  => ['s' => col('rgba(255,255,255,.75)')],
        'yg-crumb__here'      => [],
        'fx-banner--photo yg-crumb__here' => ['s' => col('rgba(255,255,255,.75)')],
        'fx-crumb-link'       => ['s' => S(col('#999999'), ty(null, '400'))],
        'yg-post-meta'        => ['s' => S(mar(32, null, null, null), col('#999999'), ty('13px'))],

        // ── Hero ──
        'yg-hero__copy'       => ['s' => S(lay(['align' => 'center']), pad(70, null, null, null)), 'm' => pad(31, null, null, null), 'center' => true],
        'yg-hero__title'      => ['s' => S(ty('clamp(36px,4.15vw,50px)', null, '1.15'), dim(['max_width' => '760px']), mar(0, 'auto', 0, 'auto')), 'm' => ty('34px')],
        'yg-hero__text'       => ['s' => S(dim(['max_width' => '570px']), mar(13, 'auto', 47, 'auto'), col(BODY), ty('14px', null, '1.45')),
                                  'm' => S(ty('13px'), dim(['max_width' => '370px']), mar(13, null, 20, null))],
        'yg-hero__actions'    => ['s' => lay(['gap' => '14px', 'align' => 'center', 'wrap' => 'wrap', 'justify' => 'center']), 'm' => lay(['gap' => '10px'])],
        'yg-hero__visual'     => ['s' => S(place('relative'), dim(['overflow' => 'hidden']), mar(49, null, null, null), radius('18px')),
                                  'm' => mar(27, null, null, null)],
        'yg-hero__video'      => ['s' => radius('18px')],
        'yg-hero__img'        => ['s' => S(dim(['height' => '100%', 'object_fit' => 'cover', 'object_position' => 'center']), radius('18px'))],
        'yg-play'             => ['s' => S(place('absolute', ['left' => '50%', 'top' => '50%']), ['effects' => ['transform' => ['translate_x' => '-50%', 'translate_y' => '-50%']]], ['z_index' => 2])],

        // ── Feature row ──
        'yg-features'         => ['s' => lay(['gap' => '28px']), 'props' => ['columns' => 3], 'mp' => ['columns' => 1], 'm' => lay(['row_gap' => '34px'])],
        'yg-features-sec--page yg-features' => ['s' => lay(['gap' => '0px']), 'm' => lay(['row_gap' => '34px'])],
        'yg-feature'          => ['s' => S(lay(['align' => 'center']), pad(0, 19)), 'm' => S(pad(0, 4), dim(['max_width' => '360px']), mar(null, 'auto', null, 'auto')), 'center' => true],
        'yg-feature__icon'    => ['s' => S(tile(46, '12px', PINK_SOFT), mar(0, 'auto', 17, 'auto'))],
        'yg-features-sec--page yg-feature__icon' => ['s' => S(dim(['width' => '80px', 'height' => '80px']), radius('14px'), mar(null, null, 25, null)), 'props' => ['size_px' => 31],
                                  'm' => S(dim(['width' => '64px', 'height' => '64px']), mar(null, null, 16, null))],
        'yg-feature__title'   => ['s' => S(ty('21px'), mar(null, null, 11, null)), 'm' => ty('19px')],
        'yg-features-sec--page yg-feature__title' => ['s' => mar(null, null, 16, null)],
        'yg-feature__text'    => ['s' => S(col(BODY), ty('14.5px', null, '1.65')), 'm' => ty('13px')],
        'yg-features-sec--page yg-feature__text' => ['s' => S(dim(['max_width' => '250px']), mar(null, 'auto', null, 'auto'), ty('15px', null, '1.7')), 'm' => ty('13px')],

        // ── About / mission ──
        'yg-about'            => ['s' => lay(['gap' => '72px', 'align' => 'center']), 'props' => ['columns' => 2], 'mp' => ['columns' => 1], 'm' => lay(['gap' => '25px'])],
        'yg-about-sec--page yg-about' => ['s' => lay(['align' => 'start'])],
        'yg-about__media'     => ['s' => S(dim(['height' => 445]), flexc('center', 'end'), place('relative')), 'm' => dim(['height' => 'auto'])],
        'yg-about__img'       => ['s' => dim(['height' => '100%', 'width' => 'auto', 'object_fit' => 'contain'])],
        'yg-about__copy'      => [],
        'yg-about__copy yg-h2' => ['s' => S(dim(['max_width' => '470px']), mar(null, null, 18, null)), 'm' => ty('31px')],
        'yg-about__copy yg-head' => ['s' => mar(0, 0, 0, 0), 'm' => mar(null, null, 0, null)],
        'yg-about__text'      => ['s' => S(dim(['max_width' => '545px']), mar(0, 0, 17, 0), ty(null, null, '1.8'), col(BODY))],
        'yg-sign'             => ['s' => mar(21, 0, 19, 0)],
        'yg-sign__name'       => ['s' => S(col(INK), ty('19px', 'bold', '1.25', null, SYNE))],
        'yg-sign__role'       => ['s' => S(mar(4, null, null, null), col('#8a8287'), ty('13px', null, '1.4'))],
        'fx-media-photo'      => ['s' => dim(['height' => 460])],
        'fx-cover'            => ['s' => S(dim(['height' => '100%', 'width' => '100%', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('14px'))],
        'fx-portrait'         => ['s' => S(dim(['width' => '100%', 'max_width' => '400px', 'object_fit' => 'cover', 'object_position' => 'top', 'overflow' => 'hidden']), mar(null, 'auto', null, 'auto'),
                                  radius('20px'), shadow(0, 18, 40, 'rgba(12,9,5,.12)'), dim(['aspect_ratio' => '4/5'])), 'm' => dim(['max_width' => '100%'])],

        // ── Why ──
        'yg-why'              => ['s' => lay(['gap' => '65px', 'align' => 'center']), 'props' => ['columns' => 2], 't' => lay(['gap' => '36px']), 'mp' => ['columns' => 1], 'm' => lay(['gap' => '28px'])],
        'fx-why--flip'        => [],
        'yg-blog-layout--single' => [],
        'yg-why__media'       => ['s' => S(dim(['height' => 460]), flexc('center', 'end')), 'm' => dim(['height' => '280px'])],
        'yg-why__img'         => ['s' => dim(['height' => '100%', 'width' => 'auto', 'object_fit' => 'contain'])],
        'yg-why__copy'        => [],
        'yg-why__copy yg-head' => ['s' => mar(null, null, 29, null), 'm' => mar(null, null, 20, null)],

        // ── Cards ──
        'yg-class-grid'       => ['s' => lay(['gap' => '28px']), 't' => lay(['gap' => '18px']), 'mp' => ['columns' => 1], 'm' => lay(['gap' => '25px'])],
        'fx-grid4'            => ['s' => lay(['gap' => '22px']), 'props' => ['columns' => 4], 'tp' => ['columns' => 2], 'mp' => ['columns' => 1]],
        'yg-class'            => ['s' => S(place('relative'), dim(['min_width' => '0px']))],
        'yg-class__photo'     => ['s' => S(place('relative'), dim(['height' => 255, 'overflow' => 'hidden']), radius('15px'), bg('#eeeeee')), 'm' => dim(['height' => '230px'])],
        'yg-class__img'       => zoom(1.035, 350, dim(['height' => '100%', 'object_fit' => 'cover'])),
        'yg-class__num'       => ['s' => S(place('absolute', ['top' => 15, 'right' => 15]), dim(['height' => 40]), radius('999px'), bg('#ffffff'), col(PINK), ty('13px', 'bold', null, null, SYNE),
                                  pad(0, 14), flexc())],
        'fx-badge'            => [],
        'yg-class__body'      => ['s' => S(place('relative'), mar(-35, 13, 0, 13), pad(24, 22, 23, 22), radius('13px'), bg('#ffffff'), shadow(0, 8, 28, 'rgba(18,10,34,.08)')),
                                  't' => S(mar(null, 8, null, 8), pad(null, 17, null, 17)), 'm' => mar(null, 12, null, 12)],
        'yg-class__icon'      => ['s' => S(tile(39, '10px', PINK_SOFT), mar(-44, null, 16, null)), 'props' => ['size_px' => 20]],
        'yg-class__title'     => ['s' => S(ty('21px'), mar(null, null, 9, null))],
        'fx-grid4 yg-class__title' => ['s' => ty('19px')], // four across at 1024px, where the design's 1050px break no longer applies
        'yg-class__text'      => ['s' => S(mar(0, 0, 13, 0), col(BODY), ty('14.5px', null, '1.65'))],
        'yg-more'             => ['s' => S(col(PINK), ty('14px', 'bold'))],

        // ── People ──
        'yg-trainer-grid'     => ['s' => lay(['row_gap' => '36px', 'column_gap' => '28px']), 'mp' => ['columns' => 1], 'm' => lay(['gap' => '25px'])],
        'fx-people'           => ['s' => lay(['row_gap' => '30px', 'column_gap' => '24px']), 'tp' => ['columns' => 3], 'mp' => ['columns' => 1], 'm' => lay(['gap' => '25px'])],
        'yg-trainer'          => ['s' => place('relative'), 'center' => true],
        'yg-trainer__img'     => ['t' => dim(['height' => '310px']), 'm' => dim(['height' => '350px'])] + zoom(1.04, 350, S(dim(['height' => 345, 'overflow' => 'hidden', 'object_fit' => 'cover']), radius('14px'), bg('#eeeeee'))),
        'fx-people yg-trainer__img' => ['s' => S(dim(['height' => 300]), bg('#f3eef1')), 't' => dim(['height' => '300px']), 'm' => dim(['height' => '320px'])],
        'yg-trainer__name'    => ['s' => S(mar(17, null, null, null), ty('21px'))],
        'yg-trainer__role'    => ['s' => S(mar(4, null, null, null), col(BODY), ty('14px'))],
        'fx-person__quote'    => ['s' => S(mar(10, null, null, null), col(BODY), ty('13.5px', null, '1.6'))],

        // ── Testimonials ──
        'yg-quote-grid'       => ['s' => lay(['gap' => '22px']), 'mp' => ['columns' => 1]],
        'fx-quote-grid'       => ['s' => lay(['gap' => '20px']), 'props' => ['columns' => 4], 'tp' => ['columns' => 2], 'mp' => ['columns' => 1]],
        'yg-quote'            => ['s' => S(pad(29, 26, 24, 26), radius('14px'), bg('#ffffff'), shadow(0, 8, 25, 'rgba(65,0,40,.055)'))],
        'yg-quote__mark'      => ['s' => S(col(PINK), ty('54px', 'bold', '.6', null, SYNE))],
        'yg-quote__text'      => ['s' => S(dim(['min_height' => 105]), mar(21, 0, 21, 0), col(BODY), ty('14.5px', null, '1.65')), 'm' => S(dim(['min_height' => '0px']), mar(14, null, 18, null))],
        'fx-quote-grid yg-quote__text' => ['s' => S(dim(['min_height' => 150]), ty('13.5px'))],
        'yg-quote__person'    => ['s' => lay(['gap' => '13px'])],
        'yg-quote__img'       => ['s' => S(dim(['width' => 52, 'height' => 52, 'object_fit' => 'cover', 'overflow' => 'hidden']), lay(['shrink' => 0, 'basis' => '52px']), radius('50%'))],
        'yg-quote__name'      => ['s' => S(col(INK), ty('15px', 'bold', null, null, SYNE))],
        'yg-quote__role'      => ['s' => S(mar(4, null, null, null), col('#8a8287'), ty('12px'))],

        // ── Blog and event cards ──
        'yg-post'             => ['s' => S(dim(['min_width' => '0px', 'overflow' => 'hidden']), radius('14px'), bg('#ffffff'), shadow(0, 5, 23, 'rgba(24,16,37,.07)'))],
        'yg-post__img'        => zoom(1.035, 300, S(dim(['height' => 235, 'overflow' => 'hidden', 'object_fit' => 'cover']), bg('#eeeeee'))),
        'fx-press yg-post__img' => ['s' => dim(['height' => 200])],
        'yg-post__body'       => ['s' => pad(24), 'm' => pad(20)],
        'yg-post__title'      => ['s' => S(ty('21px', null, '1.35'), mar(null, null, 10, null)), 'h' => col(PINK)],
        'yg-post__text'       => ['s' => S(mar(0, 0, 14, 0), col(BODY), ty('14.5px', null, '1.65'))],
        'fx-podcast yg-post__title' => ['s' => ty('22px')],
        'fx-podcast yg-post__text'  => ['s' => mar(6, 0, 18, 0)],
        'yg-posts'            => ['s' => lay(['row_gap' => '28px', 'column_gap' => '30px']), 'props' => ['columns' => 2], 'mp' => ['columns' => 1]],
        'fx-press'            => ['s' => lay(['gap' => '28px']), 'props' => ['columns' => 3], 'mp' => ['columns' => 1]],
        'fx-events'           => ['s' => lay(['gap' => '28px']), 'props' => ['columns' => 3], 'mp' => ['columns' => 1]],
        'fx-podcast'          => ['s' => S(dim(['max_width' => '720px']), mar(0, 'auto', 0, 'auto'), pad(30), radius('14px'), bg('#fef1f8'), lay(['align' => 'center'])), 'center' => true],

        // ── Sidebar and detail ──
        'yg-sidebar'          => ['s' => S(lay(['display' => 'grid', 'gap' => '26px']), mar(17, null, null, null)), 'm' => mar(0, null, null, null)],
        'yg-blog-layout--single yg-sidebar' => ['s' => mar(12, null, null, null)],
        'yg-widget'           => ['s' => S(pad(40), radius('13px'), bg('#f8f8f8')), 't' => pad(26)],
        'yg-widget__title'    => ['s' => S(pad(null, null, 14, null), mar(null, null, 15, null), line('bottom', '#e9e9e9'), ty('19px'))],
        'fx-widget__text'     => ['s' => S(mar(0, 0, 6, 0), col(BODY), ty('14px'))],
        'yg-promo'            => ['s' => S(dim(['min_height' => 266, 'overflow' => 'hidden']), place('relative'), radius('14px'), bg('#241c2c'), col('#ffffff'))],
        'yg-promo--help'      => ['s' => S(dim(['height' => 300]), mar(25, null, null, null), bg('#201726')), 'm' => mar(0, null, null, null)],
        'yg-promo__img'       => ['s' => S(place('absolute', ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]), dim(['height' => '100%', 'object_fit' => 'cover']), ['opacity' => 0.52])],
        'yg-promo--help yg-promo__img' => ['s' => ['opacity' => 0.5]],
        'yg-promo__copy'      => ['s' => S(place('absolute', ['left' => 22, 'right' => 18, 'bottom' => 23]), ['z_index' => 1])],
        'yg-promo--help yg-promo__copy' => ['s' => S(place('absolute', ['right' => 20, 'bottom' => 20]), lay(['display' => 'flex', 'direction' => 'column', 'gap' => '5px']))],
        'yg-promo__eyebrow'   => ['s' => S(ty('12px'), col('#ffd0e8'))],
        'yg-promo__title'     => ['s' => S(mar(5, 0, 11, 0), col('#ffffff'), ty('21px'))],
        'yg-promo--help yg-promo__title' => ['s' => S(mar(3, 0, 8, 0), ty('22px'))],
        'yg-promo__link'      => ['s' => S(col('#ffffff'), ty('13px', '400'))],
        'yg-article'          => [],
        'yg-detail__main'     => [],
        'yg-detail__side'     => [],
        'yg-info'             => ['s' => S(dim(['min_height' => 546]), pad(24), radius('14px'), bg('#ffffff'), shadow(0, 8, 30, 'rgba(22,12,39,.1)')), 'm' => S(dim(['min_height' => '0px']), pad(20))],
        'fx-info--short'      => ['s' => dim(['min_height' => '0px'])],
        'yg-info__row'        => ['s' => S(lay(['gap' => '9px']), pad(12, null, 12, null), line('bottom', '#f0f0f0'), ty('13px'))],
        'yg-info__label'      => ['s' => S(col('#999999'), ty('12px'))],
        'yg-info__row yg-info__label' => ['s' => ty('13px')],
        'yg-info__value'      => ['s' => S(col('#333333'), ty(null, 'bold'), ['align' => ['base' => 'right']])],
        'fx-info__title'      => ['s' => S(ty('20px'), pad(null, null, 14, null), mar(null, null, 6, null), line('bottom', '#eeeeee'))],
        'fx-info__block'      => ['s' => S(pad(12, null, 12, null), line('bottom', '#f0f0f0'))],
        'fx-info__val'        => ['s' => S(col('#333333'), ty('14px'), mar(4, null, null, null))],
        'yg-info yg-btn'      => ['s' => mar(20, null, null, null)],
        'yg-info yg-btn--dark' => ['s' => mar(10, null, null, null)],
        'yg-share'            => ['s' => S(lay(['gap' => '8px']), mar(null, null, null, 'auto')), 'm' => S(dim(['width' => '100%']), mar(7, 0, 0, 0))],
        'yg-share--card'      => ['s' => S(mar(21, 0, 0, 0), lay(['justify' => 'start']))],
        'yg-share__label'     => ['s' => S(mar(null, 4, null, null), col('#777777'), ty('13px'))],
        'yg-btn--block'       => ['props' => ['full_width' => true]],

        // ── Header ──
        'yg-brand'            => ['s' => dim(['width' => 132])],
        'fx-brand'            => [],
        'fx-head__end'        => ['s' => lay(['gap' => '14px', 'justify' => 'end'])],
        'fx-login'            => ['mhide' => true],
        'fx-nav'              => ['thide' => true, 'parts' => ['link' => S(pad(0, 15), col(INK), ['typography' => ['size' => '15px', 'weight' => 'normal', 'line_height' => '90px']]), 'link:hover' => col(PINK)]],
        'yg-nav'              => [],
        'yg-menu'             => ['s' => lay(['display' => 'flex', 'justify' => 'end']), 'parts' => ['trigger' => S(col(PINK), pad(4))]],
        'yg-drawer__brand'    => ['s' => S(dim(['width' => 130]), mar(null, null, 9, null))],
        'yg-drawer__tag'      => ['s' => S(col('#999999'), ty('13px'))],
        'yg-drawer__contact'  => ['s' => S(lay(['display' => 'grid', 'gap' => '9px']), col('#888888'), ty('13px'))],
        'yg-drawer__contact core.link' => ['s' => S(col('#888888'), ty('13px', '400'))],
        'yg-drawer__label'    => ['s' => S(mar(null, null, 4, null), col('#222222'), ty('15px', 'bold', null, null, SYNE))],
        'fx-top'              => ['s' => lay(['gap' => '12px']), 'm' => lay(['justify' => 'center'])],
        'fx-top__contact'     => ['s' => lay(['gap' => '22px']), 'mhide' => true],
        'fx-top__right'       => ['s' => lay(['gap' => '12px'])],
        'fx-top__item'        => ['s' => S(col('#d8d0d4'), ty(null, '400')), 'h' => col('#ffffff')],
        'fx-top__addr'        => ['thide' => true],
        'fx-top__member'      => ['s' => S(col('#ffffff'), ty(null, 'bold'), pad(4, 12), radius('999px'), bg(PINK))],
        'fx-social-ico'       => ['s' => S(tile(28, '50%', 'transparent', null), ['effects' => ['transition' => ['duration_ms' => 200]]]), 'h' => S(bg(PINK), col('#ffffff'))],
        'fx-social-ico--line' => ['s' => S(dim(['width' => '31px', 'height' => '31px']), outline('#eeeeee', '50%'), col(PINK))],
        'fx-foot__social fx-social-ico' => ['s' => S(dim(['width' => '34px', 'height' => '34px']), outline('#f0e6eb', '50%'), col(PINK))],

        // ── Footer ──
        'yg-foot__links'      => ['s' => S(lay(['gap' => '30px']), dim(['max_width' => '520px'])), 'm' => S(lay(['gap' => '15px']), dim(['max_width' => '100%']))],
        'yg-foot__title'      => ['s' => S(mar(null, null, 18, null), col('#ffffff'), ty('19px')), 'm' => ty('17px')],
        'yg-foot__nav'        => ['parts' => ['link' => S(col('rgba(255,255,255,.85)'), ['typography' => ['size' => '14px', 'line_height' => '23px']], pad(0), mar(10, 0, 10, 0)), 'link:hover' => col('#ffffff')]],
        'yg-foot__card'       => ['s' => S(pad(31, 31, 4, 31), radius('15px'), bg('#ffffff'), col('#777777'), shadow(0, 15, 40, 'rgba(86,0,50,.13)')),
                                  'm' => S(dim(['max_width' => '470px']), mar(null, 'auto', null, 'auto'), pad(25, 22, 4, 22))],
        'fx-foot__card'       => [],
        'yg-foot__logo'       => ['s' => S(dim(['width' => 110]), mar(null, null, 14, null))],
        'yg-foot__about'      => ['s' => S(mar(0, 0, 15, 0), col(BODY), ty('13px'))],
        'yg-foot__row'        => ['s' => S(lay(['gap' => '11px']), mar(11, 0, 11, 0), col('#777777'), ty('13px'))],
        'yg-foot__ico'        => ['s' => col(PINK), 'props' => ['size_px' => 16]],
        'yg-foot__line'       => ['s' => S(ty(null, '400'), col('#777777'))],
        'fx-foot__social'     => ['s' => S(lay(['gap' => '8px']), pad(12, 0, 24, 0))],
        'yg-foot__bar'        => ['s' => lay(['gap' => '20px']), 'm' => S(lay(['direction' => 'column', 'gap' => '6px', 'align' => 'center']))],

        // ── Long-form bodies ──
        'fx-rich'             => ['s' => S(col('#6f6a6d'), ty('15.5px', null, '1.85'), mar(0, 0, 18, 0))],
        'fx-body__h'          => ['s' => S(mar(30, 0, 12, 0), ty('26px')), 'm' => ty('22px')],
        'fx-body__h2'         => [],
        'fx-body__h3'         => ['s' => S(ty('20px'), mar(24, 0, 10, 0)), 'm' => ty('20px')],
        'fx-body__h4'         => ['s' => S(ty('20px'), mar(24, 0, 10, 0)), 'm' => ty('20px')],
        'fx-panel fx-body__h' => ['s' => mar(0, null, null, null)],
        'fx-body__img'        => ['s' => S(mar(6, 0, 24, 0), radius('14px'), dim(['overflow' => 'hidden', 'max_height' => '520px', 'object_fit' => 'cover']))],
        'fx-body__hero'       => ['s' => dim(['max_height' => '2000px']), 'props' => ['aspect_ratio' => '16:9']],
        'fx-body__btn'        => ['s' => mar(6, 0, 22, 0)],
        'fx-body__video'      => ['s' => S(mar(10, 0, 26, 0), radius('14px'), dim(['overflow' => 'hidden']))],
        'fx-course__title'    => ['s' => S(ty('32px'), mar(4, 0, 16, 0)), 'm' => ty('26px')],
        'fx-panel'            => ['s' => S(mar(10, 0, 18, 0), pad(24, 26), outline('#f0e6eb', '16px'), bg('#ffffff')), 'm' => pad(20, 18)],
        'fx-panel--soft'      => ['s' => S(bg('#fef4f9'), ['border' => ['color' => PINK_SOFT]])],
        'fx-panel__text'      => ['s' => S(col('#5f585c'), ty('15px', null, '1.7'))],
        'fx-cta fx-panel__text' => ['s' => S(col('#d9cfd4'), mar(null, null, 18, null))],
        'fx-cta'              => ['s' => S(mar(8, 0, 0, 0), pad(28, 26), radius('16px'), grad('linear-gradient(140deg,#1a1216,#0c0905)'), bg(INK), col('#ffffff'), lay(['align' => 'start'])), 'm' => pad(20, 18)],
        'fx-cta__title'       => ['s' => S(col('#ffffff'), ty('24px'), mar(2, 0, 8, 0))],
        'fx-product__photo'   => ['s' => S(dim(['object_fit' => 'cover', 'max_height' => '560px', 'overflow' => 'hidden'])), 'props' => ['aspect_ratio' => '1:1']],
        'fx-product__price'   => ['s' => S(ty('26px', 'bold', null, null, SYNE), col(PINK), mar(0, 0, 14, 0))],
        'fx-buy'              => ['s' => S(lay(['gap' => '12px', 'align' => 'center']), mar(6, null, null, null))],
        'fx-donate'           => ['s' => S(lay(['gap' => '14px', 'align' => 'center']), mar(10, null, null, null))],
        'fx-donate__alt'      => ['s' => S(mar(26, null, null, null), center())],
        'fx-order'            => [],
        'fx-info__list'       => [],

        // ── Support tiles ──
        'fx-tile'             => ['s' => S(lay(['align' => 'center']), pad(28, 20, 26, 20), outline('#f0e6eb', '14px'), bg('#ffffff'), dim(['width' => '100%']), ['effects' => ['transition' => ['duration_ms' => 200, 'easing' => 'ease']]]),
                                  // A tile keeps its own padding on a phone (yg-feature's phone padding is for the bare feature columns).
                                  'm' => pad(28, 20, 26, 20),
                                  'h' => S(['effects' => ['transform' => ['translate_y' => '-3px']]], shadow(0, 12, 28, 'rgba(120,0,70,.10)')), 'center' => true],
        'fx-tile__img'        => ['s' => S(dim(['width' => 84, 'height' => 84, 'object_fit' => 'contain']), pad(21), mar(0, 'auto', 18, 'auto'), radius('50%'), grad(PINK_GRAD), bg(PINK), shadow(0, 8, 20, 'rgba(233,0,138,.22)'))],
        'fx-tile yg-feature__title' => ['s' => ty('18px'), 'h' => col(PINK)],

        // ── Contact ──
        'yg-contact__card'    => [],
        'yg-contact__icon'    => ['s' => S(tile(60, '12px', PINK_SOFT), mar(3, 0, 14, 0)), 'props' => ['size_px' => 27]],
        'yg-contact__title'   => ['s' => S(pad(null, null, 18, null), mar(0, 0, 24, 0), line('bottom', '#dedede'), ty('23px')), 'm' => ty('19px')],
        'yg-contact__line'    => ['s' => S(mar(4, 0, 4, 0), col(BODY), ty('14px', '400', '1.6'))],

        // ── Newsletter ──
        'yg-news__icon'       => ['s' => tile(64, '17px', '#ffffff'), 'props' => ['size_px' => 28], 'm' => dim(['width' => '52px', 'height' => '52px'])],
        'yg-news__title'      => ['s' => S(ty('25px'), mar(null, null, 6, null)), 'm' => ty('20px')],
        'yg-news__text'       => ['s' => S(dim(['max_width' => '455px']), col(BODY), ty('14px', null, '1.45')), 'm' => ty('12px')],
        'yg-news__copy'       => [],

        // ── Galleries (tile look stays in gap.css) ──
        'yg-gallery'          => ['mp' => ['columns' => '2']],
        'fx-logos'            => ['tp' => ['columns' => '4'], 'mp' => ['columns' => '3']],

        // ── Query loops ──
        'fx-loop'             => ['parts' => ['card' => S(radius('14px'), shadow(0, 5, 23, 'rgba(24,16,37,.07)'), bg('#ffffff'))]],
    ];
}

/** Classes whose rules stay in gap.css (Studio has no native control for them); they keep their name. */
/** The second pass (PR #171): grid tracks, richer parts, parts per device, form and list props. */
function sz(array $s): array { return ['size' => array_map(static fn($x) => is_int($x) ? $x . 'px' : $x, $s)]; }
function fam(string $f, ?string $size = null, ?string $weight = null, ?string $lh = null): array { return ['typography' => array_filter(['family' => $f, 'size' => $size, 'weight' => $weight, 'line_height' => $lh], static fn($x) => $x !== null)]; }
function brd(string $color, string $w = '1px', ?string $r = null): array { return ['border' => array_filter(['width' => $w, 'style' => 'solid', 'color' => $color, 'radius' => $r], static fn($x) => $x !== null)]; }
/** A one-sided line (border.sides) on a part. */
function side(string $sides, string $color, string $w = '1px'): array { return ['border' => ['sides' => $sides, 'width' => $w, 'style' => 'solid', 'color' => $color]]; }
function fx(array $e): array { return ['effects' => $e]; }
const TRANS = ['transition' => ['duration_ms' => 200, 'easing' => 'ease']];

function native_more(): array
{
    $pill = S(sz(['height' => 60, 'min_width' => 169]), pad(0, 30), ['border' => ['width' => '0px', 'radius' => '999px']], lay(['display' => 'inline-flex', 'justify' => 'center', 'align' => 'center']),
        col('#ffffff'), fam('body', '16px', 'normal', '16px'), ['background' => ['gradient' => PINK_GRAD]], fx(TRANS));
    $pillPhone = S(sz(['height' => 52, 'min_width' => 140]), pad(0, 19), ty('14px'));
    $input = S(sz(['width' => '100%']), pad(14, 15), brd('#e8e8e8', '1px', '7px'), bg('#ffffff'), col('#444444'), fam('body', '14px'));
    $line = '#eeeeee';
    return [
        // ── Buttons ──
        'yg-btn'          => ['parts' => ['button' => $pill, 'button:hover' => fx(['transform' => ['translate_y' => '-2px'], 'filter' => ['saturate' => 107]])], 'mparts' => ['button' => $pillPhone]],
        'yg-btn--dark'    => ['parts' => ['button' => ['background' => ['gradient' => 'linear-gradient(140deg,#595959 0%,#0c0905 100%)']]]],
        'yg-btn--block'   => ['parts' => ['button' => sz(['width' => '100%', 'min_width' => '0px'])]],
        'fx-login'        => ['parts' => ['button' => S(sz(['height' => 44, 'min_width' => '0px']), pad(0, 20), ty('14px'))]],
        'fx-pay'          => ['parts' => ['button' => S($pill, sz(['height' => 56, 'min_width' => 150]), pad(0, 26), fam('body', '15px', 'bold'), fx(['cursor' => 'pointer']))]],
        'fx-buy yg-btn'   => ['m' => dim(['width' => '100%']), 'mparts' => ['button' => sz(['width' => '100%'])]],
        'fx-cta yg-btn'   => ['m' => dim(['width' => '100%']), 'mparts' => ['button' => sz(['width' => '100%'])]],

        // ── Grid tracks ──
        'yg-header'       => ['s' => S(lay(['tracks' => '1fr auto 1fr', 'align' => 'center']), dim(['height' => '90px'])), 't' => lay(['tracks' => '1fr auto']), 'm' => dim(['height' => '74px'])],
        'yg-blog-layout'  => ['s' => lay(['tracks' => 'minmax(0,2fr) minmax(0,1fr)', 'gap' => '60px', 'align' => 'start']), 't' => lay(['tracks' => 'minmax(0,1fr) 300px', 'gap' => '30px']), 'm' => lay(['tracks' => '1fr', 'gap' => '30px'])],
        'yg-detail'       => ['s' => lay(['tracks' => 'minmax(0,1.73fr) minmax(0,1fr)', 'gap' => '50px', 'align' => 'start']), 't' => lay(['tracks' => 'minmax(0,1.55fr) minmax(0,1fr)', 'gap' => '30px']), 'm' => lay(['tracks' => '1fr', 'gap' => '30px'])],
        'yg-contact'      => ['s' => lay(['tracks' => 'minmax(0,535px) minmax(0,565px)', 'justify' => 'between', 'column_gap' => '30px', 'row_gap' => '0px', 'align' => 'start']), 't' => lay(['tracks' => '1fr 1fr', 'column_gap' => '22px']), 'm' => lay(['tracks' => '1fr', 'gap' => '30px'])],
        'yg-contact__cards' => ['s' => lay(['tracks' => '284px 220px', 'gap' => '30px']), 't' => lay(['tracks' => '1fr 1fr', 'gap' => '22px']), 'm' => lay(['tracks' => '1fr', 'gap' => '30px'])],
        'yg-news'         => ['s' => lay(['tracks' => 'auto 1fr minmax(320px,.95fr)', 'align' => 'center', 'gap' => '22px']), 'm' => lay(['tracks' => '52px 1fr', 'gap' => '13px'])],
        'yg-foot'         => ['s' => lay(['tracks' => '1fr minmax(340px,420px)', 'gap' => '70px', 'align' => 'center']), 't' => lay(['gap' => '35px']), 'm' => lay(['tracks' => '1fr', 'gap' => '30px'])],

        // ── Forms ──
        'yg-contact__form' => ['props' => ['labels' => 'hidden'],
            's' => S(pad(75, 56, 41, 56), radius('11px'), bg('#ffffff'), shadow(0, 8, 32, 'rgba(20,10,40,.08)'), mar(-164, null, null, null), place('relative'), ['z_index' => 3], lay(['row_span' => 2])),
            'm' => S(mar(0, null, null, null), pad(25, 18), lay(['row_span' => 1])),
            'parts' => ['form' => lay(['display' => 'grid', 'gap' => '29px']), 'input' => S($input, sz(['height' => 53])), 'textarea' => sz(['height' => 148, 'min_height' => 148]),
                'actions' => mar(17, null, null, null), 'button' => S($pill, sz(['width' => 190, 'height' => 64]), fx(['cursor' => 'pointer']))],
            'mparts' => ['form' => lay(['gap' => '18px']), 'textarea' => sz(['height' => 125, 'min_height' => 125]), 'button' => sz(['width' => 170, 'height' => 54])]],
        'yg-news__form'   => ['props' => ['labels' => 'hidden', 'layout' => 'inline'], 'm' => lay(['column_span' => 'full']),
            'parts' => ['form' => S(pad(6), radius('999px'), bg('#ffffff'), shadow(0, 8, 24, 'rgba(90,0,55,.05)'), lay(['gap' => '0px'])),
                'input' => S(sz(['width' => '100%', 'height' => 48]), ['border' => ['width' => '0px']], pad(0, 16), col('#555555'), bg('transparent'), fam('body', '14px')),
                'button' => S($pill, sz(['height' => 48, 'min_width' => '0px']), pad(0, 25), fam('body', '14px', 'bold'), fx(['cursor' => 'pointer']))]],

        // ── Galleries ──
        'yg-gallery'      => ['parts' => ['grid' => lay(['gap' => '18px']), 'item' => S(sz(['height' => 245, 'overflow' => 'hidden']), radius('13px'), bg('#eeeeee'), mar(0)),
                'image' => S(sz(['width' => '100%', 'height' => '100%', 'object_fit' => 'cover']), fx(['transition' => ['duration_ms' => 300, 'easing' => 'ease']])),
                'image:hover' => fx(['transform' => ['scale' => 1.04]])],
            'mparts' => ['grid' => lay(['gap' => '10px']), 'item' => sz(['height' => 155])]],
        'fx-body__gallery' => ['s' => mar(8, 0, 26, 0), 'parts' => ['item' => sz(['height' => 'auto'])], 'mparts' => ['item' => sz(['height' => 'auto'])]],
        'fx-product__gallery' => ['parts' => ['item' => S(sz(['height' => 'auto']), bg('#f6f6f6'))], 'mparts' => ['item' => sz(['height' => 'auto'])]],
        'fx-logos'        => ['parts' => ['grid' => lay(['gap' => '22px', 'align' => 'center']),
                'item' => S(sz(['height' => 96]), lay(['display' => 'flex', 'justify' => 'center', 'align' => 'center']), pad(14), brd('#f0e6eb', '1px', '12px'), bg('#ffffff')),
                'image' => S(sz(['max_height' => '68px', 'width' => 'auto', 'height' => 'auto', 'object_fit' => 'contain']), ['opacity' => 75], fx(['filter' => ['grayscale' => 100], 'transition' => ['duration_ms' => 200, 'easing' => 'ease']])),
                'image:hover' => S(['opacity' => 100], fx(['filter' => ['grayscale' => 0]]))],
            'mparts' => ['grid' => lay(['gap' => '10px'])]],

        // ── Lists ──
        'yg-checks'       => ['props' => ['columns' => 2], 'mp' => ['columns' => 1],
            'parts' => ['list' => S(mar(19, 0, 24, 0), lay(['row_gap' => '13px', 'column_gap' => '20px'])), 'item' => S(col('#777777'), ty('14px', null, '22px')), 'marker' => col(PINK)]],
        'fx-benefits'     => ['parts' => ['list' => mar(6, 0, 0, 0), 'item' => S(col('#4f494c'), ty('14.5px'))]],
        'yg-meta'         => ['props' => ['layout' => 'inline', 'divider' => true],
            'parts' => ['list' => S(mar(0, 0, 12, 0), lay(['gap' => '13px'])), 'item' => S(col('#8a8287'), ty('12px', null, '19px'))]],

        // ── Menus ──
        'yg-nav'          => ['parts' => ['list' => lay(['display' => 'flex', 'gap' => '0px']), 'link' => S(lay(['display' => 'flex', 'align' => 'center']), fx(TRANS)), 'current' => col(PINK)]],
        'yg-drawer__nav'  => ['parts' => ['list' => S(lay(['display' => 'grid']), mar(26, 0, 26, 0), side('top', '#eeeeee')), 'link' => S(lay(['display' => 'block']), pad(11, 0), side('bottom', '#eeeeee'), col('#222222'), fam('heading', '15px', 'bold', '20px')), 'link:hover' => col(PINK)]],
        'fx-drawer__more' => ['parts' => ['list' => S(mar(-26, null, null, null), ['border' => ['width' => '0px']])]],
        'yg-foot__nav'    => ['parts' => ['list' => lay(['display' => 'block']), 'link' => S(lay(['display' => 'block']), fx(['transition' => ['duration_ms' => 180]]))], 'mparts' => ['link' => ty('13px')]],
        'fx-side-nav'     => ['parts' => ['list' => mar(6, 0, 0, 0), 'link' => S(lay(['display' => 'block']), pad(10, 0), side('bottom', '#f0f0f0'), col('#333333'), fam('heading', '14px', 'bold')), 'link:hover' => col(PINK), 'current' => col(PINK)]],

        // ── Offcanvas ──
        'yg-menu'         => ['parts' => ['trigger' => S(sz(['width' => 34, 'height' => 34]), lay(['justify' => 'center'])), 'backdrop' => bg('rgba(15,8,23,.42)'),
                'panel' => S(sz(['width' => 'min(390px,88vw)', 'max_width' => 'none']), pad(0), shadow(-10, 0, 35, 'rgba(20,10,35,.12)')),
                'close' => S(sz(['width' => 36, 'height' => 36]), radius('50%'), bg('#fdeaf4'), col(PINK), ty('25px', null, '36px'), lay(['display' => 'flex', 'justify' => 'center', 'align' => 'center']), ['typography' => ['decoration' => 'none']]),
                'header' => S(['position' => ['mode' => 'absolute', 'top' => '17px', 'right' => '22px']], pad(0)),
                'body' => pad(42, 34)]],

        // ── Icon link ──
        'yg-play'         => ['parts' => ['link' => S(sz(['width' => 66, 'height' => 66]), lay(['display' => 'flex', 'justify' => 'center', 'align' => 'center']), brd('rgba(255,255,255,.92)', '7px', '50%'),
                ['background' => ['gradient' => PINK_GRAD]], col('#ffffff'), shadow(0, 5, 24, 'rgba(31,17,43,.14)'), fx(TRANS)), 'link:hover' => fx(['transform' => ['scale' => 1.06]]), 'svg' => mar(null, null, null, 3)],
            'mparts' => ['link' => sz(['width' => 58, 'height' => 58])]],

        // The hero video's play icon: the same pink button the icon link had.
        'yg-hero__video'  => ['parts' => ['play' => S(sz(['width' => 66, 'height' => 66]), brd('rgba(255,255,255,.92)', '7px', '50%'),
                ['background' => ['gradient' => PINK_GRAD]], col('#ffffff'), shadow(0, 5, 24, 'rgba(31,17,43,.14)'), fx(TRANS)), 'play:hover' => fx(['transform' => ['scale' => 1.06]]),
                'svg' => S(sz(['width' => 22, 'height' => 22]), mar(null, null, null, 3))],
            'mparts' => ['play' => sz(['width' => 58, 'height' => 58])]],

        // ── Rich text ──
        'fx-rich'         => ['parts' => ['paragraph' => mar(0, 0, 14, 0), 'list' => S(mar(0, 0, 16, 0), pad(null, null, null, 22)), 'item' => mar(6, 0, 6, 0), 'marker' => col(PINK),
                'link' => S(col(PINK), ['typography' => ['decoration' => 'underline']]), 'bold' => col(INK)]],

        // ── Query loops ──
        // A row that tints as a whole: no lift or shadow, which would need room the sidebar does not have.
        'fx-recent'       => ['parts' => ['grid' => lay(['display' => 'grid', 'gap' => '6px']),
                'card' => S(pad(14, 16), ['border' => ['width' => '0px', 'radius' => '14px']], bg('transparent'), shadow(0, 0, 0, 'transparent'), fx(['transition' => ['duration_ms' => 220, 'easing' => 'ease']])),
                'card:hover' => S(bg('#fff0f7'), shadow(0, 0, 0, 'transparent'), fx(['transform' => ['translate_x' => '4px']])),
                'body' => pad(0), 'button_row' => lay(['display' => 'none']), 'button' => lay(['display' => 'none']),
                'title' => S(mar(0, 0, 6, 0), fam('heading', '14px', 'bold', '20px'), col('#333333')), 'card_title' => col(PINK_DEEP), 'meta' => S(mar(0, 0, 0, 0), ty('12px'), col('#8a7f85'))]],
        'yg-post-meta'    => ['parts' => ['meta' => lay(['display' => 'flex', 'justify' => 'center', 'align' => 'center', 'gap' => '15px']), 'item:not_first' => S(side('left', '#dddddd'), pad(null, null, null, 15))]],
        'fx-info__val'    => ['parts' => ['paragraph' => mar(0)]],
        'fx-info__list'   => ['parts' => ['list' => S(mar(4, 0, 0, 0), pad(null, null, null, 18))]],
        'fx-loop'         => ['parts' => ['card' => sz(['overflow' => 'hidden'])]],

        // ── Map, highlight ──
        'yg-map'          => ['parts' => ['frame' => S(sz(['height' => 440]), radius('13px'), bg('#eeeeee'))], 'mparts' => ['frame' => sz(['height' => 300])]],
        'fx-banner--photo yg-banner__title' => ['parts' => ['highlight' => col('#ff9ad2')]],
        // ── Shop (plugins/shop widgets) ──
        // The Product Grid as the site's own cards: the photo, then a white card over its foot with the pink heart tile,
        // the name, the price as the card's text and "Acheter →".
        'fx-shop-grid'    => ['parts' => [
                'grid'  => lay(['gap' => '28px']),
                'card'  => S(place('relative'), sz(['min_width' => '0px'])),
                'image' => S(sz(['width' => '100%', 'height' => 255, 'overflow' => 'hidden']), radius('15px'), bg('#eeeeee')),
                'body'  => S(place('relative'), mar(-35, 13, 0, 13), pad(24, 22, 23, 22), radius('13px'), bg('#ffffff'), shadow(0, 8, 28, 'rgba(18,10,34,.08)'), lay(['gap' => '0px'])),
                'icon'  => S(sz(['width' => 39, 'height' => 39]), radius('10px'), bg(PINK_SOFT), col(PINK), mar(-44, null, 16, null)),
                'title' => S(fam('heading', '21px', null, '25px'), mar(0, 0, 9, 0), col(INK)),
                'title:hover' => col(PINK),
                'price' => S(mar(0, 0, 13, 0), col(BODY), fam('body', '14.5px', 'normal', '24px')),
                'link'  => S(col(PINK), fam('body', '14px', 'bold')),
                'badge' => S(bg(PINK), col('#ffffff')),
            ],
            'tparts' => ['grid' => lay(['gap' => '18px'])],
            'mparts' => ['grid' => lay(['gap' => '25px']), 'image' => sz(['height' => 230])]],
        // The product page: two columns, the summary stacked; the title is the banner's.
        'fx-shop-detail'  => ['s' => lay(['tracks' => 'minmax(0,1fr) minmax(0,1fr)', 'gap' => '60px', 'align' => 'start']), 't' => lay(['gap' => '30px']), 'm' => lay(['tracks' => '1fr', 'gap' => '30px'])],
        'fx-shop-summary' => ['s' => lay(['display' => 'flex', 'direction' => 'column', 'gap' => '18px'])],
        'fx-shop-title'   => ['s' => S(dim(['max_width' => '900px']), ty('clamp(34px,4vw,50px)', null, '1.16', '-.025em')), 'm' => ty('31px')],
        'fx-shop-text'    => ['s' => S(col(BODY), ty('16px', null, '1.65'))],
        // The mini cart in the header: "Panier" and a pink count.
        'fx-mini-cart'    => ['parts' => [
                'link'  => S(lay(['display' => 'inline-flex', 'align' => 'center', 'gap' => '8px']), col(INK), fam('body', '15px', 'normal')),
                'link:hover' => col(PINK),
                'count' => S(sz(['min_width' => 22, 'height' => 22]), pad(0, 6), radius('999px'), bg(PINK), col('#ffffff'), fam('body', '12px', 'bold', '22px'), lay(['display' => 'inline-flex', 'justify' => 'center'])),
            ]],
        // The product page: price, options, quantity and the pink pill button.
        'fx-shop-price'   => ['parts' => ['regular' => S(col(INK), fam('heading', '28px', 'bold')), 'sale' => S(col(PINK), fam('heading', '28px', 'bold')), 'vat' => S(col('#999999'), fam('body', '13px'))]],
        'fx-shop-add'     => ['parts' => [
                'options' => S($input, sz(['height' => 50, 'max_width' => '100%'])),
                'option_labels' => S(col(INK), fam('body', '14px', 'bold')),
                'variant_price' => S(col(PINK), fam('heading', '20px', 'bold')),
                'quantity' => S($input, sz(['width' => 80, 'height' => 56])),
                'button' => S($pill, sz(['height' => 56]), fx(['cursor' => 'pointer'])),
            ], 'mparts' => ['button' => $pillPhone]],
        'fx-shop-stock'   => ['parts' => ['in' => S(col('#1f8a4c'), fam('body', '14px', 'bold')), 'low' => S(col('#b45309'), fam('body', '14px', 'bold')), 'out' => S(col(PINK), fam('body', '14px', 'bold'))]],
        'fx-shop-gallery' => ['parts' => ['main' => radius('15px'), 'thumbs' => radius('10px')]],
        'fx-shop-meta'    => ['parts' => ['label' => S(col(INK), fam('body', '13px', 'bold')), 'value' => S(col(BODY), fam('body', '13px'))]],
        // Cart, checkout and the order: the site's inputs, card and pink pill.
        'fx-shop-cart'    => ['parts' => ['title' => S(col(INK), fam('heading', '17px', 'bold')), 'options' => col(BODY),
                'totals' => S(pad(24), radius('14px'), bg('#ffffff'), shadow(0, 8, 30, 'rgba(22,12,39,.1)')), 'button' => S($pill, fx(['cursor' => 'pointer'])),
                'continue' => S($pill, ['background' => ['gradient' => 'linear-gradient(140deg,#595959 0%,#0c0905 100%)']], fx(['cursor' => 'pointer']))]],
        'fx-shop-checkout' => ['parts' => ['fields' => S($input, sz(['height' => 50])), 'labels' => S(col(INK), fam('body', '14px', 'bold')),
                'summary' => S(pad(26), radius('14px'), bg('#ffffff'), shadow(0, 8, 30, 'rgba(22,12,39,.1)')), 'button' => S($pill, sz(['width' => '100%']), fx(['cursor' => 'pointer']))]],
        'fx-shop-order'   => ['parts' => ['lead' => S(col(INK), fam('body', '17px')), 'total' => S(col(INK), fam('heading', '20px', 'bold'))]],
    ];
}

/** Classes with rules still in gap.css: none — the design is native. */
const GAP = [];

/** Merge one fragment into a block (or section container). */
function native_merge(array &$b, array $f): void
{
    if (isset($f['s'])) { $b['style'] = array_replace_recursive($b['style'] ?? [], $f['s']); }
    if (isset($f['h'])) { $b['style_states']['hover'] = array_replace_recursive($b['style_states']['hover'] ?? [], $f['h']); }
    if (isset($f['parts'])) { $b['style_parts'] = array_replace_recursive($b['style_parts'] ?? [], $f['parts']); }
    foreach (['tparts' => 'tablet', 'mparts' => 'mobile'] as $k => $device) {
        if (isset($f[$k])) { $b['responsive'][$device]['style_parts'] = array_replace_recursive($b['responsive'][$device]['style_parts'] ?? [], $f[$k]); }
    }
    if (isset($f['props'])) { $b['props'] = array_replace($b['props'], $f['props']); }
    // Motion: `anim` plays as the page loads, `reveal` as the block scrolls into view.
    if (isset($f['anim'])) { $b['animation'] = $f['anim']; }
    if (isset($f['reveal'])) { $b['interactions'] = ['trigger' => 'viewport-enter', 'animation' => $f['reveal']]; }
    foreach (['t' => 'tablet', 'm' => 'mobile'] as $k => $device) {
        if (isset($f[$k])) { $b['responsive'][$device]['style'] = array_replace_recursive($b['responsive'][$device]['style'] ?? [], $f[$k]); }
        if (isset($f[$k . 'p'])) { $b['responsive'][$device]['props'] = array_replace($b['responsive'][$device]['props'] ?? [], $f[$k . 'p']); }
        if (!empty($f[$k . 'hide'])) { $b['responsive'][$device]['hide'] = true; }
    }
    // A device's hide covers that device's range only: what the design hid below 1050px is hidden on phones too.
    if (!empty($f['thide'])) { $b['responsive']['mobile']['hide'] = true; }
}

/** @var array<string, int> class => blocks it was applied to; filled by native_apply for the report */
$NATIVE_USED = [];

/**
 * Apply the map to a block tree. `$anc` is the classes of every block (and the section) above. A block inside a centred
 * context is centred; a class that has an entry and is not a GAP class is dropped from the block.
 */
function native_apply(array &$b, array $anc, bool $centred = false): void
{
    global $NATIVE_USED;
    static $map = null;
    $map ??= native_map();
    $cls = $b['classNames'] ?? [];
    if ($centred) { $b['style']['align'] = ['base' => 'center']; }
    $centreKids = $centred;
    foreach ($cls as $c) {
        if (isset($map[$c])) {
            native_merge($b, $map[$c]);
            $centreKids = $centreKids || !empty($map[$c]['center']);
            $NATIVE_USED[$c] = ($NATIVE_USED[$c] ?? 0) + 1;
        }
        foreach ($anc as $a) {
            if (isset($map["{$a} {$c}"])) { native_merge($b, $map["{$a} {$c}"]); }
        }
    }
    foreach ($anc as $a) {
        if (isset($map["{$a} {$b['type']}"])) { native_merge($b, $map["{$a} {$b['type']}"]); }
    }
    if (!empty($b['style']['align']) && $centreKids && !$centred) { $b['style']['align'] = ['base' => 'center']; }
    $keep = array_values(array_filter($cls, static fn(string $c): bool => !isset($map[$c]) || in_array($c, GAP, true)));
    $down = array_merge($anc, $cls);
    foreach ($b['children'] as &$child) { native_apply($child, $down, $centreKids); }
    unset($child);
    // `stagger` (cards to a row): the children fade up in turn, each row starting over, and the block itself stays still.
    $rows = 0;
    foreach ($cls as $c) { $rows = max($rows, (int) ($map[$c]['stagger'] ?? 0)); }
    if ($rows > 0) {
        foreach ($b['children'] as $i => &$child) {
            $child['interactions'] = ['trigger' => 'viewport-enter', 'animation' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => ($i % $rows) * 120, 'easing' => EASE]];
        }
        unset($child);
    }
    if ($keep) { $b['classNames'] = $keep; } else { unset($b['classNames']); }
}
