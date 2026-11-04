<?php
/**
 * Studio — admin overview.
 *
 * The landing page for the Studio section: at-a-glance counts (class series,
 * families, students, enrollments), each linking through to the screen that
 * owns it, plus links to the public pages. On a fresh install the counts are
 * replaced by an ordered getting-started checklist. Every query is
 * tenant-scoped and wrapped so all-zeros renders rather than erroring.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';

Auth::require();
Auth::requirePerm('studio.view_reports');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio', 'Studio');
$currentNav = 'studio';

$tid = current_tenant_id();

/** Small helper: a tenant-scoped COUNT that never throws on the page. */
$count = static function (string $sql) use ($tid): int {
    try { return (int) Database::value($sql, [$tid]); }
    catch (\Throwable $e) { return 0; }
};

$activeSeries  = $count("SELECT COUNT(*) FROM studio_class_series WHERE tenant_id = ? AND is_active = 1");
$families      = $count("SELECT COUNT(*) FROM studio_families WHERE tenant_id = ?");
$students      = $count("SELECT COUNT(DISTINCT contact_id) FROM studio_contact_roles WHERE tenant_id = ? AND role = 'student'");
$activeEnroll  = $count("SELECT COUNT(*) FROM studio_enrollments WHERE tenant_id = ? AND status IN ('active','trial')");
$waitlisted    = $count("SELECT COUNT(*) FROM studio_enrollments WHERE tenant_id = ? AND status = 'waitlist'");
$upcomingOcc   = $count("SELECT COUNT(*) FROM studio_class_occurrences WHERE tenant_id = ? AND status = 'scheduled' AND occurrence_date >= CURDATE()");

$hasData = ($activeSeries + $families + $students + $activeEnroll) > 0;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<style>
/* KPI tiles link to the screen that owns the number, so the overview is a way
   into the section rather than a dead end. */
.studio-kpi-link { display: block; text-decoration: none; color: inherit; border-radius: var(--radius); transition: .15s; }
.studio-kpi-link:hover { background: var(--surface-2); text-decoration: none; color: inherit; }
.studio-kpi-link:hover .dwidget-kpi-v { color: var(--accent-ink, var(--accent)); }
.studio-start { display: grid; gap: var(--space-3); grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
.studio-start-step { display: flex; gap: 12px; align-items: flex-start; }
.studio-start-n {
    width: 24px; height: 24px; flex: none; border-radius: 50%; display: grid; place-items: center;
    font-size: 12px; font-weight: 700; background: var(--accent-soft); color: var(--accent-ink, var(--accent));
}
.studio-start-t { font-size: 13.5px; font-weight: 650; }
.studio-start-s { font-size: 12.5px; color: var(--muted); margin: 3px 0 6px; }
</style>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('studio', 'Studio') ?></h1>
        <p class="page-header-sub"><?= __('studio_overview_sub', 'Classes, families, enrollment and attendance for your studio.') ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('studio', 'admin/classes.php')) ?>" class="btn"><?= __('studio_classes', 'Classes') ?></a>
        <a href="<?= e(plugin_url('studio', 'admin/enrollments.php')) ?>" class="btn"><?= __('studio_enrollments', 'Enrollments') ?></a>
        <a href="<?= e(plugin_url('studio', 'admin/families.php')) ?>" class="btn"><?= __('studio_families', 'Families') ?></a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><?= __('studio_at_a_glance', 'At a glance') ?></h2></div>
    <div class="dwidget-kpis">
        <?php
        $kpis = [
            [__('studio_active_classes', 'Active classes'),     $activeSeries, 'admin/classes.php'],
            [__('studio_families', 'Families'),                 $families,     'admin/families.php'],
            [__('studio_students', 'Students'),                 $students,     'admin/students.php'],
            [__('studio_enrolled', 'Active enrollments'),       $activeEnroll, 'admin/enrollments.php'],
            [__('studio_waitlist', 'Waitlisted'),               $waitlisted,   'admin/enrollments.php?tab=waitlist'],
            [__('studio_upcoming_sessions', 'Upcoming sessions'), $upcomingOcc, 'admin/attendance.php'],
        ];
        foreach ($kpis as [$label, $value, $href]):
        ?>
        <a class="dwidget-kpi studio-kpi-link" href="<?= e(plugin_url('studio', $href)) ?>">
            <div class="dwidget-kpi-k"><?= e($label) ?></div>
            <div class="dwidget-kpi-v"><?= (int) $value ?></div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$hasData): ?>
<div class="card">
    <div class="card-header"><h2><?= __('studio_getting_started', 'Getting started') ?></h2></div>
    <p class="text-sm text-muted" style="margin:0 0 var(--space-4)">
        <?= __('studio_no_data_body', 'Your studio is installed and empty. Work through these in order — each step feeds the next.') ?>
    </p>
    <div class="studio-start">
        <?php
        $steps = [
            [__('studio_step_instr', 'Add your instructors'), __('studio_step_instr_s', 'Staff you can assign to classes. Their photo shows on the public schedule.'), 'admin/instructors.php?new=1', __('studio_new_instructor_btn', 'New instructor')],
            [__('studio_step_class', 'Create class series'),   __('studio_step_class_s', 'A weekly slot with style, level, day, time and term dates.'), 'admin/classes.php?new=1', __('studio_new_class_btn', 'New class')],
            [__('studio_step_family', 'Register families'),    __('studio_step_family_s', 'Or let parents sign themselves up from the public schedule.'), 'admin/families.php?new=1', __('studio_new_family_btn', 'New family')],
            [__('studio_step_enroll', 'Enroll students'),      __('studio_step_enroll_s', 'Tuition, multi-class and sibling discounts are worked out for you.'), 'admin/enrollments.php?new=1', __('studio_new_enrollment_btn', 'Enroll student')],
        ];
        foreach ($steps as $i => [$title, $sub, $href, $cta]):
        ?>
        <div class="studio-start-step">
            <span class="studio-start-n"><?= $i + 1 ?></span>
            <div>
                <div class="studio-start-t"><?= e($title) ?></div>
                <div class="studio-start-s"><?= e($sub) ?></div>
                <a class="btn btn-sm" href="<?= e(plugin_url('studio', $href)) ?>"><?= e($cta) ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><?= __('studio_public_pages', 'Public pages') ?></h2></div>
    <p class="text-sm text-muted" style="margin:0 0 var(--space-3)">
        <?= __('studio_public_pages_sub', 'What families see. The schedule is open to everyone; the portal asks them to sign in.') ?>
    </p>
    <div class="toolbar">
        <a class="btn" href="<?= e(SLATE_URL) ?>/studio" target="_blank" rel="noopener"><?= __('studio_view_schedule', 'Find a Class') ?></a>
        <a class="btn" href="<?= e(SLATE_URL) ?>/studio?view=portal" target="_blank" rel="noopener"><?= __('studio_view_portal', 'Parent portal') ?></a>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
