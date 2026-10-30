<?php
/**
 * FightxLife V2 — every page of the V2 content pack except the home page, in the approved V2 design (v2.php builds the
 * home page, header and footer and calls v2_build_pages()).
 *
 * The text comes from fxl/v2-content.json, made from the client's documents (one per page): SEO title and description,
 * H1, intro, then the H2 sections with their H3 blocks, paragraphs, lists and labelled calls to action. The documents'
 * English implementation notes are not shown; the few that ask for a module (contact form, donations, news, events,
 * team) choose how their section is built. One engine lays every page out:
 *   - a hero: the menu section as a badge, the H1, the intro and its buttons at 70%, the page's photo at 30%;
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
    '/cours-de-natation/' => ['hero' => '2025-05-design-sans-titre-5.jpg'],
    '/a-propos/' => ['hero' => '2023-01-dw7a7907.jpg'],
    '/actualites/' => ['hero' => '2025-01-f981f540-e91a-4938-ae51-19f73712c56b.jpg'],
    '/agenda/' => ['hero' => '2022-06-calendar-appointment-scheduler-with-pin-pink-background-scaled.jpg'],
    '/presse/' => ['hero' => '2022-06-4k-high-definition-video-camera-monitor-tripod-crane-studio-softbox-paper-scaled.jpg'],
    '/contact/' => ['hero' => '2022-07-pink-notebook-pens-pink-background-scaled.jpg'],
    '/partenaires-soutiens/' => ['hero' => '2022-06-smiling-women-organising-event-breast-cancer-awareness-1-scaled.jpg', 'logos' => ['La Roche-Posay : des soins pour la SmileBox' => '2022-05-nos_sponsors_7-removebg-preview.png', 'Cera : un soutien à la création de Benkick' => '2025-02-logo_cera_baseline_fr.png']],
];
/** The five activities: their photo on the activity cards. */
const V2_ACTIVITY_IMAGES = [
    'Boxe adaptée' => '2022-06-woman-fighting-breast-cancer-awareness-scaled.jpg', 'WaterxFight' => '2022-05-waterfight-piscine.jpg',
    'Boxe 100 % féminin' => '2022-05-femininn.jpg', 'Nage libre' => '2022-06-man-hit-water-punch-concept-power-assertiveness.jpg',
    'Cours de natation' => '2022-05-waterfight-piscine.jpg',
];
/** The hero photo of a page with none of its own. */
const V2_HERO_FALLBACK = '2022-06-smiling-women-organising-event-breast-cancer-awareness-1-scaled.jpg';
/** Text sections shown beside a photo, image 30% and text 70%: page, then section id. */
const V2_SIDE_IMAGES = [
    '/le-sport-comme-outil/' => ['pourquoi-sport' => '2023-03-boxe-adapte-2.jpg'],
    '/nos-actions/' => ['approche' => '2024-09-img_0498.jpeg'],
    '/information-prevention/' => ['comprendre' => '2024-01-img_7686.jpeg', 'demarche' => '2024-01-409664485_659793019694783_3153137091451259587_n.jpg'],
    '/cours/' => ['approche' => '2023-03-boxe-adapte-4.jpg'],
    '/boxe-adaptee/' => ['seance' => '2022-07-senior-woman-with-trainer-boxing-sparring.jpg'],
    '/waterxfight/' => ['pratique' => '2025-05-design-sans-titre-5.jpg'],
    '/100-feminin/' => ['pratique' => '2022-06-6c763bee83dde6f11bd493853c2c86cb-1582207165.jpg'],
    '/nage-libre-femmes/' => ['cadre' => '2025-05-design-sans-titre-5.jpg', 'choisir' => '2022-05-waterfight-piscine.jpg'],
    '/smile-box/' => ['recevoir-smilebox' => '2023-01-img_5082-scaled.jpeg'],
    '/presse/' => ['association' => '2023-01-0i0a1332-scaled.jpg'],
];

/** The menu section a page belongs to (the document number's first digit), shown as the hero badge. */
const V2_SECTION_OF = ['2' => 'Nos actions', '3' => 'Nos cours', '4' => 'Projets & impact', '5' => 'À propos', '6' => 'Contact'];
/** CTA kinds: buttons (pink, then outlined) and the lighter text links. Anything else is a text link. */
const V2_PRIMARY = ['Primary CTA', 'Member purchase CTA', 'Email CTA', 'Contextual CTA'];
const V2_GHOST = ['Secondary CTA', 'Member link', 'Anchor link', 'Secondary anchor link', 'Email link', 'Phone link'];
const V2_SKIP = ['Contact form submit button', 'Donation form submit button'];
/**
 * The team, grouped by role: the "Notre Équipe" block of the live site (names, roles and portraits as published there; it
 * has no biographies). The advisory board and the honorary committee stay out, as the document asks. Empty this to hide
 * the section again until the client sends approved entries.
 */
const V2_TEAM = [
    'Administration' => [['Sara R.', 'Fondatrice', 'sara-ponpqw0gqz9udxela26ghmgghhjey2rgcpahom9pdk.jpg'], ['Mirella', 'Administratrice', 'mirella-ponpu0t3j3k934uqzexostzrrq3glr78s7kr9tmgmw.jpg'],
        ['Nafissa', 'Administratrice', 'nafisa-ponpvl7ex5pgfqktu3bayfrfeudhflf4zypw2fau9k.jpg']],
    'Communication' => [['Valérie Cornelis', 'Attachée de presse', 'cornelis-valerie-ponq3f6zvgfh6r783h7dqglpmfrlksim2qfk1foqew.jpg']],
    'Coaching' => [['Romane Bellens', 'Coach sportive, trois fois championne d’Europe WCTS', 'bellens-romane-ponqm7ysk65xdzw6blqrlptlbv3viv5cns55knt9yw.jpg'],
        ['Inès Miloudi', 'Coach Cancerfighter', 'miloudi-ines-ponqm7ysk65xdzw6blqrlptlbv3viv5cns55knt9yw.jpg'], ['Sylvia', 'Coach WaterxFight', 'sylvia-ponr0bjn2pgrjfevzp5b05qi3xm2zf4elkfcq2womw.jpg']],
];
/** The contact links' ?reason= values (from the documents' links) and the motif each selects, by its place in the list. */
const V2_REASONS = ['information' => 0, 'cours' => 1, 'smilebox' => 2, 'hopital' => 3, 'sensibilisation' => 4, 'partenariat' => 5, 'benevolat' => 6, 'evenement' => 7, 'don' => 8, 'presse' => 9];
/** The ?activity= and ?project= values, as the object field shows them. */
const V2_CONTEXT = ['boxe-adaptee' => 'Boxe adaptée', 'waterxfight' => 'WaterxFight', 'boxe-feminin' => 'Boxe 100 % féminin', 'nage-libre' => 'Nage libre',
    'natation' => 'Cours de natation', 'smilebox' => 'La SmileBox', 'benkick' => 'Benkick'];

function native_v2_pages(array $pill, array $pillHover, array $base, \Closure $up, \Closure $V, array $outline, array $outlineHover, array $outlineFocus): array
{
    $lift = shadow(...LIFT);
    $rich = ['paragraph' => S(col(BODY), ty('16px', null, '28px'), mar(0, 0, 14, 0)), 'link' => S(col(PINK), ty(null, 'bold')), 'bold' => col(INK),
        'list' => S(mar(4, 0, 16, 0), pad(null, null, null, 22)), 'item' => S(col(BODY), ty('15.5px', null, '26px'), mar(0, 0, 6, 0)), 'marker' => col(PINK),
        'heading' => S(col(INK), mar(18, 0, 10, 0))];
    return [
        // ── Hero ──
        'v2-phero-sec'   => ['s' => S(grad('linear-gradient(180deg,#fff4fa 0%,#ffffff 100%)'), $V(64, 76), dim(['overflow' => 'hidden'])), 't' => $V(44, 60), 'm' => $V(28, 46)],
        'v2-phero'       => ['s' => lay(['tracks' => 'minmax(0,7fr) minmax(0,3fr)', 'gap' => '80px', 'align' => 'center']), 't' => lay(['tracks' => '1fr', 'gap' => '40px'])],
        'v2-phero__title' => ['s' => S(ty('clamp(36px,4vw,56px)', 'bold', '1.08', '-.035em'), mar(0, null, 20, null)), 'm' => ty('32px'), 'parts' => ['highlight' => col(PINK)],
                              'anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 80, 'easing' => EASE]],
        'v2-phero__lead' => ['s' => S(col(INK), ty('18.5px', null, '1.65'), mar(0, null, 14, null)), 'm' => ty('16.5px'), 'anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 150, 'easing' => EASE]],
        'v2-phero__text' => ['anim' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => 220, 'easing' => EASE], 'parts' => $rich + ['paragraph' => S(col(BODY), ty('16px', null, '28px'), mar(14, 0, 0, 0))]],
        'v2-phero__photo' => ['s' => S(dim(['aspect_ratio' => '16/15', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('30px'), shadow(...LIFT)), 't' => dim(['width' => '100%', 'height' => '400px']), 'm' => dim(['width' => '100%', 'height' => '280px']),
                              'anim' => ['type' => 'scale_up', 'duration_ms' => 1000, 'delay_ms' => 120, 'easing' => EASE]],
        'v2-phero__links' => ['s' => S(lay(['gap' => '20px']), mar(18, null, 0, null))],

        // ── Sections ──
        'v2-sec'         => ['s' => S(bg('#ffffff'), $V(84, 84)), 'm' => $V(54, 54)],
        'v2-sec--soft'   => ['s' => bg('#fbf6f8')],
        'v2-narrow'      => ['s' => S(dim(['max_width' => '820px']), mar(null, 'auto', null, 'auto')), 'reveal' => $up(0)],
        'v2-narrow--center' => ['center' => true],
        'v2-narrow--center v2-actions' => ['s' => lay(['justify' => 'center'])],
        'v2-narrow--center v2-links' => ['s' => lay(['justify' => 'center'])],
        'v2-center v2-actions' => ['s' => S(lay(['justify' => 'center']), mar(0, null, null, null))],
        'v2-center v2-links' => ['s' => lay(['justify' => 'center'])],
        'v2-text'        => ['parts' => $rich],
        'v2-split2'      => ['s' => lay(['tracks' => 'minmax(0,1fr) minmax(0,.9fr)', 'gap' => '64px', 'align' => 'center']), 't' => lay(['tracks' => '1fr', 'gap' => '36px'])],
        'v2-split2--bal' => ['s' => lay(['tracks' => 'minmax(0,3fr) minmax(0,7fr)', 'gap' => '80px', 'align' => 'center'])],
        'v2-split2__photo' => ['s' => S(dim(['height' => '440px', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('26px'), shadow(...LIFT)), 't' => lay(['order' => 2]), 'm' => S(dim(['height' => '280px']), lay(['order' => 2])), // stacked, the text comes first
           
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
        'v2-box'         => ['s' => S(place('relative'), radius('22px'), bg('#ffffff'), $lift, fx(['transition' => ['duration_ms' => 350, 'easing' => 'ease']])),
                              'h' => S(fx(['transform' => ['translate_y' => '-6px']]), shadow(0, 30, 70, 'rgba(70,10,48,.17)', -8)), 'reveal' => $up(0)],
        'v2-box__photo'  => ['s' => S(dim(['height' => '210px', 'overflow' => 'hidden']), bg('#f4eef1'), top_radius('22px'))],
        'v2-box__img'    => zoom(1.05, 600, dim(['height' => '100%', 'object_fit' => 'cover'])),
        'v2-box__logo'   => ['s' => S(dim(['height' => '120px', 'object_fit' => 'contain']), pad(22, 30), bg('#ffffff'), side('bottom', '#f3e8ee'), top_radius('22px'))],
        'v2-box__body'   => ['s' => pad(28, 28, 28, 28), 'm' => pad(22, 20, 24, 20)],
        'v2-box__icon'   => ['s' => S(tile(50, '15px', PINK_SOFT), mar(0, null, 18, null)), 'props' => ['size_px' => 23]],
        'v2-press__group' => ['s' => S(ty('26px', 'bold', '1.25', '-.015em'), mar(44, null, 20, null))],
        'v2-press__title' => ['s' => S(ty('18px', 'bold', '1.35'), mar(0, null, 12, null))],
        'v2-audio'       => ['s' => mar(0, null, 26, null), 'parts' => ['card' => S(pad(28, 32), radius('22px'), bg(PINK), shadow(0, 22, 50, 'rgba(233,0,138,.22)', -10)), 'title' => fam('heading', '24px', 'bold', '32px'), 'play' => col(PINK), 'link' => col('#ffffff')],
                              'mparts' => ['card' => pad(22, 20), 'title' => ty('19px')], 'reveal' => $up(0)],
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
        'v2-team__block' => ['s' => mar(0, null, 40, null), 'reveal' => $up(0)],
        'v2-team__group' => ['s' => S(col(PINK), ty('15px', 'bold', null, '.06em'), mar(0, null, 18, null)), 'center' => true],
        'v2-team'        => ['s' => lay(['gap' => '22px', 'justify' => 'center']), 'm' => lay(['gap' => '12px'])],
        'v2-team__card'  => ['s' => S(dim(['width' => '220px']), pad(24, 18), radius('22px'), bg('#ffffff'), $lift, fx(['transition' => ['duration_ms' => 300, 'easing' => 'ease']])),
                              'h' => fx(['transform' => ['translate_y' => '-6px']]), 'm' => S(dim(['width' => '46%']), pad(18, 10)), 'center' => true],
        'v2-team__photo' => ['s' => S(dim(['width' => '120px', 'height' => '120px', 'object_fit' => 'cover', 'overflow' => 'hidden']), radius('999px'), mar(0, 'auto', 14, 'auto'), brd('#ffd3ea', '4px', '999px')), 'm' => dim(['width' => '92px', 'height' => '92px'])],
        'v2-team__name'  => ['s' => S(col(INK), fam('heading', '18px', 'bold', '1.3'))],
        'v2-team__role'  => ['s' => S(col(BODY), ty('14px', null, '1.5'), mar(4, null, 0, null))],
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
        'v2-give2'       => ['s' => lay(['tracks' => 'minmax(0,1fr) minmax(0,1fr)', 'gap' => '28px']), 't' => lay(['tracks' => '1fr', 'gap' => '22px'])],
        'v2-give__title' => ['s' => S(ty('26px', 'bold', '1.2'), mar(6, null, 8, null))],
        'v2-give__text'  => ['s' => S(col(BODY), ty('15.5px', null, '1.6'), mar(0, null, 8, null))],
        'v2-give__free'  => ['s' => S(mar(18, null, 0, null), pad(20, 0, 0, 0), ['border' => ['top' => ['width' => '1px', 'style' => 'solid', 'color' => '#f1e3ea']]]), 'center' => true],
        'v2-pay--free'   => ['parts' => ['form' => lay(['justify' => 'center'])]],
        'v2-give__note'  => ['s' => S(col(BODY), ty('14px', null, '1.6'), mar(22, null, 0, null)), 'center' => true],
        'v2-pay'         => ['parts' => ['button' => S($outline, sz(['height' => 58, 'min_width' => 150]), fx(['cursor' => 'pointer'])), 'button:hover' => $outlineHover, 'button:focus' => $outlineFocus,
                'amount' => S(sz(['width' => '180px', 'height' => 58]), pad(0, 18), brd('#ead9e2', '1.5px', '999px'), bg('#fffafd'), col(INK), fam('body', '17px', 'bold'))]],
        'v2-form'        => ['props' => ['labels' => 'visible'], 'parts' => [
                'form' => lay(['display' => 'grid', 'gap' => '18px']),
                'label' => S(col(INK), fam('body', '14px', 'bold'), mar(0, 0, 6, 0)),
                'input' => S(sz(['width' => '100%', 'height' => 52]), pad(0, 16), brd('#ead9e2', '1.5px', '14px'), bg('#fffafd'), col(INK), fam('body', '15px')),
                'textarea' => sz(['height' => 160, 'min_height' => 160]),
                'button' => S($pill, fx(['cursor' => 'pointer']))]],
        'v2-loop--featured' => ['parts' => ['image' => sz(['height' => 300])], 'mparts' => ['image' => sz(['height' => 210])]],
        'v2-event'       => ['s' => S(radius('22px'), bg('#ffffff'), $lift, fx(['transition' => ['duration_ms' => 300, 'easing' => 'ease']])),
                              'h' => fx(['transform' => ['translate_y' => '-6px']]), 'reveal' => $up(0)],
        'v2-event__img'  => ['s' => S(dim(['height' => '190px', 'overflow' => 'hidden', 'object_fit' => 'cover']), top_radius('22px'))],
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

/** Press photos by the item's link. The live site's own, or a YouTube video's thumbnail where the live one is only 300px wide. */
const V2_PRESS_IMAGES = [
    'https://auvio.rtbf.be/media/tendances-premiere-tendances-premiere-les-tendanceurs-2859925' => 'press-auvio.jpg',
    'https://www.youtube.com/watch?v=IGSdV_Y3s8g' => 'press-arabel.jpg',
    'https://www.dhnet.be/lifestyle/people/2021/02/01/sara-de-paduwa-on-a-tous-envie-de-mettre-le-cancer-ko-MWF2AQUQORC7VP6IC2D6G65X7I/' => 'press-dh.webp',
    'https://www.rtbf.be/article/une-jeune-bruxelloise-se-lance-un-defi-pour-lutter-contre-le-cancer-10688290' => 'press-rtbf-defi.jpg',
    'https://www.rtbf.be/article/in-sara-rezki-we-trust-la-boxe-m-a-sauve-la-vie-10852866' => 'press-grenades.jpg',
    'https://bx1.be/communes/bruxelles-ville/de-la-boxe-pour-combattre-le-cancer/' => 'press-bx1.jpg',
    'https://www.youtube.com/watch?v=W0N25moJ8us' => 'press-petites.jpg',
    'https://www.rtbf.be/article/de-la-boxe-adaptee-pour-les-malades-du-cancer-venir-sur-le-ring-c-est-aller-vers-la-vie-10437246' => 'press-boxe-adaptee.jpg',
    'https://www.facebook.com/estellebalde/videos/sara-est-esperanza-interview/252699939717847/' => 'press-esperanza.jpg',
    'https://www.youtube.com/watch?v=PHx3K_nNnms' => 'press-clip.jpg',
    'https://www.youtube.com/watch?v=cBbOhe2XFIo' => 'press-hespress.jpg',
];

/** Copy a photo from the bundle's media folder into the site and register it, once. */
function v2_press_media(string $file): void
{
    if (MID('/uploads/media/fxl/' . $file) > 0) { return; }
    $src = dirname(__DIR__) . '/media/fxl/' . $file;
    $dst = getcwd() . '/uploads/media/fxl/' . $file;
    if (!is_file($src) || !copy($src, $dst)) { throw new RuntimeException("media {$file} missing from the bundle"); }
    $size = @getimagesize($dst) ?: [null, null, 'mime' => mime_content_type($dst) ?: ''];
    \Slate\Services\Media\Media::register('/uploads/media/fxl/' . $file, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize($dst), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => $file]);
}

/** One press item: its photo, the outlet, the title and the link. */
function v2_press_card(string $outlet, string $title, array $link, int $i): array
{
    $file = V2_PRESS_IMAGES[$link['href']] ?? throw new RuntimeException("no press photo for {$link['href']}");
    v2_press_media($file);
    $box = BOX([
        BOX([V2IMG($file, $title, 'v2-box__img', ['link_to' => 'custom', 'url' => $link['href'], 'new_tab' => true])], 'v2-box__photo'),
        BOX([T($outlet, 'v2-fact__label'), H($title, 'h4', 'v2-press__title'), MORE($link['label'], $link['href'])], 'v2-box__body'),
    ], 'v2-box');
    $box['interactions'] = ['trigger' => 'viewport-enter', 'animation' => ['type' => 'fade_up', 'duration_ms' => 800, 'delay_ms' => ($i % 3) * 110, 'easing' => EASE]];
    return $box;
}

/** The press review: the radio card, then each H3's items as a photo grid. Items are "Outlet : title" with a link each. */
function v2_press(array $sec, array $head): array
{
    [$text, , , $h3s] = v2_parts($sec['blocks']);
    $blocks = [BOX(array_merge($head, array_filter([v2_rich($text)])), 'v2-head v2-head--center')];
    foreach ($h3s as $n => $h3) {
        [$items, $ctas] = v2_parts($h3['blocks']);
        $links = array_values(array_filter($ctas, static fn(array $c): bool => $c['kind'] === 'Media link'));
        $blocks[] = H($h3['text'], 'h3', 'v2-press__group');
        if ($n === 0) {
            $blocks[] = B('core.audio', ['source' => 'soundcloud', 'url' => 'https://soundcloud.com/bxfmradio/positive-fem-sara-rezki-fightxlife', 'title' => 'Positive Fem - Sara Rezki - FightxLife', 'subtitle' => 'BXFM 104.3 Bruxelles'], [], 'v2-audio');
        }
        $cards = [];
        foreach (v2_facts($items) as $k => $f) { $cards[] = v2_press_card($f[0], implode(' ', $f[1]), $links[$k], $k); }
        $blocks[] = B('layout.grid', ['columns' => count($cards) === 2 ? 2 : 3, 'gap' => 'none'], $cards, count($cards) === 2 ? 'v2-grid v2-grid--2' : 'v2-grid');
    }
    return $blocks;
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
    $photo = V2_PAGE_IMAGES[$pg['url']]['hero'] ?? V2_HERO_FALLBACK;
    v2_press_media($photo);
    return SEC('Hero', [B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [BOX($copy), V2IMG($photo, $pg['h1'], 'v2-phero__photo')], 'v2-phero')], 'v2-phero-sec');
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
            // The links' ?reason= picks the motif (an unknown one keeps the general request); ?activity=, ?project= and
            // ?subject= fill the object, still editable.
            $f('select', 'motif', 'Motif de votre demande', true, ['options' => implode(',', $reasons), 'default_value' => $reasons[0],
                'url_param' => 'reason', 'url_values' => implode("\n", array_map(static fn(string $k, int $i): string => "{$k}={$reasons[$i]}", array_keys(V2_REASONS), V2_REASONS))]),
            $f('text', 'nom', 'Nom et prénom', true), $f('email', 'email', 'Adresse e-mail', true), $f('tel', 'telephone', 'Téléphone (facultatif)', false),
            $f('text', 'organisation', 'Organisation / établissement', false), $f('text', 'objet', 'Objet de votre demande', true, ['url_param' => 'activity, project, subject',
                'url_values' => implode("\n", array_map(static fn(string $k, string $v): string => "{$k}={$v}", array_keys(V2_CONTEXT), V2_CONTEXT))]), $f('textarea', 'message', 'Votre message', true),
        ], 'v2-form', ['id' => STABLE_ID('v2-contact-form')]);
        $side = array_merge(array_map(static fn(string $t): array => B('core.rich_text', ['content' => '<p>' . htmlspecialchars($t) . ' <a href="/politique-de-confidentialite/">Consulter la politique de confidentialité</a></p>'], [], 'v2-text'), $privacy),
            $after ? [v2_rich($after)] : []);
        return B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [BOX([$form], 'v2-panel'), BOX($side, 'v2-aside')], 'v2-split2 v2-split2--top');
    }
    if (str_contains($all, 'Donation module')) {
        // One-off and monthly, each with set amounts and a free amount (from 5 € up to 5 000 €); Stripe confirms the payment,
        // then the donor lands on the thank-you page (V2_THANKS).
        $pay = static fn(string $label, int $amount, string $frequency, array $extra = []): array => B('payment.button', array_replace([
            'label' => $label, 'amount' => $amount, 'currency' => 'EUR', 'frequency' => $frequency, 'success_path' => trim(V2URL(V2_THANKS), '/'),
            'description' => $frequency === 'month' ? 'Don mensuel à FightxLife ASBL' : 'Don à FightxLife ASBL'], $extra), [], 'v2-pay');
        $free = static function (string $frequency, string $label) use ($pay): array {
            $b = $pay('Je soutiens FightxLife', 5, $frequency, ['amount_choice' => 'visitor', 'max_amount' => 5000, 'amount_label' => $label]);
            $b['classNames'][] = 'v2-pay--free';
            return $b;
        };
        $panel = static fn(string $kicker, string $title, string $text, array $amounts, string $frequency, string $suffix, string $freeLabel): array => BOX([
            KICK($kicker), H($title, 'h3', 'v2-give__title'), T($text, 'v2-give__text'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'justify' => 'center', 'wrap' => 'wrap'],
                array_map(static fn(int $a): array => $pay("{$a} €{$suffix}", $a, $frequency), $amounts), 'v2-give'),
            BOX([$free($frequency, $freeLabel)], 'v2-give__free'),
        ], 'v2-panel v2-narrow--center');
        return BOX([
            B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'stretch'], [
                $panel('Une fois', 'Don ponctuel', 'Un seul paiement, au moment qui vous convient.', [10, 25, 50, 100], 'once', '', 'Autre montant (€)'),
                $panel('Chaque mois', 'Don mensuel', 'Prélevé chaque mois à la même date, jusqu’à ce que vous l’arrêtiez.', [10, 25, 50], 'month', ' / mois', 'Autre montant par mois (€)'),
            ], 'v2-give2'),
            T('Paiement sécurisé par Stripe (carte, Bancontact pour un don ponctuel). Pour modifier ou arrêter un don mensuel, contactez-nous.', 'v2-give__note'),
        ]);
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
    // The document says to hide the team section when no approved entries are supplied; V2_TEAM is empty in that case.
    if (str_contains($all, 'Team block')) {
        if (!V2_TEAM) { return null; }
        $groups = [];
        foreach (V2_TEAM as $group => $people) {
            $cards = array_map(static fn(array $m): array => BOX([V2IMG($m[2], $m[0], 'v2-team__photo'), T($m[0], 'v2-team__name'), T($m[1], 'v2-team__role')], 'v2-team__card'), $people);
            $groups[] = BOX([T($group, 'v2-team__group'), B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'justify' => 'center', 'wrap' => 'wrap'], $cards, 'v2-team')], 'v2-team__block');
        }
        return SEC($sec['h2'], [BOX(array_merge([KICK(), H($sec['h2'], 'h2', 'v2-h2')], array_filter([v2_rich($text)])), 'v2-head v2-head--center'), ...$groups],
            'v2-sec' . ($i % 2 === 1 ? ' v2-sec--soft' : ''));
    }
    $soft = $i % 2 === 1;
    $head = [KICK(), H($sec['h2'], 'h2', 'v2-h2')];
    $module = v2_module($notes, $sec, $pg);
    $side = V2_SIDE_IMAGES[$pg['url']][$sec['id']] ?? null;
    $photo = $side ?? V2_PAGE_IMAGES[$pg['url']]['sec'][$sec['id']] ?? null;

    // The contact form keeps its own text beside the form.
    if ($module && str_contains($all, 'Contact form module')) {
        return SEC($sec['h2'], [BOX($head, 'v2-head v2-head--center'), $module], 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
    }
    if ($sec['id'] === 'dans-les-medias') {
        return SEC($sec['h2'], v2_press($sec, $head), 'v2-sec' . ($soft ? ' v2-sec--soft' : ''));
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
        v2_press_media($photo);
        $blocks[] = $side
            ? B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [V2IMG($photo, $sec['h2'], 'v2-split2__photo'), BOX($copy, 'v2-split2__copy')], 'v2-split2 v2-split2--bal')
            : B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], $i % 2 === 0
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
/** @return array<string, int> each page's id by its document address */
function v2_build_pages(array $pages): array
{
    global $CONTENT_V1, $EXTRA_V1, $IMGMAP_V1;
    $data = __DIR__ . '/../fxl';
    $CONTENT_V1 = [];
    foreach (json_decode((string) file_get_contents("{$data}/content.json"), true) as $item) { $CONTENT_V1[$item['kind'] . ':' . $item['slug']] = $item; }
    $EXTRA_V1 = json_decode((string) file_get_contents("{$data}/extra.json"), true);
    $IMGMAP_V1 = json_decode((string) file_get_contents("{$data}/image-map.json"), true);
    $ids = [];
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
        $ids[$pg['url']] = build_page($slug, V2_LIVE ? $pg['h1'] : $pg['h1'] . ' (V2)', 'page', 'standalone', $sections, V2_LIVE ? [] : ['seo_robots' => 'noindex,nofollow'], $pg['seo_title'], $pg['meta'],
            $photo ? (MID('/uploads/media/fxl/' . $photo) ?: null) : null);
    }
    return $ids;
}

/**
 * Move the pages between the preview and the live addresses, before the build. Going live, each current page whose
 * address a V2 page takes moves to "<slug>-v1" first; going back, the V2 page returns to "<slug>-v2" and the current page
 * to its address. Renames go through Studio (updatePageAddress), so revisions, ids and the homepage role follow.
 * Returns the "-v1" slugs: the V2 header and footer leave those out, so they keep the current ones (the site's default
 * header and footer, which Studio uses wherever no other one applies).
 *
 * @return list<string>
 */
function v2_switch(array $pages, array $map): array
{
    global $runtime, $actor, $tid;
    $known = json_decode((string) Database::setting('fxl_v2_pages', $tid), true) ?: [];
    $slugOf = static fn(int $id): ?string => ($v = Database::value("SELECT slug FROM studiobuilder_pages WHERE tenant_id = ? AND id = ? AND status <> 'archived'", [$tid, $id])) !== null ? (string) $v : null;
    $pageAt = static fn(string $slug): ?int => ($v = Database::value("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = 'page' AND status <> 'archived'", [$tid, $slug])) !== null ? (int) $v : null;
    $move = static function (int $id, string $slug, string $route, ?string $title = null) use ($runtime, $actor): void {
        $runtime->app->updatePageAddress($actor, $id, array_filter(['slug' => $slug, 'route_mode' => $route, 'title' => $title], static fn($v) => $v !== null));
        say("  ~ #{$id} -> /{$slug}/" . ($route === 'homepage' ? ' (home page)' : ''));
    };
    $v1 = [];
    foreach ($pages as $pg) {
        $url = $pg['url'];
        $real = $url === '/' ? 'accueil' : trim($url, '/');
        $preview = $url === '/' ? 'accueil-v2' : $real . '-v2';
        $route = $url === '/' ? 'homepage' : 'standalone';
        // V2's page: by its recorded id, else (a first run) at its preview address. Never "whatever is at the real address".
        $id = isset($known[$url]) && $slugOf((int) $known[$url]) !== null ? (int) $known[$url] : $pageAt($preview);
        if (V2_LIVE) {
            $current = $pageAt($real);
            if ($current !== null && $current !== $id) {
                $title = (string) Database::value('SELECT title FROM studiobuilder_pages WHERE id = ?', [$current]);
                $move($current, "{$real}-v1", 'standalone', str_ends_with($title, ' (V1)') ? null : "{$title} (V1)");
            }
            if ($id !== null && $slugOf($id) !== $real) { $move($id, $real, $route); }
            if ($pageAt("{$real}-v1") !== null) { $v1[] = "{$real}-v1"; }
        } else {
            if ($id !== null && $slugOf($id) === $real) { $move($id, $preview, 'standalone'); }
            if (($old = $pageAt("{$real}-v1")) !== null) {
                $title = (string) Database::value('SELECT title FROM studiobuilder_pages WHERE id = ?', [$old]);
                $move($old, $real, $route, str_ends_with($title, ' (V1)') ? substr($title, 0, -5) : null);
            }
        }
    }
    // The site's default header and footer are a fallback Studio never scores, so they carry no display conditions.
    foreach (Database::all("SELECT id, active_draft_revision_id, published_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'default' AND page_type IN ('header_partial', 'footer_partial') AND status = 'published'", [$tid]) as $row) {
        $doc = json_decode((string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE id = ?', [(int) $row['published_revision_id']]), true);
        if (!is_array($doc) || !isset($doc['settings']['conditions'])) { continue; }
        unset($doc['settings']['conditions']);
        $draft = $runtime->app->saveDraft($actor, (int) $row['id'], $doc, (int) $row['active_draft_revision_id'], 'manual', 'FightxLife V2 switch');
        $runtime->app->publish($actor, (int) $row['id'], (int) $draft['revision']['id'], 'FightxLife V2 switch');
        say("  ~ default partial #{$row['id']}: conditions removed");
    }
    return $v1;
}

/**
 * The 301 plan: installed with --live, taken out again in the preview (where the retired pages are still the site's).
 * The site's other rules (the retired shop addresses) stay. /produit/donation/ goes straight to the donation section live,
 * so there is no chain through /donation/.
 */
function v2_redirects(array $map): void
{
    global $tid;
    $key = \Slate\Module\StudioBuilder\Runtime\StudioRedirects::SETTING;
    $rules = json_decode((string) Database::setting($key, $tid), true) ?: [];
    $rules = array_values(array_filter($rules, static fn(array $r): bool => !isset(V2_RETIRED[$r['from']]) && $r['from'] !== '/produit/donation/'));
    $rules[] = ['from' => '/produit/donation/', 'to' => V2_LIVE ? V2_RETIRED['/donation/'] : '/donation/', 'code' => 301];
    $rules[] = ['from' => '/cours/mon-compte/', 'to' => V2_MEMBRE, 'code' => 301];
    // The preview addresses the client was sent ("<slug>-v2") lead to the live page once V2 is live.
    $rules = array_values(array_filter($rules, static fn(array $r): bool => !preg_match('~-v2/$~', $r['from'])));
    if (V2_LIVE) {
        foreach (V2_RETIRED as $from => $to) {
            if ($from !== '/cours/mon-compte/') { $rules[] = ['from' => $from, 'to' => $to, 'code' => 301]; }
        }
        foreach ($map as $url => $live) {
            $rules[] = ['from' => $url === '/' ? '/accueil-v2/' : rtrim($url, '/') . '-v2/', 'to' => $live, 'code' => 301];
        }
    }
    Database::setSetting($key, json_encode($rules, JSON_UNESCAPED_SLASHES), $tid);
    say('  + redirects: ' . count($rules) . (V2_LIVE ? ' (with the 301 plan)' : ''));
}
