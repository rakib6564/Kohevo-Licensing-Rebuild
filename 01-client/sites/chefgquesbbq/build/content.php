<?php
// Everything the business has said about itself, in one place. Prices are the counter menu of 9-24-26; phone and hours
// follow the Google Business Profile (checked 2026-11-02), which disagreed with the old site.
// Dates checked against the client's timeline: CIA 1995, catering 1997, Memphis in May 1998, Central Coast 2020, Smokehouse 2024.
declare(strict_types=1);

const NAME = "Chef Gregory's Barbecue Smokehouse Emporium";
const TAGLINE = "Smoke them if you got 'em";
const STREET = '6550 El Camino Real';
const CITY = 'Atascadero, CA 93422';
const PHONE = '877-357-2433';
const ORDER = 'https://order.spoton.com/so-chef-gregorys-barbeque-19548/atascadero-ca/6658c438f62ff1003c24f5d3';
const MAPS = 'https://www.google.com/maps/search/?api=1&query=6550+El+Camino+Real,+Atascadero,+CA+93422';
const COUNTER1 = '/wp-content/uploads/2026/09/COUNTER-MENU-NEW-page-1-SIDE-9-24-26.jpg';
const COUNTER2 = '/wp-content/uploads/2026/09/COUNTER-MENU-NEW-page-2-SIDE-9-24-26.jpg';

const PLATE = 'Plates come with your choice of side. Add a second side for $2.00.';
const WHEN = 'When available';

$HOURS = [
    ['day' => 'Thursday', 'lunch' => '12:00 PM – 2:30 PM', 'dinner' => '5:00 PM – 8:00 PM'],
    ['day' => 'Friday', 'lunch' => '12:00 PM – 2:30 PM', 'dinner' => '5:00 PM – 8:00 PM'],
    ['day' => 'Saturday', 'lunch' => '12:00 PM – 2:00 PM', 'dinner' => '5:00 PM – 8:00 PM'],
    ['day' => 'Sunday – Wednesday', 'lunch' => 'Closed', 'dinner' => ''],
];
$FAQ = [
    ['question' => 'How do I know if you are open?', 'answer' => 'If online ordering is open, so are we. You can always look through the menu online, but orders only go through during our hours.'],
    ['question' => 'Should I preorder?', 'answer' => 'Yes. Preorder from Sunday through Tuesday to make sure you get some. We are open until we run out of food, and we do. We recommend preordering daily specials too.'],
    ['question' => 'What are Piglet, Porker and Whole Hog?', 'answer' => 'Our sizes. On the pork plates a Piglet is 4 oz, a Porker is 8 oz and a Whole Hog is 1 lb. On ribs it is 3, 6 or 12 ribs. On sides it is 8, 16 or 32 oz.'],
    ['question' => 'What does a plate come with?', 'answer' => 'Plates come with your choice of side, baked beans or coleslaw. Add a second side for $2.00.'],
    ['question' => 'Are the baked beans vegetarian?', 'answer' => 'No. Our BBQ baked beans are prepared with pork.'],
    ['question' => 'What payment do you take?', 'answer' => 'Cash, Venmo, Visa, Mastercard, American Express and Google Pay.'],
    ['question' => 'Do you cater?', 'answer' => 'Yes, and not just BBQ. From an intimate two-person sit-down dinner to a 400-person pickup party.'],
];

// slug, title, section, line on the menu, longer story, sizes [size, portion, price], image, availability, note
$MENU = [
    ['pork-sandwich', 'Chef G Ques Signature BBQ Pork Sandwich', 'Pork', 'Pulled pork topped with coleslaw and BBQ sauce.', '<p>Our award-winning barbecue pork sandwich: pulled pork on a bun, topped with coleslaw and barbecue sauce. The Piglet is small but mighty. Feeling extra hungry? Go for the Porker on a heartier bun.</p>', [['Piglet', '4 oz', '$15.97'], ['Porker', '8 oz', '$19.99']], 'gq-sandwich-plate.jpg', '', PLATE],
    ['porky-bowl', 'Porky Bowl', 'Pork', 'Pulled pork topped with coleslaw, beans and BBQ sauce, in a bowl.', '<p>Everything good about the sandwich, without the bun: pulled pork, coleslaw, BBQ baked beans and barbecue sauce, all in one bowl.</p>', [['Piglet', '4 oz', '$14.97'], ['Porker', '8 oz', '$18.99'], ['Whole Hog', '1 lb', '$30.97']], '', '', ''],
    ['baby-back-ribs', 'Baby Back Ribs Plate', 'Pork', 'With Chef G’s Southwestern Flair Mild Seasoning.', '<p>Smoked low and slow for nearly 3.5 hours to reach the right tenderness and flavor, using only fresh ingredients. This is award-winning barbecue at its finest.</p>', [['Piglet', '3 ribs', '$13.99'], ['Porker', '6 ribs', '$26.00'], ['Whole Hog', '12 ribs', '$50.40']], 'gq-ribs-rack.jpg', '', PLATE],
    ['smoked-pork-belly', 'Smoked Pork Belly Plate', 'Pork', 'Caramelized in Chef G’s BBQ sauce.', '<p>Slow-roasted and smoked pork belly, finished in our own barbecue sauce.</p>', [['Piglet', '4 oz', '$10.85'], ['Porker', '8 oz', '$18.00'], ['Whole Hog', '1 lb', '$30.50']], 'gq-belly-sliced.jpg', '', PLATE],

    ['smoked-chicken', 'Smoked Chicken Plate', 'Chicken', 'Smoked with Chef G’s Southwestern Flair Mild Seasoning.', '<p>Slow-roasted and smoked barbecue chicken. Our menu varies with what is fresh and available, and we never use frozen food. See the specials board for other daily chicken deals.</p>', [['1/2 Chicken', '', '$19.40'], ['Whole Chicken', '', '$29.30']], 'gq-chicken-plate.jpg', '', PLATE],

    ['tri-tip', 'Tri-Tip Plate', 'Beef', '', '', [['', '', '$17.95']], '', '', PLATE],
    ['wagyu-tri-tip', 'Wagyu Tri-Tip Plate', 'Beef', '', '', [['', '', '$27.95']], '', WHEN, PLATE],
    ['brisket', 'Brisket Plate', 'Beef', '', '', [['', '', '$18.97']], 'gq-sliced-beef.jpg', WHEN, PLATE],
    ['wagyu-beef-ribs', 'Wagyu Beef Ribs', 'Beef', '', '', [['Per pound', '', '$17.97']], '', WHEN, ''],

    ['classic-caesar', 'Classic Caesar Salad', 'Salads', 'With homemade dressing and garlic croutons.', '', [['', '', '$7.97']], '', '', ''],
    ['chicken-caesar', 'Chicken Caesar Salad', 'Salads', 'With homemade dressing and garlic croutons.', '', [['', '', '$10.95']], 'gq-caesar.jpg', '', ''],
    ['tri-tip-caesar', 'Tri-Tip Caesar Salad', 'Salads', 'With homemade dressing and garlic croutons.', '', [['', '', '$12.95']], '', '', ''],
    ['signature-salad', 'Chef G Ques Signature Salad', 'Salads', 'Spring mix with toasted pine nuts, dried cranberries and tomatoes in a ginger soy dressing.', '', [['', '', '$8.50']], 'gq-signature-salad.jpg', '', ''],

    ['bbq-baked-beans', 'BBQ Baked Beans', 'Sides', 'Prepared with pork. A meal in itself or the perfect side.', '<p>Slow-cooked, made from scratch and bursting with flavor and lots of barbecue. These are not vegetarian.</p>', [['Piglet', '8 oz', '$5.80'], ['Porker', '16 oz', '$10.00'], ['Whole Hog', '32 oz', '$19.00']], 'gq-beans.jpg', '', ''],
    ['coleslaw', 'Coleslaw', 'Sides', 'On top or on the side.', '', [['Piglet', '8 oz', '$4.75'], ['Porker', '16 oz', '$9.00'], ['Whole Hog', '32 oz', '$16.00']], '', '', ''],
    ['cornbread', 'Cornbread', 'Sides', 'Southern style with a Southwest twist: diced green chilis, mild not spicy.', '', [['Each', '', '$2.50']], '', '', ''],
    ['extra-bbq-sauce', 'Extra BBQ Sauce', 'Sides', '', '', [['Each', '', '$0.75']], '', '', ''],

    ['brown-butter-cake', 'Brown Butter Cake', 'Sweets', 'An individual serving, with deep, nutty brown butter in every bite.', '<p>Moist, golden and full of deep, nutty notes, with a balanced sweetness that stands on its own. Add Brown Butter Caramel Topping if you want to go further.</p>', [['Cake', '', '$7.95'], ['Caramel topping', '', '$2.00']], 'gq-cake.jpg', '', ''],
    ['chocolate-puddle-brownie', 'Triple Chocolate Puddle Brownie', 'Sweets', 'A fudgy base filled with silky ganache and topped with toasted walnuts.', '', [], 'gq-brownie.jpg', 'Ask at the counter', ''],

    ['soft-drinks', 'Soft Drinks', 'Drinks', '', '', [['Sodas', '', '$2.50'], ['Arizona & Teas', '', '$2.50'], ['Carbonated Flavored Waters', '', '$2.50'], ['Energy Drinks', '', '$3.50'], ['Bottled Water', '', '$1.55'], ['Atascadero’s Finest Water', '', '$0.50']], '', '', ''],
    ['beers-on-tap', 'Beers on Tap', 'Drinks', 'Local beers from the Central Coast on rotating taps.', '', [['', '12 oz', '$6.00'], ['', '16 oz', '$8.50'], ['', '20 oz', '$10.00'], ['DIPA', '12 oz', '$9.00']], '', '', ''],
    ['wine', 'Wine', 'Drinks', 'Rotating reds and white.', '', [['', '6 oz', '$7.00']], '', '', ''],

    ['clam-chowder', 'Clam Chowder', 'Soups', 'In a cup or a sourdough bread bowl.', '', [], 'gq-soup.jpg', 'Fall and winter', ''],
    ['santa-fe-black-bean', 'Santa Fe Black Bean', 'Soups', 'Piglet or Porker size.', '', [], '', 'Fall and winter', ''],

    ['ultimate-cheesy-mac', 'Ultimate Cheesy Mac', 'Specials', 'A crowd favorite.', '', [], '', 'On the specials board', ''],
    ['burnt-ends', 'Burnt Ends', 'Specials', 'When we have leftover brisket, we take the mac even further.', '', [], '', 'On the specials board', ''],
];
$SECTIONS = [
    ['Pork', 'pork', 'Pulled, smoked and caramelized.'], ['Chicken', 'chicken', 'See the specials board for other daily chicken deals.'], ['Beef', 'beef', 'See the specials board for daily deals.'],
    ['Salads', 'salads', 'With homemade dressings and garlic croutons.'], ['Sides', 'sides', ''], ['Sweets', 'sweets', ''], ['Drinks', 'drinks', ''], ['Soups', 'soups', 'We serve these during the fall and winter.'],
];
