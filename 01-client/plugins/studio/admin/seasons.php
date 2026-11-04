<?php
/**
 * Studio — admin Seasons.
 *
 * A season groups a term's classes so the next one can be duplicated instead
 * of retyped. Building next term previously meant fifteen manual forms, each
 * re-entering the same session dates.
 *
 * Duplication previews before it commits, and the preview is honest about
 * rounding: shifts are measured in WHOLE WEEKS so a Monday class stays on
 * Monday, which means picking a Wednesday start moves the term to the nearest
 * Monday instead. That is stated up front rather than discovered afterwards.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_seasons', 'Studio · Seasons');
$currentNav = 'studio_seasons';

$selfUrl = plugin_url('studio', 'admin/seasons.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        studio_set_flash('error', __('csrf_failed', 'Security check failed.'));
        header('Location: ' . $selfUrl); exit;
    }
    $action = (string) ($_POST['_action'] ?? '');
    $do     = (string) ($_POST['_do'] ?? '');

    try {
        if ($action === 'save_season') {
            $id = (int) ($_POST['id'] ?? 0);
            $data = [
                'name'       => (string) ($_POST['name'] ?? ''),
                'starts_on'  => (string) ($_POST['starts_on'] ?? ''),
                'ends_on'    => (string) ($_POST['ends_on'] ?? ''),
                'notes'      => (string) ($_POST['notes'] ?? ''),
                'is_current' => !empty($_POST['is_current']),
            ];
            if ($id > 0) {
                StudioAPI::updateSeason($id, $data);
                studio_set_flash('success', __('studio_season_saved', 'Season saved.'));
            } else {
                StudioAPI::createSeason($data);
                studio_set_flash('success', __('studio_season_created', 'Season created.'));
            }

        } elseif ($action === 'clone_season') {
            $res = StudioAPI::cloneSeason(
                (int) ($_POST['season_id'] ?? 0),
                (string) ($_POST['new_start'] ?? ''),
                (string) ($_POST['new_name'] ?? '')
            );
            studio_set_flash($res['failed'] === 0 ? 'success' : 'warning', sprintf(
                $res['failed'] === 0
                    ? __('studio_season_cloned', 'Season duplicated — %1$d class(es), %2$d lesson(s) scheduled.')
                    : __('studio_season_cloned_part', 'Season duplicated — %1$d class(es), %2$d lesson(s); %3$d skipped for bad dates.'),
                $res['classes'], $res['occurrences'], $res['failed']
            ));

        } elseif (str_starts_with($do, 'current_')) {
            StudioAPI::setCurrentSeason((int) substr($do, 8));
            studio_set_flash('success', __('studio_season_current', 'Current season updated.'));

        } elseif (str_starts_with($do, 'delete_')) {
            StudioAPI::deleteSeason((int) substr($do, 7));
            studio_set_flash('success', __('studio_season_deleted', 'Season removed. Its classes were kept and unassigned.'));
        }
    } catch (\Throwable $e) {
        studio_set_flash('error', $e->getMessage());
    }
    header('Location: ' . $selfUrl); exit;
}

$seasons = StudioAPI::getSeasons();
$editId  = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? StudioAPI::getSeason($editId) : null;
$newForm = isset($_GET['new']) || $editing !== null;

// Live preview of a duplication, so the drift is visible before committing.
$cloneId = (int) ($_GET['clone'] ?? 0);
$preview = null;
$cloneErr = '';
if ($cloneId > 0) {
    $newStart = (string) ($_GET['start'] ?? '');
    if ($newStart !== '') {
        try { $preview = StudioAPI::previewSeasonClone($cloneId, $newStart); }
        catch (\Throwable $e) { $cloneErr = $e->getMessage(); }
    }
}

$flash = studio_take_flash();
require dirname(__DIR__, 3) . '/admin/partials/header.php';
studio_ui_css();
?>

<?php if ($flash): ?><div class="alert alert-<?= e((string) $flash['type']) ?>"><?= e((string) $flash['msg']) ?></div><?php endif; ?>

<?php if ($cloneId > 0):
    $src = StudioAPI::getSeason($cloneId);
    if ($src):
?>
<div class="app-panel" style="margin-bottom:18px">
    <div class="card-header"><h2><?= sprintf(__('studio_season_dup_h', 'Duplicate “%s”'), e((string) $src['name'])) ?></h2></div>
    <div class="card-body">
        <p style="margin:0 0 14px;color:var(--muted);font-size:14px;max-width:70ch">
            <?= __('studio_season_dup_sub',
                'Every class is copied and its dates shifted by the same number of whole weeks, so a Monday class stays on Monday. Enrolments are not copied — a new term is a new sign-up.') ?>
        </p>

        <form method="get" style="margin:0 0 16px">
            <input type="hidden" name="clone" value="<?= (int) $cloneId ?>">
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="start"><?= __('studio_season_new_start', 'New term starts') ?></label>
                    <input type="date" id="start" name="start" value="<?= e((string) ($_GET['start'] ?? '')) ?>"
                           onchange="this.form.submit()">
                    <div class="field-hint">
                        <?= sprintf(__('studio_season_old_start', 'The original began %s.'),
                            $src['starts_on'] ? e(date('D j M Y', strtotime((string) $src['starts_on']))) : '—') ?>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($cloneErr !== ''): ?>
            <div class="alert alert-error"><?= e($cloneErr) ?></div>
        <?php elseif ($preview): ?>
            <div class="alert <?= $preview['drift'] === 0 ? 'alert-info' : 'alert-warning' ?>">
                <strong><?= sprintf(__('studio_season_shift_n', 'Shifts by %d week(s)'), (int) $preview['weeks']) ?></strong>
                <?php if ($preview['drift'] !== 0): ?>
                    — <?= sprintf(
                        __('studio_season_drift', 'that lands %d day(s) %s the date you picked, because classes keep their weekday'),
                        abs((int) $preview['drift']),
                        (int) $preview['drift'] > 0 ? __('studio_after', 'after') : __('studio_before', 'before')) ?>
                <?php endif; ?>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th><?= __('studio_class', 'Class') ?></th>
                        <th><?= __('studio_day', 'Day') ?></th>
                        <th><?= __('studio_was', 'Was') ?></th>
                        <th><?= __('studio_becomes', 'Becomes') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    foreach ($preview['classes'] as $c): ?>
                        <tr>
                            <td><?= e((string) $c['name']) ?></td>
                            <td><?= e($days[(int) $c['day_of_week']] ?? '') ?></td>
                            <td style="white-space:nowrap;color:var(--muted)">
                                <?= e(date('j M Y', strtotime((string) StudioAPI::getClassSeries((int) $c['id'])['session_start']))) ?>
                            </td>
                            <td style="white-space:nowrap;font-weight:600">
                                <?php if (!empty($c['_shift_failed'])): ?>
                                    <span class="pill pill-amber"><?= __('studio_season_skip', 'skipped — bad dates') ?></span>
                                <?php else: ?>
                                    <?= e(date('j M Y', strtotime((string) $c['session_start']))) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" style="margin-top:16px">
                <?= csrf_field() ?>
                <input type="hidden" name="_action"   value="clone_season">
                <input type="hidden" name="season_id" value="<?= (int) $cloneId ?>">
                <input type="hidden" name="new_start" value="<?= e((string) ($_GET['start'] ?? '')) ?>">
                <div class="field" style="max-width:32rem">
                    <label class="field-label" for="new_name"><?= __('studio_season_new_name', 'Name the new season') ?></label>
                    <input type="text" id="new_name" name="new_name" maxlength="120" value="<?= e((string) $preview['name']) ?>">
                </div>
                <button type="submit" class="btn btn-primary"><?= __('studio_season_do_dup', 'Duplicate season') ?></button>
                <a href="<?= e($selfUrl) ?>" class="btn"><?= __('cancel', 'Cancel') ?></a>
            </form>
        <?php else: ?>
            <p style="margin:0;color:var(--muted);font-size:14px"><?= __('studio_season_pick_date', 'Pick a start date to see what changes.') ?></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; endif; ?>

<?php if ($newForm): ?>
<div class="app-panel" style="margin-bottom:18px">
    <div class="card-header"><h2><?= $editing ? __('studio_season_edit', 'Edit season') : __('studio_season_new', 'New season') ?></h2></div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_season">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="field">
                <label class="field-label" for="name"><?= __('studio_season_name', 'Name') ?></label>
                <input type="text" id="name" name="name" required maxlength="120"
                       value="<?= e((string) ($editing['name'] ?? '')) ?>"
                       placeholder="<?= e(__('studio_season_name_ph', 'e.g. Fall/Winter 2026–27')) ?>">
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="starts_on"><?= __('studio_season_starts', 'Starts') ?></label>
                    <input type="date" id="starts_on" name="starts_on" value="<?= e((string) ($editing['starts_on'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="ends_on"><?= __('studio_season_ends', 'Ends') ?></label>
                    <input type="date" id="ends_on" name="ends_on" value="<?= e((string) ($editing['ends_on'] ?? '')) ?>">
                </div>
            </div>
            <div class="field">
                <label style="display:inline-flex;align-items:center;gap:8px">
                    <input type="checkbox" name="is_current" value="1" style="width:16px;height:16px"
                           <?= !empty($editing['is_current']) ? 'checked' : '' ?>>
                    <?= __('studio_season_is_current', 'This is the current season') ?>
                </label>
                <div class="field-hint"><?= __('studio_season_is_current_hint', 'Used as the default when creating a class.') ?></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= __('save', 'Save') ?></button>
            <a href="<?= e($selfUrl) ?>" class="btn"><?= __('cancel', 'Cancel') ?></a>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
studio_ui_list_start([], __('studio_search_seasons', 'Search seasons…'), [
    ['value' => 'noop', 'label' => __('studio_seasons', 'Seasons')],
]);
foreach ($seasons as $s):
    $span = ($s['starts_on'] ? date('j M Y', strtotime((string) $s['starts_on'])) : '—')
          . ' – ' . ($s['ends_on'] ? date('j M Y', strtotime((string) $s['ends_on'])) : '—');
    $actions = '<a class="btn btn-sm btn-primary" href="' . e($selfUrl) . '?clone=' . (int) $s['id'] . '">'
             . e(__('studio_season_dup', 'Duplicate')) . '</a> '
             . '<a class="btn btn-sm" href="' . e($selfUrl) . '?edit=' . (int) $s['id'] . '">' . e(__('edit', 'Edit')) . '</a> ';
    if (empty($s['is_current'])) {
        $actions .= '<button type="submit" name="_do" value="current_' . (int) $s['id'] . '" class="btn btn-sm">'
                  . e(__('studio_season_make_current', 'Make current')) . '</button> ';
    }
    $actions .= '<button type="submit" name="_do" value="delete_' . (int) $s['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
              . e(__('studio_season_confirm_del', 'Remove this season? Its classes are kept and simply unassigned.'))
              . '">' . e(__('delete', 'Delete')) . '</button>';

    studio_ui_row([
        '_id'     => (int) $s['id'],
        'avatar'  => (string) $s['name'],
        'title'   => (string) $s['name'],
        'meta'    => $span,
        'badge'   => !empty($s['is_current'])
                     ? [__('studio_season_current_b', 'Current'), 'active']
                     : [sprintf(__('studio_season_n_classes', '%d class(es)'), (int) $s['class_count']), 'inactive'],
        'value'   => (int) $s['class_count'] > 0 ? sprintf(__('studio_season_n_classes', '%d class(es)'), (int) $s['class_count']) : '',
        'actions' => $actions,
        '_search' => (string) $s['name'] . ' ' . $span,
    ]);
endforeach;
studio_ui_list_end(count($seasons), __('studio_no_seasons', 'No seasons yet'), [
    'icon'      => 'calendar',
    'sub'       => __('studio_no_seasons_sub',
        'A season groups a term\'s classes so next term can be duplicated instead of retyped.'),
    'cta_href'  => $selfUrl . '?new=1',
    'cta_label' => __('studio_season_new', 'New season'),
]);

require dirname(__DIR__, 3) . '/admin/partials/footer.php';
