<?php
/**
 * Member app — home dashboard (premium, mobile-app style).
 * Scope: router.php  ($cid, $tid, $status, $profile, $selfUrl, $cust, $accent).
 */
if (!defined('SLATE_ROOT')) exit;

$sub        = $status['sub'];
$memberName = (string)($cust['name'] ?? $cust['email'] ?? 'Member');
$memberNo   = MembershipAPI::memberNumber($cid);
$subs       = MembershipAPI::subscriptionsForCustomer($cid);
$stats      = MembershipAPI::sessionStats($cid);
$recent     = MembershipAPI::recentAttendance($cid, 5);
$breakdown  = MembershipAPI::sessionBreakdown($cid);
$weeks      = MembershipAPI::weeklyActivity($cid);
$courses    = MembershipAPI::courseAccess($cid);
$hasIns     = MembershipAPI::hasInsurance($cid);
$insFee     = MembershipAPI::insuranceFeeCents();
$insInUse   = MembershipAPI::insuranceInUse();

$activePlan = $sub ? MembershipAPI::plan((int)$sub['plan_id']) : null;
$quota      = (int)($activePlan['session_quota'] ?? 0);
$courseId   = (int)($activePlan['course_id'] ?? 0);
$courseName = '';
foreach ($courses as $c) { if ($c['id'] === $courseId) { $courseName = $c['name']; } }

// Used/remaining come from the SAME per-subscription-term count the
// booking gate uses (courseSubscriptionStatus) — it only counts bookings
// since the CURRENT active subscription's starts_at, so a renewal resets
// the count instead of it accumulating forever across terms.
$courseStatus = $courseId > 0 ? MembershipAPI::courseSubscriptionStatus($cid, $courseId) : null;
$used         = $courseStatus['used'] ?? $stats['total'];
$remaining    = $courseStatus !== null ? $courseStatus['remaining'] : ($quota > 0 ? max(0, $quota - $used) : null);

$token  = (string)($profile['qr_token'] ?? '');
$qrLib  = is_file(dirname(__DIR__, 2) . '/assets/js/qrcode-generator.js');
$qrUrl  = plugin_url('membership', 'assets/js/qrcode-generator.js');

$fmtDate = fn($d) => $d ? I18n::localDate('j M Y', strtotime($d)) : '—';
$palette = ['var(--m-blue)', 'var(--accent)', 'var(--m-green)', 'var(--m-amber)', '#7C3AED', '#0d9488'];
?>

<!-- Greeting -->
<?php
slate_portal_welcome([
    'eyebrow' => __('membership', 'Membership'),
    'title'   => __('membership_hi', 'Hi'),
    'name'    => explode(' ', $memberName)[0],
    'sub'     => $sub
        ? MembershipAPI::planName(['name' => $sub['plan_name'] ?? '', 'name_fr' => $sub['plan_name_fr'] ?? ''])
        : __('membership_none_title', 'No active membership'),
    'status'  => $sub
        ? ($status['state'] === 'expiring'
            ? ['label' => __('membership_expiring', 'Expiring soon'), 'tone' => 'amber']
            : ['label' => __('membership_active', 'Active'),          'tone' => 'green'])
        : ['label' => __('membership_none_short', 'No plan'),         'tone' => 'amber'],
    'clock'   => true,
]);
?>

<?php /* One bento wall, not three stacked two-column rows. A grid row is as
   tall as its tallest cell, so a short card left a hole beside a tall one and
   the next row could not rise into it — the void under "Recent attendance".
   .dash/.dash-col collapse to display:contents inside here, so every card
   becomes a direct item of one column flow and the columns pack level. */ ?>
<div class="bento-wall">
<!-- Row A: activity (left) · identity (right) -->
<div class="dash">
    <div class="dash-col">

        <div class="pcard">
            <div class="howto">
                <span class="howto-ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9V7a2 2 0 0 1 2-2h2M17 5h2a2 2 0 0 1 2 2v2M21 15v2a2 2 0 0 1-2 2h-2M7 19H5a2 2 0 0 1-2-2v-2"/><rect x="8" y="8" width="8" height="8" rx="1.5"/></svg></span>
                <div>
                    <b><?= __('membership_howto_h', 'How to check in') ?></b>
                    <p><?= __('membership_howto_p', 'Show your QR code at reception or the self-service kiosk. Staff scan it to mark your attendance instantly.') ?></p>
                </div>
            </div>
        </div>

        <div class="stat-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;width:100%;">
            <div class="stat stat--accent" style="min-width:0;"><b><?= (int)$stats['total'] ?></b><span><?= __('membership_total_sessions', 'Total sessions') ?></span></div>
            <div class="stat" style="min-width:0;"><b><?= (int)$stats['month'] ?></b><span><?= __('membership_this_month', 'This month') ?></span></div>
            <div class="stat" style="min-width:0;"><b><?= (int)$stats['missed'] ?></b><span><?= __('membership_missed', 'Missed') ?></span></div>
        </div>

        <div class="pcard">
            <div class="pcard-eyebrow"><?= __('membership_recent_att', 'Recent attendance') ?></div>
            <?php if (!$recent): ?>
                <p class="qr-meta" style="text-align:center;padding:10px 0;"><?= __('membership_no_att', 'No sessions yet — book your first one.') ?></p>
            <?php else: ?>
                <div class="att">
                    <?php foreach ($recent as $a):
                        $present = in_array($a['status'], ['confirmed','completed'], true);
                        $absent  = $a['status'] === 'no_show';
                        $dot = $present ? 'var(--m-green)' : ($absent ? 'var(--m-amber)' : 'var(--m-muted)');
                    ?>
                        <div class="att-row">
                            <span class="att-dot" style="background:<?= $dot ?>;"></span>
                            <div class="att-main">
                                <b><?= e(I18n::localDate('D j M Y', strtotime($a['starts_at']))) ?></b>
                                <span><?= e((string)($a['service_name'] ?? __('membership_session','Session'))) ?></span>
                            </div>
                            <span class="att-time"><?= $present ? e(date('H:i', strtotime($a['starts_at']))) : '—' ?></span>
                            <?php if ($present): ?><span class="pill pill-green">✓ <?= __('membership_present','Present') ?></span>
                            <?php elseif ($absent): ?><span class="pill pill-amber">✕ <?= __('membership_absent','Absent') ?></span>
                            <?php else: ?><span class="pill pill-blue"><?= e(ucfirst((string)$a['status'])) ?></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-col">

        <!-- QR member card -->
        <div class="pcard qr-card">
            <div class="qr-box" id="dash-qr">
                <div style="text-align:center;color:#111;">
                    <div class="qr-id"><?= e($memberNo) ?></div>
                    <?php if (!$qrLib): ?><div class="qr-meta" style="margin-top:6px;"><?= __('membership_qr_pending','QR code unavailable') ?></div><?php endif; ?>
                </div>
            </div>
            <div class="qr-meta"><?= slate_icon('lock') ?> <?= __('membership_qr_unique', 'Unique to your account · refreshes daily') ?></div>
            <div class="qr-actions">
                <button type="button" class="mbtn mbtn-primary mbtn-block" id="dash-qr-save"<?= $qrLib?'':' disabled style="opacity:.5"' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                    <?= __('membership_save_qr', 'Save QR') ?>
                </button>
                <a href="<?= e($selfUrl) ?>?view=card" class="mbtn mbtn-ghost mbtn-block"><?= __('membership_card', 'Card') ?></a>
            </div>
        </div>

        <!-- Member details -->
        <div class="pcard">
            <div class="pcard-eyebrow"><?= __('membership_member_details', 'Member details') ?></div>
            <div class="kvr"><span class="kvr-k"><?= __('membership_full_name','Full name') ?></span><span class="kvr-v"><?= e($memberName) ?></span></div>
            <div class="kvr"><span class="kvr-k"><?= __('membership_member_id','Member ID') ?></span><span class="kvr-v mono"><?= e($memberNo) ?></span></div>
            <div class="kvr"><span class="kvr-k"><?= __('membership_active_plan','Active plan') ?></span>
                <span class="kvr-v"><?php if ($sub): ?><span class="pill pill-green"><?= e(MembershipAPI::planName(['name'=>$sub['plan_name']??'','name_fr'=>$sub['plan_name_fr']??''])) ?></span><?php else: ?>—<?php endif; ?></span></div>
            <?php if ($courseName !== ''): ?>
            <div class="kvr"><span class="kvr-k"><?= __('membership_course_access','Course access') ?></span><span class="kvr-v"><span class="pill pill-blue"><?= e($courseName) ?></span></span></div>
            <?php endif; ?>
            <div class="kvr"><span class="kvr-k"><?= __('membership_valid_until','Valid until') ?></span><span class="kvr-v"><?= e($fmtDate($sub['expires_at'] ?? null)) ?></span></div>
            <?php if ($quota > 0): ?>
            <div class="kvr"><span class="kvr-k"><?= __('membership_sessions_used','Sessions used') ?></span><span class="kvr-v"><?= (int)$used ?> / <?= (int)$quota ?></span></div>
            <?php endif; ?>
        </div>

        <!-- Sessions remaining -->
        <?php if ($quota > 0): $pct = $quota>0 ? min(100, round($used/$quota*100)) : 0; ?>
        <div class="pcard">
            <div class="pcard-eyebrow"><?= e($courseName !== '' ? $courseName : __('membership','Membership')) ?> · <?= __('membership_sessions_remaining','Sessions remaining') ?></div>
            <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:14px;">
                <span class="remain-num"><?= (int)$remaining ?></span>
                <div><b style="font-size:15px;"><?= __('membership_sessions_left','sessions left') ?></b><br><span class="qr-meta"><?= __('membership_of','of') ?> <?= (int)$quota ?> · <?= (int)$used ?> <?= __('membership_used','used') ?></span></div>
            </div>
            <div class="bar bar-lg"><i style="width:<?= $pct ?>%;"></i></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Row B: plans (left) · session breakdown (right) -->
<div class="dash">
    <div class="dash-col">
        <div class="pcard">
            <div class="pcard-eyebrow"><?= __('membership_my_plans', 'My membership plans') ?></div>
            <div class="dash-plans" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr));gap:14px;">
                <?php
                $shown = 0;
                foreach ($subs as $s):
                    if (!in_array($s['status'], ['active'], true)) continue;
                    $shown++;
                ?>
                    <div class="pplan is-active">
                        <div class="pplan-top"><b><?= e(MembershipAPI::planName(['name'=>$s['plan_name']??'','name_fr'=>$s['plan_name_fr']??''])) ?></b><span class="pill pill-green">✓ <?= __('membership_active','Active') ?></span></div>
                        <div class="pplan-price"><?= e(MembershipAPI::money((int)$s['amount_cents'], $s['currency'])) ?></div>
                        <p class="pplan-sub"><?= !empty($s['expires_at']) ? __('membership_valid_until','Valid until').' '.e($fmtDate($s['expires_at'])) : '' ?></p>
                    </div>
                <?php endforeach; ?>

                <!-- Insurance / Assurance add-on — only shown when insurance is
                     actually configured (required on a service, offered on a
                     plan, or the customer already holds it); otherwise it's a
                     permanently-inactive card that unlocks nothing. -->
                <?php if ($insInUse || $hasIns): ?>
                <div class="pplan <?= $hasIns ? 'is-active' : 'is-off' ?>">
                    <div class="pplan-top"><b><?= __('membership_insurance','Insurance') ?></b>
                        <span class="pill <?= $hasIns?'pill-green':'pill-muted' ?>"><?= $hasIns ? '✓ '.__('membership_active','Active') : __('membership_not_active','Not active') ?></span></div>
                    <div class="pplan-price"><?= $insFee>0 ? e(MembershipAPI::money($insFee)) : '—' ?> <small><?= $insFee>0 ? __('membership_per_year','/year') : '' ?></small></div>
                    <p class="pplan-sub"><?= $hasIns ? __('membership_ins_covered','You are covered — boxing courses unlocked.') : __('membership_ins_unlock','Add insurance to unlock insurance-required courses.') ?></p>
                    <?php if (!$hasIns && $insFee>0): ?>
                        <a href="<?= e($selfUrl) ?>?view=plans" class="mbtn mbtn-primary mbtn-block">+ <?= __('membership_add_insurance','Add insurance') ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dash-col">
        <div class="pcard">
            <div class="pcard-eyebrow"><?= __('membership_breakdown', 'Session breakdown') ?></div>
            <?php
            $btotal = 0; foreach ($breakdown as $b) $btotal += (int)$b['cnt'];
            if ($btotal === 0): ?>
                <p class="qr-meta" style="text-align:center;padding:20px 0;"><?= __('membership_no_att','No sessions yet — book your first one.') ?></p>
            <?php else:
                $stops = []; $accp = 0; $i = 0;
                foreach ($breakdown as $b) {
                    $p = round((int)$b['cnt'] / $btotal * 100, 2);
                    $col = $palette[$i % count($palette)];
                    $stops[] = "$col {$accp}% " . ($accp + $p) . "%";
                    $accp += $p; $i++;
                }
            ?>
                <div class="donut-wrap">
                    <div class="donut" style="background:conic-gradient(<?= implode(',', $stops) ?>);">
                        <div class="donut-center"><b><?= $btotal ?></b><span><?= __('membership_sessions','sessions') ?></span></div>
                    </div>
                    <div class="legend">
                        <?php $i=0; foreach ($breakdown as $b): ?>
                            <div class="legend-row"><i style="background:<?= $palette[$i % count($palette)] ?>;"></i><?= e((string)$b['label']) ?><b><?= (int)$b['cnt'] ?></b></div>
                        <?php $i++; endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Row C: weekly activity (left) · course progress (right) -->
<div class="dash">
    <div class="dash-col">
        <div class="pcard">
            <div class="pcard-eyebrow"><?= __('membership_weekly', 'Weekly activity') ?> — <?= e(mb_strtoupper(I18n::localDate('F Y'))) ?></div>
            <?php $wmax = max(1, max($weeks)); $wsum = array_sum($weeks); ?>
            <div class="weeks">
                <?php $wi=0; foreach ($weeks as $n => $cnt): $h = round($cnt / $wmax * 100); $wi++; ?>
                    <div class="week">
                        <span class="week-n"><?= (int)$cnt ?></span>
                        <div class="week-bar<?= $wi%2===0?' alt':'' ?>" style="height:<?= max(6, $h) ?>%;"></div>
                        <span class="week-l"><?= __('membership_wk','Wk') ?> <?= (int)$n ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="weeks-foot"><span><?= (int)$wsum ?> <?= __('membership_sessions','sessions') ?></span><span><?= (int)$wmax ?> <?= __('membership_max','max') ?></span></div>
        </div>
    </div>

    <div class="dash-col">
        <div class="pcard">
            <div class="pcard-eyebrow"><?= __('membership_course_progress', 'Course progress') ?></div>
            <?php if (!$courses): ?>
                <p class="qr-meta" style="text-align:center;padding:14px 0;"><?= __('membership_no_courses','No courses available.') ?></p>
            <?php else: foreach ($courses as $c):
                $cq = $quota > 0 && $c['id'] === $courseId ? $quota : 0;
                $cp = $cq > 0 ? min(100, round($c['used']/$cq*100)) : ($c['used']>0?100:0);
            ?>
                <div class="prog-row">
                    <span class="prog-ic <?= $c['locked']?'lock':'' ?>"><?= $c['locked'] ? slate_icon('lock') : slate_icon('award') ?></span>
                    <div class="prog-main">
                        <b><?= e($c['name']) ?></b>
                        <div class="bar"><i style="width:<?= $c['locked']?0:$cp ?>%;<?= $c['locked']?'background:var(--m-line);':'' ?>"></i></div>
                        <div class="prog-foot">
                            <span><?= $c['locked'] ? __('membership_requires_ins','Requires insurance') : ($cq>0 ? (int)$c['used'].' / '.$cq.' '.__('membership_sessions','sessions') : (int)$c['used'].' '.__('membership_sessions','sessions')) ?></span>
                            <span><?php if ($c['locked']): ?><span class="pill pill-amber">⚠ <?= __('membership_locked','Locked') ?></span><?php else: ?><span class="pill pill-green">✓ <?= __('membership_enrolled','Enrolled') ?></span><?php endif; ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
</div><!-- .bento-wall -->

<?php if ($qrLib && $token !== ''): ?>
<script src="<?= e($qrUrl) ?>"></script>
<script>
(function(){
    if (typeof qrcode !== 'function') return;
    var qr = qrcode(0,'M'); qr.addData('MEMBER:<?= e($token) ?>'); qr.make();
    var box = document.getElementById('dash-qr');
    box.innerHTML = qr.createImgTag(5, 8);
    var img = box.querySelector('img'); if(img){img.style.width='200px';img.style.height='200px';img.style.imageRendering='pixelated';}
    var save = document.getElementById('dash-qr-save');
    if (save) save.addEventListener('click', function(){
        var a=document.createElement('a'); a.href=qr.createDataURL(8,8); a.download='member-<?= e($memberNo) ?>.gif';
        document.body.appendChild(a); a.click(); a.remove();
    });
})();
</script>
<?php endif; ?>
