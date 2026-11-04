<?php
/**
 * Studio — feature list and roadmap.
 *
 * What is built, what is coming, and what each gap actually is. Written after
 * a feature-by-feature comparison against Class Manager (classmanager.com),
 * the closest commercial equivalent, so the "coming" half is grounded in what
 * a studio owner will actually ask for rather than guesswork.
 *
 * The list is data, not prose: one array below, rendered. Update the array
 * when something ships — a roadmap that lives in someone's head goes stale in
 * a fortnight, and one that lives in a doc nobody links to goes stale in a
 * month. This one is in the admin nav.
 *
 * Status values:
 *   done    shipped and verified
 *   partial works, but not to the standard the comparison sets
 *   todo    not built; the note says what it means concretely
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.view_reports');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_roadmap', 'Studio · Features');
$currentNav = 'studio_roadmap';

/**
 * area => [ [status, title, note], … ]
 */
$FEATURES = [

    __('studio_rm_classes', 'Classes & scheduling') => [
        ['done',    'Class series with capacity, level, age band and style', ''],
        ['done',    'Weekly occurrence generation', 'Idempotent — re-running adds only newly in-range sessions.'],
        ['done',    'Waitlist with automatic promotion', 'Promotes on a drop and emails the parent.'],
        ['done',    'Room and instructor clash detection',
                    'Warns rather than blocks; a standing banner lists unresolved clashes. Rooms come from Booking.'],
        ['done',    'Featured image per class', ''],
        ['done',    'Attendance registers', ''],
        ['done',    'Seasons, and duplicate a class or a whole season',
                    'Shifts by whole weeks so a Monday class stays on Monday, and says how far that lands from the date you picked. Enrolments are never copied.'],
        ['done',    'Cancel a single lesson, mark holidays',
                    'Closure dates never generate a lesson; adding one later cancels what exists. Per-lesson and bulk cancel, with the reason kept.'],
        ['done',    'Parent schedule — next 7 days',
                    'Dated lessons, not the weekly pattern. Cancelled ones stay visible, struck through, with the reason.'],
        ['done',    'Class descriptions, location, virtual classes',
                    'Description and location on the public class page. The online-class link is shown only to enrolled families, never in the public HTML.'],
        ['todo',    'Colour-coded classes on the timetable', 'Images yes, colour coding no.'],
    ],

    __('studio_rm_enrol', 'Enrolment') => [
        ['done',    'Public class catalog with type and instructor filters', ''],
        ['done',    'Choosable catalog layout',
                    'Day picker or a full weekly grid that puts every class, time and price on one page. Chosen in Settings and previewable without switching parents over; a third layout is one file.'],
        ['done',    'Self-serve registration', ''],
        ['done',    'Liability waiver via Forms', 'Prompted, never enforced — it must not block a parent from paying.'],
        ['partial', 'Trials',
                    'The trial status exists on an enrolment, but a parent cannot book one and there is no trial price, expiry or count.'],
        ['todo',    'Shareable booking links and an enrolment on/off switch',
                    'Catalog is public and linkable, but there is no copy-link action, no embed snippet, and no way to close online enrolment.'],
        ['todo',    'Make-up credits', 'A missed lesson produces nothing. No credit ledger.'],
    ],

    __('studio_rm_money', 'Money') => [
        ['done',    'Tuition with multi-class and sibling discount curves', 'Tiers are configurable per studio.'],
        ['done',    'Stripe checkout for tuition', 'Self-reconciles on return, so it works despite the webhook.'],
        ['done',    'Fee ledger — registration, recital, costumes, tights',
                    'Costumes split across instalments; exemptions for technique and ballet 8+.'],
        ['done',    'Fees visible to parents and collectable by staff', ''],
        ['done',    'Automated payment reminders',
                    'Scheduled −7 / 0 / +7 / +14 / +30 days, one email per family, never repeating a step.'],
        ['done',    'Registration fee',
                    'Raised once per FAMILY on their first real enrolment — not per dancer, not per term, and never on a waitlisted place.'],
        ['done',    'Tuition payment plans',
                    'In advance / current month / in arrears, split into monthly instalments that always re-sum to the quoted total.'],
        ['done',    'Hand-raised fees',
                    'For what the generators cannot know in advance — the policy\'s competition costume and crystal fees. Billed to the dancer\'s own family, derived rather than posted.'],
        ['todo',    'Apple Pay, Google Pay and saved cards',
                    'Stripe supports it and the toggles exist, but they are off and no card is ever stored. Blocked on the webhook.'],
        ['partial', 'Itemised checkout summary',
                    'tuitionQuote() itemises list price, discount and registration and reconciles to the total; the Stripe checkout page does not render it yet.'],
    ],

    __('studio_rm_comms', 'Communication') => [
        ['done',    'Branded transactional email', 'Enrolment, waitlist promotion, payment receipt.'],
        ['done',    'Targeted announcements with send history',
                    'All families, one class, families with a balance, or an age band. A row per recipient, so "I never got it" is a lookup.'],
        ['todo',    'Two-way messaging with families',
                    'The Coaching plugin already has threaded chat with unread counts, photos and scheduled sends — the plan is to promote it to core rather than rebuild.'],
        ['todo',    'Customer-facing notification feed',
                    'The admin bell exists in core; there is no equivalent for parents, and the store has no per-recipient targeting.'],
    ],

    __('studio_rm_recital', 'Recitals') => [
        ['done',    'Recitals, running order and ticket sales', 'Seat tracking and Stripe ticket checkout.'],
        ['done',    'Costume records with sizing and fulfilment status', ''],
        ['done',    'Costume billing',
                    'One place for money: the fee ledger prices costumes, the wardrobe records handle sizing and fulfilment. They used to bill the same $95 twice.'],
    ],

    __('studio_rm_people', 'People & reporting') => [
        ['done',    'Families, students and instructors', ''],
        ['done',    'Student and instructor profiles with photos', ''],
        ['done',    'Revenue, enrolment and attendance reports', ''],
        ['done',    'Published policies page', 'Fee figures render from the billing settings, so they cannot drift.'],
        ['todo',    'Teacher logins',
                    'Instructors are contacts with no login. A teacher cannot take their own register.'],
    ],
];

$flash = studio_take_flash();
require dirname(__DIR__, 3) . '/admin/partials/header.php';
studio_ui_css();

$tally = ['done' => 0, 'partial' => 0, 'todo' => 0];
foreach ($FEATURES as $rows) {
    foreach ($rows as [$st, , ]) { $tally[$st] = ($tally[$st] ?? 0) + 1; }
}
$total = array_sum($tally);
?>
<style>
.rm-legend { display:flex; gap:18px; flex-wrap:wrap; margin:0 0 4px; font-size:13px; }
.rm-bar { height:8px; border-radius:999px; overflow:hidden; display:flex; margin:12px 0 0; background:var(--surface-2,#F1F5F9); }
.rm-bar i { display:block; height:100%; }
.rm-group { margin-bottom:22px; }
.rm-item { display:flex; gap:12px; align-items:flex-start; padding:10px 0; border-bottom:1px solid var(--line,#ECEEF1); }
.rm-item:last-child { border-bottom:0; }
.rm-mark { flex:none; width:20px; height:20px; border-radius:6px; display:grid; place-items:center;
    font-size:12px; font-weight:800; margin-top:1px; }
.rm-done    { background:#DCFCE7; color:#15803D; }
.rm-partial { background:#FEF3C7; color:#B45309; }
.rm-todo    { background:var(--surface-2,#F1F5F9); color:#64748B; }
.rm-title { font-size:14px; font-weight:600; color:var(--ink,#15181E); }
.rm-note  { font-size:13px; color:var(--muted,#64748B); margin-top:2px; line-height:1.5; }
.rm-item.is-todo .rm-title { color:var(--muted,#64748B); font-weight:550; }
</style>

<?php if ($flash): ?><div class="alert alert-<?= e((string) $flash['type']) ?>"><?= e((string) $flash['msg']) ?></div><?php endif; ?>

<div class="app-panel" style="margin-bottom:18px">
    <div class="card-header"><h2><?= __('studio_rm_h', 'What Studio does, and what is coming') ?></h2></div>
    <div class="card-body">
        <p style="margin:0 0 12px;color:var(--muted);font-size:14px;max-width:70ch">
            <?= __('studio_rm_intro',
                'Compared feature by feature against Class Manager, the closest commercial equivalent, so the gaps below are the ones a studio owner will actually ask about.') ?>
        </p>
        <div class="rm-legend">
            <span><span class="rm-mark rm-done" style="display:inline-grid">✓</span>
                <?= sprintf(__('studio_rm_built', '%d built'), $tally['done']) ?></span>
            <span><span class="rm-mark rm-partial" style="display:inline-grid">~</span>
                <?= sprintf(__('studio_rm_part', '%d partial'), $tally['partial']) ?></span>
            <span><span class="rm-mark rm-todo" style="display:inline-grid">·</span>
                <?= sprintf(__('studio_rm_todo', '%d coming'), $tally['todo']) ?></span>
        </div>
        <div class="rm-bar">
            <i style="width:<?= round($tally['done'] / max(1, $total) * 100, 1) ?>%;background:#22C55E"></i>
            <i style="width:<?= round($tally['partial'] / max(1, $total) * 100, 1) ?>%;background:#F59E0B"></i>
        </div>
    </div>
</div>

<?php foreach ($FEATURES as $area => $rows): ?>
<div class="app-panel rm-group">
    <div class="card-header"><h2><?= e($area) ?></h2></div>
    <div class="card-body">
        <?php foreach ($rows as [$status, $title, $note]): ?>
            <div class="rm-item <?= $status === 'todo' ? 'is-todo' : '' ?>">
                <span class="rm-mark rm-<?= e($status) ?>"><?=
                    $status === 'done' ? '✓' : ($status === 'partial' ? '~' : '·') ?></span>
                <div style="min-width:0">
                    <div class="rm-title"><?= e($title) ?></div>
                    <?php if ($note !== ''): ?><div class="rm-note"><?= e($note) ?></div><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php require dirname(__DIR__, 3) . '/admin/partials/footer.php'; ?>
