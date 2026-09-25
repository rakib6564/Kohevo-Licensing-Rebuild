<?php
/**
 * Slate — create a complete 5-page construction-company website.
 *
 * Creates/updates 5 published Content Builder pages (Home, About, Services,
 * Projects, Contact) for a fictional general contractor ("Ironclad
 * Construction Co."), wires up a header/footer nav menu linking them,
 * switches on the Small Business Kit theme, and sets site branding (name,
 * tagline, header CTA, phone/email/address).
 *
 * Safe to re-run: pages are matched and updated by slug rather than
 * duplicated, and the menu is matched by name.
 *
 * Run:  php bin/create-construction-site.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/create-construction-site.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';

if (!class_exists('ContentBuilderAPI')) {
    fwrite(STDERR, "Content Builder plugin isn't active.\n");
    exit(1);
}

ContentBuilderAPI::ensureSchema();

$sbkActive = class_exists('SBKitAPI');

/**
 * Build an internal site link. Slate can be deployed at the domain root or
 * in a subdirectory (e.g. this local install serves it at /slate) — root-
 * relative hrefs like "/p/about" break whenever it's the latter, so every
 * internal link is built from SLATE_URL instead.
 */
function path(string $p): string {
    return rtrim(SLATE_URL, '/') . $p;
}

/* ── Site branding ──────────────────────────────────────────── */
$siteName = 'Ironclad Construction Co.';
$tagline  = 'Building Tomorrow, Today.';
$phone    = '(555) 271-4400';
$phoneHref= 'tel:5552714400';
$email    = 'info@ironcladconstructionco.com';
$address  = "4820 Foundry Row\nBakersfield, CA 93301";

ContentBuilderAPI::setSiteSetting('site_name', $siteName);
ContentBuilderAPI::setSiteSetting('tagline', $tagline);
ContentBuilderAPI::setSiteSetting('header_cta_text', 'Get a Free Quote');
ContentBuilderAPI::setSiteSetting('header_cta_href', path('/p/contact'));

if ($sbkActive) {
    // SBK renders its own fixed header/footer via a content_footer hook, so
    // switch off the generic Theme header/footer to avoid a duplicate.
    ContentBuilderAPI::setSiteSetting('show_header', '0');
    ContentBuilderAPI::setSiteSetting('show_footer', '0');
    ContentBuilderAPI::setActiveTheme('marine-pro');

    Database::setSetting('small-business-kit.header_phone', $phone);
    Database::setSetting('small-business-kit.header_phone_href', $phoneHref);
    Database::setSetting('small-business-kit.header_email', $email);
    Database::setSetting('small-business-kit.footer_address', $address);
    Database::setSetting('small-business-kit.footer_about',
        'Full-service general contractor building quality homes and commercial spaces across Kern County since 1998.');
} else {
    ContentBuilderAPI::setSiteSetting('show_header', '1');
    ContentBuilderAPI::setSiteSetting('show_footer', '1');
    ContentBuilderAPI::setSiteSetting('footer_text', "$siteName · $phone · $email");
}

/* ── Placeholder photography (Lorem Picsum, seeded so it's stable) ───── */
function img(string $seed, int $w, int $h): string {
    return "https://picsum.photos/seed/{$seed}/{$w}/{$h}";
}

/* ── Page block layouts ───────────────────────────────────────── */

$homeBlocks = [
    ['type' => 'sb-hero', 'props' => [
        'align' => 'center', 'tall' => '1',
        'eyebrow' => 'General Contractor · Licensed Since 1998',
        'heading' => 'Built right,', 'accentLine' => 'the first time.',
        'lede' => "Ironclad Construction Co. delivers residential and commercial building projects across Kern County — on schedule, on budget, and built to last.",
        'image' => img('ironclad-home', 1600, 900),
        'btnText' => 'Get a Free Quote', 'btnHref' => path('/p/contact'),
        'btn2Text' => 'View Our Work', 'btn2Href' => path('/p/projects'),
    ]],
    ['type' => 'sb-feature-grid', 'props' => [
        'eyebrow' => 'What we do', 'heading' => 'Three ways we', 'accentLine' => 'bring it home.',
        'sub' => 'From ground-up builds to detailed renovations, our crews handle every phase in-house.',
        'cols' => '3', 'bg' => 'page',
        'items' => [
            ['icon' => 'shield', 'title' => 'Residential Construction', 'text' => 'Custom homes and additions built to your exact specifications, from foundation to final walkthrough.', 'linkText' => 'Learn more', 'linkHref' => path('/p/services')],
            ['icon' => 'bolt', 'title' => 'Commercial Buildouts', 'text' => 'Retail, office, and light-industrial spaces delivered on tight timelines without cutting corners.', 'linkText' => 'Learn more', 'linkHref' => path('/p/services')],
            ['icon' => 'check', 'title' => 'Renovations & Additions', 'text' => "Kitchen, bath, and whole-home remodels that respect your budget and your daily life.", 'linkText' => 'Learn more', 'linkHref' => path('/p/services')],
        ],
    ]],
    ['type' => 'sb-feature-grid', 'props' => [
        'eyebrow' => 'Why choose us', 'heading' => 'Twenty-five years of', 'accentLine' => 'doing it right.',
        'sub' => 'Every project ships with the same standard: licensed crews, clear communication, and a warranty that means something.',
        'cols' => '3', 'bg' => 'dark',
        'items' => [
            ['icon' => 'shield', 'title' => 'Licensed & Insured', 'text' => "Fully licensed general contractor with comprehensive liability and workers' comp coverage."],
            ['icon' => 'clock', 'title' => 'On-Time Delivery', 'text' => '94% of our projects finish on or ahead of the schedule we hand you at signing.'],
            ['icon' => 'check', 'title' => 'Transparent Pricing', 'text' => 'Detailed line-item bids with no change-order surprises.'],
            ['icon' => 'heart', 'title' => 'Safety-First Crews', 'text' => 'OSHA-trained teams and a zero-incident record over the last three years.'],
            ['icon' => 'star', 'title' => '5-Year Warranty', 'text' => 'Every build is backed by a structural and workmanship warranty.'],
            ['icon' => 'globe', 'title' => 'Locally Owned', 'text' => 'Based in Bakersfield, building across all of Kern County since 1998.'],
        ],
    ]],
    ['type' => 'sb-split', 'props' => [
        'eyebrow' => 'Our story', 'heading' => 'Three generations of', 'accentLine' => 'craftsmanship.',
        'body' => "Ironclad Construction Co. started in 1998 as a two-person framing crew. Today we're a 40-person team of carpenters, project managers, and estimators — but we still show up to every job the same way: on time, with a plan, and ready to build it right.\n\nWe believe a construction company should feel like a partner, not a vendor. That means straight answers, a single point of contact for your whole project, and a crew that treats your home or business like it's their own.",
        'image' => img('ironclad-about', 1200, 900), 'mediaSide' => 'right',
        'btnText' => 'Meet the Team', 'btnHref' => path('/p/about'), 'btnStyle' => 'dark', 'bg' => 'surface',
    ]],
    ['type' => 'sb-quote-grid', 'props' => [
        'eyebrow' => 'Word of mouth', 'heading' => 'What our clients', 'accentLine' => 'are saying.', 'sub' => '',
        'cols' => '3', 'bg' => 'page',
        'stats' => [
            ['big' => '25+', 'label' => 'Years in business', 'isText' => '0'],
            ['big' => '480+', 'label' => 'Projects completed', 'isText' => '0'],
            ['big' => '4.9★', 'label' => 'Average rating', 'isText' => '0'],
            ['big' => '68%', 'label' => 'Repeat & referral clients', 'isText' => '0'],
        ],
        'items' => [
            ['stars' => '5', 'quote' => 'Ironclad rebuilt our kitchen in six weeks, exactly as quoted. Communication was constant and the crew treated our house with real respect.', 'name' => 'Marisol Reyes', 'meta' => 'Kitchen Remodel, Bakersfield'],
            ['stars' => '5', 'quote' => 'We hired them for a 6,000 sq ft retail buildout with a hard opening date. They hit it. No drama, no excuses.', 'name' => 'Devon Park', 'meta' => 'Owner, Park Retail Group'],
            ['stars' => '5', 'quote' => "Our addition doubled our living space and you can't tell where the old house ends and the new one begins. That's craftsmanship.", 'name' => 'Tom & Ellen Whitfield', 'meta' => 'Home Addition, Oildale'],
        ],
    ]],
    ['type' => 'sb-cta-band', 'props' => [
        'heading' => 'Ready to build something great?',
        'text' => "Tell us about your project and we'll get back to you with a free, no-obligation estimate within two business days.",
        'btnText' => 'Request a Quote', 'btnHref' => path('/p/contact'),
    ]],
];

$aboutBlocks = [
    ['type' => 'sb-page-hero', 'props' => [
        'crumb1' => 'Home', 'crumb1Url' => path('/p/home'), 'crumb2' => 'About',
        'heading' => 'About Ironclad',
        'lede' => 'A local, licensed general contractor built on the idea that construction should be straightforward — for us and for you.',
        'image' => img('ironclad-about-hero', 1600, 600),
        'btnText' => 'Get a Free Quote', 'btnHref' => path('/p/contact'),
    ]],
    ['type' => 'sb-split', 'props' => [
        'eyebrow' => 'How we started', 'heading' => 'From a two-person', 'accentLine' => 'framing crew.',
        'body' => "Ironclad Construction Co. was founded in 1998 by Hank Delgado, a journeyman carpenter who wanted to build a company around a simple promise: say what you'll do, then do it.\n\nTwenty-five years later, that promise hasn't changed. We've grown into a full-service general contractor with in-house carpentry, electrical, and project management teams, but every job still gets a single point of contact and a detailed written schedule before the first nail goes in.",
        'image' => img('ironclad-founder', 1200, 900), 'mediaSide' => 'left',
        'btnText' => 'See Our Projects', 'btnHref' => path('/p/projects'), 'btnStyle' => 'dark', 'bg' => 'surface',
    ]],
    ['type' => 'sb-feature-grid', 'props' => [
        'eyebrow' => 'What we stand for', 'heading' => 'Our', 'accentLine' => 'values.',
        'sub' => 'The standards every Ironclad crew is held to, on every job, every time.',
        'cols' => '3', 'bg' => 'page',
        'items' => [
            ['icon' => 'shield', 'title' => 'Integrity', 'text' => "We quote it straight, and we don't pad estimates to pad our margin."],
            ['icon' => 'check', 'title' => 'Craftsmanship', 'text' => 'Every trade on our crew is licensed, trained, and proud of the work.'],
            ['icon' => 'heart', 'title' => 'Safety', 'text' => "A zero-incident record isn't luck — it's a daily standard on every site."],
            ['icon' => 'phone', 'title' => 'Communication', 'text' => "You'll always know who to call, and they'll always call you back."],
            ['icon' => 'clock', 'title' => 'Accountability', 'text' => "If we're behind schedule, you hear it from us before you have to ask."],
            ['icon' => 'globe', 'title' => 'Community', 'text' => "We're your neighbors — we live in Kern County too."],
        ],
    ]],
    ['type' => 'sb-quote-grid', 'props' => [
        'eyebrow' => 'By the numbers', 'heading' => '25 years,', 'accentLine' => 'one standard.', 'sub' => '',
        'cols' => '3', 'bg' => 'surface',
        'stats' => [
            ['big' => '1998', 'label' => 'Year founded', 'isText' => '1'],
            ['big' => '40+', 'label' => 'Team members', 'isText' => '0'],
            ['big' => '0', 'label' => 'Lost-time safety incidents (3-yr)', 'isText' => '0'],
            ['big' => '480+', 'label' => 'Projects completed', 'isText' => '0'],
        ],
        'items' => [
            ['stars' => '5', 'quote' => 'The Ironclad team felt like an extension of our own staff during our office renovation.', 'name' => 'Priya Nathan', 'meta' => 'Facilities Director, Summit Health'],
            ['stars' => '5', 'quote' => 'They caught a structural issue during permitting that would have cost us months later. That\'s the kind of team you want.', 'name' => 'Ray Ortega', 'meta' => 'Homeowner, Rosedale'],
            ['stars' => '5', 'quote' => 'Professional from the first walkthrough to the final punch list.', 'name' => 'Grace Lin', 'meta' => 'Property Manager, Lin Holdings'],
        ],
    ]],
    ['type' => 'sb-cta-band', 'props' => [
        'heading' => 'Want to work with a team that shows up?',
        'text' => "Reach out and we'll set up a walkthrough — no pressure, no obligation.",
        'btnText' => 'Contact Us', 'btnHref' => path('/p/contact'),
    ]],
];

$servicesBlocks = [
    ['type' => 'sb-page-hero', 'props' => [
        'crumb1' => 'Home', 'crumb1Url' => path('/p/home'), 'crumb2' => 'Services',
        'heading' => 'What We Build',
        'lede' => 'Full-service general contracting for homeowners, property owners, and businesses across Kern County.',
        'image' => img('ironclad-services-hero', 1600, 600),
        'btnText' => 'Get a Free Quote', 'btnHref' => path('/p/contact'),
    ]],
    ['type' => 'sb-feature-grid', 'props' => [
        'eyebrow' => 'Our services', 'heading' => 'Six ways we can', 'accentLine' => 'help you build.',
        'sub' => 'Every project starts with a detailed written estimate — no surprises once work begins.',
        'cols' => '3', 'bg' => 'page',
        'items' => [
            ['icon' => 'shield', 'title' => 'New Home Construction', 'text' => 'Custom single-family homes, from site work and foundation through final finishes.'],
            ['icon' => 'bolt', 'title' => 'Commercial Construction', 'text' => 'Retail, office, restaurant, and light-industrial buildouts delivered on schedule.'],
            ['icon' => 'check', 'title' => 'Remodels & Additions', 'text' => 'Room additions, second stories, and whole-home renovations.'],
            ['icon' => 'leaf', 'title' => 'Kitchen & Bath Renovation', 'text' => 'Full gut-and-rebuild kitchens and bathrooms, designed and built in-house.'],
            ['icon' => 'gauge', 'title' => 'Design-Build Services', 'text' => 'One contract, one team, from architectural drawings through construction.'],
            ['icon' => 'clock', 'title' => 'Project Management', 'text' => "Owner's-rep style project management for projects using your own architect or trades."],
        ],
    ]],
    ['type' => 'icon-grid', 'props' => [
        'pad' => 'normal', 'eyebrow' => 'How it works', 'heading' => 'Our four-step process',
        'bg' => 'tint', 'cols' => '4',
        'items' => [
            ['icon' => 'box', 'title' => '1. Consultation', 'text' => 'We walk the site, listen to your goals, and talk through budget and timeline.'],
            ['icon' => 'clock', 'title' => '2. Design & Permitting', 'text' => 'We finalize scope, pull permits, and lock in a written schedule.'],
            ['icon' => 'check', 'title' => '3. Construction', 'text' => 'Your dedicated crew and project manager build to plan, with weekly updates.'],
            ['icon' => 'star', 'title' => '4. Walkthrough & Warranty', 'text' => 'A final walkthrough, a punch list closed out fast, and a 5-year warranty.'],
        ],
    ]],
    ['type' => 'sb-cta-band', 'props' => [
        'heading' => 'Have a project in mind?',
        'text' => "Send us a few details and we'll follow up with next steps and a ballpark timeline.",
        'btnText' => 'Start Your Estimate', 'btnHref' => path('/p/contact'),
    ]],
];

$projectsBlocks = [
    ['type' => 'sb-page-hero', 'props' => [
        'crumb1' => 'Home', 'crumb1Url' => path('/p/home'), 'crumb2' => 'Projects',
        'heading' => 'Featured Projects',
        'lede' => "A sample of the homes and commercial spaces we've built across Kern County.",
        'image' => img('ironclad-projects-hero', 1600, 600),
        'btnText' => 'Get a Free Quote', 'btnHref' => path('/p/contact'),
    ]],
    ['type' => 'image-grid', 'props' => [
        'pad' => 'normal', 'eyebrow' => 'Recent work', 'heading' => 'A few of our favorite builds',
        'bg' => 'tint', 'cols' => '3',
        'items' => [
            ['image' => img('ironclad-proj-1', 800, 600), 'title' => 'Riverside Family Residence', 'text' => '4-bedroom custom home, 2,800 sq ft, completed 2024.', 'href' => ''],
            ['image' => img('ironclad-proj-2', 800, 600), 'title' => 'Downtown Retail Buildout', 'text' => '6,000 sq ft ground-up retail space for a regional grocer.', 'href' => ''],
            ['image' => img('ironclad-proj-3', 800, 600), 'title' => 'Hilltop Modern Addition', 'text' => '900 sq ft second-story addition with a full primary suite.', 'href' => ''],
            ['image' => img('ironclad-proj-4', 800, 600), 'title' => 'Maple Street Duplex', 'text' => 'New-construction duplex, two 3-bed units, completed 2023.', 'href' => ''],
            ['image' => img('ironclad-proj-5', 800, 600), 'title' => 'Oakview Kitchen Remodel', 'text' => 'Full gut renovation with structural wall removal.', 'href' => ''],
            ['image' => img('ironclad-proj-6', 800, 600), 'title' => 'Summit Corporate Office', 'text' => '12,000 sq ft office buildout with a 10-week schedule.', 'href' => ''],
        ],
    ]],
    ['type' => 'sb-quote-grid', 'props' => [
        'eyebrow' => 'Client feedback', 'heading' => 'Straight from the', 'accentLine' => 'job site.', 'sub' => '',
        'cols' => '3', 'bg' => 'page', 'stats' => [],
        'items' => [
            ['stars' => '5', 'quote' => 'They finished our duplex two weeks early and the finish work is immaculate.', 'name' => 'Sam & Julia Torres', 'meta' => 'Maple Street Duplex'],
            ['stars' => '5', 'quote' => "Best commercial GC we've worked with in ten years of retail buildouts.", 'name' => 'Nina Alvarez', 'meta' => 'Regional Facilities Manager'],
            ['stars' => '5', 'quote' => "The addition looks like it was always part of the house. Couldn't be happier.", 'name' => 'Robert Kim', 'meta' => 'Hilltop Addition'],
        ],
    ]],
    ['type' => 'sb-cta-band', 'props' => [
        'heading' => 'Like what you see?',
        'text' => "Let's talk about your project and add it to this list.",
        'btnText' => 'Start a Conversation', 'btnHref' => path('/p/contact'),
    ]],
];

$contactBlocks = [
    ['type' => 'sb-page-hero', 'props' => [
        'crumb1' => 'Home', 'crumb1Url' => path('/p/home'), 'crumb2' => 'Contact',
        'heading' => "Let's Build Something",
        'lede' => 'Call, email, or send a message below — we typically reply within one business day.',
        'image' => img('ironclad-contact-hero', 1600, 500),
        'btnText' => '', 'btnHref' => '',
    ]],
    ['type' => 'sb-contact-grid', 'props' => [
        'eyebrow' => 'How to reach us', 'heading' => 'Three ways to', 'accentLine' => 'get in touch.',
        'bg' => 'page',
        'items' => [
            ['icon' => 'phone', 'label' => 'Phone', 'value' => $phone, 'href' => $phoneHref],
            ['icon' => 'mail', 'label' => 'Email', 'value' => $email, 'href' => 'mailto:' . $email],
            ['icon' => 'pin', 'label' => 'Office', 'value' => $address, 'href' => ''],
        ],
    ]],
    ['type' => 'sb-split', 'props' => [
        'eyebrow' => 'Service area', 'heading' => 'Where we', 'accentLine' => 'work.',
        'body' => "We build throughout Kern County, including Bakersfield, Oildale, Rosedale, Shafter, and Tehachapi.\n\nOutside that area? Reach out anyway — for larger commercial projects we regularly travel across the Central Valley.",
        'image' => img('ironclad-service-area', 1200, 900), 'mediaSide' => 'right',
        'btnText' => '', 'btnHref' => '', 'btnStyle' => 'dark', 'bg' => 'surface',
    ]],
    ['type' => 'sb-cta-band', 'props' => [
        'heading' => 'Prefer to talk it through?',
        'text' => "Give us a call and we'll walk you through next steps.",
        'btnText' => 'Call ' . $phone, 'btnHref' => $phoneHref,
    ]],
];

/* ── Create / update each page (matched + replaced by slug) ─────────── */
$pages = [
    'home'     => ['title' => 'Home',     'blocks' => $homeBlocks],
    'about'    => ['title' => 'About',    'blocks' => $aboutBlocks],
    'services' => ['title' => 'Services', 'blocks' => $servicesBlocks],
    'projects' => ['title' => 'Projects', 'blocks' => $projectsBlocks],
    'contact'  => ['title' => 'Contact',  'blocks' => $contactBlocks],
];

$adminId = null;
$adminRow = Database::row(
    "SELECT u.id FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE r.slug IN ('admin','owner','super_admin')
     ORDER BY u.id ASC LIMIT 1"
);
if (!$adminRow) {
    // Fallback: just take the earliest user if no role matched by slug.
    $adminRow = Database::row("SELECT id FROM users ORDER BY id ASC LIMIT 1");
}
if ($adminRow) $adminId = (int)$adminRow['id'];

$results = [];
foreach ($pages as $slug => $def) {
    $existing = ContentBuilderAPI::getPostBySlug('page', $slug);
    $data = [
        'type'   => 'page',
        'title'  => $def['title'],
        'slug'   => $slug,
        'status' => 'published',
        'layout' => $def['blocks'],
    ];
    if ($adminId) $data['author_id'] = $adminId;
    if ($existing) $data['id'] = (int)$existing['id'];

    $id = ContentBuilderAPI::savePost($data);
    // Keep draft_data in step so the visual editor doesn't show stale
    // "unpublished changes" the first time someone opens it there.
    ContentBuilderAPI::saveDraft($id, $def['blocks']);
    ContentBuilderAPI::publishDraft($id);

    $results[$slug] = $id;
    echo ($existing ? "Updated" : "Created") . " page '{$def['title']}' (id={$id}, slug={$slug})\n";
}

/* ── Header nav menu linking all 5 pages ─────────────────────────────── */
$menuItems = [
    ['label' => 'Home',     'url' => path('/p/home'),     'type' => 'page'],
    ['label' => 'About',    'url' => path('/p/about'),    'type' => 'page'],
    ['label' => 'Services', 'url' => path('/p/services'), 'type' => 'page'],
    ['label' => 'Projects', 'url' => path('/p/projects'), 'type' => 'page'],
    ['label' => 'Contact',  'url' => path('/p/contact'),  'type' => 'page'],
];

$existingMenu = ContentBuilderAPI::getMenuByLocation('header');
$menuData = ['name' => 'Main Navigation', 'location' => 'header', 'items' => $menuItems];
if ($existingMenu) $menuData['id'] = (int)$existingMenu['id'];
$menuId = ContentBuilderAPI::saveMenu($menuData);
echo ($existingMenu ? "Updated" : "Created") . " header menu (id={$menuId}) with " . count($menuItems) . " links\n";

echo "\nDone. Visit:\n";
foreach ($pages as $slug => $def) {
    echo "  " . rtrim(SLATE_URL, '/') . "/p/{$slug}\n";
}
