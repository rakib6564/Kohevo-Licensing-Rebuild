<?php
/**
 * FightxLife V2 — the home page of the V2 content pack (« 1. Accueil », October 2026) in the upgraded design, as a
 * preview for the client: the live site's character (handwritten pink kickers, deep soft shadows, scroll animations,
 * the wave newsletter and the white contact card in the footer) with a cleaner, more modern finish.
 *
 * It builds three things and touches nothing else:
 *   - the page /accueil-v2/ (noindex);
 *   - a header and a footer whose display condition is that page's slug, so only the preview shows them;
 *   - the menus they name (v2-*), and the Homemade Apple font in the head snippet.
 *
 *   cd <site> && php .../build/v2.php            the preview: V2 at "<slug>-v2", the current site untouched
 *   cd <site> && php .../build/v2.php --live     the switch: V2 at the real addresses, the 301 plan installed
 *
 * The switch deletes nothing. Each current page whose address V2 takes moves to "<slug>-v1" (the home page to
 * /accueil-v1/) and keeps its header and footer there; the V2 header and footer show everywhere else. Running without
 * --live again moves every page back, so the switch can be undone. The pages are renamed through Studio, keeping their
 * revisions, and V2's page ids are kept in the `fxl_v2_pages` setting so both directions find them.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/v2-pages.php'; // the other V2 pages: their styles (native_v2_pages) and the page engine

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

define('V2_LIVE', in_array('--live', $argv ?? [], true));
define('V2_SLUG', V2_LIVE ? 'accueil' : 'accueil-v2');
/** Where the logo goes: the home page. */
define('V2_HOME_URL', V2_LIVE ? '/' : '/accueil-v2/');
const V2_MEMBRE = 'https://membre.fightxlife.com/connexion/';
const V2_LOGO = '2022-05-cropped-logo-fxl-01-e1638355780533-1.png';
const PINK_GRAD_HOVER = 'linear-gradient(140deg,#e9008a 0%,#a30062 100%)';
const SCRIPT = 'Homemade Apple, cursive';
const STENCIL = 'Saira Stencil One, sans-serif';
const INK_SOFT = '#1b1418';
/** The design's lifted-card shadow: the live site's deep, soft drop, warmed toward the brand pink. */
const LIFT = [0, 22, 60, 'rgba(70,10,48,.13)', -8];
const EASE = 'cubic-bezier(.22,1,.36,1)';

function V2IMG(string $file, string $alt, string $cls = '', array $extra = []): array
{
    $id = MID('/uploads/media/fxl/' . $file);
    if ($id <= 0) { throw new RuntimeException("media {$file} missing"); }
    return B('core.image', array_replace(['media' => ['media_id' => $id, 'alt' => $alt, 'focal_point' => [0.5, 0.5]], 'aspect_ratio' => 'auto', 'rounded' => false], $extra), [], $cls);
}
/** The handwritten pink kicker above a title, as on the live site. */
function KICK(string $text = 'FightxLife', string $cls = 'v2-kick'): array { return T($text, $cls); }
/** A section title: kicker, heading (its highlighted words pink) and an optional lead. */
function V2HEAD(string $heading, string $hl, array $lead = [], string $align = 'center', string $kicker = 'FightxLife'): array
{
    $kids = [KICK($kicker), H($heading, 'h2', 'v2-h2', $hl)];
    foreach ($lead as $p) { $kids[] = T($p, 'v2-lead'); }
    return BOX($kids, 'v2-head v2-head--' . $align);
}
function MORE(string $label, string $href): array { return LNK($label . ' →', $href, 'v2-more'); }
function V2BTN(string $label, string $href, string $cls = 'v2-btn'): array { return B('core.button', ['link' => L($label, $href), 'variant' => 'primary'], [], $cls); }
function ICO(string $name, string $cls): array { return B('core.icon', ['name' => $name, 'size' => 'md', 'size_px' => 22], [], $cls); }
function V2SOCIAL(string $icon, string $url, string $label, string $cls): array { return B('core.icon', ['name' => $icon, 'url' => $url, 'new_tab' => true, 'label' => $label, 'size_px' => 16], [], $cls); }

/** The V2 design as native style (see native.php for the fragment keys). */
function native_v2(): array
{
    $base = S(sz(['height' => 56, 'min_width' => '0px']), pad(0, 28), ['border' => ['width' => '0px', 'radius' => '999px']], lay(['display' => 'inline-flex', 'justify' => 'center', 'align' => 'center']),
        fam('body', '15px', 'bold', '16px'), fx(['transition' => ['duration_ms' => 250, 'easing' => 'ease']]));
    $pill = S($base, col('#ffffff'), ['background' => ['gradient' => PINK_GRAD]], shadow(0, 12, 28, 'rgba(233,0,138,.28)', -6));
    $pillHover = S(['background' => ['gradient' => PINK_GRAD_HOVER]], fx(['transform' => ['translate_y' => '-3px']]), shadow(0, 18, 34, 'rgba(233,0,138,.34)', -6));
    // The donate buttons: an outline at rest, the brand fill on hover and keyboard focus. 19px bold is large text, which #e9008a passes on white both ways round.
    $outline = S($base, brd(PINK, '2px', '999px'), bg('transparent'), col(PINK), ty('19px'));
    $outlineFocus = S(bg(PINK), col('#ffffff'));
    $outlineHover = S($outlineFocus, $pillHover);
    $lift = shadow(...LIFT);
    $up = static fn(int $delay = 0): array => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => $delay, 'easing' => EASE];
    $V = static fn(int $t, int $b): array => pad($t, null, $b, null);
    return native_v2_pages($pill, $pillHover, $base, $up, $V, $outline, $outlineHover, $outlineFocus) + [
        // ── Type ──
        'v2-kick'        => ['s' => S(col(PINK), ty('19px', '400', '1.5', null, SCRIPT), mar(0, null, 6, null))],
        'v2-kick--light' => ['s' => col('#ff7cc3')],
        'v2-h2'          => ['s' => S(ty('clamp(30px,3.2vw,44px)', 'bold', '1.14', '-.025em'), mar(0, null, 22, null)), 'm' => ty('29px'), 'parts' => ['highlight' => col(PINK)]],
        'v2-lead'        => ['s' => S(col(BODY), ty('16.5px', null, '1.75'), mar(0, null, 18, null)), 'm' => ty('15.5px')],
        'v2-head'        => ['s' => mar(0, null, 64, null), 'm' => mar(0, null, 40, null), 'reveal' => $up(0)],
        'v2-head--center' => ['s' => S(dim(['max_width' => '780px']), mar(null, 'auto', null, 'auto')), 'center' => true],
        'v2-more'        => ['s' => S(col(PINK), ty('15px', 'bold'), fx(TRANS)), 'h' => fx(['transform' => ['translate_x' => '4px']])],
        'v2-dark v2-h2'  => ['s' => col('#ffffff')],
        'v2-dark v2-lead' => ['s' => col('rgba(255,255,255,.72)')],
        'v2-dark v2-kick' => ['s' => col('#ff7cc3')],

        // ── Buttons ──
        'v2-btn'         => ['parts' => ['button' => $pill, 'button:hover' => $pillHover], 'mparts' => ['button' => S(sz(['height' => 52]), pad(0, 22), ty('14.5px'))]],
        'v2-ghost'       => ['parts' => ['button' => S($base, bg('#ffffff'), col(INK), brd('#eadde4', '1.5px', '999px'), shadow(0, 8, 20, 'rgba(40,10,30,.06)', -6)),
                'button:hover' => S(col(PINK), ['border' => ['color' => PINK]], fx(['transform' => ['translate_y' => '-3px']]))], 'mparts' => ['button' => S(sz(['height' => 52]), pad(0, 22), ty('14.5px'))]],
        'v2-btn--block'  => ['s' => dim(['width' => '100%']), 'parts' => ['button' => sz(['width' => '100%'])]],
        'v2-actions'     => ['s' => S(lay(['gap' => '16px']), mar(34, null, 0, null)), 'm' => lay(['gap' => '12px'])],

        // ── Hero ──
        'v2-hero-sec'    => ['s' => S(grad('linear-gradient(180deg,#fff7fb 0%,#ffffff 78%)'), $V(90, 150), dim(['overflow' => 'hidden'])), 't' => $V(64, 116), 'm' => $V(44, 88)],
        'v2-hero'        => ['s' => lay(['tracks' => 'minmax(0,1.04fr) minmax(0,.96fr)', 'gap' => '96px', 'align' => 'center']), 't' => lay(['tracks' => '1fr', 'gap' => '64px']), 'm' => lay(['gap' => '52px'])],
        'v2-badge'       => ['s' => S(lay(['display' => 'inline-flex', 'align' => 'center', 'gap' => '9px']), dim(['width' => 'fit-content']), pad(8, 16, 8, 8), radius('999px'), bg('#ffffff'), col(INK), ty('13.5px', 'bold', '1.3'), shadow(0, 10, 26, 'rgba(70,10,48,.08)'), mar(0, null, 22, null)),
                              'anim' => ['type' => 'fade_up', 'duration_ms' => 700, 'easing' => EASE]],
        'v2-badge__dot'  => ['s' => S(tile(26, '50%', PINK_SOFT), col(PINK)), 'props' => ['size_px' => 14]],
        'v2-hero__title' => ['s' => S(ty('clamp(38px,4.4vw,62px)', 'bold', '1.06', '-.035em'), mar(0, null, 28, null)), 'm' => ty('34px'), 'parts' => ['highlight' => col(PINK)],
                              'anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 80, 'easing' => EASE]],
        'v2-hero__lead'  => ['s' => S(col(BODY), ty('17.5px', null, '1.75'), dim(['max_width' => '560px'])), 'm' => ty('16px'),
                              'anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 160, 'easing' => EASE]],
        'v2-hero__copy v2-actions' => ['anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 240, 'easing' => EASE]],
        'v2-hero__ticks' => ['s' => S(lay(['display' => 'flex', 'gap' => '28px']), mar(38, null, 0, null)), 'm' => lay(['gap' => '10px', 'direction' => 'column']),
                              'anim' => ['type' => 'fade_in', 'duration_ms' => 900, 'delay_ms' => 380, 'easing' => EASE]],
        'v2-tick'        => ['s' => S(lay(['display' => 'flex', 'align' => 'center', 'gap' => '8px']), col(INK), ty('14px', 'bold'))],
        'v2-tick__ico'   => ['s' => col(PINK), 'props' => ['size_px' => 18]],
        'v2-hero__art'   => ['s' => S(place('relative'), pad(0, 0, 40, 40)), 'm' => pad(0, 0, 30, 18),
                              'anim' => ['type' => 'fade_up', 'duration_ms' => 1000, 'delay_ms' => 120, 'easing' => EASE]],
        'v2-hero__main'  => ['s' => S(dim(['height' => '540px', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('30px'), shadow(...LIFT)), 't' => dim(['height' => '460px']), 'm' => dim(['height' => '360px'])],
        'v2-hero__small' => ['s' => S(place('absolute', ['left' => 0, 'bottom' => 0]), dim(['width' => '210px', 'height' => '210px', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('24px'),
                                  brd('#ffffff', '7px', '24px'), shadow(0, 20, 40, 'rgba(70,10,48,.18)')), 'm' => dim(['width' => '130px', 'height' => '130px'])],
        'v2-hero__quote' => ['s' => S(place('absolute', ['top' => 34, 'right' => 0]), dim(['max_width' => '270px']), pad(18, 20), radius('18px'), bg('#ffffff'), shadow(0, 18, 40, 'rgba(70,10,48,.14)')),
                              'mhide' => true],
        'v2-hero__qtext' => ['s' => S(col(INK), ty('14px', null, '1.55')), 'm' => ty('12.5px')],
        'v2-hero__qname' => ['s' => S(col(PINK), ty('18px', null, '1.4', null, SCRIPT), mar(8, null, 0, null)), 'm' => ty('15px')],

        // ── Intro card (the page's second and third paragraphs) ──
        'v2-force-sec'   => ['s' => S(bg('#ffffff'), $V(70, 80), dim(['overflow' => 'hidden'])), 'm' => $V(48, 48)],
        'v2-force__kick' => ['center' => true, 's' => mar(0, null, 0, null)],
        'v2-force__marquee' => ['s' => S(col(INK), ty('clamp(44px,7vw,92px)', '400', '1.2', null, STENCIL), ['typography' => ['transform' => 'uppercase']], mar(0, null, 48, null)), 'm' => S(ty('40px'), mar(0, null, 32, null))],
        'v2-force__video' => ['s' => S(radius('26px'), dim(['overflow' => 'hidden']), $lift), 'reveal' => $up(0)],
        'v2-intro-sec'   => ['s' => S(bg('#ffffff'), $V(0, 60)), 'm' => $V(0, 36)],
        'v2-intro'       => ['s' => S(mar(-70, null, 0, null), place('relative'), pad(60, 64), radius('26px'), bg('#ffffff'), $lift, lay(['tracks' => 'minmax(0,1fr) minmax(0,1fr)', 'gap' => '64px'])),
                              't' => pad(36, 34), 'm' => S(lay(['tracks' => '1fr', 'gap' => '6px']), pad(26, 22), mar(-50, null, null, null)), 'reveal' => $up()],
        'v2-intro__text' => ['s' => S(col(BODY), ty('16px', null, '1.8'))],

        // ── Three axes ──
        'v2-axes-sec'    => ['s' => S(bg('#ffffff'), $V(130, 130)), 'm' => $V(80, 80)],
        'v2-cards'       => ['s' => lay(['gap' => '38px', 'align' => 'stretch']), 'props' => ['columns' => 3], 't' => lay(['gap' => '24px']), 'mp' => ['columns' => 1], 'm' => lay(['gap' => '32px'])],
        'v2-card'        => ['s' => S(place('relative'), radius('24px'), bg('#ffffff'), $lift, fx(['transition' => ['duration_ms' => 350, 'easing' => 'ease']])),
                              'h' => S(fx(['transform' => ['translate_y' => '-8px']]), shadow(0, 30, 70, 'rgba(70,10,48,.18)', -8))],
        'v2-card--1'     => ['reveal' => $up(0)], 'v2-card--2' => ['reveal' => $up(120)], 'v2-card--3' => ['reveal' => $up(240)],
        'v2-card__photo' => ['s' => S(place('relative'), dim(['height' => '230px', 'overflow' => 'hidden']), top_radius('24px'))],
        'v2-card__img'   => zoom(1.06, 600, dim(['height' => '100%', 'object_fit' => 'cover'])),
        'v2-card__num'   => ['s' => S(place('absolute', ['top' => 18, 'left' => 18]), dim(['height' => '36px']), pad(0, 14), radius('999px'), bg('rgba(255,255,255,.92)'), col(PINK), ty('13px', 'bold', null, '.06em', SYNE), flexc())],
        'v2-card__body'  => ['s' => pad(36, 34, 38, 34), 'm' => pad(28, 24, 30, 24)],
        'v2-card__icon'  => ['s' => S(tile(52, '16px', PINK_SOFT), mar(-58, null, 18, null), place('relative'), shadow(0, 10, 22, 'rgba(233,0,138,.16)')), 'props' => ['size_px' => 24]],
        'v2-card__title' => ['s' => S(ty('23px', 'bold', '1.25', '-.015em'), mar(0, null, 16, null))],
        'v2-card__text'  => ['s' => S(col(BODY), ty('15px', null, '1.7'), mar(0, null, 16, null))],

        // ── Projects (dark) ──
        'v2-dark'        => ['s' => S(grad('radial-gradient(1200px 500px at 85% 0%,rgba(233,0,138,.28),transparent 60%),linear-gradient(180deg,#140d12 0%,#0c0905 100%)'), col('#ffffff'), $V(140, 150)), 'm' => $V(84, 90)],
        'v2-bento'       => ['s' => lay(['tracks' => 'minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)', 'gap' => '34px', 'align' => 'stretch']), 't' => lay(['tracks' => '1fr 1fr', 'gap' => '24px']), 'm' => lay(['tracks' => '1fr', 'gap' => '20px'])],
        'v2-quote'       => ['s' => S(place('relative'), pad(48, 42, 39, 42), radius('24px'), grad('linear-gradient(150deg,#ff3fa6 0%,#e9008a 55%,#b0006a 100%)'), shadow(0, 30, 60, 'rgba(233,0,138,.32)', -10), lay(['justify' => 'between'])),
                              't' => lay(['column_span' => 'full']), 'm' => pad(30, 24), 'reveal' => $up(0)],
        'v2-quote__mark' => ['s' => S(col('rgba(255,255,255,.35)'), ty('110px', 'bold', '.8', null, SYNE), mar(0, null, -6, null))],
        'v2-quote__title' => ['s' => S(col('#ffffff'), ty('15px', 'bold', null, '.1em'), ['typography' => ['transform' => 'uppercase']], mar(0, null, 14, null))],
        'v2-quote__text' => ['s' => S(col('#ffffff'), ty('24px', 'bold', '1.35', '-.01em', SYNE)), 'm' => ty('20px')],
        'v2-quote__name' => ['s' => S(col('#ffffff'), ty('20px', null, '1.4', null, SCRIPT), mar(22, null, 4, null))],
        'v2-quote__role' => ['s' => S(col('rgba(255,255,255,.8)'), ty('13.5px'), mar(0, null, 24, null))],
        'v2-quote v2-more' => ['s' => col('#ffffff')],
        'v2-tile'        => ['s' => S(lay(['display' => 'flex', 'direction' => 'column']), radius('24px'), bg('#1d1519'), brd('rgba(255,255,255,.07)', '1px', '24px'), fx(['transition' => ['duration_ms' => 350, 'easing' => 'ease']])),
                              'h' => S(fx(['transform' => ['translate_y' => '-6px']]), ['border' => ['color' => 'rgba(255,124,195,.45)']])],
        'v2-tile--1'     => ['reveal' => $up(120)], 'v2-tile--2' => ['reveal' => $up(240)],
        'v2-tile__photo' => ['s' => S(dim(['height' => '240px', 'overflow' => 'hidden']), bg('#f4eef1'), top_radius('23px'))],
        'v2-tile__img'   => zoom(1.05, 600, dim(['height' => '100%', 'object_fit' => 'cover'])),
        'v2-tile__body'  => ['s' => S(lay(['display' => 'flex', 'direction' => 'column', 'grow' => 1]), pad(34, 34, 38, 34)), 'm' => pad(26, 24, 30, 24)],
        'v2-tile__title' => ['s' => S(col('#ffffff'), ty('22px', 'bold', '1.25'), mar(0, null, 16, null))],
        'v2-tile__text'  => ['s' => S(col('rgba(255,255,255,.7)'), ty('14.5px', null, '1.7'), mar(0, null, 16, null))],
        'v2-tile v2-more' => ['s' => S(col('#ff7cc3'), mar('auto', null, 0, null))], // on the card's bottom edge, level with the quote's link

        // ── Partners ──
        'v2-partners-sec' => ['s' => S(bg('#ffffff'), $V(140, 130)), 'm' => $V(84, 80)],
        'v2-split'       => ['s' => lay(['tracks' => 'minmax(0,1fr) minmax(0,1.05fr)', 'gap' => '96px', 'align' => 'center']), 't' => lay(['tracks' => '1fr', 'gap' => '52px'])],
        'v2-split__copy' => ['reveal' => $up(0)],
        'v2-logos'       => ['props' => ['columns' => '3'], 'reveal' => $up(0),
                              'parts' => ['grid' => lay(['gap' => '16px', 'align' => 'center']),
                                  'item' => S(sz(['height' => 108, 'overflow' => 'visible']), lay(['display' => 'flex', 'justify' => 'center', 'align' => 'center']), pad(18), radius('18px'), bg('#ffffff'), shadow(0, 14, 34, 'rgba(70,10,48,.08)', -6), fx(TRANS)),
                                  'item:hover' => S(fx(['transform' => ['translate_y' => '-4px']]), shadow(0, 20, 40, 'rgba(70,10,48,.14)', -6)),
                                  'image' => S(sz(['max_height' => '66px', 'width' => 'auto', 'height' => 'auto', 'object_fit' => 'contain']), fx(['filter' => ['grayscale' => 100], 'transition' => ['duration_ms' => 250, 'easing' => 'ease']]), ['opacity' => 70]),
                                  'image:hover' => S(fx(['filter' => ['grayscale' => 0]]), ['opacity' => 100])],
                              'mparts' => ['grid' => lay(['gap' => '10px']), 'item' => sz(['height' => 84])]],

        // ── Support (photo band) ──
        'v2-support-sec' => ['s' => S(bg('#ffffff'), $V(0, 140)), 'm' => $V(0, 84)],
        'v2-support'     => ['s' => S(place('relative'), radius('30px'), dim(['overflow' => 'hidden', 'min_height' => '580px']), pad(90, 80), lay(['justify' => 'center'])),
                              't' => pad(54, 40), 'm' => S(pad(34, 22), dim(['min_height' => '0px'])), 'reveal' => $up(0)],
        'v2-support__card' => ['s' => S(dim(['max_width' => '560px']), pad(48, 48), radius('24px'), bg('rgba(255,255,255,.96)'), shadow(0, 30, 70, 'rgba(20,0,12,.25)')), 'm' => pad(26, 22)],
        'v2-support__list' => ['props' => ['style' => 'check'], 'parts' => ['list' => S(mar(8, 0, 8, 0), lay(['row_gap' => '14px'])), 'item' => S(col(INK), ty('15px', null, '22px')), 'marker' => col(PINK)]],

        // ── News ──
        'v2-news-sec'    => ['s' => S(bg('#fbf6f8'), $V(140, 140)), 'm' => $V(84, 84)],
        // Home section headings and closing buttons sit further from their content.
        'v2-axes-sec v2-head' => ['s' => mar(0, null, 68, null), 'm' => mar(0, null, 44, null)],
        'v2-dark v2-head' => ['s' => mar(0, null, 68, null), 'm' => mar(0, null, 44, null)],
        'v2-news-sec v2-head' => ['s' => mar(0, null, 68, null), 'm' => mar(0, null, 44, null)],
        'v2-news-sec v2-center' => ['s' => mar(64, null, 0, null), 'm' => mar(44, null, 0, null)],
        'v2-loop'        => ['reveal' => $up(0), 'parts' => [
                'grid' => lay(['gap' => '28px']),
                'card' => S(radius('22px'), bg('#ffffff'), shadow(0, 18, 46, 'rgba(70,10,48,.09)', -8), sz(['overflow' => 'visible']), ['border' => ['width' => '0px']], fx(['transition' => ['duration_ms' => 300, 'easing' => 'ease']])),
                'card:hover' => S(fx(['transform' => ['translate_y' => '-6px']]), shadow(0, 26, 56, 'rgba(70,10,48,.15)', -8)),
                'image' => sz(['height' => 210]), 'body' => pad(22, 24, 26, 24),
                'category' => S(col(PINK), ty('12px', 'bold', null, '.08em')), 'title' => S(col(INK), fam('heading', '19px', 'bold', '25px')),
                'excerpt' => S(col(BODY), ty('14.5px', null, '24px')), 'meta' => S(col('#9a8f95'), ty('12.5px')),
                'button' => S(bg('transparent'), col(PINK), pad(0), ['border' => ['width' => '0px']], fam('body', '14.5px', 'bold'), sz(['height' => 'auto', 'min_width' => '0px']))],
            'mparts' => ['grid' => lay(['gap' => '20px'])]],
        'v2-center'      => ['s' => S(lay(['display' => 'flex', 'justify' => 'center', 'gap' => '14px']), mar(44, null, 0, null))],

        // ── Header ──
        'v2-top-sec'     => ['s' => S(bg('#0c0905'), $V(0, 0))],
        'v2-top'         => ['s' => S(dim(['min_height' => '44px']), col('rgba(255,255,255,.78)'), ty('13px')), 'm' => S(lay(['justify' => 'center']))],
        'v2-top__contact' => ['s' => lay(['gap' => '26px']), 'thide' => true],
        'v2-intro__second' => ['m' => mar(18, null, null, null)],
        'v2-top__item'   => ['s' => S(col('rgba(255,255,255,.78)'), fx(TRANS)), 'h' => col('#ffffff')],
        'v2-top__right'  => ['s' => lay(['gap' => '6px'])],
        'v2-top__member' => ['s' => S(col('#ffffff'), ty('12.5px', 'bold'), pad(6, 14), radius('999px'), brd('rgba(255,255,255,.28)', '1px', '999px'), mar(null, null, null, 10), fx(TRANS)),
                              'h' => S(bg(PINK), ['border' => ['color' => PINK]])],
        'v2-cart'        => ['s' => S(col('#ffffff'), ty('12.5px', 'bold'), mar(null, null, null, 12))],
        'v2-social'      => ['s' => S(tile(30, '50%', 'transparent', null), col('rgba(255,255,255,.8)'), fx(TRANS)), 'h' => S(bg(PINK), col('#ffffff'))],
        'v2-head-sec'    => ['s' => S(bg('#ffffff'), $V(0, 0))],
        'v2-header'      => ['s' => S(lay(['tracks' => 'auto 1fr auto', 'align' => 'center', 'gap' => '20px']), dim(['height' => '92px'])), 't' => lay(['tracks' => '1fr auto auto', 'gap' => '12px']), 'm' => dim(['height' => '74px'])],
        'v2-brand'       => ['s' => dim(['width' => '140px']), 'm' => dim(['width' => '112px'])],
        // One menu at every width: links with panels in a row, then a dropdown card behind its own button; folded, it moves after the donate button.
        'v2-nav'         => ['t' => lay(['order' => 2]), 'm' => lay(['order' => 2]), 'parts' => [
                'list' => lay(['gap' => '6px']), // no display here: it would beat the rule that folds the list away
                'link' => S(lay(['display' => 'flex', 'align' => 'center']), pad(10, 10), col(INK), fam('body', '15px', 'bold'), fx(TRANS)),
                'link:hover' => col(PINK_DEEP), 'open' => col(PINK_DEEP), 'current' => col(PINK_DEEP),
                'submenu' => S(pad(34, 32, 38, 32), side('top', '#f6e8ef'), shadow(0, 30, 54, 'rgba(70,10,48,.16)', -16)), 'grid' => lay(['gap' => '36px', 'tracks' => '1fr 1.25fr 1fr', 'align' => 'stretch']),
                'burger' => S(sz(['width' => 44, 'height' => 44]), radius('12px'), bg('#fff0f7'), col(PINK)),
                'panel' => pad(10, 22, 24, 22),
                'cta' => S($pill, sz(['width' => '100%']), ['typography' => ['decoration' => 'none']]), 'cta:hover' => $pillHover]],
        'v2-mega__title' => ['s' => S(col(PINK_DEEP), ty('13px', 'bold', '1.3', '.08em'), ['typography' => ['transform' => 'uppercase']], mar(0, null, 14, 12)), 'thide' => true],
        // The page being viewed is a filled row; pointing at a link only tints it, so the two never read alike.
        'v2-mega__nav'   => ['s' => mar(0, null, 0, null), 'parts' => ['list' => lay(['gap' => '4px']),
                'link' => S(lay(['display' => 'block']), pad(11, 14), radius('12px'), col(INK), fam('body', '15px', 'bold', '20px'), fx(TRANS)),
                'link:hover' => S(col(PINK_DEEP), bg('#fff0f7')), 'current' => S(col('#ffffff'), bg(PINK_DEEP)), 'current:hover' => S(col('#ffffff'), bg(PINK_DEEP))]],
        'v2-mega__img'   => ['s' => S(dim(['height' => '250px', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('18px')), 'thide' => true],
        'v2-mega__cta'   => ['s' => S(lay(['display' => 'flex', 'direction' => 'column', 'justify' => 'center', 'gap' => '18px']), pad(28, 28), radius('18px'), grad('linear-gradient(135deg,#ff3fa6 0%,#e9008a 55%,#a3005f 100%)')), 'thide' => true],
        'v2-mega__cta-title' => ['s' => S(col('#ffffff'), ty('19px', 'bold', '1.3', null, SYNE), mar(0, null, 0, null))],
        'v2-mega__cta v2-btn' => ['parts' => ['button' => S(['background' => ['gradient' => 'linear-gradient(140deg,#ffffff 0%,#ffffff 100%)']], col(PINK_DEEP), shadow(0, 12, 26, 'rgba(60,0,35,.28)', -8)),
                'button:hover' => S(['background' => ['gradient' => 'linear-gradient(140deg,#fff0f7 0%,#ffe0f0 100%)']], col(PINK_DEEP))]],
        'v2-head__end'   => ['s' => lay(['gap' => '12px', 'align' => 'center', 'justify' => 'end'])],
        // 19px would run into the menu at 1024px, so the header keeps 14px and takes the deeper pink.
        'v2-donate'      => ['parts' => ['button' => S($outline, brd(PINK_DEEP, '2px', '999px'), col(PINK_DEEP), sz(['height' => 46]), pad(0, 22), ty('14px')),
                'button:hover' => S($outlineHover, bg(PINK_DEEP)), 'button:focus' => S($outlineFocus, bg(PINK_DEEP))], 'mparts' => ['button' => S(sz(['height' => 42]), pad(0, 16), ty('13.5px'))]],

        // ── Newsletter and footer ──
        'v2-news-strip'  => ['s' => $V(64, 120), 'm' => $V(44, 90)],
        'v2-nl'          => ['s' => lay(['tracks' => 'auto minmax(0,1fr) minmax(320px,.9fr)', 'gap' => '24px', 'align' => 'center']), 't' => lay(['tracks' => 'auto minmax(0,1fr)']), 'm' => lay(['tracks' => '1fr', 'gap' => '16px']), 'reveal' => $up(0)],
        'v2-nl__icon'    => ['s' => S(tile(64, '18px', PINK, '#ffffff'), shadow(0, 14, 30, 'rgba(233,0,138,.3)')), 'props' => ['size_px' => 28]],
        'v2-nl__title'   => ['s' => S(ty('27px', 'bold', '1.2', '-.015em'), mar(0, null, 6, null)), 'm' => ty('22px')],
        'v2-nl__text'    => ['s' => S(col(BODY), ty('15px', null, '1.65'))],
        'v2-nl__form'    => ['props' => ['labels' => 'hidden', 'layout' => 'inline'], 't' => lay(['column_span' => 'full']),
                              'parts' => ['form' => S(pad(6), radius('999px'), bg('#ffffff'), brd('#f0e3ea', '1px', '999px'), shadow(0, 14, 34, 'rgba(70,10,48,.08)'), lay(['gap' => '0px'])),
                                  'input' => S(sz(['width' => '100%', 'height' => 50]), ['border' => ['width' => '0px']], pad(0, 18), col('#555555'), bg('transparent'), fam('body', '15px')),
                                  'button' => S($pill, sz(['height' => 50]), pad(0, 26), ty('14px'), fx(['cursor' => 'pointer'])), 'button:hover' => $pillHover]],
        'v2-foot-sec'    => ['s' => S(grad('linear-gradient(135deg,#f2189a 0%,#e9008a 45%,#b8006f 100%)'), col('#ffffff'), $V(0, 70)), 'm' => $V(0, 50)],
        'v2-foot'        => ['s' => lay(['tracks' => 'minmax(0,1.55fr) minmax(330px,1fr)', 'gap' => '60px', 'align' => 'start']), 't' => lay(['tracks' => '1fr', 'gap' => '40px'])],
        'v2-foot__cols'  => ['s' => S(lay(['tracks' => 'minmax(0,1fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)', 'gap' => '24px']), pad(80, null, 0, null)), 't' => S(lay(['tracks' => 'minmax(0,1fr) minmax(0,1fr)', 'gap' => '30px']), pad(56, null, null, null)), 'm' => S(lay(['row_gap' => '26px', 'column_gap' => '18px']), pad(44, null, null, null))],
        'v2-foot__title' => ['s' => S(col('#ffffff'), ty('15px', 'bold', '1.3', '.06em'), ['typography' => ['transform' => 'uppercase']], mar(0, null, 16, null))],
        'v2-foot__nav'   => ['parts' => ['list' => S(lay(['display' => 'grid', 'row_gap' => '10px'])), 'link' => S(col('rgba(255,255,255,.86)'), ty('14.5px', null, '21px'), pad(0), fx(TRANS)), 'link:hover' => S(col('#ffffff'), fx(['transform' => ['translate_x' => '3px']]))]],
        'v2-foot__card'  => ['s' => S(mar(-80, null, 0, null), place('relative'), pad(34, 34, 30, 34), radius('26px'), bg('#ffffff'), col(BODY), shadow(0, 30, 70, 'rgba(60,0,35,.28)')),
                              't' => S(mar(0, null, null, null), dim(['max_width' => '520px'])), 'm' => pad(26, 22, 22, 22), 'reveal' => $up(0)],
        'v2-foot__logo'  => ['s' => S(dim(['width' => '150px']), mar(0, null, 18, null))],
        'v2-foot__about' => ['s' => S(col(BODY), ty('14px', null, '1.65'), mar(0, null, 18, null))],
        'v2-foot__row'   => ['s' => S(lay(['gap' => '12px', 'align' => 'center']), mar(0, null, 12, null), col(INK), ty('14.5px'))],
        'v2-foot__ico'   => ['s' => S(tile(34, '10px', PINK_SOFT)), 'props' => ['size_px' => 16]],
        'v2-foot__line'  => ['s' => S(col(INK), fx(TRANS)), 'h' => col(PINK)],
        'v2-foot__social' => ['s' => S(lay(['gap' => '8px']), mar(8, null, 20, null))],
        'v2-foot__social v2-social' => ['s' => S(col(PINK), brd('#f2dfe9', '1px', '50%'), dim(['width' => '38px', 'height' => '38px']))],
        'v2-bar-sec'     => ['s' => S(grad('linear-gradient(90deg,#4a4446 0%,#0c0905 70%)'), col('rgba(255,255,255,.75)'), ty('13.5px'), $V(20, 20))],
        'v2-bar'         => ['s' => lay(['gap' => '20px']), 'm' => S(lay(['direction' => 'column', 'gap' => '8px', 'align' => 'center']))],
        'v2-bar__copy'   => ['s' => S(col('#ffffff'), ty('14px', 'bold', null, null, SYNE))],
        'v2-bar__nav'    => ['parts' => ['list' => lay(['display' => 'flex', 'gap' => '20px']), 'link' => S(col('rgba(255,255,255,.75)'), ty('13.5px'), pad(0)), 'link:hover' => col('#ffffff')],
                              'mparts' => ['list' => lay(['justify' => 'center', 'row_gap' => '6px', 'column_gap' => '20px'])]],
    ];
}

// ── Fonts ────────────────────────────────────────────────────────────────────────────────────────────────────────
// The handwritten kicker font joins the site's Karla and Syne (the head snippet is shared, so it is added, not replaced).
$head = (string) (Database::setting(StudioCodePolicy::SETTING_HEAD_SNIPPET, $tid) ?? '');
if (!str_contains($head, 'Homemade+Apple')) {
    $head = str_replace('family=Karla', 'family=Homemade+Apple&family=Karla', $head);
    if (!str_contains($head, 'Homemade+Apple')) {
        $head .= '<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">';
    }
    Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($head, 'head'), $tid);
    say('  + font: Homemade Apple');
}
// The marquee's stencil face, as on the live site.
if (!str_contains($head, 'Saira+Stencil+One')) {
    $head = str_replace('family=Homemade+Apple', 'family=Saira+Stencil+One&family=Homemade+Apple', $head);
    Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($head, 'head'), $tid);
    say('  + font: Saira Stencil One');
}

// ── Addresses ────────────────────────────────────────────────────────────────────────────────────────────────────
// While the client compares the two designs, each V2 page lives beside the current one at "<slug>-v2" (the home page at
// /accueil-v2/), and every link between V2 pages points at those addresses. With --live, V2URL() is the identity.
$V2PAGES = json_decode((string) file_get_contents(__DIR__ . '/../fxl/v2-content.json'), true);
// Where Stripe sends a donor back: not in the content pack, which only asks for a clear payment confirmation.
const V2_THANKS = '/merci-don/';
$V2PAGES[] = ['doc' => '2.4. (merci)', 'url' => V2_THANKS, 'seo_title' => 'Merci pour votre don | FightxLife', 'meta' => '', 'h1' => 'Merci pour votre don', 'design' => '', 'sections' => [],
    'intro' => [
        ['t' => 'p', 'html' => 'Votre paiement a bien été accepté. Merci de soutenir FightxLife et les personnes touchées par le cancer.'],
        ['t' => 'p', 'html' => 'Si vous avez choisi un don mensuel, il sera prélevé chaque mois à la même date. Pour le modifier ou l’arrêter, <a href="/contact/">contactez-nous</a>.'],
        ['t' => 'cta', 'kind' => 'Primary CTA', 'label' => 'Découvrir nos actions', 'href' => '/nos-actions/'],
        ['t' => 'cta', 'kind' => 'Secondary CTA', 'label' => 'Retour à l’accueil', 'href' => '/'],
    ]];
$V2MAP = [];
foreach ($V2PAGES as $pg) { $V2MAP[$pg['url']] = V2_LIVE ? $pg['url'] : ($pg['url'] === '/' ? '/accueil-v2/' : rtrim($pg['url'], '/') . '-v2/'); }
/**
 * The 301 plan of the content pack (« 301 redirect plan », 16 October 2026): each retired address and its destination.
 * Installed with --live; links to a retired address always go straight to its destination, never through the 301.
 */
const V2_RETIRED = [
    '/les-hopitaux/' => '/soutien-a-lhopital/', '/blog/' => '/actualites/', '/evenements/' => '/agenda/', '/events/' => '/agenda/#evenements-passes',
    '/smilebox/' => '/smile-box/', '/gala/' => '/nous-soutenir/#autres-aides', '/crowdfunding/' => '/nous-soutenir/#faire-un-don',
    '/donation/' => '/nous-soutenir/#faire-un-don', '/register/' => V2_MEMBRE, '/cours/mon-compte/' => V2_MEMBRE,
];
function V2URL(string $href): string
{
    global $V2MAP;
    if ($href === '' || $href[0] !== '/' || str_starts_with($href, '//')) { return $href; }
    $path = (string) parse_url($href, PHP_URL_PATH);
    $rest = substr($href, strlen($path));
    if (isset(V2_RETIRED[$path])) {
        [$path, $rest] = array_pad(explode('#', V2_RETIRED[$path], 2), 2, null);
        if ($path[0] !== '/') { return V2_RETIRED[parse_url($href, PHP_URL_PATH)]; }
        $rest = $rest !== null ? '#' . $rest : '';
    }
    return isset($V2MAP[$path]) ? $V2MAP[$path] . $rest : $path . $rest;
}
/** Every V2 page slug: the header and footer show on these only. */
$V2SLUGS = array_map(static fn(string $u): string => trim($u, '/'), array_values($V2MAP));
/** Point a block tree's internal links at the V2 addresses. */
function v2_relink(array &$node): void
{
    foreach (['url', 'href'] as $k) { if (isset($node['props'][$k]) && is_string($node['props'][$k])) { $node['props'][$k] = V2URL($node['props'][$k]); } }
    if (isset($node['props']['link']['href'])) { $node['props']['link']['href'] = V2URL($node['props']['link']['href']); }
    if (isset($node['props']['content']) && is_string($node['props']['content'])) {
        $node['props']['content'] = (string) preg_replace_callback('~href="([^"]+)"~', static fn($m) => 'href="' . htmlspecialchars(V2URL(html_entity_decode($m[1])), ENT_QUOTES) . '"', $node['props']['content']);
    }
    foreach (['blocks', 'children'] as $k) {
        if (isset($node[$k]) && is_array($node[$k])) { foreach ($node[$k] as &$c) { v2_relink($c); } unset($c); }
    }
}

// ── Menus (V2 structure, « 0. Menu ») ────────────────────────────────────────────────────────────────────────────
$actions = [['label' => 'Le sport comme outil', 'url' => '/le-sport-comme-outil/'], ['label' => 'Soutien à l’hôpital', 'url' => '/soutien-a-lhopital/'],
    ['label' => 'Information & prévention', 'url' => '/information-prevention/'], ['label' => 'Nous soutenir', 'url' => '/nous-soutenir/']];
$cours = [['label' => 'Boxe adaptée', 'url' => '/boxe-adaptee/'], ['label' => 'WaterxFight', 'url' => '/waterxfight/'], ['label' => 'Boxe 100 % féminin', 'url' => '/100-feminin/'],
    ['label' => 'Nage libre', 'url' => '/nage-libre-femmes/'], ['label' => 'Cours de natation', 'url' => '/cours-de-natation/']];
$projets = [['label' => 'Notre impact', 'url' => '/notre-impact/'], ['label' => 'La SmileBox', 'url' => '/smile-box/'], ['label' => 'Benkick, notre mascotte', 'url' => '/benkick-mascotte/'],
    ['label' => 'Partenaires & soutiens', 'url' => '/partenaires-soutiens/']];
$apropos = [['label' => 'Qui sommes-nous ?', 'url' => '/qui-sommes-nous/'], ['label' => 'Actualités', 'url' => '/actualites/'], ['label' => 'Agenda', 'url' => '/agenda/'], ['label' => 'Presse', 'url' => '/presse/']];
$main = [['label' => 'Nos actions', 'url' => '/nos-actions/', 'children' => $actions], ['label' => 'Nos cours', 'url' => '/cours/', 'children' => $cours],
    ['label' => 'Projets & impact', 'url' => '/notre-impact/', 'children' => $projets], ['label' => 'À propos', 'url' => '/a-propos/', 'children' => $apropos], ['label' => 'Contact', 'url' => '/contact/']];
$menus = ['v2-header' => ['V2 · Menu principal', $main], 'v2-actions' => ['V2 · Nos actions', $actions], 'v2-cours' => ['V2 · Nos cours', $cours], 'v2-projets' => ['V2 · Projets & impact', $projets],
    'v2-apropos' => ['V2 · À propos', $apropos], 'v2-legal' => ['V2 · Mentions', [['label' => 'Politique de confidentialité', 'url' => '/politique-de-confidentialite/'], ['label' => 'Conditions générales de vente', 'url' => '/conditions-generales-de-vente/']]]];
$relinkItems = static function (array $items) use (&$relinkItems): array {
    return array_map(static fn(array $i): array => array_replace($i, ['url' => V2URL($i['url'])], isset($i['children']) ? ['children' => $relinkItems($i['children'])] : []), $items);
};
foreach ($menus as $key => [$name, $items]) { StudioMenus::save($tid, $key, $name, $relinkItems($items), $key); }
say('  + menus: ' . implode(', ', array_keys($menus)));

// ── Header ───────────────────────────────────────────────────────────────────────────────────────────────────────
// One panel of the menu: the section's links, a photo and the call to support; folded, only the links.
$megaItem = static fn(string $label, string $url, string $menu, string $photo): array => B('layout.mega_item', ['label' => $label, 'url' => $url, 'columns' => 3], [
    BOX([H($label, 'h3', 'v2-mega__title'), B('core.nav_menu', ['menu' => $menu, 'items' => [], 'layout' => 'vertical', 'aria_label' => $label], [], 'v2-mega__nav')]),
    V2IMG($photo, '', 'v2-mega__img'),
    BOX([H('Faire avancer les actions de FightxLife', 'h3', 'v2-mega__cta-title'), V2BTN('Soutenir FightxLife', '/nous-soutenir/', 'v2-btn v2-btn--sm')], 'v2-mega__cta'),
]);
$header = [
    SEC('Top bar', [B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'align' => 'center', 'gap' => 'none', 'wrap' => 'wrap'], [
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'wrap' => 'wrap'], [
            LNK('+32 483 12 72 98', 'tel:+32483127298', 'v2-top__item'), LNK('contact@fightxlife.com', 'mailto:contact@fightxlife.com', 'v2-top__item'),
            LNK('Rue Gabrielle Petit 32, 1080 Molenbeek', 'https://goo.gl/maps/aJW74coL3FB9Ld96A', 'v2-top__item')], 'v2-top__contact'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [
            V2SOCIAL('linkedin', 'https://www.linkedin.com/company/fightxlife/', 'FightxLife sur LinkedIn', 'v2-social'), V2SOCIAL('facebook', 'https://www.facebook.com/Fightxlife', 'FightxLife sur Facebook', 'v2-social'),
            V2SOCIAL('instagram', 'https://www.instagram.com/fightxlife', 'FightxLife sur Instagram', 'v2-social'), LNK('Espace membre', V2_MEMBRE, 'v2-top__member'), B('shop.mini_cart', ['label' => 'Panier', 'show_total' => false], [], 'v2-cart')], 'v2-top__right'),
    ], 'v2-top')], 'v2-top-sec'),
    SEC('Header', [B('layout.grid', ['columns' => 3, 'gap' => 'none', 'align' => 'center'], [
        V2IMG(V2_LOGO, 'FightxLife', 'v2-brand', ['link_to' => 'custom', 'url' => V2_HOME_URL]),
        B('layout.mega_menu', ['align' => 'center', 'aria_label' => 'Navigation principale', 'pointer' => 'underline', 'pointer_animation' => 'grow', 'toggle_breakpoint' => 'tablet', 'toggle_mode' => 'dropdown',
            'toggle_animation' => 'slide', 'toggle_label' => 'Ouvrir le menu', 'toggle_cta_label' => 'Soutenir FightxLife', 'toggle_cta_url' => '/nous-soutenir/'], [
            $megaItem('Nos actions', '/nos-actions/', 'v2-actions', '2022-05-waterfight-piscine.jpg'),
            $megaItem('Nos cours', '/cours/', 'v2-cours', '2022-05-femininn.jpg'),
            $megaItem('Projets & impact', '/notre-impact/', 'v2-projets', '2023-01-ny-20220827-smile-box-5262.jpg'),
            $megaItem('À propos', '/a-propos/', 'v2-apropos', '2022-05-visite-hopitaux-4.jpg'),
            B('layout.mega_item', ['label' => 'Contact', 'url' => '/contact/']),
        ], 'v2-nav'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'justify' => 'end'], [
            B('core.button', ['link' => L('Soutenir FightxLife', '/nous-soutenir/'), 'variant' => 'primary'], [], 'v2-donate', ['visibility' => ['auth_state' => 'any', 'devices' => ['md', 'lg']]]),
            B('core.button', ['link' => L('Soutenir', '/nous-soutenir/'), 'variant' => 'primary'], [], 'v2-donate', ['visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm']]]),
        ], 'v2-head__end'),
    ], 'v2-header')], 'v2-head-sec'),
];

// ── Footer ───────────────────────────────────────────────────────────────────────────────────────────────────────
$footCol = static fn(string $title, string $menu): array => BOX([H($title, 'h3', 'v2-foot__title'), B('core.nav_menu', ['menu' => $menu, 'items' => [], 'layout' => 'vertical', 'aria_label' => $title], [], 'v2-foot__nav')]);
$footRow = static fn(string $icon, array $line): array => B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [ICO($icon, 'v2-foot__ico'), $line], 'v2-foot__row');
$footer = [
    SEC('Newsletter', [B('layout.grid', ['columns' => 3, 'gap' => 'none', 'align' => 'center'], [
        ICO('mail', 'v2-nl__icon'),
        BOX([H('Abonne-toi à notre newsletter', 'h2', 'v2-nl__title'), T('Reste informé de nos actions, événements, galas et plus encore… C’est promis, nous ne te spammerons pas !', 'v2-nl__text')]),
        B('core.form', ['form_name' => 'newsletter', 'submit_text' => 'S’abonner', 'submit_variant' => 'primary', 'success_message' => 'Merci ! Ton inscription à la newsletter est bien enregistrée.'], [
            B('core.form_field', ['field_type' => 'email', 'name' => 'email', 'label' => 'Email', 'placeholder' => 'Ton adresse email', 'required' => true]),
        ], 'v2-nl__form', ['id' => STABLE_ID('newsletter-form')]),
    ], 'v2-nl')], 'v2-news-strip'),
    SEC('Footer', [B('layout.grid', ['columns' => 2, 'gap' => 'none'], [
        B('layout.grid', ['columns' => 4, 'gap' => 'none'], [
            $footCol('Nos actions', 'v2-actions'), $footCol('Nos cours', 'v2-cours'), $footCol('Projets & impact', 'v2-projets'), $footCol('À propos', 'v2-apropos'),
        ], 'v2-foot__cols'),
        BOX([
            V2IMG(V2_LOGO, 'FightxLife', 'v2-foot__logo', ['link_to' => 'custom', 'url' => V2_HOME_URL]),
            T('Association d’accompagnement face au cancer : cours adaptés à Bruxelles, actions à l’hôpital et sensibilisation en Belgique.', 'v2-foot__about'),
            $footRow('phone', LNK('+32 483 12 72 98', 'tel:+32483127298', 'v2-foot__line')),
            $footRow('mail', LNK('contact@fightxlife.com', 'mailto:contact@fightxlife.com', 'v2-foot__line')),
            $footRow('map-pin', T('Rue Gabrielle Petit 32, 1080 Molenbeek', 'v2-foot__line')),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [
                V2SOCIAL('facebook', 'https://www.facebook.com/Fightxlife', 'FightxLife sur Facebook', 'v2-social'), V2SOCIAL('instagram', 'https://www.instagram.com/fightxlife', 'FightxLife sur Instagram', 'v2-social'),
                V2SOCIAL('linkedin', 'https://www.linkedin.com/company/fightxlife/', 'FightxLife sur LinkedIn', 'v2-social')], 'v2-foot__social'),
            V2BTN('Contacter l’association', '/contact/', 'v2-btn v2-btn--block'),
        ], 'v2-foot__card'),
    ], 'v2-foot')], 'v2-foot-sec'),
    SEC('Footer bar', [B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'align' => 'center', 'gap' => 'none', 'wrap' => 'wrap'], [
        T('© ' . date('Y') . ' FightxLife • Tous droits réservés', 'v2-bar__copy'),
        B('core.nav_menu', ['menu' => 'v2-legal', 'items' => [], 'layout' => 'horizontal', 'aria_label' => 'Mentions'], [], 'v2-bar__nav'),
    ], 'v2-bar')], 'v2-bar-sec'),
];

// The live site's grey wave mesh behind the newsletter, on the right.
$footer[0]['blocks'][0]['style']['background'] = ['image' => ['media_id' => MID('/uploads/media/fxl/2022-05-bg-footer.png'), 'alt' => '', 'focal_point' => [0.5, 0.5]], 'fit' => 'cover', 'position' => 'bottom-right'];
$footer[0]['style'] = ['background' => ['gradient' => 'linear-gradient(170deg,#ffffff 48%,#cfc6ca 78%,#3a3236 100%)']];

// ── Home page (« 1. Accueil ») ───────────────────────────────────────────────────────────────────────────────────
$axis = static fn(int $n, string $img, string $alt, string $icon, string $title, array $text, string $link, string $href): array => BOX([
    BOX([V2IMG($img, $alt, 'v2-card__img', ['link_to' => 'custom', 'url' => $href]), T(sprintf('%02d', $n), 'v2-card__num')], 'v2-card__photo'),
    BOX(array_merge([ICO($icon, 'v2-card__icon'), B('core.heading', ['text' => $title, 'level' => 'h3', 'url' => $href], [], 'v2-card__title')],
        array_map(static fn(string $p): array => T($p, 'v2-card__text'), $text), [MORE($link, $href)]), 'v2-card__body'),
], "v2-card v2-card--{$n}");
$tile = static fn(int $n, string $img, string $alt, string $title, array $text, string $link, string $href): array => BOX([
    BOX([V2IMG($img, $alt, 'v2-tile__img', ['link_to' => 'custom', 'url' => $href])], 'v2-tile__photo'),
    BOX(array_merge([B('core.heading', ['text' => $title, 'level' => 'h3', 'url' => $href], [], 'v2-tile__title')], array_map(static fn(string $p): array => T($p, 'v2-tile__text'), $text), [MORE($link, $href)]), 'v2-tile__body'),
], "v2-tile v2-tile--{$n}");
$logos = ['2022-05-nos_sponsors_7-removebg-preview.png', '2025-02-logo_cera_baseline_fr.png', '2022-08-logohude-full-xl.png', '2022-08-logo_fondation_rvb.png', '2022-05-nos_sponsors_8-removebg-preview.png',
    '2022-05-nos_sponsors_5-removebg-preview.png', '2022-05-adeps-removebg-preview.png', '2022-05-nos_sponsors_9-removebg-preview.png', '2022-07-cdd-2018-w-copie.png'];

$supportBand = BOX([BOX([
    KICK(), H('Soutenir les enfants et les familles à l’hôpital', 'h2', 'v2-h2', 'à l’hôpital'),
    T('Les soutiens reçus par FightxLife contribuent notamment aux actions menées auprès des enfants touchés par le cancer et de leurs familles dans les services d’oncologie pédiatrique.', 'v2-lead'),
    T('Il est possible d’aider FightxLife de différentes manières :', 'v2-lead'),
    B('core.list', ['style' => 'check', 'items' => array_map(static fn(string $t): array => ['text' => $t], ['Faire un don', 'Devenir bénévole', 'Proposer ses compétences', 'Organiser une initiative solidaire', 'Soutenir un projet en tant que partenaire'])], [], 'v2-support__list'),
    B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap'], [V2BTN('Soutenir FightxLife', '/nous-soutenir/'), V2BTN('Contacter l’association', '/contact/', 'v2-ghost')], 'v2-actions'),
], 'v2-support__card')], 'v2-support');
$supportBand['style']['background'] = ['image' => ['media_id' => MID('/uploads/media/fxl/2022-05-enfant-malade.jpg'), 'alt' => '', 'focal_point' => [0.75, 0.3]], 'fit' => 'cover', 'position' => 'right',
    'overlay' => ['color' => 'rgba(26,4,18,.12)']];

$sections = [
    SEC('Hero', [B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [ICO('heart', 'v2-badge__dot'), T('Association d’accompagnement face au cancer')], 'v2-badge'),
            H('Aux côtés des personnes touchées par le cancer et de leurs proches', 'h1', 'v2-hero__title', 'touchées par le cancer'),
            T('Le cancer ne bouleverse pas seulement la santé. Il transforme le rapport au corps, bouscule les repères, fragilise parfois la confiance, modifie le quotidien et touche aussi profondément les proches et la vie familiale.', 'v2-hero__lead'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap'], [V2BTN('Découvrir nos actions', '/nos-actions/'), V2BTN('Voir nos cours à Bruxelles', '/cours/', 'v2-ghost')], 'v2-actions'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none'], array_map(static fn(string $t): array => B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [ICO('check-circle', 'v2-tick__ico'), T($t)], 'v2-tick'),
                ['Cours adaptés à Bruxelles', 'Actions à l’hôpital', 'Sensibilisation en Belgique']), 'v2-hero__ticks'),
        ], 'v2-hero__copy'),
        BOX([
            V2IMG('2022-05-femininn.jpg', 'Un cours de boxe FightxLife : une participante frappe dans les pattes d’ours de sa coach', 'v2-hero__main'),
            V2IMG('2022-05-visite-hopitaux-4.jpg', 'L’équipe FightxLife en visite à l’hôpital', 'v2-hero__small'),
            BOX([T('« Une jolie bulle d’oxygène et de défoulement qui me permet de reprendre le sport en respectant mon rythme. »', 'v2-hero__qtext'), T('Jayne, WaterxFight', 'v2-hero__qname')], 'v2-hero__quote'),
        ], 'v2-hero__art'),
    ], 'v2-hero')], 'v2-hero-sec'),
    SEC('Intro', [B('layout.grid', ['columns' => 2, 'gap' => 'none'], [
        BOX([KICK('Qui accompagnons-nous ?'), T('FightxLife accompagne les enfants, les adolescents et les adultes touchés par la maladie, ainsi que leurs proches, pour les aider à rester en mouvement, garder du lien et trouver du soutien à chaque étape de leur parcours.', 'v2-intro__text')]),
        BOX([KICK('Où agissons-nous ?'), T('L’association propose des cours adaptés à Bruxelles et mène également des actions dans les hôpitaux, les écoles et différents lieux en Belgique, à travers le sport, la sensibilisation et des projets pensés au plus près des personnes concernées.', 'v2-intro__text')], 'v2-intro__second'),
    ], 'v2-intro')], 'v2-intro-sec'),
    SEC('La force de vivre', [
        KICK('FightxLife', 'v2-kick v2-force__kick'),
        B('core.marquee', ['text' => 'La force de vivre', 'duration' => 60], [], 'v2-force__marquee'),
        B('core.video', ['source' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=jwMB0uoajXQ', 'aspect_ratio' => '16:9', 'privacy' => true, 'lazy_load' => true, 'controls' => true,
            'autoplay' => true, 'muted' => true], [], 'v2-force__video'),
    ], 'v2-force-sec'),
    SEC('Trois manières d’agir', [
        V2HEAD('Trois manières d’agir face au cancer', 'face au cancer', [
            'Parce que chaque parcours est différent, FightxLife agit à plusieurs endroits de la vie : quand il faut retrouver confiance dans son corps, traverser le quotidien à l’hôpital ou aider les autres à mieux comprendre la maladie.']),
        B('layout.grid', ['columns' => 3, 'gap' => 'none'], [
            $axis(1, '2022-05-waterfight-piscine.jpg', 'Un cours de WaterxFight à la piscine', 'zap', 'Le sport comme outil', [
                'À Bruxelles, FightxLife propose des activités adaptées aux personnes malades, fragiles ou en convalescence.',
                'Boxe adaptée, WaterxFight, Boxe 100 % féminin, nage libre et cours de natation permettent de bouger à son rythme, sans pression de performance.'], 'Découvrir le sport comme outil', '/le-sport-comme-outil/'),
            $axis(2, '2022-05-enfant-lit-hopital.jpg', 'Un enfant dans son lit d’hôpital', 'heart', 'Le soutien à l’hôpital', [
                'FightxLife intervient auprès des enfants touchés par le cancer et de leurs familles dans des services d’oncologie pédiatrique en Belgique.',
                'Sport, jeux, ateliers créatifs, rencontres ou SmileBox : des moments pour vivre autre chose que les soins et simplement être un enfant.'], 'Découvrir notre soutien à l’hôpital', '/soutien-a-lhopital/'),
            $axis(3, '2023-01-0i0a1230.jpg', 'Une intervention de sensibilisation de FightxLife', 'message', 'Information & prévention', [
                'Parler du cancer autrement, c’est aussi aider à mieux le comprendre.',
                'Dans les écoles, les associations et lors d’événements, FightxLife aide les enfants, les jeunes et le grand public à mieux comprendre le cancer, la santé et la prévention.'], 'Découvrir nos actions d’information et de prévention', '/information-prevention/'),
        ], 'v2-cards'),
    ], 'v2-axes-sec'),
    SEC('Des projets qui créent du lien', [
        V2HEAD('Des projets qui créent du lien', 'créent du lien', [
            'Dans les cours, les écoles ou les services d’oncologie pédiatrique, FightxLife développe des projets qui partent d’une même idée : permettre aux personnes touchées par le cancer de rester reliées aux autres et créer autour d’elles davantage de compréhension et de solidarité.']),
        B('layout.grid', ['columns' => 3, 'gap' => 'none'], [
            BOX([
                BOX([T('“', 'v2-quote__mark'), T('L’expérience des membres', 'v2-quote__title'), T('Une jolie bulle d’oxygène et de défoulement qui me permet de reprendre le sport en respectant mon rythme.', 'v2-quote__text')]),
                BOX([T('Jayne', 'v2-quote__name'), T('Participante au WaterxFight', 'v2-quote__role'), MORE('Découvrir notre impact', '/notre-impact/')]),
            ], 'v2-quote'),
            $tile(1, '2023-01-ny-20220827-smile-box-5262.jpg', 'La SmileBox : produits La Roche-Posay, peluche Benkick et messages d’encouragement', 'La SmileBox', [
                'Offerte aux enfants et aux jeunes de 0 à 21 ans touchés par le cancer, via des services d’oncologie pédiatrique partenaires : produits La Roche-Posay, une peluche Benkick, un t-shirt, une casquette et des messages préparés par des élèves.',
                'Sa remise est aussi un moment pour rencontrer l’enfant et sa famille.'], 'Découvrir la SmileBox', '/smile-box/'),
            $tile(2, '2023-01-ny-20220827-smile-box-5273.jpg', 'Benkick, la mascotte kangourou de FightxLife, en peluche', 'Benkick, la mascotte', [
                'Benkick est un kangourou qui représente la force et le courage. À l’hôpital, ses visites proposent aux enfants un moment de jeu et de complicité, sans chercher à les faire parler de la maladie.',
                'Son univers sert aussi de support pédagogique dans les écoles.'], 'Rencontrer Benkick', '/benkick-mascotte/'),
        ], 'v2-bento'),
    ], 'v2-dark'),
    SEC('Agir ensemble', [B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([
            V2HEAD('Agir ensemble', 'ensemble', [
                'Les actions de FightxLife se construisent aussi grâce aux collaborations développées avec des hôpitaux, des écoles, des associations, des institutions, des fondations, des entreprises et d’autres acteurs engagés.',
                'Selon les projets, les partenaires peuvent accueillir ou coorganiser une action, mettre à disposition des produits ou du matériel, apporter leur expertise ou contribuer financièrement à une initiative précise.'], 'left'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap'], [V2BTN('Découvrir nos partenaires et soutiens', '/partenaires-soutiens/'), V2BTN('Construire une collaboration', '/contact/?reason=partenariat', 'v2-ghost')], 'v2-actions'),
        ], 'v2-split__copy'),
        B('core.gallery', ['columns' => '3', 'gap' => 'md', 'aspect_ratio' => 'auto', 'rounded' => false,
            'images' => array_map(static fn(string $f): array => ['url' => '/uploads/media/fxl/' . $f, 'alt' => 'Partenaire de FightxLife', 'caption' => ''], $logos)], [], 'v2-logos'),
    ], 'v2-split')], 'v2-partners-sec'),
    SEC('Soutenir', [$supportBand], 'v2-support-sec'),
    SEC('La vie de FightxLife', [
        V2HEAD('La vie de FightxLife', 'FightxLife', ['Dans un hôpital, une école, autour d’un cours ou lors d’un événement, découvrez les dernières actions de FightxLife et les prochains rendez-vous de l’association.']),
        B('core.query_loop', ['source' => 'posts', 'category' => 'Actualités', 'per_page' => 3, 'columns' => 3, 'card_variant' => 'card', 'show_author' => false, 'show_category' => true,
            'enable_pagination' => false, 'read_more_text' => 'Lire la suite →', 'empty_message' => ''], [], 'v2-loop'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [V2BTN('Voir toutes les actualités', '/actualites/'), V2BTN('Consulter l’agenda', '/agenda/', 'v2-ghost')], 'v2-center'),
    ], 'v2-news-sec'),
];
// The V2 document's anchors: the section container's HTML id (Advanced › ID in the editor).
foreach ([2 => 'axes', 3 => 'projets', 4 => 'partenaires', 5 => 'soutenir', 6 => 'vie-association'] as $i => $anchor) {
    $sections[$i]['blocks'][0]['attributes'] = ['id' => $anchor];
}

// ── Build ────────────────────────────────────────────────────────────────────────────────────────────────────────
$v1slugs = v2_switch($V2PAGES, $V2MAP);
// Live, the V2 header and footer are the site's, except on the "-v1" pages, which keep the current ones.
$only = ['conditions' => ['rules' => V2_LIVE
    ? [['type' => 'include', 'condition' => 'entire_site'], ...array_map(static fn(string $s): array => ['type' => 'exclude', 'condition' => 'slug', 'value' => $s], $v1slugs)]
    : array_map(static fn(string $s): array => ['type' => 'include', 'condition' => 'slug', 'value' => $s], $V2SLUGS)]];
foreach ([&$header, &$footer, &$sections] as &$tree) { foreach ($tree as &$sec) { v2_relink($sec); } unset($sec); }
unset($tree);
$v2ids = [];
$v2ids['header'] = build_page('v2-header', V2_LIVE ? 'Header' : 'Header V2 (aperçu)', 'header_partial', 'standalone', $header,
    $only + ['sticky_header' => ['edge' => 'top', 'offset' => 0, 'devices' => ['desktop', 'tablet', 'mobile'], 'shadow' => true, 'background' => '#ffffff']]);
$v2ids['footer'] = build_page('v2-footer', V2_LIVE ? 'Footer' : 'Footer V2 (aperçu)', 'footer_partial', 'standalone', $footer, $only);
$v2ids['/'] = build_page(V2_SLUG, V2_LIVE ? 'Accueil' : 'Accueil V2 (aperçu)', 'page', V2_LIVE ? 'homepage' : 'standalone', $sections, V2_LIVE ? [] : ['seo_robots' => 'noindex,nofollow'],
    'FightxLife | Association d’accompagnement face au cancer',
    'FightxLife accompagne les personnes touchées par le cancer et leurs proches : cours adaptés à Bruxelles, actions hospitalières et sensibilisation en Belgique.',
    MID('/uploads/media/fxl/2022-05-femininn.jpg') ?: null);
$v2ids += v2_build_pages($V2PAGES);
Database::setSetting('fxl_v2_pages', json_encode($v2ids, JSON_UNESCAPED_SLASHES), $tid);
v2_redirects($V2MAP);
PageRoutes::forget($tid);
say(V2_LIVE ? 'done: V2 is the site, at the real addresses; the current pages are at "<slug>-v1"; ' . count(V2_RETIRED) . ' retired addresses redirect (301)'
    : 'done: /accueil-v2/ and ' . (count($V2SLUGS) - 1) . ' V2 pages, with their own header and footer');
