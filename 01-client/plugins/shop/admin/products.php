<?php
/**
 * Shop — products (list + create/edit).
 *
 * Title, address (slug), SKU, price and sale price (VAT included), stock (tracked or not), images (one address per
 * line: a site path or an https:// URL, first is the main image), short and full description, categories and
 * Draft/Published. A product that was ever ordered is unpublished instead of deleted, so order history keeps it.
 *
 * Variants: attributes are written one per line (`Couleur: Blanc, Noir`); saving makes one variant per combination,
 * listed under them with its own SKU, price, sale price, stock and image, each of which may be left to the product's,
 * and an "On sale" switch for a combination the shop does not sell.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('shop.view');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_products', 'Products');
$currentNav = 'shop-products';
$tid = current_tenant_id();
$canEdit = Auth::can('shop.manage_products') || Auth::isSuperAdmin();
$self = plugin_url('shop', 'admin/products.php');

$flash  = null;
$errors = [];
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isNew  = !empty($_GET['new']) && $canEdit;
$editing = $editId > 0 ? ShopStoreAPI::productRow($editId) : null;
if ($editId > 0 && !$editing) { http_response_code(404); $editId = 0; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        $flash = ['type' => 'error', 'msg' => __('shop_err_no_permission', 'You do not have permission to change products.')];
    } elseif (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $res = ShopStoreAPI::saveProduct($_POST, $id);
            if ($res['ok']) {
                AuditLog::record($id > 0 ? 'shop.product_updated' : 'shop.product_created', (string) $res['id']);
                header('Location: ' . $self . '?edit=' . (int) $res['id'] . '&saved=1');
                exit;
            }
            $errors = $res['errors'] ?? [];
            $flash = ['type' => 'error', 'msg' => $errors['_'] ?? __('shop_err_fix_fields', 'Please fix the highlighted fields.')];
            $editing = $id > 0 ? (ShopStoreAPI::productRow($id) ?: null) : null;
            $isNew = $id === 0;
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $what = ShopStoreAPI::deleteProduct($id);
            AuditLog::record('shop.product_' . $what, (string) $id);
            $_SESSION['shop_flash'] = $what === 'deleted'
                ? ['type' => 'success', 'msg' => __('shop_product_deleted', 'Product deleted.')]
                : ['type' => 'warning', 'msg' => __('shop_product_unpublished', 'This product has orders, so it was set to Draft instead of deleted.')];
            header('Location: ' . $self);
            exit;
        }
    }
}
if (!empty($_GET['saved'])) $flash = ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')];
if (!$flash && !empty($_SESSION['shop_flash'])) { $flash = $_SESSION['shop_flash']; unset($_SESSION['shop_flash']); }

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/orders.php')],
    ['label' => __('shop_products', 'Products')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (($isNew || $editing) && $canEdit):
    $posted = $_SERVER['REQUEST_METHOD'] === 'POST' && $errors;
    $src = $posted ? $_POST : null;
    $p = $editing ?: ['id' => 0, 'title' => '', 'slug' => '', 'sku' => '', 'price_cents' => 0, 'sale_price_cents' => null, 'currency' => ShopStoreAPI::currency(),
        'short_description' => '', 'description' => '', 'images_json' => '[]', 'attributes_json' => null, 'stock_qty' => null, 'status' => 'draft', 'sort_order' => 0];
    $v = static function (string $key, string $fromRow) use ($src): string { return e($src !== null ? (string) ($src[$key] ?? '') : $fromRow); };
    $fmt = static fn($c): string => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, '.', '');
    $tracked = $src !== null ? !empty($src['track_stock']) : $p['stock_qty'] !== null;
    $selectedCats = $src !== null ? array_map('intval', (array) ($src['categories'] ?? [])) : array_column(ShopStoreAPI::categoriesOf([(int) $p['id']])[(int) $p['id']] ?? [], 'id');
    $err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field-error" role="alert">' . e($errors[$k]) . '</div>' : '';
    $publicUrl = $editing ? rtrim((string) SLATE_URL, '/') . ShopStoreAPI::productPath((string) $editing['slug']) : '';
    $attributes = ShopStoreAPI::attributes($p);
    $variantRows = $editing && $attributes !== [] ? ShopStoreAPI::variantRows((int) $editing['id']) : [];
    $postedVariants = $src !== null && is_array($src['variants'] ?? null) ? $src['variants'] : [];
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => __('shop_new_product', 'New product')]); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">

        <?php slate_edit_backlink(['back_href' => $self, 'back_label' => __('shop_all_products', 'All products')]); ?>

        <?php slate_edit_hero([
            'icon'     => 'tag',
            'initials' => $editing ? mb_strtoupper(mb_substr((string) $editing['title'], 0, 2)) : '–',
            'title'    => $editing ? (string) $editing['title'] : __('shop_new_product', 'New product'),
            'ref'      => $editing ? (int) $editing['id'] : null,
            'stats'    => $editing ? [
                [__('shop_price', 'Price'), e(ShopStoreAPI::money(ShopStoreAPI::effectivePrice($editing), (string) $editing['currency']))],
                [__('shop_stock', 'Stock'), $editing['stock_qty'] === null ? e(__('shop_not_tracked', 'Not tracked')) : (int) $editing['stock_qty']],
                [__('shop_status', 'Status'), $editing['status'] === 'published' ? '<a href="' . e($publicUrl) . '" target="_blank" rel="noopener">' . e(__('shop_published', 'Published')) . ' ↗</a>' : e(__('shop_draft', 'Draft'))],
            ] : [],
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'tag', 'eyebrow' => __('shop_general', 'General'), 'title' => __('shop_product_details', 'Product details')]); ?>
            <div class="field">
                <label class="field-label" for="title"><?= __('shop_title', 'Title') ?> <span class="field-required">*</span></label>
                <input type="text" id="title" name="title" required maxlength="200" value="<?= $v('title', (string) $p['title']) ?>">
                <?= $err('title') ?>
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="slug"><?= __('shop_slug', 'Address') ?></label>
                    <input type="text" id="slug" name="slug" maxlength="190" value="<?= $v('slug', (string) $p['slug']) ?>" placeholder="<?= e(__('shop_slug_auto', 'Made from the title')) ?>">
                    <div class="field-hint">/<?= e(ShopStoreAPI::productPrefix()) ?>/…</div>
                    <?= $err('slug') ?>
                </div>
                <div class="field">
                    <label class="field-label" for="sku"><?= __('shop_sku', 'SKU') ?></label>
                    <input type="text" id="sku" name="sku" maxlength="64" value="<?= $v('sku', (string) ($p['sku'] ?? '')) ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="short_description"><?= __('shop_short_description', 'Short description') ?></label>
                <textarea id="short_description" name="short_description" rows="2" maxlength="1000"><?= $v('short_description', (string) ($p['short_description'] ?? '')) ?></textarea>
            </div>
            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="description"><?= __('shop_description', 'Description') ?></label>
                <textarea id="description" name="description" rows="8"><?= $v('description', (string) ($p['description'] ?? '')) ?></textarea>
                <div class="field-hint"><?= __('shop_description_hint', 'Plain text; leave an empty line between paragraphs.') ?></div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'credit-card', 'eyebrow' => __('shop_pricing', 'Pricing'), 'title' => __('shop_price_stock', 'Price & stock')]); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="price"><?= __('shop_price', 'Price') ?> <span class="text-muted text-xs">(<?= e((string) $p['currency']) ?>, <?= __('shop_incl_vat', 'incl. VAT') ?>)</span> <span class="field-required">*</span></label>
                    <input type="text" inputmode="decimal" id="price" name="price" required value="<?= $v('price', $fmt($p['price_cents'])) ?>" placeholder="24.90">
                    <?= $err('price') ?>
                </div>
                <div class="field">
                    <label class="field-label" for="sale_price"><?= __('shop_sale_price', 'Sale price') ?></label>
                    <input type="text" inputmode="decimal" id="sale_price" name="sale_price" value="<?= $v('sale_price', $fmt($p['sale_price_cents'])) ?>">
                    <div class="field-hint"><?= __('shop_sale_hint', 'Leave empty when the product is not on sale.') ?></div>
                    <?= $err('sale_price') ?>
                </div>
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-check"><input type="checkbox" name="track_stock" value="1" <?= $tracked ? 'checked' : '' ?> data-shop-track> <?= __('shop_track_stock', 'Track stock') ?></label>
                    <?php if ($attributes !== []): ?><div class="field-hint"><?= __('shop_stock_by_variant', 'This product has variants: each variant keeps its own stock below.') ?></div><?php endif; ?>
                </div>
                <div class="field" data-shop-stock style="<?= $tracked ? '' : 'display:none;' ?>">
                    <label class="field-label" for="stock_qty"><?= __('shop_in_stock_qty', 'In stock') ?></label>
                    <input type="number" id="stock_qty" name="stock_qty" min="0" step="1" value="<?= $v('stock_qty', $p['stock_qty'] === null ? '0' : (string) (int) $p['stock_qty']) ?>">
                    <?= $err('stock_qty') ?>
                </div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'layers', 'eyebrow' => __('shop_variants', 'Variants'), 'title' => __('shop_options_variants', 'Options and variants')]); ?>
            <div class="field">
                <label class="field-label" for="attributes"><?= __('shop_attributes', 'Attributes') ?></label>
                <textarea id="attributes" name="attributes" rows="3" placeholder="<?= e(__('shop_attributes_placeholder', "Couleur: Blanc, Noir\nTaille: S, M, L")) ?>"><?= $v('attributes', ShopStoreAPI::attributesText($attributes)) ?></textarea>
                <div class="field-hint"><?= __('shop_attributes_hint', 'One per line: the name, a colon, then the values separated by commas. Leave empty for a product without options. Saving makes one variant per combination.') ?></div>
                <?= $err('attributes') ?>
            </div>
            <?php if ($variantRows): ?>
                <?= $err('variants') ?>
                <div class="table-wrap">
                <table class="table shop-variants">
                    <thead><tr>
                        <th><?= __('shop_variant', 'Variant') ?></th><th><?= __('shop_sku', 'SKU') ?></th>
                        <th><?= __('shop_price', 'Price') ?></th><th><?= __('shop_sale_price', 'Sale price') ?></th>
                        <th><?= __('shop_stock', 'Stock') ?></th><th><?= __('shop_image', 'Image') ?></th><th><?= __('shop_variant_sold', 'On sale') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($variantRows as $vr):
                        $key = (string) $vr['options_key'];
                        $f = is_array($postedVariants[$key] ?? null) ? $postedVariants[$key] : null;
                        $fv = static fn(string $k, string $fromRow): string => e($f !== null ? (string) ($f[$k] ?? '') : $fromRow);
                        $label = ShopStoreAPI::optionsLabel(ShopStoreAPI::variantValues($vr));
                        $name = 'variants[' . e($key) . ']';
                        $on = $f !== null ? !empty($f['enabled']) : (int) $vr['enabled'] === 1;
                    ?>
                        <tr>
                            <th scope="row"><?= e($label) ?></th>
                            <td><input type="text" name="<?= $name ?>[sku]" maxlength="64" value="<?= $fv('sku', (string) ($vr['sku'] ?? '')) ?>" aria-label="<?= e($label . ' — ' . __('shop_sku', 'SKU')) ?>"></td>
                            <td><input type="text" inputmode="decimal" name="<?= $name ?>[price]" value="<?= $fv('price', $fmt($vr['price_cents'])) ?>" placeholder="<?= e($fmt($p['price_cents'])) ?>" aria-label="<?= e($label . ' — ' . __('shop_price', 'Price')) ?>"></td>
                            <td><input type="text" inputmode="decimal" name="<?= $name ?>[sale_price]" value="<?= $fv('sale_price', $fmt($vr['sale_price_cents'])) ?>" aria-label="<?= e($label . ' — ' . __('shop_sale_price', 'Sale price')) ?>"></td>
                            <td><input type="number" min="0" step="1" name="<?= $name ?>[stock]" value="<?= $fv('stock', $vr['stock_qty'] === null ? '' : (string) (int) $vr['stock_qty']) ?>" placeholder="<?= e(__('shop_not_tracked', 'Not tracked')) ?>" aria-label="<?= e($label . ' — ' . __('shop_stock', 'Stock')) ?>"></td>
                            <td><input type="text" name="<?= $name ?>[image]" maxlength="500" value="<?= $fv('image', (string) ($vr['image'] ?? '')) ?>" aria-label="<?= e($label . ' — ' . __('shop_image', 'Image')) ?>"></td>
                            <td><label class="field-check"><input type="checkbox" name="<?= $name ?>[enabled]" value="1" <?= $on ? 'checked' : '' ?>> <span class="sr-only"><?= e($label) ?></span></label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="field-hint"><?= __('shop_variants_hint', 'Empty price: the product\'s price. Empty stock: not tracked. Untick "On sale" for a combination you do not sell.') ?></div>
            <?php endif; ?>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'image', 'eyebrow' => __('shop_media', 'Media'), 'title' => __('shop_images', 'Images')]); ?>
            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="images"><?= __('shop_image_urls', 'Image addresses') ?></label>
                <textarea id="images" name="images" rows="4" placeholder="/uploads/tshirt-front.jpg"><?= $v('images', implode("\n", ShopStoreAPI::images($p))) ?></textarea>
                <div class="field-hint"><?= __('shop_images_hint', 'One per line; the first is the main image. Copy an address from the Media library.') ?></div>
                <?= $err('images') ?>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'layers', 'eyebrow' => __('shop_organise', 'Organise'), 'title' => __('shop_categories_status', 'Categories & status')]); ?>
            <?php $cats = ShopStoreAPI::categories(); ?>
            <div class="field">
                <span class="field-label"><?= __('shop_categories', 'Categories') ?></span>
                <?php if (!$cats): ?>
                    <div class="field-hint"><a href="<?= e(plugin_url('shop', 'admin/categories.php')) ?>"><?= __('shop_add_categories', 'Add categories') ?></a></div>
                <?php endif; ?>
                <?php foreach ($cats as $c): ?>
                    <label class="field-check"><input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>" <?= in_array($c['id'], $selectedCats, true) ? 'checked' : '' ?>> <?= e($c['name']) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="status"><?= __('shop_status', 'Status') ?></label>
                    <?php $st = $src !== null ? (string) ($src['status'] ?? 'draft') : (string) $p['status']; ?>
                    <select id="status" name="status">
                        <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>><?= __('shop_draft', 'Draft') ?></option>
                        <option value="published" <?= $st === 'published' ? 'selected' : '' ?>><?= __('shop_published', 'Published') ?></option>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="sort_order"><?= __('shop_sort_order', 'Sort order') ?></label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= $v('sort_order', (string) (int) $p['sort_order']) ?>">
                </div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' => '<button type="submit" class="btn btn-primary">' . ($editing ? __('shop_save', 'Save changes') : __('shop_create_product', 'Create product')) . '</button>'
                . '<a href="' . e($self) . '" class="btn btn-ghost">' . __('shop_cancel', 'Cancel') . '</a>',
        ]); ?>
    </form>
<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>
<script>
(function () {
    var box = document.querySelector('[data-shop-track]'), field = document.querySelector('[data-shop-stock]');
    if (box && field) box.addEventListener('change', function () { field.style.display = box.checked ? '' : 'none'; });
})();
</script>

<?php else:
    $products = Database::rows("SELECT * FROM shop_products WHERE tenant_id = ? ORDER BY sort_order, created_at DESC, id DESC LIMIT 500", [$tid]);
    $catsOf = ShopStoreAPI::categoriesOf(array_column($products, 'id'));
?>
    <div class="page-header">
        <div><h1><?= __('shop_products', 'Products') ?></h1></div>
        <?php if ($canEdit): ?><a href="?new=1" class="btn btn-primary"><?= __('shop_new_product', 'New product') ?></a><?php endif; ?>
    </div>

    <?php if (!$products): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('shop_no_products', 'No products yet.') ?></div>
        <p class="text-sm"><?= __('shop_no_products_sub', 'Add your first product, then show it on your site with the Product Grid widget in Kohevo Studio.') ?></p>
    </div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($products as $p):
            $price = ShopStoreAPI::money(ShopStoreAPI::effectivePrice($p), (string) $p['currency']);
            $variantCount = ShopStoreAPI::attributes($p) !== [] ? count(ShopStoreAPI::variantRows((int) $p['id'], true)) : 0;
            $stock = $variantCount > 0
                ? sprintf(__('shop_n_variants', '%d variants'), $variantCount)
                : ($p['stock_qty'] === null ? __('shop_not_tracked', 'Not tracked') : (string) (int) $p['stock_qty']);
            $actions = '';
            if ($canEdit) {
                $actions = '<a href="?edit=' . (int) $p['id'] . '" class="btn btn-sm">' . __('shop_edit', 'Edit') . '</a> '
                    . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('shop_delete_confirm', 'Delete this product?')) . '\')">' . csrf_field()
                    . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
                    . '<button class="btn btn-sm btn-danger">' . __('shop_delete', 'Delete') . '</button></form>';
            }
            if ($p['status'] === 'published') {
                $actions .= ' <a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="' . e(rtrim((string) SLATE_URL, '/') . ShopStoreAPI::productPath((string) $p['slug'])) . '">' . __('shop_view', 'View') . ' ↗</a>';
            }
            slate_data_row([
                'avatar'       => mb_substr((string) $p['title'], 0, 1),
                'avatar_color' => $p['status'] === 'published' ? 'info' : 'muted',
                'title'        => (string) $p['title'],
                'meta'         => $price . ' · ' . __('shop_stock', 'Stock') . ': ' . $stock,
                'badge'        => $p['status'] === 'published' ? [__('shop_published', 'Published'), 'active'] : [__('shop_draft', 'Draft'), 'inactive'],
                'detail'       => [
                    __('shop_sku', 'SKU')               => (string) ($p['sku'] ?? '—'),
                    __('shop_price', 'Price')           => $price,
                    __('shop_stock', 'Stock')           => $stock,
                    __('shop_categories', 'Categories') => implode(', ', array_column($catsOf[(int) $p['id']] ?? [], 'name')) ?: '—',
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
