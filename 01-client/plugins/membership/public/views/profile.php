<?php
/** Member app — Profile tab (premium). Scope: router.php ($profile, $cid, $tid). */
if (!defined('SLATE_ROOT')) exit;
$genders = MembershipAPI::genders();
$skills  = MembershipAPI::skillLevels();
$phone   = (string)(Database::value("SELECT phone FROM customers WHERE id = ? AND tenant_id = ?", [$cid, $tid]) ?? '');
$avatar  = (string)($profile['avatar_path'] ?? '');
$name    = (string)(Auth::customer()['name'] ?? '?');
?>
<div class="mview-head">
    <div>
        <h1><?= __('membership_profile', 'Profile') ?></h1>
        <p><?= __('membership_profile_sub', 'Keep your details up to date.') ?></p>
    </div>
</div>

<form method="post" enctype="multipart/form-data" class="mform" style="display:flex;flex-direction:column;gap:18px;">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_profile">

    <div class="pcard">
        <div class="pcard-eyebrow"><?= __('membership_profile', 'Profile') ?></div>
        <div style="display:flex;align-items:center;gap:18px;margin-bottom:6px;flex-wrap:wrap;">
            <span style="width:72px;height:72px;border-radius:20px;overflow:hidden;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;flex:none;font-size:26px;font-weight:800;">
                <?php if ($avatar !== ''): ?><img src="<?= e(SLATE_URL . $avatar) ?>" alt="" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?><?php endif; ?>
            </span>
            <div class="field" style="flex:1;min-width:200px;margin:0;">
                <label class="field-label" for="avatar"><?= __('membership_avatar', 'Profile photo') ?></label>
                <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
                <div class="field-hint"><?= __('membership_avatar_hint', 'JPG, PNG or WebP, up to 4 MB.') ?></div>
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="gender"><?= __('membership_gender', 'Gender') ?></label>
                <select id="gender" name="gender"><?php foreach ($genders as $k => $l): ?><option value="<?= e($k) ?>" <?= (($profile['gender'] ?? '')===$k)?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field">
                <label class="field-label" for="dob"><?= __('membership_dob', 'Date of birth') ?></label>
                <input type="date" id="dob" name="dob" value="<?= e($profile['dob'] ?? '') ?>">
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="phone"><?= __('membership_phone', 'Phone') ?></label>
                <input type="tel" id="phone" name="phone" maxlength="40" value="<?= e($phone) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="skill_level"><?= __('membership_skill', 'Skill level') ?></label>
                <select id="skill_level" name="skill_level"><?php foreach ($skills as $k => $l): ?><option value="<?= e($k) ?>" <?= (($profile['skill_level'] ?? '')===$k)?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </div>
        </div>
    </div>

    <div class="pcard">
        <div class="pcard-eyebrow"><?= __('membership_health', 'Health') ?></div>
        <div class="field">
            <label class="field-label" for="medical_notes"><?= __('membership_medical', 'Medical conditions') ?></label>
            <textarea id="medical_notes" name="medical_notes" rows="2"><?= e($profile['medical_notes'] ?? '') ?></textarea>
        </div>
        <div class="field" style="margin-bottom:0;">
            <label class="field-label" for="allergies"><?= __('membership_allergies', 'Allergies') ?></label>
            <textarea id="allergies" name="allergies" rows="2"><?= e($profile['allergies'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="pcard">
        <div class="pcard-eyebrow"><?= __('membership_emergency', 'Emergency contact') ?></div>
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
        <div class="field" style="margin-bottom:0;">
            <label class="field-label" for="emergency_relation"><?= __('membership_emergency_relation', 'Relationship') ?></label>
            <input type="text" id="emergency_relation" name="emergency_relation" maxlength="80" value="<?= e($profile['emergency_relation'] ?? '') ?>">
        </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="mbtn mbtn-primary"><?= __('membership_save', 'Save changes') ?></button>
        <a href="<?= e(SLATE_URL) ?>/member?view=wallet" class="mbtn mbtn-ghost"><?= __('membership_wallet', 'Wallet') ?></a>
    </div>
</form>
