<?php
/**
 * Kohevo Studio — Types & Fields Architect.
 *
 * Dedicated admin page for Custom Post Types and Meta Fields Builder,
 * with the field types in `MetaFieldTypes` (text, number, choice, media, date and time,
 * relation and advanced kinds, each with its own options) and dynamic query loop bindings.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Meta\PageMeta;

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_types_fields', 'Types & fields');
$currentNav = 'studio-types-fields';

$flash = null;

// The site's post types: Studio ships none built in, so every type here is one the site created.
$customTypesRaw = Database::setting('studio_custom_post_types', $tenantId);
$customTypes = $customTypesRaw ? json_decode((string) $customTypesRaw, true) : [];
if (!is_array($customTypes)) {
    $customTypes = [];
}
$allPostTypes = $customTypes;

// The field definitions, read exactly as the pages read them.
$metaFields = PageMeta::allFields($tenantId);
// After a change the screen is shown again (POST/redirect/GET), on the same type filter.
$self = 'types-fields.php' . (isset($_GET['type']) && $_GET['type'] !== 'all' ? '?' . http_build_query(['type' => (string) $_GET['type']]) : '');

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage types and fields.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'create_post_type') {
            $key = strtolower(trim((string) ($_POST['key'] ?? '')));
            $singular = trim((string) ($_POST['singular'] ?? ''));
            $plural = trim((string) ($_POST['plural'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $prefix = \Slate\Module\StudioBuilder\Meta\PageRoutes::normalizePrefix((string) ($_POST['route'] ?? ''));
            $route = '/' . (string) $prefix;

            $key = preg_replace('/[^a-z0-9_-]/', '', $key);

            if ($key === '' || $singular === '') {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_type_required', 'Post type key and singular name are required.')];
            } elseif ($prefix === null) {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_route_invalid', 'A URL route prefix is one to three lowercase words separated by /, like /events. Leave it empty for none.')];
            } elseif (isset($allPostTypes[$key])) {
                $flash = ['type' => 'error', 'msg' => sprintf(__('studio_tf_type_exists', "Post type '%s' already exists."), $key)];
            } else {
                $customTypes[$key] = [
                    'key' => $key,
                    'singular' => $singular,
                    'plural' => $plural ?: $singular . 's',
                    'route' => $route,
                    'is_system' => false,
                    'description' => $desc,
                    'show_in_menu' => !empty($_POST['show_in_menu']),
                ];
                Database::setSetting('studio_custom_post_types', json_encode($customTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => sprintf(__('studio_tf_type_created', "Custom post type '%s' created successfully!"), $singular)]);
            }
        } elseif ($action === 'update_post_type_route') {
            // Pages of the type move to the new address at once; their old address answers with a 301 to it.
            $key = trim((string) ($_POST['key'] ?? ''));
            $prefix = \Slate\Module\StudioBuilder\Meta\PageRoutes::normalizePrefix((string) ($_POST['route'] ?? ''));
            if (!isset($customTypes[$key])) {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_field_bad_type', 'Choose a post type that exists.')];
            } elseif ($prefix === null) {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_route_invalid', 'A URL route prefix is one to three lowercase words separated by /, like /events. Leave it empty for none.')];
            } else {
                $customTypes[$key]['route'] = '/' . $prefix;
                Database::setSetting('studio_custom_post_types', json_encode($customTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                \Slate\Module\StudioBuilder\Meta\PageRoutes::forget($tenantId);
                AuditLog::record('studio.post_type_route_changed', (string) $tenantId, ['key' => $key, 'route' => '/' . $prefix]);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => $prefix === ''
                    ? sprintf(__('studio_tf_route_cleared', "Pages of '%s' now live at /their-slug."), (string) ($customTypes[$key]['singular'] ?? $key))
                    : sprintf(__('studio_tf_route_saved', "Pages of '%1\$s' now live at /%2\$s/their-slug."), (string) ($customTypes[$key]['singular'] ?? $key), $prefix)]);
            }
        } elseif ($action === 'toggle_menu') {
            // A type's own list in the admin sidebar, on or off. Its items and the list itself stay either way.
            $key = trim((string) ($_POST['key'] ?? ''));
            if (isset($customTypes[$key])) {
                $customTypes[$key]['show_in_menu'] = !empty($_POST['show_in_menu']);
                Database::setSetting('studio_custom_post_types', json_encode($customTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => sprintf($customTypes[$key]['show_in_menu']
                    ? __('studio_tf_menu_on', '%s now has its own entry in the sidebar.')
                    : __('studio_tf_menu_off', '%s left the sidebar. Its list is still here, under View items.'), (string) ($customTypes[$key]['plural'] ?? $key))]);
            }
        } elseif ($action === 'delete_post_type') {
            // Fields attached to the type go with it; content items already created are not touched.
            $key = trim((string) ($_POST['key'] ?? ''));
            if (isset($customTypes[$key])) {
                $label = (string) ($customTypes[$key]['singular'] ?? $key);
                unset($customTypes[$key]);
                Database::setSetting('studio_custom_post_types', json_encode($customTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                $metaFields = array_values(array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') !== $key));
                Database::setSetting('studio_meta_fields', json_encode($metaFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                AuditLog::record('studio.post_type_deleted', (string) $tenantId, ['key' => $key]);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => sprintf(__('studio_tf_type_deleted', "Post type '%s' deleted."), $label)]);
            }
        } elseif ($action === 'create_meta_field') {
            $pType = trim((string) ($_POST['post_type'] ?? ''));
            $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 120);
            $fKey = strtolower(trim((string) ($_POST['key'] ?? '')));
            $fType = MetaFieldTypes::normalize((string) ($_POST['type'] ?? 'text'));
            $placeholder = mb_substr(trim((string) ($_POST['placeholder'] ?? '')), 0, 255);
            $help = mb_substr(trim((string) ($_POST['help'] ?? '')), 0, 300);
            $required = !empty($_POST['required']);

            $fKey = trim((string) preg_replace('/[^a-z0-9_]/', '_', $fKey), '_');
            $fOptions = MetaFieldTypes::options($fType, $_POST, array_keys($allPostTypes));
            $duplicate = array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') === $pType && ($f['key'] ?? '') === $fKey);

            if ($label === '' || $fKey === '') {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_field_required', 'Field label and field key are required.')];
            } elseif (!isset($allPostTypes[$pType])) {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_field_bad_type', 'Choose a post type that exists.')];
            } elseif ($duplicate !== []) {
                $flash = ['type' => 'error', 'msg' => sprintf(__('studio_tf_field_exists', "A field with the key '%1\$s' already exists on %2\$s."), $fKey, $pType)];
            } elseif (in_array($fType, ['select', 'radio', 'multiselect', 'checkboxes'], true) && empty($fOptions['choices'])) {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_field_choices', 'Add at least one choice (one per line, as value or value : Label).')];
            } elseif (in_array($fType, ['relation', 'relations'], true) && empty($fOptions['target'])) {
                $flash = ['type' => 'error', 'msg' => __('studio_tf_field_target', 'Pick the post type this relation links to.')];
            } else {
                $fieldId = 'f_' . bin2hex(random_bytes(4));
                $metaFields[] = [
                    'id' => $fieldId,
                    'post_type' => $pType,
                    'label' => $label,
                    'key' => $fKey,
                    'type' => $fType,
                    'required' => $required,
                    'placeholder' => $placeholder,
                    'help' => $help,
                    'options' => $fOptions,
                ];
                Database::setSetting('studio_meta_fields', json_encode($metaFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => sprintf(__('studio_tf_field_added', "Meta field '%1\$s' added to %2\$s!"), $label, $pType)]);
            }
        } elseif ($action === 'delete_meta_field') {
            $fieldId = trim((string) ($_POST['field_id'] ?? ''));
            if ($fieldId !== '') {
                $metaFields = array_values(array_filter($metaFields, fn($f) => ($f['id'] ?? '') !== $fieldId));
                Database::setSetting('studio_meta_fields', json_encode($metaFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => __('studio_tf_field_deleted', 'Meta field deleted.')]);
            }
        }
    }
}

$flash = $flash ?? sb_take_flash();

// Post type filter for fields list
$selectedType = trim((string) ($_GET['type'] ?? 'all'));
$filteredFields = $selectedType === 'all'
    ? $metaFields
    : array_values(array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') === $selectedType));


require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <!-- Overview Bar -->
    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_tf_heading', 'Post Types & Dynamic Schema')) ?></h2>
            <p><?= e(__('studio_tf_intro', 'Create custom content models and bind dynamic fields to Query Loops and Single Templates.')) ?></p>
        </div>
        <div class="sb-screen-actions">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-cpt-drawer').style.display='block'">
                <?= sb_svg('plus', 14) ?> <?= e(__('studio_tf_new_type', 'New Post Type')) ?>
            </button>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-field-drawer').style.display='block'">
                <?= sb_svg('plus', 14) ?> <?= e(__('studio_tf_add_field', 'Add Meta Field')) ?>
            </button>
        </div>
    </div>

    <!-- Drawer: New Post Type Form -->
    <div id="sb-cpt-drawer" class="sb-card sb-drawer" style="display: none;">
        <div class="sb-card__head sb-card__head--split">
            <div class="sb-card__title" style="display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('types-fields', 20) ?> <?= e(__('studio_tf_register_type', 'Register New Custom Post Type')) ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('sb-cpt-drawer').style.display='none'">
                <?= sb_svg('close', 14) ?>
            </button>
        </div>
        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="create_post_type">
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_type_key', 'Key / Identifier')) ?> <span class="field-required">*</span></label>
                    <input type="text" name="key" class="form-control" placeholder="<?= e(__('studio_tf_type_key_placeholder', 'e.g. clients, events')) ?>" required>
                    <p class="sb-field__hint"><?= e(__('studio_tf_type_key_hint', 'Lower-case letters and hyphens only.')) ?></p>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_singular', 'Singular Label')) ?> <span class="field-required">*</span></label>
                    <input type="text" name="singular" class="form-control" placeholder="<?= e(__('studio_tf_singular_placeholder', 'e.g. Client, Event')) ?>" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_plural', 'Plural Label')) ?></label>
                    <input type="text" name="plural" class="form-control" placeholder="<?= e(__('studio_tf_plural_placeholder', 'e.g. Clients, Events')) ?>">
                </div>
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_route', 'URL Route Prefix')) ?></label>
                    <input type="text" name="route" class="form-control" placeholder="/clients">
                    <p class="sb-field__hint"><?= e(__('studio_tf_route_hint', 'Pages of this type live at /clients/page-slug. Leave it empty for /page-slug.')) ?></p>
                </div>
            </div>
            <div class="sb-field">
                <label class="sb-field__label"><?= e(__('description', 'Description')) ?></label>
                <input type="text" name="description" class="form-control" placeholder="<?= e(__('studio_tf_type_description_placeholder', 'Purpose and data description...')) ?>">
            </div>
            <div class="sb-check-row" style="margin: 4px 0 12px;">
                <label style="display: flex; gap: 8px; align-items: center; font-weight: 600; font-size: 0.85rem;"><input type="checkbox" name="show_in_menu" value="1" checked> <?= e(__('studio_tf_show_in_menu', 'Show in the sidebar')) ?></label>
                <p class="sb-field__hint"><?= e(__('studio_tf_show_in_menu_hint', 'Gives the type its own list in the Studio sidebar, under Pages.')) ?></p>
            </div>
            <div class="sb-card-actions">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-cpt-drawer').style.display='none'"><?= e(__('cancel', 'Cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> <?= e(__('studio_tf_save_type', 'Save Post Type')) ?></button>
            </div>
        </form>
    </div>

    <!-- Drawer: Add Meta Field Form -->
    <div id="sb-field-drawer" class="sb-card sb-drawer" style="display: none;">
        <div class="sb-card__head sb-card__head--split">
            <div class="sb-card__title" style="display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('plus', 20) ?> <?= e(__('studio_tf_add_field', 'Add Meta Field')) ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('sb-field-drawer').style.display='none'">
                <?= sb_svg('close', 14) ?>
            </button>
        </div>
        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="create_meta_field">
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_target_type', 'Target Post Type')) ?></label>
                    <select name="post_type" class="form-control" style="background: #ffffff;">
                        <?php foreach ($allPostTypes as $tKey => $t): ?>
                            <option value="<?= e($tKey) ?>"><?= e($t['singular']) ?> (<?= e($tKey) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_field_label', 'Field Label')) ?> <span class="field-required">*</span></label>
                    <input type="text" name="label" class="form-control" placeholder="<?= e(__('studio_tf_field_label_placeholder', 'e.g. Project Budget')) ?>" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_field_key', 'Field Key')) ?> <span class="field-required">*</span></label>
                    <input type="text" name="key" class="form-control" placeholder="<?= e(sprintf(__('studio_tf_eg', 'e.g. %s'), 'project_budget')) ?>" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-meta-type"><?= e(__('studio_tf_field_type', 'Field Type')) ?></label>
                    <select name="type" id="sb-meta-type" class="form-control">
                        <?php foreach (MetaFieldTypes::GROUPS as $gKey => $gLabel): ?>
                            <optgroup label="<?= e($gLabel) ?>">
                                <?php foreach (MetaFieldTypes::all() as $tk => $tdef): if ($tdef['group'] !== $gKey) { continue; } ?>
                                    <option value="<?= e($tk) ?>" data-options="<?= e(implode(' ', $tdef['options'])) ?>"><?= e($tdef['label']) ?> — <?= e($tdef['hint']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Options for the chosen type: only the ones it takes are shown -->
            <div class="sb-form-grid" id="sb-meta-options" style="margin-top: 20px;">
                <div class="sb-field sb-field--wide" data-opt="choices" hidden>
                    <label class="sb-field__label" for="sb-opt-choices"><?= e(__('studio_tf_choices', 'Choices')) ?></label>
                    <textarea name="choices" id="sb-opt-choices" rows="4" class="form-control" placeholder="remote : Remote&#10;onsite : On-site&#10;hybrid : Hybrid"></textarea>
                    <p class="sb-field__hint"><?= sprintf(e(__('studio_tf_choices_hint', 'One per line, as %1$s or %2$s.')), '<code>value</code>', '<code>value : Label</code>') ?></p>
                </div>
                <div class="sb-field" data-opt="target" hidden>
                    <label class="sb-field__label" for="sb-opt-target"><?= e(__('studio_tf_links_to', 'Links to')) ?></label>
                    <select name="target" id="sb-opt-target" class="form-control">
                        <?php foreach ($allPostTypes as $tKey => $t): ?>
                            <option value="<?= e($tKey) ?>"><?= e($t['singular']) ?> (<?= e($tKey) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sb-field" data-opt="min" hidden>
                    <label class="sb-field__label" for="sb-opt-min"><?= e(__('studio_tf_min', 'Minimum')) ?></label>
                    <input type="number" step="any" name="min" id="sb-opt-min" class="form-control" placeholder="<?= e(sprintf(__('studio_tf_eg', 'e.g. %s'), '0')) ?>">
                </div>
                <div class="sb-field" data-opt="max" hidden>
                    <label class="sb-field__label" for="sb-opt-max"><?= e(__('studio_tf_max', 'Maximum')) ?></label>
                    <input type="number" step="any" name="max" id="sb-opt-max" class="form-control" placeholder="<?= e(sprintf(__('studio_tf_eg', 'e.g. %s'), '100')) ?>">
                </div>
                <div class="sb-field" data-opt="step" hidden>
                    <label class="sb-field__label" for="sb-opt-step"><?= e(__('studio_tf_step', 'Step')) ?></label>
                    <input type="number" step="any" min="0" name="step" id="sb-opt-step" class="form-control" placeholder="<?= e(sprintf(__('studio_tf_eg', 'e.g. %s'), '1')) ?>">
                </div>
                <div class="sb-field" data-opt="max_length" hidden>
                    <label class="sb-field__label" for="sb-opt-maxlen"><?= e(__('studio_tf_max_length', 'Maximum length')) ?></label>
                    <input type="number" min="1" max="65535" name="max_length" id="sb-opt-maxlen" class="form-control" placeholder="<?= e(__('studio_tf_characters', 'characters')) ?>">
                </div>
                <div class="sb-field" data-opt="extensions" hidden>
                    <label class="sb-field__label" for="sb-opt-ext"><?= e(__('studio_tf_extensions', 'Allowed file types')) ?></label>
                    <input type="text" name="extensions" id="sb-opt-ext" class="form-control" placeholder="pdf, docx, zip">
                </div>
            </div>
            <script>
            (function () {
                var sel = document.getElementById('sb-meta-type');
                var box = document.getElementById('sb-meta-options');
                if (!sel || !box) return;
                function sync() {
                    var wanted = (sel.options[sel.selectedIndex].getAttribute('data-options') || '').split(' ');
                    box.querySelectorAll('[data-opt]').forEach(function (el) {
                        var on = wanted.indexOf(el.getAttribute('data-opt')) !== -1;
                        el.hidden = !on;
                        el.querySelectorAll('input,select,textarea').forEach(function (c) { c.disabled = !on; });
                    });
                    box.style.display = box.querySelector('[data-opt]:not([hidden])') ? '' : 'none';
                }
                sel.addEventListener('change', sync);
                sync();
            })();
            </script>
            <div class="sb-field" style="margin-top: 20px;">
                <label class="sb-field__label" for="sb-meta-help"><?= e(__('studio_tf_help', 'Help text')) ?></label>
                <input type="text" name="help" id="sb-meta-help" class="form-control" maxlength="300" placeholder="<?= e(__('studio_tf_help_placeholder', 'Shown to editors under the field (optional)')) ?>">
            </div>
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; margin-bottom: 16px;">
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tf_placeholder', 'Placeholder / Default')) ?></label>
                    <input type="text" name="placeholder" class="form-control" placeholder="<?= e(__('studio_tf_placeholder_placeholder', 'Optional placeholder hint or default value')) ?>">
                </div>
                <div style="padding-top: 22px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="required" value="1">
                        <span><?= e(__('studio_tf_required_field', 'Required field')) ?></span>
                    </label>
                </div>
            </div>
            <div class="sb-card-actions">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-field-drawer').style.display='none'"><?= e(__('cancel', 'Cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> <?= e(__('studio_tf_save_field', 'Save Field')) ?></button>
            </div>
        </form>
    </div>

    <!-- Section 1: Active Post Types Grid -->
    <div style="margin-bottom: 36px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--sb-text); margin: 0;"><?= e(__('studio_tf_active_types', 'Active Content Types')) ?></h3>
        </div>
        <?php if ($allPostTypes === []): ?>
            <div class="sb-card" style="text-align: center; color: var(--sb-muted);"><?= e(__('studio_tf_no_types', 'No post types. Create one with “New Post Type”.')) ?></div>
        <?php endif; ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px;">
            <?php foreach ($allPostTypes as $tKey => $pt):
                // Count fields
                $fieldCount = count(array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') === $tKey));
            ?>
                <div class="card" data-post-type="<?= e($tKey) ?>" style="border-radius: 14px; border: 1px solid var(--sb-border); padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-size: 0.72rem; font-weight: 700; background: rgba(99, 102, 241, 0.1); color: var(--sb-accent); padding: 2px 7px; border-radius: 6px; text-transform: uppercase;">
                                <?= e($pt['key']) ?>
                            </span>
                            <p class="sb-field__hint">
                                <?= e(sprintf(__('studio_tf_field_count', '%d field(s)'), $fieldCount)) ?>
                            </p>
                        </div>
                        <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--sb-text); margin: 0 0 4px 0;"><?= e($pt['singular']) ?></h4>
                        <p style="font-size: 0.82rem; color: var(--sb-muted); line-height: 1.4; margin: 0 0 12px 0;">
                            <?= e($pt['description'] ?? __('studio_tf_type_description_default', 'Custom content collection.')) ?>
                        </p>
                    </div>
                    <?php $ptPrefix = \Slate\Module\StudioBuilder\Meta\PageRoutes::normalizePrefix((string) ($pt['route'] ?? '')); ?>
                    <?php if ($canEdit): ?>
                        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" class="sb-type-route" style="display: flex; gap: 6px; align-items: center; margin: 0 0 12px 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="update_post_type_route">
                            <input type="hidden" name="key" value="<?= e($tKey) ?>">
                            <label for="route-<?= e($tKey) ?>" class="sb-field__hint" style="white-space: nowrap; margin: 0;"><?= e(__('studio_tf_url_prefix', 'URL prefix')) ?></label>
                            <input type="text" id="route-<?= e($tKey) ?>" name="route" class="form-control" value="<?= e($ptPrefix ? '/' . $ptPrefix : '') ?>" placeholder="<?= e(__('studio_tf_url_prefix_none', 'none')) ?>" style="flex: 1; min-width: 0;">
                            <button type="submit" class="btn btn-sm btn-outline"><?= e(__('save', 'Save')) ?></button>
                        </form>
                    <?php else: ?>
                        <p class="sb-field__hint" style="margin: 0 0 12px 0;"><?= e(__('studio_tf_address', 'Address:')) ?> /<?= e($ptPrefix ? $ptPrefix . '/' : '') ?>page-slug</p>
                    <?php endif; ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; border-top: 1px solid var(--sb-border); padding-top: 12px;">
                        <a class="btn btn-sm btn-primary" href="<?= e(plugin_url('studio-builder', 'admin/pages.php?type=' . rawurlencode((string) $tKey))) ?>" data-testid="type-items-<?= e((string) $tKey) ?>"><?= e(__('studio_tf_view_items', 'View items')) ?></a>
                        <?php if ($canEdit): ?>
                            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="toggle_menu">
                                <input type="hidden" name="key" value="<?= e($tKey) ?>">
                                <?php $inMenu = \Slate\Module\StudioBuilder\Meta\PageMeta::showsInMenu($pt); ?>
                                <input type="hidden" name="show_in_menu" value="<?= $inMenu ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-sm btn-outline" aria-pressed="<?= $inMenu ? 'true' : 'false' ?>" data-testid="type-menu-<?= e((string) $tKey) ?>">
                                    <?= e($inMenu ? __('studio_tf_in_menu_on', 'In sidebar: on') : __('studio_tf_in_menu_off', 'In sidebar: off')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(sprintf(__('studio_tf_delete_type_confirm', 'Delete the post type %s? Its meta fields are deleted too. Content already created is kept.'), (string) $pt['singular'])) ?>" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_post_type">
                                <input type="hidden" name="key" value="<?= e($tKey) ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?= e(__('studio_tf_delete_type_title', 'Delete this post type')) ?>" aria-label="<?= e(sprintf(__('studio_tf_delete_type_aria', 'Delete the post type %s'), (string) $pt['singular'])) ?>">
                                    <?= sb_svg('trash', 12) ?> <?= e(__('delete', 'Delete')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Section 2: Meta Fields Table with Filter -->
    <div style="margin-bottom: 36px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--sb-text); margin: 0;"><?= e(sprintf(__('studio_tf_fields_heading', 'Configured Meta Fields (%d)'), count($filteredFields))) ?></h3>
            <div class="sb-pill-filter" style="margin-bottom: 0;">
                <a href="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" class="sb-pill-item <?= $selectedType === 'all' ? 'active' : '' ?>">
                    <?= e(__('studio_tf_all_fields', 'All Fields')) ?>
                </a>
                <?php foreach ($allPostTypes as $tKey => $pt): ?>
                    <a href="<?= e(plugin_url('studio-builder', 'admin/types-fields.php?type=' . $tKey)) ?>" class="sb-pill-item <?= $selectedType === $tKey ? 'active' : '' ?>">
                        <?= e($pt['singular']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($filteredFields)): ?>
            <div class="card" style="padding: 32px; text-align: center; color: var(--sb-muted);">
                <?= e(__('studio_tf_no_fields', 'No custom fields found for this post type. Click "Add Meta Field" to create one.')) ?>
            </div>
        <?php else: ?>
            <div class="data-list is-columnar" data-single-open>
                <?php
                slate_data_list_head(['main' => __('studio_tf_field_label', 'Field Label'), 'value' => __('studio_tf_post_type', 'Post Type'), 'badge' => __('required', 'Required')]);
                foreach ($filteredFields as $f) {
                    $fId = (string) ($f['id'] ?? '');
                    $fLabel = (string) ($f['label'] ?? '');
                    $fKey = (string) ($f['key'] ?? '');
                    $fPType = (string) ($f['post_type'] ?? '');
                    $fType = (string) ($f['type'] ?? 'text');
                    $fReq = !empty($f['required']);

                    $actions = '';
                    if ($canEdit) {
                        $actions = '<form method="POST" action="' . e(plugin_url('studio-builder', 'admin/types-fields.php')) . '" onsubmit="return window.confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . e(sprintf(__('studio_tf_delete_field_confirm', 'Remove meta field %s?'), $fLabel)) . '" style="margin: 0; display: inline;">'
                            . csrf_field()
                            . '<input type="hidden" name="_action" value="delete_meta_field">'
                            . '<input type="hidden" name="field_id" value="' . e($fId) . '">'
                            . '<button type="submit" class="btn btn-sm btn-danger" title="' . e(__('studio_tf_delete_field_title', 'Delete Field')) . '">' . sb_svg('trash', 12) . ' ' . e(__('delete', 'Delete')) . '</button>'
                            . '</form>';
                    }

                    slate_data_row([
                        'avatar'       => $fLabel,
                        'avatar_color' => 'accent',
                        'title'        => $fLabel,
                        'meta_html'    => '<code>{{meta.' . e($fKey) . '}}</code> · ' . e(MetaFieldTypes::label($fType)),
                        'value'        => $fPType,
                        'badge'        => $fReq ? [__('required', 'Required'), 'danger'] : [__('studio_tf_optional', 'Optional'), 'muted'],
                        'detail'       => array_filter([
                            'key'  => ['label' => __('studio_tf_binding_token', 'Key / Binding Token'), 'html' => '<code>{{meta.' . e($fKey) . '}}</code>'],
                            'ptype' => ['label' => __('studio_tf_post_type', 'Post Type'), 'value' => $fPType],
                            'ftype' => ['label' => __('studio_tf_field_type', 'Field Type'), 'value' => MetaFieldTypes::label($fType)],
                            'opts'  => MetaFieldTypes::summary($f) !== '' ? ['label' => __('studio_tf_options', 'Options'), 'value' => MetaFieldTypes::summary($f)] : null,
                            'help'  => !empty($f['help']) ? ['label' => __('studio_tf_help', 'Help text'), 'value' => (string) $f['help']] : null,
                        ]),
                        'actions'      => $actions,
                    ]);
                }
                ?>
            </div>
            <?php slate_data_list_script(); ?>
        <?php endif; ?>
    </div>

    <!-- Section 3: Dynamic Data Binding Documentation -->
    <div class="card" style="background: #f8fafc; border: 1px solid var(--sb-border); border-radius: 14px; padding: 20px;">
        <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="color: var(--sb-accent); flex-shrink: 0; padding-top: 2px;">
                <?= sb_svg('terminal', 22) ?>
            </div>
            <div>
                <h4 style="font-weight: 700; color: var(--sb-text); margin: 0 0 6px 0; font-size: 0.95rem;"><?= e(__('studio_tf_guide_heading', 'Using Meta Fields in the Canvas Builder & Query Loops')) ?></h4>
                <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0 0 10px 0;">
                    <?= sprintf(e(__('studio_tf_guide_text', 'Any meta field declared above can be bound inside %s or single templates. In the Visual Canvas Property Panel, simply select the dynamic lightning icon or type the token directly:')), '<code>core.query_loop</code>') ?>
                </p>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <span style="background: #ffffff; border: 1px solid var(--sb-border); padding: 4px 10px; border-radius: 6px; font-family: monospace; font-size: 0.8rem; color: var(--sb-text);">
                        {{post.title}}
                    </span>
                    <span style="background: #ffffff; border: 1px solid var(--sb-border); padding: 4px 10px; border-radius: 6px; font-family: monospace; font-size: 0.8rem; color: var(--sb-text);">
                        {{post.excerpt}}
                    </span>
                    <span style="background: #ffffff; border: 1px solid var(--sb-border); padding: 4px 10px; border-radius: 6px; font-family: monospace; font-size: 0.8rem; color: var(--sb-text);">
                        {{meta.client}}
                    </span>
                    <span style="background: #ffffff; border: 1px solid var(--sb-border); padding: 4px 10px; border-radius: 6px; font-family: monospace; font-size: 0.8rem; color: var(--sb-text);">
                        {{meta.project_url}}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
