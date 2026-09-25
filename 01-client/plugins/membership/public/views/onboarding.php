<?php
/** Member area — 4-step onboarding wizard. Scope: router.php ($profile, $cid). */
if (!defined('SLATE_ROOT')) exit;

$done = (int)($profile['onboarding_step'] ?? 0);
// Show the requested step, but never skip ahead of what's been completed.
$step = max(1, min(4, (int)($_GET['step'] ?? ($done + 1))));
if ($step > $done + 1) $step = $done + 1;

$cust    = Auth::customer();
$phone   = (string)(Database::value("SELECT phone FROM customers WHERE id = ? AND tenant_id = ?", [$cid, $tid]) ?? '');
$genders = MembershipAPI::genders();
$skills  = MembershipAPI::skillLevels();
$steps   = [
    1 => __('membership_step_personal', 'Personal info'),
    2 => __('membership_step_experience', 'Experience & health'),
    3 => __('membership_step_emergency', 'Emergency contact'),
    4 => __('membership_step_consent', 'Consent'),
];
?>
<div class="card" style="max-width:640px;margin:0 auto;">
    <div class="card-header"><h2><?= __('membership_welcome', 'Welcome — complete your profile') ?></h2></div>
    <p class="text-sm text-muted"><?= __('membership_onboarding_intro', 'A few quick details before you can book sessions.') ?></p>

    <!-- Progress -->
    <ol style="display:flex;gap:6px;list-style:none;padding:0;margin:var(--space-3) 0;">
        <?php foreach ($steps as $n => $lbl):
            $state = $n < $step ? 'done' : ($n === $step ? 'current' : 'todo');
            $bg = $state === 'done' ? 'var(--accent)' : ($state === 'current' ? 'var(--text)' : 'var(--border)');
            $fg = $state === 'todo' ? 'var(--muted)' : '#fff';
        ?>
            <li style="flex:1;text-align:center;">
                <span style="display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:<?= $bg ?>;color:<?= $fg ?>;font-size:12px;font-weight:700;"><?= $n ?></span>
                <div class="text-xs" style="margin-top:4px;color:<?= $state==='todo'?'var(--muted)':'var(--text)' ?>;"><?= e($lbl) ?></div>
            </li>
        <?php endforeach; ?>
    </ol>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_onboarding">
        <input type="hidden" name="step" value="<?= $step ?>">

        <?php if ($step === 1): ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="gender"><?= __('membership_gender', 'Gender') ?></label>
                    <select id="gender" name="gender">
                        <?php foreach ($genders as $k => $lbl): ?>
                            <option value="<?= e($k) ?>" <?= (($profile['gender'] ?? '') === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="dob"><?= __('membership_dob', 'Date of birth') ?></label>
                    <input type="date" id="dob" name="dob" value="<?= e($profile['dob'] ?? '') ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="phone"><?= __('membership_phone', 'Phone') ?></label>
                <input type="tel" id="phone" name="phone" maxlength="40" value="<?= e($phone) ?>">
            </div>

        <?php elseif ($step === 2): ?>
            <div class="field">
                <label class="field-label" for="skill_level"><?= __('membership_skill', 'Skill level') ?></label>
                <select id="skill_level" name="skill_level">
                    <?php foreach ($skills as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= (($profile['skill_level'] ?? '') === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="medical_notes"><?= __('membership_medical', 'Medical conditions') ?></label>
                <textarea id="medical_notes" name="medical_notes" rows="2"><?= e($profile['medical_notes'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label class="field-label" for="allergies"><?= __('membership_allergies', 'Allergies') ?></label>
                <textarea id="allergies" name="allergies" rows="2"><?= e($profile['allergies'] ?? '') ?></textarea>
            </div>

        <?php elseif ($step === 3): ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="emergency_name"><?= __('membership_emergency_name', 'Contact name') ?></label>
                    <input type="text" id="emergency_name" name="emergency_name" maxlength="160" value="<?= e($profile['emergency_name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="emergency_phone"><?= __('membership_emergency_phone', 'Contact phone') ?></label>
                    <input type="tel" id="emergency_phone" name="emergency_phone" maxlength="40" value="<?= e($profile['emergency_phone'] ?? '') ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="emergency_relation"><?= __('membership_emergency_relation', 'Relationship') ?></label>
                <input type="text" id="emergency_relation" name="emergency_relation" maxlength="80" value="<?= e($profile['emergency_relation'] ?? '') ?>">
            </div>

        <?php else: /* step 4 */
            $termsUrl = (string) Database::setting('membership.terms_url');
        ?>
            <div class="field">
                <label class="switch-label" style="align-items:flex-start;">
                    <span class="switch"><input type="checkbox" name="consent_terms" value="1" required><span class="switch-track"></span></span>
                    <span><?= __('membership_consent_terms', 'I accept the terms and conditions') ?>
                        <?php if ($termsUrl !== ''): ?> — <a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener"><?= __('membership_read_terms', 'read') ?></a><?php endif; ?>
                    </span>
                </label>
            </div>
            <div class="field">
                <label class="switch-label" style="align-items:flex-start;">
                    <span class="switch"><input type="checkbox" name="consent_media" value="1"><span class="switch-track"></span></span>
                    <span><?= __('membership_consent_media', 'I consent to photos/video being used for promotion (optional)') ?></span>
                </label>
            </div>
        <?php endif; ?>

        <div class="flex gap-2 mt-3">
            <?php if ($step > 1): ?>
                <a href="?view=onboarding&step=<?= $step - 1 ?>" class="btn btn-ghost"><?= __('membership_back', 'Back') ?></a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <?= $step >= 4 ? __('membership_finish', 'Finish') : __('membership_next', 'Next') ?>
            </button>
        </div>
    </form>
</div>
