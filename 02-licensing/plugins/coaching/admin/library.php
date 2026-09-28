<?php
/**
 * Coaching — Library.
 *
 * The practitioner's shared library of meal structures, shopping lists,
 * and recipes. Rows here have customer_id NULL. "Copy to client"
 * duplicates the row with a customer_id set — editing the copy never
 * affects the library original.
 *
 * Tabs: ?tab=structure (default) | shopping | recipes
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/includes/assets.php';

Auth::require();
Auth::requirePerm('coaching.manage_library');
CoachingAPI::ensureSchema();

$tab  = (string)($_GET['tab'] ?? 'structure');
if (!in_array($tab, ['structure','shopping','recipes','submitted'], true)) $tab = 'structure';

$pageTitle  = 'Coaching · Library';
$currentNav = 'coaching-library';

$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cc_security_check_failed', 'Security check failed.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'save_structure') {
            $tags = array_filter(array_map('trim', explode(',', (string)($_POST['tags_csv'] ?? ''))));
            CoachingAPI::saveMealStructure([
                'id'         => (int)($_POST['id'] ?? 0),
                'title'      => (string)($_POST['title'] ?? ''),
                'slot'       => (string)($_POST['slot'] ?? 'note'),
                'notes_html' => (string)($_POST['notes_html'] ?? ''),
                'tags'       => $tags,
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'customer_id'=> null,
            ]);
            $flash = ['type' => 'success', 'msg' => __('cc_lib_structure_saved', 'Meal structure saved.')];
        }
        elseif ($action === 'delete_structure') {
            CoachingAPI::deleteMealStructure((int)($_POST['id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('cc_removed', 'Removed.')];
        }
        elseif ($action === 'copy_structure') {
            CoachingAPI::copyMealStructureToClient((int)($_POST['id'] ?? 0), (int)($_POST['customer_id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('cc_lib_copied_to_client', 'Copied to client.')];
        }
        elseif ($action === 'save_shopping') {
            $sections = [];
            $headings = (array)($_POST['section_heading'] ?? []);
            $itemBlobs = (array)($_POST['section_items'] ?? []);
            foreach ($headings as $i => $h) {
                $items = array_filter(array_map('trim', explode("\n", (string)($itemBlobs[$i] ?? ''))));
                $sections[] = ['heading' => (string)$h, 'items' => $items];
            }
            $tags = array_filter(array_map('trim', explode(',', (string)($_POST['tags_csv'] ?? ''))));
            CoachingAPI::saveShoppingList([
                'id'          => (int)($_POST['id'] ?? 0),
                'name'        => (string)($_POST['name'] ?? ''),
                'sections'    => $sections,
                'tags'        => $tags,
                'customer_id' => null,
            ]);
            $flash = ['type' => 'success', 'msg' => __('cc_lib_shopping_saved', 'Shopping list saved.')];
        }
        elseif ($action === 'delete_shopping') {
            CoachingAPI::deleteShoppingList((int)($_POST['id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('cc_removed', 'Removed.')];
        }
        elseif ($action === 'copy_shopping') {
            CoachingAPI::copyShoppingListToClient((int)($_POST['id'] ?? 0), (int)($_POST['customer_id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('cc_lib_copied_to_client', 'Copied to client.')];
        }
        elseif ($action === 'save_recipe') {
            $ingredients = array_filter(array_map('trim', explode("\n", (string)($_POST['ingredients_text'] ?? ''))));
            $tags = array_filter(array_map('trim', explode(',', (string)($_POST['tags_csv'] ?? ''))));
            // Prefer a freshly uploaded file; otherwise a media-library pick
            // (validated so only a path the library actually manages can be
            // set); otherwise keep whatever photo the recipe already had --
            // this used to fall through to null and silently clear the
            // photo on every edit that didn't re-upload one.
            $photoPath = trim((string)($_POST['existing_photo_path'] ?? '')) ?: null;
            if (!empty($_POST['picked_photo_path'])) {
                $picked = trim((string)$_POST['picked_photo_path']);
                if (class_exists('MediaLibrary') && MediaLibrary::isManagedPath($picked)) {
                    $photoPath = $picked;
                }
            }
            if (!empty($_FILES['photo']['tmp_name'])) $photoPath = CoachingAPI::saveRecipePhoto($_FILES['photo']);
            CoachingAPI::saveRecipe([
                'id'                => (int)($_POST['id'] ?? 0),
                'author'            => 'practitioner',
                'title'             => (string)($_POST['title'] ?? ''),
                'photo_path'        => $photoPath,
                'ingredients'       => $ingredients,
                'instructions_html' => (string)($_POST['instructions_html'] ?? ''),
                'video_url'         => (string)($_POST['video_url'] ?? ''),
                'notes'             => (string)($_POST['notes'] ?? ''),
                'tags'              => $tags,
                'customer_id'       => null,
            ]);
            $flash = ['type' => 'success', 'msg' => __('cc_lib_recipe_saved', 'Recipe saved.')];
        }
        elseif ($action === 'delete_recipe') {
            CoachingAPI::deleteRecipe((int)($_POST['id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('cc_removed', 'Removed.')];
        }
        elseif ($action === 'copy_recipe') {
            CoachingAPI::copyRecipeToClient((int)($_POST['id'] ?? 0), (int)($_POST['customer_id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('cc_lib_copied_to_client', 'Copied to client.')];
        }
        elseif ($action === 'save_customer_recipe_note') {
            // Every sibling action in this file (saveRecipe/deleteRecipe, and
            // their meal-structure/shopping-list equivalents) scopes by
            // `tenant_id = ?`; this one didn't, so a staff member in ANY
            // tenant with coaching.manage_library could overwrite another
            // tenant's customer-submitted recipe note by id alone.
            $rid = (int)($_POST['id'] ?? 0);
            $tid = current_tenant_id();
            $r = Database::row("SELECT * FROM coaching_recipe WHERE id = ? AND tenant_id = ?", [$rid, $tid]);
            if ($r) {
                Database::update('coaching_recipe', ['notes' => trim((string)($_POST['notes'] ?? ''))], 'id = ? AND tenant_id = ?', [$rid, $tid]);
                $flash = ['type' => 'success', 'msg' => __('cc_lib_note_saved', 'Note saved.')];
            }
        }
    }
}

$clients = CoachingAPI::listEnrolledClients();

require SLATE_ROOT . '/admin/partials/header.php';

// The plugin's design, emitted by the page rather than relying on a boot-time
// enqueue that never runs while coaching is inactive.
coaching_emit_css('admin.css');

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cc_coaching_title', 'Coaching'),  'href' => plugin_url('coaching', 'admin/index.php')],
    ['label' => __('cc_library', 'Library')],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('cc_library', 'Library') ?></h1>
        <p class="text-muted"><?= __('cc_lib_subtitle', 'Reusable meal structures, shopping lists and recipes. "Copy to client" spins off an editable copy.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:var(--space-3);">
        <?= e($flash['msg']) ?>
    </div>
<?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:var(--space-3);border-bottom:1px solid var(--border);overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <?php foreach (['structure'=>__('cc_lib_tab_structure', 'Meal structures'),'shopping'=>__('cc_lib_tab_shopping', 'Shopping lists'),'recipes'=>__('cc_recipes', 'Recipes'),'submitted'=>__('cc_lib_tab_submitted', 'Client-submitted')] as $key => $lbl): ?>
        <a href="?tab=<?= e($key) ?>" style="padding:8px 14px;text-decoration:none;white-space:nowrap;flex-shrink:0;border-bottom:2px solid <?= $tab === $key ? 'var(--coach-brand)' : 'transparent' ?>;color:<?= $tab === $key ? '#0f172a' : '#64748b' ?>;font-weight:<?= $tab === $key ? '600' : '400' ?>;">
            <?= e($lbl) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'structure') coaching_lib_render_structure($clients);
      elseif ($tab === 'shopping') coaching_lib_render_shopping($clients);
      elseif ($tab === 'submitted') coaching_lib_render_submitted();
      else coaching_lib_render_recipes($clients);
?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';


function coaching_client_copy_form(string $action, int $rowId, array $clients): void {
    if (!$clients) return;
    ?>
    <form method="post" style="display:flex;gap:6px;margin:0;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= e($action) ?>">
        <input type="hidden" name="id" value="<?= (int)$rowId ?>">
        <select name="customer_id">
            <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary"><?= __('cc_lib_copy_to_client_btn', 'Copy to client') ?></button>
    </form>
    <?php
}


function coaching_lib_render_structure(array $clients): void {
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = $editId > 0 ? Database::row("SELECT * FROM coaching_meal_structure WHERE id = ? AND tenant_id = ? AND customer_id IS NULL", [$editId, current_tenant_id()]) : null;
    if ($editing) {
        $editing['tags'] = !empty($editing['tags_json']) ? (json_decode((string)$editing['tags_json'], true) ?: []) : [];
    }
    $items = CoachingAPI::listMealStructure(null);
    ?>
    <div class="card" style="padding:var(--space-4);margin-bottom:var(--space-3);">
        <h3 style="margin-top:0;"><?= $editing ? __('cc_lib_edit_structure', 'Edit meal structure') : __('cc_lib_new_structure', 'New meal structure') ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_structure">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="field-row field-row-title">
                <div class="field"><label class="field-label" for="title"><?= __('title', 'Title') ?></label>
                    <input type="text" id="title" name="title" required maxlength="200" value="<?= e((string)($editing['title'] ?? '')) ?>" placeholder="<?= e(__('cc_lib_placeholder_balanced_breakfast', 'Balanced breakfast')) ?>">
                </div>
                <div class="field"><label class="field-label" for="slot"><?= __('cc_lib_slot', 'Slot') ?></label>
                    <select id="slot" name="slot">
                        <?php foreach (['breakfast'=>__('cc_lib_breakfast', 'Breakfast'),'lunch'=>__('cc_lib_lunch', 'Lunch'),'dinner'=>__('cc_lib_dinner', 'Dinner'),'snack'=>__('cc_lib_snack', 'Snack'),'note'=>__('cc_lib_note_slot', 'Note')] as $v=>$lbl): ?>
                            <option value="<?= e($v) ?>" <?= (($editing['slot'] ?? 'note') === $v) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label class="field-label" for="sort_order"><?= __('cc_lib_sort', 'Sort') ?></label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="notes_html"><?= __('cc_lib_notes_html', 'Notes (HTML)') ?></label>
                <textarea id="notes_html" name="notes_html" rows="5" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e((string)($editing['notes_html'] ?? '')) ?></textarea>
                <div class="field-hint"><?= __('cc_lib_html_hint', 'You can use basic HTML:') ?> <code>&lt;ul&gt; &lt;li&gt; &lt;strong&gt; &lt;em&gt;</code>.</div>
            </div>
            <div class="field">
                <label class="field-label" for="tags_csv"><?= __('cc_lib_tags', 'Tags') ?></label>
                <input type="text" id="tags_csv" name="tags_csv" value="<?= e(implode(', ', (array)($editing['tags'] ?? []))) ?>" placeholder="<?= e(__('cc_lib_tags_placeholder_structure', 'gluten-free, low-gi, quick')) ?>">
                <div class="field-hint"><?= __('cc_lib_tags_hint', 'Comma-separated. Used to filter the library later.') ?></div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <?php if ($editing): ?><a href="?tab=structure" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a><?php endif; ?>
                <button class="btn btn-primary"><?= $editing ? __('cc_update', 'Update') : __('cc_lib_add_to_library', 'Add to library') ?></button>
            </div>
        </form>
    </div>

    <?php if (!$items): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= __('cc_lib_no_structures', 'No meal structures yet') ?></div><p class="text-sm"><?= __('cc_lib_no_structures_hint', 'Add one above — it becomes reusable across all your clients.') ?></p></div></div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($items as $it): ?>
                <div style="padding:14px 16px;border-bottom:1px solid var(--border);">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap;">
                        <div>
                            <strong><?= e($it['title']) ?></strong>
                            <span class="text-muted"> · <?= e($it['slot']) ?></span>
                            <?php foreach ($it['tags'] as $t): ?>
                                <span style="display:inline-block;font-size:11px;background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:8px;margin-left:6px;"><?= e($t) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <a class="btn btn-sm btn-ghost" href="?tab=structure&edit=<?= (int)$it['id'] ?>"><?= __('edit', 'Edit') ?></a>
                            <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('cc_lib_confirm_remove_library', 'Remove from library?')) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_structure">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button class="btn btn-sm btn-danger"><?= __('delete', 'Delete') ?></button>
                            </form>
                            <?php coaching_client_copy_form('copy_structure', (int)$it['id'], $clients); ?>
                        </div>
                    </div>
                    <?php if (!empty($it['notes_html'])): ?>
                        <div class="text-muted" style="margin-top:6px;font-size:13px;line-height:1.5;">
                            <?= strip_tags($it['notes_html'], '<ul><ol><li><strong><em><br>') ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;
}


function coaching_lib_render_shopping(array $clients): void {
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = $editId > 0 ? Database::row("SELECT * FROM coaching_shopping_list WHERE id = ? AND tenant_id = ? AND customer_id IS NULL", [$editId, current_tenant_id()]) : null;
    $editingSections = $editing && !empty($editing['sections_json']) ? (json_decode((string)$editing['sections_json'], true) ?: []) : [];
    if (!$editingSections) $editingSections = [['heading' => 'Staples', 'items' => []]];
    $editingTags = $editing && !empty($editing['tags_json']) ? (json_decode((string)$editing['tags_json'], true) ?: []) : [];

    $lists = CoachingAPI::listShoppingLists(null);
    ?>
    <div class="card" style="padding:var(--space-4);margin-bottom:var(--space-3);">
        <h3 style="margin-top:0;"><?= $editing ? __('cc_lib_edit_shopping', 'Edit shopping list') : __('cc_lib_new_shopping', 'New shopping list') ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_shopping">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="field">
                <label class="field-label" for="name"><?= __('name', 'Name') ?></label>
                <input type="text" id="name" name="name" required maxlength="200" value="<?= e((string)($editing['name'] ?? '')) ?>" placeholder="<?= e(__('cc_lib_placeholder_shopping_name', 'Gluten-free basic weekly')) ?>">
            </div>
            <div id="sections-list">
                <?php foreach ($editingSections as $i => $sec): ?>
                    <div class="field-row field-row-wide" style="margin-bottom:12px;padding:12px;background:rgba(148,163,184,0.05);border-radius:8px;">
                        <div class="field" style="margin:0;">
                            <label class="field-label"><?= __('cc_lib_section_heading', 'Section heading') ?></label>
                            <input type="text" name="section_heading[]" maxlength="100" value="<?= e((string)($sec['heading'] ?? '')) ?>" placeholder="<?= e(__('cc_lib_placeholder_staples', 'Staples')) ?>">
                        </div>
                        <div class="field" style="margin:0;">
                            <label class="field-label"><?= __('cc_lib_items_one_per_line', 'Items (one per line)') ?></label>
                            <textarea name="section_items[]" rows="4" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e(implode("\n", (array)($sec['items'] ?? []))) ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p style="margin:4px 0;"><small class="text-muted"><?= __('cc_lib_add_section_hint', 'Add another section by editing the empty rows below.') ?></small></p>
            <?php for ($i = 0; $i < 3; $i++): ?>
                <div style="display:grid;grid-template-columns:1fr 3fr;gap:12px;margin-bottom:12px;padding:12px;background:rgba(148,163,184,0.05);border-radius:8px;">
                    <div class="field" style="margin:0;">
                        <input type="text" name="section_heading[]" maxlength="100" placeholder="<?= e(__('cc_lib_placeholder_suitable_alt', 'e.g. Suitable alternatives')) ?>">
                    </div>
                    <div class="field" style="margin:0;">
                        <textarea name="section_items[]" rows="3" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;" placeholder="<?= e(__('cc_lib_placeholder_one_item_per_line', 'one item per line')) ?>"></textarea>
                    </div>
                </div>
            <?php endfor; ?>
            <div class="field">
                <label class="field-label" for="tags_csv"><?= __('cc_lib_tags', 'Tags') ?></label>
                <input type="text" id="tags_csv" name="tags_csv" value="<?= e(implode(', ', $editingTags)) ?>" placeholder="<?= e(__('cc_lib_tags_placeholder_shopping', 'gluten-free, vegetarian, low-gi')) ?>">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <?php if ($editing): ?><a href="?tab=shopping" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a><?php endif; ?>
                <button class="btn btn-primary"><?= $editing ? __('cc_update', 'Update') : __('cc_lib_add_to_library', 'Add to library') ?></button>
            </div>
        </form>
    </div>

    <?php if (!$lists): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= __('cc_lib_no_shopping', 'No shopping lists yet') ?></div></div></div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($lists as $l): ?>
                <div style="padding:14px 16px;border-bottom:1px solid var(--border);">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap;">
                        <div>
                            <strong><?= e($l['name']) ?></strong>
                            <?php foreach ($l['tags'] as $t): ?>
                                <span style="display:inline-block;font-size:11px;background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:8px;margin-left:6px;"><?= e($t) ?></span>
                            <?php endforeach; ?>
                            <div class="text-muted" style="font-size:13px;margin-top:4px;">
                                <?= e(sprintf(__('cc_lib_sections_count', '%d section(s)'), count($l['sections']))) ?> ·
                                <?= e(sprintf(__('cc_lib_items_count', '%d item(s)'), array_sum(array_map(fn($s) => count($s['items'] ?? []), $l['sections'])))) ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <a class="btn btn-sm btn-ghost" href="?tab=shopping&edit=<?= (int)$l['id'] ?>"><?= __('edit', 'Edit') ?></a>
                            <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('cc_confirm_remove', 'Remove?')) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_shopping">
                                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                <button class="btn btn-sm btn-danger"><?= __('delete', 'Delete') ?></button>
                            </form>
                            <?php coaching_client_copy_form('copy_shopping', (int)$l['id'], $clients); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;
}


function coaching_lib_render_recipes(array $clients): void {
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = $editId > 0 ? Database::row("SELECT * FROM coaching_recipe WHERE id = ? AND tenant_id = ? AND customer_id IS NULL", [$editId, current_tenant_id()]) : null;
    $editingIngredients = $editing && !empty($editing['ingredients_json']) ? (json_decode((string)$editing['ingredients_json'], true) ?: []) : [];
    $editingTags = $editing && !empty($editing['tags_json']) ? (json_decode((string)$editing['tags_json'], true) ?: []) : [];

    $recipes = CoachingAPI::listRecipes(null);

    // Media library picker: same pattern as shop/admin/products.php and
    // admin/settings.php -- enqueue if the plugin is installed and active,
    // the picker self-wires from data-mlp-target buttons.
    $mediaLibraryActive = class_exists('MediaLibrary')
        && PluginLoader::isActive('media-library');
    if ($mediaLibraryActive): ?>
        <link rel="stylesheet" href="<?= e(plugin_url('media-library', 'assets/css/picker.css')) ?>">
        <script src="<?= e(plugin_url('media-library', 'assets/js/picker.js')) ?>"></script>
    <?php endif; ?>
    <div class="card" style="padding:var(--space-4);margin-bottom:var(--space-3);">
        <h3 style="margin-top:0;"><?= $editing ? __('cc_lib_edit_recipe', 'Edit recipe') : __('cc_lib_new_recipe', 'New recipe') ?></h3>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_recipe">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="field-row field-row-recipe">
                <div class="field"><label class="field-label" for="title"><?= __('title', 'Title') ?></label>
                    <input type="text" id="title" name="title" required maxlength="200" value="<?= e((string)($editing['title'] ?? '')) ?>">
                </div>
                <div class="field"><label class="field-label" for="video_url"><?= __('cc_lib_video_url', 'Video URL (optional)') ?></label>
                    <input type="url" id="video_url" name="video_url" maxlength="500" value="<?= e((string)($editing['video_url'] ?? '')) ?>" placeholder="https://youtu.be/…">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="ingredients_text"><?= __('cc_lib_ingredients_label', 'Ingredients (one per line)') ?></label>
                <textarea id="ingredients_text" name="ingredients_text" rows="6" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e(implode("\n", $editingIngredients)) ?></textarea>
            </div>
            <div class="field">
                <label class="field-label" for="instructions_html"><?= __('cc_lib_instructions_label', 'Instructions (HTML)') ?></label>
                <textarea id="instructions_html" name="instructions_html" rows="8" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e((string)($editing['instructions_html'] ?? '')) ?></textarea>
            </div>
            <div class="field">
                <label class="field-label" for="photo"><?= __('cc_lib_photo_optional', 'Photo (optional)') ?></label>
                <input type="file" id="photo" name="photo" accept="image/*">
                <input type="hidden" name="existing_photo_path" value="<?= e((string)($editing['photo_path'] ?? '')) ?>">
                <?php if ($mediaLibraryActive): ?>
                    <input type="hidden" id="picked_photo_path" name="picked_photo_path" value="">
                    <button type="button" class="mlp-trigger-btn"
                            data-mlp-target="picked_photo_path"
                            data-mlp-mode="single">
                        <?= __('cc_lib_pick_media', 'Or pick from media library&hellip;') ?>
                    </button>
                <?php endif; ?>
                <?php if (!empty($editing['photo_path'])): ?>
                    <div style="margin-top:8px;">
                        <img id="picked_photo_path-preview" src="<?= e(SLATE_URL . '/' . ltrim($editing['photo_path'], '/')) ?>" style="max-width:180px;border-radius:8px;">
                    </div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label class="field-label" for="tags_csv"><?= __('cc_lib_tags', 'Tags') ?></label>
                <input type="text" id="tags_csv" name="tags_csv" value="<?= e(implode(', ', $editingTags)) ?>" placeholder="<?= e(__('cc_lib_tags_placeholder_recipe', 'quick, budget, low-gi')) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="notes"><?= __('cc_lib_practitioner_note', 'Practitioner note') ?></label>
                <textarea id="notes" name="notes" rows="2"><?= e((string)($editing['notes'] ?? '')) ?></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <?php if ($editing): ?><a href="?tab=recipes" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a><?php endif; ?>
                <button class="btn btn-primary"><?= $editing ? __('cc_update', 'Update') : __('cc_lib_add_to_library', 'Add to library') ?></button>
            </div>
        </form>
    </div>

    <?php if (!$recipes): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= __('cc_lib_no_recipes', 'No recipes yet') ?></div></div></div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($recipes as $r):
                $ingredientCount = count($r['ingredients']);
                $meta = sprintf(__('cc_lib_ingredient_count', '%d ingredient(s)'), $ingredientCount);
                if (!empty($r['tags'])) {
                    $meta .= ' - ' . implode(', ', $r['tags']);
                }

                $photoHtml = null;
                if (!empty($r['photo_path'])) {
                    $photoHtml = '<img src="' . e(SLATE_URL . '/' . ltrim($r['photo_path'], '/'))
                               . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">';
                }

                ob_start(); ?>
                <a class="btn btn-sm btn-ghost" href="?tab=recipes&edit=<?= (int)$r['id'] ?>"><?= __('edit', 'Edit') ?></a>
                <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('cc_confirm_remove', 'Remove?')) ?>');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete_recipe">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-danger"><?= __('delete', 'Delete') ?></button>
                </form>
                <?php $rowActions = ob_get_clean();

                ob_start(); ?>
                <?php if (!empty($r['tags'])): ?>
                    <div>
                        <?php foreach ($r['tags'] as $t): ?>
                            <span class="badge" style="margin-right:4px;"><?= e($t) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($ingredientCount > 0): ?>
                    <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;color:var(--muted);">
                        <?php foreach ($r['ingredients'] as $ing): ?>
                            <li><?= e($ing) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div style="margin-top:10px;">
                    <?php coaching_client_copy_form('copy_recipe', (int)$r['id'], $clients); ?>
                </div>
                <?php $tagsAndIngredientsHtml = ob_get_clean();

                slate_data_row([
                    'avatar_html'  => $photoHtml,
                    'avatar'       => $photoHtml === null ? mb_strtoupper(mb_substr((string)$r['title'], 0, 2)) : '',
                    'avatar_color' => 'accent',
                    'title'        => (string)$r['title'],
                    'meta'         => $meta,
                    'detail'       => [['label' => __('cc_lib_details', 'Details'), 'html' => $tagsAndIngredientsHtml]],
                    'actions'      => $rowActions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif;
}


function coaching_lib_render_submitted(): void {
    $rows = CoachingAPI::recentCustomerRecipes(30);
    ?>
    <?php if (!$rows): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= __('cc_lib_no_submissions', 'No client submissions yet') ?></div><p class="text-sm"><?= __('cc_lib_no_submissions_hint', 'Recipes clients share with you appear here.') ?></p></div></div>
    <?php else: ?>
        <div class="card">
            <?php foreach ($rows as $r):
                $ingredients = !empty($r['ingredients_json']) ? (json_decode((string)$r['ingredients_json'], true) ?: []) : [];
            ?>
                <div style="padding:14px 16px;border-bottom:1px solid var(--border);">
                    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
                        <?php if (!empty($r['photo_path'])): ?>
                            <img src="<?= e(SLATE_URL . '/' . ltrim($r['photo_path'], '/')) ?>" style="width:120px;height:120px;object-fit:cover;border-radius:8px;">
                        <?php endif; ?>
                        <div style="flex:1;min-width:280px;">
                            <strong><?= e($r['title']) ?></strong>
                            <div class="text-muted" style="font-size:13px;">
                                <?= e(__('cc_lib_from_prefix', 'From')) ?> <?= e($r['customer_name']) ?> · <?= e(I18n::localDate('j M Y', strtotime($r['created_at']))) ?>
                            </div>
                            <?php if ($ingredients): ?>
                                <details style="margin-top:6px;font-size:13px;">
                                    <summary><?= e(sprintf(__('cc_lib_ingredient_count', '%d ingredient(s)'), count($ingredients))) ?></summary>
                                    <ul><?php foreach ($ingredients as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul>
                                </details>
                            <?php endif; ?>
                            <form method="post" style="margin-top:8px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="save_customer_recipe_note">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <textarea name="notes" rows="2" style="width:100%;font-size:13px;padding:6px 10px;border-radius:6px;border:1px solid var(--border);" placeholder="<?= e(__('cc_lib_comment_placeholder', 'Comment or correction for the client…')) ?>"><?= e((string)($r['notes'] ?? '')) ?></textarea>
                                <button class="btn btn-sm btn-primary" style="margin-top:6px;"><?= __('cc_lib_save_comment', 'Save comment') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;
}
