<?php
/**
 * Studio — admin Settings.
 *
 * Studio-wide policy that isn't per-class: the discount curves tuition is
 * calculated from, and whether parents get emailed. Both were previously
 * unreachable — the discounts were hardcoded in the domain, and notifications
 * had a kill switch with no UI.
 *
 * Discount tiers are stored as `threshold => percent off` JSON and validated by
 * Slate\Module\Studio\Domain\DiscountPolicy, so a typo loses that tier rather
 * than making every class free.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_settings', 'Studio · Settings');
$currentNav = 'studio_settings';

$selfUrl = plugin_url('studio', 'admin/settings.php');
$flash   = null;

/** Turn the posted parallel threshold/percent rows into a tier map. */
$tiersFromPost = static function (string $field): array {
    $rows = (array) ($_POST[$field] ?? []);
    $out  = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $t = trim((string) ($row['threshold'] ?? ''));
        $p = trim((string) ($row['percent'] ?? ''));
        if ($t === '' || $p === '') { continue; }   // blank row = deleted
        $out[(int) $t] = (float) $p;
    }
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        try {
            StudioAPI::saveDiscountPolicy($tiersFromPost('multi'), $tiersFromPost('sibling'));
            Database::setSetting('studio.notify_disabled', empty($_POST['notify']) ? '1' : '');

            $start = trim((string) ($_POST['term_start'] ?? ''));
            $end   = trim((string) ($_POST['term_end'] ?? ''));
            if ($start !== '' && $end !== '' && $end < $start) {
                throw new \InvalidArgumentException(__('studio_term_backwards', 'The term ends before it starts.'));
            }
            Database::setSetting('studio.term_start', $start);
            Database::setSetting('studio.term_end', $end);
            Database::setSetting('studio.waiver_form_id', (string) (int) ($_POST['waiver_form_id'] ?? 0));

            // Validated against the registry rather than stored raw: the slug
            // becomes a filename in views/catalog/, and an unknown one would
            // fall back silently on every page load instead of failing here.
            if (array_key_exists('catalog_template', $_POST)) {
                $tpl = (string) $_POST['catalog_template'];
                if (!isset(StudioAPI::catalogTemplates()[$tpl])) {
                    throw new \InvalidArgumentException(__('studio_tpl_unknown', 'That catalog layout does not exist.'));
                }
                Database::setSetting('studio.catalog_template', $tpl);
            }

            // Round-tripped through the parser, so the textarea comes back
            // normalised and a malformed line is dropped once, here, rather
            // than on every page that renders the card.
            if (array_key_exists('rate_card', $_POST)) {
                StudioAPI::saveRateCard((string) $_POST['rate_card']);
            }

            // Closures. Saving also cancels lessons already generated onto a
            // newly-closed date — an owner adds the winter break in November,
            // long after the term was generated in August.
            if (array_key_exists('holidays', $_POST)) {
                StudioAPI::saveHolidays((string) $_POST['holidays']);
                $offNow = StudioAPI::applyHolidaysToExisting();
                if ($offNow > 0) {
                    studio_set_flash('success', sprintf(
                        __('studio_closures_applied', 'Settings saved — %d already-scheduled lesson(s) cancelled.'), $offNow));
                    header('Location: ' . $selfUrl); exit;
                }
            }

            // Fees. Entered in dollars because that is what the price list is
            // written in; stored in cents because money is minor units
            // everywhere else (ADR-0011). saveFeeSchedule round-trips through
            // the value object, so a rejected instalment plan is never stored.
            $dollarsToCents = static function (string $field): ?string {
                $v = trim((string) ($_POST[$field] ?? ''));
                if ($v === '') { return null; }                  // blank = keep default
                return (string) (int) round(((float) $v) * 100);
            };
            $feeInput = array_filter([
                'registration_cents'       => $dollarsToCents('fee_registration'),
                'recital_first_cents'      => $dollarsToCents('fee_recital_first'),
                'recital_additional_cents' => $dollarsToCents('fee_recital_additional'),
                'costume_cents'            => $dollarsToCents('fee_costume'),
                'tights_cents'             => $dollarsToCents('fee_tights'),
                'recital_due'              => trim((string) ($_POST['fee_recital_due'] ?? '')) ?: null,
            ], static fn ($v) => $v !== null);

            // Two instalment dates with a shared split. A studio wanting an
            // uneven or three-way plan sets it in settings directly; the form
            // covers the common case without inviting a plan that fails to
            // total 100%.
            $i1 = trim((string) ($_POST['fee_instalment_1'] ?? ''));
            $i2 = trim((string) ($_POST['fee_instalment_2'] ?? ''));
            if ($i1 !== '' && $i2 !== '') {
                $feeInput['costume_instalments'] = json_encode([
                    ['month_day' => $i1, 'percent' => 50],
                    ['month_day' => $i2, 'percent' => 50],
                ]);
            }
            StudioAPI::saveFeeSchedule($feeInput);

            // Billing cadence. Round-tripped through TuitionPlan so an
            // out-of-range instalment count is clamped once, here, rather
            // than every time a quote is built.
            if (array_key_exists('tuition_cadence', $_POST)) {
                StudioAPI::saveTuitionPlan(
                    (string) $_POST['tuition_cadence'],
                    (int) ($_POST['tuition_instalments'] ?? 1)
                );
            }

            studio_set_flash('success', __('studio_settings_saved', 'Settings saved.'));
            header('Location: ' . $selfUrl); exit;
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

$flash    = $flash ?? studio_take_flash();
$policy   = StudioAPI::discountPolicy();

// Round-tripped through the calendar so the textarea shows the normalised
// list — a range typed backwards comes back the right way round.
$plan        = StudioAPI::tuitionPlan();
$holidayCal  = StudioAPI::holidayCalendar();
$holidayText = '';
foreach ($holidayCal->all() as $d => $label) {
    $holidayText .= ($label !== '' ? "{$d} {$label}" : $d) . "\n";
}
$holidayText = rtrim($holidayText);
$fees     = StudioAPI::feeSchedule();
$feeInst  = $fees->costumeInstalments();
$notifyOn = (string) Database::setting('studio.notify_disabled') !== '1';
$term     = StudioAPI::termDefaults();
$forms    = StudioAPI::availableForms();
$waiverId = (int) Database::setting('studio.waiver_form_id');
$catalogTpls  = StudioAPI::catalogTemplates();
$catalogTpl   = StudioAPI::catalogTemplate();
$rateCardText = StudioAPI::rateCardText();

// Email can't go out at all without SMTP, so say so rather than letting an
// admin switch notifications on and wonder why nothing arrives.
$smtpReady = (string) Database::setting('smtp_host') !== ''
    || (Database::setting('smtp_auth_type') === 'xoauth2'
        && (string) Database::setting('smtp_oauth_refresh_token') !== '');

/** Render one editable tier table. Always shows a spare blank row to add to. */
$tierRows = static function (string $field, array $tiers, string $unit): void {
    $rows = $tiers;
    $rows[''] = '';                                   // trailing blank = "add one"
    foreach ($rows as $threshold => $percent): ?>
        <div class="studio-tier">
            <span class="studio-tier-lead"><?= e($unit) ?></span>
            <input type="number" min="1" step="1" name="<?= e($field) ?>[<?= e((string) $threshold) ?>][threshold]"
                   value="<?= e((string) $threshold) ?>" aria-label="<?= e(__('studio_tier_threshold', 'From this many')) ?>">
            <span class="studio-tier-mid"><?= __('studio_tier_get', 'or more get') ?></span>
            <input type="number" min="0" max="100" step="1" name="<?= e($field) ?>[<?= e((string) $threshold) ?>][percent]"
                   value="<?= e((string) $percent) ?>" aria-label="<?= e(__('studio_tier_percent', 'Percent off')) ?>">
            <span class="studio-tier-mid">% <?= __('studio_tier_off', 'off') ?></span>
        </div>
    <?php endforeach;
};

require SLATE_ROOT . '/admin/partials/header.php';
studio_ui_css();
?>

<style>
.studio-tier { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:8px; }
.studio-tier input { width:84px; }
.studio-tier-lead { font-size:13px; color:var(--muted); min-width:72px; }
.studio-tier-mid  { font-size:13px; color:var(--muted); }
.studio-hint-strong { font-size:12.5px; color:var(--muted); margin:6px 0 0; }
</style>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('settings', 'Settings')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('studio_settings_h', 'Studio settings') ?></h1>
        <p class="page-header-sub"><?= __('studio_settings_sub', 'Discount rules and parent notifications.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_public_catalog', 'Public class catalog') ?></h2></div>
        <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
            <?= __('studio_public_catalog_sub',
                'How the class list looks to parents at your public catalog page. The classes and prices are the same either way — only the layout changes.') ?>
        </p>

        <div class="field">
            <label class="field-label" for="catalog_template"><?= __('studio_catalog_layout', 'Layout') ?></label>
            <select id="catalog_template" name="catalog_template">
                <?php foreach ($catalogTpls as $slug => $tplDef): ?>
                    <option value="<?= e($slug) ?>" <?= $slug === $catalogTpl ? 'selected' : '' ?>>
                        <?= e($tplDef['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="field-hint" id="catalog_template_note"><?=
                e($catalogTpls[$catalogTpl]['note'] ?? '') ?></div>
        </div>

        <div class="field" style="margin-top:var(--space-4)">
            <label class="field-label" for="rate_card"><?= __('studio_rate_card', 'Rate card') ?></label>
            <textarea id="rate_card" name="rate_card" rows="7"
                      style="font-family:var(--font-mono,monospace);font-size:13px"><?= e($rateCardText) ?></textarea>
            <div class="field-hint">
                <?= __('studio_rate_card_hint',
                    'One rate per line, as <code>price | what it is</code>. This is the published price list — it can name things that are not on the timetable, like private lessons. Shown on the Price list page and on Policies.') ?>
            </div>
        </div>

        <p class="studio-hint-strong" style="margin-top:var(--space-4)">
            <?= __('studio_catalog_preview', 'Preview:') ?>
            <?php foreach ($catalogTpls as $slug => $tplDef): ?>
                <a href="<?= e(SLATE_URL . '/studio?tpl=' . urlencode($slug)) ?>" target="_blank" rel="noopener"
                   style="margin-right:12px"><?= e($tplDef['label']) ?> ↗</a>
            <?php endforeach; ?>
            <br><span class="text-muted"><?= __('studio_catalog_preview_note',
                'Previews show the real catalog without switching parents over. Saving is what changes what they see.') ?></span>
        </p>
    </div>

    <script>
    // The hint under the select describes the CHOSEN layout, so it has to move
    // with the select rather than with the last save.
    (function () {
        var sel  = document.getElementById('catalog_template');
        var note = document.getElementById('catalog_template_note');
        if (!sel || !note) { return; }
        var notes = <?= json_encode(array_map(static fn (array $t): string => $t['note'], $catalogTpls)) ?>;
        sel.addEventListener('change', function () { note.textContent = notes[sel.value] || ''; });
    })();
    </script>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_discounts', 'Discounts') ?></h2></div>
        <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
            <?= __('studio_discounts_sub', 'Both discounts stack: a second class at 10% off for a family with one sibling at 10% off pays 81% of the price, not 80%. Leave a row blank to remove that tier.') ?>
        </p>

        <h3 class="studio-section-heading"><?= __('studio_multi_class', 'Multi-class — one student, several classes') ?></h3>
        <?php $tierRows('multi', $policy->multiClassTiers(), __('studio_classes_lc', 'Classes')); ?>

        <h3 class="studio-section-heading"><?= __('studio_sibling', 'Siblings — other children in the family') ?></h3>
        <?php $tierRows('sibling', $policy->siblingTiers(), __('studio_siblings_lc', 'Siblings')); ?>

        <p class="studio-hint-strong">
            <?= __('studio_discount_note', 'Changing these re-prices unpaid tuition the next time it is shown. Payments already taken are not affected.') ?>
        </p>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_fees', 'Fees') ?></h2></div>
        <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
            <?= __('studio_fees_sub', 'The money billed alongside tuition. Enter dollars. Leave a box blank to keep the shipped default.') ?>
        </p>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="fee_registration"><?= __('studio_fee_registration', 'Registration fee') ?></label>
                <input type="number" step="0.01" min="0" id="fee_registration" name="fee_registration"
                       value="<?= e(number_format($fees->registrationCents() / 100, 2, '.', '')) ?>">
                <p class="field-hint">
                    <?= $fees->registrationIsFree()
                        ? __('studio_fee_reg_free', 'Zero — the studio advertises free registration.')
                        : __('studio_fee_reg_charged', 'Charged once per family at sign-up.') ?>
                </p>
            </div>
            <div class="field">
                <label class="field-label" for="fee_costume"><?= __('studio_fee_costume', 'Costume, per class') ?></label>
                <input type="number" step="0.01" min="0" id="fee_costume" name="fee_costume"
                       value="<?= e(number_format($fees->costumeCents() / 100, 2, '.', '')) ?>">
                <p class="field-hint">
                    <?= __('studio_fee_costume_hint', 'Per costumed class. Technique classes and ballet from age 8 are exempt.') ?>
                </p>
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="fee_recital_first"><?= __('studio_fee_recital_first', 'Recital fee — first child') ?></label>
                <input type="number" step="0.01" min="0" id="fee_recital_first" name="fee_recital_first"
                       value="<?= e(number_format($fees->recitalFeeBreakdown(1)[0] / 100, 2, '.', '')) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="fee_recital_additional"><?= __('studio_fee_recital_add', 'Recital fee — each additional child') ?></label>
                <input type="number" step="0.01" min="0" id="fee_recital_additional" name="fee_recital_additional"
                       value="<?= e(number_format(($fees->recitalFeeForFamily(2) - $fees->recitalFeeForFamily(1)) / 100, 2, '.', '')) ?>">
                <p class="field-hint">
                    <?= __('studio_fee_recital_hint', 'Charged per family, not per student — three siblings pay first + additional + additional.') ?>
                </p>
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="fee_tights"><?= __('studio_fee_tights', 'Tights, per student') ?></label>
                <input type="number" step="0.01" min="0" id="fee_tights" name="fee_tights"
                       value="<?= e(number_format($fees->tightsCents() / 100, 2, '.', '')) ?>">
                <p class="field-hint">
                    <?= __('studio_fee_tights_hint', 'Once per student, however many costumes they need.') ?>
                </p>
            </div>
            <div class="field">
                <label class="field-label" for="fee_recital_due"><?= __('studio_fee_recital_due', 'Recital fee processed on') ?></label>
                <input type="text" id="fee_recital_due" name="fee_recital_due" placeholder="05-01"
                       value="<?= e(substr($fees->recitalDueDate(2000), 5)) ?>">
                <p class="field-hint"><?= __('studio_fee_md_hint', 'Month and day, as MM-DD.') ?></p>
            </div>
        </div>

        <h3 class="studio-section-heading"><?= __('studio_fee_instalments', 'Costume instalments') ?></h3>
        <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
            <?= __('studio_fee_inst_sub', 'Costume and tights charges are split evenly across these two dates. A date earlier than the first falls in the following year, so November to February spans the season.') ?>
        </p>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="fee_instalment_1"><?= __('studio_fee_inst_1', 'First instalment (50%)') ?></label>
                <input type="text" id="fee_instalment_1" name="fee_instalment_1" placeholder="11-01"
                       value="<?= e((string) ($feeInst[0]['month_day'] ?? '11-01')) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="fee_instalment_2"><?= __('studio_fee_inst_2', 'Second instalment (50%)') ?></label>
                <input type="text" id="fee_instalment_2" name="fee_instalment_2" placeholder="02-01"
                       value="<?= e((string) ($feeInst[1]['month_day'] ?? '02-01')) ?>">
            </div>
        </div>

        <p class="studio-hint-strong">
            <?= __('studio_fee_note', 'Changing a fee affects bills raised from now on. Charges already raised keep the amount they were quoted at.') ?>
        </p>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_term', 'Term dates') ?></h2></div>
        <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
            <?= __('studio_term_sub', 'Prefilled when you create a class, so a term\'s worth of classes needs the dates typed once. Changing them here never moves a class that already exists.') ?>
        </p>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="term_start"><?= __('studio_term_start', 'Term starts') ?></label>
                <input type="date" id="term_start" name="term_start" value="<?= e($term['start']) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="term_end"><?= __('studio_term_end', 'Term ends') ?></label>
                <input type="date" id="term_end" name="term_end" value="<?= e($term['end']) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_plan', 'How tuition is billed') ?></h2></div>
        <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
            <?= __('studio_plan_sub',
                'Which month a payment covers matters: it decides whether you hold a term\'s fees before paying a teacher, and what a family who leaves mid-term owes.') ?>
        </p>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="tuition_cadence"><?= __('studio_plan_when', 'Billed') ?></label>
                <select id="tuition_cadence" name="tuition_cadence">
                    <option value="current" <?= $plan->cadence() === 'current' ? 'selected' : '' ?>>
                        <?= __('studio_plan_current', 'For the current month — charge in September for September') ?>
                    </option>
                    <option value="advance" <?= $plan->cadence() === 'advance' ? 'selected' : '' ?>>
                        <?= __('studio_plan_advance', 'A month in advance — charge in August for September') ?>
                    </option>
                    <option value="arrears" <?= $plan->cadence() === 'arrears' ? 'selected' : '' ?>>
                        <?= __('studio_plan_arrears', 'A month in arrears — charge in September for August') ?>
                    </option>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="tuition_instalments"><?= __('studio_plan_parts', 'Payments per term') ?></label>
                <input type="number" id="tuition_instalments" name="tuition_instalments"
                       min="1" max="<?= \Slate\Module\Studio\Domain\TuitionPlan::MAX_INSTALMENTS ?>"
                       value="<?= (int) $plan->instalments() ?>">
                <div class="field-hint">
                    <?= __('studio_plan_parts_hint',
                        '1 means paid in full at sign-up. A term too small to split sensibly is billed in fewer payments rather than in unpayable pieces.') ?>
                </div>
            </div>
        </div>
        <p class="text-sm text-muted" style="margin:0"><strong><?= e($plan->describe()) ?></strong></p>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_closures', 'Closure dates') ?></h2></div>
        <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
            <?= __('studio_closures_sub',
                'Days the studio is shut. Lessons are never generated on these dates, and any already generated are cancelled when you save.') ?>
        </p>
        <div class="field">
            <label class="field-label" for="holidays"><?= __('studio_closure_list', 'One per line') ?></label>
            <textarea id="holidays" name="holidays" rows="6"
                      placeholder="2026-11-26 Thanksgiving&#10;2026-12-24..2027-01-02 Winter break"><?= e($holidayText) ?></textarea>
            <div class="field-hint">
                <?= __('studio_closure_hint',
                    'A date, or a range with two dots, optionally followed by a label. Lines that are not dates are ignored.') ?>
                <?php if ($holidayCal->count() > 0): ?>
                    · <?= sprintf(__('studio_closure_n', '%d closed day(s)'), $holidayCal->count()) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php $nextOff = $holidayCal->upcoming(date('Y-m-d'), 5); if ($nextOff): ?>
            <p class="text-sm text-muted" style="margin:var(--space-3) 0 0">
                <?= __('studio_closure_next', 'Next closures:') ?>
                <?php $bits = [];
                foreach ($nextOff as $d => $label) {
                    $bits[] = date('j M', strtotime($d)) . ($label !== '' ? ' (' . $label . ')' : '');
                }
                echo e(implode(' · ', $bits)); ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_waiver', 'Liability waiver') ?></h2></div>
        <?php if (!$forms): ?>
            <p class="text-sm text-muted" style="margin:0;max-width:64ch">
                <?= __('studio_waiver_no_forms', 'No forms are available. Build a waiver in the Forms plugin — give it a signature field — then come back and select it here.') ?>
                <a href="<?= e(plugin_url('forms', 'admin/index.php')) ?>"><?= __('studio_waiver_go_forms', 'Open Forms') ?></a>
            </p>
        <?php else: ?>
            <p class="text-sm text-muted" style="margin:0 0 var(--space-4);max-width:64ch">
                <?= __('studio_waiver_sub', 'Studio doesn\'t collect signatures itself — it points at one of your forms. Parents are prompted to sign from their studio portal, and a parent counts as signed when they submit it from the address on their account.') ?>
            </p>
            <div class="field" style="max-width:420px">
                <label class="field-label" for="waiver_form_id"><?= __('studio_waiver_form', 'Waiver form') ?></label>
                <select id="waiver_form_id" name="waiver_form_id">
                    <option value="0"><?= __('studio_waiver_none', '— No waiver —') ?></option>
                    <?php foreach ($forms as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= $waiverId === (int) $f['id'] ? 'selected' : '' ?>>
                            <?= e((string) $f['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_notifications', 'Parent notifications') ?></h2></div>
        <label class="field" style="display:flex;align-items:flex-start;gap:10px;">
            <input type="checkbox" name="notify" value="1" <?= $notifyOn ? 'checked' : '' ?> style="width:16px;height:16px;margin-top:2px;">
            <span>
                <span class="field-label" style="margin:0"><?= __('studio_notify_label', 'Email parents automatically') ?></span>
                <span class="field-hint" style="display:block">
                    <?= __('studio_notify_hint', 'Sends on registration, when a waitlisted dancer is promoted into a class, and as a receipt when tuition is paid.') ?>
                </span>
            </span>
        </label>
        <?php if (!$smtpReady): ?>
            <div class="alert alert-warning" role="status" style="margin-top:var(--space-3)">
                <?= __('studio_notify_no_smtp', 'Email isn\'t configured yet, so nothing will be delivered.') ?>
                <a href="<?= e(SLATE_URL) ?>/admin/settings.php?tab=smtp"><?= __('studio_notify_setup', 'Set up email') ?></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="toolbar" style="margin-top:var(--space-4)">
        <button type="submit" class="btn btn-primary"><?= __('studio_save_settings', 'Save settings') ?></button>
    </div>
</form>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
