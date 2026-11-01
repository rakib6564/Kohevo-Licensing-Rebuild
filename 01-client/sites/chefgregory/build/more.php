<?php
// Chef Gregory's redesign: Help Us Help You, Cooking Classes, Consulting, Holiday Services.
declare(strict_types=1);

// ── Help Us Help You: the questionnaire from the PDF, as a native form ─────────
$f = static fn(string $type, string $name, string $label, string $width = '50', bool $req = false, string $ph = '', string $opts = ''): array => B('core.form_field', ['field_type' => $type, 'name' => $name, 'label' => $label, 'width' => $width, 'required' => $req, 'placeholder' => $ph] + ($opts !== '' ? ['options' => $opts] : []));
$part = static fn(string $t): array => B('core.heading', ['text' => $t, 'level' => 'h3'], [], ['color' => INK, 'margin' => ['top' => '18px'], 'dimensions' => ['width' => '100%'], 'typography' => ['size' => '28px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.2']]);
build_page('help-us-help-you', 'Help Us Help You', 'page', 'standalone', [
    INHERO('Plan Your Event', 'Help Us Help You', 'We know planning a party is extremely personal, and in all our years of business no two events have ever been the same.'),
    CTOP('Check band', [CHECK()], []),
    SEC('Questionnaire', [ROW([
        W(VBOX([
            EYE('The Questionnaire'),
            H('Share Your Vision', 'h2', '46px', '34px'),
            P('This questionnaire will help you and your event coordinator get on the same page more quickly. If you are planning an event within the next year and would like a proposal so you can see the bottom line, it helps your event planner to have some background before contacting you.'),
            B('core.form', ['form_name' => 'Help Us Help You', 'notify_email' => 'chef@chefgregory.com', 'submit_text' => 'Send My Event Details', 'submit_variant' => 'primary', 'success_message' => 'Thank you for choosing Chef Gregory’s. We look forward to serving you and your guests.'], [
                $part('About You'),
                $f('text', 'name', 'Your name', '50', true), $f('text', 'company', 'Your company, if applicable'),
                $f('email', 'email', 'Email', '50', true), $f('tel', 'mobile', 'Mobile'),
                $f('tel', 'day_phone', 'Day or work number'), $f('tel', 'home_phone', 'Night or home number'),
                $f('text', 'address', 'Address of location', '100'),
                $f('text', 'city', 'City', '50'), $f('text', 'state', 'State', '25'), $f('text', 'zip', 'ZIP', '25'),
                $part('Your Event'),
                $f('date', 'event_date', 'Date of event'), $f('select', 'date_status', 'Is the date firm or tentative?', '50', false, '', 'Select..., Firm, Tentative'),
                $f('text', 'event_type', 'Type of event, or reason for the event'), $f('text', 'in_honor_of', 'Name(s) of the person(s) the event is in honor of'),
                $f('number', 'guests_min', 'Minimum number of guests expected'), $f('number', 'guests_max', 'Maximum number of guests expected'),
                $f('time', 'arrival', 'When guests will arrive'), $f('time', 'departure', 'When they will leave'),
                $f('text', 'food_hours', 'Hours of food service'), $f('text', 'beverage_hours', 'Hours of beverage service'),
                $f('text', 'theme', 'Theme', '100'),
                $part('Location'),
                $f('text', 'where', 'Where will the event take place?', '100', false, 'Private residence, clubhouse, park, or is a facility needed?'),
                $f('text', 'preferred_city', 'City or location preferred'), $f('text', 'facility_type', 'Type of facility desired', '50', false, 'Indoors, park, yacht, etc.'),
                $part('Menu'),
                $f('text', 'menu_type', 'Type of menu', '100', false, 'Lunch, dinner, heavy grazing, BBQ'),
                $f('textarea', 'hors_doeuvres', 'Hors d’oeuvres'), $f('textarea', 'entrees', 'Entrées'),
                $f('textarea', 'salads', 'Salads'), $f('textarea', 'sides', 'Sides'),
                $f('text', 'budget', 'Do you have a budget you are working with? If so, what is it?', '100'),
                $f('textarea', 'vision', 'What do you want you and your guests to leave feeling and thinking about the experience?', '100'),
            ], ['margin' => ['top' => '6px']], ['classNames' => ['cg-form']]),
        ], '14px'), '62%'),
        W(VBOX([
            VBOX([CHECK(), VBOX([H('No Menu Yet?', 'h2', '30px', '28px', CREAM), P('If you do not have menus at the time you fill this out, tell us your wish list or the type of foods and flavors you enjoy. We are truly happy to accommodate!', '#d9cdbd', '16px')], '10px', ['padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '26px', 'left' => '24px']])], '0px', ['background' => ['color' => INK], 'margin' => ['bottom' => '20px']]),
            P('Rather talk it through?', INK, '20px', 'left', DISPLAY),
            B('core.link', ['link' => L('Call ' . PHONE, 'tel:' . PHONE), 'style' => 'plain'], [], ['color' => EMBER, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '600']], ['classNames' => ['cg-link']]),
        ], '6px', [], ['classNames' => ['cg-sticky']]), '32%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '40px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT('80px', '96px'), WRAP(), '48px'),
], ['seo_title' => $CAP['/contact/help-us-help-you/']['d']['title'], 'seo_desc' => $meta('/contact/help-us-help-you/')]);

// ── Cooking classes ────────────────────────────────────────────────────────────
$ls = LEAFLIST($MAIN('/cooking-classes/')['d']);
$video = array_values(array_filter($ls, static fn(array $n): bool => $n['t'] === 'video'));
$classes = [];
for ($i = 2, $n = count($ls); $i < $n; $i++) {
    if ($ls[$i]['t'] !== 'heading') { continue; }
    $title = plain((string) $ls[$i]['text']);
    if (str_starts_with($title, 'Stuffed Baked Lobster')) { $classes[count($classes) - 1]['body'] .= ' ' . $title . '.'; continue; }
    $lead = '';
    if (preg_match('/^(.+?)\.\s*(.+)$/', $title, $m) && mb_strlen($title) > 30) { $title = $m[1]; $lead = rtrim($m[2]); }
    if (($ls[$i + 1]['t'] ?? '') === 'heading') { $lead = plain((string) $ls[++$i]['text']); }
    $body = ($ls[$i + 1]['t'] ?? '') === 'text-editor' ? plain((string) $ls[++$i]['html']) : '';
    $classes[] = ['title' => TT($title), 'lead' => $lead, 'body' => str_replace('~', ' · ', $body)];
}
say('    ' . count($classes) . ' classes');
$cards = array_map(static fn(array $c): array => VBOX([H($c['title'], 'h3', '26px', '25px'), $c['lead'] !== '' ? P($c['lead'], COCOA, '16px', 'left', ROMAN, 'italic') : null, P($c['body'], COCOA, '16px')], '8px', ['border' => ['top' => ['width' => '1px', 'style' => 'solid', 'color' => INK]], 'padding' => ['top' => '16px']]), $classes);
build_page('cooking-classes', 'Cooking Classes', 'page', 'standalone', [
    SEC('Hero', [ROW([
        W(VBOX([EYE('Learn with the Chef', EMBERLT), H('Cooking Classes', 'h1', '64px', '42px', CREAM), P('Classes are taught in the privacy of your own home. All classes are custom created and based on your skill level. I will break down the barriers of gourmet cuisine and teach you how to cook with a strong foundation.', '#d9cdbd'), ROW([BTN('Enroll Now', 'https://gourmetmenow.com/product/cooking-classes/'), BTN('Call ' . PHONE, 'tel:' . PHONE, 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], '18px'), '50%'),
        W($video ? (widget($video[0], null)[0] ?? PIC('2022-04-cooking-class-salad-bowl-finished.jpg', 'A cooking class', '380px', '240px')) : PIC('2022-04-cooking-class-salad-bowl-finished.jpg', 'A cooking class', '380px', '240px'), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Classes', [
        VBOX([EYE('Practice Makes Perfect', EMBER, 'center'), H('Our Classes', 'h2', '52px', '38px', INK, 'center'), P('After class is over you must practice what you have learned. Call me and we can talk about your next class.', COCOA, '18px', 'center')], '12px', ['margin' => ['bottom' => '44px']]),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none'], $cards, ['layout' => ['display' => 'grid', 'columns' => 3, 'gap' => '36px']], ['responsive' => ['tablet' => ['style' => ['layout' => ['columns' => 2]]], 'mobile' => ['style' => ['layout' => ['columns' => 1, 'gap' => '26px']]]]]),
    ], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
    BOOK(),
], ['seo_title' => $CAP['/cooking-classes/']['d']['title'], 'seo_desc' => $meta('/cooking-classes/'), 'image' => '2022-04-cooking-class-salad-bowl-finished.jpg']);

// ── Consulting ─────────────────────────────────────────────────────────────────
$ls = LEAFLIST($MAIN('/consulting/')['d']);
$long = array_values(array_filter(array_map(static fn(array $n): string => plain((string) ($n['t'] === 'text-editor' ? $n['html'] : ($n['text'] ?? ''))), $ls), static fn(string $t): bool => mb_strlen($t) > 120));
build_page('consulting', 'Consulting', 'page', 'standalone', [
    INHERO('For Restaurant Owners', 'Consulting Services', 'Operational guidance from a CIA-trained chef and restaurateur.'),
    CTOP('Check band', [CHECK()], []),
    SEC('Consulting', [ROW([
        W(VBOX([PIC('2022-04-cooking-class-chef-explaining.jpg', 'Chef Gregory S. Kalatsky teaching', '320px', '220px'), P('Gregory S. Kalatsky', INK, '24px', 'left', DISPLAY)], '12px'), '36%'),
        W(VBOX([...array_map(static fn(string $t): array => P($t), $long), ROW([BTN('Book Now', '/contact/')], '0px', ['margin' => ['top' => '10px']], [])], '16px'), '58%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
    BOOK(),
], ['seo_title' => $CAP['/consulting/']['d']['title'], 'seo_desc' => $meta('/consulting/'), 'image' => '2022-04-cooking-class-chef-explaining.jpg']);

// ── Holiday services ───────────────────────────────────────────────────────────
$hc = static fn(string $t, string $text): array => VBOX([H($t, 'h3', '28px', '26px'), P($text, COCOA, '16px')], '8px', ['border' => ['top' => ['width' => '1px', 'style' => 'solid', 'color' => INK]], 'padding' => ['top' => '16px']]);
build_page('holiday-services', 'Holiday Services', 'page', 'standalone', [
    SEC('Hero', [ROW([
        W(VBOX([EYE('Holiday Catering', EMBERLT), H('Holiday Services', 'h1', '64px', '42px', CREAM), P('We are offering a wide variety of catering services this Holiday Season. Please call us for further details and menus too.', '#d9cdbd'), ROW([BTN('Thanksgiving Menu', '/thanksgiving-menu/'), BTN('Call ' . PHONE, 'tel:' . PHONE, 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], '18px'), '50%'),
        W(PIC('thanksgiving-feast.jpg', 'A holiday feast with roast turkey', '440px', '240px'), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Holiday catering', [
        VBOX([EYE('Our Busiest Season', EMBER, 'center'), H('Holiday Catering Across the Central Coast', 'h2', '50px', '36px', INK, 'center'), P('The holidays are our busiest and most joyful season. From intimate family dinners to full corporate holiday parties, Chef Gregory’s Gourmet Catering brings restaurant-quality food and full-service hospitality to homes, offices, wineries, and event spaces across Paso Robles, San Luis Obispo, Santa Barbara, Atascadero, and the wider Central Coast. Every holiday menu is built around the season — local produce, roasts and hams, hearty sides, and desserts worth lingering over.', COCOA, '18px', 'center')], '12px', ['margin' => ['bottom' => '48px'], 'dimensions' => ['max_width' => '860px']]),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [
            $hc('Thanksgiving & Christmas Dinners', 'Whether you want a traditional turkey-and-all-the-trimmings Thanksgiving or an elegant Christmas dinner, we handle the cooking, delivery, setup, and cleanup so you can actually enjoy the day. Fully prepared holiday meals are available for pickup or delivery, and full-service in-home dinners come with staffing and plating. Menus are customizable for dietary needs, guest counts, and budgets.'),
            $hc('Corporate Holiday Parties', 'Company holiday parties are one of the things we do best. From plated dinners and elegant buffets to passed appetizers and carving stations, we design a menu and service style that fits your venue, headcount, and budget — with full bar service available. We cater office parties, client-appreciation events, and year-end celebrations throughout the Central Coast.'),
            $hc('How to Book Holiday Catering', 'Holiday dates book quickly, so we recommend reaching out early. Every new client is welcome to a complimentary pre-event tasting, and we respond to inquiries within 24 hours — usually the same day.'),
        ], ['layout' => ['display' => 'grid', 'columns' => 3, 'gap' => '36px']], ['responsive' => ['mobile' => ['style' => ['layout' => ['columns' => 1, 'gap' => '26px']]]]]),
        P('Quantities on servings per person should be as follows: 4–6 ounces on side dishes, 8 ounces on main course.', COCOA, '16px', 'center', ROMAN, 'italic'),
    ], CREAM, GUT('88px', '96px'), WRAP('column', '28px', ['align' => 'center']), '48px'),
    BOOK(),
], ['seo_title' => $CAP['/holiday-services/']['d']['title'], 'seo_desc' => $meta('/holiday-services/'), 'image' => 'thanksgiving-feast.jpg']);
