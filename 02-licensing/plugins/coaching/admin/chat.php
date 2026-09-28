<?php
/**
 * Coaching — Chat (redesigned UI, two-pane).
 *
 * Left rail: thread list with unread badges and last-message previews.
 * Right pane: active thread with bubble UI + composer + scheduled queue.
 * Uses the coach-* design system.
 *
 * URL: /plugins/coaching/admin/chat.php?thread=<id>
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/includes/assets.php';

Auth::require();
Auth::requirePerm('coaching.reply_chat');
CoachingAPI::ensureSchema();

/**
 * Renders one admin-side chat bubble's HTML (shared by the initial page
 * render below and the live-poll JSON branch, so they can't drift).
 */
function coaching_admin_render_bubble_html(array $m, bool $mine): string {
    ob_start(); ?>
        <div class="coach-msg <?= $mine ? 'is-mine' : '' ?>" data-msg-id="<?= (int)$m['id'] ?>">
            <div class="coach-msg-bubble">
                <?php if (!empty($m['photo_path'])): ?>
                    <a href="<?= e(SLATE_URL . '/' . ltrim($m['photo_path'], '/')) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(SLATE_URL . '/' . ltrim($m['photo_path'], '/')) ?>" class="coach-msg-photo">
                    </a>
                <?php endif; ?>
                <?php if (!empty($m['body'])): ?>
                    <div style="white-space:pre-wrap;"><?= e($m['body']) ?></div>
                <?php endif; ?>
                <div class="coach-msg-time">
                    <?= e(date('H:i', strtotime($m['sent_at']))) ?>
                    <?php if ($mine && !empty($m['seen_at'])): ?> · <span style="opacity:0.9;">✓ <?= e(__('cc_chat_seen', 'seen')) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
    <?php
    return (string) ob_get_clean();
}

$threadId = (int)($_GET['thread'] ?? 0);
$pageTitle  = 'Coaching · ' . __('cc_chat_page_title', 'Chat');
$currentNav = 'coaching-chat';

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cc_security_check_failed', 'Security check failed.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');
        if ($action === 'reply' && $threadId > 0) {
            $body    = (string)($_POST['body'] ?? '');
            $sendAt  = trim((string)($_POST['send_at'] ?? ''));
            $photo   = !empty($_FILES['photo']['tmp_name']) ? CoachingAPI::saveChatPhoto($_FILES['photo']) : null;
            CoachingAPI::sendMessage($threadId, 'practitioner', $body, $photo, $sendAt !== '' ? $sendAt : null);
            header('Location: ?thread=' . $threadId);
            exit;
        }
        if ($action === 'cancel_scheduled') {
            $mid = (int)($_POST['message_id'] ?? 0);
            if ($mid > 0) CoachingAPI::cancelScheduledMessage($mid);
            header('Location: ?thread=' . $threadId);
            exit;
        }
    }
}

// If no thread selected, auto-select the top thread with unread; otherwise
// the most recent one; otherwise leave $threadId = 0 for the empty state.
$threads = CoachingAPI::listThreads();
if ($threadId <= 0 && $threads) {
    foreach ($threads as $t) {
        if ((int)$t['unread_practitioner'] > 0) { $threadId = (int)$t['thread_id']; break; }
    }
    if ($threadId <= 0) $threadId = (int)$threads[0]['thread_id'];
}

$tid = current_tenant_id();
$activeThread = null;
if ($threadId > 0) {
    $activeThread = Database::row(
        "SELECT t.*, c.name AS customer_name, c.email AS customer_email
           FROM coaching_thread t JOIN customers c ON c.id = t.customer_id
          WHERE t.id = ? AND t.tenant_id = ?", [$threadId, $tid]);
}

if ($activeThread) {
    CoachingAPI::markThreadRead($threadId, 'practitioner');
    $messages = CoachingAPI::listMessages($threadId, true, 500);
    $delivered = []; $scheduled = [];
    foreach ($messages as $m) {
        if (!empty($m['sent_at'])) $delivered[] = $m; else $scheduled[] = $m;
    }
}

// ── Live chat poll (AJAX, no page reload) ───────────────────────────────
// 2026-09-06: mirrors the customer-side poll in customer/router.php — the
// active thread polls this for messages newer than the last one it has.
if (isset($_GET['poll']) && $activeThread) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $afterId = max(0, (int)($_GET['after'] ?? 0));
    $fresh   = array_values(array_filter($delivered, static fn($m) => (int)$m['id'] > $afterId));
    if ($fresh) {
        CoachingAPI::markThreadRead($threadId, 'practitioner');
    }
    $out = [];
    foreach ($fresh as $m) {
        $out[] = [
            'id'       => (int)$m['id'],
            'mine'     => $m['sender'] === 'practitioner',
            'day'      => date('Y-m-d', strtotime($m['sent_at'])),
            'dayLabel' => $m['sent_at'] && date('Y-m-d', strtotime($m['sent_at'])) === date('Y-m-d') ? __('cc_chat_today', 'Today')
                          : (date('Y-m-d', strtotime($m['sent_at'])) === date('Y-m-d', strtotime('-1 day')) ? __('cc_chat_yesterday', 'Yesterday')
                             : I18n::localDate('l, j F', strtotime($m['sent_at']))),
            'html'     => coaching_admin_render_bubble_html($m, $m['sender'] === 'practitioner'),
        ];
    }
    echo json_encode(['messages' => $out]);
    exit;
}

require SLATE_ROOT . '/admin/partials/header.php';

// The plugin's design, emitted by the page rather than relying on a boot-time
// enqueue that never runs while coaching is inactive.
coaching_emit_css('admin.css');

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cc_coaching_title', 'Coaching'),  'href' => plugin_url('coaching', 'admin/index.php')],
    ['label' => __('cc_chat_page_title', 'Chat')],
]);

$totalUnread = array_sum(array_column($threads, 'unread_practitioner'));
?>

<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="margin:0;"><?= __('cc_chat_client_chat', 'Client chat') ?></h1>
        <p style="margin:4px 0 0;color:var(--coach-muted);font-size:14px;">
            <?= e(sprintf(__('cc_chat_thread_count', '%d thread(s)'), count($threads))) ?>
            <?php if ($totalUnread > 0): ?> · <span style="color:var(--coach-brand);font-weight:600;"><?= e(sprintf(__('cc_chat_unread_count', '%d unread'), (int)$totalUnread)) ?></span><?php endif; ?>
        </p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:16px;"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="coach-chat">

    <!-- LEFT: threads list -->
    <div class="coach-chat-side">
        <div class="coach-chat-side-head">
            <h3><?= __('cc_chat_threads', 'Threads') ?></h3>
            <span class="coach-pill is-brand"><?= count($threads) ?></span>
        </div>
        <div class="coach-chat-side-list">
            <?php if (!$threads): ?>
                <div class="coach-empty" style="padding:32px 20px;">
                    <div class="coach-empty-icon"><?= slate_admin_nav_icon('message') ?></div>
                    <div class="coach-empty-title"><?= __('cc_chat_no_threads', 'No threads yet') ?></div>
                    <div class="coach-empty-sub"><?= __('cc_chat_no_threads_hint', 'Threads spin up automatically when a client is enrolled.') ?></div>
                </div>
            <?php else: foreach ($threads as $t):
                $preview = trim((string)$t['last_body']);
                if ($preview === '' && !empty($t['last_message_at'])) $preview = slate_admin_nav_icon('camera') . ' ' . __('cc_photo', 'Photo');
                $senderTag = $t['last_sender'] === 'practitioner' ? __('cc_chat_you_prefix', 'You: ') : '';
                $unread = (int)$t['unread_practitioner'];
                $when = $t['last_message_at']
                    ? (date('Y-m-d', strtotime($t['last_message_at'])) === date('Y-m-d')
                        ? date('H:i', strtotime($t['last_message_at']))
                        : (date('Y-W', strtotime($t['last_message_at'])) === date('Y-W')
                            ? I18n::localDate('D', strtotime($t['last_message_at']))
                            : I18n::localDate('j M', strtotime($t['last_message_at']))))
                    : '';
                $isActive = ((int)$t['thread_id'] === $threadId);
            ?>
                <a href="?thread=<?= (int)$t['thread_id'] ?>" class="coach-chat-thread-item<?= $isActive ? ' is-active' : '' ?><?= $unread > 0 ? ' has-unread' : '' ?>">
                    <div class="coach-list-avatar"><?= e(mb_strtoupper(mb_substr($t['customer_name'], 0, 1))) ?></div>
                    <div class="coach-chat-thread-body">
                        <div class="coach-chat-thread-title">
                            <span class="coach-chat-thread-name"><?= e($t['customer_name']) ?></span>
                            <span class="coach-chat-thread-time"><?= e($when) ?></span>
                        </div>
                        <div class="coach-chat-thread-preview"><?= e($senderTag . ($preview ?: __('cc_chat_no_messages_yet', 'No messages yet'))) ?></div>
                    </div>
                    <?php if ($unread > 0): ?><span class="coach-chat-unread-badge"><?= $unread ?></span><?php endif; ?>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- RIGHT: active thread -->
    <div class="coach-chat-main">
        <?php if (!$activeThread): ?>
            <div class="coach-empty" style="margin:auto;padding:40px 24px;">
                <div class="coach-empty-icon"><?= slate_admin_nav_icon('message') ?></div>
                <div class="coach-empty-title"><?= __('cc_chat_select_thread', 'Select a thread') ?></div>
                <div class="coach-empty-sub"><?= __('cc_chat_select_thread_hint', 'Pick a conversation from the left to start replying.') ?></div>
            </div>
        <?php else: ?>
            <div class="coach-chat-main-head">
                <div class="coach-list-avatar"><?= e(mb_strtoupper(mb_substr($activeThread['customer_name'], 0, 1))) ?></div>
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:15px;color:var(--coach-text);"><?= e($activeThread['customer_name']) ?></div>
                    <div style="font-size:12px;color:var(--coach-muted);"><?= e($activeThread['customer_email']) ?></div>
                </div>
                <a href="<?= e(plugin_url('coaching', 'admin/client.php')) ?>?id=<?= (int)$activeThread['customer_id'] ?>" class="btn btn-sm btn-secondary"><?= __('cc_chat_open_client', 'Open client') ?></a>
            </div>

            <div class="coach-chat-main-body" id="chat-scroll"
                 data-last-id="<?= $delivered ? (int)end($delivered)['id'] : 0 ?>"
                 data-last-day="<?= $delivered ? e(date('Y-m-d', strtotime(end($delivered)['sent_at']))) : '' ?>"
                 data-thread="<?= (int)$threadId ?>">
                <?php if (!$delivered): ?>
                    <div class="coach-empty" style="padding:40px 20px;">
                        <div class="coach-empty-icon"><?= slate_admin_nav_icon('edit-3') ?></div>
                        <div class="coach-empty-title"><?= __('cc_chat_no_messages_exchanged', 'No messages exchanged yet') ?></div>
                        <div class="coach-empty-sub"><?= __('cc_chat_break_the_ice', 'Break the ice below.') ?></div>
                    </div>
                <?php else:
                    $lastDay = '';
                    foreach ($delivered as $m):
                        $day = date('Y-m-d', strtotime($m['sent_at']));
                        if ($day !== $lastDay):
                            $lastDay = $day;
                            $dayLabel = $day === date('Y-m-d')
                                ? __('cc_chat_today', 'Today')
                                : ($day === date('Y-m-d', strtotime('-1 day')) ? __('cc_chat_yesterday', 'Yesterday') : I18n::localDate('l, j F', strtotime($day)));
                            ?>
                            <div class="coach-msg-day-divider"><?= e($dayLabel) ?></div>
                        <?php endif;
                        echo coaching_admin_render_bubble_html($m, $m['sender'] === 'practitioner');
                    endforeach; endif; ?>
            </div>

            <?php if ($scheduled): ?>
                <div style="padding:12px 20px;background:rgba(245,158,11,0.06);border-top:1px solid rgba(245,158,11,0.2);">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--coach-warn);font-weight:700;margin-bottom:8px;">
                        <?= slate_admin_nav_icon('calendar') ?> <?= e(__('cc_chat_scheduled_to_send', 'Scheduled to send')) ?> · <?= count($scheduled) ?>
                    </div>
                    <?php foreach ($scheduled as $m): ?>
                        <div class="coach-scheduled">
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--coach-text);white-space:pre-wrap;overflow:hidden;text-overflow:ellipsis;"><?= e($m['body'] ?? '') !== '' ? e($m['body']) : slate_admin_nav_icon('camera') . ' ' . e(__('cc_photo', 'Photo')) ?></div>
                                <div style="font-size:11px;color:var(--coach-warn);margin-top:2px;"><?= e(__('cc_chat_fires_at', 'Fires')) ?> <?= e(I18n::localDate('l, j M · H:i', strtotime($m['send_at']))) ?></div>
                            </div>
                            <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('cc_chat_confirm_cancel_scheduled', 'Cancel this scheduled message?')) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="cancel_scheduled">
                                <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                                <button style="border:0;background:none;color:var(--coach-warn);cursor:pointer;font-size:12px;font-weight:600;"><?= __('cancel', 'Cancel') ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="coach-chat-main-composer">
                <form method="post" enctype="multipart/form-data" id="composer">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="reply">
                    <textarea name="body" rows="2" placeholder="<?= e(__('cc_chat_write_a_reply', 'Write a reply…')) ?>" style="width:100%;padding:10px 14px;border:1px solid var(--coach-border-2);border-radius:var(--coach-r-sm);font-size:14px;resize:vertical;font-family:inherit;background:#fff;"></textarea>
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                        <div style="display:flex;gap:8px;align-items:center;">
                            <label style="cursor:pointer;padding:6px 12px;background:#fff;border:1px solid var(--coach-border-2);border-radius:var(--coach-r-sm);font-size:13px;">
                                <?= slate_admin_nav_icon('camera') ?> <?= __('cc_photo', 'Photo') ?>
                                <input type="file" name="photo" accept="image/*" style="display:none;">
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--coach-muted);">
                                <span><?= slate_admin_nav_icon('clock') ?></span>
                                <input type="datetime-local" name="send_at" style="padding:5px 8px;border:1px solid var(--coach-border-2);border-radius:6px;font-size:12px;background:#fff;">
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= __('cc_send', 'Send') ?></button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($activeThread): ?>
    <script>
        (function() {
            var scr = document.getElementById('chat-scroll');
            if (scr) scr.scrollTop = scr.scrollHeight;
            var ta = document.querySelector('#composer textarea');
            if (ta) ta.focus();
            if (!scr) return;

            // 2026-09-06: "real live chat" — poll for new client messages
            // without a page reload, and chime when one arrives. Mirrors
            // the customer-side poll in coaching/customer/router.php.
            var lastId  = parseInt(scr.getAttribute('data-last-id') || '0', 10);
            var lastDay = scr.getAttribute('data-last-day') || '';
            var thread  = scr.getAttribute('data-thread') || '0';
            var soundOn = true;
            try { soundOn = localStorage.getItem('slate_notif_sound') !== '0'; } catch (e) {}
            var audioCtx = null;
            function chime() {
                if (!soundOn) return;
                try {
                    var Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) return;
                    if (!audioCtx) audioCtx = new Ctx();
                    var o = audioCtx.createOscillator(), g = audioCtx.createGain();
                    o.type = 'sine'; o.frequency.value = 720;
                    g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
                    g.gain.exponentialRampToValueAtTime(0.16, audioCtx.currentTime + 0.01);
                    g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.3);
                    o.connect(g); g.connect(audioCtx.destination);
                    o.start(); o.stop(audioCtx.currentTime + 0.31);
                } catch (e) { /* audio unavailable */ }
            }
            function nearBottom() { return scr.scrollHeight - scr.scrollTop - scr.clientHeight < 80; }
            function poll() {
                fetch('?thread=' + thread + '&poll=1&after=' + lastId, { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data || !data.messages || !data.messages.length) return;
                        var stick = nearBottom();
                        var gotIncoming = false;
                        data.messages.forEach(function (m) {
                            if (m.day !== lastDay) {
                                lastDay = m.day;
                                var div = document.createElement('div');
                                div.className = 'coach-msg-day-divider';
                                div.textContent = m.dayLabel;
                                scr.appendChild(div);
                            }
                            var wrap = document.createElement('div');
                            wrap.innerHTML = m.html;
                            scr.appendChild(wrap.firstElementChild);
                            lastId = m.id;
                            if (!m.mine) gotIncoming = true;
                        });
                        scr.setAttribute('data-last-id', String(lastId));
                        scr.setAttribute('data-last-day', lastDay);
                        if (gotIncoming) chime();
                        if (stick) scr.scrollTop = scr.scrollHeight;
                    })
                    .catch(function () { /* offline this tick */ });
            }
            var timer = setInterval(poll, 4000);
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) { clearInterval(timer); }
                else { poll(); timer = setInterval(poll, 4000); }
            });
        })();
    </script>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
