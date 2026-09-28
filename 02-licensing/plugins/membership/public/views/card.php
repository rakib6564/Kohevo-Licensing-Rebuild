<?php
/** Member area — digital member card + QR. Scope: router.php ($profile, $cid). */
if (!defined('SLATE_ROOT')) exit;

// Backfill a QR token for profiles created before the column existed.
$token = (string)($profile['qr_token'] ?? '');
if ($token === '') {
    $token = bin2hex(random_bytes(16));
    Database::update('membership_profiles', ['qr_token' => $token], 'customer_id = ? AND tenant_id = ?', [$cid, $tid]);
}

$cust    = Auth::customer();
$name    = (string)($cust['name'] ?? $cust['email'] ?? 'Member');
$avatar  = (string)($profile['avatar_path'] ?? '');
$memberNo = mb_strtoupper(mb_substr($token, 0, 8));
$siteName = Database::setting('site_name') ?: 'Kohevo';
$status  = MembershipAPI::status($cid);
$planName = $status['sub'] ? MembershipAPI::planName(['name'=>$status['sub']['plan_name']??'','name_fr'=>$status['sub']['plan_name_fr']??'']) : __('membership_no_membership', 'No membership');

// The QR encodes a stable check-in value the front desk / coach app reads.
$qrPayload = 'MEMBER:' . $token;

// Vendored QR library (MIT). Present only after it's been dropped in.
$libDisk = dirname(__DIR__, 2) . '/assets/js/qrcode-generator.js';
$libUrl  = plugin_url('membership', 'assets/js/qrcode-generator.js');
$hasLib  = is_file($libDisk);
?>
<style>
.mcard{max-width:380px;margin:0 auto;background:linear-gradient(135deg,var(--accent),var(--accent-deep,#1e3a8a));color:#fff;border-radius:var(--radius-lg);padding:22px;box-shadow:0 6px 24px rgba(0,0,0,.18);}
.mcard-top{display:flex;align-items:center;gap:12px;margin-bottom:16px;}
.mcard-avatar{width:52px;height:52px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,.25);display:grid;place-items:center;flex:none;font-size:22px;font-weight:700;}
.mcard-avatar img{width:100%;height:100%;object-fit:cover;}
.mcard-name{font-size:18px;font-weight:700;line-height:1.2;}
.mcard-sub{font-size:12px;opacity:.85;}
.mcard-qr{background:#fff;border-radius:var(--radius);padding:12px;display:grid;place-items:center;min-height:180px;}
.mcard-qr img,.mcard-qr svg{width:180px;height:180px;}
.mcard-foot{display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:12px;}
.mcard-no{font-family:var(--font-mono,monospace);letter-spacing:.08em;font-weight:700;}
</style>

<div class="mcard">
    <div class="mcard-top">
        <span class="mcard-avatar">
            <?php if ($avatar !== ''): ?><img src="<?= e(SLATE_URL . $avatar) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?><?php endif; ?>
        </span>
        <div>
            <div class="mcard-name"><?= e($name) ?></div>
            <div class="mcard-sub"><?= e($siteName) ?> · <?= e($planName) ?></div>
        </div>
    </div>
    <div class="mcard-qr" id="mcard-qr">
        <?php if (!$hasLib): ?>
            <div style="text-align:center;color:#111;">
                <div style="font-family:var(--font-mono,monospace);font-size:20px;font-weight:700;letter-spacing:.1em;"><?= e($memberNo) ?></div>
                <div class="text-xs" style="color:#666;margin-top:6px;"><?= __('membership_qr_pending', 'QR code unavailable') ?></div>
            </div>
        <?php endif; ?>
    </div>
    <div class="mcard-foot">
        <span><?= __('membership_member_no', 'Member') ?> <span class="mcard-no"><?= e($memberNo) ?></span></span>
        <?php if ($hasLib): ?><a href="#" id="mcard-dl" style="color:#fff;text-decoration:underline;"><?= __('membership_download', 'Download') ?></a><?php endif; ?>
    </div>
</div>

<?php if ($hasLib): ?>
<script src="<?= e($libUrl) ?>"></script>
<script>
(function () {
    if (typeof qrcode !== 'function') return;
    var payload = <?= json_encode($qrPayload) ?>;
    var qr = qrcode(0, 'M');           // type 0 = auto-size, error correction M
    qr.addData(payload);
    qr.make();
    var box = document.getElementById('mcard-qr');
    box.innerHTML = qr.createImgTag(6, 8);
    var img = box.querySelector('img');
    if (img) { img.style.width = '180px'; img.style.height = '180px'; img.style.imageRendering = 'pixelated'; }
    var dl = document.getElementById('mcard-dl');
    if (dl) {
        dl.addEventListener('click', function (e) {
            e.preventDefault();
            var a = document.createElement('a');
            a.href = qr.createDataURL(8, 8);
            a.download = 'member-card-<?= e($memberNo) ?>.gif';
            document.body.appendChild(a); a.click(); a.remove();
        });
    }
})();
</script>
<?php endif; ?>
