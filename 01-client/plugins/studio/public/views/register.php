<?php
/**
 * Studio public — self-serve registration. Scope: public/router.php (login required).
 *
 * Renders inside the shared customer portal shell, using the app kit
 * (pcard / kvr / pill / mbtn) so it matches the parent dashboard it returns to.
 */
if (!defined('SLATE_ROOT')) { exit; }

use Slate\Module\Studio\Domain\DanceStyle;

$base = SLATE_URL . '/studio';
$cid  = (int) Auth::customerId();
$id   = (int) ($_GET['class'] ?? 0);
$c    = $id > 0 ? StudioAPI::getPublicClass($id) : null;
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$money = static fn (int $cents): string => '$' . number_format($cents / 100, 2);
$styleLabel = static function (string $v): string {
    $x = DanceStyle::tryFrom($v);
    return $x ? $x->label() : ucwords(str_replace(['_', '-'], ' ', $v));
};

if ($c === null) {
    ?>
    <section class="pcard">
        <p class="pcard-eyebrow"><?= __('studio_class', 'Class') ?></p>
        <p class="pcard-title"><?= __('studio_class_gone', 'Class not found') ?></p>
        <p style="margin:0 0 16px;font-size:14px;color:var(--m-muted)">
            <?= __('studio_class_gone_sub', 'It may no longer be open.') ?>
        </p>
        <a class="mbtn mbtn-ghost" href="<?= e($base) ?>">← <?= e(__('studio_back_catalog', 'Back to classes')) ?></a>
    </section>
    <?php
    return;
}

$data     = StudioAPI::getParentPortal($cid);
$students = $data['students'];
$full     = (int) $c['open_spots'] <= 0;
$dow      = (int) $c['day_of_week'];
?>

<?php
// A real page title here (the class being registered for) rather than a
// restatement of the nav — this page is *about* one class.
slate_portal_welcome([
    'eyebrow' => $styleLabel((string) $c['style']),
    'title'   => __('studio_register', 'Register'),
    'name'    => (string) $c['name'],
    'sub'     => ($days[$dow] ?? '') . ' · ' . substr((string) $c['start_time'], 0, 5)
               . '–' . substr((string) $c['end_time'], 0, 5),
    'status'  => $full
        ? ['label' => __('studio_class_full', 'Full — waitlist'), 'tone' => 'amber']
        : ['label' => sprintf(__('studio_n_open', '%d open'), (int) $c['open_spots']), 'tone' => 'green'],
]);
?>
<?php /* The hero already carries 18px beneath it; a second 18px above the back
         link stacked into a 36px gap and left the button floating between the
         hero and the form. Sits tight under the hero instead. */ ?>
<p style="margin:0 0 18px">
    <a class="mbtn mbtn-ghost" href="<?= e($base) ?>?view=class&amp;id=<?= (int) $c['id'] ?>">
        ← <?= e(__('studio_back_class', 'Back to class')) ?>
    </a>
</p>

<div class="dash">
    <div class="dash-col">
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('studio_who_is_this_for', 'Who is this for?') ?></p>

            <form method="post" action="<?= e($base) ?>?view=register&amp;class=<?= (int) $c['id'] ?>" class="mform">
                <?= csrf_field() ?>
                <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">

                <?php if ($students): ?>
                <div class="field">
                    <label class="field-label" for="student_id"><?= __('studio_which_dancer', 'Which dancer?') ?></label>
                    <select id="student_id" name="student_id">
                        <option value="0"><?= __('studio_add_new_dancer', '— Add a new dancer —') ?></option>
                        <?php foreach ($students as $st): ?>
                            <option value="<?= (int) $st['id'] ?>"><?= e($st['name'] ?: ('#' . $st['id'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="new_student"><?= $students ? __('studio_or_new_dancer', 'Or a new dancer\'s name') : __('studio_dancer_name', 'Dancer\'s name') ?></label>
                    <input type="text" id="new_student" name="new_student" maxlength="190" placeholder="<?= e(__('studio_dancer_placeholder', 'e.g. Sam Morgan')) ?>">
                    <?php if ($students): ?>
                        <div class="field-hint" id="new_student_hint" hidden><?= __('studio_new_dancer_ignored', 'Choose “Add a new dancer” above to enter a new name.') ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="mbtn mbtn-primary mbtn-block">
                    <?= $full ? e(__('studio_join_waitlist', 'Join the waitlist')) : e(__('studio_confirm_register', 'Confirm registration')) ?>
                </button>
                <p style="margin:12px 0 0;font-size:13px;color:var(--m-muted)">
                    <?= __('studio_payment_note2', 'After registering, pay tuition online from My Studio (or at the front desk).') ?>
                </p>
            </form>
        </section>
    </div>

    <div class="dash-col">
        <section class="pcard">
            <p class="pcard-eyebrow"><?= __('studio_class', 'Class') ?></p>
            <div class="kvr">
                <span class="kvr-k"><?= __('studio_schedule', 'Schedule') ?></span>
                <span class="kvr-v"><?= e(($days[$dow] ?? '') . ' ' . substr((string) $c['start_time'], 0, 5) . '–' . substr((string) $c['end_time'], 0, 5)) ?></span>
            </div>
            <div class="kvr">
                <span class="kvr-k"><?= __('studio_price', 'Tuition') ?></span>
                <span class="kvr-v"><?= e($money((int) $c['price_cents'])) ?></span>
            </div>
            <div class="kvr">
                <span class="kvr-k"><?= __('studio_availability', 'Availability') ?></span>
                <span class="kvr-v">
                    <?php if ($full): ?>
                        <span class="pill pill-amber"><?= __('studio_class_full', 'Full — waitlist') ?></span>
                    <?php else: ?>
                        <span class="pill pill-green"><?= e(sprintf(__('studio_n_open', '%d open'), (int) $c['open_spots'])) ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </section>
    </div>
</div>

<?php if ($students): ?>
<script>
/* The router ignores `new_student` whenever an existing dancer is picked, so say
   so in the UI rather than silently dropping a name the parent just typed. */
(function () {
    var sel = document.getElementById('student_id');
    var txt = document.getElementById('new_student');
    var hint = document.getElementById('new_student_hint');
    if (!sel || !txt) return;
    function sync() {
        var picked = sel.value !== '0';
        txt.disabled = picked;
        if (picked) { txt.value = ''; }
        if (hint) { hint.hidden = !picked; }
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>
