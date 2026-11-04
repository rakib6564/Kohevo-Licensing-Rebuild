<?php
/**
 * Studio public — the published price list at ?view=prices. Scope: router.php.
 *
 * No days, no times, no timetable: what a class costs, by type. Asked for in
 * exactly those words — "can we remove the days and times and just list it
 * like PRICE LIST" — after the weekly grid turned out to be more than a
 * parent asking "how much is ballet?" needs.
 *
 * A page of its own rather than a third catalog layout. The catalog answers
 * "when does it run and can I book it"; this answers "what does it cost".
 * Making them alternatives meant a studio had to give one up to have the
 * other, when the honest answer is that a parent wants both on different
 * days.
 *
 * The rates come from StudioAPI::rateCard(), not from the class rows. That is
 * the point of it rather than a shortcut: the card prices half-hour private
 * vocal, which is sold by the lesson and has no series on the timetable, and
 * it says "1.5 hour ballet class" once instead of repeating a price against
 * every ballet class the studio runs.
 *
 * Because it prices types rather than classes there is nothing here to enrol
 * in, so the page ends by pointing at the timetable, which is where the
 * booking is.
 */
if (!defined('SLATE_ROOT')) { exit; }

$rates   = StudioAPI::rateCard();
$catalog = StudioAPI::getPublicCatalog();
$base    = SLATE_URL . '/studio';
$money   = static function (int $cents): string {
    // Whole dollars lose the .00 — a rate card reads "$85", not "$85.00".
    return '$' . ($cents % 100 === 0
        ? number_format($cents / 100)
        : number_format($cents / 100, 2));
};

$siteName = Database::setting('site_name') ?: __('studio', 'Studio');

slate_portal_welcome([
    'eyebrow' => $siteName,
    'title'   => __('studio_price_list', 'Price list'),
    'sub'     => __('studio_price_list_sub', 'What a class costs. No registration fee.'),
    'status'  => $catalog
        ? ['label' => sprintf(__('studio_n_classes_open', '%d classes open'), count($catalog)), 'tone' => 'green']
        : ['label' => __('studio_none_open', 'Nothing open yet'), 'tone' => 'amber'],
]);
?>
<style>
/* Same warm palette as the weekly grid, so a studio switching between the
   two templates does not get two different-looking pages. */
.cp-wrap {
    --cp-panel: #F7F1F1;
    --cp-row:   #F9E4E8;
    --cp-ink:   #3F3F46;
    --cp-ink-2: #57534E;
    --cp-line:  #F0DDE1;
    margin-top: 6px;
}
/* Full width, like the hero above it. It was capped at 760px and left
   aligned, which under a full-bleed header just looked like the page had
   failed to load the rest of itself. */
.cp-board { background:var(--cp-panel); border-radius:22px; padding:16px; }

/* Flex rather than grid, and every tile grows. Six rates divide evenly into
   three columns today; a seventh would leave a hole in a grid, whereas here
   the last row simply shares the width between whatever is on it. A price
   list is exactly the sort of thing that gains a line without warning. */
.cp-tiles { display:flex; flex-wrap:wrap; gap:12px; }
.cp-tile {
    flex:1 1 250px; display:flex; flex-direction:column; gap:7px;
    padding:22px 20px; border-radius:16px; background:var(--cp-row);
}
.cp-price {
    font-size:30px; font-weight:800; line-height:1; letter-spacing:-.02em;
    color:var(--cp-ink); font-variant-numeric:tabular-nums;
}
.cp-label { font-size:14px; line-height:1.5; color:var(--cp-ink-2); }

.cp-foot { margin:18px 0 0; text-align:center; font-size:14px; color:var(--cp-ink-2); }
.cp-foot a { color:var(--cp-ink); font-weight:700; }
.cp-none { padding:38px 20px; text-align:center; color:var(--muted); font-size:14px; }

@media (max-width: 640px) {
    .cp-board { padding:10px; border-radius:18px; }
    .cp-tile { padding:18px 16px; }
    .cp-price { font-size:26px; }
}
</style>

<div class="cp-wrap">
    <?php studio_pub_subnav('prices'); ?>
    <?php if (!$rates): ?>
        <div class="cp-none"><?= __('studio_rates_empty',
            'No rates published yet. Add them under Studio → Settings.') ?></div>
    <?php else: ?>

    <div class="cp-board">
        <div class="cp-tiles">
            <?php foreach ($rates as $r): ?>
                <div class="cp-tile">
                    <span class="cp-price"><?= e($money((int) $r['cents'])) ?></span>
                    <span class="cp-label"><?= e((string) $r['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($catalog): ?>
        <p class="cp-foot">
            <?= sprintf(
                __('studio_price_list_foot', 'There are %d classes open — see the %s to pick a day and enrol.'),
                count($catalog),
                '<a href="' . e($base) . '?tpl=grid">' . __('studio_timetable', 'weekly timetable') . '</a>'
            ) ?>
        </p>
    <?php endif; ?>

    <?php endif; ?>
</div>
