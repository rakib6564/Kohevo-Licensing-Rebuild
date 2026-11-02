<?php
/** Storefront — menu browse with inline option panels and a live cart. */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
if (!RestaurantAPI::onlineOrderingEnabled()) {
    sf_closed('This restaurant is not taking online orders right now. Please check back later.');
}
$tid = current_tenant_id();

$cats  = Database::rows("SELECT * FROM restaurant_menu_categories WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
$items = Database::rows("SELECT * FROM restaurant_items WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
$modItemIds = array_map('intval', array_column(
    Database::rows("SELECT DISTINCT item_id FROM restaurant_item_modifier_groups WHERE tenant_id = ?", [$tid]), 'item_id'));

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

// ── MENU (full width; the cart lives in a slide-in drawer) ───
echo '<div class="sf-menu-col">';

$renderPanel = function (array $it, array $groups) use ($api) {
    echo '<div class="sf-opts" id="opts-' . (int)$it['id'] . '" hidden data-base="' . (int)$it['price_cents'] . '">';
    echo '<form class="sf-ajax" method="post" action="' . $api . '">' . csrf_field();
    echo '<input type="hidden" name="_action" value="add"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">';
    echo '<div class="sf-opts-name">' . e($it['name']) . '</div>';
    foreach ($groups as $g) {
        $min = (int)$g['min_select']; $max = (int)$g['max_select']; $req = !empty($g['is_required']);
        $hint = $req ? ('required' . ($max > 1 ? ', up to ' . $max : '')) : ($max > 1 ? 'up to ' . $max : '');
        echo '<div class="sf-opts-grp" data-min="' . $min . '" data-max="' . $max . '" data-required="' . ($req ? '1' : '0') . '" data-name="' . e($g['name']) . '">';
        echo '<div class="sf-opts-glabel">' . e($g['name']) . ($req ? ' <span class="rq">*</span>' : '')
           . ($hint ? ' <span style="font-weight:500;text-transform:none;letter-spacing:0">(' . e($hint) . ')</span>' : '') . '</div>';
        echo '<div class="sf-opts-chips">';
        foreach ($g['modifiers'] as $m) {
            $d = (int)$m['price_delta_cents'];
            echo '<label class="sf-chip"><input type="checkbox" name="modifiers[]" value="' . (int)$m['id'] . '" data-delta="' . $d . '">'
               . '<span>' . e($m['name']) . '</span>'
               . ($d !== 0 ? '<em>' . ($d > 0 ? '+' : '') . sf_money($d) . '</em>' : '') . '</label>';
        }
        if (!$g['modifiers']) echo '<span class="sf-muted" style="font-size:12px">No options.</span>';
        echo '</div></div>';
    }
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
