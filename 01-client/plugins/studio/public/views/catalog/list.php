<?php
/** Studio public — premium weekly class schedule ("Find a Class"). Scope: public/router.php. */
if (!defined('SLATE_ROOT')) { exit; }

use Slate\Module\Studio\Domain\DanceStyle;

$catalog = StudioAPI::getPublicCatalog();
$base    = SLATE_URL . '/studio';
$dayAbbr = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$dayFull = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$styleLabel = static function (string $v): string {
    $c = DanceStyle::tryFrom($v);
    return $c ? $c->label() : ucwords(str_replace(['_', '-'], ' ', $v));
};
$money = static fn (int $cents): string => '$' . number_format($cents / 100, 2);
$mins  = static function (string $a, string $b): int {
    [$ah, $am] = array_pad(explode(':', $a), 2, 0);
    [$bh, $bm] = array_pad(explode(':', $b), 2, 0);
    return max(0, ((int) $bh * 60 + (int) $bm) - ((int) $ah * 60 + (int) $am));
};
$fmtTime = static function (string $t): string {
    $ts = strtotime('2000-01-01 ' . $t);
    return $ts ? date('g:i A', $ts) : $t;
};

// Distinct styles + instructors for the filter menus.
$styles = $instructors = [];
foreach ($catalog as $c) {
    $styles[(string) $c['style']] = $styleLabel((string) $c['style']);
    if ($c['instructor_name']) { $instructors[(int) $c['instructor_id']] = (string) $c['instructor_name']; }
}
asort($styles); asort($instructors);

$siteName = Database::setting('site_name') ?: __('studio', 'Studio');
?>
<style>
/* --st-ink / --st-accent / the availability pills come from _pubui.php. */

.st-toolbar { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin: 6px 0 14px; }
.st-week { display:flex; align-items:center; gap:10px; }
.st-week h2 { margin:0; font-size:17px; font-weight:700; letter-spacing:-.01em; }
.st-nav { display:inline-flex; gap:6px; }
.st-nav button { width:36px; height:36px; border-radius:50%; border:1px solid var(--border); background:var(--surface); cursor:pointer; font-size:16px; color:var(--text-2,#334155); display:grid; place-items:center; transition:.15s; }
.st-nav button:hover { border-color:var(--accent-ink, var(--st-accent)); color:var(--accent-ink, var(--st-accent)); }
.st-filters { display:flex; gap:10px; flex-wrap:wrap; }
.st-select { position:relative; }
.st-select select {
    appearance:none; -webkit-appearance:none; font:inherit; font-size:14px; font-weight:600; color:var(--text);
    padding:10px 38px 10px 16px; border:1px solid var(--border); border-radius:999px; background:var(--surface); cursor:pointer; min-width:150px;
}
.st-select::after { content:"▾"; position:absolute; right:15px; top:50%; transform:translateY(-50%); pointer-events:none; color:var(--muted); font-size:12px; }
.st-select select:focus { outline:none; border-color:var(--accent-ink, var(--st-accent)); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent-ink, #3F4450) 30%, transparent); }

/* Day strip */
.st-days { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; margin: 4px 0 22px; }
.st-day { display:flex; flex-direction:column; align-items:center; gap:8px; padding:12px 4px; border:1px solid transparent; border-radius:18px; background:transparent; cursor:pointer; transition:.15s; }
.st-day:hover { background:var(--surface-2); }
.st-day .st-day-name { font-size:12px; font-weight:600; color:var(--muted); letter-spacing:.02em; }
.st-day .st-day-num { width:46px; height:46px; border-radius:50%; display:grid; place-items:center; font-size:18px; font-weight:700; color:var(--text); border:1px solid var(--border); background:var(--surface); transition:.15s; }
.st-day .st-day-count { font-size:11px; color:var(--muted); min-height:14px; }
.st-day.is-active .st-day-num { background:var(--st-ink); color:var(--st-ink-on); border-color:var(--st-ink); box-shadow:0 6px 16px color-mix(in srgb, var(--st-ink) 30%, transparent); }
.st-day.is-active .st-day-name { color:var(--text); }
/* A day with no classes is de-emphasised by tint, not by opacity: a blanket
   opacity:.5 dropped --muted text to roughly 2.5:1 and made the date unreadable. */
.st-day.is-empty .st-day-num { background:var(--surface-2); color:var(--muted); border-color:var(--border); }
.st-day.is-empty .st-day-name { color:var(--subtle); }
.st-day.is-empty.is-active .st-day-num { background:var(--st-ink); color:var(--st-ink-on); border-color:var(--st-ink); }

.st-dayhead { font-size:15px; font-weight:700; margin:0 0 14px; color:var(--text); }
.st-dayhead span { color:var(--muted); font-weight:500; }

/* Class rows — compact. Every class is four facts (when, what, who, price), so
   the row is one line of content with a single wrapping meta line under the
   name, not the three stacked blocks it used to be. Halves the row height and
   lets a full Saturday fit on one screen. */
.st-list { display:flex; flex-direction:column; gap:8px; }
.st-class {
    display:grid; grid-template-columns: 66px 44px minmax(0,1fr) auto; gap:14px; align-items:center;
    padding:10px 14px; border:1px solid var(--border); border-radius:14px; background:var(--surface);
    transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}
.st-class:hover { border-color:color-mix(in srgb, var(--st-accent) 55%, var(--border));
    box-shadow:0 8px 22px -14px rgba(15,23,42,.28); transform:translateY(-1px); }
.st-when { text-align:left; }
.st-when .st-t { font-size:14.5px; font-weight:750; letter-spacing:-.01em; color:var(--text);
    font-variant-numeric:tabular-nums; white-space:nowrap; }
.st-when .st-dur { font-size:11px; color:var(--muted); margin-top:1px; }
.st-thumb { width:44px; height:44px; border-radius:11px; overflow:hidden; display:grid; place-items:center;
    background:linear-gradient(135deg, color-mix(in srgb, var(--accent) 22%, transparent), color-mix(in srgb, var(--accent) 8%, transparent)); }
.st-thumb img { width:100%; height:100%; object-fit:cover; }
.st-thumb .st-thumb-i { font-size:16px; font-weight:800; color:var(--accent-ink, #955E74); opacity:.75; }
.st-info { min-width:0; }
.st-info .st-name { font-size:14.5px; font-weight:700; letter-spacing:-.01em; color:var(--text);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* One wrapping line: type · level · ages · instructor. */
.st-meta { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:3px; }
.st-chip { font-size:10.5px; font-weight:600; padding:1px 7px; border-radius:999px;
    background:var(--surface-2); color:var(--text-2,#475569); border:1px solid var(--border); }
.st-byline { display:inline-flex; align-items:center; gap:5px; color:var(--muted); font-size:11.5px; }
.st-ava { width:18px; height:18px; border-radius:50%; position:relative; overflow:hidden; display:grid;
    place-items:center; font-size:8px; font-weight:700; color:var(--on-accent); background:var(--st-accent); flex:none; }
.st-ava img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
/* Price + availability read as one figure, the action sits beside it. */
.st-cta { display:flex; align-items:center; gap:14px; }
.st-cta-num { display:flex; flex-direction:column; align-items:flex-end; gap:3px; }
.st-price { font-size:15px; font-weight:750; color:var(--text); font-variant-numeric:tabular-nums; }
.st-spots { font-size:10.5px; font-weight:600; padding:1px 8px; border-radius:999px; white-space:nowrap; }
.st-signup { display:inline-flex; align-items:center; justify-content:center; padding:8px 16px; border-radius:10px;
    background:var(--st-ink); color:var(--st-ink-on); font-weight:700; font-size:13px; text-decoration:none;
    white-space:nowrap; transition:filter .15s ease, transform .15s ease; }
.st-signup:hover { filter:brightness(.94); transform:translateY(-1px); }
.st-class.is-hidden { display:none; }
.st-empty { text-align:center; color:var(--muted); padding:48px 20px; border:1px dashed var(--border-stronger); border-radius:18px; }
.st-empty p { margin:0 0 14px; }
.st-empty .btn { font:inherit; font-size:13px; font-weight:600; padding:9px 18px; border-radius:999px; border:1px solid var(--border-stronger); background:var(--surface); color:var(--text); cursor:pointer; }
.st-empty .btn:hover { border-color:var(--accent-ink, var(--st-accent)); color:var(--accent-ink, var(--st-accent)); }

@media (max-width: 760px) {
    .st-days { grid-template-columns:repeat(7,1fr); gap:4px; }
    .st-day { padding:10px 0; }
    .st-day .st-day-num { width:38px; height:38px; font-size:15px; }
    .st-day .st-day-count { display:none; }
    /* Thumb and time share the top line with the price; name + meta below. */
    .st-class { grid-template-columns: auto minmax(0,1fr); grid-template-areas: "thumb info" "cta cta"; gap:10px 12px; padding:12px; }
    .st-thumb { grid-area:thumb; width:40px; height:40px; }
    .st-info  { grid-area:info; }
    .st-when  { display:none; }
    .st-info .st-name { white-space:normal; }
    /* Time moves into the meta line so it isn't lost with the column. */
    .st-info .st-name::after { content:" · " attr(data-when); color:var(--muted); font-weight:600; font-size:13px; }
    .st-cta { grid-area:cta; justify-content:space-between; padding-top:8px; border-top:1px solid var(--border); }
    .st-cta-num { flex-direction:row; align-items:baseline; gap:8px; }
}
/* Two 150px-min selects plus the gap overflow a 320px viewport, and the
   scrollbars are hidden app-wide — so stack them full width instead. */
@media (max-width: 560px) {
    .st-toolbar { gap:10px; }
    .st-week { width:100%; }
    .st-filters { width:100%; flex-direction:column; gap:8px; }
    .st-select { display:block; }
    .st-select select { width:100%; min-width:0; }
}
</style>

<?php
// The shared welcome hero rather than this page's own centred one, so the
// catalog opens the same way as the portal, the member area and the studio
// dashboard. Total classes doubles as the status line.
slate_portal_welcome([
    'eyebrow' => $siteName,
    'title'   => __('studio_find_a_class', 'Find a Class'),
    'sub'     => __('studio_find_sub', 'Weekly classes for every age and level. Pick a day to see what\'s on.'),
    'status'  => $catalog
        ? ['label' => sprintf(__('studio_n_classes_open', '%d classes open'), count($catalog)), 'tone' => 'green']
        : ['label' => __('studio_none_open', 'Nothing open yet'), 'tone' => 'amber'],
]);
?>

<div class="st">
    <?php studio_pub_subnav('classes'); ?>
    <div class="st-toolbar">
        <div class="st-week">
            <div class="st-nav"><button type="button" id="st-prev" aria-label="Previous week">‹</button><button type="button" id="st-next" aria-label="Next week">›</button></div>
            <h2 id="st-weeklabel"></h2>
        </div>
        <div class="st-filters">
            <span class="st-select"><select id="st-style"><option value=""><?= __('studio_all_styles', 'All class types') ?></option>
                <?php foreach ($styles as $v => $label): ?><option value="<?= e($v) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select></span>
            <span class="st-select"><select id="st-instr"><option value=""><?= __('studio_all_instructors', 'All instructors') ?></option>
                <?php foreach ($instructors as $iid => $iname): ?><option value="<?= (int) $iid ?>"><?= e($iname) ?></option><?php endforeach; ?>
            </select></span>
        </div>
    </div>

    <div class="st-days" id="st-days" role="tablist" aria-label="<?= e(__('studio_pick_day', 'Pick a day')) ?>"></div>

    <div class="st-dayhead" id="st-dayhead" role="status" aria-live="polite"></div>

    <?php if (!$catalog): ?>
        <div class="st-empty"><?= __('studio_catalog_empty', 'No classes are open right now. Please check back soon.') ?></div>
    <?php else: ?>
    <div class="st-list" id="st-list">
        <?php foreach ($catalog as $c):
            $dow   = (int) $c['day_of_week'];
            $full  = (int) $c['open_spots'] <= 0;
            $img   = StudioAPI::classImage($c);
            $iName = (string) ($c['instructor_name'] ?? '');
            $iImg  = (string) ($c['instructor_image'] ?? '') ?: studio_gravatar_url((string) ($c['instructor_email'] ?? ''), 60);
            $lvl   = $c['level'] ? ucfirst((string) $c['level']) : '';
        ?>
        <article class="st-class" data-dow="<?= $dow ?>" data-style="<?= e((string) $c['style']) ?>" data-instructor="<?= (int) $c['instructor_id'] ?>">
            <div class="st-when">
                <div class="st-t"><?= e($fmtTime((string) $c['start_time'])) ?></div>
                <div class="st-dur"><?= (int) $mins((string) $c['start_time'], (string) $c['end_time']) ?> <?= __('studio_min', 'min') ?></div>
            </div>
            <div class="st-thumb">
                <?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="" loading="lazy" onerror="this.remove();this.parentNode.innerHTML='<span class=&quot;st-thumb-i&quot;><?= e(mb_substr($styleLabel((string) $c['style']),0,1)) ?></span>'">
                <?php else: ?><span class="st-thumb-i"><?= e(mb_substr($styleLabel((string) $c['style']), 0, 1)) ?></span><?php endif; ?>
            </div>
            <div class="st-info">
                <div class="st-name" data-when="<?= e($fmtTime((string) $c['start_time'])) ?>"><?= e($c['name']) ?></div>
                <div class="st-meta">
                    <span class="st-chip"><?= e($styleLabel((string) $c['style'])) ?></span>
                    <?php if ($lvl): ?><span class="st-chip"><?= e($lvl) ?></span><?php endif; ?>
                    <span class="st-chip"><?= __('studio_ages', 'Ages') ?> <?= (int) $c['age_min'] ?>–<?= (int) $c['age_max'] ?></span>
                    <?php if ($iName): ?>
                        <span class="st-byline">
                            <span class="st-ava"><?= e(studio_initials($iName)) ?><?php if ($iImg): ?><img src="<?= e($iImg) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
                            <?= e($iName) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="st-cta">
                <div class="st-cta-num">
                    <span class="st-price"><?= e($money((int) $c['price_cents'])) ?></span>
                    <span class="st-spots <?= $full ? 'is-full' : '' ?>"><?= $full ? __('studio_waitlist', 'Waitlist') : sprintf(__('studio_n_open', '%d open'), (int) $c['open_spots']) ?></span>
                </div>
                <a class="st-signup" href="<?= e($base) ?>?view=class&amp;id=<?= (int) $c['id'] ?>"><?= $full ? __('studio_join', 'Join') : __('studio_sign_up', 'Sign Up') ?></a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <div class="st-empty" id="st-none" hidden>
        <p><?= __('studio_no_classes_day', 'No classes on this day. Try another day or filter.') ?></p>
        <button type="button" class="btn" id="st-clear" hidden><?= __('studio_clear_filters', 'Clear filters') ?></button>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var dayNames = <?= json_encode($dayAbbr) ?>, dayFull = <?= json_encode($dayFull) ?>;
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var daysEl = document.getElementById('st-days'), listEl = document.getElementById('st-list');
    if (!daysEl) return;
    var cards = listEl ? Array.prototype.slice.call(listEl.querySelectorAll('.st-class')) : [];
    var styleSel = document.getElementById('st-style'), instrSel = document.getElementById('st-instr');
    var noneEl = document.getElementById('st-none'), headEl = document.getElementById('st-dayhead'), weekEl = document.getElementById('st-weeklabel');
    var clearEl = document.getElementById('st-clear');

    var today = new Date(); today.setHours(0,0,0,0);
    var weekStart = new Date(today);            // 7-day window starting today
    var selDow = today.getDay();

    function countFor(dow) {
        var st = styleSel.value, ins = instrSel.value, n = 0;
        cards.forEach(function (c) {
            if (+c.dataset.dow !== dow) return;
            if (st && c.dataset.style !== st) return;
            if (ins && c.dataset.instructor !== ins) return;
            n++;
        });
        return n;
    }
    function renderDays() {
        daysEl.innerHTML = '';
        for (var i = 0; i < 7; i++) {
            var d = new Date(weekStart); d.setDate(weekStart.getDate() + i);
            var dow = d.getDay(), n = countFor(dow);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'st-day' + (dow === selDow ? ' is-active' : '') + (n === 0 ? ' is-empty' : '');
            btn.dataset.dow = dow;
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', dow === selDow ? 'true' : 'false');
            btn.setAttribute('aria-label', dayFull[dow] + ' ' + d.getDate() + ', ' + (n ? n + (n === 1 ? ' class' : ' classes') : 'no classes'));
            btn.innerHTML = '<span class="st-day-name">' + dayNames[dow] + '</span>' +
                            '<span class="st-day-num">' + d.getDate() + '</span>' +
                            '<span class="st-day-count">' + (n ? n + (n===1?' class':' classes') : '') + '</span>';
            btn.addEventListener('click', function () { selDow = +this.dataset.dow; renderDays(); filter(); });
            daysEl.appendChild(btn);
        }
        weekEl.textContent = monthNames[weekStart.getMonth()] + ' ' + weekStart.getFullYear();
    }
    function filter() {
        var st = styleSel.value, ins = instrSel.value, shown = 0;
        cards.forEach(function (c) {
            var ok = (+c.dataset.dow === selDow) && (!st || c.dataset.style === st) && (!ins || c.dataset.instructor === ins);
            c.classList.toggle('is-hidden', !ok); if (ok) shown++;
        });
        if (noneEl) noneEl.hidden = shown !== 0;
        // Only offer "Clear filters" when a filter is what's hiding everything —
        // on a genuinely empty day it would be a dead end.
        if (clearEl) clearEl.hidden = !(styleSel.value || instrSel.value);
        if (headEl) headEl.innerHTML = dayFull[selDow] + ' <span>· ' + shown + (shown===1?' class':' classes') + '</span>';
    }
    document.getElementById('st-prev').addEventListener('click', function () { weekStart.setDate(weekStart.getDate() - 7); renderDays(); });
    document.getElementById('st-next').addEventListener('click', function () { weekStart.setDate(weekStart.getDate() + 7); renderDays(); });
    styleSel.addEventListener('change', function () { renderDays(); filter(); });
    instrSel.addEventListener('change', function () { renderDays(); filter(); });
    if (clearEl) clearEl.addEventListener('click', function () {
        styleSel.value = ''; instrSel.value = ''; renderDays(); filter(); styleSel.focus();
    });
    renderDays(); filter();
})();
</script>
