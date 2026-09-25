<?php
/**
 * Licensing — clients CRUD (list + inline create/edit).
 *
 * One row per customer buying licenses. Shared across products — the same
 * client can hold licenses for more than one product over time.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_clients_nav', 'Clients');
$currentNav = 'licensing-clients';

$flash  = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM licensing_clients WHERE id = ?", [$editId]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('licensing_csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id    = (int) ($_POST['id'] ?? 0);
            $name  = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => __('licensing_client_name_required', 'Name is required.')];
            } else {
                $row = [
                    'name'  => mb_substr($name, 0, 160),
                    'email' => $email !== '' ? mb_substr($email, 0, 190) : null,
                    'notes' => $notes !== '' ? $notes : null,
                ];
                if ($id > 0) {
                    Database::update('licensing_clients', $row, 'id = ?', [$id]);
                    AuditLog::record('licensing.client_updated', (string) $id);
                } else {
                    $id = Database::insert('licensing_clients', $row);
                    AuditLog::record('licensing.client_created', (string) $id);
                }
                header('Location: ' . plugin_url('licensing', 'admin/clients.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                // licensing_licenses.client_id carries no FK back to
                // licensing_clients, so without this a Phase-3 license
                // referencing this client would be silently orphaned by a
                // delete that only ever checked the legacy table.
                $inUse = (int) Database::value("SELECT COUNT(*) FROM licensing_installs WHERE client_id = ?", [$id])
                       + (int) Database::value("SELECT COUNT(*) FROM licensing_licenses WHERE client_id = ?", [$id]);
                if ($inUse > 0) {
                    $flash = ['type' => 'error', 'msg' => sprintf(
                        __('licensing_client_in_use', 'Cannot delete — this client still holds %d license(s).'), $inUse)];
                } else {
                    Database::delete('licensing_clients', 'id = ?', [$id]);
                    AuditLog::record('licensing.client_deleted', (string) $id);
                    $flash = ['type' => 'success', 'msg' => __('licensing_client_deleted', 'Client deleted.')];
                }
                // Deliberately no redirect here (unlike 'save' above) — a
                // redirect discarded $flash before it could ever render,
                // silently swallowing both the success and the "still in
                // use" error message (same fix already applied to
                // admin/plans.php's and admin/products.php's delete actions).
            }
        }
    }
}

// install_count is shown to the admin as "licenses" and must reflect both
// the legacy licensing_installs table AND the Phase-3 licensing_licenses
// table — a client with only Phase-3 licenses previously showed 0.
$clients = Database::rows(
    "SELECT c.*,
            (SELECT COUNT(*) FROM licensing_installs i WHERE i.client_id = c.id)
          + (SELECT COUNT(*) FROM licensing_licenses l WHERE l.client_id = c.id) AS install_count
       FROM licensing_clients c ORDER BY c.name"
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing'), 'href' => plugin_url('licensing', 'admin/index.php')],
    ['label' => __('licensing_clients_nav', 'Clients')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing): $c = $editing ?: ['id' => 0, 'name' => '', 'email' => '', 'notes' => '']; ?>
<section class="card mb-3">
    <div class="card-header"><h2><?= $editing ? e(__('licensing_edit_client', 'Edit client')) : e(__('licensing_new_client', 'New client')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="name"><?= __('licensing_name', 'Name') ?> <span class="field-required">*</span></label>
                <input type="text" id="name" name="name" required maxlength="160" value="<?= e($c['name']) ?>" placeholder="Acme Inc.">
            </div>
            <div class="field">
                <label class="field-label" for="email"><?= __('licensing_email', 'Email') ?></label>
                <input type="email" id="email" name="email" maxlength="190" value="<?= e($c['email'] ?? '') ?>" placeholder="billing@acme.com">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="notes"><?= __('licensing_notes', 'Notes') ?></label>
            <textarea id="notes" name="notes" rows="3"><?= e($c['notes'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-primary" type="submit"><?= $editing ? e(__('save', 'Save')) : e(__('licensing_create_client', 'Create client')) ?></button>
        <a href="<?= e(plugin_url('licensing', 'admin/clients.php')) ?>" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a>
    </form>
</section>
<?php else: ?>
    <div class="page-header">
        <div><h1><?= __('licensing_clients_nav', 'Clients') ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= __('licensing_new_client', 'New client') ?></a>
    </div>

    <?php if (!$clients): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('licensing_no_clients', 'No clients yet') ?></div>
        <p class="text-sm"><?= __('licensing_no_clients_sub', 'Add a client before issuing them a license.') ?></p>
    </div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($clients as $c):
            $actions = '<a href="?edit=' . (int) $c['id'] . '" class="btn btn-sm">' . __('edit', 'Edit') . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(' . e(json_encode(__('licensing_delete_client_confirm', 'Delete this client?'))) . ');">'
                     . csrf_field() . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . __('delete', 'Delete') . '</button></form>';
            slate_data_row([
                'avatar' => mb_substr($c['name'], 0, 1),
                'avatar_color' => 'info',
                'title'  => $c['name'],
                'meta'   => $c['email'] ?: '—',
                'badge'  => [$c['install_count'] . ' ' . __('licensing_licenses', 'license(s)'), $c['install_count'] > 0 ? 'active' : 'muted'],
                'detail' => [
                    __('licensing_email', 'Email') => $c['email'] ?: '—',
                    __('licensing_notes', 'Notes') => $c['notes'] ?: '—',
                    __('licensing_licenses', 'Licenses') => (int) $c['install_count'],
                    __('licensing_created', 'Created') => $c['created_at'],
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<style>.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
