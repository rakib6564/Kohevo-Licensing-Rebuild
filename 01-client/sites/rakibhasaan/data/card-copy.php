<?php
/**
 * rakibhasaan.com — the Service items' card copy, edited to even lengths so each grid's cards line up.
 *
 * The original pages' copy varies from one line to a paragraph and a list per card; here each group keeps one length:
 * features about 30 words, steps about 15, highlights about 10. Facts come only from the original pages (their
 * features, steps, pricing and hub cards). Feature grids are four across, so each service has 4 or 8 feature cards.
 * services.php lays these over what it reads from pages.json, group by group.
 */

declare(strict_types=1);

$p = static fn(string $t): string => '<p>' . $t . '</p>';
$f = static fn(array $rows): array => array_map(static fn(array $r): array => ['title' => $r[0], 'text' => $p($r[1])], $rows);
$s = static fn(array $rows): array => array_map(static fn(array $r): array => ['title' => $r[0], 'text' => $r[1]], $rows);

return [
    'wordpress-websites' => [
        'features' => $f([
            ['Built for your industry', 'I design around how your customers decide: big photography for a villa, coverage and trust for a logistics firm, service areas and a short quote form for a cleaner. You approve the homepage first.'],
            ['A site you can edit yourself', 'Every site is built with Elementor Pro, the most widely used visual builder for WordPress. You change text, images and pages by clicking on them, and short videos show you how.'],
            ['Fast pages on every device', 'Slow sites lose visitors and rank lower. I convert images to WebP, keep plugins to what the site needs, load fonts efficiently and test on phones, tablets and desktops before launch.'],
            ['SEO from day one', 'Every page launches with a unique title, meta description and one clear H1. I set up Yoast SEO, a sitemap, clean URLs, structured data and alt text, then submit to Search Console.'],
            ['Forms, booking and tools', 'Contact forms, quote requests, booking enquiries, galleries, service area and team pages, and reviews, built with dependable plugins such as Gravity Forms and Crocoblock, or custom-built when nothing else fits.'],
            ['Security and a safe launch', 'I install security tools such as Wordfence, take backups and build major changes on a staging copy, so your live site stays up. On launch day I check every page, form and redirect.'],
            ['Words that sell the work', 'Copy that says what you do in the first line, answers the questions customers ask and ends with a clear next step, written with your search keywords in mind, never for a robot.'],
            ['Support after launch', 'You get every login, short video tutorials covering the edits you will actually make, and 30 days of support, so your team is never stuck when a price or photo needs changing.'],
        ]),
        'steps' => $s([
            ['Free audit and call', 'I review your current site, or your competitors\' sites, and we agree on pages, content and goals.'],
            ['Quote and plan', 'You get a fixed price and a dated plan; a typical site takes three to five weeks.'],
            ['Homepage design', 'You approve the look and direction on the homepage before I design any of the inner pages.'],
            ['Build on staging', 'The full site is built on a private copy you can click through and comment on.'],
            ['Launch and check', 'I move the site live, set up redirects and Search Console, and check everything on the domain.'],
            ['Handover', 'You get every login, video tutorials for your edits and 30 days of support after launch.'],
        ]),
        'highlights' => $s([
            ['New businesses', 'that need a professional site that looks established from the very first day'],
            ['Businesses with an outdated site', 'that\'s slow, hard to edit, or doesn\'t work well on phones and tablets'],
            ['Service businesses', 'that need visitors to call, book an appointment or request a quote online'],
            ['Owners who want control', 'who would rather change a price or a photo themselves than email a developer'],
        ]),
    ],
    'custom-development' => [
        'features' => $f([
            ['Custom WordPress plugins', 'Plugins built around your exact workflow, with an admin screen your team can use: booking tools, pricing calculators, custom post types and fields, member areas, API integrations and Stripe payments.'],
            ['Custom WordPress themes', 'Lightweight themes coded for your design, without the bulk of a multipurpose theme. A good fit for sites where speed matters most, or where a page builder isn\'t the right tool.'],
            ['Add-ons and integrations', 'Extensions for plugins you already use, such as WooCommerce, Elementor or Crocoblock, and connections to payment providers, CRMs and booking systems, tested on a staging copy before they reach your site.'],
            ['Licensing and updates', 'If you\'re building a product to sell, I can add licence keys, activation limits and update delivery, so customers receive updates inside WordPress and you can sell and support it properly.'],
        ]),
        'steps' => $s([
            ['Written specification', 'We agree in writing what it does, who uses it and what\'s out of scope, before any code.'],
            ['Staging and backups', 'Development and testing happen on a staging copy with backups, never on your live site.'],
            ['WordPress coding standards', 'Code follows WordPress conventions for security, escaping and data, so it works with other plugins.'],
            ['Version control', 'Code lives in Git with tagged releases, so every change is tracked and can be rolled back.'],
            ['Live verification', 'After deployment I test the new feature on the live site with real data, not a preview.'],
            ['Documentation', 'You get clear notes on how it works, how to manage it and how to update it.'],
        ]),
        'highlights' => $s([
            ['Business owners', 'with a process the website should handle for them, day in, day out'],
            ['Agencies and designers', 'who need a dependable WordPress developer for their client work'],
            ['Product builders', 'who want a plugin they can sell, license and keep reusing'],
        ]),
    ],
    'seo' => [
        'features' => $f([
            ['Crawling and indexing', 'I check robots settings, your XML sitemap, canonical and noindex tags and Search Console\'s coverage report, so we know which pages Google can reach and which it actually shows.'],
            ['Duplicates and thin content', 'I find pages that compete with each other, text copied across pages and pages too thin to rank, and recommend for each one whether to merge, rewrite or remove it.'],
            ['On-page SEO', 'Titles, meta descriptions, headings, image alt text and internal links, checked page by page against the keyword each page should target, so every page has one clear job in search.'],
            ['Search performance', 'Using Search Console data, I show which searches already bring impressions, which pages sit close to page one, and where the quickest wins are for the least amount of work.'],
            ['Speed and mobile', 'Core Web Vitals, image sizes, plugin load and how the site behaves on a phone, where oversized images and too many plugins hurt most, with the fixes that make the biggest difference.'],
            ['Local and business details', 'Name, address and phone kept consistent across your site, Google Business Profile and directories, plus service areas, opening hours and structured data that tell Google what you do and where.'],
            ['A ranked fix plan', 'Every issue is ranked by priority, from critical to minor, so you know what to fix first and what can wait. The full audit comes with a walkthrough call to go through it together.'],
            ['Fixes, done safely', 'Take the report to anyone, or I make the fixes: backed up and tested on a staging copy first, then checked on the live site and in Search Console, not just in a preview.'],
        ]),
        'highlights' => $s([
            ['The site is hidden from Google.', 'One WordPress setting, "Discourage search engines", is left on after launch and blocks every page.'],
            ['Pages aren\'t indexed.', 'Google has crawled them but chosen not to show them, often because of thin or duplicate content.'],
            ['Duplicate and missing titles.', 'Every page shares the same title, or has none at all, so Google can\'t tell the pages apart.'],
            ['Broken links and lost pages.', 'A redesign changed URLs without redirects, and the pages that used to rank now return errors.'],
            ['Slow pages.', 'Oversized images and too many plugins drag down Core Web Vitals, and phones feel it most of all.'],
            ['Wrong business details.', 'Opening hours, service areas or addresses differ between the site, Google Business Profile and directories.'],
            ['No structured data.', 'Google has to guess what the business is, where it works and what it offers its customers.'],
        ]),
    ],
    'apps' => [
        'steps' => $s([
            ['Discovery', 'We list who uses the app, what each person needs to do, and what can wait.'],
            ['Written scope', 'You get a short specification with screens, features, a fixed price per phase and dates.'],
            ['Clickable prototype', 'You try the main screens before any code is written, so changes are quick and cheap.'],
            ['Build in phases', 'The essential features ship first, and each phase is tested on a staging version you can use.'],
            ['Launch and verify', 'I deploy, test with real data and watch closely for errors in the first days after launch.'],
            ['Handover', 'Documentation, logins and code in version control, so you\'re never dependent on one person.'],
        ]),
        'highlights' => $s([
            ['Booking and reservation tools', 'time slots, seat holds, deposits and confirmations, all handled in one place'],
            ['Client and member portals', 'logins, documents, orders and messages, together in one secure place'],
            ['Internal tools', 'dashboards, job tracking, quote builders and admin panels that replace spreadsheets'],
            ['Payment flows', 'checkout, deposits and recurring subscriptions, taken securely with Stripe'],
            ['Mobile apps', 'companion apps for your customers or team, on iOS and Android, built cross-platform'],
        ]),
    ],
    'platforms' => [
        // The original's "which platform fits" rows are folded into each platform's card, so the grid is four even cards.
        'features' => $f([
            ['Shopify', 'Store setup and redesigns, product and collection pages, theme customisation, apps, payments and SEO for product pages. The best fit when your main goal is selling many products online.'],
            ['Wix', 'New sites and redesigns, service pages, booking setup, galleries, and fixes to the SEO settings Wix hides in its menus. A good fit for launching a simple service site quickly.'],
            ['Squarespace', 'Polished portfolio, restaurant and consulting sites, with menus, galleries, forms and blog setup. The best fit for photography, portfolios and image-led businesses that value simple editing.'],
            ['WordPress and more', 'WordPress is the best fit for growing a large site with custom features, content and search. On anything else, ask: I\'ll tell you honestly whether I can help, or who can.'],
        ]),
        'highlights' => $s([
            ['New sites and redesigns', 'for ecommerce, portfolio, restaurant, consulting and company websites'],
            ['Setup and configuration', 'domains, menus, navigation, galleries and service pages, done properly'],
            ['Fixes to live sites', 'indexing problems, page titles, opening hours, service areas and broken links'],
            ['Migrations', 'moving your content and keeping your search rankings when you switch platforms'],
            ['Ongoing management', 'regular updates and changes, for when you\'d rather not do them yourself'],
        ]),
    ],
    'branding' => [
        'features' => $f([
            ['Website copy and content', 'Copy that says what you do in the first line, answers the questions customers ask and ends with a clear next step, written with your search keywords in mind, never for a robot.'],
            ['Social media content', 'Social content keeps your business visible between website updates and sends warm visitors back to your site. I plan posts around your services and projects, so each one has a purpose.'],
            ['Video', 'Every website I build comes with video tutorials, and the same skills work for marketing: short explainer, slideshow and tutorial videos that show what you do faster than a page of text.'],
            ['Brand style guide', 'Your logo use, colours, fonts and tone of voice written down in one short guide, so your website, social posts and documents always look and sound like the same business.'],
        ]),
    ],
];
