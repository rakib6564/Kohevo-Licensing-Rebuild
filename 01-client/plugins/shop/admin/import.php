<?php
/**
 * Shop — import products from WooCommerce.
 *
 * 1. Upload WooCommerce's product CSV (Products › Export) or a REST API JSON list. The file is kept in a private
 *    folder for this session only.
 * 2. Preview: what would be created, updated and skipped, with a warning per product where something does not carry
 *    over. Choose the file's units (read from the CSV header when it names them), whether images are copied into the
 *    Media library, and whether products keep their published state.
 * 3. Import. Running the same file again updates the products it made (ShopImport matches by WooCommerce id, SKU,
 *    then address).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once dirname(__DIR__) . '/ShopImport.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('shop.manage_products');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_import_title', 'Import products');
$currentNav = 'shop-products';
$self = plugin_url('shop', 'admin/import.php');
$flash = null;
$result = null;

// data/ is never served (its .htaccess and the dev router refuse it).
$dir = SLATE_ROOT . '/data/shop-import';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
// Old uploads are removed after a day.
foreach (glob($dir . '/*.upload') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);

$session = $_SESSION['shop_import'] ?? null;
$file = is_array($session) && preg_match('/^[a-f0-9]{32}$/', (string) ($session['token'] ?? '')) ? $dir . '/' . $session['token'] . '.upload' : '';
if ($file !== '' && !is_file($file)) { $file = ''; unset($_SESSION['shop_import']); $session = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } elseif ($action === 'upload') {
        $up = $_FILES['file'] ?? null;
        if (!is_array($up) || (int) ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $up['tmp_name'])) {
            $flash = ['type' => 'error', 'msg' => __('shop_err_file_missing', 'No file was received.')];
        } elseif ((int) $up['size'] > ShopImport::MAX_BYTES) {
            $flash = ['type' => 'error', 'msg' => __('shop_err_file_large', 'The file is too large.')];
        } else {
            $token = bin2hex(random_bytes(16));
            if (@move_uploaded_file((string) $up['tmp_name'], $dir . '/' . $token . '.upload')) {
                if ($file !== '') @unlink($file);
                $_SESSION['shop_import'] = ['token' => $token, 'name' => mb_substr(basename((string) $up['name']), 0, 120)];
                header('Location: ' . $self);
                exit;
            }
            $flash = ['type' => 'error', 'msg' => __('shop_err_file_save', 'The file could not be saved.')];
        }
    } elseif ($action === 'clear') {
        if ($file !== '') @unlink($file);
        unset($_SESSION['shop_import']);
        header('Location: ' . $self);
        exit;
    } elseif ($action === 'run' && $file !== '') {
        $read = ShopImport::read((string) file_get_contents($file), (string) $session['name']);
        if (!isset($read['error'])) {
            $result = ShopImport::run($read['items'], [
                'images'         => ($_POST['images'] ?? '') === 'link' ? 'link' : 'library',
                'status'         => ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'file',
                'weight_unit'    => (string) ($_POST['weight_unit'] ?? ''),
                'dimension_unit' => (string) ($_POST['dimension_unit'] ?? ''),
            ]);
            @unlink($file);
            unset($_SESSION['shop_import']);
            $file = '';
            $session = null;
        }
    }
}

$read = $file !== '' ? ShopImport::read((string) file_get_contents($file), (string) ($session['name'] ?? '')) : null;
$plan = $read !== null && !isset($read['error']) ? ShopImport::plan($read['items']) : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop_products', 'Products'), 'href' => plugin_url('shop', 'admin/products.php')],
    ['label' => __('shop_import_title', 'Import products')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header"><div><h1><?= __('shop_import_title', 'Import products') ?></h1>
    <p class="text-muted text-sm"><?= __('shop_import_sub', 'Bring products, variants, categories, tags and images over from a WooCommerce shop.') ?></p></div></div>

<?php if ($result !== null): ?>
    <div class="card" style="padding:20px;">
        <h2 style="margin-top:0;"><?= __('shop_import_done', 'Import finished') ?></h2>
        <p><?= e(sprintf(__('shop_import_done_counts', '%1$d created, %2$d updated, %3$d variants, %4$d images, %5$d files, %6$d skipped.'),
            $result['created'], $result['updated'], $result['variants'], $result['images'], $result['files'], $result['skipped'])) ?></p>
        <?php if ($result['errors']): ?>
            <div class="alert alert-warning"><strong><?= __('shop_import_problems', 'Some things did not carry over:') ?></strong>
                <ul style="margin:8px 0 0 18px;"><?php foreach (array_slice($result['errors'], 0, 50) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <p class="sb-button-row"><a class="btn btn-primary" href="<?= e(plugin_url('shop', 'admin/products.php')) ?>"><?= __('shop_open_products', 'Open the products') ?></a></p>
    </div>
<?php elseif ($file === ''): ?>
    <?php slate_editor_css(); ?>
    <?php slate_edit_open(['title_fallback' => __('shop_import_title', 'Import products')]); ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="upload">
        <?php slate_edit_card_open(['icon' => 'upload', 'eyebrow' => __('shop_import_step1', 'Step 1'), 'title' => __('shop_import_choose', 'Choose the file')]); ?>
            <div class="field">
                <input type="file" name="file" accept=".csv,.json,text/csv,application/json" required aria-label="<?= e(__('shop_import_file', 'Product file')) ?>">
                <div class="field-hint"><?= __('shop_import_file_hint', 'In WooCommerce: Products › Export, all columns, all products. Or a JSON list from the WooCommerce REST API. Nothing is imported before you see a preview.') ?></div>
            </div>
        <?php slate_edit_card_close(); ?>
        <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary">' . __('shop_import_preview', 'Preview') . '</button>'
            . '<a href="' . e(plugin_url('shop', 'admin/products.php')) . '" class="btn btn-ghost">' . __('shop_cancel', 'Cancel') . '</a>']); ?>
    </form>
    <?php slate_edit_close(); ?>
<?php elseif (isset($read['error'])): ?>
    <div class="alert alert-error"><?= e($read['error']) ?></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="clear"><button class="btn"><?= __('shop_import_other', 'Choose another file') ?></button></form>
<?php else:
    $units = $read['units']; ?>
    <div class="dwidget-kpis" style="margin-bottom:16px;">
        <?php foreach ([__('shop_import_new', 'New') => $plan['create'], __('shop_import_updated', 'Updated') => $plan['update'], __('shop_variants', 'Variants') => $plan['variants'],
                        __('shop_images', 'Images') => $plan['images'], __('shop_import_skipped', 'Skipped') => $plan['skipped']] as $k => $n): ?>
            <div class="dwidget-kpi"><div class="dwidget-kpi-k"><?= e($k) ?></div><div class="dwidget-kpi-v"><?= (int) $n ?></div></div>
        <?php endforeach; ?>
    </div>
    <div class="data-list" data-single-open>
        <?php foreach ($plan['rows'] as $r):
            slate_data_row([
                'avatar'       => mb_substr($r['name'], 0, 1),
                'avatar_color' => $r['action'] === 'skip' ? 'muted' : ($r['warnings'] ? 'warning' : 'success'),
                'title'        => $r['name'],
                'meta'         => $r['detail'] . ($r['warnings'] ? ' · ' . (count($r['warnings']) === 1 ? __('shop_import_one_note', '1 note') : sprintf(__('shop_import_n_notes', '%d notes'), count($r['warnings']))) : ''),
                'badge'        => match ($r['action']) { 'create' => [__('shop_import_new', 'New'), 'active'], 'update' => [__('shop_import_updated', 'Updated'), 'blue'], default => [__('shop_import_skipped', 'Skipped'), 'inactive'] },
                'detail'       => $r['warnings'] ? array_combine(array_map(static fn(int $i): string => '#' . ($i + 1), array_keys($r['warnings'])), $r['warnings']) : [__('shop_import_notes', 'Notes') => '—'],
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>

    <?php slate_editor_css(); ?>
    <?php slate_edit_open(['title_fallback' => __('shop_import_title', 'Import products')]); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="run">
        <?php slate_edit_card_open(['icon' => 'settings', 'eyebrow' => __('shop_import_step2', 'Step 2'), 'title' => sprintf(__('shop_import_options', 'Import %s'), (string) $session['name'])]); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="weight_unit"><?= __('shop_import_weight_unit', 'Weights in the file are in') ?></label>
                    <select id="weight_unit" name="weight_unit"><?php foreach (ShopStoreAPI::WEIGHT_UNITS as $u): ?><option value="<?= $u ?>" <?= ($units['weight'] ?? ShopStoreAPI::weightUnit()) === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select>
                </div>
                <div class="field">
                    <label class="field-label" for="dimension_unit"><?= __('shop_import_dimension_unit', 'Sizes in the file are in') ?></label>
                    <select id="dimension_unit" name="dimension_unit"><?php foreach (ShopStoreAPI::DIMENSION_UNITS as $u): ?><option value="<?= $u ?>" <?= ($units['dimension'] ?? ShopStoreAPI::dimensionUnit()) === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select>
                </div>
            </div>
            <div class="field-hint" style="margin:-4px 0 14px;"><?= e(sprintf(__('shop_import_units_hint', 'They are converted to this shop\'s units (%1$s, %2$s).'), ShopStoreAPI::weightUnit(), ShopStoreAPI::dimensionUnit())) ?></div>
            <div class="field">
                <span class="field-label"><?= __('shop_images', 'Images') ?></span>
                <label class="field-check"><input type="radio" name="images" value="library" checked> <?= __('shop_import_images_copy', 'Copy them into the Media library (recommended: the old shop can be switched off)') ?></label>
                <label class="field-check"><input type="radio" name="images" value="link"> <?= __('shop_import_images_link', 'Keep showing them from the old shop\'s addresses') ?></label>
            </div>
            <div class="field" style="margin-bottom:0;">
                <span class="field-label"><?= __('shop_status', 'Status') ?></span>
                <label class="field-check"><input type="radio" name="status" value="draft" checked> <?= __('shop_import_as_drafts', 'Import everything as drafts, to check before publishing') ?></label>
                <label class="field-check"><input type="radio" name="status" value="file"> <?= __('shop_import_as_file', 'Publish what is published in the file') ?></label>
            </div>
        <?php slate_edit_card_close(); ?>
        <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary">' . e(sprintf(__('shop_import_run', 'Import %d products'), $plan['create'] + $plan['update'])) . '</button>']); ?>
    </form>
    <form method="post" style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="_action" value="clear"><button class="btn btn-ghost"><?= __('shop_import_other', 'Choose another file') ?></button></form>
    <?php slate_edit_close(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
