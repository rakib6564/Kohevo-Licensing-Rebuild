<?php
// Gourmet Me Now: what the business says about itself, in Chef Gregory's own words from the old site (data/pages.json,
// data/products.json, data/recipes.json). Held back on purpose (INVENTORY.md, section 9): the phone number in the old
// pickup text (on his do-not-publish list), the Templeton street address, and opening hours that disagree between the
// Contact page and the SEO settings. Nothing here is a new claim.
declare(strict_types=1);

const NAME = "Chef Gregory's Gourmet Me Now";
const SHORT = 'Gourmet Me Now';
const EMAIL = 'chef@chefgregory.com';
const BRITTLE_SLUG = 'peanut-brittles'; // the old shop's address for it

// Home and shop.
const BRITTLE_LINE = 'Our Old Fashioned Peanut Brittle is a special treat that is made using an old Southern family recipe from 1899. Each batch is hand-made with care and attention to detail, ensuring that every piece is perfectly crunchy, nutty, and delicious.';
const BRITTLE_INGREDIENTS = 'We start with the highest quality ingredients, including red-skinned peanuts, Madagascar vanilla, Himalayan salt, and fresh butter.';
const PRODUCTS_LINE = 'At Chef Gregory’s Ventures, he’s always innovating and creating new ways to make whimsical treats!';

// About.
const STORY = [
    'While I was working at the Orange County School of Culinary Arts as a Chef Instructor, while teaching one of my classes called Chill’n and Grill’n back in 1999. I put together a blend of spices that worked extremely well. My students and I both agreed that this was quite an amazing combination, from there I tweaked it and perfected it. Now it’s known as Southwestern Flair Seasonings.',
    'It comes in three different intensities; mild, spicy, and Oh my God someone dial 911!!! Not to mention there is a diabetic line, along with a regular version that is not low sodium.',
    'These seasonings are low-sodium, heart-smart & the highest quality ingredients. No fillers. You won’t see words like Salt, MSG to enhance flavors, or other big words like Sodium Dioxide. We use only clean, pure simple ingredients. This way you know that by using these products you are getting the best. “This is the way.”',
];
const CHEF_BIO = 'Chef Gregory Kalatsky is a Graduate of the Culinary Institute of America (CIA), Hyde Park, New York. Born and raised in Southern California. Through his extensive studies and training that have taken him around the world, he has been in many kitchens with some of the world’s best Chefs, having been trained by Heinz Winkler the youngest 3 Star Michelin Chef that also trained Wolfgang Puck, and by Certified Master Chefs and Pastry Chefs, Culinary Olympic team members and more.';
const CHEF_TRAINING = 'Chef Gregory Kalatsky was originally trained in the kitchens of Laguna Beach, CA and then off to The Culinary Institute of America (CIA) where he learned the Culinary Arts of Pastry and Savory Cuisines. After that his tutelage began in Europe by the same chef that trained Wolfgang Puck in a three-star Michelin restaurant/ hotel. His love of confections grew as his training continued throughout Europe into Germany, France, and Italy. Now we get to enjoy some of his wonderful creations here at Gourmet Me Now!!!';
const CATERING_LINE = 'Chef Gregory’s is one of the leading catering service providers in the Central Coast area.';
const OFFICE_HOURS = 'Office hours are Monday-Friday 9 AM- 4 PM.';

/**
 * The Recipes page as recipes: data/recipes.json is the old page's widgets in order, a divider between recipes. Each
 * recipe: title, intro, yield, ingredients, steps, notes, videos. Text is kept as written; the only repair is one
 * character the old database had mangled (`¨÷` for `⅓` in the burger yield: 3 patties of ⅓ lb make the 1 lb).
 *
 * @return array{intro: array{title: string, lines: list<string>, video: string}, recipes: list<array<string, mixed>>, tips: list<array{0: string, 1: string}>}
 */
function gmn_recipes(string $bundle): array
{
    $rows = json_decode((string) file_get_contents($bundle . '/data/recipes.json'), true, 512, JSON_THROW_ON_ERROR);
    $clean = static fn(string $s): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</br>', '<br>', '<br/>'], ' ', str_replace('¨÷', '⅓', $s))), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $segments = [[]];
    foreach ($rows as $r) {
        if ($r[0] === 'other:divider') { $segments[] = []; continue; }
        $segments[count($segments) - 1][] = $r;
    }
    $intro = ['title' => '', 'lines' => [], 'video' => ''];
    $recipes = [];
    $tips = [];
    // The first part is the seasoning's own introduction: every line in order, and the sizzle reel.
    foreach (array_shift($segments) ?? [] as $r) {
        if ($r[0] === 'video' && $intro['video'] === '') $intro['video'] = (string) $r[1];
        if ($r[0] !== 'heading' || ($t = $clean((string) $r[1])) === '' || $t === '.') continue;
        if ($intro['title'] === '') $intro['title'] = $t; else $intro['lines'][] = $t;
    }
    foreach ($segments as $seg) {
        $recipe = ['title' => '', 'intro' => [], 'yield' => '', 'ingredients' => [], 'steps' => [], 'notes' => [], 'videos' => []];
        foreach ($seg as $r) {
            [$kind, $value] = [$r[0], $r[1]];
            if ($kind === 'video' && is_string($value) && $value !== '') { $recipe['videos'][] = $value; continue; }
            if ($kind === 'list') { foreach ($value as $step) if (($t = $clean((string) $step)) !== '') $recipe['steps'][] = $t; continue; }
            if ($kind === 'accordion') { foreach ($value as [$q, $a]) $tips[] = [$clean((string) $q), $clean((string) $a)]; continue; }
            if ($kind !== 'heading') continue;
            $t = $clean((string) $value);
            if ($t === '' || $t === '.' || preg_match('/^Instructions:?$/i', $t)) continue;
            if ($recipe['title'] === '') { $recipe['title'] = $t; continue; }
            if (preg_match('/^(Makes|Serves|This recipe will make)\b/i', $t)) { $recipe['yield'] = $t; continue; }
            if (preg_match('/^(Chef|Cook|\*|“|click on next|CHECK OUR VIDEO)/iu', $t)) { $recipe['notes'][] = $t; continue; }
            // A long line that is not a measure (an amount first) introduces the recipe, or after the list adds to it.
            if (mb_strlen($t) > 90 && !preg_match('/^[\d¼½¾⅓⅔]/u', $t)) { $recipe[$recipe['ingredients'] === [] ? 'intro' : 'notes'][] = $t; continue; }
            $recipe['ingredients'][] = $t;
        }
        if ($recipe['title'] === 'Tips For Gourmet Me Now!!') continue;
        if ($recipe['title'] !== '') $recipes[] = $recipe;
    }
    return ['intro' => $intro, 'recipes' => $recipes, 'tips' => $tips];
}
