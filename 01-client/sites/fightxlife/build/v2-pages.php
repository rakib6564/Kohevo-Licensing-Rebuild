<?php
/**
 * FightxLife V2 — every page of the V2 content pack except the home page, in the approved V2 design (v2.php builds the
 * home page, header and footer and calls v2_build_pages()).
 *
 * The text comes from fxl/v2-content.json, made from the client's documents (one per page): SEO title and description,
 * H1, intro, then the H2 sections with their H3 blocks, paragraphs, lists and labelled calls to action. The documents'
 * English implementation notes are not shown; the few that ask for a module (contact form, donations, news, events,
 * team) choose how their section is built. One engine lays every page out:
 *   - a hero: the menu section as a badge, the H1, the intro and its buttons, with the page's photo when it has one;
 *   - each H2 section on a white or soft-pink band: kicker, title, text, then its H3 blocks as cards;
 *   - "Label : value" lines (durée, horaires, tarifs…) as fact cards, figures as stat cards, quotes as quote cards;
 *   - the last section, when it is a call to action, as a pink band.
 * Each page goes to "<slug>-v2" beside the current page (see V2URL in v2.php); nothing existing is changed.
 */

declare(strict_types=1);

/** Photos per page: hero, section images (by HTML id) and card images (by H3 title). Files in uploads/media/fxl. */
const V2_PAGE_IMAGES = [
    '/nos-actions/' => ['hero' => '2022-07-cowdfunding-2022-24.jpg', 'cards' => [
        'Le sport comme outil' => '2022-05-waterfight-piscine.jpg', 'Soutien à l’hôpital' => '2022-05-enfant-lit-hopital.jpg', 'Information & prévention' => '2023-01-0i0a1230.jpg']],
    '/le-sport-comme-outil/' => ['hero' => '2022-05-femininn.jpg', 'sec' => ['approche' => '2022-07-fightxlife-8.jpg.jpg']],
    '/soutien-a-lhopital/' => ['hero' => '2022-07-7124ea94-e35a-4e0e-99ff-8983ca3b2570.jpeg', 'sec' => ['lien' => '2023-01-img_0305.jpeg'],
        'cards' => ['La SmileBox' => '2023-01-ny-20220827-smile-box-5262.jpg', 'Benkick, notre mascotte' => '2023-01-ny-20220827-smile-box-5273.jpg']],
    '/information-prevention/' => ['hero' => '2023-01-0i0a1230.jpg', 'sec' => ['benkick' => '2023-01-ny-20220827-smile-box-5273.jpg']],
    '/nous-soutenir/' => ['hero' => '2022-05-visite-hopitaux-4.jpg'],
    '/cours/' => ['hero' => '2022-05-femininn.jpg'],
    '/boxe-adaptee/' => ['hero' => '2022-06-woman-fighting-breast-cancer-awareness-scaled.jpg'],
    '/waterxfight/' => ['hero' => '2022-05-waterfight-piscine.jpg'],
    '/100-feminin/' => ['hero' => 'sara-ponpqw0gqz9udxela26ghmgghhjey2rgcpahom9pdk.jpg'],
    '/nage-libre-femmes/' => ['hero' => '2022-06-man-hit-water-punch-concept-power-assertiveness.jpg'],
    '/notre-impact/' => ['hero' => '2022-05-cowdfunding-2022-2.jpg'],
    '/smile-box/' => ['hero' => '2023-01-ny-20220827-smile-box-5262.jpg', 'sec' => ['lien-ecoles' => '2022-07-7124ea94-e35a-4e0e-99ff-8983ca3b2570.jpeg'],
        'cards' => ['Benkick, une peluche pédagogique et émotionnelle' => '2023-01-ny-20220827-smile-box-5273.jpg', 'Un t-shirt et une casquette FightxLife' => '2022-05-fightxlife-merch-scaled.jpg']],
    '/benkick-mascotte/' => ['hero' => '2023-01-ny-20220827-smile-box-5273.jpg', 'sec' => ['peluche' => '2022-05-fightxlife-merch-30.jpg']],
    '/qui-sommes-nous/' => ['hero' => 'sara-ponpqw0gqz9udxela26ghmgghhjey2rgcpahom9pdk.jpg', 'sec' => ['histoire' => '2022-05-gala-sara-scaled.jpg']],
    '/partenaires-soutiens/' => ['logos' => ['La Roche-Posay : des soins pour la SmileBox' => '2022-05-nos_sponsors_7-removebg-preview.png', 'Cera : un soutien à la création de Benkick' => '2025-02-logo_cera_baseline_fr.png']],
];
/** The five activities: their photo on the activity cards. */
const V2_ACTIVITY_IMAGES = [
    'Boxe adaptée' => '2022-06-woman-fighting-breast-cancer-awareness-scaled.jpg', 'WaterxFight' => '2022-05-waterfight-piscine.jpg',
    'Boxe 100 % féminin' => '2022-05-femininn.jpg', 'Nage libre' => '2022-06-man-hit-water-punch-concept-power-assertiveness.jpg',
    'Cours de natation' => '2022-05-waterfight-piscine.jpg',
];
/** The menu section a page belongs to (the document number's first digit), shown as the hero badge. */
const V2_SECTION_OF = ['2' => 'Nos actions', '3' => 'Nos cours', '4' => 'Projets & impact', '5' => 'À propos', '6' => 'Contact'];
/** CTA kinds: buttons (pink, then outlined) and the lighter text links. Anything else is a text link. */
const V2_PRIMARY = ['Primary CTA', 'Member purchase CTA', 'Email CTA', 'Contextual CTA'];
const V2_GHOST = ['Secondary CTA', 'Member link', 'Anchor link', 'Secondary anchor link', 'Email link', 'Phone link'];
const V2_SKIP = ['Contact form submit button', 'Donation form submit button'];

function native_v2_pages(array $pill, array $pillHover, array $base, \Closure $up, \Closure $V): array
{
    $lift = shadow(...LIFT);
    $rich = ['paragraph' => S(col(BODY), ty('16px', null, '28px'), mar(0, 0, 14, 0)), 'link' => S(col(PINK), ty(null, 'bold')), 'bold' => col(INK),
        'list' => S(mar(4, 0, 16, 0), pad(null, null, null, 22)), 'item' => S(col(BODY), ty('15.5px', null, '26px'), mar(0, 0, 6, 0)), 'marker' => col(PINK),
        'heading' => S(col(INK), mar(18, 0, 10, 0))];
    return [
        // ── Hero ──
        'v2-phero-sec'   => ['s' => S(grad('linear-gradient(180deg,#fff4fa 0%,#ffffff 100%)'), $V(64, 76), dim(['overflow' => 'hidden'])), 't' => $V(44, 60), 'm' => $V(28, 46)],
        'v2-phero'       => ['s' => lay(['tracks' => 'minmax(0,1.05fr) minmax(0,.95fr)', 'gap' => '64px', 'align' => 'center']), 't' => lay(['tracks' => '1fr', 'gap' => '40px'])],
        'v2-phero--text' => ['s' => S(dim(['max_width' => '860px']), mar(null, 'auto', null, 'auto')), 'center' => true],
        'v2-phero__title' => ['s' => S(ty('clamp(36px,4vw,56px)', 'bold', '1.08', '-.035em'), mar(0, null, 20, null)), 'm' => ty('32px'), 'parts' => ['highlight' => col(PINK)],
                              'anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 80, 'easing' => EASE]],
        'v2-phero__lead' => ['s' => S(col(INK), ty('18.5px', null, '1.65'), mar(0, null, 14, null)), 'm' => ty('16.5px'), 'anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 150, 'easing' => EASE]],
        'v2-phero__text' => ['anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 220, 'easing' => EASE], 'parts' => $rich + ['paragraph' => S(col(BODY), ty('16px', null, '28px'), mar(14, 0, 0, 0))]],
        'v2-phero__photo' => ['s' => S(dim(['height' => '470px', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('30px'), shadow(...LIFT)), 't' => dim(['height' => '400px']), 'm' => dim(['height' => '280px']),
                              'anim' => ['type' => 'scale_up', 'duration_ms' => 1000, 'delay_ms' => 120, 'easing' => EASE]],
        'v2-phero__links' => ['s' => S(lay(['gap' => '20px']), mar(18, null, 0, null))],
        'v2-phero--text v2-actions' => ['s' => lay(['justify' => 'center'])],
        'v2-phero--text v2-phero__links' => ['s' => lay(['justify' => 'center'])],

        // ── Sections ──
        'v2-sec'         => ['s' => S(bg('#ffffff'), $V(84, 84)), 'm' => $V(54, 54)],
        'v2-sec--soft'   => ['s' => bg('#fbf6f8')],
        'v2-narrow'      => ['s' => S(dim(['max_width' => '820px']), mar(null, 'auto', null, 'auto')), 'reveal' => $up(0)],
        'v2-narrow--center' => ['center' => true],
        'v2-text'        => ['parts' => $rich],
        'v2-split2'      => ['s' => lay(['tracks' => 'minmax(0,1fr) minmax(0,.9fr)', 'gap' => '64px', 'align' => 'center']), 't' => lay(['tracks' => '1fr', 'gap' => '36px'])],
        'v2-split2__photo' => ['s' => S(dim(['height' => '440px', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('26px'), shadow(...LIFT)), 'm' => dim(['height' => '280px']),
                              'reveal' => ['type' => 'scale_up', 'duration_ms' => 900, 'easing' => EASE]],
        'v2-split2__copy' => ['reveal' => $up(0)],
        'v2-split2--top' => ['s' => lay(['tracks' => 'minmax(0,1.25fr) minmax(0,.75fr)', 'align' => 'start', 'gap' => '40px'])],
        'v2-aside'       => ['s' => S(pad(30, 28), radius('22px'), bg('#fbf6f8'), brd('#f2e4ec', '1px', '22px')), 'reveal' => $up(120)],
        'v2-fact--in v2-more' => ['s' => S(mar(8, null, 0, null), ty('13.5px'))],
        'v2-links'       => ['s' => S(lay(['gap' => '22px']), mar(10, null, 0, null))],

        // ── Cards (the H3 blocks) ──
        'v2-grid'        => ['s' => S(lay(['gap' => '26px', 'align' => 'stretch']), mar(8, null, 0, null)), 'props' => ['columns' => 3], 't' => lay(['gap' => '18px']), 'tp' => ['columns' => 2], 'mp' => ['columns' => 1]],
        'v2-grid--2'     => ['props' => ['columns' => 2]],
        'v2-grid--4'     => ['props' => ['columns' => 4], 'tp' => ['columns' => 2]],
        'v2-box'         => ['s' => S(place('relative'), radius('22px'), bg('#ffffff'), $lift, dim(['overflow' => 'hidden']), fx(['transition' => ['duration_ms' => 350, 'easing' => 'ease']])),
                              'h' => S(fx(['transform' => ['translate_y' => '-6px']]), shadow(0, 30, 70, 'rgba(70,10,48,.17)', -8)), 'reveal' => $up(0)],
        'v2-box__photo'  => ['s' => S(dim(['height' => '210px', 'overflow' => 'hidden']), bg('#f4eef1'))],
        'v2-box__img'    => ['s' => dim(['height' => '100%', 'object_fit' => 'cover'])] + zoom(1.05, 600),
        'v2-box__logo'   => ['s' => S(dim(['height' => '120px', 'object_fit' => 'contain']), pad(22, 30), bg('#ffffff'), side('bottom', '#f3e8ee'))],
        'v2-box__body'   => ['s' => pad(28, 28, 28, 28), 'm' => pad(22, 20, 24, 20)],
        'v2-box__icon'   => ['s' => S(tile(50, '15px', PINK_SOFT), mar(0, null, 18, null)), 'props' => ['size_px' => 23]],
        'v2-box__title'  => ['s' => S(ty('21px', 'bold', '1.28', '-.012em'), mar(0, null, 12, null))],
        'v2-box__text'   => ['parts' => $rich + ['paragraph' => S(col(BODY), ty('15px', null, '25px'), mar(0, 0, 10, 0)), 'item' => S(col(BODY), ty('14.5px', null, '23px'), mar(0, 0, 4, 0))]],
        'v2-box__links'  => ['s' => S(lay(['gap' => '10px']), mar(8, null, 0, null))],
        'v2-btn--sm'     => ['parts' => ['button' => S(sz(['height' => 46]), pad(0, 20), ty('14px'))]],
        'v2-ghost--sm'   => ['parts' => ['button' => S(sz(['height' => 46]), pad(0, 20), ty('14px'))]],

        // ── Facts (Label : value) ──
        'v2-facts'       => ['s' => S(lay(['gap' => '14px']), mar(6, null, 0, null)), 'props' => ['columns' => 3], 'tp' => ['columns' => 2], 'mp' => ['columns' => 1], 'reveal' => $up(0)],
        'v2-facts--2'    => ['props' => ['columns' => 2]],
        'v2-fact'        => ['s' => S(pad(20, 22), radius('18px'), bg('#ffffff'), brd('#f2e4ec', '1px', '18px'), shadow(0, 12, 30, 'rgba(70,10,48,.06)', -6))],
        'v2-sec--soft v2-fact' => ['s' => bg('#ffffff')],
        'v2-fact__label' => ['s' => S(col(PINK), ty('12.5px', 'bold', '1.3', '.08em'), ['typography' => ['transform' => 'uppercase']], mar(0, null, 8, null))],
        'v2-fact__value' => ['s' => S(col(INK), ty('15.5px', null, '1.55'))],
        'v2-fact--in'    => ['s' => S(pad(12, 16), radius('12px'), bg('#fbf6f8'), ['border' => ['width' => '0px']], shadow(0, 0, 0, 'transparent'), mar(0, null, 10, null))],

        // ── Figures and quotes ──
        'v2-stats'       => ['s' => lay(['gap' => '20px']), 'props' => ['columns' => 3], 'tp' => ['columns' => 2], 'mp' => ['columns' => 1], 'reveal' => $up(0)],
        'v2-stat'        => ['s' => S(pad(30, 28), radius('22px'), bg('#ffffff'), $lift), 'center' => true],
        'v2-stat__num'   => ['s' => S(col(PINK), ty('clamp(30px,3vw,40px)', 'bold', '1.1', '-.02em', SYNE), mar(0, null, 10, null))],
        'v2-stat__text'  => ['s' => S(col(BODY), ty('15px', null, '1.55'))],
        'v2-quotes'      => ['s' => lay(['gap' => '22px']), 'props' => ['columns' => 3], 'mp' => ['columns' => 1], 'reveal' => $up(0)],
        'v2-q'           => ['s' => S(pad(30, 28), radius('22px'), grad('linear-gradient(150deg,#ff3fa6 0%,#e9008a 60%,#b0006a 100%)'), shadow(0, 24, 50, 'rgba(233,0,138,.25)', -10))],
        'v2-q__text'     => ['s' => S(col('#ffffff'), ty('19px', 'bold', '1.4', null, SYNE))],
        'v2-q__name'     => ['s' => S(col('#ffffff'), ty('19px', null, '1.4', null, SCRIPT), mar(16, null, 0, null))],

        // ── Call-to-action band ──
        'v2-band'        => ['s' => S(place('relative'), pad(60, 56), radius('28px'), grad('linear-gradient(135deg,#ff3fa6 0%,#e9008a 50%,#a3005f 100%)'), shadow(0, 30, 70, 'rgba(233,0,138,.3)', -12)),
                              't' => pad(46, 36), 'm' => pad(34, 22), 'center' => true, 'reveal' => ['type' => 'scale_up', 'duration_ms' => 900, 'easing' => EASE]],
        'v2-band v2-kick' => ['s' => col('#ffd3ea')],
        'v2-band v2-h2'  => ['s' => col('#ffffff'), 'parts' => ['highlight' => col('#ffd3ea')]],
        'v2-band v2-text' => ['parts' => ['paragraph' => S(col('rgba(255,255,255,.9)'), ty('16.5px', null, '28px'), mar(0, 0, 12, 0)), 'link' => col('#ffffff'), 'bold' => col('#ffffff'), 'item' => col('rgba(255,255,255,.9)'), 'marker' => col('#ffffff')]],
        'v2-band v2-actions' => ['s' => lay(['justify' => 'center'])],
        'v2-band v2-links' => ['s' => lay(['justify' => 'center'])],
        'v2-band v2-more' => ['s' => col('#ffffff')],
        'v2-band v2-btn' => ['parts' => ['button' => S(['background' => ['gradient' => 'linear-gradient(140deg,#ffffff 0%,#ffffff 100%)']], col(PINK), shadow(0, 14, 30, 'rgba(60,0,35,.25)', -6))]],
        'v2-band v2-ghost' => ['parts' => ['button' => S(bg('transparent'), col('#ffffff'), brd('rgba(255,255,255,.65)', '1.5px', '999px'), shadow(0, 0, 0, 'transparent')),
                'button:hover' => S(col('#ffffff'), bg('rgba(255,255,255,.14)'), ['border' => ['color' => '#ffffff']])]],

        // ── Modules ──
        'v2-panel'       => ['s' => S(pad(36, 36), radius('24px'), bg('#ffffff'), $lift), 'm' => pad(24, 20), 'reveal' => $up(0)],
        'v2-give'        => ['s' => S(lay(['gap' => '12px', 'justify' => 'center']), mar(14, null, 6, null))],
        'v2-pay'         => ['parts' => ['button' => S($pill, sz(['height' => 58, 'min_width' => 150]), fx(['cursor' => 'pointer'])), 'button:hover' => $pillHover]],
        'v2-form'        => ['props' => ['labels' => 'visible'], 'parts' => [
                'form' => lay(['display' => 'grid', 'gap' => '18px']),
                'label' => S(col(INK), fam('body', '14px', 'bold'), mar(0, 0, 6, 0)),
                'input' => S(sz(['width' => '100%', 'height' => 52]), pad(0, 16), brd('#ead9e2', '1.5px', '14px'), bg('#fffafd'), col(INK), fam('body', '15px')),
                'textarea' => sz(['height' => 160, 'min_height' => 160]),
                'button' => S($pill, fx(['cursor' => 'pointer']))]],
        'v2-loop--featured' => ['parts' => ['image' => sz(['height' => 300])], 'mparts' => ['image' => sz(['height' => 210])]],
        'v2-event'       => ['s' => S(radius('22px'), bg('#ffffff'), $lift, dim(['overflow' => 'hidden']), fx(['transition' => ['duration_ms' => 300, 'easing' => 'ease']])),
                              'h' => fx(['transform' => ['translate_y' => '-6px']]), 'reveal' => $up(0)],
        'v2-event__img'  => ['s' => dim(['height' => '190px', 'object_fit' => 'cover'])],
        'v2-event__body' => ['s' => pad(22, 24, 24, 24)],
        'v2-event__date' => ['s' => S(col(PINK), ty('12.5px', 'bold', null, '.08em'), ['typography' => ['transform' => 'uppercase']], mar(0, null, 8, null))],
        'v2-event__title' => ['s' => S(ty('18px', 'bold', '1.3'), mar(0, null, 12, null))],
    ];
}

/** An icon for a card, from words in its title. */
function v2_icon(string $title): string
{
    $t = mb_strtolower($title);
    foreach (['hôpital' => 'heart', 'soin' => 'heart', 'enfant' => 'heart', 'sport' => 'zap', 'boxe' => 'zap', 'water' => 'zap', 'nage' => 'zap', 'natation' => 'zap', 'atelier' => 'sparkle',
        'créati' => 'sparkle', 'festi' => 'gift', 'smilebox' => 'gift', 'casquette' => 'gift', 'école' => 'message', 'information' => 'message', 'contenu' => 'message', 'événement' => 'calendar',
        'agenda' => 'calendar', 'actualit' => 'message', 'presse' => 'message', 'radio' => 'play', 'article' => 'message', 'bénévol' => 'users', 'partenai' => 'users', 'entreprise' => 'users',
        'association' => 'users', 'organisation' => 'users', 'mission' => 'star', 'valeur' => 'heart', 'tarif' => 'dollar', 'cotisation' => 'dollar', 'entrée' => 'dollar', 'carte' => 'dollar',
        'tenue' => 'check', 'débutant' => 'leaf', 'perfection' => 'award', 'piscine' => 'map-pin', 'réseaux' => 'instagram', 'benkick' => 'star', 'qui sommes' => 'users', 'bouger' => 'zap',
        'informer' => 'message', 'initiative' => 'gift', 'don' => 'heart'] as $k => $icon) {
        if (str_contains($t, $k)) { return $icon; }
    }
    return 'check-circle';
}

function v2_plain(string $html): string { return trim(html_entity_decode(strip_tags(str_replace('<br>', ' ', $html)), ENT_QUOTES | ENT_HTML5)); }

/** Paragraphs and lists as one rich text block. */
function v2_rich(array $items, string $cls = 'v2-text'): ?array
{
    $h = '';
    foreach ($items as $b) {
        if ($b['t'] === 'p') { $h .= '<p>' . $b['html'] . '</p>'; }
        elseif ($b['t'] === 'ul') { $h .= '<ul>' . implode('', array_map(static fn(string $i): string => '<li>' . $i . '</li>', $b['items'])) . '</ul>'; }
    }
    return $h === '' ? null : B('core.rich_text', ['content' => $h], [], $cls);
}

/**
 * "Label : value" paragraphs as facts ([label, [lines]]); a following line without a label continues the fact above.
 * Null when the paragraphs are not a fact list (fewer than two facts, or the first is not one).
 */
function v2_facts(array $ps): ?array
{
    $facts = [];
    foreach ($ps as $b) {
        if ($b['t'] !== 'p') { return null; }
        $plain = v2_plain($b['html']);
        if (preg_match('/^([^:«]{2,34}?)\s:\s(.+)$/u', $plain, $m) && !preg_match('/\d{2,}\s*$/u', $m[1])) {
            $facts[] = [$m[1], [$m[2]]];
        } elseif ($facts) {
            $facts[count($facts) - 1][1][] = $plain;
        } else {
            return null;
        }
    }
    return count($facts) >= 2 ? $facts : null;
}
function v2_fact_grid(array $facts, int $cols = 3): array
{
    $cells = array_map(static fn(array $f): array => BOX([T($f[0], 'v2-fact__label'), T(implode("\n", $f[1]), 'v2-fact__value')], 'v2-fact'), $facts);
    return B('layout.grid', ['columns' => $cols, 'gap' => 'none'], $cells, $cols === 2 ? 'v2-facts v2-facts--2' : 'v2-facts');
}

/** Buttons (pink then outlined) and text links for a list of CTAs. */
function v2_ctas(array $ctas, bool $small = false): array
{
    $buttons = []; $links = [];
    foreach ($ctas as $c) {
        if (in_array($c['kind'], V2_SKIP, true) || str_starts_with($c['href'], 'module:')) { continue; }
        $href = $c['href'];
        if (in_array($c['kind'], V2_PRIMARY, true)) { $buttons[] = V2BTN($c['label'], $href, $small ? 'v2-btn v2-btn--sm' : 'v2-btn'); }
        elseif (in_array($c['kind'], V2_GHOST, true)) { $buttons[] = V2BTN($c['label'], $href, $small ? 'v2-ghost v2-ghost--sm' : 'v2-ghost'); }
        else { $links[] = MORE($c['label'], $href); }
    }
    $out = [];
    if ($buttons) { $out[] = B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap'], $buttons, $small ? 'v2-box__links' : 'v2-actions'); }
    if ($links) { $out[] = B('layout.flex', ['direction' => $small ? 'column' : 'row', 'gap' => 'none', 'wrap' => 'wrap'], $links, $small ? 'v2-box__links' : 'v2-links'); }
    return $out;
}

/** Split a block list into text (p/ul), CTAs and notes. */
function v2_parts(array $blocks): array
{
    $text = []; $ctas = []; $notes = []; $h3s = [];
    foreach ($blocks as $b) {
        match ($b['t']) { 'p', 'ul' => $text[] = $b, 'cta' => $ctas[] = $b, 'note' => $notes[] = $b['text'], 'h3' => $h3s[] = $b, default => null };
    }
    return [$text, $ctas, $notes, $h3s];
}

/** One H3 block as a card: photo, logo or icon, title, text (or facts), and its own links. */
function v2_card(array $h3, string $url, int $i): array
{
    [$text, $ctas] = v2_parts($h3['blocks']);
    $cfg = V2_PAGE_IMAGES[$url] ?? [];
    $kids = [];
    $photo = $cfg['cards'][$h3['text']] ?? (V2_ACTIVITY_IMAGES[$h3['text']] ?? null);
    $logo = $cfg['logos'][$h3['text']] ?? null;
    $body = [];
    if ($logo) { $kids[] = V2IMG($logo, $h3['text'], 'v2-box__logo'); }
    elseif ($photo) { $kids[] = BOX([V2IMG($photo, $h3['text'], 'v2-box__img')], 'v2-box__photo'); }
    else { $body[] = ICO(v2_icon($h3['text']), 'v2-box__icon'); }
    $body[] = H($h3['text'], 'h3', 'v2-box__title');
    if ($text) {
        $facts = v2_facts($text);
        // Media items ("Outlet : title") each take their own link, in order (the press page).
        $media = array_values(array_filter($ctas, static fn(array $c): bool => $c['kind'] === 'Media link'));
        if ($facts && count($media) === count($facts)) {
            foreach ($facts as $k => $f) { $body[] = BOX([T($f[0], 'v2-fact__label'), T(implode("\n", $f[1]), 'v2-fact__value'), MORE($media[$k]['label'], $media[$k]['href'])], 'v2-fact v2-fact--in'); }
            $ctas = array_values(array_filter($ctas, static fn(array $c): bool => $c['kind'] !== 'Media link'));
        } elseif ($facts) { array_push($body, ...array_map(static fn(array $f): array => BOX([T($f[0], 'v2-fact__label'), T(implode("\n", $f[1]), 'v2-fact__value')], 'v2-fact v2-fact--in'), $facts)); }
        elseif ($r = v2_rich($text, 'v2-box__text')) { $body[] = $r; }
    }
    array_push($body, ...v2_ctas($ctas, true));
    $kids[] = BOX($body, 'v2-box__body');
    $box = BOX($kids, 'v2-box');
    $box['interactions'] = ['trigger' => 'viewport-enter', 'animation' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => ($i % 3) * 110, 'easing' => EASE]];
    return $box;
}
function v2_cards(array $h3s, string $url): array
{
    $n = count($h3s);
    $cls = $n === 2 || $n === 4 ? 'v2-grid v2-grid--2' : 'v2-grid';
    return B('layout.grid', ['columns' => $n === 2 || $n === 4 ? 2 : 3, 'gap' => 'none'], array_map(static fn(array $h, int $i): array => v2_card($h, $url, $i), $h3s, array_keys($h3s)), $cls);
}

/** The page hero: badge, H1, lead, intro text, buttons and links, and the page photo beside them when it has one. */
function v2_hero(array $pg): array
{
    [$text, $ctas] = v2_parts($pg['intro']);
    // The document's own title line ("3.2. WaterxFight") is not page copy.
    $text = array_values(array_filter($text, static fn(array $b): bool => !($b['t'] === 'p' && preg_match('/^\d+(\.\d+)*\.\s/u', v2_plain($b['html'])))));
    $section = V2_SECTION_OF[$pg['doc'][0]] ?? 'FightxLife';
    $copy = [B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [ICO('heart', 'v2-badge__dot'), T($section)], 'v2-badge'), H($pg['h1'], 'h1', 'v2-phero__title')];
    if ($text && $text[0]['t'] === 'p') { $copy[] = T(v2_plain(array_shift($text)['html']), 'v2-phero__lead'); }
    if ($r = v2_rich($text, 'v2-phero__text')) { $copy[] = $r; }
    array_push($copy, ...array_map(static function (array $b): array {
        $b['classNames'] = array_map(static fn(string $c): string => $c === 'v2-links' ? 'v2-phero__links' : $c, $b['classNames'] ?? []);
        return $b;
    }, v2_ctas($ctas)));
    $photo = V2_PAGE_IMAGES[$pg['url']]['hero'] ?? null;
    if ($photo) {
        return SEC('Hero', [B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [BOX($copy), V2IMG($photo, $pg['h1'], 'v2-phero__photo')], 'v2-phero')], 'v2-phero-sec');
    }
    return SEC('Hero', [BOX($copy, 'v2-phero--text')], 'v2-phero-sec');
}

/** The section's module, when a document note asks for one. */
function v2_module(array $notes, array $sec, array $pg): ?array
{
    global $tid;
    $all = implode("\n", $notes);
    if (str_contains($all, 'Contact form module')) {
        $reasons = [];
        $labels = [];
        $after = [];
        $success = [];
        $phase = 'fields';
        foreach ($sec['blocks'] as $b) {
            if ($b['t'] === 'note' && str_starts_with($b['text'], 'Success state')) { $phase = 'success'; continue; }
            if ($b['t'] === 'note' && str_starts_with($b['text'], 'End of')) { $phase = 'after'; continue; }
            if ($b['t'] === 'ul') { $reasons = array_map('v2_plain', $b['items']); continue; }
            if ($b['t'] !== 'p') { continue; }
            $p = v2_plain($b['html']);
            if ($phase === 'success') { $success[] = $p; } elseif ($phase === 'after') { $after[] = $b; } elseif ($p !== 'Motif de votre demande') { $labels[] = $p; }
        }
        $privacy = array_values(array_filter($labels, static fn(string $l): bool => mb_strlen($l) > 60));
        $f = static fn(string $type, string $name, string $label, bool $req, array $extra = []): array =>
            B('core.form_field', array_replace(['field_type' => $type, 'name' => $name, 'label' => $label, 'placeholder' => '', 'required' => $req], $extra));
        $form = B('core.form', ['form_name' => 'contact-v2', 'submit_text' => 'Envoyer ma demande', 'submit_variant' => 'primary', 'success_message' => implode(' ', $success)], [
            $f('select', 'motif', 'Motif de votre demande', true, ['options' => implode(',', $reasons)]),
            $f('text', 'nom', 'Nom et prénom', true), $f('email', 'email', 'Adresse e-mail', true), $f('tel', 'telephone', 'Téléphone (facultatif)', false),
            $f('text', 'organisation', 'Organisation / établissement', false), $f('text', 'objet', 'Objet de votre demande', true), $f('textarea', 'message', 'Votre message', true),
        ], 'v2-form', ['id' => STABLE_ID('v2-contact-form')]);
        $side = array_merge(array_map(static fn(string $t): array => B('core.rich_text', ['content' => '<p>' . htmlspecialchars($t) . ' <a href="/politique-de-confidentialite/">Consulter la politique de confidentialité</a></p>'], [], 'v2-text'), $privacy),
            $after ? [v2_rich($after)] : []);
        return B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [BOX([$form], 'v2-panel'), BOX($side, 'v2-aside')], 'v2-split2 v2-split2--top');
    }
    if (str_contains($all, 'Donation module')) {
        return BOX([
            KICK('Je soutiens FightxLife'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'justify' => 'center', 'wrap' => 'wrap'], array_map(static fn(int $a): array =>
                B('payment.button', ['label' => "Donner {$a} €", 'amount' => $a, 'currency' => 'EUR', 'description' => 'Don à FightxLife ASBL'], [], 'v2-pay'), [10, 25, 50, 100]), 'v2-give'),
        ], 'v2-panel v2-narrow--center');
    }
    if (str_contains($all, 'Featured news block')) {
        return B('core.query_loop', ['source' => 'posts', 'category' => 'Actualités', 'per_page' => 1, 'columns' => 1, 'card_variant' => 'horizontal', 'show_author' => false, 'show_category' => true,
            'enable_pagination' => false, 'read_more_text' => 'Lire l’article →', 'title_level' => 'h3', 'empty_message' => ''], [], 'v2-loop v2-loop--featured');
    }
    if (str_contains($all, 'News archive')) {
        return B('core.query_loop', ['source' => 'posts', 'category' => 'Actualités', 'per_page' => 9, 'columns' => 3, 'card_variant' => 'card', 'show_author' => false, 'show_category' => true,
            'enable_pagination' => true, 'read_more_text' => 'Lire la suite →', 'title_level' => 'h3', 'empty_message' => ''], [], 'v2-loop');
    }
    if (str_contains($all, 'Past events module')) {
        global $CONTENT_V1, $EXTRA_V1;
        $cards = [];
        foreach ($EXTRA_V1['events'] ?? [] as $slug => $ev) {
            $it = $CONTENT_V1["mec-events:{$slug}"] ?? null;
            if (!$it) { continue; }
            $img = (static function (string $u): string { global $IMGMAP_V1; return $IMGMAP_V1[preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $u)] ?? $IMGMAP_V1[$u] ?? ''; })((string) ($it['featured'] ?? ''));
            $kids = [];
            if ($img !== '' && MID($img) > 0) { $kids[] = V2IMG(basename($img), $it['title'], 'v2-event__img', ['link_to' => 'custom', 'url' => "/events/{$slug}/"]); }
            $kids[] = BOX([T(trim($ev['date'] . ($ev['heure'] !== '' ? ' · ' . $ev['heure'] : '')), 'v2-event__date'), B('core.heading', ['text' => $it['title'], 'level' => 'h3', 'url' => "/events/{$slug}/"], [], 'v2-event__title'), MORE('Voir l’événement', "/events/{$slug}/")], 'v2-event__body');
            $cards[] = BOX($kids, 'v2-event');
        }
        return $cards ? B('layout.grid', ['columns' => 3, 'gap' => 'none'], $cards, 'v2-grid') : null;
    }
    return null;
}

/** One H2 section of a page. */
function v2_section(array $sec, array $pg, int $i, bool $last): ?array
{
    [$text, $ctas, $notes, $h3s] = v2_parts($sec['blocks']);
    $all = implode("\n", $notes);
    if (str_contains($all, 'Team block')) { return null; } // no approved team entries supplied: the document says to hide the section
    $soft = $i % 2 === 1;
    $head = [KICK(), H($sec['h2'], 'h2', 'v2-h2')];
    $module = v2_module($notes, $sec, $pg);
    $photo = V2_PAGE_IMAGES[$pg['url']]['sec'][$sec['id']] ?? null;

    // The contact form keeps its own text beside the form.
    if ($module && str_contains($all, 'Contact form module')) {
        return SEC($sec['h2'], [BOX($head, 'v2-head v2-head--center'), $module], 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
    }
    // Upcoming events: there is none, so the document's empty state shows.
    if (str_contains($all, 'Upcoming events module')) {
        $body = array_filter([v2_rich($text)]);
        return SEC($sec['h2'], [BOX(array_merge($head, $body, v2_ctas(array_map(static fn(array $c): array => ['kind' => 'Primary CTA'] + $c, $ctas))), 'v2-panel v2-narrow--center')], 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
    }
    // Figures and testimonials.
    if ($sec['id'] === 'chiffres') {
        $stats = []; $rest = [];
        foreach ($text as $b) {
            $p = $b['t'] === 'p' ? v2_plain($b['html']) : '';
            if ($p !== '' && preg_match('/^(.{1,30}?)\s:\s(.+)$/u', $p, $m)) { $stats[] = BOX([T($m[1], 'v2-stat__num'), T($m[2], 'v2-stat__text')], 'v2-stat'); } else { $rest[] = $b; }
        }
        return SEC($sec['h2'], [BOX($head, 'v2-head v2-head--center'), B('layout.grid', ['columns' => 3, 'gap' => 'none'], $stats, 'v2-stats'), BOX(array_filter([v2_rich($rest)]), 'v2-narrow v2-narrow--center')], 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
    }
    if ($sec['id'] === 'temoignages') {
        $quotes = []; $q = null; $rest = [];
        foreach ($text as $b) {
            $p = $b['t'] === 'p' ? v2_plain($b['html']) : '';
            if (str_starts_with($p, '«')) { $q = $p; } elseif ($q !== null && mb_strlen($p) < 40) { $quotes[] = BOX([T($q, 'v2-q__text'), T($p, 'v2-q__name')], 'v2-q'); $q = null; } else { $rest[] = $b; }
        }
        return SEC($sec['h2'], array_values(array_filter([BOX(array_merge($head, array_filter([v2_rich($rest)])), 'v2-head v2-head--center'), B('layout.grid', ['columns' => 3, 'gap' => 'none'], $quotes, 'v2-quotes')])), 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
    }

    // The closing call to action: a pink band.
    $hasButton = (bool) array_filter($ctas, static fn(array $c): bool => in_array($c['kind'], V2_PRIMARY, true));
    if ($last && !$h3s && !$module && !$photo && $hasButton) {
        $band = BOX(array_merge($head, array_filter([v2_rich($text)]), v2_ctas($ctas)), 'v2-band');
        return SEC($sec['h2'], [$band], 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
    }

    $facts = $text ? v2_facts($text) : null;
    $intro = $facts ? [] : $text;
    $blocks = [];
    if ($photo && !$h3s) {
        $copy = array_merge($head, array_filter([v2_rich($intro)]), v2_ctas($ctas));
        $blocks[] = B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], $i % 2 === 0
            ? [BOX($copy, 'v2-split2__copy'), V2IMG($photo, $sec['h2'], 'v2-split2__photo')]
            : [V2IMG($photo, $sec['h2'], 'v2-split2__photo'), BOX($copy, 'v2-split2__copy')], 'v2-split2');
    } else {
        $short = count($intro) <= 2 && !array_filter($intro, static fn(array $b): bool => $b['t'] === 'ul');
        $headBox = array_merge($head, array_filter([v2_rich($intro)]));
        if ($h3s || $facts || $module) {
            $blocks[] = BOX($headBox, 'v2-head v2-head--center');
        } else {
            $blocks[] = BOX(array_merge($headBox, v2_ctas($ctas)), 'v2-narrow' . ($short ? ' v2-narrow--center' : ''));
            $ctas = [];
        }
        if ($facts) { $blocks[] = v2_fact_grid($facts, count($facts) === 4 ? 2 : 3); }
        if ($module) { $blocks[] = $module; }
        if ($h3s) { $blocks[] = v2_cards($h3s, $pg['url']); }
        if ($ctas) { $blocks[] = BOX(v2_ctas($ctas), 'v2-center'); }
    }
    return SEC($sec['h2'], $blocks, 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
}

/** Build every V2 page but the home page, each at "<slug>-v2", with the document's SEO title and description. */
function v2_build_pages(array $pages): void
{
    global $CONTENT_V1, $EXTRA_V1, $IMGMAP_V1;
    $data = __DIR__ . '/../fxl';
    $CONTENT_V1 = [];
    foreach (json_decode((string) file_get_contents("{$data}/content.json"), true) as $item) { $CONTENT_V1[$item['kind'] . ':' . $item['slug']] = $item; }
    $EXTRA_V1 = json_decode((string) file_get_contents("{$data}/extra.json"), true);
    $IMGMAP_V1 = json_decode((string) file_get_contents("{$data}/image-map.json"), true);
    foreach ($pages as $pg) {
        if ($pg['url'] === '/') { continue; }
        $sections = [v2_hero($pg)];
        $n = count($pg['sections']);
        foreach ($pg['sections'] as $i => $sec) {
            $s = v2_section($sec, $pg, $i, $i === $n - 1);
            if (!$s) { continue; }
            // The document's HTML id, so its anchor links (/waterxfight/#infos-pratiques) land on the section.
            if ($sec['id'] !== '') { $s['blocks'][0]['attributes'] = ['id' => $sec['id']]; }
            $sections[] = $s;
        }
        foreach ($sections as &$s) { v2_relink($s); }
        unset($s);
        $slug = trim(V2URL($pg['url']), '/');
        $photo = V2_PAGE_IMAGES[$pg['url']]['hero'] ?? null;
        build_page($slug, $pg['h1'] . ' (V2)', 'page', 'standalone', $sections, ['seo_robots' => 'noindex,nofollow'], $pg['seo_title'], $pg['meta'],
            $photo ? (MID('/uploads/media/fxl/' . $photo) ?: null) : null);
    }
}
