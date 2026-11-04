<?php
/**
 * Studio public — catalog as a weekly grid. Scope: public/router.php.
 *
 * The whole timetable on one page: days across, hours down, every class with
 * its time and price in the cell. Built because the day-picker template makes
 * a parent tap Monday, Tuesday, Wednesday… to find out what a class costs,
 * and a price list nobody can see in one glance is not a price list.
 *
 * Two structural decisions.
 *
 * It is a real <table>. A timetable is tabular data — a screen reader should
 * announce "Monday, 4–5pm" for a cell, and rows must align across columns
 * without subgrid or JavaScript. Divs would have bought nothing here.
 *
 * Rows are the hours that actually contain a class, not a fixed 9-to-9 band.
 * A studio teaching Saturday mornings and weekday evenings gets both, with no
 * run of empty afternoon rows between them.
 *
 * Phones do not get a horizontal scroll — scrollbars are hidden app-wide, so
 * a 7-column grid would simply lose its right-hand days. Below 900px the same
 * markup restyles into one block per time slot, each class carrying its day.
 */
if (!defined('SLATE_ROOT')) { exit; }

use Slate\Module\Studio\Domain\DanceStyle;

$catalog = StudioAPI::getPublicCatalog();
$base    = SLATE_URL . '/studio';

$dayFull = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$styleLabel = static function (string $v): string {
    $c = DanceStyle::tryFrom($v);
    return $c ? $c->label() : ucwords(str_replace(['_', '-'], ' ', $v));
};
$money   = static fn (int $cents): string => '$' . number_format($cents / 100, 2);
$fmtTime = static function (string $t): string {
    $ts = strtotime('2000-01-01 ' . $t);
    return $ts ? date('g:i A', $ts) : $t;
};
/** "4–5pm" for the row stub. */
$hourLabel = static function (int $h): string {
    $a = strtotime('2000-01-01 ' . sprintf('%02d:00', $h % 24));
    $b = strtotime('2000-01-01 ' . sprintf('%02d:00', ($h + 1) % 24));
    return date('g', $a) . '–' . date('ga', $b);
};
/** Minutes between two H:i values. */
$mins = static function (string $a, string $b): int {
    [$ah, $am] = array_pad(explode(':', $a), 2, 0);
    [$bh, $bm] = array_pad(explode(':', $b), 2, 0);
    return max(0, ((int) $bh * 60 + (int) $bm) - ((int) $ah * 60 + (int) $am));
};

// ── Shape the grid ───────────────────────────────────────────
// [hour][day] => list of classes. Both axes are derived from the data, so a
// studio that never teaches before 4pm never renders a 9am row.
$slots = $hours = $days = [];
foreach ($catalog as $c) {
    $dow  = (int) $c['day_of_week'];
    $hour = (int) substr((string) $c['start_time'], 0, 2);
    $slots[$hour][$dow][] = $c;
    $hours[$hour] = true;
    $days[$dow]   = true;
}
ksort($hours);

// Monday first — a dance week starts on Monday, and Sunday trailing keeps the
// common case (Mon–Sat) reading left to right without a gap.
$dayOrder = [];
foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
    if (isset($days[$d])) { $dayOrder[] = $d; }
}

// Filter menus, same two axes as the day-picker template.
$styles = $instructors = [];
foreach ($catalog as $c) {
    $styles[(string) $c['style']] = $styleLabel((string) $c['style']);
    if ($c['instructor_name']) { $instructors[(int) $c['instructor_id']] = (string) $c['instructor_name']; }
}
asort($styles); asort($instructors);

$siteName = Database::setting('site_name') ?: __('studio', 'Studio');

slate_portal_welcome([
    'eyebrow' => $siteName,
    'title'   => __('studio_find_a_class', 'Find a Class'),
    'sub'     => __('studio_grid_sub', 'The whole week at a glance — every class, time and price.'),
    'status'  => $catalog
        ? ['label' => sprintf(__('studio_n_classes_open', '%d classes open'), count($catalog)), 'tone' => 'green']
        : ['label' => __('studio_none_open', 'Nothing open yet'), 'tone' => 'amber'],
]);
?>
<style>
/* The studio's own schedule palette — cream headers, blush cells, warm ink —
   rather than the brand token. The brand is a single pink; this design is
   three warm colours working against each other, and no amount of tinting
   one hue produces the cream. Kept as variables in one place so a studio
   that wants its own can be given them without touching the markup. */
.cg-wrap {
    --cg-panel:      #F7F1F1;
    --cg-head:       #FBF1D9;
    --cg-cell:       #F9E4E8;
    --cg-cell-empty: #FBEFF1;
    --cg-ink:        #3F3F46;
    --cg-ink-2:      #57534E;
    --cg-adult-bg:   #FFFDF7;
    --cg-adult-line: #C6A44E;
    --cg-adult-ink:  #A81D45;
    --cg-caption:    #78716C;
    margin-top: 6px;
}
.cg-filters { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 16px; }
.cg-select { position:relative; }
.cg-select select {
    appearance:none; -webkit-appearance:none; font:inherit; font-size:14px; font-weight:600; color:var(--text);
    padding:10px 38px 10px 16px; border:1px solid var(--border); border-radius:999px;
    background:var(--surface); cursor:pointer; min-width:150px;
}
.cg-select::after { content:"▾"; position:absolute; right:15px; top:50%; transform:translateY(-50%);
    pointer-events:none; color:var(--muted); font-size:12px; }
/* Focus rings carry the darkened ink too. A focus indicator has to clear 3:1
   against its background (WCAG 2.1 non-text contrast), and the raw brand pink
   manages about 2:1 — the ring was visible to me and not to everyone. */
.cg-select select:focus { outline:none; border-color:var(--accent-ink, var(--text-2,#3F4450));
    box-shadow:0 0 0 3px color-mix(in srgb, var(--accent-ink, #3F4450) 30%, transparent); }

/* The board the timetable sits on, so the grid reads as one object rather
   than as rows loose on the page background. */
.cg-board { background:var(--cg-panel); border-radius:22px; padding:14px; }

.cg { width:100%; border-collapse:separate; border-spacing:9px; table-layout:fixed; }
.cg thead th {
    padding:16px 10px; border-radius:14px; background:var(--cg-head);
    font-size:12px; font-weight:700; letter-spacing:.10em; text-transform:uppercase;
    color:var(--cg-ink); text-align:center; white-space:nowrap;
}
.cg thead th.cg-timecol { width:104px; }
.cg tbody th {
    border-radius:14px; background:var(--cg-head); text-align:center; vertical-align:middle;
    font-size:13.5px; font-weight:700; color:var(--cg-ink); white-space:nowrap; padding:12px 6px;
}
.cg td { vertical-align:top; padding:0; }

/* Centred, and flex so a one-line class and a three-line one still sit on the
   same optical centre of their cell. */
.cg-c {
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px;
    min-height:104px; padding:14px 12px; border-radius:14px; text-align:center;
    text-decoration:none; background:var(--cg-cell); border:1.5px solid transparent;
    transition:.15s;
}
.cg-c + .cg-c { margin-top:9px; }
.cg-c:hover { border-color:var(--cg-adult-line); transform:translateY(-1px); }
.cg-c:focus-visible { outline:2px solid var(--cg-ink); outline-offset:2px; }

.cg-name  { font-size:14px; font-weight:600; color:var(--cg-ink); line-height:1.4; }
.cg-time  { font-size:13px; font-weight:500; color:var(--cg-ink); }
.cg-ages  { font-size:12.5px; color:var(--cg-ink-2); }
.cg-price { margin-top:4px; font-size:13px; font-weight:700; color:var(--cg-ink);
    font-variant-numeric:tabular-nums; }
.cg-full  { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
    color:var(--cg-adult-ink); }

/* Adult classes are called out the way the studio's own timetable calls them
   out: gold rule, crimson name. Keyed on the age band, not the class name, so
   it does not depend on someone typing "Adult" into the title. */
.cg-c.is-adult { background:var(--cg-adult-bg); border-color:var(--cg-adult-line); }
.cg-c.is-adult .cg-name,
.cg-c.is-adult .cg-time { color:var(--cg-adult-ink); font-weight:700;
    text-transform:uppercase; letter-spacing:.02em; }

.cg-day-label { display:none; }
/* Empty slots are drawn, not left blank — they are what turns a scatter of
   cards back into a grid, and they let the eye track a row all the way
   across a day nothing is running. */
.cg-empty-cell { display:block; min-height:104px; border-radius:14px;
    background:var(--cg-cell-empty); }

.cg-caption { margin:14px 0 0; text-align:center; font-size:11.5px; font-weight:600;
    letter-spacing:.22em; text-transform:uppercase; color:var(--cg-caption); }

.cg-none { padding:38px 20px; text-align:center; color:var(--muted); font-size:14px; }

/* Phones: one block per time slot. The table markup is unchanged — only its
   display is — so there is no second copy of the timetable to keep in step. */
@media (max-width: 900px) {
    .cg-board { padding:10px; border-radius:18px; }
    .cg, .cg tbody, .cg tr, .cg td, .cg th { display:block; width:auto; }
    .cg { border-spacing:0; }
    .cg thead { display:none; }
    .cg tbody tr { margin-bottom:18px; }
    .cg tbody th { text-align:left; margin-bottom:8px; padding:10px 14px; font-size:12px;
        letter-spacing:.06em; text-transform:uppercase; }
    .cg td { margin-bottom:8px; }
    .cg-c { min-height:0; align-items:flex-start; text-align:left; }
    .cg-empty-cell { display:none; }
    .cg-day-label { display:block; font-size:11px; font-weight:700; letter-spacing:.06em;
        text-transform:uppercase; color:var(--cg-ink-2); margin-bottom:4px; }
}
</style>

<div class="cg-wrap">
    <?php studio_pub_subnav('classes'); ?>
    <?php if (!$catalog): ?>
        <div class="cg-none"><?= __('studio_catalog_empty', 'No classes are open right now. Please check back soon.') ?></div>
    <?php else: ?>

    <div class="cg-filters">
        <span class="cg-select"><select id="cg-style" aria-label="<?= e(__('studio_all_styles', 'All class types')) ?>">
            <option value=""><?= __('studio_all_styles', 'All class types') ?></option>
            <?php foreach ($styles as $v => $label): ?><option value="<?= e($v) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select></span>
        <span class="cg-select"><select id="cg-instr" aria-label="<?= e(__('studio_all_instructors', 'All instructors')) ?>">
            <option value=""><?= __('studio_all_instructors', 'All instructors') ?></option>
            <?php foreach ($instructors as $iid => $iname): ?><option value="<?= (int) $iid ?>"><?= e($iname) ?></option><?php endforeach; ?>
        </select></span>
    </div>

    <div class="cg-board">
    <table class="cg" id="cg-table" aria-label="<?= e(__('studio_grid_caption', 'Weekly class schedule')) ?>">
        <thead>
            <tr>
                <th class="cg-timecol" scope="col"><?= __('studio_time', 'Time') ?></th>
                <?php foreach ($dayOrder as $d): ?>
                    <th scope="col"><?= e(__('day_' . strtolower($dayFull[$d]), $dayFull[$d])) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_keys($hours) as $h): ?>
            <tr data-hour="<?= (int) $h ?>">
                <th scope="row"><?= e($hourLabel((int) $h)) ?></th>
                <?php foreach ($dayOrder as $d):
                    $cells = $slots[$h][$d] ?? [];
                ?>
                    <td data-day="<?= (int) $d ?>">
                        <?php if (!$cells): ?>
                            <span class="cg-empty-cell" aria-hidden="true"></span>
                        <?php else: ?>
                            <span class="cg-day-label"><?= e($dayFull[$d]) ?></span>
                            <?php foreach ($cells as $c):
                                $full  = (int) $c['open_spots'] <= 0;
                                $start = (string) $c['start_time'];
                                $end   = (string) $c['end_time'];
                                $len   = $mins($start, $end);
                                // The row stub already says "4–5pm". Repeat the
                                // time only when the class does not match it —
                                // a 6:00–7:30 class in the 6pm row must say so.
                                $offSlot = ((int) substr($start, 3, 2) !== 0) || $len !== 60;
                                // Keyed on the age band rather than the title:
                                // the studio's own timetable calls these out,
                                // and "Adult" being typed into the name is not
                                // something to depend on.
                                $isAdult = (int) $c['age_min'] >= 18;
                            ?>
                            <a class="cg-c<?= $isAdult ? ' is-adult' : '' ?>"
                               href="<?= e($base) ?>?view=class&amp;id=<?= (int) $c['id'] ?>"
                               data-style="<?= e((string) $c['style']) ?>"
                               data-instructor="<?= (int) $c['instructor_id'] ?>">
                                <span class="cg-name"><?= e((string) $c['name']) ?></span>
                                <?php if ($offSlot): ?>
                                    <span class="cg-time"><?= e($fmtTime($start)) ?>–<?= e($fmtTime($end)) ?></span>
                                <?php endif; ?>
                                <?php if ((int) $c['age_min'] > 0 || (int) $c['age_max'] > 0): ?>
                                    <span class="cg-ages"><?= __('studio_ages', 'Ages') ?>
                                        <?= (int) $c['age_min'] ?>–<?= (int) $c['age_max'] ?></span>
                                <?php endif; ?>
                                <span class="cg-price"><?= e($money((int) $c['price_cents'])) ?></span>
                                <?php if ($full): ?>
                                    <span class="cg-full"><?= __('studio_waitlist', 'Waitlist') ?></span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <p class="cg-caption"><?= __('studio_grid_caption', 'Weekly class schedule') ?></p>

    <div class="cg-none" id="cg-none" hidden>
        <p><?= __('studio_no_classes_filter', 'No classes match those filters.') ?></p>
        <button type="button" class="btn" id="cg-clear"><?= __('studio_clear_filters', 'Clear filters') ?></button>
    </div>

    <?php endif; ?>
</div>

<script>
(function () {
    var table = document.getElementById('cg-table');
    if (!table) { return; }
    var styleSel = document.getElementById('cg-style');
    var instrSel = document.getElementById('cg-instr');
    var noneEl   = document.getElementById('cg-none');
    var clearEl  = document.getElementById('cg-clear');
    var cards    = Array.prototype.slice.call(table.querySelectorAll('.cg-c'));
    var rows     = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));

    function apply() {
        var st = styleSel.value, ins = instrSel.value, shown = 0;

        cards.forEach(function (c) {
            var ok = (!st || c.dataset.style === st) && (!ins || c.dataset.instructor === ins);
            c.style.display = ok ? '' : 'none';
            if (ok) { shown++; }
        });

        // An hour with nothing left in it is noise, not information.
        rows.forEach(function (r) {
            var live = r.querySelectorAll('.cg-c:not([style*="none"])').length;
            r.style.display = live ? '' : 'none';
        });

        table.hidden = shown === 0;
        if (noneEl) { noneEl.hidden = shown !== 0; }
    }

    styleSel.addEventListener('change', apply);
    instrSel.addEventListener('change', apply);
    if (clearEl) {
        clearEl.addEventListener('click', function () {
            styleSel.value = ''; instrSel.value = ''; apply(); styleSel.focus();
        });
    }
    apply();
})();
</script>
