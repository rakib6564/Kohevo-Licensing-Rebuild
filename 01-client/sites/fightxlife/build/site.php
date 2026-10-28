<?php
/**
 * FightxLife — fightxlife.com rebuilt in Kohevo Studio on the Fight Life design.
 *
 * Every live URL keeps its address (trailing slash on). Text, dates and SEO titles/descriptions come from the live
 * site (WordPress REST API + crawl, see ../fxl/content.json); images are the live site's own uploads.
 * Content types: cours, projet, evenement (/events/…), produit (/produit/…), article (the blog posts, in "Actualités").
 *
 *   cd <site> && php .../fxl-build/site.php
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

$DATA = __DIR__ . '/../fxl';
$CONTENT = [];
foreach (json_decode((string) file_get_contents("{$DATA}/content.json"), true) as $item) {
    $CONTENT[$item['kind'] . ':' . $item['slug']] = $item;
}
$IMGMAP = json_decode((string) file_get_contents("{$DATA}/image-map.json"), true);
$EXTRA = json_decode((string) file_get_contents("{$DATA}/extra.json"), true);
$BG = json_decode((string) file_get_contents("{$DATA}/page-bg.json"), true);

const MEMBRE = 'https://membre.fightxlife.com/';
const CAT = 'Actualités';

// ── Theme ────────────────────────────────────────────────────────────────────────────────────────────────────────
$tokens = [
    'surface.page' => '#ffffff', 'surface.primary' => '#ffffff', 'surface.secondary' => '#f6f6f6', 'surface.muted' => '#f8f8f8',
    'surface.accent' => '#e9008a', 'surface.inverse' => '#0c0905',
    'text.primary' => '#0c0905', 'text.muted' => '#8a8a8a', 'text.accent' => '#e9008a', 'text.inverse' => '#ffffff',
    'color.accent' => '#e9008a', 'border.default' => '#eeeeee',
    'font.heading' => 'Syne, Arial, sans-serif', 'font.body' => 'Karla, Arial, sans-serif',
    // Site settings › Text: grey body text under ink headings, 15px/1.65 body, tight headings, no default block spacing.
    'text.body' => '#6b6468', 'text.heading' => '#0c0905', 'type.body_size' => '15px', 'type.body_leading' => '1.65',
    'type.heading_leading' => '1.17', 'type.flow' => '0', 'type.heading_gap' => '0', 'type.section_gutter' => '0', 'type.smoothing' => 'antialiased',
    'radius.sm' => '7px', 'radius.md' => '14px', 'radius.lg' => '15px', 'radius.full' => '999px',
    'shadow.sm' => '0 6px 18px rgba(30,20,48,.035)', 'shadow.md' => '0 8px 28px rgba(18,10,34,.08)',
    // Site settings › Layout: the design's content width. Every section's boxed container keeps to it.
    'layout.content' => '1170px',
];
$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;700&family=Syne:wght@400;700&display=swap" rel="stylesheet">';
$css = StudioCodePolicy::prepareCustomCss(trim((string) preg_replace('~/\*.*?\*/~s', '', file_get_contents(__DIR__ . '/gap.css'))));
if ($css['changed']) { say('  ! the CSS cleaner changed the stylesheet'); }
$runtime->app->saveDesignTokens($actor, 'default', $tokens);
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, $css['css'], $tid);
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($fonts, 'head'), $tid);
Database::setSetting('site_name', 'FightxLife', $tid);
Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, '1', $tid);
Database::setSetting('studio_seo_title_format', '%title%', $tid);
say("  + theme, fonts, stylesheet {$css['bytes']} bytes, trailing slash on");

// ── Content types and fields ─────────────────────────────────────────────────────────────────────────────────────
$typeDefs = [
    'cours'     => ['Cours', 'Cours', '/', 'A boxing class: where, when, for whom, prices.', [
        ['lieu', 'Lieu', 'text'], ['horaires', 'Horaires', 'text'], ['duree', 'Durée', 'text'], ['public', 'Pour qui', 'text'], ['tarifs', 'Tarifs', 'list']]],
    'projet'    => ['Projet', 'Projets', '/', 'An action of the association (SmileBox, hospitals, gala…).', [['accroche', 'Accroche', 'text']]],
    'evenement' => ['Événement', 'Événements', '/events', 'A FightxLife event.', [
        ['date', 'Date', 'text'], ['heure', 'Heure', 'text'], ['tarif', 'Tarif', 'text'], ['lieu', 'Lieu', 'text']]],
    'produit'   => ['Produit', 'Produits', '/produit', 'An item of the e-shop.', [
        ['prix', 'Prix', 'text'], ['couleurs', 'Couleurs', 'text'], ['tailles', 'Tailles', 'text']]],
    // The blog posts: their own list in the admin. Lists and the Article template still pick them by the Actualités category.
    'article'   => ['Article', 'Articles', '/', 'A news post of the Actualités blog.', []],
];
$types = PageMeta::postTypes($tid);
$fields = array_values(array_filter(PageMeta::allFields($tid), static fn(array $f): bool => !isset($typeDefs[$f['post_type'] ?? ''])));
foreach ($typeDefs as $key => [$singular, $plural, $route, $desc, $fs]) {
    $types[$key] = ['key' => $key, 'singular' => $singular, 'plural' => $plural, 'route' => $route, 'is_system' => false, 'description' => $desc];
    foreach ($fs as [$fk, $label, $ft]) {
        $fields[] = ['id' => 'f_' . substr(md5($key . $fk), 0, 8), 'post_type' => $key, 'label' => $label, 'key' => $fk, 'type' => $ft, 'required' => false, 'placeholder' => '', 'help' => '', 'options' => []];
    }
}
Database::setSetting('studio_custom_post_types', json_encode($types, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tid);
Database::setSetting('studio_meta_fields', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tid);
PageRoutes::forget($tid);
say('  + content types: ' . implode(', ', array_keys($typeDefs)));

// ── Helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────
function C(string $kind, string $slug): array
{
    global $CONTENT;
    return $CONTENT["{$kind}:{$slug}"] ?? throw new RuntimeException("no content {$kind}:{$slug}");
}
/** The local library path of a live image URL ('' when it was not downloaded). */
function LOCAL(string $url): string
{
    global $IMGMAP;
    return $IMGMAP[preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $url)] ?? $IMGMAP[$url] ?? '';
}
/** An image block from a library path. */
function IMGP(string $path, string $alt, string $cls = '', array $extra = []): array
{
    $id = MID($path);
    if ($id <= 0) { throw new RuntimeException("media {$path} missing"); }
    return B('core.image', array_replace(['media' => ['media_id' => $id, 'alt' => $alt, 'focal_point' => [0.5, 0.5]], 'aspect_ratio' => 'auto', 'rounded' => false], $extra), [], $cls);
}
function F(string $file): string { return '/uploads/media/fxl/' . $file; }
/** Site-relative link for a live URL. */
function REL(string $href): string
{
    return (string) preg_replace('~^https?://(www\.)?fightxlife\.com~', '', $href) ?: '/';
}
function CLEAN(string $h): string
{
    $h = preg_replace('~<i>\s*</i>~', '', $h);
    $h = preg_replace('~<a href="(javascript:[^"]*|#|)">(.*?)</a>~s', '$2', $h);
    $h = preg_replace_callback('~<a href="([^"]+)">~', static fn($m) => '<a href="' . htmlspecialchars(REL(html_entity_decode($m[1])), ENT_QUOTES) . '">', $h);
    $h = preg_replace('~<a href="[^"]*">\s*</a>~', '', $h);
    $h = preg_replace('~<(ul|ol)>\s*(<li>\s*</li>\s*)*</\1>~', '', $h);
    $h = preg_replace('~<p>\s*</p>~', '', $h);
    return trim((string) $h);
}
function RICH(string $html, string $cls = 'fx-rich'): array { return B('core.rich_text', ['content' => $html], [], $cls); }
function WRAP(array $children, string $cls = ''): array { return ['__wrap' => true, 'children' => $children, 'cls' => $cls]; }
function HEAD(string $eyebrow, string $heading, string $hl = '', string $text = '', string $align = 'center'): array
{
    $kids = [T($eyebrow, 'yg-eyebrow'), H($heading, 'h2', 'yg-h2', $hl)];
    if ($text !== '') { $kids[] = T($text, 'yg-lead'); }
    return BOX($kids, 'yg-head yg-head--' . $align);
}
function ICONTILE(string $icon, string $cls): array { return B('core.icon', ['name' => $icon, 'size' => 'md', 'size_px' => 23], [], $cls); }
function CHECKS(array $items, string $cls = ''): array
{
    return B('core.list', ['style' => 'check', 'items' => array_map(static fn(string $t): array => ['text' => $t], $items)], [], trim('yg-checks ' . $cls));
}
/** Page banner: the live page's background photo behind its title. */
function BANNER(string $title, string $hl, string $crumb, string $bgPath = ''): array
{
    $sec = SEC('Page banner', [WRAP([
        H($title, 'h1', 'yg-banner__title', $hl),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'wrap' => 'wrap'], [LNK('Accueil', '/', 'yg-crumb__home'), T('›', 'yg-crumb__sep'), T($crumb, 'yg-crumb__here')], 'yg-crumbs'),
    ])], 'yg-banner' . ($bgPath !== '' ? ' fx-banner--photo' : ''));
    if ($bgPath !== '' && ($id = MID($bgPath)) > 0) {
        $sec['blocks'][0]['style']['background'] = ($sec['blocks'][0]['style']['background'] ?? []) + ['image' => ['media_id' => $id, 'alt' => '', 'focal_point' => [0.5, 0.5]], 'size' => 'cover', 'position' => 'center'];
    }
    return $sec;
}
function PAGEBG(string $slug, int $n = 0): string
{
    global $BG;
    $u = $BG[$slug][$n] ?? '';
    return $u !== '' && !str_contains($u, 'bg-line') && !str_contains($u, 'bg-footer') && !str_contains($u, 'wave-hero') ? LOCAL($u) : '';
}
/** The live body of an item as Studio blocks, in order. */
function BODY(array $blocks, bool $skipFirstH1 = true): array
{
    $out = [];
    foreach ($blocks as $b) {
        switch ($b['t']) {
            case 'h':
                if ($skipFirstH1 && $b['level'] === 'h1') { $skipFirstH1 = false; break; }
                $level = ['h1' => 'h2', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h3', 'h5' => 'h4', 'h6' => 'h4'][$b['level']];
                $out[] = H($b['text'], $level, 'fx-body__h fx-body__' . $level);
                break;
            case 'rich':
                $h = CLEAN($b['html']);
                if (trim(strip_tags($h)) !== '') { $out[] = RICH($h); }
                break;
            case 'img':
                $p = LOCAL($b['src']);
                if ($p !== '' && MID($p) > 0) { $out[] = IMGP($p, $b['alt'] ?? '', 'fx-body__img'); }
                break;
            case 'gallery':
                $imgs = [];
                foreach ($b['imgs'] as $i) {
                    $p = LOCAL($i['src']);
                    if ($p !== '' && !in_array($p, array_column($imgs, 'url'), true)) { $imgs[] = ['url' => $p, 'alt' => (string) ($i['alt'] ?? ''), 'caption' => '']; }
                }
                if (count($imgs) === 1) { $out[] = IMGP($imgs[0]['url'], $imgs[0]['alt'], 'fx-body__img'); }
                elseif ($imgs) { $out[] = B('core.gallery', ['columns' => count($imgs) >= 4 ? '4' : '3', 'gap' => 'sm', 'aspect_ratio' => '4:3', 'rounded' => true, 'images' => array_slice($imgs, 0, 50)], [], 'yg-gallery fx-body__gallery'); }
                break;
            case 'btn':
                $out[] = BTN($b['text'], REL($b['href']), 'yg-btn fx-body__btn');
                break;
            case 'video':
                if (preg_match('~youtube\.com/(embed/|watch\?v=)([\w-]{6,})~', $b['src'], $m)) { $out[] = B('core.video', ['url' => 'https://www.youtube.com/watch?v=' . $m[2]], [], 'fx-body__video'); }
                break;
        }
    }
    return $out;
}
function DONATE(): array
{
    return SEC('Aide-nous à agir', [WRAP([
        T('Faire un don', 'yg-eyebrow'), H('Aide-nous à agir', 'h2', 'yg-h2', 'agir'), T('Tes dons se traduisent en actions concrètes !', 'yg-lead'), BTN('Faire un don', '/donation/'),
    ], 'yg-consult')], 'yg-consult-sec');
}
function CARD(string $img, string $title, string $text, string $href, string $more = 'En savoir plus', string $badge = ''): array
{
    $photo = [IMGP($img, $title, 'yg-class__img', ['link_to' => 'custom', 'url' => $href])];
    if ($badge !== '') { $photo[] = T($badge, 'yg-class__num fx-badge'); }
    return BOX([
        BOX($photo, 'yg-class__photo'),
        BOX([ICONTILE('heart', 'yg-class__icon'), B('core.heading', ['text' => $title, 'level' => 'h3', 'url' => $href], [], 'yg-class__title'), T($text, 'yg-class__text'), LNK($more . ' →', $href, 'yg-more')], 'yg-class__body'),
    ], 'yg-class');
}
function GRID(array $cards, string $cls = 'yg-class-grid', int $cols = 3): array { return B('layout.grid', ['columns' => $cols, 'gap' => 'none'], $cards, $cls); }
function PERSON(string $img, string $name, string $role, string $quote = ''): array
{
    $photo = IMGP($img, $name, 'yg-trainer__img');
    if (preg_match('~/user-|-user\.~', $img)) { $photo['style'] = S($photo['style'], dim(['object_fit' => 'contain']), pad('22%'), bg('#fdeef6')); } // no photo: the placeholder sits small on a soft tile
    $kids = [$photo, H($name, 'h3', 'yg-trainer__name'), T($role, 'yg-trainer__role')];
    if ($quote !== '') { $kids[] = T($quote, 'fx-person__quote'); }
    return BOX($kids, 'yg-trainer');
}
function LOGOS(array $files, string $cls = 'fx-logos'): array
{
    return B('core.gallery', ['columns' => '6', 'gap' => 'md', 'aspect_ratio' => 'auto', 'rounded' => false,
        'images' => array_map(static fn(string $f): array => ['url' => F($f), 'alt' => 'Partenaire de FightxLife', 'caption' => ''], $files)], [], $cls);
}
function QUOTE(string $text, string $img, string $name, string $role): array
{
    return BOX([
        T('“', 'yg-quote__mark'), T($text, 'yg-quote__text'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [str_contains($img, '-user.') ? array_replace_recursive(IMGP(F($img), $name, 'yg-quote__img'), ['style' => S(dim(['object_fit' => 'contain']), pad(10), bg('#fdeef6'))]) : IMGP(F($img), $name, 'yg-quote__img'), BOX([T($name, 'yg-quote__name'), T($role, 'yg-quote__role')])], 'yg-quote__person'),
    ], 'yg-quote');
}
function PRODUCTCARD(string $slug): array
{
    global $EXTRA;
    $it = C('product', $slug);
    $img = LOCAL($it['featured']) ?: LOCAL($EXTRA['products'][$slug]['imgs'][0] ?? '');
    return CARD($img, $it['title'], $EXTRA['products'][$slug]['price'], "/produit/{$slug}/", 'Acheter');
}
function EVENTCARD(string $slug): array
{
    global $EXTRA;
    $it = C('mec-events', $slug);
    $ev = $EXTRA['events'][$slug];
    return BOX([
        IMGP(LOCAL($it['featured']), $it['title'], 'yg-post__img', ['link_to' => 'custom', 'url' => "/events/{$slug}/"]),
        BOX([
            B('core.list', ['style' => 'none', 'items' => array_values(array_filter([['text' => $ev['date']], $ev['heure'] !== '' ? ['text' => $ev['heure']] : null]))], [], 'yg-meta'),
            B('core.heading', ['text' => $it['title'], 'level' => 'h3', 'url' => "/events/{$slug}/"], [], 'yg-post__title'),
            T($it['seo_desc'] !== '' ? $it['seo_desc'] : mb_substr($it['excerpt'], 0, 160), 'yg-post__text'),
            LNK('Voir l’événement →', "/events/{$slug}/", 'yg-more'),
        ], 'yg-post__body'),
    ], 'yg-post');
}
function NEWSLETTER(): array
{
    return SEC('Newsletter', [WRAP([B('layout.grid', ['columns' => 3, 'gap' => 'none', 'align' => 'center'], [
        B('core.icon', ['name' => 'mail', 'size_px' => 28], [], 'yg-news__icon'),
        BOX([H('Abonne-toi à notre newsletter', 'h2', 'yg-news__title'), T('Reste informé de nos actions, événements, galas et plus encore… C’est promis, nous ne te spammerons pas !', 'yg-news__text')], 'yg-news__copy'),
        B('core.form', ['form_name' => 'newsletter', 'submit_text' => 'S’abonner', 'submit_variant' => 'primary', 'success_message' => 'Merci ! Ton inscription à la newsletter est bien enregistrée.'], [
            B('core.form_field', ['field_type' => 'email', 'name' => 'email', 'label' => 'Email', 'placeholder' => 'Email', 'required' => true]),
        ], 'yg-news__form', ['id' => STABLE_ID('newsletter-form')]),
    ], 'yg-news')])], 'yg-news-sec');
}
$SPONSORS = ['2022-05-nos_sponsors_8-removebg-preview.png', '2022-05-nos_sponsors_5-removebg-preview.png', '2022-05-nos_sponsors_7-removebg-preview.png', '2022-05-nos_sponsors_6-removebg-preview.png',
    '2022-05-nos_sponsors_10-removebg-preview.png', '2022-05-adeps-removebg-preview.png', '2022-05-nos_sponsors_-removebg-preview.png', '2022-05-nos_sponsors_4-removebg-preview.png', '2022-05-nos_sponsors_9-removebg-preview.png'];
$PARTNERS = ['2022-05-nos_sponsors_7-removebg-preview.png', '2022-05-nos_partenaires_3-removebg-preview.png', '2022-05-nos_partenaires_1-removebg-preview.png',
    '2022-07-33228461-dae6-44ec-bbe7-2d152c02dfb9-modified-copie.png', '2022-07-cdd-2018-w-copie.png'];
$HOMELOGOS = ['2022-05-nos_sponsors_7-removebg-preview.png', '2022-08-logohude-full-xl.png', '2025-05-design-sans-titre-6-copie.jpg', '2022-08-logo_fondation_rvb.png', '2025-05-design-sans-titre-2.jpg',
    '2022-08-logos-hub-hudef-rectangle.png', '2022-09-unknown-2.jpeg', '2022-05-nos_sponsors_8-removebg-preview.png', '2022-05-nos_sponsors_5-removebg-preview.png', '2025-02-logo_cera_baseline_fr.png',
    '2022-07-audacity-coach-logo2.png', '2024-10-unknown.png', '2022-05-nos_sponsors_9-removebg-preview.png', '2022-05-nos_sponsors_10-removebg-preview.png', '2022-05-adeps-removebg-preview.png',
    '2024-10-photo-2024-09-24-11-13-33.jpg', '2022-07-cdd-2018-w-copie.png', '2022-07-logo-photo-black.png'];
const LOGO = '2022-05-cropped-logo-fxl-01-e1638355780533-1.png';

// ── Header and footer ────────────────────────────────────────────────────────────────────────────────────────────
$nav = [['label' => 'Accueil', 'url' => '/'], ['label' => 'Qui Sommes-Nous', 'url' => '/qui-sommes-nous/'], ['label' => 'Cours', 'url' => '/cours/'],
    ['label' => 'Projets', 'url' => '/nos-actions/'], ['label' => 'Evénements', 'url' => '/evenements/'], ['label' => 'Nous Soutenir', 'url' => '/nous-soutenir/']];
$drawerNav = [['label' => 'Accueil', 'url' => '/'], ['label' => 'Qui Sommes-Nous', 'url' => '/qui-sommes-nous/'], ['label' => 'Cours', 'url' => '/cours/'],
    ['label' => 'Boxe Adaptée aux Malades', 'url' => '/boxe-adaptee/'], ['label' => 'WaterxFight', 'url' => '/waterxfight/'], ['label' => 'Boxe 100% Féminin', 'url' => '/100-feminin/'],
    ['label' => 'Nos Actions', 'url' => '/nos-actions/'], ['label' => 'SmileBox', 'url' => '/smile-box/'], ['label' => 'Solidarité contre le Cancer', 'url' => '/solidarite-5/'],
    ['label' => 'Les Hôpitaux', 'url' => '/les-hopitaux/'], ['label' => 'Gala', 'url' => '/gala/'], ['label' => 'Crowdfunding', 'url' => '/crowdfunding/'],
    ['label' => 'Evénements', 'url' => '/evenements/'], ['label' => 'Nous Soutenir', 'url' => '/nous-soutenir/'], ['label' => 'E-shop', 'url' => '/e-shop/'],
    ['label' => 'Blog', 'url' => '/blog/'], ['label' => 'Presse', 'url' => '/presse/'], ['label' => 'Contact', 'url' => '/contact/']];
// Site menus (Studio › Menus): the header, drawer and footer navs name one, so links are edited in one place.
$menu = static function (string $key, string $name, array $items): string {
    global $tid;
    \Slate\Module\StudioBuilder\Menu\StudioMenus::save($tid, $key, $name, $items, $key);
    return $key;
};
$footPages = [['label' => 'Nous soutenir', 'url' => '/nous-soutenir/'], ['label' => 'Les Hôpitaux', 'url' => '/les-hopitaux/'], ['label' => 'E-shop', 'url' => '/e-shop/'], ['label' => 'Blog', 'url' => '/blog/']];
$footInfo = [['label' => 'Presse', 'url' => '/presse/'], ['label' => 'Nous Contacter', 'url' => '/contact/'], ['label' => 'Politique de Confidentialité', 'url' => '/politique-de-confidentialite/'], ['label' => 'Conditions Générales de Vente', 'url' => '/conditions-generales-de-vente/']];
$actions = [['label' => 'SmileBox', 'url' => '/smile-box/'], ['label' => 'Solidarité', 'url' => '/solidarite-5/'], ['label' => 'Les hôpitaux', 'url' => '/les-hopitaux/'], ['label' => 'Gala', 'url' => '/gala/'], ['label' => 'Crowdfunding', 'url' => '/crowdfunding/']];
$menuHeader = $menu('header', 'Menu principal', $nav);
$menuDrawer = $menu('drawer', 'Menu mobile', array_slice($drawerNav, 0, 12));
$menuDrawerMore = $menu('drawer-more', 'Menu mobile (suite)', array_slice($drawerNav, 12));
$menuFootPages = $menu('footer-pages', 'Pied de page · Autres pages', $footPages);
$menuFootInfo = $menu('footer-info', 'Pied de page · En savoir plus', $footInfo);
$menuActions = $menu('actions', 'Nos actions', $actions);
$social = static fn(string $icon, string $url, string $label): array => B('core.icon', ['name' => $icon, 'url' => $url, 'new_tab' => true, 'label' => $label, 'size_px' => 16], [], 'fx-social-ico');
$header = [
    SEC('Top bar', [B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'align' => 'center', 'gap' => 'none', 'wrap' => 'wrap'], [
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'wrap' => 'wrap'], [
            LNK('+32 483 12 72 98', 'tel:+32483127298', 'fx-top__item'), LNK('contact@fightxlife.com', 'mailto:contact@fightxlife.com', 'fx-top__item'),
            LNK('Rue Gabrielle Petit 32, 1080 Molenbeek', 'https://goo.gl/maps/aJW74coL3FB9Ld96A', 'fx-top__item fx-top__addr')], 'fx-top__contact'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [
            $social('linkedin', 'https://www.linkedin.com/company/fightxlife/', 'FightxLife sur LinkedIn'), $social('facebook', 'https://www.facebook.com/Fightxlife', 'FightxLife sur Facebook'),
            $social('instagram', 'https://www.instagram.com/fightxlife', 'FightxLife sur Instagram'), LNK('Accès Membre', MEMBRE, 'fx-top__member')], 'fx-top__right'),
    ], 'fx-top')], 'fx-top-sec'),
    SEC('Header', [WRAP([B('layout.grid', ['columns' => 3, 'gap' => 'none', 'align' => 'center'], [
        IMGP(F(LOGO), 'FightxLife', 'yg-brand fx-brand', ['link_to' => 'custom', 'url' => '/']),
        B('core.nav_menu', ['menu' => $menuHeader, 'items' => [], 'layout' => 'horizontal', 'align' => 'center', 'aria_label' => 'Navigation principale'], [], 'yg-nav fx-nav'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'justify' => 'end'], [
            BTN('Connexion', MEMBRE, 'yg-btn fx-login'),
            B('layout.offcanvas', ['drawer_id' => 'site-drawer', 'title' => '', 'position' => 'right', 'trigger_text' => 'Ouvrir le menu', 'trigger_icon' => 'menu-short',
                'trigger_icon_size' => 26, 'trigger_show_text' => false, 'trigger_variant' => 'plain'], [
                IMGP(F(LOGO), 'FightxLife', 'yg-drawer__brand', ['link_to' => 'custom', 'url' => '/']),
                T('La force de vivre', 'yg-drawer__tag'),
                B('core.nav_menu', ['menu' => $menuDrawer, 'items' => [], 'layout' => 'vertical', 'aria_label' => 'Pages'], [], 'yg-drawer__nav'),
                B('core.nav_menu', ['menu' => $menuDrawerMore, 'items' => [], 'layout' => 'vertical', 'aria_label' => 'Plus de pages'], [], 'yg-drawer__nav fx-drawer__more'),
                BOX([T('Contact', 'yg-drawer__label'), LNK('contact@fightxlife.com', 'mailto:contact@fightxlife.com'), LNK('+32 483 12 72 98', 'tel:+32483127298'), LNK('Accès Membre', MEMBRE)], 'yg-drawer__contact'),
            ], 'yg-menu'),
        ], 'fx-head__end'),
    ], 'yg-header')])], 'yg-header-sec'),
];
$footer = [
    NEWSLETTER(),
    SEC('Footer', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        B('layout.grid', ['columns' => 2, 'gap' => 'none'], [
            BOX([H('Autres Pages', 'h3', 'yg-foot__title'), B('core.nav_menu', ['menu' => $menuFootPages, 'items' => [], 'layout' => 'vertical', 'aria_label' => 'Autres pages'], [], 'yg-foot__nav')]),
            BOX([H('En Savoir Plus', 'h3', 'yg-foot__title'), B('core.nav_menu', ['menu' => $menuFootInfo, 'items' => [], 'layout' => 'vertical', 'aria_label' => 'En savoir plus'], [], 'yg-foot__nav')]),
        ], 'yg-foot__links'),
        BOX([
            IMGP(F(LOGO), 'FightxLife', 'yg-foot__logo', ['link_to' => 'custom', 'url' => '/']),
            T('Association qui lutte contre le cancer grâce à des cours de boxe adaptés, des visites dans les hôpitaux et des SmileBox.', 'yg-foot__about'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [ICONTILE('map-pin', 'yg-foot__ico'), T('Rue Gabrielle Petit 32, 1080 Molenbeek', 'yg-foot__line')], 'yg-foot__row'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [ICONTILE('mail', 'yg-foot__ico'), LNK('contact@fightxlife.com', 'mailto:contact@fightxlife.com', 'yg-foot__line')], 'yg-foot__row'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [ICONTILE('phone', 'yg-foot__ico'), LNK('+32 483 12 72 98', 'tel:+32483127298', 'yg-foot__line')], 'yg-foot__row'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [
                $social('facebook', 'https://www.facebook.com/Fightxlife', 'FightxLife sur Facebook'), $social('instagram', 'https://www.instagram.com/fightxlife', 'FightxLife sur Instagram'),
                $social('linkedin', 'https://www.linkedin.com/company/fightxlife/', 'FightxLife sur LinkedIn')], 'fx-foot__social'),
        ], 'yg-foot__card fx-foot__card'),
    ], 'yg-foot')])], 'yg-foot-main'),
    SEC('Footer bar', [B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'gap' => 'none'], [T('© 2022 FightxLife • Tous droits réservés'), T('ASBL FightxLife · Numéro d’entreprise 720.965564')], 'yg-foot__bar')], 'yg-foot-bottom'),
];

// ── Pages ────────────────────────────────────────────────────────────────────────────────────────────────────────
$pages = []; // slug => [title, route, sections, settings, metaType, metaValues, date]
$p = static fn(string $slug): array => C('pages', $slug);

$coursCards = [
    CARD(LOCAL($BG['cours'][3]), '100% féminin', 'Des cours de kickboxing exclusivement pour des femmes, par des femmes, encadrés par des coachs professionnelles.', '/100-feminin/'),
    CARD(LOCAL($BG['cours'][2]), 'Boxe adaptée', 'Une séance de boxe de 90 minutes, encadrée par des coachs professionnels et adaptée aux personnes atteintes d’un cancer ou d’autres maladies.', '/boxe-adaptee/'),
    CARD(LOCAL($BG['cours'][4]), 'WaterxFight', 'De la boxe en piscine pendant 45 minutes, avec des gants spécifiques et en musique : l’eau amortit les chocs.', '/waterxfight/'),
];
$testimonials = [
    ['C’est par une amie que j’ai connu FightxLife, rejoindre la team m’a permis de me sentir mieux dans mon corps et de rencontrer des personnes de grande qualité qui continuent le combat.', '2022-05-tino-temoignage.jpg', 'Tino', 'Membre'],
    ['Les cours de boxe avec FightXLife sont super pour une remise en forme physique. J’y ai rencontré des personnes charmantes et le groupe m’apporte autant un soutien psychologique que physique.', '2022-05-geraldine-temoignage.jpg', 'Geraldine', 'Membre'],
    ['Une superbe aventure humaine ! Une énorme révélation qui m’apprend à parler de mes émotions et à évacuer mes tensions.', '2022-05-eva-temoignage.jpg', 'Eva', 'Membre'],
    ['La boxe, une passion mais avant tout une vraie bouffée d’oxygène et un moyen de me sentir vivante malgré les moments difficiles. Je résiste, je persévère, j’essaie, et surtout je combats la maladie.', '2022-05-malika-temoignage.jpg', 'Malika', 'Membre'],
    ['La boxe est devenue une thérapie en soi, un besoin, et FightxLife me permet de lui donner une place au quotidien tout en respectant mon corps.', '2022-05-ynes-temoignage.jpg', 'Ynes', 'Membre'],
    ['FightxLife aborde la maladie mais parle aussi de résistance, de courage, d’inclusion, d’humanité et de résilience. Ceux et celles qui ont choisi la force plutôt que l’acceptation.', '2022-05-nadine-temoignage.jpg', 'Nadine Minampala', 'Consultante en entrepreneuriat'],
    ['Testé par curiosité, j’ai vite adopté le cours de WaterxFight. Sara et Dounia donnent le tempo avec sourire, énergie et professionnalisme. Ça vide la tête et remplit l’esprit de bonnes vibes !', '2022-06-user.png', 'Jayne', 'WaterxFight'],
    ['J’ai toujours été une fervente ANTI-SPORT ! Et là, après le premier cours de WaterxFight, j’étais accro ! J’ai retrouvé ma joie de vivre et une énergie physique et mentale qui me faisaient défaut.', '2022-06-user.png', 'Maria', 'WaterxFight'],
];

$pages['accueil'] = ['Accueil', 'homepage', [
    SEC('Hero', [
        BOX([
            H('FightxLife, la force de vivre', 'h1', 'yg-hero__title', 'la force de vivre'),
            T('Association qui lutte contre le cancer grâce à des cours de boxe adaptés, des visites dans les hôpitaux, des SmileBox et bien plus.', 'yg-hero__text'),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'justify' => 'center'], [BTN('Nos cours', '/cours/'), BTN('Nous soutenir', '/nous-soutenir/', 'yg-btn yg-btn--dark')], 'yg-hero__actions'),
        ], 'yg-hero__copy'),
        BOX([
            // The interview plays on the page: the hero photo is the video's overlay, and clicking it starts the player in place.
            B('core.video', [
                'source' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=PHx3K_nNnms', 'aspect_ratio' => '16:9',
                'privacy' => true, 'lazy_load' => true, 'controls' => true, 'overlay' => true, 'play_icon' => true, 'lightbox' => false,
                'overlay_image' => ['media_id' => MID(LOCAL($BG['cours'][0])), 'alt' => 'Une femme en gants de boxe, sensibilisation au cancer', 'focal_point' => [0.5, 0.5]],
            ], [], 'yg-hero__video'),
        ], 'yg-hero__visual'),
    ], 'yg-hero-sec'),
    SEC('Nos valeurs', [WRAP([HEAD('FightxLife', 'Nos valeurs', 'valeurs'), GRID([
        BOX([ICONTILE('users', 'yg-feature__icon'), H('L’égalité des chances', 'h2', 'yg-feature__title'), T('Nous faisons en sorte que nos membres disposent des mêmes chances, des mêmes opportunités de développement social, indépendamment de leur origine sociale ou ethnique.', 'yg-feature__text')], 'yg-feature'),
        BOX([ICONTILE('heart', 'yg-feature__icon'), H('Le bien-être', 'h2', 'yg-feature__title'), T('La santé mentale et physique n’a pas de prix. Un bon moral et une bonne humeur peuvent être des atouts primordiaux nous permettant d’avancer.', 'yg-feature__text')], 'yg-feature'),
        BOX([ICONTILE('shield', 'yg-feature__icon'), H('La solidarité', 'h2', 'yg-feature__title'), T('Elle est cette main tendue vers l’autre pour lui rappeler qu’il n’est pas seul dans ce qu’il traverse, avec respect, en tenant compte du rythme de chacun.', 'yg-feature__text')], 'yg-feature'),
    ], 'yg-features')])], 'yg-features-sec fx-values'),
    SEC('Mission', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([IMGP(F('sara-ponpqw0gqz9udxela26ghmgghhjey2rgcpahom9pdk.jpg'), 'Sara Rezki, fondatrice de FightxLife', 'yg-about__img fx-portrait')], 'yg-about__media'),
        BOX([
            T('Mission', 'yg-eyebrow'), H('FightxLife lutte contre le cancer', 'h2', 'yg-h2', 'contre le cancer'),
            T('Le projet de FightxLife est né d’une expérience de vie, celle de la fondatrice Sara Rezki, touchée par le cancer à plusieurs moments de sa vie. Elle décide de mettre les gants pour faire face à ce crabe, se sentir vivante, extérioriser la colère et se sortir de l’isolement. À sa 4ème récidive, elle fonde l’ASBL FightxLife pour offrir aux personnes atteintes du cancer et d’autres maladies des cours de boxe adaptée, pour :', 'yg-about__text'),
            CHECKS(['Avancer malgré la maladie', 'Encaisser les coups de la vie', 'Progresser dans le combat', 'Esquiver les coups durs']),
            BOX([T('Sara Rezki', 'yg-sign__name'), T('Fondatrice de FightxLife', 'yg-sign__role')], 'yg-sign'),
            BTN('A propos de nous', '/qui-sommes-nous/'),
        ], 'yg-about__copy'),
    ], 'yg-about')])], 'yg-about-sec'),
    SEC('Nous soutenir', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([IMGP(LOCAL($BG['nous-soutenir'][0]), 'Une bénévole fait un cœur avec ses mains', 'yg-why__img fx-cover')], 'yg-why__media fx-media-photo'),
        BOX([
            HEAD('FightxLife', 'Nous soutenir', 'soutenir', 'Soutenir FightxLife, c’est encourager nos actions dans les hôpitaux, rendre le sourire à un enfant, réconforter des parents face au cancer et épauler nos membres pour une meilleure qualité de vie. Alors toi aussi rejoins-nous dans la lutte contre le cancer !', 'left'),
            BTN('En savoir plus', '/nous-soutenir/'),
        ], 'yg-why__copy'),
    ], 'yg-why')])], 'yg-why-sec'),
    SEC('Nos cours', [WRAP([HEAD('FightxLife', 'Nos cours', 'cours', 'Envie de rejoindre la Team FightxLife ? Nos cours sont adaptés et respectent le rythme de tous !'), GRID($coursCards), BOX([BTN('Tous nos cours', '/cours/')], 'yg-center')])], 'yg-content'),
    SEC('SmileBox', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([
            HEAD('FightxLife', 'SmileBox', 'Box', 'FightxLife vient en aide aux enfants diagnostiqués d’un cancer et à leurs familles en leur distribuant une SmileBox dans tous les hôpitaux de Belgique. Toi aussi, aide-nous à leur donner le sourire !', 'left'),
            BTN('En savoir plus', '/smile-box/'),
        ], 'yg-why__copy'),
        BOX([IMGP(LOCAL($BG['smile-box'][0]), 'La SmileBox offerte aux enfants hospitalisés', 'yg-why__img fx-cover')], 'yg-why__media fx-media-photo'),
    ], 'yg-why fx-why--flip')])], 'yg-why-sec fx-white'),
    SEC('Témoignages', [WRAP([HEAD('FightxLife', 'Témoignages', 'Témoignages'), GRID(array_map(static fn(array $t): array => QUOTE(...$t), $testimonials), 'yg-quote-grid fx-quote-grid', 4)])], 'yg-testi-sec'),
    SEC('E-shop', [WRAP([HEAD('FightxLife', 'E-shop', 'shop', 'Se faire plaisir et soutenir nos projets : deviens notre ambassadeur et arbore nos couleurs !'),
        GRID([PRODUCTCARD('casquette-adulte-fightxlife'), PRODUCTCARD('sweat-a-capuche'), PRODUCTCARD('t-shirt-fightxlife')]), BOX([BTN('Voir l’e-shop', '/e-shop/')], 'yg-center')])], 'yg-content'),
    SEC('Partenaires', [WRAP([HEAD('FightxLife', 'Nos sponsors & Partenaires', 'Partenaires'), LOGOS($HOMELOGOS)])], 'yg-content fx-logos-sec'),
], ['seo' => 'accueil']];

// Qui sommes-nous
$advisory = [
    ['depositphotos_425648378-stock-photo-cropped-view-woman-boxing-glovee-ponp6iudw27rd9tvabhjn1zaxz15dp2wmo0lk3zyb4.jpg', 'Sara R.', 'Fondatrice', 'Je remercie notre Advisory de nous avoir rejoint dans la lutte contre le cancer. Il est impératif de nous entourer de personnes de confiance qui mettent à notre disposition leurs expériences et leurs connaissances.'],
    ['advisory-board-ponp838pa4cypvjy4zv5snqyl3b67jasuf5qcpobxs.jpg', 'Mirella G.', 'Advisory Board', 'Un burn-out, suivi d’un cancer : je pensais qu’il serait difficile de se relever. Ma rencontre avec Sara, FightxLife et la boxe m’ont amenée à rejoindre l’Advisory Board.'],
    ['advisory-board2-ponp9nn0o6i62ha0zo8ry9im87l71dip26av5bcpkg.jpg', 'Fathia R.', 'Advisory Board', ''],
    ['user-pq7xyyeb9zmzganvleu2cm0sloeesrtbbxxity4p88.png', 'Karine V.', 'Advisory Board', ''],
    ['user-pq7xyyeb9zmzganvleu2cm0sloeesrtbbxxity4p88.png', 'Samira R.', 'Advisory Board', ''],
];
$team = [['sara-ponpqw0gqz9udxela26ghmgghhjey2rgcpahom9pdk.jpg', 'Sara R.', 'Fondatrice'], ['mirella-ponpu0t3j3k934uqzexostzrrq3glr78s7kr9tmgmw.jpg', 'Mirella', 'Administratrice'],
    ['nafisa-ponpvl7ex5pgfqktu3bayfrfeudhflf4zypw2fau9k.jpg', 'Nafissa', 'Administratrice'], ['cornelis-valerie-ponq3f6zvgfh6r783h7dqglpmfrlksim2qfk1foqew.jpg', 'Valérie Cornelis', 'Attachée de presse'],
    ['bellens-romane-ponqm7ysk65xdzw6blqrlptlbv3viv5cns55knt9yw.jpg', 'Bellens Romane', '3 fois championne européenne WCTS – Initiatrice coach sportif'],
    ['miloudi-ines-ponqm7ysk65xdzw6blqrlptlbv3viv5cns55knt9yw.jpg', 'Miloudi Inès', 'Coach Cancerfighter'], ['sylvia-ponr0bjn2pgrjfevzp5b05qi3xm2zf4elkfcq2womw.jpg', 'Sylvia', 'Coach WaterxFight']];
$honour = [['2022-06-comite-dhonneur1.jpg', 'Joel Jiberot', 'Coach sportif de Jujitsu Brésilien, responsable de la salle Phoenix A.D.A'], ['2022-05-cornelis-valerie.jpg', 'Valérie Cornelis', 'Attachée de presse'],
    ['2022-06-comite-dhonneur6.jpg', 'Karema Menassar', 'Manager Projet et Photographe'], ['2022-06-comite-dhonneur5.jpg', 'Nadeen Minampala', 'Consultante en Entrepreneuriat'],
    ['2022-06-comite-dhonneur3.jpg', 'Dr Bali', 'Médecin Spécialiste Douleur – Clinique du Lothier'], ['2022-09-unknown-1-1.jpeg', 'Pr. Dr Hamdi Moustapha', 'Chirurgien Plasticien UZ Brussel, fondateur de la Fondation Hamdi'],
    ['2022-06-user.png', 'Patricia Seghers', 'Médecin Généraliste']];
$ambassadors = [['5615d348-90ac-4a90-b0c4-6210521ec5e3-prsubd2o6bwoccg3zp240wbfjx3rtlwnha2f560ixc.jpg', 'Selloi Hannaoui'], ['dd483073-da89-4104-9e14-c2a0dad2eac1-prsubfw6qu0jb6c0j89zqdltc2pvgp7uho0vkzwceo.jpg', 'Marlène Hallard'],
    ['db74f76e-36fb-4c4f-abc4-d76d9b243bec-prsubeycjzz8zkddopvd5vucqoui90445jde3pxqkw.jpg', 'Esra Altigan'], ['f948c236-89b3-43ca-ab38-8a2845eb0c69-prsubippbc4ea07x2rhvfuw748bz3sj1i1zc0ts5w0.jpg', 'Angela'],
    ['user-pq7xyyea24ujuai56hyw8j6fkc6s50llg5ccojnh74.png', 'Nadia El Gharbany'], ['user-pq7xyyea24ujuai56hyw8j6fkc6s50llg5ccojnh74.png', 'Peut-être Vous ?']];
$pages['qui-sommes-nous'] = ['Qui Sommes-Nous', 'standalone', [
    BANNER('Qui sommes-nous', 'sommes-nous', 'Qui sommes-nous', PAGEBG('qui-sommes-nous')),
    SEC('Nos missions', [WRAP([HEAD('FightxLife', 'Nos missions', 'missions'), GRID([
        BOX([ICONTILE('user', 'yg-feature__icon'), H('Développement Personnel', 'h2', 'yg-feature__title'), T('A travers une boxe adaptée, il s’agit de réconcilier le patient avec son mental et son corps. Lâcher les coups pour mieux se libérer du cancer, ne plus subir la maladie mais se reconstruire à travers la boxe.', 'yg-feature__text')], 'yg-feature'),
        BOX([ICONTILE('zap', 'yg-feature__icon'), H('Développement Physique', 'h2', 'yg-feature__title'), T('Abattu et peu confiant, le patient n’exerce plus d’effort physique. Nous proposons par la boxe adaptée un renforcement musculaire et une stimulation de la mémoire par les gestes répétitifs.', 'yg-feature__text')], 'yg-feature'),
        BOX([ICONTILE('users', 'yg-feature__icon'), H('Développement Social', 'h2', 'yg-feature__title'), T('Se remettre dans la vie sociale et active, créer des liens et des échanges, établir des relations au sein du groupe. Ne plus être seul face à la maladie, ne plus être isolé.', 'yg-feature__text')], 'yg-feature'),
    ], 'yg-features')])], 'yg-features-sec yg-features-sec--page'),
    SEC('Nos valeurs', [WRAP([HEAD('FightxLife', 'Nos valeurs', 'valeurs'), GRID([
        BOX([ICONTILE('users', 'yg-feature__icon'), H('L’égalité des chances', 'h2', 'yg-feature__title'), T('Nous faisons en sorte que nos membres disposent des mêmes chances, des mêmes opportunités de développement social, indépendamment de leur origine sociale ou ethnique.', 'yg-feature__text')], 'yg-feature'),
        BOX([ICONTILE('heart', 'yg-feature__icon'), H('Le bien-être', 'h2', 'yg-feature__title'), T('La santé mentale et physique n’a pas de prix. Un bon moral et une bonne humeur peuvent être des atouts primordiaux nous permettant d’avancer.', 'yg-feature__text')], 'yg-feature'),
        BOX([ICONTILE('shield', 'yg-feature__icon'), H('La solidarité', 'h2', 'yg-feature__title'), T('Elle est cette main tendue vers l’autre pour lui rappeler qu’il n’est pas seul, dans un esprit marqué par le respect et le rythme de chacun.', 'yg-feature__text')], 'yg-feature'),
    ], 'yg-features')])], 'yg-features-sec fx-soft'),
    SEC('Rejoindre', [WRAP([T('FightxLife', 'yg-eyebrow'), H('Envie de nous rejoindre ? Contacte-nous !', 'h2', 'yg-h2', 'Contacte-nous !'), BTN('En savoir plus', '/contact/')], 'yg-consult')], 'yg-consult-sec'),
    SEC('Advisory Board', [WRAP([HEAD('FightxLife', 'Advisory Board', 'Board'), GRID(array_map(static fn(array $a): array => PERSON(F($a[0]), $a[1], $a[2], $a[3]), $advisory), 'yg-trainer-grid fx-people')])], 'yg-content'),
    SEC('Notre équipe', [WRAP([HEAD('FightxLife', 'Notre Équipe', 'Équipe'), GRID(array_map(static fn(array $a): array => PERSON(F($a[0]), $a[1], $a[2]), $team), 'yg-trainer-grid fx-people', 4)])], 'yg-team-sec'),
    SEC('Comité d’honneur', [WRAP([HEAD('FightxLife', 'Comité d’honneur', 'honneur', 'Notre comité d’honneur, qui partage l’expérience de chacun, aussi variée qu’intéressante, nous aide à penser nos projets et à choisir la meilleure voie pour assurer la pérennité de notre association.'),
        GRID(array_map(static fn(array $a): array => PERSON(F($a[0]), $a[1], $a[2]), $honour), 'yg-trainer-grid fx-people', 4)])], 'yg-content'),
    SEC('Nos ambassadeurs', [WRAP([HEAD('FightxLife', 'Nos Ambassadeurs', 'Ambassadeurs', 'Nos ambassadrices nous soutiennent, nous aident à mieux faire connaître l’association et nous accompagnent dans nos différentes activités.'),
        GRID(array_map(static fn(array $a): array => PERSON(F($a[0]), $a[1], 'Ambassadrice'), $ambassadors), 'yg-trainer-grid fx-people', 3), BOX([BTN('Devenir notre voix ? Nous contacter', '/contact/')], 'yg-center')])], 'yg-team-sec'),
    SEC('Sponsors', [WRAP([HEAD('FightxLife', 'Nos sponsors', 'sponsors'), LOGOS($SPONSORS)])], 'yg-content fx-logos-sec'),
    SEC('Partenaires', [WRAP([HEAD('FightxLife', 'Nos partenaires', 'partenaires'), LOGOS($PARTNERS)])], 'yg-content fx-logos-sec fx-pt0'),
], ['seo' => 'qui-sommes-nous']];

$pages['cours'] = ['Cours', 'standalone', [
    BANNER('Cours de Boxe', 'Boxe', 'Cours', PAGEBG('cours')),
    SEC('Intro', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([IMGP(F('2022-05-stock-photo-partial-view-woman-boxing-gloves-removebg-preview.png'), 'Gants de boxe', 'yg-about__img')], 'yg-about__media'),
        BOX(array_merge([T('FightxLife', 'yg-eyebrow'), H('Des cours adaptés pour chacun', 'h2', 'yg-h2', 'pour chacun')], BODY([array_values(array_filter(C('pages', 'cours')['blocks'], static fn(array $b): bool => $b['t'] === 'rich'))[0]])), 'yg-about__copy'),
    ], 'yg-about')])], 'yg-about-sec'),
    SEC('Nos cours', [WRAP([HEAD('FightxLife', 'Nos cours', 'cours'), GRID($coursCards)])], 'yg-content fx-soft'),
], ['seo' => 'cours']];

$pages['nos-actions'] = ['Nos Actions', 'standalone', [
    BANNER('Nos Actions', 'Actions', 'Nos actions', PAGEBG('nos-actions')),
    SEC('Intro', [WRAP([HEAD('FightxLife', 'Chaque action nous rapproche de l’humanité', 'l’humanité', 'FightxLife est active dans la société pour une meilleure qualité de vie auprès de personnes atteintes du cancer. Elle veille à une égalité des chances face à la maladie dans le monde sportif, et se tient aux côtés des enfants et des parents pendant ces moments difficiles.')])], 'yg-content fx-pb0'),
    SEC('Soutiens un enfant', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([IMGP(LOCAL($BG['smile-box'][0]), 'La SmileBox', 'yg-why__img fx-cover')], 'yg-why__media fx-media-photo'),
        BOX([HEAD('SmileBox', 'Soutiens un enfant !', 'enfant', 'L’association a créé la SmileBox, une box offerte gratuitement à tous les enfants diagnostiqués d’un cancer.', 'left'), BTN('Découvrir la SmileBox', '/smile-box/')], 'yg-why__copy'),
    ], 'yg-why')])], 'yg-why-sec'),
    SEC('Projets', [WRAP([HEAD('FightxLife', 'Nos projets', 'projets'), GRID([
        CARD(LOCAL($BG['nos-actions'][2]), 'Solidarité', 'Ce projet dépasse nos frontières et sollicite une chaîne de solidarité : chaque personne compte, ici et ailleurs.', '/solidarite-5/'),
        CARD(LOCAL($BG['nos-actions'][3]), 'Visites hôpitaux', 'Tout au long de l’année, FightxLife rend visite aux enfants des services oncologie et pédiatrie et leur apporte un rayon de soleil.', '/les-hopitaux/'),
        CARD(LOCAL($BG['nos-actions'][4]), 'Gala', 'Chaque année, un Gala de charité pour informer et sensibiliser le public face au cancer, et recueillir des dons.', '/gala/'),
        CARD(LOCAL($BG['nos-actions'][5]), 'Crowdfunding', 'Pendant 36H lors de la journée mondiale du cancer, témoignages, artistes et appel aux dons.', '/crowdfunding/'),
    ], 'yg-class-grid fx-grid4', 4)])], 'yg-content'),
    DONATE(),
], ['seo' => 'nos-actions']];

$pages['nous-soutenir'] = ['Nous Soutenir', 'standalone', [
    BANNER('Nous Soutenir', 'Soutenir', 'Nous soutenir', PAGEBG('nous-soutenir')),
    SEC('Ensemble', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'center'], [
        BOX([IMGP(LOCAL($BG['nous-soutenir'][0]), 'Une bénévole fait un cœur avec ses mains', 'yg-why__img fx-cover')], 'yg-why__media fx-media-photo'),
        BOX([HEAD('FightxLife', 'Ensemble contre le cancer', 'le cancer', 'FightxLife a besoin de ton aide, et aujourd’hui tu as le pouvoir de mener le travail de FightxLife au niveau suivant. Rejoins FightxLife dans le Combat pour la Vie face au cancer au travers de la boxe adaptée.', 'left'), BTN('Faire un don', '/donation/')], 'yg-why__copy'),
    ], 'yg-why')])], 'yg-why-sec'),
    SEC('Merci', [WRAP([HEAD('FightxLife', 'Merci pour ton soutien !', 'soutien', 'Soutiens FightxLife dans sa lutte contre le cancer en faisant un don, en organisant une action, en faisant un partenariat ou encore en devenant bénévole. Quelle que soit la façon avec laquelle vous nous aiderez, FightxLife vous remercie !'),
        GRID([
            BOX([IMGP(F('2022-06-donation.png'), '', 'fx-tile__img'), B('core.heading', ['text' => 'Je fais un don', 'level' => 'h3', 'url' => '/donation/'], [], 'yg-feature__title')], 'yg-feature fx-tile'),
            BOX([IMGP(F('2022-06-coup-de-main.png'), '', 'fx-tile__img'), B('core.heading', ['text' => 'Je deviens bénévole', 'level' => 'h3', 'url' => '/contact/'], [], 'yg-feature__title')], 'yg-feature fx-tile'),
            BOX([IMGP(F('2022-06-event.png'), '', 'fx-tile__img'), B('core.heading', ['text' => 'J’organise un événement', 'level' => 'h3', 'url' => '/contact/'], [], 'yg-feature__title')], 'yg-feature fx-tile'),
            BOX([IMGP(F('2022-06-cooperation.png'), '', 'fx-tile__img'), B('core.heading', ['text' => 'Je souhaite être partenaire', 'level' => 'h3', 'url' => '/contact/'], [], 'yg-feature__title')], 'yg-feature fx-tile'),
        ], 'yg-features fx-grid4', 4), BOX([BTN('Des questions ?', '/contact/')], 'yg-center')])], 'yg-content'),
], ['seo' => 'nous-soutenir']];

$contactCard = static fn(string $icon, string $title, array $lines): array => BOX(array_merge([ICONTILE($icon, 'yg-contact__icon'), H($title, 'h3', 'yg-contact__title')], $lines), 'yg-contact__card');
$pages['contact'] = ['Contact', 'standalone', [
    BANNER('Contact', 'Contact', 'Contact', PAGEBG('contact')),
    SEC('Contact', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [
        HEAD('Contact', 'Contacte-nous !', 'nous !', 'Tu as des questions sur FightxLife ? Tu souhaites t’investir en tant que bénévole, nous soutenir avec une levée de fonds ou devenir partenaire ? N’hésite plus, et contacte-nous via le formulaire !', 'left'),
        B('core.form', ['form_name' => 'contact', 'submit_text' => 'Envoyer', 'submit_variant' => 'primary', 'success_message' => 'Merci ! Nous avons bien reçu ton message et te répondrons rapidement.'], [
            B('core.form_field', ['field_type' => 'text', 'name' => 'name', 'label' => 'Nom', 'placeholder' => 'Nom', 'required' => true]),
            B('core.form_field', ['field_type' => 'email', 'name' => 'email', 'label' => 'Email', 'placeholder' => 'Email', 'required' => true]),
            B('core.form_field', ['field_type' => 'select', 'name' => 'subject', 'label' => 'Sujet', 'placeholder' => 'Sujet', 'options' => 'Je fais un don, Je deviens bénévole, J’organise un événement, Je souhaite être partenaire, J’ai une question']),
            B('core.form_field', ['field_type' => 'textarea', 'name' => 'message', 'label' => 'Message', 'placeholder' => 'Message']),
        ], 'yg-contact__form', ['id' => STABLE_ID('contact-form')]),
            B('layout.grid', ['columns' => 2, 'gap' => 'none'], [
                $contactCard('map-pin', 'ASBL FightxLife', [T('Rue Gabrielle Petit, 32 - 1080 Molenbeek-Saint-Jean', 'yg-contact__line'), T('Numéro d’entreprise : 720.965564', 'yg-contact__line')]),
                $contactCard('phone', 'Rezki Sara', [LNK('GSM : +32 483 12 72 98', 'tel:+32483127298', 'yg-contact__line'), LNK('contact@fightxlife.com', 'mailto:contact@fightxlife.com', 'yg-contact__line')]),
            ], 'yg-contact__cards'),
    ], 'yg-contact')])], 'yg-contact-sec'),
    SEC('Map', [WRAP([B('core.map', ['latitude' => 50.8563, 'longitude' => 4.3366, 'zoom' => 15, 'height' => 'lg', 'title' => 'FightxLife, Rue Gabrielle Petit 32, Molenbeek'], [], 'yg-map')])], 'yg-map-sec'),
], ['seo' => 'contact']];

// Presse: the live list of articles, each linking to the outlet.
$press = []; $pb = C('pages', 'presse')['blocks']; $pendingImg = '';
for ($i = 0; $i < count($pb); $i++) {
    $b = $pb[$i];
    if ($b['t'] === 'img') { $pendingImg = LOCAL($b['src']); continue; }
    if ($b['t'] === 'h' && $b['level'] === 'h3') {
        $next = $pb[$i + 1] ?? null; $text = ''; $href = '';
        if ($next && $next['t'] === 'rich') { $text = trim(strip_tags(preg_replace('~<a .*$~s', '', $next['html']))); }
        $press[] = ['img' => $pendingImg, 'title' => $b['text'], 'text' => $text];
        $pendingImg = '';
    }
}
// Each article's link is the anchor at the end of the rich block BEFORE its image (the live markup wraps card N's link around card N+1's image).
$links = [];
foreach ($pb as $b) { if ($b['t'] === 'rich' && preg_match_all('~<a href="(https?://(?!fightxlife)[^"]+)">~', $b['html'], $m)) { foreach ($m[1] as $u) { if (!str_contains($u, 'soundcloud')) { $links[] = html_entity_decode($u); } } } }
$pressCards = [];
foreach ($press as $k => $pr) {
    $href = $links[$k - 1] ?? ($k === 0 ? 'https://www.dhnet.be/' : '/presse/');
    $kids = [];
    if ($pr['img'] !== '') { $kids[] = IMGP($pr['img'], $pr['title'], 'yg-post__img', ['link_to' => 'custom', 'url' => $href, 'new_tab' => true]); }
    $kids[] = BOX(array_values(array_filter([B('core.heading', ['text' => $pr['title'], 'level' => 'h3', 'url' => $href, 'new_tab' => true], [], 'yg-post__title'), $pr['text'] !== '' ? T(mb_substr($pr['text'], 0, 260), 'yg-post__text') : null, LNK('Lire →', $href, 'yg-more')])), 'yg-post__body');
    $pressCards[] = BOX($kids, 'yg-post');
}
$pages['presse'] = ['Presse', 'standalone', [
    BANNER('Presse', 'Presse', 'Presse', PAGEBG('presse')),
    SEC('Radios & Podcasts', [WRAP([HEAD('FightxLife', 'Radios & Podcasts', 'Podcasts'), BOX([H('Positive Fem - Sara Rezki - Fightxlife', 'h3', 'yg-post__title'), T('Sara Rezki raconte FightxLife au micro de BX FM.', 'yg-post__text'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'justify' => 'center', 'wrap' => 'wrap'], [BTN('Écouter sur SoundCloud', 'https://soundcloud.com/bxfmradio/positive-fem-sara-rezki-fightxlife'), BTN('Écouter sur Spotify', 'https://open.spotify.com/episode/4pBb3MgCQxSDA9F188P69t', 'yg-btn yg-btn--dark')], 'yg-hero__actions')], 'fx-podcast')])], 'yg-content fx-pb0'),
    SEC('Articles', [WRAP([HEAD('FightxLife', 'Articles', 'Articles'), GRID($pressCards, 'yg-posts fx-press', 3)])], 'yg-content'),
], ['seo' => 'presse']];

$blogLoop = static fn(): array => B('core.query_loop', ['source' => 'posts', 'category' => CAT, 'per_page' => 15, 'columns' => 3, 'card_variant' => 'card', 'show_author' => false, 'show_category' => true, 'read_more_text' => 'Lire la suite', 'empty_message' => 'Aucun article.'], [], 'fx-loop');
$pages['blog'] = ['Blog', 'standalone', [BANNER('Blog', 'Blog', 'Blog', PAGEBG('blog')), SEC('Articles', [WRAP([HEAD('Actualités', 'Le blog de FightxLife', 'FightxLife', 'Articles, conseils, infos et événements de l’association.'), $blogLoop()])], 'yg-content')], ['seo' => 'blog']];
$pages['actualites'] = ['Actualités', 'standalone', [BANNER('Actualités', 'Actualités', 'Actualités', PAGEBG('blog')), SEC('Articles', [WRAP([$blogLoop()])], 'yg-content')],
    ['seo_title' => 'Actualités | Fightxlife', 'seo_desc' => 'Toutes les actualités de FightxLife : actions dans les hôpitaux, événements, partenariats et témoignages.']];

// Events (newest first)
$eventSlugs = ['chaque-pas-compte', 'bounce-back-festival-baob', 'fightxlife-soutien-le-relais-pour-la-vie-2024', 'les-fous-rires-de-fightxlife-2', 'les-fous-rires-de-fightxlife',
    'fightxlife-au-festival-des-rencontres', 'village-de-noel-a-rouge-cloitre-auderghem', 'event-gala-octo-pink', 'colloque-free-clinic'];
$eventsGrid = static fn(): array => GRID(array_map('EVENTCARD', $eventSlugs), 'yg-posts fx-events', 3);
$pages['evenements'] = ['Evénements', 'standalone', [BANNER('Evénements', 'Evénements', 'Evénements', PAGEBG('evenements')),
    SEC('Événements', [WRAP([HEAD('FightxLife', 'Nos événements', 'événements', 'FightxLife organise de nombreux événements tout au long de l’année.'), $eventsGrid()])], 'yg-content')], ['seo' => 'evenements']];
$pages['events'] = ['Événements', 'standalone', [BANNER('Evénements Passés', 'Passés', 'Evénements', PAGEBG('evenements')), SEC('Événements', [WRAP([$eventsGrid()])], 'yg-content')],
    ['seo_title' => 'Événements Archive - Fightxlife', 'seo_desc' => 'Les événements passés de FightxLife : galas, festivals, marches solidaires et soirées pour la lutte contre le cancer.']];

$shop = static fn(): array => GRID([PRODUCTCARD('casquette-adulte-fightxlife'), PRODUCTCARD('sweat-a-capuche'), PRODUCTCARD('t-shirt-fightxlife')]);
$pages['e-shop'] = ['E-Shop', 'standalone', [BANNER('E-Shop', 'Shop', 'E-shop', PAGEBG('e-shop')),
    SEC('Produits', [WRAP([HEAD('FightxLife', 'Arbore nos couleurs', 'couleurs', 'Acheter sur la boutique de FightxLife, c’est faire un don contre le cancer et nous soutenir dans la lutte contre la maladie.'), $shop()])], 'yg-content')], ['seo' => 'e-shop']];
$pages['boutique'] = ['Boutique', 'standalone', [BANNER('Boutique', 'Boutique', 'Boutique', PAGEBG('e-shop')), SEC('Produits', [WRAP([$shop()])], 'yg-content')],
    ['seo_title' => 'Boutique - Fightxlife', 'seo_desc' => 'La boutique FightxLife : casquettes, sweats et t-shirts pour soutenir la lutte contre le cancer.']];

$pages['donation'] = ['Donation', 'standalone', [
    BANNER('Faire un don', 'don', 'Donation', PAGEBG('nous-soutenir')),
    SEC('Don', [WRAP([HEAD('FightxLife', 'Ton don, nos actions', 'nos actions', 'Tes dons se traduisent en actions concrètes : visites dans les hôpitaux, SmileBox pour les enfants, cours de boxe adaptée. Choisis un montant, le paiement est sécurisé par Stripe.'),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'justify' => 'center', 'wrap' => 'wrap'], array_map(static fn(int $a): array =>
            B('payment.button', ['label' => "Donner {$a} €", 'amount' => $a, 'currency' => 'EUR', 'description' => 'Don à FightxLife ASBL'], [], 'yg-btn fx-pay'), [10, 25, 50, 100]), 'fx-donate'),
        T('Tu préfères faire un virement ou donner du matériel ? Écris-nous, nous te répondons rapidement.', 'yg-lead fx-donate__alt'), BOX([BTN('Nous contacter', '/contact/', 'yg-btn yg-btn--dark')], 'yg-center')])], 'yg-content'),
], ['seo_title' => 'Faire un don | Fightxlife', 'seo_desc' => 'Fais un don à FightxLife : tes dons financent les cours de boxe adaptée, les visites dans les hôpitaux et les SmileBox pour les enfants.']];

foreach (['politique-de-confidentialite' => 'Politique de Confidentialité', 'conditions-generales-de-vente' => 'Conditions Générales de Vente'] as $slug => $title) {
    $pages[$slug] = [$title, 'standalone', [BANNER($title, '', $title), SEC('Texte', [WRAP(BODY(C('pages', $slug)['blocks']), 'fx-legal')], 'yg-content')],
        ['seo' => $slug, 'seo_desc_fallback' => "{$title} de l’ASBL FightxLife."]];
}

// Courses (type cours), with their live body.
$courseMeta = [
    '100-feminin'  => ['Quai des Charbonnages, 82 - 1080 Molenbeek-Saint-Jean', 'Les lundis, mercredis et vendredis de 18h00 à 19h15', '75 minutes', 'Exclusivement pour les femmes, quelle que soit ta condition physique', "Inscription et assurance annuelle 50€\nAbonnement mensuel : 40€\nAbonnement trimestriel : 110€\nAbonnement semestriel : 210€"],
    'boxe-adaptee' => ['Phoenix A.D.A Martial Arts, Avenue de la Toison d’Or 39 - 1050 Ixelles', 'Les lundis et vendredis de 17h30 à 19h00', '90 minutes', 'Toute personne motivée, même en cours de traitement', "Inscription et assurance annuelle 50€\nAbonnement mensuel : 30€"],
    'waterxfight'  => ['Piscine du Centre, Rue du Chevreuil 28 - 1000 Bruxelles', 'Les mercredis et vendredis à 19h00, les dimanches à 15h45', '45 minutes', 'Toute personne motivée, quelle que soit sa condition physique', "Inscription annuelle : 20 €\nAbonnement 10 séances : 110 € (valable 3 mois)"],
];
// Course pages: the live copy, laid out as sections (the live Elementor layout converts to one long list).
$courseCopy = [
    '100-feminin' => ['La Boxe pour les Femmes, par des Femmes', 'Boxe 100% féminin', LOCAL($BG['cours'][3]), [
        'Pour répondre à une demande plus que nécessaire, FightxLife propose des cours de kickboxing exclusivement réservés aux <strong>femmes</strong> et encadrés par des coachs professionnelles femmes.',
        'Rassembler les femmes entre elles permet à FightxLife de les sensibiliser chacune au cancer, de leur donner confiance en elles et de leur ouvrir la porte au monde du sport.',
        'Le kickboxing est un sport de combat qui combine différentes techniques de poings et de pieds.'],
        'Bienfaits de la boxe', ['Améliore le cardio vasculaire, l’endurance et la respiration', 'Favorise le renforcement musculaire', 'Développe la rapidité, l’agilité et la coordination', 'Libère les tensions et le stress', 'Tonifie les muscles'],
        'Quelle que soit ta condition physique ou ton expérience, rejoins-nous !'],
    'boxe-adaptee' => ['La Boxe Adaptée aux Malades', 'Qu’est-ce que la boxe adaptée ?', LOCAL($BG['cours'][0]), [
        'C’est une séance de boxe encadrée par des coachs professionnels qui se déroule en salle pendant <strong>90 minutes</strong>.',
        'Contrairement aux cours de boxe traditionnels, celle-ci reprend les techniques de la boxe afin de s’adapter aux personnes atteintes et/ou ayant été atteintes d’un cancer ou d’autres maladies. Nos coachs vous font évoluer selon votre rythme, votre capacité et votre condition physique.',
        'Aucun coup n’est porté au corps : aucun combat n’est effectué. L’activité physique est essentielle et la boxe reste un atout majeur face à la dépression, la peur, les angoisses, etc.'],
        'Bienfaits de la boxe adaptée', ['Renforcement musculaire', 'Coordination', 'Concentration', 'Stimulation motrice, sensorielle et cognitive', 'Reprendre confiance en soi', 'Extérioriser sa colère', 'Établir des relations au sein d’un groupe'],
        'Les cours sont accessibles à toute personne motivée, même en cours de traitement.'],
    'waterxfight' => ['De la Boxe dans l’eau', 'Qu’est-ce que WaterxFight ?', PAGEBG('waterxfight') ?: LOCAL($BG['cours'][4]), [
        'Le <strong>WaterxFight</strong> est une séance de boxe encadrée par des coachs professionnels qui se déroule en piscine pendant <strong>45 minutes</strong>, dont les mouvements sont inspirés des arts martiaux et des techniques de boxe.',
        'Elle se pratique avec des gants de boxe spécifiques, afin de mieux travailler avec la résistance de l’eau, et tout cela en musique. Grâce à la résistance de l’eau, les coups sont ralentis : l’eau préserve des impacts et des secousses sur l’organisme et les articulations, sans aucun risque de blessure.',
        'Il suffit de se munir d’un maillot et d’un bonnet de bain.'],
        'Bienfaits de WaterxFight', ['Évacuer le stress et les tensions', 'Développer et renforcer les capacités respiratoires', 'Muscler l’intégralité du corps en douceur et en profondeur', 'Drainage lymphatique', 'Tonifier le rythme cardio-vasculaire', 'Renforcer les muscles', 'Travailler l’équilibre'],
        'Un cours accessible à toute personne motivée, quelle que soit sa condition physique.'],
];
foreach ($courseMeta as $slug => [$lieu, $horaires, $duree, $public, $tarifs]) {
    $it = $p($slug);
    [$tagline, $what, $photo, $paras, $benefitsTitle, $benefits, $forWho] = $courseCopy[$slug];
    $body = [
        IMGP($photo, $it['title'], 'fx-body__img fx-body__hero'),
        T('Cours FightxLife', 'yg-eyebrow'),
        H($tagline, 'h2', 'fx-body__h fx-body__h2 fx-course__title'),
        H($what, 'h3', 'fx-body__h fx-body__h3'),
        RICH(implode('', array_map(static fn(string $t): string => "<p>{$t}</p>", $paras))),
        BOX([H($benefitsTitle, 'h3', 'fx-body__h fx-body__h3'), CHECKS($benefits, 'fx-benefits')], 'fx-panel'),
        BOX([H('Pour qui ?', 'h3', 'fx-body__h fx-body__h3'), T($forWho, 'fx-panel__text')], 'fx-panel fx-panel--soft'),
        BOX([T('Inscription', 'yg-eyebrow'), H('Pour t’inscrire, connecte-toi !', 'h3', 'fx-cta__title'), T('Les inscriptions et les réservations se font dans l’espace membre FightxLife.', 'fx-panel__text'), BTN('Accéder à l’espace membre', MEMBRE)], 'fx-cta'),
    ];
    $pages[$slug] = [$it['title'], 'standalone', [SEC('Cours', $body, 'fx-body')], ['seo' => $slug], 'cours',
        ['lieu' => $lieu, 'horaires' => $horaires, 'duree' => $duree, 'public' => $public, 'tarifs' => $tarifs]];
}
foreach (['smile-box', 'solidarite-5', 'les-hopitaux', 'gala', 'crowdfunding'] as $slug) {
    $it = $p($slug);
    $body = BODY($it['blocks']);
    $bg = PAGEBG($slug);
    if ($bg !== '' && $slug !== 'smile-box') { array_unshift($body, IMGP($bg, $it['title'], 'fx-body__img fx-body__hero')); }
    $pages[$slug] = [$it['title'], 'standalone', [SEC('Projet', $body, 'fx-body')], ['seo' => $slug], 'projet', ['accroche' => $it['seo_desc']]];
}
foreach ($eventSlugs as $slug) {
    $it = C('mec-events', $slug); $ev = $EXTRA['events'][$slug];
    $body = BODY($it['blocks']);
    if ($it['featured'] !== '' && LOCAL($it['featured']) !== '') { array_unshift($body, IMGP(LOCAL($it['featured']), $it['title'], 'fx-body__img fx-body__hero')); }
    $pages[$slug] = [$it['title'], 'standalone', [SEC('Événement', $body, 'fx-body')], ['seo_item' => "mec-events:{$slug}"], 'evenement',
        ['date' => $ev['date'], 'heure' => $ev['heure'], 'tarif' => $ev['tarif'] !== '' ? $ev['tarif'] : 'Gratuit', 'lieu' => $ev['lieu']]];
}
foreach (['casquette-adulte-fightxlife', 'sweat-a-capuche', 't-shirt-fightxlife'] as $slug) {
    $it = C('product', $slug); $pr = $EXTRA['products'][$slug];
    $body = [IMGP(LOCAL($it['featured']), $it['title'], 'fx-body__img fx-product__photo'),
        H($it['title'], 'h2', 'fx-body__h fx-body__h2'),
        T($pr['price'], 'fx-product__price'),
        RICH('<p>Avec cet achat, tu soutiens directement les actions de FightxLife : cours de boxe adaptée, visites dans les hôpitaux et SmileBox pour les enfants.</p>'
            . ($pr['couleurs'] !== '' ? '<ul><li><strong>Couleurs :</strong> ' . htmlspecialchars($pr['couleurs']) . '</li><li><strong>Tailles :</strong> ' . htmlspecialchars($pr['tailles']) . '</li></ul>' : ''))];
    $body = array_merge($body, BODY($it['blocks']));
    $amount = (float) str_replace(',', '.', preg_replace('/[^0-9,]/', '', $pr['price']));
    $body[] = B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'wrap' => 'wrap'], [
        B('payment.button', ['label' => 'Acheter — ' . $pr['price'], 'amount' => $amount, 'currency' => 'EUR', 'description' => $it['title']], [], 'yg-btn fx-pay'),
        BTN('Commander par message', '/contact/', 'yg-btn yg-btn--dark fx-order')], 'fx-buy');
    $pages[$slug] = [$it['title'], 'standalone', [SEC('Produit', $body, 'fx-body')], ['seo_item' => "product:{$slug}"], 'produit',
        ['prix' => $pr['price'], 'couleurs' => $pr['couleurs'], 'tailles' => $pr['tailles']]];
}
// Articles: pages in "Actualités", original dates, featured image first.
foreach (array_filter($CONTENT, static fn(array $i): bool => $i['kind'] === 'posts') as $it) {
    $body = BODY($it['blocks']);
    $feat = LOCAL($it['featured']);
    if ($feat !== '') { array_unshift($body, IMGP($feat, $it['title'], 'fx-body__img fx-body__hero')); }
    $pages[$it['slug']] = [$it['title'], 'standalone', [SEC('Article', $body, 'fx-body')],
        ['seo_item' => "posts:{$it['slug']}", 'category' => CAT, 'excerpt' => mb_substr($it['seo_desc'] !== '' ? $it['seo_desc'] : $it['excerpt'], 0, 300)], 'article', [], $it['date']];
}

// ── Single templates ─────────────────────────────────────────────────────────────────────────────────────────────
$row = static fn(string $label, string $key, string $tag = 'span'): array => B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'gap' => 'none'], [T($label, 'yg-info__label'), B('theme.field_value', ['field' => $key, 'tag' => $tag === 'div' ? 'div' : 'span'], [], 'yg-info__value')], 'yg-info__row');
$titleBanner = static fn(string $crumb, string $crumbUrl): array => SEC('Banner', [WRAP([
    B('theme.post_title', ['level' => 'h1'], [], 'yg-banner__title'),
    B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'wrap' => 'wrap'], [LNK('Accueil', '/', 'yg-crumb__home'), T('›', 'yg-crumb__sep'), LNK($crumb, $crumbUrl, 'yg-crumb__here fx-crumb-link')], 'yg-crumbs'),
])], 'yg-banner');
$helpCard = BOX([
    IMGP(F('2022-05-fightxlife-merch-30.jpg'), 'La team FightxLife', 'yg-promo__img'),
    BOX([T('Une question ?', 'yg-promo__eyebrow'), H('Contacte-nous', 'h3', 'yg-promo__title'), LNK('+32 483 12 72 98', 'tel:+32483127298', 'yg-promo__link'), LNK('contact@fightxlife.com', 'mailto:contact@fightxlife.com', 'yg-promo__link')], 'yg-promo__copy'),
], 'yg-promo yg-promo--help');
$templates = [
    'fxl-article' => ['Article', [['type' => 'include', 'condition' => 'category', 'value' => CAT]], [
        SEC('Banner', [WRAP([B('theme.post_title', ['level' => 'h1', 'align' => 'center'], [], 'yg-banner__title'), B('theme.post_meta', ['show_author' => false, 'show_date' => true, 'show_category' => true, 'separator' => ''], [], 'yg-post-meta')])], 'yg-banner yg-banner--centered'),
        SEC('Article', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [
            B('theme.post_content', [], [], 'yg-article'),
            BOX([
                BOX([H('D’autres articles', 'h3', 'yg-widget__title'), B('core.query_loop', ['source' => 'posts', 'category' => CAT, 'per_page' => 4, 'columns' => 1, 'card_variant' => 'minimal', 'show_featured_image' => false, 'show_author' => false, 'show_category' => false, 'show_excerpt' => false, 'enable_pagination' => false, 'read_more_text' => 'Lire', 'title_level' => 'h4'], [], 'fx-recent')], 'yg-widget'),
                BOX([IMGP(F('2022-05-fightxlife-merch-32.jpg'), 'SmileBox', 'yg-promo__img'), BOX([T('Soutiens un enfant', 'yg-promo__eyebrow'), H('La SmileBox', 'h3', 'yg-promo__title'), LNK('Découvrir →', '/smile-box/', 'yg-promo__link')], 'yg-promo__copy')], 'yg-promo'),
                BOX([H('Nous soutenir', 'h3', 'yg-widget__title'), T('Tes dons se traduisent en actions concrètes !', 'fx-widget__text'), BTN('Faire un don', '/donation/', 'yg-btn yg-btn--block')], 'yg-widget'),
            ], 'yg-sidebar'),
        ], 'yg-blog-layout yg-blog-layout--single')])], 'yg-content yg-single-sec'),
    ]],
    'fxl-cours' => ['Cours', [['type' => 'include', 'condition' => 'post_type', 'value' => 'cours']], [
        $titleBanner('Cours', '/cours/'),
        SEC('Cours', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [
            B('theme.post_content', [], [], 'yg-detail__main'),
            BOX([BOX([
                H('Infos pratiques', 'h3', 'fx-info__title'),
                BOX([T('Où', 'yg-info__label'), B('theme.field_value', ['field' => 'lieu', 'tag' => 'p'], [], 'fx-info__val')], 'fx-info__block'),
                BOX([T('Quand', 'yg-info__label'), B('theme.field_value', ['field' => 'horaires', 'tag' => 'p'], [], 'fx-info__val')], 'fx-info__block'),
                $row('Durée', 'duree'),
                BOX([T('Pour qui', 'yg-info__label'), B('theme.field_value', ['field' => 'public', 'tag' => 'p'], [], 'fx-info__val')], 'fx-info__block'),
                BOX([T('Tarifs', 'yg-info__label'), B('theme.field_value', ['field' => 'tarifs'], [], 'fx-info__val fx-info__list')], 'fx-info__block'),
                BTN('Pour t’inscrire, connecte-toi', MEMBRE, 'yg-btn yg-btn--block'),
            ], 'yg-info'), $helpCard], 'yg-detail__side'),
        ], 'yg-detail')])], 'yg-content yg-detail-sec'),
    ]],
    'fxl-projet' => ['Projet', [['type' => 'include', 'condition' => 'post_type', 'value' => 'projet']], [
        $titleBanner('Nos actions', '/nos-actions/'),
        SEC('Projet', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [
            B('theme.post_content', [], [], 'yg-detail__main'),
            BOX([BOX([H('Nos actions', 'h3', 'fx-info__title'), B('core.nav_menu', ['menu' => $menuActions, 'items' => [], 'layout' => 'vertical', 'aria_label' => 'Nos actions'], [], 'fx-side-nav'), BTN('Faire un don', '/donation/', 'yg-btn yg-btn--block')], 'yg-info fx-info--short'), $helpCard], 'yg-detail__side'),
        ], 'yg-detail')])], 'yg-content yg-detail-sec'),
        DONATE(),
    ]],
    'fxl-evenement' => ['Événement', [['type' => 'include', 'condition' => 'post_type', 'value' => 'evenement']], [
        $titleBanner('Evénements', '/evenements/'),
        SEC('Événement', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [
            B('theme.post_content', [], [], 'yg-detail__main'),
            BOX([BOX([H('Infos', 'h3', 'fx-info__title'), $row('Date', 'date'), $row('Heure', 'heure'), $row('Tarif', 'tarif'), BTN('Nous contacter', '/contact/', 'yg-btn yg-btn--block'),
                B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [T('Partager', 'yg-share__label'),
                    B('core.icon', ['name' => 'facebook', 'url' => 'https://www.facebook.com/Fightxlife', 'new_tab' => true, 'label' => 'Facebook', 'size_px' => 15], [], 'fx-social-ico fx-social-ico--line'),
                    B('core.icon', ['name' => 'linkedin', 'url' => 'https://www.linkedin.com/company/fightxlife/', 'new_tab' => true, 'label' => 'LinkedIn', 'size_px' => 15], [], 'fx-social-ico fx-social-ico--line')], 'yg-share yg-share--card')], 'yg-info fx-info--short'), $helpCard], 'yg-detail__side'),
        ], 'yg-detail')])], 'yg-content yg-detail-sec'),
    ]],
    'fxl-produit' => ['Produit', [['type' => 'include', 'condition' => 'post_type', 'value' => 'produit']], [
        $titleBanner('E-shop', '/e-shop/'),
        SEC('Produit', [WRAP([B('layout.grid', ['columns' => 2, 'gap' => 'none', 'align' => 'start'], [
            B('theme.post_content', [], [], 'yg-detail__main'),
            BOX([BOX([H('Informations', 'h3', 'fx-info__title'), $row('Prix', 'prix'), $row('Couleurs', 'couleurs'), $row('Tailles', 'tailles'),
                T('Précise ta taille et ta couleur dans le message du paiement, ou écris-nous.', 'fx-widget__text'), BTN('Une question ?', '/contact/', 'yg-btn yg-btn--block yg-btn--dark')], 'yg-info fx-info--short'), $helpCard], 'yg-detail__side'),
        ], 'yg-detail')])], 'yg-content yg-detail-sec'),
    ]],
];

// ── Build ────────────────────────────────────────────────────────────────────────────────────────────────────────
// A sticky section can't stick past the header partial around it, so the whole header sticks (its "Sticky header" setting).
build_page('default', 'Header', 'header_partial', 'standalone', $header, ['sticky_header' => ['edge' => 'top', 'offset' => 0, 'devices' => ['desktop', 'tablet', 'mobile'], 'shadow' => true, 'background' => '#ffffff']]);
build_page('default', 'Footer', 'footer_partial', 'standalone', $footer);
$n = 0;
foreach ($pages as $slug => $def) {
    [$title, $route, $sections] = $def;
    $settings = $def[3] ?? [];
    $type = $def[4] ?? null; $values = $def[5] ?? []; $date = $def[6] ?? null;
    $src = isset($settings['seo']) ? C('pages', $settings['seo']) : (isset($settings['seo_item']) ? $CONTENT[$settings['seo_item']] : null);
    $seoTitle = $settings['seo_title'] ?? ($src['seo_title'] ?? "{$title} - Fightxlife");
    $seoDesc = $settings['seo_desc'] ?? ($src['seo_desc'] ?? '');
    if ($seoDesc === '') { $seoDesc = $settings['seo_desc_fallback'] ?? ($src['excerpt'] ?? ''); }
    $og = null;
    if ($src && ($src['featured'] ?? '') !== '' && ($ogPath = LOCAL($src['featured'])) !== '') { $og = MID($ogPath) ?: null; }
    unset($settings['seo'], $settings['seo_item'], $settings['seo_title'], $settings['seo_desc'], $settings['seo_desc_fallback']);
    $pageSlug = $slug === 'accueil' ? 'accueil' : $slug;
    $id = build_page($pageSlug, $title, 'page', $route, $sections, $settings, $seoTitle, $seoDesc, $og);
    if ($type !== null) { PageMeta::save($tid, $id, $type, $values); }
    if ($date !== null) { Database::query('UPDATE studiobuilder_pages SET published_at = ? WHERE id = ?', [date('Y-m-d H:i:s', (int) strtotime($date)), $id]); }
    $n++;
}
foreach ($templates as $key => [$name, $rules, $sections]) {
    $doc = \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::emptyDocument('system', 'default', $name);
    $doc['seo']['robots'] = 'noindex,nofollow';
    $doc['settings']['conditions'] = ['rules' => $rules];
    $doc['settings']['template_type'] = 'single';
    $doc['sections'] = $sections;
    check_doc($doc);
    $runtime->app->saveTemplate($actor, $key, 'page_template', 'single', $name, "FightxLife {$name} template", $doc, null, true);
    build_page('template-' . $key, "[Theme] {$name}", 'system', 'standalone', $sections, ['conditions' => ['rules' => $rules], 'template_type' => 'single'], "{$name} | FightxLife");
}

// Redirects for retired WooCommerce addresses (301), and the duplicate account page.
$rules = [];
foreach (['/panier/', '/commander/', '/categorie-produit/t-shirt/', '/categorie-produit/casquette-adulte/', '/categorie-produit/fightxlife/', '/categorie-produit/sweat-a-capuche/', '/etiquette-produit/fightxlife/'] as $from) {
    $rules[] = ['from' => $from, 'to' => '/e-shop/', 'code' => 301];
}
$rules[] = ['from' => '/produit/donation/', 'to' => '/donation/', 'code' => 301];
$rules[] = ['from' => '/cours/mon-compte/', 'to' => MEMBRE, 'code' => 301];
Database::setSetting(\Slate\Module\StudioBuilder\Runtime\StudioRedirects::SETTING, json_encode($rules, JSON_UNESCAPED_SLASHES), $tid);
// Site & SEO defaults, for a page that leaves its own empty: pages' own titles already end in "| Fightxlife", so the
// format stays the bare title; the description and share image are the home page's.
$home = Database::row("SELECT seo_json FROM studiobuilder_pages WHERE tenant_id = ? AND route_mode = 'homepage' AND status = 'published' LIMIT 1", [$tid]);
$homeSeo = $home ? (json_decode((string) $home['seo_json'], true) ?: []) : [];
Database::setSetting('studio_seo_title_format', '%title%', $tid);
Database::setSetting('studio_seo_description', (string) ($homeSeo['description'] ?? ''), $tid);
Database::setSetting('studio_seo_og_media_id', (string) ($homeSeo['og_image_media_id'] ?? ''), $tid);
Database::setSetting('studio_seo_og_image_url', '', $tid);
PageRoutes::forget($tid);
say("done: {$n} pages, " . count($templates) . ' templates, ' . count($rules) . ' redirects');
