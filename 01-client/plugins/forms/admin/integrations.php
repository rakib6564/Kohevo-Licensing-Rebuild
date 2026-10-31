<?php
/**
 * Forms — Integrations: connect an email marketing service and choose which forms send it their signups.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/FormsAPI.php';
require_once dirname(__DIR__) . '/lib/FormsIntegrations.php';

Auth::require();
Auth::requirePerm('forms.manage');
ModuleGuard::require('forms');
FormsAPI::ensureSchema();
FormsIntegrations::ensureSchema();

$tid = current_tenant_id();
$connectors = FormsIntegrations::connectors();
$provider = isset($connectors[$_GET['provider'] ?? '']) ? (string) $_GET['provider'] : (string) array_key_first($connectors);
$connector = $connectors[$provider];
$forms = Database::rows('SELECT id, title FROM forms_definitions WHERE tenant_id = ? ORDER BY title', [$tid]);

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        if ($action === 'save_key') {
            if (!empty($_POST['clear_key'])) {
                FormsIntegrations::saveSealedKey($provider, '');
                AuditLog::record('forms.integration_key_removed', $provider);
                $flash = ['type' => 'success', 'msg' => __('forms_int_key_removed', 'API key removed.')];
            } else {
                $name = FormsIntegrations::keySettingName($provider);
                $sealed = slate_seal_posted_secrets(['api_key' => $name], $_POST);
                if ($sealed === null) {
                    $flash = ['type' => 'error', 'msg' => __('settings_not_saved_encryption', 'Nothing was saved: a secret could not be encrypted.')];
                } elseif (isset($sealed[$name])) {
                    try {
                        // A key is kept only once the service accepts it.
                        $connector->lists(trim((string) $_POST['api_key']));
                        FormsIntegrations::saveSealedKey($provider, $sealed[$name]);
                        AuditLog::record('forms.integration_key_saved', $provider);
                        $flash = ['type' => 'success', 'msg' => __('forms_int_key_saved', 'Connected. The API key is saved, encrypted.')];
                    } catch (IntegrationException $e) {
                        $flash = ['type' => 'error', 'msg' => __('forms_int_key_refused', 'Not saved: the service refused this key.') . ' ' . $e->getMessage()];
                    }
                }
            }
        } elseif ($action === 'save_rule') {
            $formId = (int) ($_POST['form_id'] ?? 0);
            $form = $formId > 0 ? FormsAPI::getFormById($formId) : null;
            $names = array_column((array) ($form['fields'] ?? []), 'name');
            $pick = static fn(string $key): string => in_array((string) ($_POST[$key] ?? ''), $names, true) ? (string) $_POST[$key] : '';
            $redirect = trim((string) ($_POST['doi_redirect_url'] ?? ''));
            $template = (int) ($_POST['doi_template_id'] ?? 0);
            if ($form === null) {
                $flash = ['type' => 'error', 'msg' => __('forms_int_form_missing', 'That form no longer exists.')];
            } elseif ((int) ($_POST['list_id'] ?? 0) > 0 && $pick('email_field') === '') {
                $flash = ['type' => 'error', 'msg' => __('forms_int_email_required', 'Choose the field that holds the email address.')];
            } elseif ($template > 0 && !preg_match('~^https://~i', $redirect)) {
                $flash = ['type' => 'error', 'msg' => __('forms_int_redirect_required', 'Double opt-in needs a confirmation page starting with https://.')];
            } else {
                FormsIntegrations::saveRule($formId, $provider, ['list_id' => (int) ($_POST['list_id'] ?? 0), 'email_field' => $pick('email_field'), 'name_field' => $pick('name_field'),
                    'consent_field' => $pick('consent_field'), 'doi_template_id' => $template, 'doi_redirect_url' => $redirect]);
                AuditLog::record('forms.integration_rule_saved', (string) $formId, ['provider' => $provider]);
                $flash = ['type' => 'success', 'msg' => __('forms_int_rule_saved', 'Saved.')];
            }
        } elseif ($action === 'retry') {
            $n = FormsIntegrations::retryFailed();
            $flash = ['type' => 'success', 'msg' => sprintf(__('forms_int_retried', '%d signup(s) sent again.'), $n)];
        }
    }
}

$apiKey = FormsIntegrations::apiKey($provider);
$lists = [];
$listsError = null;
if ($apiKey !== '') {
    try { $lists = $connector->lists($apiKey); } catch (IntegrationException $e) { $listsError = $e->getMessage(); }
}
FormsIntegrations::processQueue();
$rules = FormsIntegrations::rules($provider);
$counts = FormsIntegrations::counts();
$problems = FormsIntegrations::recentProblems();

$pageTitle  = __('forms_integrations', 'Integrations');
$currentNav = 'forms-integrations';
require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('forms_integrations', 'Integrations')],
]); ?>

<div class="page-header"><div><h1><?= e(__('forms_integrations', 'Integrations')) ?></h1>
    <p class="page-header-sub"><?= e(__('forms_int_intro', 'Send your forms’ signups to your email marketing service. Every signup is also kept in Forms.')) ?></p></div></div>

<?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<section class="card mb-3">
    <div class="card-header"><h2><?= e($connector->label()) ?></h2>
        <span class="badge <?= $apiKey !== '' && !$listsError ? 'badge-success' : '' ?>"><?= e($apiKey !== '' && !$listsError ? __('forms_int_connected', 'Connected') : __('forms_int_not_connected', 'Not connected')) ?></span></div>
    <?php if ($listsError): ?><div class="alert alert-error"><?= e($listsError) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="save_key">
        <div class="field">
            <label class="field-label" for="api_key"><?= e(__('forms_int_api_key', 'API key')) ?></label>
            <input class="input" id="api_key" name="api_key" type="password" autocomplete="off" placeholder="<?= e($apiKey !== '' ? __('forms_int_key_keep', 'Saved. Leave blank to keep it.') : '') ?>">
            <div class="field-hint"><?= e(__('forms_int_key_hint', 'In Brevo: SMTP & API › API keys. It is stored encrypted and is never shown again.')) ?></div>
        </div>
        <?php if ($apiKey !== ''): ?><label class="checkbox"><input type="checkbox" name="clear_key" value="1"> <?= e(__('forms_int_remove_key', 'Remove the saved key')) ?></label><?php endif; ?>
        <div class="mt-2"><button class="btn btn-primary" type="submit"><?= e(__('forms_int_save_test', 'Save and test')) ?></button></div>
    </form>
</section>

<?php if ($apiKey !== '' && !$listsError): ?>
<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('forms_int_forms', 'Forms')) ?></h2></div>
    <p class="muted"><?= e(__('forms_int_forms_hint', 'Choose a list to turn a form on. Double opt-in sends a confirmation email first: enter the ID of your Brevo double opt-in template and the page to show afterwards.')) ?></p>
    <?php foreach ($forms as $f):
        $form = FormsAPI::getFormById((int) $f['id']);
        $fields = (array) ($form['fields'] ?? []);
        $rule = $rules[(int) $f['id']] ?? [];
        $emailDefault = (string) ($rule['email_field'] ?? '');
        if ($emailDefault === '') { foreach ($fields as $x) { if (($x['type'] ?? '') === 'email') { $emailDefault = (string) $x['name']; break; } } }
        $select = static function (string $name, string $current, bool $blank) use ($fields): string {
            $html = '<select class="input" name="' . e($name) . '" id="' . e($name) . '">' . ($blank ? '<option value="">—</option>' : '');
            foreach ($fields as $x) { $html .= '<option value="' . e((string) $x['name']) . '"' . ((string) $x['name'] === $current ? ' selected' : '') . '>' . e((string) ($x['label'] ?? $x['name'])) . '</option>'; }
            return $html . '</select>';
        };
    ?>
    <details class="mb-2" <?= !empty($rule['is_active']) ? 'open' : '' ?>>
        <summary><strong><?= e((string) $f['title']) ?></strong><?= !empty($rule['is_active']) ? ' · ' . e(__('forms_int_on', 'On')) : '' ?></summary>
        <form method="post" class="mt-2">
            <?= csrf_field() ?><input type="hidden" name="_action" value="save_rule"><input type="hidden" name="form_id" value="<?= (int) $f['id'] ?>">
            <div class="form-grid">
                <div class="field"><label class="field-label"><?= e(__('forms_int_list', 'List')) ?></label>
                    <select class="input" name="list_id"><option value="0"><?= e(__('forms_int_off', 'Off')) ?></option>
                    <?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (int) ($rule['list_id'] ?? 0) === (int) $l['id'] ? ' selected' : '' ?>><?= e((string) $l['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="field-label"><?= e(__('forms_int_email_field', 'Email field')) ?></label><?= $select('email_field', $emailDefault, true) ?></div>
                <div class="field"><label class="field-label"><?= e(__('forms_int_name_field', 'Name field')) ?></label><?= $select('name_field', (string) ($rule['name_field'] ?? ''), true) ?></div>
                <div class="field"><label class="field-label"><?= e(__('forms_int_consent_field', 'Consent checkbox')) ?></label><?= $select('consent_field', (string) ($rule['consent_field'] ?? ''), true) ?>
                    <div class="field-hint"><?= e(__('forms_int_consent_hint', 'If chosen, only people who tick it are sent.')) ?></div></div>
                <div class="field"><label class="field-label"><?= e(__('forms_int_doi_template', 'Double opt-in template ID')) ?></label>
                    <input class="input" type="number" min="0" name="doi_template_id" value="<?= (int) ($rule['doi_template_id'] ?? 0) ?>"></div>
                <div class="field"><label class="field-label"><?= e(__('forms_int_doi_redirect', 'Page after confirming')) ?></label>
                    <input class="input" type="url" name="doi_redirect_url" value="<?= e((string) ($rule['doi_redirect_url'] ?? '')) ?>" placeholder="https://"></div>
            </div>
            <button class="btn btn-primary btn-sm" type="submit"><?= e(__('save', 'Save')) ?></button>
        </form>
    </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-header"><h2><?= e(__('forms_int_deliveries', 'Deliveries')) ?></h2>
        <span class="muted"><?= e(sprintf(__('forms_int_counts', '%1$d sent · %2$d waiting · %3$d failed'), $counts['sent'], $counts['pending'], $counts['failed'])) ?></span></div>
    <?php if ($problems === []): ?>
        <p class="muted"><?= e(__('forms_int_no_problems', 'Nothing is waiting or failed.')) ?></p>
    <?php else: ?>
        <table class="table"><thead><tr><th><?= e(__('forms_form', 'Form')) ?></th><th><?= e(__('email', 'Email')) ?></th><th><?= e(__('forms_int_status', 'Status')) ?></th><th><?= e(__('forms_int_error', 'Error')) ?></th></tr></thead><tbody>
        <?php foreach ($problems as $p): ?><tr><td><?= e((string) ($p['form_title'] ?? '')) ?></td><td><?= e((string) $p['email']) ?></td>
            <td><?= e($p['status'] === 'failed' ? __('forms_int_failed', 'Failed') : __('forms_int_waiting', 'Waiting')) ?></td><td><?= e((string) ($p['last_error'] ?? '')) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($counts['failed'] > 0): ?><form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="_action" value="retry"><button class="btn btn-sm" type="submit"><?= e(__('forms_int_retry', 'Send failed signups again')) ?></button></form><?php endif; ?>
    <?php endif; ?>
</section>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
