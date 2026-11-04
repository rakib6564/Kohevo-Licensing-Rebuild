<?php
/**
 * Studio public — recital detail for a parent. Scope: public/router.php (login required).
 *
 * What a family needs on show day: when to arrive (call time is not doors
 * time), where, which pieces their dancers are in, what costume money is
 * outstanding, and how many seats they hold.
 */
if (!defined('SLATE_ROOT')) { exit; }

$base = SLATE_URL . '/studio';
$cid  = (int) Auth::customerId();
$rid  = (int) ($_GET['id'] ?? 0);

$mine = StudioAPI::recitalsForParent($cid);
$r    = null;
foreach ($mine as $row) { if ((int) $row['id'] === $rid) { $r = $row; } }

if ($r === null) {
    ?>
    <section class="pcard">
        <p class="pcard-eyebrow"><?= __('studio_recital', 'Recital') ?></p>
        <p class="pcard-title"><?= __('studio_recital_gone', 'Recital not found') ?></p>
        <p style="margin:0 0 16px;font-size:14px;color:var(--m-muted)">
            <?= __('studio_recital_gone_sub', 'It may not be published yet, or none of your dancers are in it.') ?>
        </p>
        <a class="mbtn mbtn-ghost" href="<?= e($base) ?>?view=portal">← <?= e(__('studio_back_portal', 'Back to my studio')) ?></a>
    </section>
    <?php
    return;
}

$money = static fn (int $c): string => '$' . number_format($c / 100, 2);
$time  = static fn (?string $t): string => $t ? date('g:i A', strtotime('2000-01-01 ' . $t)) : '—';
$left  = StudioAPI::recitalSeatsLeft($rid);
$price = (int) $r['ticket_price_cents'];

slate_portal_welcome([
    'eyebrow' => __('studio_recital', 'Recital'),
    'title'   => (string) $r['name'],
    'sub'     => $r['recital_date']
        ? date('l, j F Y', strtotime((string) $r['recital_date'])) . ($r['venue'] ? ' · ' . $r['venue'] : '')
        : (string) ($r['venue'] ?? ''),
    'status'  => (int) $r['tickets_held'] > 0
        ? ['label' => sprintf(__('studio_n_seats_held', '%d seat(s) held'), (int) $r['tickets_held']), 'tone' => 'green']
        : ['label' => __('studio_no_seats_yet', 'No tickets yet'), 'tone' => 'amber'],
]);
?>

<p style="margin:0 0 18px">
    <a class="mbtn mbtn-ghost" href="<?= e($base) ?>?view=portal">← <?= e(__('studio_back_portal', 'Back to my studio')) ?></a>
</p>

<div class="bento">
    <section class="pcard">
        <p class="pcard-eyebrow"><?= __('studio_show_day', 'Show day') ?></p>
        <div class="kvr">
            <span class="kvr-k"><?= __('studio_call_time', 'Call time') ?></span>
            <span class="kvr-v"><?= e($time($r['call_time'] ?? null)) ?></span>
        </div>
        <div class="kvr">
            <span class="kvr-k"><?= __('studio_doors', 'Doors') ?></span>
            <span class="kvr-v"><?= e($time($r['doors_time'] ?? null)) ?></span>
        </div>
        <?php if ($r['venue']): ?>
        <div class="kvr">
            <span class="kvr-k"><?= __('studio_venue', 'Venue') ?></span>
            <span class="kvr-v"><?= e((string) $r['venue']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($r['notes']): ?>
            <p style="margin:14px 0 0;font-size:13.5px;color:var(--m-muted)"><?= e((string) $r['notes']) ?></p>
        <?php endif; ?>
        <p style="margin:14px 0 0;font-size:12.5px;color:var(--m-muted)">
            <?= __('studio_call_note', 'Call time is when dancers should arrive backstage — earlier than doors.') ?>
        </p>
    </section>

    <section class="pcard">
        <p class="pcard-eyebrow"><?= __('studio_your_dancers', 'Your dancers in this show') ?></p>
        <?php if (!$r['performers']): ?>
            <p style="margin:0;font-size:14px;color:var(--m-muted)"><?= __('studio_no_pieces_you', 'No pieces yet.') ?></p>
        <?php else: foreach ($r['performers'] as $p): ?>
            <div class="kvr">
                <span class="kvr-k">
                    <strong style="display:block;color:var(--m-ink);font-size:14.5px"><?= e((string) ($p['student_name'] ?? '')) ?></strong>
                    <?= e((string) ($p['title'] ?: $p['class_name'])) ?>
                </span>
                <span class="kvr-v"><?= __('studio_piece_no', 'Piece') ?> <?= (int) $p['position'] ?></span>
            </div>
        <?php endforeach; endif; ?>

        <?php
        // Points at the balance rather than restating it. The figure lives on
        // the portal's "Costumes & fees" card; quoting it in two places is how
        // a parent ends up believing they owe it twice.
        if ((int) ($r['costume_due_cents'] ?? 0) > 0): ?>
            <div class="upsell" style="margin-top:16px"><div class="upsell-txt">
                <b><?= e(__('studio_costume_bal', 'You have a costume balance outstanding')) ?></b>
                <p><?= __('studio_costume_bal_sub', 'See “Costumes & fees” in your studio portal for the breakdown and due dates.') ?></p>
            </div>
            <a class="mbtn mbtn-ghost" href="<?= e(SLATE_URL . '/studio?view=portal') ?>">
                <?= __('studio_view_fees', 'View my fees') ?>
            </a></div>
        <?php endif; ?>
    </section>

    <section class="pcard">
        <p class="pcard-eyebrow"><?= __('studio_tickets', 'Tickets') ?></p>
        <div class="kvr">
            <span class="kvr-k"><?= __('studio_price_each', 'Price each') ?></span>
            <span class="kvr-v"><?= e($money($price)) ?></span>
        </div>
        <div class="kvr">
            <span class="kvr-k"><?= __('studio_you_hold', 'You hold') ?></span>
            <span class="kvr-v"><?= (int) $r['tickets_held'] ?></span>
        </div>
        <?php if ($left !== null): ?>
        <div class="kvr">
            <span class="kvr-k"><?= __('studio_seats_left', 'Seats left') ?></span>
            <span class="kvr-v"><?= (int) $left ?></span>
        </div>
        <?php endif; ?>

        <?php if ($left !== null && $left <= 0): ?>
            <p style="margin:16px 0 0"><span class="pill pill-amber"><?= __('studio_sold_out', 'Sold out') ?></span></p>
        <?php elseif ($price <= 0): ?>
            <p style="margin:16px 0 0;font-size:13.5px;color:var(--m-muted)">
                <?= __('studio_free_show', 'This show is free — no tickets needed.') ?>
            </p>
        <?php else: ?>
            <form method="post" action="<?= e($base) ?>?view=tickets" class="mform" style="margin-top:16px">
                <?= csrf_field() ?>
                <input type="hidden" name="recital_id" value="<?= $rid ?>">
                <div class="field">
                    <label class="field-label" for="qty"><?= __('studio_how_many', 'How many seats?') ?></label>
                    <select id="qty" name="quantity">
                        <?php $max = $left !== null ? min(10, $left) : 10; ?>
                        <?php for ($i = 1; $i <= max(1, $max); $i++): ?>
                            <option value="<?= $i ?>"><?= $i ?> — <?= e($money($price * $i)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="mbtn mbtn-primary mbtn-block"><?= __('studio_buy_tickets', 'Buy tickets') ?></button>
            </form>
        <?php endif; ?>
    </section>
</div>
