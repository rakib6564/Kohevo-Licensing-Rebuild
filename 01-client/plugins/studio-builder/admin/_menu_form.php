<?php
/**
 * Kohevo Studio — the shared menu form: a name, a key and a list of links (add, remove, move up/down).
 * Used by menu-new.php and menu-edit.php. Expects: $menuForm = ['key','name','items'], $isNew (bool), $menuPaths (list<string>).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Menu\StudioMenus;

$items = $menuForm['items'] !== [] ? $menuForm['items'] : [['label' => '', 'url' => '', 'new_tab' => false]];
?>
<div class="field-row field-row-2">
    <div class="field">
        <label class="field-label" for="sb-menu-name"><?= e(__('studio_menu_name', 'Menu name')) ?> <span class="field-required">*</span></label>
        <input type="text" id="sb-menu-name" name="name" required maxlength="<?= StudioMenus::MAX_NAME ?>" placeholder="<?= e(__('studio_menu_name_placeholder', 'Header menu')) ?>" value="<?= e($menuForm['name']) ?>">
    </div>
    <div class="field">
        <label class="field-label" for="sb-menu-key"><?= e(__('studio_menu_key', 'Key')) ?></label>
        <input type="text" id="sb-menu-key" name="key" maxlength="63" pattern="[a-z0-9][a-z0-9\-]*" placeholder="header" value="<?= e($menuForm['key']) ?>">
        <div class="field-hint"><?= e(__('studio_menu_key_hint', 'Lowercase letters, numbers and hyphens. Leave blank to generate from the name.')) ?></div>
    </div>
</div>
<?php if ($isNew): ?>
<div class="sb-suggest" role="group" aria-label="<?= e(__('studio_menu_suggested', 'Suggested menus')) ?>">
    <span class="sb-suggest__label"><?= e(__('studio_menu_suggested', 'Suggested menus')) ?>:</span>
    <?php foreach (['header' => __('studio_menu_header', 'Header menu'), 'footer' => __('studio_menu_footer', 'Footer menu'), 'services' => __('studio_menu_services', 'Services menu')] as $k => $label): ?>
        <button type="button" class="btn btn-sm btn-ghost" data-suggest-key="<?= e($k) ?>" data-suggest-name="<?= e($label) ?>"><?= e($label) ?></button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h3 class="sb-menu-links-title"><?= e(__('studio_menu_links', 'Links')) ?></h3>
<datalist id="sb-menu-paths">
    <?php foreach ($menuPaths as $path): ?><option value="<?= e($path) ?>"></option><?php endforeach; ?>
</datalist>
<ol class="sb-menu-items" id="sb-menu-items" data-max="<?= StudioMenus::MAX_ITEMS ?>">
    <?php foreach ($items as $i => $item): ?>
    <li class="sb-menu-item" data-testid="menu-item">
        <input type="text" name="items[<?= (int) $i ?>][label]" maxlength="<?= StudioMenus::MAX_LABEL ?>" placeholder="<?= e(__('studio_menu_label', 'Label')) ?>" aria-label="<?= e(__('studio_menu_label', 'Label')) ?>" value="<?= e((string) ($item['label'] ?? '')) ?>">
        <input type="text" name="items[<?= (int) $i ?>][url]" maxlength="<?= StudioMenus::MAX_URL ?>" list="sb-menu-paths" placeholder="<?= e(__('studio_menu_url', '/about or https://…')) ?>" aria-label="<?= e(__('studio_menu_url_label', 'Address')) ?>" value="<?= e((string) ($item['url'] ?? '')) ?>">
        <label class="sb-menu-item__tab"><input type="checkbox" name="items[<?= (int) $i ?>][new_tab]" value="1"<?= !empty($item['new_tab']) ? ' checked' : '' ?>> <span><?= e(__('studio_menu_new_tab', 'New tab')) ?></span></label>
        <span class="sb-menu-item__tools">
            <button type="button" class="btn btn-sm btn-ghost" data-move="up" aria-label="<?= e(__('studio_menu_move_up', 'Move up')) ?>">↑</button>
            <button type="button" class="btn btn-sm btn-ghost" data-move="down" aria-label="<?= e(__('studio_menu_move_down', 'Move down')) ?>">↓</button>
            <button type="button" class="btn btn-sm btn-ghost" data-remove aria-label="<?= e(__('studio_menu_remove', 'Remove link')) ?>"><?= sb_svg('trash', 14) ?></button>
        </span>
    </li>
    <?php endforeach; ?>
</ol>
<p><button type="button" class="btn btn-outline btn-sm" id="sb-menu-add" data-testid="menu-add-link"><?= sb_svg('plus', 14) ?> <span><?= e(__('studio_menu_add_link', 'Add link')) ?></span></button></p>
<style>
    .sb-menu-items { list-style: none; margin: 0 0 8px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .sb-menu-item { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(160px, 1.6fr) auto auto; align-items: center; gap: 8px; }
    .sb-menu-item__tab { display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; color: var(--sb-muted); white-space: nowrap; }
    .sb-menu-item__tools { display: inline-flex; gap: 2px; }
    .sb-menu-links-title { margin: 20px 0 10px; font-size: 1rem; }
    .sb-suggest { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin: 4px 0 6px; font-size: 0.84rem; color: var(--sb-muted); }
    @media (max-width: 720px) { .sb-menu-item { grid-template-columns: 1fr; } }
</style>
<script>
(function () {
    var list = document.getElementById('sb-menu-items');
    if (!list) return;
    var max = parseInt(list.getAttribute('data-max'), 10) || 30;
    function reindex() {
        Array.prototype.forEach.call(list.children, function (li, i) {
            Array.prototype.forEach.call(li.querySelectorAll('[name^="items["]'), function (el) {
                el.name = el.name.replace(/^items\[\d+\]/, 'items[' + i + ']');
            });
        });
        document.getElementById('sb-menu-add').disabled = list.children.length >= max;
    }
    document.getElementById('sb-menu-add').addEventListener('click', function () {
        if (list.children.length >= max) return;
        var copy = list.lastElementChild.cloneNode(true);
        Array.prototype.forEach.call(copy.querySelectorAll('input'), function (el) {
            if (el.type === 'checkbox') el.checked = false; else el.value = '';
        });
        list.appendChild(copy);
        reindex();
        copy.querySelector('input').focus();
    });
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var li = btn.closest('.sb-menu-item');
        if (btn.hasAttribute('data-remove')) {
            if (list.children.length > 1) li.remove();
            else Array.prototype.forEach.call(li.querySelectorAll('input'), function (el) { if (el.type === 'checkbox') el.checked = false; else el.value = ''; });
        } else if (btn.getAttribute('data-move') === 'up' && li.previousElementSibling) {
            list.insertBefore(li, li.previousElementSibling);
        } else if (btn.getAttribute('data-move') === 'down' && li.nextElementSibling) {
            list.insertBefore(li.nextElementSibling, li);
        }
        reindex();
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-suggest-key]'), function (b) {
        b.addEventListener('click', function () {
            document.getElementById('sb-menu-key').value = b.getAttribute('data-suggest-key');
            document.getElementById('sb-menu-name').value = b.getAttribute('data-suggest-name');
        });
    });
    reindex();
})();
</script>
