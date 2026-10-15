<?php
/**
 * Kohevo Studio — Types & Fields Architect.
 *
 * Dedicated admin page for Custom Post Types and Meta Fields Builder,
 * supporting all 10 canonical field types (text, textarea, number, image,
 * gallery, select, relation, date, boolean, url) and dynamic query loop bindings.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_types_fields', 'Types & fields');
$currentNav = 'studio-types-fields';

$flash = null;

// Built-in Post Types
$defaultTypes = [
    'portfolio' => [
        'key' => 'portfolio',
        'singular' => 'Portfolio Item',
        'plural' => 'Portfolio Items',
        'route' => '/portfolio',
        'is_system' => true,
        'description' => 'Showcase client work, case studies, technologies, and project metrics.',
    ],
    'services' => [
        'key' => 'services',
        'singular' => 'Service',
        'plural' => 'Services',
        'route' => '/services',
        'is_system' => true,
        'description' => 'Service packages, deliverables, tech stacks, and freelance pricing tiers.',
    ],
    'testimonials' => [
        'key' => 'testimonials',
        'singular' => 'Testimonial',
        'plural' => 'Testimonials',
        'route' => '/testimonials',
        'is_system' => true,
        'description' => 'Social proof, client reviews, ratings, and endorsements.',
    ],
    'case_studies' => [
        'key' => 'case_studies',
        'singular' => 'Case Study',
        'plural' => 'Case Studies',
        'route' => '/case-studies',
        'is_system' => true,
        'description' => 'In-depth project breakdown with challenge, solution, and quantifiable results.',
    ],
];

// Load Custom Post Types from Settings
$customTypesRaw = Database::setting('studio_custom_post_types', $tenantId);
$customTypes = $customTypesRaw ? json_decode((string) $customTypesRaw, true) : [];
if (!is_array($customTypes)) {
    $customTypes = [];
}
// Built-in types an administrator has removed from this site (the list of their keys), so every type can be deleted.
$removedRaw = Database::setting('studio_removed_post_types', $tenantId);
$removedTypes = $removedRaw ? json_decode((string) $removedRaw, true) : [];
$removedTypes = is_array($removedTypes) ? array_values(array_filter($removedTypes, 'is_string')) : [];
$visibleDefaults = fn(array $removed): array => array_diff_key($defaultTypes, array_flip($removed));
$allPostTypes = array_merge($visibleDefaults($removedTypes), $customTypes);

// Load Meta Fields from Settings
$metaFieldsRaw = Database::setting('studio_meta_fields', $tenantId);
$metaFields = $metaFieldsRaw ? json_decode((string) $metaFieldsRaw, true) : [];
if (!is_array($metaFields)) {
    // Default starter fields for portfolio
    $metaFields = [
        [
            'id' => 'f_client',
            'post_type' => 'portfolio',
            'label' => 'Client / Organization',
            'key' => 'client',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'e.g. Acme Corp, FinTech Labs',
        ],
        [
            'id' => 'f_year',
            'post_type' => 'portfolio',
            'label' => 'Completion Year',
            'key' => 'year',
            'type' => 'number',
            'required' => false,
            'placeholder' => 'e.g. 2026',
        ],
        [
            'id' => 'f_role',
            'post_type' => 'portfolio',
            'label' => 'Role & Scope',
            'key' => 'role',
            'type' => 'text',
            'required' => false,
            'placeholder' => 'e.g. Lead Full-Stack Architect',
        ],
        [
            'id' => 'f_project_url',
            'post_type' => 'portfolio',
            'label' => 'Live Project URL',
            'key' => 'project_url',
            'type' => 'url',
            'required' => false,
            'placeholder' => 'https://...',
        ],
        [
            'id' => 'f_featured',
            'post_type' => 'portfolio',
            'label' => 'Featured Highlight',
            'key' => 'featured',
            'type' => 'boolean',
            'required' => false,
            'placeholder' => '',
        ],
    ];
}

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage types and fields.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'create_post_type') {
            $key = strtolower(trim((string) ($_POST['key'] ?? '')));
            $singular = trim((string) ($_POST['singular'] ?? ''));
            $plural = trim((string) ($_POST['plural'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $route = '/' . ltrim(trim((string) ($_POST['route'] ?? $key)), '/');

            $key = preg_replace('/[^a-z0-9_-]/', '', $key);

            if ($key === '' || $singular === '') {
                $flash = ['type' => 'error', 'msg' => 'Post type key and singular name are required.'];
            } elseif (isset($allPostTypes[$key])) {
                $flash = ['type' => 'error', 'msg' => "Post type '{$key}' already exists."];
            } else {
                $customTypes[$key] = [
                    'key' => $key,
                    'singular' => $singular,
                    'plural' => $plural ?: $singular . 's',
                    'route' => $route,
                    'is_system' => false,
                    'description' => $desc,
                ];
                Database::setSetting('studio_custom_post_types', json_encode($customTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                $allPostTypes = array_merge($visibleDefaults($removedTypes), $customTypes);
                $flash = ['type' => 'success', 'msg' => "Custom post type '{$singular}' created successfully!"];
            }
        } elseif ($action === 'delete_post_type') {
            // Any type can go, built-in or custom. A built-in is hidden (its key is remembered); a custom one is removed. Fields
            // attached to the type go with it; content items already created are not touched.
            $key = trim((string) ($_POST['key'] ?? ''));
            if (isset($customTypes[$key]) || (isset($defaultTypes[$key]) && !in_array($key, $removedTypes, true))) {
                $label = (string) (($customTypes[$key] ?? $defaultTypes[$key])['singular'] ?? $key);
                if (isset($customTypes[$key])) {
                    unset($customTypes[$key]);
                    Database::setSetting('studio_custom_post_types', json_encode($customTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                } else {
                    $removedTypes[] = $key;
                    Database::setSetting('studio_removed_post_types', json_encode(array_values(array_unique($removedTypes)), JSON_UNESCAPED_SLASHES), $tenantId);
                }
                $allPostTypes = array_merge($visibleDefaults($removedTypes), $customTypes);
                $metaFields = array_values(array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') !== $key));
                Database::setSetting('studio_meta_fields', json_encode($metaFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                AuditLog::record('studio.post_type_deleted', (string) $tenantId, ['key' => $key, 'system' => isset($defaultTypes[$key])]);
                $flash = ['type' => 'success', 'msg' => "Post type '{$label}' deleted."];
            }
        } elseif ($action === 'restore_post_types') {
            Database::setSetting('studio_removed_post_types', '[]', $tenantId);
            $removedTypes = [];
            $allPostTypes = array_merge($visibleDefaults([]), $customTypes);
            $flash = ['type' => 'success', 'msg' => 'Built-in post types restored.'];
        } elseif ($action === 'create_meta_field') {
            $pType = trim((string) ($_POST['post_type'] ?? 'portfolio'));
            $label = trim((string) ($_POST['label'] ?? ''));
            $fKey = strtolower(trim((string) ($_POST['key'] ?? '')));
            $fType = trim((string) ($_POST['type'] ?? 'text'));
            $placeholder = trim((string) ($_POST['placeholder'] ?? ''));
            $required = !empty($_POST['required']);

            $fKey = preg_replace('/[^a-z0-9_]/', '_', $fKey);

            if ($label === '' || $fKey === '') {
                $flash = ['type' => 'error', 'msg' => 'Field label and field key are required.'];
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
                ];
                Database::setSetting('studio_meta_fields', json_encode($metaFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                $flash = ['type' => 'success', 'msg' => "Meta field '{$label}' added to {$pType}!"];
            }
        } elseif ($action === 'delete_meta_field') {
            $fieldId = trim((string) ($_POST['field_id'] ?? ''));
            if ($fieldId !== '') {
                $metaFields = array_values(array_filter($metaFields, fn($f) => ($f['id'] ?? '') !== $fieldId));
                Database::setSetting('studio_meta_fields', json_encode($metaFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                $flash = ['type' => 'success', 'msg' => 'Meta field deleted.'];
            }
        }
    }
}

// Post type filter for fields list
$selectedType = trim((string) ($_GET['type'] ?? 'all'));
$filteredFields = $selectedType === 'all'
    ? $metaFields
    : array_values(array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') === $selectedType));

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('types-fields', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" >
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Overview Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">Post Types & Dynamic Schema</h2>
            <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Create custom content models and bind dynamic fields to Query Loops and Single Templates.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-cpt-drawer').style.display='block'">
                <?= sb_svg('plus', 14) ?> New Post Type
            </button>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-field-drawer').style.display='block'">
                <?= sb_svg('plus', 14) ?> Add Meta Field
            </button>
        </div>
    </div>

    <!-- Drawer: New Post Type Form -->
    <div id="sb-cpt-drawer" class="sb-card sb-drawer" style="display: none;">
        <div class="sb-card__head sb-card__head--split">
            <div class="sb-card__title" style="display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('types-fields', 20) ?> Register New Custom Post Type
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
                    <label class="sb-field__label">Key / Identifier <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="key" class="form-control" placeholder="e.g. clients, events" required>
                    <p class="sb-field__hint">Lower-case letters and hyphens only.</p>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">Singular Label <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="singular" class="form-control" placeholder="e.g. Client, Event" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">Plural Label</label>
                    <input type="text" name="plural" class="form-control" placeholder="e.g. Clients, Events">
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">URL Route Prefix</label>
                    <input type="text" name="route" class="form-control" placeholder="/clients">
                </div>
            </div>
            <div class="sb-field">
                <label class="sb-field__label">Description</label>
                <input type="text" name="description" class="form-control" placeholder="Purpose and data description...">
            </div>
            <div class="sb-card-actions">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-cpt-drawer').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> Save Post Type</button>
            </div>
        </form>
    </div>

    <!-- Drawer: Add Meta Field Form -->
    <div id="sb-field-drawer" class="sb-card sb-drawer" style="display: none;">
        <div class="sb-card__head sb-card__head--split">
            <div class="sb-card__title" style="display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('plus', 20) ?> Add Meta Field
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
                    <label class="sb-field__label">Target Post Type</label>
                    <select name="post_type" class="form-control" style="background: #ffffff;">
                        <?php foreach ($allPostTypes as $tKey => $t): ?>
                            <option value="<?= e($tKey) ?>"><?= e($t['singular']) ?> (<?= e($tKey) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">Field Label <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="label" class="form-control" placeholder="e.g. Project Budget" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">Field Key <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="key" class="form-control" placeholder="e.g. project_budget" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">Field Type</label>
                    <select name="type" class="form-control" style="background: #ffffff;">
                        <option value="text">Text (Single-line string)</option>
                        <option value="textarea">Textarea (Multi-line / rich text)</option>
                        <option value="number">Number (Integer / Decimal / Metric)</option>
                        <option value="image">Image (Media asset ID attachment)</option>
                        <option value="gallery">Gallery (Multi-image collection)</option>
                        <option value="select">Select (Dropdown choices)</option>
                        <option value="relation">Relation (Linked item reference)</option>
                        <option value="date">Date (Calendar picker / timestamp)</option>
                        <option value="boolean">Boolean (True/False toggle)</option>
                        <option value="url">URL (Web address link)</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; margin-bottom: 16px;">
                <div class="sb-field">
                    <label class="sb-field__label">Placeholder / Default</label>
                    <input type="text" name="placeholder" class="form-control" placeholder="Optional placeholder hint or default value">
                </div>
                <div style="padding-top: 22px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="required" value="1">
                        <span>Required field</span>
                    </label>
                </div>
            </div>
            <div class="sb-card-actions">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-field-drawer').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> Save Field</button>
            </div>
        </form>
    </div>

    <!-- Section 1: Active Post Types Grid -->
    <div style="margin-bottom: 36px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--sb-text); margin: 0;">Active Content Types</h3>
            <?php if ($removedTypes !== [] && $canEdit): ?>
                <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" style="margin: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="restore_post_types">
                    <button type="submit" class="btn btn-sm btn-outline"><?= sb_svg('refresh', 12) ?> Restore <?= count($removedTypes) ?> built-in type(s)</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($allPostTypes === []): ?>
            <div class="sb-card" style="text-align: center; color: var(--sb-muted);">No post types. Create one with “New Post Type”<?= $removedTypes !== [] ? ', or restore the built-in ones' : '' ?>.</div>
        <?php endif; ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px;">
            <?php foreach ($allPostTypes as $tKey => $pt):
                $isSys = !empty($pt['is_system']);
                // Count fields
                $fieldCount = count(array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') === $tKey));
            ?>
                <div class="card" style="border-radius: 14px; border: 1px solid var(--sb-border); padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-size: 0.72rem; font-weight: 700; background: rgba(99, 102, 241, 0.1); color: var(--sb-accent); padding: 2px 7px; border-radius: 6px; text-transform: uppercase;">
                                <?= e($pt['key']) ?>
                            </span>
                            <p class="sb-field__hint">
                                <?= $fieldCount ?> field(s)
                            </p>
                        </div>
                        <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--sb-text); margin: 0 0 4px 0;"><?= e($pt['singular']) ?></h4>
                        <p style="font-size: 0.82rem; color: var(--sb-muted); line-height: 1.4; margin: 0 0 12px 0;">
                            <?= e($pt['description'] ?? 'Custom content collection.') ?>
                        </p>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--sb-border); padding-top: 12px;">
                        <a href="<?= e(plugin_url('studio-builder', 'admin/content.php?type=' . $tKey)) ?>" class="btn btn-sm btn-outline">
                            <?= sb_svg('content', 12) ?> Manage Items
                        </a>
                        <?php if ($canEdit): ?>
                            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" onsubmit="return confirm('Delete the post type <?= e(addslashes($pt['singular'])) ?>? Its meta fields are deleted too. Content already created is kept.');" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_post_type">
                                <input type="hidden" name="key" value="<?= e($tKey) ?>">
                                <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444;" title="Delete this post type" aria-label="Delete the post type <?= e($pt['singular']) ?>">
                                    <?= sb_svg('trash', 12) ?> Delete
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
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--sb-text); margin: 0;">Configured Meta Fields (<?= count($filteredFields) ?>)</h3>
            <div class="sb-pill-filter" style="margin-bottom: 0;">
                <a href="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" class="sb-pill-item <?= $selectedType === 'all' ? 'active' : '' ?>">
                    All Fields
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
                No custom fields found for this post type. Click "Add Meta Field" to create one.
            </div>
        <?php else: ?>
            <div class="data-list is-columnar" data-single-open>
                <?php
                slate_data_list_head(['main' => 'Field Label', 'value' => 'Post Type', 'badge' => 'Required']);
                foreach ($filteredFields as $f) {
                    $fId = (string) ($f['id'] ?? '');
                    $fLabel = (string) ($f['label'] ?? '');
                    $fKey = (string) ($f['key'] ?? '');
                    $fPType = (string) ($f['post_type'] ?? 'portfolio');
                    $fType = (string) ($f['type'] ?? 'text');
                    $fReq = !empty($f['required']);

                    $actions = '';
                    if ($canEdit) {
                        $actions = '<form method="POST" action="' . e(plugin_url('studio-builder', 'admin/types-fields.php')) . '" onsubmit="return confirm(\'Remove meta field ' . e($fLabel) . '?\');" style="margin: 0; display: inline;">'
                            . csrf_field()
                            . '<input type="hidden" name="_action" value="delete_meta_field">'
                            . '<input type="hidden" name="field_id" value="' . e($fId) . '">'
                            . '<button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444;" title="Delete Field">' . sb_svg('trash', 12) . ' Delete</button>'
                            . '</form>';
                    }

                    slate_data_row([
                        'avatar'       => $fLabel,
                        'avatar_color' => 'accent',
                        'title'        => $fLabel,
                        'meta_html'    => '<code>{{meta.' . e($fKey) . '}}</code>',
                        'value'        => $fPType,
                        'badge'        => $fReq ? ['Required', 'danger'] : ['Optional', 'muted'],
                        'detail'       => [
                            'key'  => ['label' => 'Key / Binding Token', 'html' => '<code>{{meta.' . e($fKey) . '}}</code>'],
                            'ptype' => ['label' => 'Post Type', 'value' => $fPType],
                            'ftype' => ['label' => 'Field Type', 'value' => strtoupper($fType)],
                        ],
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
                <h4 style="font-weight: 700; color: var(--sb-text); margin: 0 0 6px 0; font-size: 0.95rem;">Using Meta Fields in the Canvas Builder & Query Loops</h4>
                <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0 0 10px 0;">
                    Any meta field declared above can be bound inside <code>core.query_loop</code> or single templates. In the Visual Canvas Property Panel, simply select the dynamic lightning icon or type the token directly:
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
