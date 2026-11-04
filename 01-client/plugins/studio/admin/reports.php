<?php
/**
 * Studio — admin Reports.
 *
 * The three questions a studio owner actually asks: what have I taken and what
 * am I owed, how full are my classes, and is the register being kept.
 *
 * Deliberately shows the denominator everywhere. An attendance rate off three
 * marks is not a 100% attendance rate, and a fill percentage means nothing
 * without the capacity beside it — reports that hide their sample size are how
 * people end up confidently wrong.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.view_reports');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_reports', 'Studio · Reports');
$currentNav = 'studio_reports';

$rev  = StudioAPI::reportRevenue();
$enr  = StudioAPI::reportEnrollment();
$att  = StudioAPI::reportAttendance();

$money = static fn (int $cents): string => '$' . number_format($cents / 100, 2);
$billed = $rev['collected_cents'] + $rev['outstanding_cents'];
$collectedPct = $billed > 0 ? (int) round(($rev['collected_cents'] / $billed) * 100) : 0;

require SLATE_ROOT . '/admin/partials/header.php';
studio_ui_css();
?>

<style>
.srep-bar { height:8px; border-radius:999px; background:var(--surface-sunken,var(--surface-2)); overflow:hidden; }
.srep-bar > i { display:block; height:100%; border-radius:999px; background:var(--accent); }
.srep-bar.is-full > i { background:var(--warning); }
.srep-row { display:flex; align-items:center; gap:14px; padding:11px 0; border-bottom:1px solid var(--border); }
.srep-row:last-child { border-bottom:0; }
.srep-name { flex:1 1 40%; min-width:0; font-weight:600; font-size:13.5px; overflow-wrap:anywhere; }
.srep-meter { flex:1 1 30%; min-width:120px; }
.srep-num { flex:0 0 auto; font-size:13px; color:var(--muted); font-variant-numeric:tabular-nums; white-space:nowrap; }
.srep-empty { color:var(--muted); font-size:13px; padding:14px 0; }
.srep-note { font-size:12.5px; color:var(--muted); margin:10px 0 0; }
.srep-split { display:grid; gap:var(--space-4); grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); }
</style>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('studio_reports_h', 'Reports')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('studio_reports_h', 'Reports') ?></h1>
        <p class="page-header-sub"><?= __('studio_reports_sub', 'Revenue, enrolment and attendance across the studio.') ?></p>
    </div>
</div>

<!-- ── Headline numbers ─────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h2><?= __('studio_rep_money', 'Tuition') ?></h2></div>
    <div class="dwidget-kpis">
        <div class="dwidget-kpi">
            <div class="dwidget-kpi-k"><?= __('studio_rep_collected', 'Collected') ?></div>
            <div class="dwidget-kpi-v"><?= e($money($rev['collected_cents'])) ?></div>
            <div class="dwidget-kpi-note"><?= (int) $rev['paid_count'] ?> <?= __('studio_rep_paid_enr', 'paid enrolments') ?></div>
        </div>
        <div class="dwidget-kpi">
            <div class="dwidget-kpi-k"><?= __('studio_rep_outstanding', 'Outstanding') ?></div>
            <div class="dwidget-kpi-v"><?= e($money($rev['outstanding_cents'])) ?></div>
            <div class="dwidget-kpi-note"><?= (int) $rev['unpaid_count'] ?> <?= __('studio_rep_awaiting', 'awaiting payment') ?></div>
        </div>
        <div class="dwidget-kpi">
            <div class="dwidget-kpi-k"><?= __('studio_rep_billed', 'Billed to date') ?></div>
            <div class="dwidget-kpi-v"><?= e($money($billed)) ?></div>
            <div class="dwidget-kpi-note"><?= (int) $collectedPct ?>% <?= __('studio_rep_collected_lc', 'collected') ?></div>
        </div>
    </div>
    <p class="srep-note">
        <?= __('studio_rep_money_note', 'Outstanding is the discounted tuition of every active enrolment that has not been paid, priced by the same rules that bill it.') ?>
    </p>
</div>

<div class="srep-split">
    <!-- ── Revenue by month ─────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h2><?= __('studio_rep_by_month', 'Collected by month') ?></h2></div>
        <?php if (!$rev['by_month']): ?>
            <div class="srep-empty"><?= __('studio_rep_no_payments', 'No payments recorded yet.') ?></div>
        <?php else: $peak = max($rev['by_month']) ?: 1; ?>
            <?php foreach ($rev['by_month'] as $month => $cents): ?>
                <div class="srep-row">
                    <span class="srep-name"><?= e($month !== '' ? date('F Y', strtotime($month . '-01')) : __('studio_unknown', 'Unknown')) ?></span>
                    <span class="srep-meter"><span class="srep-bar"><i style="width:<?= (int) round(($cents / $peak) * 100) ?>%"></i></span></span>
                    <span class="srep-num"><?= e($money((int) $cents)) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── Revenue by class ─────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h2><?= __('studio_rep_by_class', 'Tuition by class') ?></h2></div>
        <?php if (!$rev['by_class']): ?>
            <div class="srep-empty"><?= __('studio_rep_no_enrolments', 'No enrolments yet.') ?></div>
        <?php else: ?>
            <?php foreach (array_slice($rev['by_class'], 0, 8, true) as $name => $c):
                $total = $c['collected_cents'] + $c['outstanding_cents'];
                $pct   = $total > 0 ? (int) round(($c['collected_cents'] / $total) * 100) : 0;
            ?>
                <div class="srep-row">
                    <span class="srep-name"><?= e($name) ?></span>
                    <span class="srep-meter"><span class="srep-bar"><i style="width:<?= $pct ?>%"></i></span></span>
                    <span class="srep-num"><?= e($money($c['collected_cents'])) ?> / <?= e($money($total)) ?></span>
                </div>
            <?php endforeach; ?>
            <p class="srep-note"><?= __('studio_rep_class_note', 'Collected against billed. A short bar is money still owed on that class.') ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- ── Enrolment ────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h2><?= __('studio_rep_enrolment', 'Enrolment') ?></h2></div>
    <div class="dwidget-kpis">
        <div class="dwidget-kpi">
            <div class="dwidget-kpi-k"><?= __('studio_rep_enrolled', 'Enrolled') ?></div>
            <div class="dwidget-kpi-v"><?= (int) $enr['active'] ?></div>
        </div>
        <div class="dwidget-kpi">
            <div class="dwidget-kpi-k"><?= __('studio_waitlist', 'Waitlisted') ?></div>
            <div class="dwidget-kpi-v"><?= (int) $enr['waitlist'] ?></div>
            <?php if ($enr['waitlist'] > 0): ?>
                <div class="dwidget-kpi-note"><?= __('studio_rep_wait_note', 'demand for another section') ?></div>
            <?php endif; ?>
        </div>
        <div class="dwidget-kpi">
            <div class="dwidget-kpi-k"><?= __('studio_dropped', 'Dropped') ?></div>
            <div class="dwidget-kpi-v"><?= (int) $enr['dropped'] ?></div>
        </div>
    </div>

    <h3 class="studio-section-heading"><?= __('studio_rep_fill', 'How full each class is') ?></h3>
    <?php if (!$enr['classes']): ?>
        <div class="srep-empty"><?= __('studio_no_classes', 'No classes yet') ?></div>
    <?php else: foreach ($enr['classes'] as $c): ?>
        <div class="srep-row">
            <span class="srep-name">
                <?= e($c['name']) ?>
                <?php if (!$c['is_active']): ?>
                    <span class="badge badge-inactive" style="margin-left:6px"><?= __('inactive', 'Inactive') ?></span>
                <?php endif; ?>
            </span>
            <span class="srep-meter">
                <span class="srep-bar <?= $c['fill_pct'] >= 100 ? 'is-full' : '' ?>">
                    <i style="width:<?= min(100, (int) $c['fill_pct']) ?>%"></i>
                </span>
            </span>
            <span class="srep-num">
                <?= (int) $c['enrolled'] ?>/<?= (int) $c['capacity'] ?>
                <?php if ($c['waitlist'] > 0): ?>
                    · <?= (int) $c['waitlist'] ?> <?= __('studio_rep_waiting', 'waiting') ?>
                <?php endif; ?>
            </span>
        </div>
    <?php endforeach; endif; ?>
</div>

<!-- ── Attendance ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h2><?= __('studio_attendance', 'Attendance') ?></h2></div>
    <?php
    $marks = $att['present'] + $att['late'] + $att['absent'] + $att['excused'];
    $unmarked = max(0, (int) $att['sessions_past'] - (int) $att['sessions_marked']);
    ?>
    <?php if ($marks === 0): ?>
        <div class="srep-empty">
            <?= __('studio_rep_no_attendance', 'No attendance has been marked yet — take a register from the Attendance page and this fills in.') ?>
        </div>
    <?php else: ?>
        <div class="dwidget-kpis">
            <div class="dwidget-kpi">
                <div class="dwidget-kpi-k"><?= __('studio_rep_turnout', 'Turnout') ?></div>
                <div class="dwidget-kpi-v"><?= (int) $att['rate'] ?>%</div>
                <div class="dwidget-kpi-note"><?= __('studio_rep_of_marks', 'of') ?> <?= (int) $marks ?> <?= __('studio_rep_marks', 'marks') ?></div>
            </div>
            <div class="dwidget-kpi">
                <div class="dwidget-kpi-k"><?= __('studio_present', 'Present') ?> / <?= __('studio_late', 'Late') ?></div>
                <div class="dwidget-kpi-v"><?= (int) $att['present'] ?> / <?= (int) $att['late'] ?></div>
            </div>
            <div class="dwidget-kpi">
                <div class="dwidget-kpi-k"><?= __('studio_absent', 'Absent') ?> / <?= __('studio_excused', 'Excused') ?></div>
                <div class="dwidget-kpi-v"><?= (int) $att['absent'] ?> / <?= (int) $att['excused'] ?></div>
            </div>
        </div>

        <?php if ($att['classes']): ?>
            <h3 class="studio-section-heading"><?= __('studio_rep_by_class_att', 'Turnout by class') ?></h3>
            <?php foreach ($att['classes'] as $c): ?>
                <div class="srep-row">
                    <span class="srep-name"><?= e($c['name']) ?></span>
                    <span class="srep-meter"><span class="srep-bar"><i style="width:<?= (int) $c['rate'] ?>%"></i></span></span>
                    <span class="srep-num"><?= (int) $c['rate'] ?>% <?= __('studio_rep_of', 'of') ?> <?= (int) $c['marked'] ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($unmarked > 0): ?>
        <p class="srep-note">
            <?= e(sprintf(__('studio_rep_unmarked', '%d past session(s) have no register taken, so they count towards nothing above.'), $unmarked)) ?>
            <a href="<?= e(plugin_url('studio', 'admin/attendance.php')) ?>"><?= __('studio_rep_take_register', 'Take a register') ?></a>
        </p>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
