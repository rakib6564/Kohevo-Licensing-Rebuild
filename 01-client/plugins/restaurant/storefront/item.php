<?php
/** Storefront — single item with modifier options, adds to cart. */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
if (!RestaurantAPI::onlineOrderingEnabled()) sf_closed('This restaurant is not taking online orders right now.');

$id   = (int)($_GET['id'] ?? 0);
$item = sf_item($id);
if (!$item) {
    http_response_code(404);
    sf_header('Not found');
    echo '<h1 class="sf-h1">Item not found</h1><p><a href="' . e(sf_url()) . '">← Back to the menu</a></p>';
    sf_footer(); exit;
}
$groups = RestaurantAPI::getItemModifierGroups($id);
$flash  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add') {
    if (!csrf_verify()) { $flash = 'Session expired — please try again.'; }
    elseif (!empty($item['is_86'])) { $flash = "Sorry, {$item['name']} is sold out right now."; }
    else {
        // The same rules the POS uses: modifier groups and combo slots, required / min / max.
        $choice = null;
        try {
            $choice = RestaurantAPI::validateLineChoices($id, (array)($_POST['modifiers'] ?? []), (array)($_POST['combo'] ?? []));
        } catch (\RuntimeException $e) {
            $flash = $e->getMessage();
        }
        if ($choice) {
            $qty  = max(1, (int)($_POST['qty'] ?? 1));
            $cart = sf_cart();
            $cart[] = ['item_id' => $id, 'qty' => $qty,
                       'modifiers' => array_map(fn($m) => (int)$m['id'], $choice['modifiers']),
                       'combo'     => array_map(fn($c) => (int)$c[1]['id'], $choice['components']),
                       'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255)];
            sf_cart_set($cart);
            header('Location: ' . sf_url('cart')); exit;
        }
    }
}

sf_header($item['name']);
echo '<p style="margin-top:18px"><a href="' . e(sf_url()) . '" class="sf-muted">← Menu</a></p>';
echo '<h1 class="sf-h1">' . e($item['name']) . '</h1>';
echo '<p class="sf-sub">' . sf_money((int)$item['price_cents']);
if (trim((string)$item['description']) !== '') echo ' · ' . e($item['description']);
echo '</p>';
if ($flash) echo '<div class="sf-flash sf-flash-err">' . e($flash) . '</div>';
?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="add">
    <?php foreach (RestaurantAPI::getComboSlots($id) as $sl):
        $type = (int)$sl['max_pick'] === 1 ? 'radio' : 'checkbox'; ?>
        <div class="sf-card">
            <div style="font-weight:700;margin-bottom:6px">
                <?= e($sl['label']) ?><?= (int)$sl['min_pick'] > 0 ? ' <span style="color:#b91c1c">*</span>' : '' ?>
                <span class="sf-muted" style="font-weight:400;font-size:.85rem">(<?= e((int)$sl['min_pick'] === (int)$sl['max_pick']
                    ? sprintf(__('restaurant_sf_choose_n', 'choose %d'), (int)$sl['max_pick'])
                    : sprintf(__('restaurant_sf_choose_range', 'choose %1$d to %2$d'), (int)$sl['min_pick'], (int)$sl['max_pick'])) ?>)</span>
            </div>
            <?php foreach ($sl['choices'] as $c): if (empty($c['is_active'])) continue; $off = !empty($c['is_86']); ?>
                <label class="sf-mopt"<?= $off ? ' style="opacity:.55"' : '' ?>>
                    <input type="<?= $type ?>" name="combo[]" value="<?= (int)$c['id'] ?>"<?= $off ? ' disabled' : '' ?>>
                    <span><?= e($c['name']) ?><?= $off ? ' · ' . e(__('restaurant_sf_sold_out', 'Sold out')) : '' ?></span>
                    <?php if ((int)$c['price_delta_cents'] !== 0): ?><span class="mp">+<?= sf_money((int)$c['price_delta_cents']) ?></span><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php foreach ($groups as $g):
        $single = (int)$g['max_select'] === 1;
        $type   = $single ? 'radio' : 'checkbox';
        $req    = !empty($g['is_required']);
    ?>
        <div class="sf-card">
            <div style="font-weight:700;margin-bottom:6px">
                <?= e($g['name']) ?><?= $req ? ' <span style="color:#b91c1c">*</span>' : '' ?>
                <span class="sf-muted" style="font-weight:400;font-size:.85rem">
                    <?php if ($req): ?>(required<?= (int)$g['max_select'] > 1 ? ', up to ' . (int)$g['max_select'] : '' ?>)<?php elseif ((int)$g['max_select'] > 1): ?>(up to <?= (int)$g['max_select'] ?>)<?php endif; ?>
                </span>
            </div>
            <?php foreach ($g['modifiers'] as $m): ?>
                <label class="sf-mopt">
                    <input type="<?= $type ?>" name="modifiers[]" value="<?= (int)$m['id'] ?>">
                    <span><?= e($m['name']) ?></span>
                    <?php if ((int)$m['price_delta_cents'] !== 0): ?><span class="mp"><?= sf_money((int)$m['price_delta_cents']) ?></span><?php endif; ?>
                </label>
            <?php endforeach; ?>
            <?php if (!$g['modifiers']): ?><span class="sf-muted">No options.</span><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="sf-card">
        <div class="sf-field"><label for="notes">Special instructions</label>
            <input type="text" id="notes" name="notes" maxlength="255" placeholder="e.g. no onions, extra sauce"></div>
        <div class="sf-row">
            <div class="sf-field" style="margin:0"><label for="qty">Qty</label>
                <input class="sf-qty" type="number" id="qty" name="qty" value="1" min="1" step="1"></div>
            <button class="sf-btn" type="submit" style="margin-left:auto"><?= !empty($item['is_86']) ? 'Sold out' : 'Add to cart' ?></button>
        </div>
    </div>
</form>
<?php
sf_footer();
