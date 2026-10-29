<?php
/**
 * Shop — product categories (list, add, rename, delete). Deleting a category leaves its products in place.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';

Auth::require();
Auth::requirePerm('shop.manage_products');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_categories', 'Categories');
$currentNav = 'shop-categories';
$self = plugin_url('shop', 'admin/categories.php');
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'save') {
            $res = ShopStoreAPI::saveCategory($_POST, $id);
            if ($res['ok']) {
                AuditLog::record('shop.category_saved', (string) $res['id']);
                header('Location: ' . $self);
                exit;
            }
            $flash = ['type' => 'error', 'msg' => (string) $res['error']];
        } elseif ($action === 'delete' && $id > 0) {
            ShopStoreAPI::deleteCategory($id);
            AuditLog::record('shop.category_deleted', (string) $id);
            header('Location: ' . $self);
            exit;
        }
    }
}

$categories = ShopStoreAPI::categories();
$editId = (int) ($_GET['edit'] ?? 0);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/orders.php')],
    ['label' => __('shop_categories', 'Categories')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header"><div><h1><?= __('shop_categories', 'Categories') ?></h1></div></div>

<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h2><?= __('shop_add_category', 'Add a category') ?></h2></div>
    <form method="post" class="field-row field-row-2" style="padding:0 1rem 1rem;align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <div class="field">
            <label class="field-label" for="cat-name"><?= __('shop_name', 'Name') ?></label>
            <input type="text" id="cat-name" name="name" required maxlength="120">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-primary"><?= __('shop_add', 'Add') ?></button>
        </div>
    </form>
</div>

<?php if (!$categories): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= __('shop_no_categories', 'No categories yet') ?></div>
        <p class="text-sm"><?= __('shop_no_categories_sub', 'Categories group your products; the Product Grid can show one category or let visitors filter.') ?></p></div></div>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead><tr><th><?= __('shop_name', 'Name') ?></th><th><?= __('shop_slug', 'Address') ?></th><th><?= __('shop_products', 'Products') ?></th><th><?= __('shop_sort_order', 'Sort order') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
            <?php if ($editId === $c['id']): ?>
            <tr><td colspan="5">
                <form method="post" class="field-row" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <label class="field"><span class="field-label"><?= __('shop_name', 'Name') ?></span><input type="text" name="name" required maxlength="120" value="<?= e($c['name']) ?>"></label>
                    <label class="field"><span class="field-label"><?= __('shop_slug', 'Address') ?></span><input type="text" name="slug" maxlength="120" value="<?= e($c['slug']) ?>"></label>
                    <label class="field"><span class="field-label"><?= __('shop_sort_order', 'Sort order') ?></span><input type="number" name="sort_order" value="<?= (int) $c['sort_order'] ?>"></label>
                    <button type="submit" class="btn btn-primary btn-sm"><?= __('shop_save', 'Save changes') ?></button>
                    <a class="btn btn-ghost btn-sm" href="<?= e($self) ?>"><?= __('shop_cancel', 'Cancel') ?></a>
                </form>
            </td></tr>
            <?php else: ?>
            <tr>
                <td><?= e($c['name']) ?></td>
                <td><code><?= e($c['slug']) ?></code></td>
                <td><?= (int) $c['product_count'] ?></td>
                <td><?= (int) $c['sort_order'] ?></td>
                <td style="text-align:right;white-space:nowrap;">
                    <a class="btn btn-sm" href="?edit=<?= (int) $c['id'] ?>"><?= __('shop_edit', 'Edit') ?></a>
                    <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= e(__('shop_delete_category_confirm', 'Delete this category? Its products are kept.')) ?>')">
                        <?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-sm btn-danger"><?= __('shop_delete', 'Delete') ?></button>
                    </form>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
