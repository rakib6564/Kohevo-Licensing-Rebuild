<?php
/** Storefront — menu browse with inline option panels and a live cart. */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
if (!RestaurantAPI::onlineOrderingEnabled()) {
    sf_closed('This restaurant is not taking online orders right now. Please check back later.');
}
$tid = current_tenant_id();

$cats  = Database::rows("SELECT * FROM restaurant_menu_categories WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
// Pre-order menus: shown first, with their note, while their ordering window is open; hidden otherwise.
$poHours = RestaurantHours::config();
$poNow   = RestaurantHours::now();
$poOpen  = [];
$regular = [];
foreach ($cats as $c) {
    if (empty($c['preorder'])) { $regular[] = $c; continue; }
    if (RestaurantPreorder::isOpen($c, $poHours, $poNow)) $poOpen[] = $c;
}
$cats = array_merge($poOpen, $regular);
$items = Database::rows("SELECT * FROM restaurant_items WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
// Items that need choosing before they go in the cart: those with modifier groups, and combos.
$modItemIds = array_map('intval', array_column(
    Database::rows("SELECT DISTINCT item_id FROM restaurant_item_modifier_groups WHERE tenant_id = ?
                     UNION SELECT id FROM restaurant_items WHERE tenant_id = ? AND is_combo = 1", [$tid, $tid]), 'item_id'));

// Modifier groups for the items that have them, so each can offer an inline panel.
$groupsByItem = [];
foreach ($modItemIds as $iid) $groupsByItem[$iid] = RestaurantAPI::getItemModifierGroups($iid);

// Group items by category id; collect uncategorised under 0.
$byCat = [];
foreach ($items as $it) { $byCat[(int)($it['category_id'] ?: 0)][] = $it; }

$api = e(sf_url('api'));

sf_header('', true);   // bare: hero renders full-width before the wrap
sf_hero();
echo '<div class="sf-wrap">';
if (isset($_GET['err']) && is_string($_GET['err']) && $_GET['err'] !== '') {   // a refused add without JavaScript
    echo '<div class="sf-flash sf-flash-err" style="margin-top:18px">' . sf_icon('alert', 15) . ' ' . e(mb_substr($_GET['err'], 0, 200)) . '</div>';
}

// ── MENU (full width; the cart lives in a slide-in drawer) ───
echo '<div class="sf-menu-col">';

$renderPanel = function (array $it, array $groups) use ($api) {
    echo '<div class="sf-opts" id="opts-' . (int)$it['id'] . '" hidden data-base="' . (int)$it['price_cents'] . '">';
    echo '<form class="sf-ajax" method="post" action="' . $api . '">' . csrf_field();
    echo '<input type="hidden" name="_action" value="add"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">';
    echo '<div class="sf-opts-name">' . e($it['name']) . '</div>';
    echo sf_choice_groups_html((int)$it['id'], $groups);
    echo '<textarea class="sf-opts-instr" name="notes" rows="2" maxlength="255" placeholder="Special instructions (optional)"></textarea>';
    echo '<div class="sf-opts-foot"><div class="sf-stepper">'
       . '<button type="button" data-qstep="-1" aria-label="Decrease">−</button>'
       . '<span data-qty>1</span>'
       . '<button type="button" data-qstep="1" aria-label="Increase">+</button>'
       . '<input type="hidden" name="qty" value="1" data-qty-input></div>';
    echo '<button class="sf-btn" type="submit">Add · <span data-line-total>' . sf_money((int)$it['price_cents']) . '</span></button>';
    echo '<button class="sf-opts-cancel" type="button" data-cancel-panel>Cancel</button>';
    echo '</div></form></div>';
};

$renderItem = function (array $it) use ($modItemIds, $groupsByItem, $api, $renderPanel) {
    $off     = !empty($it['is_86']);
    $hasMods = in_array((int)$it['id'], $modItemIds, true);
    echo '<div class="sf-item' . ($off ? ' is86' : '') . '">';
    echo '<div class="sf-ci-info"><div class="nm">' . e($it['name']) . '</div>';
    if (trim((string)$it['description']) !== '') echo '<div class="ds">' . e($it['description']) . '</div>';
    echo '<div class="pr" style="margin-top:7px">' . ($off ? '<span class="sf-soldout-tag">Sold out</span>' : sf_money((int)$it['price_cents'])) . '</div>';
    echo '</div><div class="sf-item-actions">';
    if (!$off) {
        if ($hasMods) {
            // JS intercepts via data-toggle-panel; href is the no-JS fallback.
            echo '<a class="sf-choose-btn" href="' . e(sf_url('item') . '?id=' . (int)$it['id']) . '" data-toggle-panel="opts-' . (int)$it['id'] . '">Choose</a>';
        } else {
            echo '<form class="sf-ajax" method="post" action="' . $api . '" style="margin:0">' . csrf_field()
               . '<input type="hidden" name="_action" value="add"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">'
               . '<button class="sf-add-btn" type="submit" aria-label="Add ' . e($it['name']) . '">+</button></form>';
        }
    }
    echo '</div></div>';
    if (!$off && $hasMods) $renderPanel($it, $groupsByItem[(int)$it['id']] ?? []);
};

$any = false;
foreach ($cats as $c) {
    $list = $byCat[(int)$c['id']] ?? [];
    if (!$list) continue;
    $any = true;
    echo '<div class="sf-sec"><h2>' . e($c['name']) . '</h2><div class="sf-sec-line"></div></div>';
    if (!empty($c['preorder'])) {
        echo '<div class="sf-flash sf-flash-info sf-po-note">' . sf_icon('note', 15) . ' ' . e(RestaurantPreorder::note($c, $poHours, $poNow)) . '</div>';
    }
    echo '<div class="sf-card">';
    foreach ($list as $it) $renderItem($it);
    echo '</div>';
}
if (!empty($byCat[0])) {
    $any = true;
    echo '<div class="sf-sec"><h2>More</h2><div class="sf-sec-line"></div></div><div class="sf-card">';
    foreach ($byCat[0] as $it) $renderItem($it);
    echo '</div>';
}
if (!$any) echo '<div class="sf-card sf-muted" style="text-align:center;padding:34px">The menu is being set up. Please check back soon.</div>';
echo '</div>'; // /sf-menu-col

echo '</div>'; // /sf-wrap

sf_footer();         // emits the slide-in cart drawer + interaction JS for us
