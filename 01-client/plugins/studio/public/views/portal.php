<?php
/**
 * Studio public — parent dashboard. Scope: public/router.php (login required).
 *
 * Renders inside the shared customer portal shell (includes/portal_shell.php),
 * so it carries the same navigation and component kit as /customer and /member
 * rather than its own chrome. Uses the app kit vocabulary: pcard / kvr / pill /
 * mbtn / stat, all from assets/css/portal-app.css.
 */
if (!defined('SLATE_ROOT')) { exit; }

$base = SLATE_URL . '/studio';
$cid  = (int) Auth::customerId();
$data = StudioAPI::getParentPortal($cid);
$cust = Auth::customer();
$name = trim((string) ($cust['name'] ?? '')) ?: __('studio_parent', 'Parent');
$days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$money = static fn (int $cents): string => '$' . number_format($cents / 100, 2);
$fmt   = static fn (string $t): string => substr($t, 0, 5);

$students   = $data['students'];
$totalCents = 0;
$dueCents   = 0;
$activeN    = 0;
foreach ($students as $st) {
    foreach ($st['enrollments'] as $en) {
        if ($en['status'] === 'waitlist') { continue; }
        $totalCents += (int) $en['tuition_cents'];
        $activeN++;
        if (empty($en['paid'])) { $dueCents += (int) $en['tuition_cents']; }
    }
}

// Fees are the money billed ALONGSIDE tuition — costumes, tights, the recital
// performance fee. They live in studio_fees rather than on an enrolment
// because a recital fee belongs to the family and a costume fee is split
// across instalments months apart. See 0007_studio_fees.
$familyId  = (int) ($data['family']['id'] ?? 0);
$fees      = $familyId ? StudioAPI::feesForFamily($familyId) : [];
$feesDue   = $familyId ? StudioAPI::feesOutstandingForFamily($familyId) : 0;
$owedTotal = $dueCents + $feesDue;

// Group by due date: a parent owing eleven costume instalments wants to see
// "1 November — $105", not eleven rows. Paid rows stay visible so the page
// answers "what happened to that $95" as well as "what do I owe".
$feesByDue = [];
foreach ($fees as $f) {
    if (($f['status'] ?? '') === 'void') { continue; }
    $feesByDue[(string) ($f['due_date'] ?? '')][] = $f;
}
ksort($feesByDue);

// The next date with something still OWED — not simply the earliest date on
// the ledger, which would keep pointing at a November instalment the parent
// settled months ago.
$nextDue = '';
foreach ($feesByDue as $due => $group) {
    foreach ($group as $f) {
        if (($f['status'] ?? '') === 'pending' && $due !== '') { $nextDue = $due; break 2; }
    }
}
?>

<?php
slate_portal_welcome([
    'eyebrow' => __('studio_my_studio', 'My studio'),
    'title'   => __('studio_welcome', 'Welcome'),
    'name'    => explode(' ', $name)[0],
    'sub'     => __('studio_portal_sub', 'Your dancers, their classes, and tuition at a glance.'),
    // Reflects tuition AND fees. Reporting only tuition told a parent who owed
    // $455 in costumes that they were "up to date".
    'status'  => $owedTotal > 0
        ? ['label' => sprintf(__('studio_owed_label', '%s outstanding'), $money($owedTotal)), 'tone' => 'amber']
        : ['label' => __('studio_all_paid', 'All paid up'),                                   'tone' => 'green'],
    'clock'   => true,
]);

$firstDancer = $students[0]['name'] ?? '';
?>

<div>
<?php slate_portal_stats([
    ['label' => __('studio_dancers', 'Dancers'), 'value' => (string) count($students), 'icon' => 'users',
     'sub'   => $firstDancer !== '' ? e($firstDancer) . (count($students) > 1 ? ' +' . (count($students) - 1) : '') : ''],
    ['label' => __('studio_enrolled_classes', 'Enrolled classes'), 'value' => (string) $activeN, 'icon' => 'music',
     'sub'   => __('studio_this_term', 'This term'), 'href' => $base],
    ['label' => __('studio_tuition_total', 'Tuition / term'), 'value' => $money($totalCents), 'icon' => 'card',
     'sub'   => $dueCents > 0 ? sprintf(__('studio_of_which_due', '%s outstanding'), $money($dueCents))
                              : __('studio_paid_in_full', 'Paid in full')],
    ['label' => __('studio_fees_due', 'Costumes & fees'), 'value' => $money($feesDue), 'icon' => 'tag',
     'sub'   => $feesDue > 0 && $nextDue !== ''
        ? sprintf(__('studio_fees_next', 'Next due %s'), date('j M', strtotime($nextDue)))
        : ($feesDue > 0 ? __('studio_fees_undated', 'No due date')
                        : __('studio_fees_none', 'Nothing outstanding'))],
]); ?>
</div>

<div class="dash">
    <div class="dash-col">
        <?php
        // The next seven days as DATED lessons, not the weekly pattern. The
        // cards below answer "what is my dancer enrolled in"; this answers
        // "where do I need to be on Tuesday", which is the question a parent
        // actually opens the portal for.
        //
        // Cancelled lessons are shown, struck through, on purpose. Hiding one
        // is indistinguishable from the class never existing, and a parent who
        // turns up to a cancelled lesson is exactly the failure this prevents.
        $agenda = StudioAPI::upcomingLessonsForParent($cid, 7);
        if ($agenda):
            $byDay = [];
            foreach ($agenda as $l) { $byDay[(string) $l['occurrence_date']][] = $l; }
        ?>
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('studio_schedule', 'Schedule') ?></p>
            <p class="pcard-title" style="margin-bottom:14px"><?= __('studio_next_7', 'Next 7 days') ?></p>

            <?php foreach ($byDay as $day => $lessons):
                $ts    = strtotime($day);
                $isTod = $day === date('Y-m-d');
            ?>
                <div style="margin-bottom:14px">
                    <div style="font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
                                color:<?= $isTod ? 'var(--accent-ink)' : 'var(--m-muted,#737886)' ?>;margin-bottom:6px">
                        <?= $isTod ? e(__('studio_today', 'Today')) : e(date('D j M', $ts)) ?>
                        <span style="font-weight:600;text-transform:none;letter-spacing:0">
                            · <?= count($lessons) ?> <?= count($lessons) === 1
                                ? __('studio_class_lc', 'class') : __('studio_classes_lc', 'classes') ?>
                        </span>
                    </div>

                    <?php foreach ($lessons as $l):
                        $off = (string) $l['status'] === 'cancelled';
                    ?>
                        <div class="kvr" style="align-items:flex-start<?= $off ? ';opacity:.72' : '' ?>">
                            <span class="kvr-k" style="white-space:nowrap;font-variant-numeric:tabular-nums">
                                <?= e(substr((string) $l['start_time'], 0, 5)) ?>
                            </span>
                            <span class="kvr-v" style="text-align:left">
                                <span style="<?= $off ? 'text-decoration:line-through' : 'font-weight:600' ?>">
                                    <?= e((string) $l['series_name']) ?>
                                </span>
                                <?php if ($off): ?>
                                    <span class="pill pill-amber"><?= __('cancelled', 'Cancelled') ?></span>
                                <?php endif; ?>
                                <span style="display:block;font-size:12.5px;color:var(--m-muted,#737886)">
                                    <?= e((string) $l['student_name']) ?><?php
                                    if (($l['instructor_name'] ?? '') !== '') {
                                        echo ' · ' . e((string) $l['instructor_name']);
                                    }
                                    if ($off && trim((string) ($l['note'] ?? '')) !== '') {
                                        echo ' · ' . e((string) $l['note']);
                                    }
                                    ?>
                                </span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if (!$students): ?>
            <section class="pcard">
                <p class="pcard-eyebrow"><?= __('studio_dancers', 'Dancers') ?></p>
                <p style="margin:0 0 16px;font-size:14px;color:var(--m-muted)">
                    <?= __('studio_no_dancers_sub', 'Register for a class to add your first dancer.') ?>
                </p>
                <a class="mbtn mbtn-primary" href="<?= e($base) ?>"><?= __('studio_browse_classes', 'Browse classes') ?></a>
            </section>
        <?php else: ?>
            <?php foreach ($students as $st): ?>
                <?php $sName = (string) ($st['name'] ?: ('#' . $st['id'])); ?>
                <section class="pcard">
                    <p class="pcard-eyebrow"><?= e($sName) ?></p>
                    <?php if (!$st['enrollments']): ?>
                        <p style="margin:0;font-size:14px;color:var(--m-muted)">
                            <?= __('studio_not_enrolled', 'Not enrolled in any classes yet.') ?>
                            <a href="<?= e($base) ?>"><?= __('studio_find_class', 'Find a class') ?></a>
                        </p>
                    <?php else: ?>
                        <?php foreach ($st['enrollments'] as $en):
                            $dow  = (int) $en['day_of_week'];
                            $wait = $en['status'] === 'waitlist';
                            $paid = !empty($en['paid']);
                        ?>
                        <div class="kvr">
                            <span class="kvr-k">
                                <strong style="display:block;color:var(--m-ink);font-size:14.5px"><?= e($en['series_name']) ?></strong>
                                <?= e(($days[$dow] ?? '') . ' ' . $fmt((string) $en['start_time']) . '–' . $fmt((string) $en['end_time'])) ?>
                            </span>
                            <span class="kvr-v">
                                <?php if ($wait): ?>
                                    <span class="pill pill-amber"><?= __('studio_waitlisted_s', 'Waitlisted') ?></span>
                                <?php elseif ($paid): ?>
                                    <?= e($money((int) $en['tuition_cents'])) ?>
                                    <span class="pill pill-green"><?= __('studio_paid', 'Paid') ?></span>
                                <?php else: ?>
                                    <?= e($money((int) $en['tuition_cents'])) ?>
                                    <form method="post" action="<?= e($base) ?>?view=pay" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="enrollment_id" value="<?= (int) $en['id'] ?>">
                                        <button type="submit" class="mbtn mbtn-primary" style="min-height:36px;padding:7px 14px;font-size:13.5px">
                                            <?= __('studio_pay_now', 'Pay now') ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="dash-col">
        <?php if ($feesByDue): ?>
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('studio_fees', 'Costumes & fees') ?></p>
            <p class="pcard-title" style="margin-bottom:2px">
                <?= $feesDue > 0 ? e($money($feesDue)) : __('studio_fees_settled', 'All settled') ?>
            </p>
            <p style="margin:0 0 14px;font-size:13px;color:var(--m-muted)">
                <?= __('studio_fees_sub', 'Billed alongside tuition. Costume fees are split across two payments.') ?>
            </p>

            <?php foreach ($feesByDue as $due => $group):
                $groupDue = 0;
                foreach ($group as $f) { if ($f['status'] === 'pending') { $groupDue += (int) $f['amount_cents']; } }
                $allPaid = $groupDue === 0;
            ?>
                <div class="kvr" style="align-items:baseline">
                    <span style="font-weight:650">
                        <?= $due !== '' ? e(date('j F Y', strtotime($due))) : __('studio_fee_no_date', 'No date') ?>
                    </span>
                    <span>
                        <?php if ($allPaid): ?>
                            <span class="pill pill-green"><?= __('studio_paid', 'Paid') ?></span>
                        <?php else: ?>
                            <strong><?= e($money($groupDue)) ?></strong>
                        <?php endif; ?>
                    </span>
                </div>
                <ul style="margin:2px 0 12px;padding:0 0 0 2px;list-style:none">
                    <?php foreach ($group as $f): ?>
                        <li style="display:flex;justify-content:space-between;gap:10px;
                                   padding:3px 0;font-size:12.5px;color:var(--m-muted)">
                            <span><?= e((string) $f['label']) ?></span>
                            <span style="white-space:nowrap<?= $f['status'] === 'paid' ? ';text-decoration:line-through' : '' ?>">
                                <?= e($money((int) $f['amount_cents'])) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>

            <p style="margin:2px 0 0;font-size:12px;color:var(--m-muted)">
                <?= __('studio_fees_pay_note', 'Fees are collected at the studio. Speak to the front desk to settle a balance.') ?>
            </p>
        </section>
        <?php endif; ?>

        <?php
        // Waiver: prompted, never enforced here. Blocking the portal on a
        // signature would lock a parent out of paying tuition they already owe.
        $waiver = StudioAPI::waiverForm();
        if ($waiver !== null && !StudioAPI::waiverSignedBy((string) ($cust['email'] ?? ''))):
        ?>
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('studio_waiver', 'Liability waiver') ?></p>
            <p class="pcard-title" style="margin-bottom:2px"><?= e((string) $waiver['title']) ?></p>
            <p style="margin:0 0 14px;font-size:13.5px;color:var(--m-muted)">
                <?= __('studio_waiver_prompt', 'We don\'t have your signed waiver yet. It takes a minute and only needs doing once.') ?>
            </p>
            <a class="mbtn mbtn-primary mbtn-block" href="<?= e(SLATE_URL . '/forms/' . rawurlencode((string) $waiver['slug'])) ?>">
                <?= __('studio_waiver_sign', 'Sign the waiver') ?>
            </a>
        </section>
        <?php endif; ?>

        <?php
        // Upcoming shows the family's dancers are actually in. Published only —
        // recitalsForParent filters drafts out, so a season being sketched
        // doesn't leak to parents.
        $recitals = StudioAPI::recitalsForParent($cid);
        foreach ($recitals as $rec):
        ?>
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('studio_recital', 'Recital') ?></p>
            <p class="pcard-title" style="margin-bottom:2px"><?= e((string) $rec['name']) ?></p>
            <p style="margin:0 0 14px;font-size:13px;color:var(--m-muted)">
                <?= $rec['recital_date'] ? e(date('l, j F Y', strtotime((string) $rec['recital_date']))) : '' ?>
                <?php if ($rec['venue']): ?> · <?= e((string) $rec['venue']) ?><?php endif; ?>
            </p>
            <div class="kvr">
                <span class="kvr-k"><?= __('studio_your_dancers', 'Your dancers') ?></span>
                <span class="kvr-v"><?= count($rec['performers']) ?></span>
            </div>
            <div class="kvr">
                <span class="kvr-k"><?= __('studio_you_hold', 'Tickets held') ?></span>
                <span class="kvr-v">
                    <?= (int) $rec['tickets_held'] ?>
                    <?php if ((int) $rec['tickets_held'] === 0): ?>
                        <span class="pill pill-amber"><?= __('studio_none_yet', 'none yet') ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php
            // Wardrobe progress, NOT money. What is owed for costumes is in the
            // "Costumes & fees" card above — showing it here as well meant the
            // same $95 appeared twice on one page, in two different cards.
            $cRec   = (int) ($rec['costume_records'] ?? 0);
            $cReady = (int) ($rec['costume_ready'] ?? 0);
            if ($cRec > 0):
            ?>
            <div class="kvr">
                <span class="kvr-k"><?= __('studio_costumes', 'Costumes') ?></span>
                <span class="kvr-v">
                    <?= (int) $cReady ?>/<?= (int) $cRec ?>
                    <?php if ($cReady >= $cRec): ?>
                        <span class="pill pill-green"><?= __('studio_costume_ready', 'ready') ?></span>
                    <?php else: ?>
                        <span class="pill"><?= __('studio_costume_prep', 'in preparation') ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <p style="margin:16px 0 0">
                <a class="mbtn mbtn-primary mbtn-block" href="<?= e($base) ?>?view=recital&amp;id=<?= (int) $rec['id'] ?>">
                    <?= __('studio_recital_details', 'Show details & tickets') ?>
                </a>
            </p>
        </section>
        <?php endforeach; ?>

        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('studio_classes', 'Classes') ?></p>
            <div class="howto">
                <span class="howto-ic"><?= slate_icon('music', 'icon') ?></span>
                <div>
                    <b><?= __('studio_add_a_class', 'Add another class') ?></b>
                    <p><?= __('studio_add_a_class_sub', 'Multi-class and sibling discounts apply automatically at checkout.') ?></p>
                </div>
            </div>
            <p style="margin:16px 0 0">
                <a class="mbtn mbtn-ghost mbtn-block" href="<?= e($base) ?>">
                    <?= __('studio_browse_classes', 'Browse classes') ?>
                </a>
            </p>
        </section>
    </div>
</div>
