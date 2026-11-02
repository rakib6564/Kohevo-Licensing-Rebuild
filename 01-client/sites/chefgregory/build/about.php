<?php
// Chef Gregory's redesign: About and Contact.
declare(strict_types=1);

// ── About ──────────────────────────────────────────────────────────────────────
$year = static fn(string $when, string $title): array => ROW([
    W(B('core.text', ['content' => $when, 'size' => 'inherit'], [], ['color' => EMBERLT, 'typography' => ['size' => '34px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.2']]), '18%'),
    W(B('core.text', ['content' => $title, 'size' => 'inherit'], [], ['color' => CREAM, 'typography' => ['size' => '24px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.3']]), '82%'),
], '24px', ['border' => ['bottom' => ['width' => '1px', 'style' => 'solid', 'color' => 'rgba(247,241,230,.18)']], 'padding' => ['top' => '22px', 'bottom' => '22px']], ['layout' => ['direction' => 'column', 'gap' => '6px']]);
$pillar = static fn(string $n, string $t): array => ROW([B('core.text', ['content' => $n, 'size' => 'inherit'], [], ['color' => EMBER, 'typography' => ['size' => '30px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.2']]), B('core.text', ['content' => $t, 'size' => 'inherit'], [], ['color' => INK, 'typography' => ['size' => '30px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.2']])], '18px', ['border' => ['bottom' => ['width' => '1px', 'style' => 'solid', 'color' => '#c9b99d']], 'padding' => ['top' => '14px', 'bottom' => '14px']], ['layout' => ['direction' => 'row']]);

build_page('about-us', 'About Us', 'page', 'standalone', [
    SEC('Hero', [ROW([
        W(VBOX([EYE('About the Chef', EMBERLT), H('Gregory S. Kalatsky', 'h1', '70px', '44px', CREAM), P('CIA-Trained Culinary Professional · 25+ Years of Excellence · Central Coast California', '#d9cdbd'), ROW([BTN('Book Your Tasting', '/contact/'), BTN('Explore Services', '/services/', 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], '20px'), '50%'),
        W(PIC('2022-04-cooking-class-salad-bowl-finished.jpg', 'Chef Gregory plating a salad at a cooking class', '420px', '240px'), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Biography', [ROW([
        W(VBOX([EYE('Full Biography'), H('A Quarter Century of Culinary Passion', 'h2', '50px', '36px')], '14px', [], ['classNames' => ['cg-sticky']]), '38%'),
        W(VBOX([
            PROSE('<p>Born with an innate love for food and an unwavering drive for perfection, <strong>Gregory S. Kalatsky</strong> has built one of California’s most respected culinary careers over nearly three decades. His journey began at the <strong>Culinary Institute of America</strong> in Hyde Park, New York — widely regarded as the finest culinary school in the United States — where he mastered classical technique, kitchen leadership, and the discipline that defines world-class cooking.</p><p>Gregory graduated from the CIA in 1995. In <strong>1997</strong>, he founded VVSI Gourmet Catering, which later became Chef Gregory’s Gourmet Catering, quickly earning a reputation for uncompromising quality, creative menus, and seamless event execution. In <strong>2020</strong>, he moved to California’s Central Coast, drawn by the richness of the land, the quality of local producers, and the growing appetite for sophisticated, farm-to-table dining.</p><p>Over the decades, Gregory has evolved into a multi-dimensional culinary entrepreneur. Beyond his flagship catering business, he has opened Chef Gregory’s Barbecue Smokehouse Emporium, competed in regional and national BBQ championships, developed proprietary food products for the e-commerce market, and offered culinary instruction to aspiring home cooks and professionals alike.</p><p>What sets Chef Gregory apart is not merely technical skill — it is his obsessive attention to preparation. Every event he accepts receives <strong>30 to 50 hours of dedicated pre-event preparation</strong>, from sourcing the finest seasonal ingredients to conducting thorough pre-event tastings with clients.</p>'),
            VBOX([P('“The event is the performance. The weeks before the event are the rehearsal — and I never shortchange the rehearsal.”', INK, '28px', 'left', DISPLAY)], '0px', ['border' => ['left' => ['width' => '3px', 'style' => 'solid', 'color' => EMBER]], 'padding' => ['left' => '24px'], 'margin' => ['top' => '10px', 'bottom' => '10px']]),
            PROSE('<p>Today, Chef Gregory’s Gourmet Catering serves clients across San Luis Obispo County, Paso Robles, Santa Barbara, and beyond, consistently earning five-star reviews and the loyalty of clients who return event after event.</p>'),
        ], '14px'), '56%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '28px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT(), WRAP(), '60px'),
    SEC('Philosophy', [ROW([
        W(VBOX([EYE('Philosophy & Approach'), VBOX([$pillar('1', 'Quality Ingredients'), $pillar('2', 'Classical Technique'), $pillar('3', 'Genuine Hospitality')])], '14px'), '42%'),
        W(VBOX([
            P('Chef Gregory believes that the finest dining experiences arise when exceptional food meets warmth of service — and that every guest, whether at a 300-person wedding or an intimate dinner for 12, deserves to feel as though the meal was prepared especially for them.'),
            P('He sources ingredients from local Central Coast farms, ranches, and fisheries whenever possible, building relationships with producers who share his commitment to quality. His menus change seasonally, and he actively encourages clients to embrace what is freshest and finest at the time of their event.'),
        ], '14px'), '50%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '28px']], ['justify' => 'between', 'align' => 'center'])], SAND, GUT('96px', '96px'), WRAP(), '60px'),
    SEC('Timeline', [
        VBOX([EYE('Career Journey', EMBERLT), H('Chef Gregory’s Timeline', 'h2', '52px', '38px', CREAM)], '14px', ['margin' => ['bottom' => '28px']]),
        $year('1994', 'ChefGregory.com was conceived'),
        $year('1995', 'Graduates from the Culinary Institute of America'),
        $year('1997', 'Founded VVSI Gourmet Catering, which later turned into Chef Gregory’s Gourmet Catering'),
        $year('1998', 'Started participating in the Memphis in May World Championship Barbecue competition, through 2025'),
        $year('1999', 'Started teaching at the Orange County School of Culinary Arts as a chef instructor'),
        $year('2000', 'Started the development of Chef Gregory’s Gourmet Me Now food product line'),
        $year('2001', 'Southwestern FLAIR seasoning was developed and started distributing'),
        $year('2005', 'Started Chef Gregory’s Gourmet Catering'),
        $year('2006', 'Added consulting to the Chef Gregory umbrella'),
        $year('2020', 'Picked up and moved to the Central Coast of California'),
        $year('2022', 'Started mass-producing over 20 flavors of peanut brittle'),
        $year('2024', 'Started Chef Gregory’s Barbecue Smokehouse Emporium'),
    ], INK, GUT(), WRAP()),
    SEC('From the kitchen', [ROW([
        W(PIC('2026-08-fb-img-1748360801989.jpg', 'Ribs and brisket on Chef Gregory’s smoker', '320px', '220px'), '34%'),
        W(PIC('2023-08-20230812-181431-scaled.jpg', 'Filo purses on a silver platter', '320px', '220px'), '40%'),
        W(PIC('2023-07-img-20210206-115404-239.jpg', 'A composed salad with berries', '320px', '220px'), '26%'),
    ], '12px', [], ['layout' => ['direction' => 'column', 'gap' => '12px']])], CREAM, ['padding' => ['top' => '12px', 'right' => '12px', 'bottom' => '12px', 'left' => '12px']], ['display' => 'flex', 'direction' => 'column'], '12px'),
    BOOK(),
], ['seo_title' => $CAP['/about-us/']['d']['title'], 'seo_desc' => $meta('/about-us/'), 'image' => '2022-04-cooking-class-salad-bowl-finished.jpg']);

// ── Contact ────────────────────────────────────────────────────────────────────
$fld = static fn(string $type, string $name, string $label, string $ph = '', bool $req = false, string $width = '50', string $options = ''): array => B('core.form_field', ['field_type' => $type, 'name' => $name, 'label' => $label, 'placeholder' => $ph, 'required' => $req, 'width' => $width] + ($options !== '' ? ['options' => $options] : []));
$detail = static fn(string $label, array $value): array => VBOX([EYE($label), $value], '4px', ['border' => ['bottom' => ['width' => '1px', 'style' => 'solid', 'color' => '#d9ccb5']], 'padding' => ['top' => '14px', 'bottom' => '14px']]);
$dlink = static fn(string $label, string $href): array => B('core.link', ['link' => L($label, $href), 'style' => 'plain'], [], ['color' => INK, 'typography' => ['size' => '20px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.3']], ['classNames' => ['cg-link']]);
$dtext = static fn(string $text): array => P($text, COCOA, '16px');

build_page('contact', 'Contact', 'page', 'standalone', [
    SEC('Hero', [VBOX([EYE('Get in Touch', EMBERLT), H('Book Chef Gregory', 'h1', '70px', '44px', CREAM), P('Start with a conversation. Tell us about your event and let’s craft something extraordinary together.', '#d9cdbd', '19px')], '18px', ['dimensions' => ['max_width' => '720px']])], INK, ['padding' => ['top' => '64px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Booking inquiry', [ROW([
        W(VBOX([
            EYE('Booking Inquiry'),
            H('Tell Us About Your Event', 'h2', '46px', '34px'),
            B('core.form', ['form_name' => 'Booking Inquiry', 'notify_email' => 'chef@chefgregory.com', 'submit_text' => 'Submit Booking Inquiry', 'submit_variant' => 'primary', 'success_message' => 'Thank you. We respond within 24 hours.'], [
                $fld('text', 'first_name', 'First name', 'Jane', true), $fld('text', 'last_name', 'Last name', 'Williamson', true),
                $fld('email', 'email', 'Email address', 'jane@example.com', true), $fld('tel', 'phone', 'Phone number', '(805) 555-0100'),
                $fld('select', 'event_type', 'Event type', '', false, '50', 'Select Event Type..., Wedding Reception, Rehearsal Dinner, Corporate Event, Winery Dinner, Bar / Bat Mitzvah, Holiday Party, Anniversary Dinner, Private Chef Experience, Cooking Class, Other'),
                $fld('date', 'event_date', 'Event date'),
                $fld('select', 'guests', 'Estimated guests', '', false, '50', 'Select Range..., Under 25, 25-50, 50-100, 100-200, 200-300, 300+'),
                $fld('text', 'venue', 'Event location or venue', 'Venue name or city'),
                $fld('textarea', 'details', 'Tell us more', 'Share any details about your vision, dietary requirements, or questions...', false, '100'),
            ], ['margin' => ['top' => '10px']], ['classNames' => ['cg-form']]),
            P('We respond within 24 hours · Complimentary pre-event tasting included', COCOA, '15px', 'left', ROMAN, 'italic'),
        ], '14px'), '60%'),
        W(VBOX([
            EYE('Contact Information'),
            H('Let’s Plan Your Event', 'h2', '36px', '30px'),
            P('Reach out directly or complete the booking inquiry form and Chef Gregory’s team will respond within 24 hours.', COCOA, '16px'),
            VBOX([
                $detail('Phone', $dlink(PHONE, 'tel:' . PHONE)),
                $detail('Email', $dlink('sales@gourmetmenow.com', 'mailto:sales@gourmetmenow.com')),
                $detail('Location', $dlink('6550 El Camino Real, Atascadero, CA 93422', 'https://maps.google.com/?q=6550+El+Camino+Real,+Atascadero,+CA+93422')),
                $detail('Service Areas', $dtext('Paso Robles · San Luis Obispo · Santa Barbara · Morro Bay · Cambria · Central Coast Region')),
                $detail('Response Time', $dtext('Within 24 hours, usually same day')),
            ]),
            VBOX([CHECK(), VBOX([H('Pre-Event Tasting', 'h3', '28px', '26px', CREAM), P('Every new catering client receives a complimentary pre-event tasting. This is our promise to you: you will never serve your guests food you haven’t personally approved.', '#d9cdbd', '16px')], '8px', ['padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '26px', 'left' => '24px']])], '0px', ['background' => ['color' => INK], 'margin' => ['top' => '16px']]),
        ], '12px'), '34%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '48px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
], ['seo_title' => $CAP['/contact/']['d']['title'], 'seo_desc' => str_replace('Call 877-357-2433', 'Call ' . PHONE, $meta('/contact/'))]);
