<?php
/** Studio public — class detail (premium). Scope: public/router.php. */
if (!defined('SLATE_ROOT')) { exit; }

use Slate\Module\Studio\Domain\DanceStyle;

$base = SLATE_URL . '/studio';
$id   = (int) ($_GET['id'] ?? 0);
$c    = $id > 0 ? StudioAPI::getPublicClass($id) : null;
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$styleLabel = static function (string $v): string {
    $x = DanceStyle::tryFrom($v);
    return $x ? $x->label() : ucwords(str_replace(['_', '-'], ' ', $v));
};
$money   = static fn (int $cents): string => '$' . number_format($cents / 100, 2);
$fmtTime = static fn (string $t): string => ($ts = strtotime('2000-01-01 ' . $t)) ? date('g:i A', $ts) : $t;

if ($c === null) {
    slate_portal_empty(__('studio_class_gone', 'Class not found'), __('studio_class_gone_sub', 'It may no longer be open for enrollment.'));
    echo '<p style="margin-top:16px"><a class="btn" href="' . e($base) . '">← ' . e(__('studio_back_catalog', 'Back to classes')) . '</a></p>';
    return;
}

$dow   = (int) $c['day_of_week'];
$full  = (int) $c['open_spots'] <= 0;
$lvl   = $c['level'] ? ucfirst((string) $c['level']) : '';
$img   = StudioAPI::classImage($c);
$iName = (string) ($c['instructor_name'] ?? '');
$iImg  = (string) ($c['instructor_image'] ?? '') ?: studio_gravatar_url((string) ($c['instructor_email'] ?? ''), 96);
$registerHref = $base . '?view=register&class=' . (int) $c['id'];
?>
<style>
/* No width of its own. The shell's .mapp-main is already max-width:1120px and
   centred, so a second, narrower cap here made this the one studio page that
   didn't line up with catalog / portal / register / recital. */
.scd-back { display:inline-block; margin:0 0 16px; color:var(--m-muted,#737886); text-decoration:none; font-size:14px; }
.scd-back:hover { color:var(--accent-ink, var(--accent)); }
/* Brand gradient, not a hardcoded blue→purple: this page sits in the portal
   shell alongside everything else, and a fixed blue read as another product. */
.scd-hero { position:relative; border-radius:22px; overflow:hidden; min-height:220px; display:flex; align-items:flex-end;
    background:linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 45%, #0E1117));
    color:#fff; padding:24px; }
.scd-hero img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.scd-hero::after { content:""; position:absolute; inset:0; background:linear-gradient(180deg, rgba(15,23,42,.25) 0%, rgba(15,23,42,.55) 55%, rgba(15,23,42,.86) 100%); }
.scd-hero-in { position:relative; z-index:1; }
.scd .scd-hero .scd-eyebrow { font-size:12px; letter-spacing:.18em; text-transform:uppercase; font-weight:700; color:#fff; text-shadow:0 1px 8px rgba(0,0,0,.6); }
.scd .scd-hero h1 { font-family:var(--font-display,inherit); font-size:clamp(26px,4vw,40px); font-weight:800; letter-spacing:-.02em; margin:6px 0 0; color:#fff; text-shadow:0 2px 14px rgba(0,0,0,.65); }
.scd-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:18px; margin-top:18px; }
.scd-card { border:1px solid var(--border); border-radius:18px; background:var(--surface); padding:20px; }
.scd-kv { display:flex; justify-content:space-between; gap:12px; padding:11px 0; border-bottom:1px solid var(--border); font-size:14px; }
.scd-kv:last-child { border-bottom:0; } .scd-kv .k { color:var(--muted); } .scd-kv .v { font-weight:600; color:var(--text); text-align:right; }
.scd-instr { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
.scd-ava { width:52px; height:52px; border-radius:50%; position:relative; overflow:hidden; display:grid; place-items:center; font-size:17px; font-weight:800; color:var(--on-accent); background:var(--accent); flex:none; }
.scd-ava img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.scd-price { font-size:30px; font-weight:800; letter-spacing:-.02em; }
.scd-price span { font-size:14px; color:var(--muted); font-weight:500; }
/* Pill colours come from _pubui.php so the catalog and this page agree. */
.scd-spots { display:inline-block; font-size:12px; font-weight:600; padding:3px 11px; border-radius:999px; margin:10px 0; }
.scd-signup { display:block; text-align:center; padding:13px; border-radius:13px; background:var(--st-ink); color:var(--st-ink-on); font-weight:700; text-decoration:none; margin-top:6px; transition:.15s; }
.scd-signup:hover { background:var(--st-accent); color:var(--on-accent); }
.scd-note { font-size:12.5px; color:var(--muted); margin-top:10px; text-align:center; }
@media (max-width:700px){ .scd-grid{ grid-template-columns:1fr; } }
</style>

<div class="scd">
    <a class="scd-back" href="<?= e($base) ?>">← <?= e(__('studio_back_catalog', 'Back to classes')) ?></a>

    <div class="scd-hero">
        <?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="" onerror="this.remove()"><?php endif; ?>
        <div class="scd-hero-in">
            <div class="scd-eyebrow"><?= e($styleLabel((string) $c['style']) . ($lvl ? ' · ' . $lvl : '')) ?></div>
            <h1><?= e($c['name']) ?></h1>
        </div>
    </div>

    <?php $desc = StudioAPI::classMeta($c, 'description'); if ($desc !== ''): ?>
        <div class="scd-card" style="margin-top:18px">
            <p style="margin:0;font-size:14.5px;line-height:1.65;color:var(--text);white-space:pre-line"><?= e($desc) ?></p>
        </div>
    <?php endif; ?>

    <div class="scd-grid">
        <div class="scd-card">
            <?php if ($iName): ?>
            <div class="scd-instr">
                <span class="scd-ava"><?= e(studio_initials($iName)) ?><?php if ($iImg): ?><img src="<?= e($iImg) ?>" alt="" onerror="this.remove()"><?php endif; ?></span>
                <div>
                    <div style="font-size:12px;color:var(--muted)"><?= __('studio_instructor', 'Instructor') ?></div>
                    <div style="font-weight:700"><?= e($iName) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="scd-kv"><span class="k"><?= __('studio_schedule', 'Schedule') ?></span><span class="v"><?= e(($days[$dow] ?? '') . 's · ' . $fmtTime((string) $c['start_time']) . '–' . $fmtTime((string) $c['end_time'])) ?></span></div>
            <div class="scd-kv"><span class="k"><?= __('studio_term', 'Term') ?></span><span class="v"><?= e(date('M j', strtotime((string) $c['session_start'])) . ' – ' . date('M j, Y', strtotime((string) $c['session_end']))) ?></span></div>
            <div class="scd-kv"><span class="k"><?= __('studio_ages', 'Ages') ?></span><span class="v"><?= (int) $c['age_min'] ?>–<?= (int) $c['age_max'] ?></span></div>
            <div class="scd-kv"><span class="k"><?= __('studio_availability', 'Availability') ?></span><span class="v"><?= $full ? e(__('studio_class_full', 'Full — waitlist')) : e(sprintf(__('studio_spots_left', '%d of %d spots open'), (int) $c['open_spots'], (int) $c['capacity'])) ?></span></div>
            <?php $loc = StudioAPI::classMeta($c, 'location'); if ($loc !== ''): ?>
                <div class="scd-kv"><span class="k"><?= __('studio_where', 'Where') ?></span><span class="v"><?= e($loc) ?></span></div>
            <?php endif; ?>
            <?php
            // The join link, only if the viewer has a dancer in this class.
            // classJoinUrl returns '' for everyone else, so a public reader
            // cannot obtain the meeting URL for a children's class.
            $join = StudioAPI::classJoinUrl($c, class_exists('Auth') ? Auth::customerId() : null);
            if (StudioAPI::classMeta($c, 'virtual_url') !== ''):
            ?>
                <div class="scd-kv">
                    <span class="k"><?= __('studio_online', 'Online') ?></span>
                    <span class="v">
                        <?php if ($join !== ''): ?>
                            <a href="<?= e($join) ?>" rel="noopener noreferrer" target="_blank"><?= __('studio_join_link', 'Join the class') ?></a>
                        <?php else: ?>
                            <?= __('studio_online_enrolled', 'Link shared with enrolled families') ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="scd-card" style="text-align:center;">
            <div class="scd-price"><?= e($money((int) $c['price_cents'])) ?> <span>/ <?= __('studio_term_lc', 'term') ?></span></div>
            <div><span class="scd-spots <?= $full ? 'is-full' : '' ?>"><?= $full ? __('studio_waitlist', 'Waitlist') : sprintf(__('studio_n_open', '%d spots open'), (int) $c['open_spots']) ?></span></div>
            <a class="scd-signup" href="<?= e($registerHref) ?>"><?= $full ? e(__('studio_join_waitlist', 'Join the waitlist')) : e(__('studio_register_now', 'Register')) ?></a>
            <div class="scd-note"><?= __('studio_login_to_register', 'You\'ll sign in to complete registration.') ?></div>
        </div>
    </div>
</div>
