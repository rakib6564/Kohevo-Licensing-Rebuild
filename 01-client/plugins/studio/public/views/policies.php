<?php
/**
 * Studio public — studio policies. Scope: public/router.php (no login required).
 *
 * The route and the nav title already existed; the view did not, so
 * `?view=policies` fell through the router's switch and rendered the CATALOG
 * under a "Policies" heading. This is that missing view.
 *
 * One rule shapes the whole page: anything the system actually BILLS is
 * rendered from StudioAPI::feeSchedule(), never retyped as prose. A policy page
 * that says "$95" while the ledger raises $110 is worse than no policy page —
 * a parent quotes it back at you. So the fee figures, the exemption rule and
 * the instalment dates all come from the same settings that drive
 * generateCostumeFeesForStudent().
 *
 * The tuition rate card is different: it is a published rate document covering
 * offerings that need not exist as class rows (private vocal has no series),
 * so it comes from StudioAPI::rateCard() — the same settings-backed list the
 * Price list page renders — rather than being derived from the catalog.
 */
if (!defined('SLATE_ROOT')) { exit; }

$base  = SLATE_URL . '/studio';
$fs    = StudioAPI::feeSchedule();
$money = static fn (int $c): string => '$' . number_format($c / 100, 2);

/**
 * Published rate card — "what a class costs", by duration and type.
 *
 * Shared with the price-list catalog template via StudioAPI::rateCard(). It
 * used to be a second copy of the same six lines declared here, which is one
 * copy too many for a number a parent will quote back at you: the private
 * vocal rate moved from $65 to $70 and only one of the two places would have
 * been updated.
 */
$rates = StudioAPI::rateCard();

/** Company programme requirements. */
$company = [
    [
        'name'  => 'Junior Company',
        'ages'  => 'approx. 7–9 years old',
        'count' => '4 required classes',
        'items' => ['1 hr of Ballet', '1 hr of Leaps and Turns Technique',
                    '1 hr of Jazz', '1 hr of Tap'],
    ],
    [
        'name'  => 'Preteen / Teen Company',
        'ages'  => 'approx. 10 & up',
        'count' => '5 required classes',
        'items' => ['1.5 hrs of Ballet', '1 hr of Leaps and Turns Technique',
                    '1 hr of Jazz', '1 hr of Tap', '1 hr of Hip-Hop'],
    ],
];

$attire = [
    'Any colour leotard and tights may be worn for ballet. Dance shorts or skirts may be worn over leotards.',
    'In colder months, dancers may wear dance sweaters or form-fitting long sleeve shirts over leotards.',
    'Form-fitted clothing may be worn for tap, jazz, technique and theatre classes. Leggings, tanks and athletic dresses are all allowed. No tube tops, no denim.',
    'No jewellery.',
    'Street shoes and baggy clothes are allowed in Hip-Hop classes.',
    'Students must have proper shoes for all classes. Proper dance socks may be worn for jazz and technique classes only.',
    'Hair pulled back for all classes — a bun for ballet.',
];

$instalments = $fs->splitCostume($fs->costumeCents() + $fs->tightsCents(), (int) date('Y'));

slate_portal_welcome([
    'eyebrow' => __('studio_policies', 'Policies'),
    'title'   => __('studio_policies_title', 'Studio policies'),
    'sub'     => __('studio_policies_sub',
                    'Tuition, performance fees, costumes and what to wear to class.'),
]);
?>
<style>
/* Packed by height, not by row.
 *
 * These cards are wildly uneven — "there is no registration fee" is two lines
 * and sits beside a six-row price list — and a grid row is only ever as short
 * as its tallest cell. So every row stretched its short cards to match its
 * tallest one, and any row that did not divide evenly left the remainder
 * empty: the recital card sat alone with two thirds of a row beside it.
 *
 * Multi-column flows each card into whichever column is currently shortest
 * and lets it keep its natural height, so the holes cannot form. break-inside
 * is what keeps a card whole rather than splitting it across the fold.
 *
 * The bottom edge comes out ragged, which is the honest shape of eight cards
 * that are not the same size, and is what a bento is.
 */
.sp-grid { columns:3; column-gap:16px; }
.sp-card {
    display:block; margin:0 0 16px;
    break-inside:avoid; -webkit-column-break-inside:avoid; page-break-inside:avoid;
}
@media (max-width:1180px) { .sp-grid { columns:2; } }
@media (max-width:720px)  { .sp-grid { columns:1; } }
.sp-lede { margin:0 0 14px; font-size:14px; line-height:1.6; color:var(--m-muted,#737886); }
.sp-rate { display:flex; justify-content:space-between; gap:16px; align-items:baseline;
    padding:11px 0; border-bottom:1px solid var(--m-line,#ECEEF1); font-size:14px; }
.sp-rate:last-child { border-bottom:0; }
.sp-rate .k { color:var(--m-ink,#15181E); }
.sp-rate .v { font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap; }
.sp-free { display:inline-flex; align-items:center; gap:7px; padding:7px 14px; border-radius:999px;
    background:var(--success-soft,#E7F7EE); color:#15803D; font-weight:700; font-size:14px; }
.sp-list { margin:0; padding:0; list-style:none; }
.sp-list li { position:relative; padding:7px 0 7px 20px; font-size:14px; line-height:1.55; }
.sp-list li::before { content:""; position:absolute; left:2px; top:15px; width:6px; height:6px;
    border-radius:50%; background:var(--accent); }
.sp-note { margin:14px 0 0; padding:12px 14px; border-radius:12px; font-size:13px; line-height:1.55;
    background:var(--warning-soft,#FEF6E7); color:#B45309; }
.sp-inst { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
.sp-inst-step { flex:1 1 150px; padding:12px 14px; border-radius:14px;
    border:1px solid var(--m-line,#ECEEF1); background:var(--m-bg,#F7F9FB); }
.sp-inst-when { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
    color:var(--m-muted,#737886); }
.sp-inst-amt { margin-top:3px; font-size:19px; font-weight:800; font-variant-numeric:tabular-nums; }
.sp-co { padding:14px 0; border-bottom:1px solid var(--m-line,#ECEEF1); }
.sp-co:last-child { border-bottom:0; }
.sp-co-name { font-weight:700; font-size:15px; }
.sp-co-meta { font-size:12.5px; color:var(--m-muted,#737886); margin:2px 0 8px; }
</style>

<?php studio_pub_subnav('policies'); ?>

<div class="sp-grid">

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_registration', 'Registration') ?></p>
        <p class="pcard-title"><?= __('studio_pol_registration_t', 'Registration') ?></p>
        <?php if ($fs->registrationIsFree()): ?>
            <span class="sp-free">✓ <?= __('studio_pol_free', 'There is no registration fee') ?></span>
            <p class="sp-lede" style="margin:14px 0 0;">
                <?= __('studio_pol_free_sub', 'Registration is free — you only pay tuition for the classes you enrol in.') ?>
            </p>
        <?php else: ?>
            <div class="sp-rate"><span class="k"><?= __('studio_pol_reg_fee', 'Registration fee') ?></span>
                <span class="v"><?= e($money($fs->registrationCents())) ?></span></div>
        <?php endif; ?>
    </section>

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_tuition', 'Price list') ?></p>
        <p class="pcard-title"><?= __('studio_pol_tuition_t', 'Tuition') ?></p>
        <?php foreach ($rates as $rate): ?>
            <div class="sp-rate">
                <span class="k"><?= e((string) $rate['label']) ?></span>
                <span class="v"><?= e($money((int) $rate['cents'])) ?></span>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_summer', 'Summer') ?></p>
        <p class="pcard-title"><?= __('studio_pol_summer_t', 'Summer dance') ?></p>
        <p class="sp-lede">
            <?= __('studio_pol_summer_body',
                'Summer tuition is based on a 5 week session and is charged on the day of registration.') ?>
        </p>
        <div class="sp-note">
            <?= __('studio_pol_summer_note',
                'There are no refunds or credits for the summer session once classes have begun.') ?>
        </div>
    </section>

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_performance', 'Performance') ?></p>
        <p class="pcard-title"><?= __('studio_pol_performance_t', 'Recital fee') ?></p>
        <div class="sp-rate">
            <span class="k"><?= __('studio_pol_rec_first', 'Per student') ?></span>
            <span class="v"><?= e($money($fs->recitalFeeBreakdown(1)[0])) ?></span>
        </div>
        <div class="sp-rate">
            <span class="k"><?= __('studio_pol_rec_add', 'Each additional child enrolled') ?></span>
            <span class="v"><?= e($money($fs->recitalFeeForFamily(2) - $fs->recitalFeeForFamily(1))) ?></span>
        </div>
        <p class="sp-lede" style="margin:14px 0 0;">
            <?php
            // The date comes from the fee schedule, so a studio that moves the
            // billing date cannot leave this page quoting the old one.
            printf(
                e(__('studio_pol_rec_when', 'Processed on %s. The fee includes a download of the recital video, live photos, and a finale costume or shirt.')),
                e(date('F j', strtotime($fs->recitalDueDate((int) date('Y')))))
            );
            ?>
        </p>
    </section>

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_costumes', 'Costumes') ?></p>
        <p class="pcard-title"><?= __('studio_pol_costumes_t', 'Recital costumes') ?></p>
        <div class="sp-rate">
            <span class="k"><?= __('studio_pol_cost_each', 'Costume fee, per class') ?></span>
            <span class="v"><?= e($money($fs->costumeCents())) ?></span>
        </div>
        <div class="sp-rate">
            <span class="k"><?= __('studio_pol_cost_tights', 'Tights, per student') ?></span>
            <span class="v"><?= e($money($fs->tightsCents())) ?></span>
        </div>
        <p class="sp-lede" style="margin:14px 0 0;">
            <?= __('studio_pol_cost_tights_note',
                'Students need only one pair of tights unless a second pair is required for the costume.') ?>
        </p>

        <?php if ($instalments): ?>
            <p class="sp-lede" style="margin:16px 0 0;font-weight:650;color:var(--m-ink,#15181E);">
                <?= __('studio_pol_cost_billing', 'All costumes are billed in two payments') ?>
            </p>
            <div class="sp-inst">
                <?php foreach ($instalments as $p): ?>
                    <div class="sp-inst-step">
                        <div class="sp-inst-when">
                            <?= e(date('F j', strtotime($p['due_date']))) ?>
                            · <?= (int) round(100 / max(1, $p['instalment_of'])) ?>%
                        </div>
                        <div class="sp-inst-amt"><?= e($money($p['amount_cents'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="sp-lede" style="margin:10px 0 0;font-size:12.5px;">
                <?= __('studio_pol_cost_example',
                    'Shown for one costumed class plus tights. A student in several classes is billed one costume fee per class.') ?>
            </p>
        <?php endif; ?>

        <div class="sp-note">
            <?= __('studio_pol_cost_exempt',
                'There is no costume fee for technique classes, or for ballet classes from age 8 and up. Competition classes may carry an additional costume and/or crystal fee. Costume fees are non-refundable.') ?>
        </div>
    </section>

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_company', 'Company') ?></p>
        <p class="pcard-title"><?= __('studio_pol_company_t', 'Company programme') ?></p>
        <?php foreach ($company as $c): ?>
            <div class="sp-co">
                <div class="sp-co-name"><?= e($c['name']) ?></div>
                <div class="sp-co-meta"><?= e($c['ages']) ?> · <?= e($c['count']) ?></div>
                <ul class="sp-list">
                    <?php foreach ($c['items'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_closings', 'Closings') ?></p>
        <p class="pcard-title"><?= __('studio_pol_closings_t', 'School closings') ?></p>
        <p class="sp-lede">
            <?= __('studio_pol_closings_body',
                'We follow the Seaford School District for holiday and inclement weather closings.') ?>
        </p>
    </section>

    <section class="pcard sp-card">
        <p class="pcard-eyebrow"><?= __('studio_pol_attire', 'Dress code') ?></p>
        <p class="pcard-title"><?= __('studio_pol_attire_t', 'Attire') ?></p>
        <ul class="sp-list">
            <?php foreach ($attire as $a): ?><li><?= e($a) ?></li><?php endforeach; ?>
        </ul>
    </section>

</div>

<p style="margin:22px 0 0;">
    <a class="mbtn mbtn-ghost" href="<?= e($base) ?>">← <?= e(__('studio_back_classes', 'Back to classes')) ?></a>
</p>
