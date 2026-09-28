<?php
/**
 * Coaching — customer-facing area.  URL: /coaching?view=X
 *
 *   ?view=home    (default) — Today dashboard
 *   ?view=profile           — profile edit form (identity + body + medical)
 *   ?view=goals             — all goals + daily check-in
 *   ?view=diary             — food diary list (past 14 days)
 *   ?view=entry             — new/edit diary entry (?id=N to edit)
 *   ?view=charts            — insight charts (?range=7|14|30|90)
 *   ?view=chat              — practitioner thread
 *   ?view=structure         — meal structure
 *   ?view=shopping          — shopping lists
 *   ?view=recipes / recipe  — recipe library + detail
 *   ?view=motivation        — challenges & exercises
 *   ?view=summary           — end-of-program summary
 *
 * Identity is core customers. Access is gated on active enrollment
 * (membership plugin). The router falls back to a friendly "not enrolled
 * yet" landing when the gate fails.
 *
 * LAYOUT — this file renders its own document shell (coaching_head /
 * coaching_foot) rather than requiring customer/partials/header.php.
 * That partial only offers an 'auth' variant (a centred 400px login card)
 * and a 'dashboard' variant (with its own branded topbar); both fight an
 * app layout, and requiring it is what produced the duplicated brand
 * block. coaching_head() calls exactly the same core helpers that partial
 * does — slate_ui_emit_css(), slate_brand_accent_emit(), a11y_head.php,
 * the customer_head hook and renderQueuedStyles(). If core ever adds
 * something to the customer <head>, mirror it there.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
require_once SLATE_ROOT . '/includes/portal_ui.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/CoachingCharts.php';
CoachingAPI::ensureSchema();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

Auth::requireCustomer();
$cid  = (int) Auth::customerId();
$view = (string)($_GET['view'] ?? 'home');
$flash = null;

// Ensure a profile row exists (self-heal — customer_registered hook covers
// new signups but this catches customers who existed before the plugin).
CoachingAPI::provisionProfile($cid);

$enrolled = CoachingAPI::isEnrolled($cid);

// ── Live chat poll (AJAX, no page reload) ───────────────────────────────
// 2026-09-06: "real live chat" — the customer-side chat view polls this
// every few seconds for messages newer than the last one it already has.
// Kept as a thin JSON branch on this same router rather than a new public
// entry point, so it inherits Auth::requireCustomer() above for free.
if ($view === 'chat' && isset($_GET['poll']) && $enrolled) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $threadId = CoachingAPI::ensureThread($cid);
    $afterId  = max(0, (int)($_GET['after'] ?? 0));
    $all      = CoachingAPI::listMessages($threadId, false, 500);
    $fresh    = array_values(array_filter($all, static fn($m) => (int)$m['id'] > $afterId));
    if ($fresh) {
        CoachingAPI::markThreadRead($threadId, 'customer');
    }
    $out = [];
    foreach ($fresh as $m) {
        $stamp = $m['sent_at'] ?: $m['created_at'];
        $out[] = [
            'id'    => (int)$m['id'],
            'mine'  => $m['sender'] === 'customer',
            'day'   => date('Y-m-d', strtotime($stamp)),
            'dayLabel' => coaching_day_label(date('Y-m-d', strtotime($stamp))),
            'html'  => coaching_render_bubble_html($m, $m['sender'] === 'customer'),
        ];
    }
    echo json_encode(['messages' => $out]);
    exit;
}

// ── POST actions (all views) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $enrolled) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('co_err_security_check_failed', 'Security check failed.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');
        // 2026-09-06: "food/mood page needs to interact on-page, not reload".
        // The diary entry form (save_entry / delete_photo) now submits via
        // fetch() and sets this flag, so those two branches respond with
        // JSON instead of a redirect. Every other action is untouched and
        // still works exactly as a plain form post if JS is unavailable.
        $isAjax = !empty($_POST['_ajax']);

        if ($action === 'save_profile') {
            $fields = [];
            foreach (['dob','gender','height_cm','weight_kg','body_type',
                      'pathologies','ongoing_care','alternative_medicine','personal_issues'] as $k) {
                if (isset($_POST[$k])) $fields[$k] = $_POST[$k];
            }
            // JSON arrays from CSV inputs.
            if (isset($_POST['intolerances_csv'])) {
                $csv = trim((string)$_POST['intolerances_csv']);
                $fields['intolerances'] = $csv === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv))));
            }
            if (isset($_POST['dietary_pref_csv'])) {
                $csv = trim((string)$_POST['dietary_pref_csv']);
                $fields['dietary_preferences'] = $csv === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv))));
            }
            CoachingAPI::saveProfile($cid, $fields);
            $flash = ['type' => 'success', 'msg' => 'Profile updated.'];
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
        }
        elseif ($action === 'checkin') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'not_achieved');
            $day    = (string)($_POST['day'] ?? date('Y-m-d'));
            CoachingAPI::recordCheckIn($cid, $goalId, $day, $status);
            $flash = ['type' => 'success', 'msg' => 'Check-in recorded.'];
        }
        elseif ($action === 'extra_action') {
            $text = trim((string)($_POST['action_text'] ?? ''));
            $day  = (string)($_POST['day'] ?? date('Y-m-d'));
            if ($text !== '') CoachingAPI::recordExtraAction($cid, $day, $text);
            $flash = ['type' => 'success', 'msg' => 'Added.'];
        }
        elseif ($action === 'save_entry') {
            $foods = [];
            $names      = (array)($_POST['food_name'] ?? []);
            $cats       = (array)($_POST['food_category'] ?? []);
            $pleasures  = (array)($_POST['food_pleasure'] ?? []);
            foreach ($names as $i => $name) {
                $foods[] = [
                    'name'             => (string)$name,
                    'category'         => (string)($cats[$i] ?? ''),
                    'is_pleasure_food' => !empty($pleasures[$i]),
                ];
            }
            $entryId = CoachingAPI::saveDiaryEntry($cid, [
                'id'            => (int)($_POST['id'] ?? 0),
                'day'           => (string)($_POST['day'] ?? date('Y-m-d')),
                'meal_type'     => (string)($_POST['meal_type'] ?? 'other'),
                'started_at'    => (string)($_POST['started_at'] ?? ''),
                'duration_min'  => $_POST['duration_min'] ?? '',
                'emotion'       => (string)($_POST['emotion'] ?? ''),
                'emotion_note'  => (string)($_POST['emotion_note'] ?? ''),
                'hunger_before' => $_POST['hunger_before'] ?? '',
                'satiety_after' => $_POST['satiety_after'] ?? '',
                'context'       => (string)($_POST['context'] ?? ''),
                'context_note'  => (string)($_POST['context_note'] ?? ''),
                'quantity_note' => (string)($_POST['quantity_note'] ?? ''),
                'notes'         => (string)($_POST['notes'] ?? ''),
                'foods'         => $foods,
            ]);
            // Optional photo upload alongside entry save.
            if (!empty($_FILES['photo']['tmp_name'])) {
                CoachingAPI::saveDiaryPhoto($entryId, $_FILES['photo'], (string)($_POST['photo_caption'] ?? ''));
            }
            if ($isAjax) {
                $savedEntry = CoachingAPI::getDiaryEntry($entryId, $cid);
                header('Content-Type: application/json');
                echo json_encode([
                    'ok'         => true,
                    'id'         => $entryId,
                    'summary'    => (string)($savedEntry['summary'] ?? ''),
                    'photosHtml' => coaching_render_diary_photos_html($savedEntry['photos'] ?? []),
                ]);
                exit;
            }
            header('Location: ?view=entry&id=' . $entryId . '&saved=1');
            exit;
        }
        elseif ($action === 'delete_entry') {
            $entryId = (int)($_POST['entry_id'] ?? 0);
            $deleted = false;
            if ($entryId > 0) $deleted = CoachingAPI::deleteDiaryEntry($entryId, $cid);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => $deleted, 'redirect' => '?view=diary']);
                exit;
            }
            header('Location: ?view=diary');
            exit;
        }
        elseif ($action === 'delete_photo') {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $entryId = (int)($_POST['entry_id'] ?? 0);
            $removed = false;
            if ($photoId > 0) $removed = CoachingAPI::deleteDiaryPhoto($photoId, $cid);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => $removed, 'photoId' => $photoId]);
                exit;
            }
            header('Location: ?view=entry&id=' . $entryId);
            exit;
        }
        elseif ($action === 'save_hydration') {
            $day    = (string)($_POST['day'] ?? date('Y-m-d'));
            $liters = max(0, (float)($_POST['liters'] ?? 0));
            $glass  = (int)($_POST['glass_count'] ?? 0);
            $other  = (string)($_POST['other_drinks'] ?? '');
            CoachingAPI::upsertHydration($cid, $day, $liters, $glass, $other);
            $flash = ['type' => 'success', 'msg' => 'Hydration saved.'];
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok'      => true,
                    'liters'  => $liters,
                    'glasses' => (int) round($liters * 4),
                ]);
                exit;
            }
        }
        elseif ($action === 'add_activity') {
            $day      = (string)($_POST['day'] ?? date('Y-m-d'));
            $kind     = (string)($_POST['kind'] ?? '');
            $duration = isset($_POST['duration_min']) && $_POST['duration_min'] !== '' ? (int)$_POST['duration_min'] : null;
            $notes    = (string)($_POST['notes'] ?? '');
            if ($kind !== '') {
                CoachingAPI::addActivity($cid, $day, $kind, $duration, $notes);
                $flash = ['type' => 'success', 'msg' => 'Activity logged.'];
            }
        }
        elseif ($action === 'delete_activity') {
            $activityId = (int)($_POST['activity_id'] ?? 0);
            if ($activityId > 0) CoachingAPI::deleteActivity($activityId, $cid);
            $flash = ['type' => 'success', 'msg' => 'Removed.'];
        }
        elseif ($action === 'send_message') {
            $threadId = CoachingAPI::ensureThread($cid);
            $body     = (string)($_POST['body'] ?? '');
            $photo    = !empty($_FILES['photo']['tmp_name']) ? CoachingAPI::saveChatPhoto($_FILES['photo']) : null;
            CoachingAPI::sendMessage($threadId, 'customer', $body, $photo, null);
            if (class_exists('Notifications')) {
                $clientName = (string) Database::value('SELECT display_name FROM contacts WHERE id = ?', [$cid]);
                Notifications::add(__('coaching_notif_new_message', 'New message') . ' · ' . ($clientName ?: __('coaching_notif_a_client', 'A client')), [
                    'body' => $body !== '' ? mb_substr($body, 0, 140) : __('coaching_notif_sent_photo', 'Sent a photo.'),
                    'url'  => function_exists('plugin_url') ? plugin_url('coaching', 'admin/chat.php') . '?thread=' . $threadId : '',
                    'icon' => 'message-circle',
                ]);
            }
            header('Location: ?view=chat');
            exit;
        }
        elseif ($action === 'complete_challenge') {
            $chId = (int)($_POST['challenge_id'] ?? 0);
            $note = trim((string)($_POST['client_note'] ?? ''));
            if ($chId > 0) CoachingAPI::completeChallenge($chId, $cid, $note);
            header('Location: ?view=motivation');
            exit;
        }
        elseif ($action === 'share_recipe') {
            $ingredients = array_filter(array_map('trim', explode("\n", (string)($_POST['ingredients_text'] ?? ''))));
            $photoPath = !empty($_FILES['photo']['tmp_name']) ? CoachingAPI::saveRecipePhoto($_FILES['photo']) : null;
            CoachingAPI::saveRecipe([
                'author'            => 'customer',
                'customer_id'       => $cid,
                'title'             => (string)($_POST['title'] ?? ''),
                'photo_path'        => $photoPath,
                'ingredients'       => $ingredients,
                'instructions_html' => (string)($_POST['instructions_html'] ?? ''),
                'notes'             => (string)($_POST['notes'] ?? ''),
            ]);
            header('Location: ?view=recipes&shared=1');
            exit;
        }
    }
}


// ── Dispatch ────────────────────────────────────────────────────────────
// Every view gets an identical shell: head → topbar → nav → tiles → FAB.

$pageMeta = [
    'home'       => ['Today',     I18n::localDate('l, j F')],
    'profile'    => ['Account',   'My profile'],
    'goals'      => ['Progress',  'My goals'],
    'diary'      => ['Nutrition', 'Food diary'],
    'entry'      => ['Nutrition', 'Log a meal'],
    'charts'     => ['Insights',  'My charts'],
    'chat'       => ['Coach',     'Chat'],
    'structure'  => ['Nutrition', 'Meal structure'],
    'shopping'   => ['Nutrition', 'Shopping list'],
    'recipes'    => ['Nutrition', 'Recipes'],
    'recipe'     => ['Nutrition', 'Recipe'],
    'motivation' => ['Coach',     'Motivation'],
    'summary'    => ['Coach',     'Program summary'],
];
if (!isset($pageMeta[$view])) $view = 'home';
[$eyebrow, $title] = $pageMeta[$view];

// The entry view titles itself after the entry's own day.
if ($view === 'entry') {
    $peekId = (int)($_GET['id'] ?? 0);
    if ($peekId > 0) {
        $peek = CoachingAPI::getDiaryEntry($peekId, $cid);
        if ($peek && (int)$peek['customer_id'] === $cid) {
            $eyebrow = 'Editing entry';
            $title   = I18n::localDate('l, j F', strtotime((string)$peek['day']));
        }
    }
}

// Which of the 5 bottom-nav slots owns this view.
$navGroups = [
    'home'    => ['home', 'goals'],
    'diary'   => ['diary', 'entry', 'structure', 'shopping', 'recipes', 'recipe'],
    'charts'  => ['charts', 'summary'],
    'chat'    => ['chat', 'motivation'],
    'profile' => ['profile'],
];
$navKey = 'home';
foreach ($navGroups as $slot => $slotViews) {
    if (in_array($view, $slotViews, true)) { $navKey = $slot; break; }
}

coaching_head($title . ' — Body & Soul Program');

if (!$enrolled) {
    coaching_topbar('Program', 'Body & Soul');
    echo '<div class="ck-wrap" id="ck-main">';
    coaching_render_not_enrolled();
    echo '</div>';
    coaching_foot();
    return;
}

coaching_topbar($eyebrow, $title, $view);
coaching_bottom_nav($cid, $view);
echo '<div class="ck-wrap" id="ck-main">';

switch ($view) {
    case 'profile':    coaching_render_profile($cid, $flash);    break;
    case 'goals':      coaching_render_goals($cid, $flash);      break;
    case 'diary':      coaching_render_diary($cid, $flash);      break;
    case 'entry':      coaching_render_entry($cid, $flash);      break;
    case 'charts':     coaching_render_charts($cid, $flash);     break;
    case 'chat':       coaching_render_chat($cid, $flash);       break;
    case 'structure':  coaching_render_structure($cid);          break;
    case 'shopping':   coaching_render_shopping($cid);           break;
    case 'recipes':    coaching_render_recipes($cid, $flash);    break;
    case 'recipe':     coaching_render_recipe_detail($cid);      break;
    case 'motivation': coaching_render_motivation($cid, $flash); break;
    case 'summary':    coaching_render_summary($cid);            break;
    case 'home':
    default:           coaching_render_home($cid, $flash);       break;
}

echo '</div>';
coaching_fab($view);
coaching_foot();


// ═══ Shell ══════════════════════════════════════════════════════════════

/** Opens the document on the SHARED portal shell. */
function coaching_head(string $title): void {
    // The program now sits in the same chrome as booking, studio and
    // membership instead of being its own island. 'ck-body' is kept so
    // coaching's CSS still targets this area, and its stylesheets are emitted
    // after the shell's so they still win collisions.
    require_once SLATE_ROOT . '/includes/portal_shell.php';
    $GLOBALS['currentPortalNav'] = 'coaching';
    slate_portal_shell_head($title, 'ck-body');

    // customer.css only. customer-shell.css is NOT loaded: every one of its
    // 492 lines is scoped to `body:has(main.cust-content)`, an element nothing
    // renders any more — the portal shell emits main.mapp-main. It was an
    // overlay that upgraded the old plainer customer area, and that area now
    // has the shared app design. See the note at the top of that file.
    require_once dirname(__DIR__) . '/includes/assets.php';
    coaching_emit_css('customer.css');
}

function coaching_foot(): void {
    slate_portal_shell_close();
}

/**
 * The bar belongs to the shared portal now (brand + global nav + account), so
 * this renders the page's own eyebrow/title as a heading in the content.
 * Dropping it would leave views like "Log a meal" unlabelled.
 */
function coaching_topbar(string $eyebrow, string $title, string $view = 'home'): void {
    slate_portal_shell_open([
        'active'        => 'coaching',
        'area'          => 'coaching',
        'subnav_active' => $view,
    ]);
    echo '<div class="ck-pagehead">'
       . '<span class="ck-pagehead-eyebrow">' . e($eyebrow) . '</span>'
       . '<h1 class="ck-pagehead-title">' . e($title) . '</h1>'
       . '</div>';
}

/**
 * Section navigation for the program, rendered by the shared portal shell.
 *
 * Was a bespoke 5-slot bar plus a separate overflow list, because the global
 * nav had no room for coaching's pages. Now that coaching contributes ONE
 * entry to the portal bar, everything it owns belongs here — the core areas
 * plus whichever optional modules are switched on for this client.
 */
function coaching_bottom_nav(int $cid, string $view): void {
    $unread = 0;
    try { $unread = (int) CoachingAPI::unreadForCustomer($cid); } catch (\Throwable $e) {}

    $base  = SLATE_URL . '/coaching?view=';
    $items = [
        ['home',    'home',      __('cc_home', 'Home')],
        ['diary',   'clipboard', __('cc_diary', 'Diary')],
        ['goals',   'check',     __('cc_goals', 'Goals')],
        ['charts',  'percent',   __('cc_charts', 'Charts')],
        ['chat',    'mail',      __('cc_chat', 'Chat')],
        ['profile', 'user',      __('cc_profile', 'Profile')],
    ];
    foreach ([['meal_structure', 'structure', 'coffee', 'Meal structure'],
              ['shopping',       'shopping',  'clipboard', 'Shopping list'],
              ['recipes',        'recipes',   'gift', 'Recipes']] as [$flag, $slot, $icon, $label]) {
        try {
            if (CoachingAPI::isModuleEnabled($cid, $flag)) { $items[] = [$slot, $icon, $label]; }
        } catch (\Throwable $e) { /* a module probe must not drop the nav */ }
    }

    // Views that live inside a section keep that section highlighted.
    $owner  = ['entry' => 'diary', 'recipe' => 'recipes', 'summary' => 'charts', 'motivation' => 'chat'];
    $active = $owner[$view] ?? $view;

    slate_portal_subnav(array_map(static fn (array $i): array => [
        'href'   => $base . $i[0],
        'label'  => $i[2] . ($i[0] === 'chat' && $unread > 0 ? ' (' . $unread . ')' : ''),
        'icon'   => $i[1],
        'active' => $active === $i[0],
    ], $items));
}

/** Floating "log a meal" button. Hidden on the entry view — you're there. */
function coaching_fab(string $view): void {
    if ($view === 'entry') return;
    ?>
    <a href="?view=entry" class="ck-fab" aria-label="Log a meal">
        <span aria-hidden="true">+</span>
    </a>
    <?php
}


// ═══ Components ═════════════════════════════════════════════════════════

/**
 * SVG progress ring with a stroke-dashoffset sweep.
 * $tone is a CSS custom-property name from the brand ladder.
 */
function coaching_ring(float $pct, string $tone, string $glyph, string $value, string $label): string {
    $pct    = max(0.0, min(100.0, $pct));
    $r      = 38;
    $circ   = 2 * M_PI * $r;
    $offset = $circ - ($circ * $pct / 100);

    ob_start();
    ?>
    <div>
        <div class="ck-ring">
            <svg viewBox="0 0 92 92" width="92" height="92" role="img"
                 aria-label="<?= e($label . ': ' . $value . ', ' . round($pct) . '% of target') ?>">
                <circle class="ck-ring-track" cx="46" cy="46" r="<?= $r ?>"/>
                <circle class="ck-ring-fill" cx="46" cy="46" r="<?= $r ?>"
                        stroke="var(<?= e($tone) ?>)"
                        style="--ck-circ: <?= number_format($circ, 2, '.', '') ?>;"
                        stroke-dasharray="<?= number_format($circ, 2, '.', '') ?>"
                        stroke-dashoffset="<?= number_format($offset, 2, '.', '') ?>"/>
            </svg>
            <div class="ck-ring-mid" aria-hidden="true">
                <span class="ck-ring-glyph"><?= $glyph ?></span>
                <span class="ck-ring-val"><?= e($value) ?></span>
            </div>
        </div>
        <div class="ck-ring-label"><?= e($label) ?></div>
    </div>
    <?php
    return ob_get_clean();
}

/** Tile header: eyebrow + title, with an optional right-hand slot. */
function coaching_tile_head(string $eyebrow, string $title, string $rightHtml = ''): void {
    ?>
    <div class="ck-tile-head">
        <div>
            <span class="ck-eyebrow"><?= e($eyebrow) ?></span>
            <h2 class="ck-tile-title"><?= e($title) ?></h2>
        </div>
        <?= $rightHtml ?>
    </div>
    <?php
}

function coaching_alert(?array $flash): void {
    if (!$flash) return;
    $kind = (($flash['type'] ?? '') === 'success') ? 'success' : 'danger';
    ?>
    <div class="ck-alert ck-alert--<?= e($kind) ?>" role="status">
        <span aria-hidden="true"><?= $kind === 'success' ? '✓' : '!' ?></span>
        <span><?= e($flash['msg']) ?></span>
    </div>
    <?php
}

function coaching_empty(string $glyph, string $title, string $subHtml = ''): void {
    ?>
    <div class="ck-empty">
        <div class="ck-empty-glyph" aria-hidden="true"><?= $glyph ?></div>
        <div class="ck-empty-title"><?= e($title) ?></div>
        <?php if ($subHtml !== ''): ?><div class="ck-empty-sub"><?= $subHtml ?></div><?php endif; ?>
    </div>
    <?php
}

/** Shared emoji vocabulary. */
function coaching_emotion_emoji(): array {
    return [
        'joy'=>slate_icon('smile'),'stress'=>slate_icon('worried'),'fatigue'=>slate_icon('sleepy'),'anxiety'=>slate_icon('worried'),'boredom'=>slate_icon('meh'),
        'anger'=>slate_icon('angry'),'sadness'=>slate_icon('frown'),'serenity'=>slate_icon('serene'),'neutrality'=>slate_icon('meh'),'other'=>slate_icon('smile'),
    ];
}
function coaching_meal_emoji(): array {
    return [
        'breakfast'=>slate_icon('egg'),'lunch'=>slate_icon('salad'),'dinner'=>slate_icon('plate'),'snack'=>slate_icon('cookie'),
        'binge'=>slate_icon('stack'),'drink'=>slate_icon('coffee'),'other'=>slate_icon('utensils'),
    ];
}
function coaching_context_emoji(): array {
    return [
        'home'=>slate_icon('home'),'work'=>slate_icon('briefcase'),'friends'=>slate_icon('users'),'family'=>slate_icon('users'),
        'restaurant'=>slate_icon('utensils'),'commute'=>slate_icon('bus'),'other'=>slate_icon('map-pin'),
    ];
}

/** "Today" / "Yesterday" / "Monday, 7 July" */
function coaching_day_label(string $day): string {
    if ($day === date('Y-m-d'))                      return 'Today';
    if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return I18n::localDate('l, j F', strtotime($day));
}

/** The program areas the 5-slot bottom nav can't hold. */
function coaching_program_links(): array {
    return [
        ['goals',      slate_icon('target'),    'My goals'],
        ['structure',  slate_icon('stack'),     'Meal structure'],
        ['shopping',   slate_icon('cart'),      'Shopping list'],
        ['recipes',    slate_icon('book'),      'Recipes'],
        ['motivation', slate_icon('dumbbell'),  'Motivation'],
        ['summary',    slate_icon('file'),      'Summary'],
    ];
}


// ═══ Views ══════════════════════════════════════════════════════════════

function coaching_render_not_enrolled(): void {
    ?>
    <div class="ck-bento">
        <div class="ck-tile ck-s12">
            <?php coaching_empty(slate_icon('lock'), 'Not enrolled yet',
                'The daily-tracking program opens up here once you&rsquo;re enrolled. '
                . 'If you&rsquo;ve booked a program, this unlocks as soon as your membership is activated.'); ?>
        </div>
    </div>
    <?php
}

function coaching_render_home(int $cid, ?array $flash): void {
    $day       = date('Y-m-d');
    $profile   = CoachingAPI::getProfile($cid);
    $goals     = CoachingAPI::listGoals($cid, 'daily', true);
    $hydration = CoachingAPI::getHydration($cid, $day);
    $todayEntries  = CoachingAPI::listDiaryEntries($cid, $day, $day);
    $todayActivity = CoachingAPI::listActivity($cid, $day);
    $challenges      = CoachingAPI::listChallenges($cid, true);
    $activeChallenge = $challenges[0] ?? null;

    $tid = current_tenant_id();

    $todayCheckins = [];
    foreach (Database::rows(
        "SELECT goal_id, status FROM coaching_goal_checkin
          WHERE tenant_id = ? AND customer_id = ? AND day = ?", [$tid, $cid, $day]) as $r) {
        $todayCheckins[(int)$r['goal_id']] = $r['status'];
    }

    // Small wins logged today. No API method exists for these yet — this is
    // the same direct-read pattern the check-in query above uses.
    $extraActions = Database::rows(
        "SELECT id, action_text FROM coaching_extra_action
          WHERE tenant_id = ? AND customer_id = ? AND day = ?
          ORDER BY created_at DESC", [$tid, $cid, $day]);

    $threadId  = CoachingAPI::ensureThread($cid);
    $latestMsg = Database::row(
        "SELECT * FROM coaching_message
          WHERE thread_id = ? AND sender = 'practitioner' AND sent_at IS NOT NULL
          ORDER BY sent_at DESC LIMIT 1", [$threadId]);
    $unreadFromCoach = CoachingAPI::unreadForCustomer($cid);

    // Consistency streak — consecutive days back from today with an entry.
    // Today not yet logged doesn't break a streak that's alive yesterday.
    $recentDays = Database::rows(
        "SELECT DISTINCT day FROM coaching_diary_entry
          WHERE tenant_id = ? AND customer_id = ? AND day <= ?
          ORDER BY day DESC LIMIT 90", [$tid, $cid, $day]);
    $loggedDays = array_column($recentDays, 'day');
    $streak = 0;
    for ($i = 0; $i < 90; $i++) {
        $probe = date('Y-m-d', strtotime("-{$i} days"));
        if (in_array($probe, $loggedDays, true)) { $streak++; continue; }
        if ($i === 0) continue;
        break;
    }

    $customer  = Database::row("SELECT name FROM customers WHERE id = ?", [$cid]);
    $firstName = $customer ? explode(' ', trim((string)$customer['name']))[0] : 'there';

    $hydroLitres = (float)($hydration['liters'] ?? 0);
    $hydroTarget = 2.0;
    $hydroPct    = min(100, ($hydroLitres / $hydroTarget) * 100);

    $mealsToday  = count($todayEntries);
    $mealsTarget = 3;
    $mealsPct    = min(100, ($mealsToday / $mealsTarget) * 100);

    $goalsDone = 0;
    foreach ($todayCheckins as $st) {
        if (in_array($st, ['achieved', 'exceeded'], true)) $goalsDone++;
    }
    $goalCount = count($goals);
    $goalsPct  = $goalCount > 0 ? min(100, ($goalsDone / $goalCount) * 100) : 0;

    $mealsBySlot = [];
    foreach ($todayEntries as $me) { $mealsBySlot[$me['meal_type']][] = $me; }

    $hour = (int) date('G');
    $timeOfDay = $hour < 5  ? 'Good night'
               : ($hour < 12 ? 'Good morning'
               : ($hour < 17 ? 'Good afternoon'
               : 'Good evening'));

    $emotions        = CoachingAPI::emotions();
    $emotionEmojis   = coaching_emotion_emoji();
    $profileComplete = $profile && !empty($profile['dob'])
                       && !empty($profile['height_cm']) && !empty($profile['weight_kg']);
    ?>

    <?php coaching_alert($flash); ?>

    <?php if (!$profileComplete): ?>
        <div class="ck-alert ck-alert--warn">
            <span aria-hidden="true"><?= slate_icon('user') ?></span>
            <span><strong>Your profile is incomplete.</strong>
                  <a href="?view=profile">Fill it in →</a></span>
        </div>
    <?php endif; ?>

    <div class="ck-bento">

        <!-- Hero -->
        <section class="ck-tile ck-hero ck-s12">
            <div class="ck-hero-eyebrow"><?= e($timeOfDay) ?></div>
            <h1 class="ck-hero-name"><?= e($firstName) ?></h1>
            <p class="ck-hero-sub">
                <?php if ($mealsToday === 0 && $hydroLitres < 0.1): ?>
                    A fresh page. Log a meal, a glass of water, or check in on a goal —
                    whatever's easiest to start with.
                <?php else: ?>
                    You've logged <?= (int)$mealsToday ?> meal<?= $mealsToday === 1 ? '' : 's' ?>
                    and <?= number_format($hydroLitres, 1) ?>L of water today. Keep going.
                <?php endif; ?>
            </p>
            <div class="ck-hero-stats">
                <div class="ck-hero-stat">
                    <div class="ck-hero-stat-value"><?= (int)$streak ?><small> d</small></div>
                    <div class="ck-hero-stat-label">Streak</div>
                </div>
                <div class="ck-hero-stat">
                    <div class="ck-hero-stat-value"><?= (int)$mealsToday ?><small>/<?= (int)$mealsTarget ?></small></div>
                    <div class="ck-hero-stat-label">Meals today</div>
                </div>
                <div class="ck-hero-stat">
                    <div class="ck-hero-stat-value"><?= number_format($hydroLitres, 1) ?><small>L</small></div>
                    <div class="ck-hero-stat-label">Hydration</div>
                </div>
                <?php if ($goalCount > 0): ?>
                    <div class="ck-hero-stat">
                        <div class="ck-hero-stat-value"><?= (int)$goalsDone ?><small>/<?= (int)$goalCount ?></small></div>
                        <div class="ck-hero-stat-label">Goals hit</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Rings -->
        <section class="ck-tile ck-s4">
            <?php coaching_tile_head('Today', 'Progress'); ?>
            <div class="ck-rings">
                <?= coaching_ring($hydroPct, '--ck-ring-1', slate_icon('droplet'),
                        number_format($hydroLitres, 1) . 'L', 'Water') ?>
                <?= coaching_ring($mealsPct, '--ck-ring-2', slate_icon('plate'),
                        (int)$mealsToday . '/' . (int)$mealsTarget, 'Meals') ?>
                <?= coaching_ring($goalsPct, '--ck-ring-3', slate_icon('target'),
                        (int)$goalsDone . '/' . (int)$goalCount, 'Goals') ?>
            </div>
        </section>

        <!-- Hydration -->
        <section class="ck-tile ck-s4">
            <?php coaching_tile_head('Hydration', 'Water intake'); ?>
            <div class="ck-hydro-value" id="ck-hydro-value">
                <?= number_format($hydroLitres, 1) ?><small>L / <?= number_format($hydroTarget, 0) ?>L</small>
            </div>
            <div class="ck-glasses" id="ck-glasses" role="img"
                 aria-label="<?= (int)round($hydroLitres * 4) ?> of 8 glasses filled">
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="ck-glass <?= $i < round($hydroLitres * 4) ? 'is-full' : '' ?>"></div>
                <?php endfor; ?>
            </div>
            <form method="post" class="ck-hydro-controls" id="ck-hydro-form"
                  data-target="<?= number_format($hydroTarget, 0) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_hydration">
                <input type="hidden" name="day" value="<?= e($day) ?>">
                <input type="hidden" name="glass_count" value="<?= (int)($hydration['glass_count'] ?? 0) ?>">
                <input type="hidden" name="other_drinks" value="<?= e((string)($hydration['other_drinks'] ?? '')) ?>">
                <button type="submit" name="liters" value="<?= max(0, $hydroLitres - 0.25) ?>"
                        class="ck-step" aria-label="Remove a glass of water">−</button>
                <button type="submit" name="liters" value="<?= $hydroLitres + 0.25 ?>"
                        class="ck-step ck-step--go" aria-label="Add a glass of water">+</button>
            </form>
        </section>
        <script>
        (function () {
            // "Log a meal ... still reloading on some options" (2026-09-06):
            // the water +/- buttons were a plain form post, so every tap
            // reloaded the whole dashboard. Same fetch()+_ajax=1 pattern as
            // the diary entry page. Only this tile's own number/glasses are
            // updated in place -- the hero stat and the hydration ring
            // elsewhere on this page still show the value as of the last
            // full load, which is a smaller, honest scope for this pass.
            var form = document.getElementById('ck-hydro-form');
            if (!form) return;
            var valueEl   = document.getElementById('ck-hydro-value');
            var glassesEl = document.getElementById('ck-glasses');
            var minusBtn  = form.querySelector('.ck-step:not(.ck-step--go)');
            var plusBtn   = form.querySelector('.ck-step--go');
            var target    = parseFloat(form.dataset.target || '0') || 0;
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var data = new FormData(form);
                var submitter = ev.submitter || document.activeElement;
                if (submitter && submitter.name === 'liters') data.set('liters', submitter.value);
                data.set('_ajax', '1');
                fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('bad response')); })
                    .then(function (res) {
                        if (!res || !res.ok) return Promise.reject(new Error('save failed'));
                        if (valueEl) {
                            valueEl.innerHTML = res.liters.toFixed(1) + '<small>L / ' + target.toFixed(0) + 'L</small>';
                        }
                        if (glassesEl) {
                            var glasses = glassesEl.querySelectorAll('.ck-glass');
                            for (var i = 0; i < glasses.length; i++) {
                                glasses[i].classList.toggle('is-full', i < res.glasses);
                            }
                            glassesEl.setAttribute('aria-label', res.glasses + ' of 8 glasses filled');
                        }
                        // The +/- buttons' own [value] attributes are the
                        // literal numbers to submit next -- computed once at
                        // page load from the server-rendered total, so they
                        // go stale after the very first AJAX change unless
                        // refreshed here too (otherwise a second click would
                        // resubmit the ORIGINAL total +/-0.25, undoing the
                        // first click instead of moving further).
                        if (minusBtn) minusBtn.value = Math.max(0, res.liters - 0.25);
                        if (plusBtn)  plusBtn.value  = res.liters + 0.25;
                    })
                    .catch(function () { form.submit(); });
            });
        })();
        </script>

        <!-- Mood -->
        <section class="ck-tile ck-s4">
            <?php coaching_tile_head('Right now', 'Mood'); ?>
            <div class="ck-emoji-grid">
                <?php foreach ($emotionEmojis as $key => $glyph): ?>
                    <a href="?view=entry&amp;emotion=<?= e($key) ?>" class="ck-emoji"
                       aria-label="Log a meal feeling <?= e($emotions[$key] ?? $key) ?>">
                        <span class="ck-emoji-glyph" aria-hidden="true"><?= $glyph ?></span>
                        <span class="ck-emoji-label"><?= e($emotions[$key] ?? $key) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Today's meals -->
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Nutrition', "Today's meals",
                '<a href="?view=diary" class="ck-tile-action">Diary →</a>'); ?>
            <div class="ck-meals">
                <?php foreach (['breakfast' => slate_icon('egg'), 'lunch' => slate_icon('salad'), 'dinner' => slate_icon('plate')] as $slot => $glyph):
                    $loggedMeal = $mealsBySlot[$slot] ?? [];
                    if ($loggedMeal):
                        $me = $loggedMeal[0];
                ?>
                    <a href="?view=entry&amp;id=<?= (int)$me['id'] ?>" class="ck-meal">
                        <span class="ck-meal-icon" aria-hidden="true"><?= $glyph ?></span>
                        <span class="ck-meal-body">
                            <span class="ck-meal-title"><?= e($slot) ?></span>
                            <?php if (!empty($me['summary'])): ?>
                                <span class="ck-meal-sub"><?= e($me['summary']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="ck-meal-status" aria-label="Logged">✓</span>
                    </a>
                <?php else: ?>
                    <a href="?view=entry" class="ck-meal ck-meal--empty">
                        <span class="ck-meal-icon" aria-hidden="true"><?= $glyph ?></span>
                        <span class="ck-meal-body">
                            <span class="ck-meal-title"><?= e($slot) ?></span>
                            <span class="ck-meal-sub">Tap to log</span>
                        </span>
                        <span class="ck-meal-status" aria-label="Not logged">+</span>
                    </a>
                <?php endif; endforeach; ?>
            </div>
        </section>

        <!-- Daily goals -->
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Progress', 'Daily goals',
                $goalCount > 0
                    ? '<span class="ck-count">' . (int)$goalsDone . '/' . (int)$goalCount . '</span>'
                    : ''); ?>
            <?php if (!$goals): ?>
                <?php coaching_empty(slate_icon('target'), 'No daily goals set',
                    'Your practitioner will set your goals soon.'); ?>
            <?php else: foreach ($goals as $g):
                $current = $todayCheckins[(int)$g['id']] ?? '';
            ?>
                <div class="ck-goal">
                    <div class="ck-goal-title"><?= e($g['title']) ?></div>
                    <form method="post" class="ck-goal-btns">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="checkin">
                        <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                        <input type="hidden" name="day" value="<?= e($day) ?>">
                        <?php
                        $statuses = [
                            'not_achieved' => ['miss',   'Missed'],
                            'partial'      => ['part',   'Partial'],
                            'achieved'     => ['done',   'Done'],
                            'exceeded'     => ['exceed', 'Exceeded'],
                        ];
                        foreach ($statuses as $value => [$mod, $label]):
                            $on = ($current === $value);
                        ?>
                            <button type="submit" name="status" value="<?= e($value) ?>"
                                    class="ck-gbtn ck-gbtn--<?= e($mod) ?> <?= $on ? 'is-on' : '' ?>"
                                    aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($label) ?></button>
                        <?php endforeach; ?>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <!-- Active challenge -->
        <?php if ($activeChallenge): ?>
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Motivation',
                $activeChallenge['kind'] === 'exercise' ? 'Active exercise' : 'Active challenge',
                '<a href="?view=motivation" class="ck-tile-action">Open →</a>'); ?>
            <div class="ck-row">
                <span class="ck-row-glyph" aria-hidden="true"><?= $activeChallenge['kind'] === 'exercise' ? slate_icon('dumbbell') : slate_icon('target') ?></span>
                <div class="ck-row-body">
                    <div class="ck-row-title"><?= e($activeChallenge['title']) ?></div>
                    <?php if (!empty($activeChallenge['description_html'])):
                        $plain = trim(strip_tags((string)$activeChallenge['description_html']));
                    ?>
                        <div class="ck-row-sub">
                            <?= e(mb_substr($plain, 0, 140)) ?><?= mb_strlen($plain) > 140 ? '…' : '' ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Chat preview -->
        <?php if ($latestMsg): ?>
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('From your practitioner', 'Latest message',
                $unreadFromCoach > 0
                    ? '<span class="ck-badge">' . (int)$unreadFromCoach . ' new</span>'
                    : '<a href="?view=chat" class="ck-tile-action">Open →</a>'); ?>
            <a href="?view=chat" class="ck-chat-preview">
                <span class="ck-chat-avatar" aria-hidden="true">C</span>
                <span class="ck-row-body">
                    <span class="ck-chat-name">Your practitioner</span>
                    <span class="ck-chat-msg"><?= !empty($latestMsg['body']) ? e($latestMsg['body']) : slate_icon('camera') . ' Photo' ?></span>
                </span>
            </a>
        </section>
        <?php endif; ?>

        <!-- Activity -->
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Movement', 'Physical activity'); ?>
            <?php foreach ($todayActivity as $a): ?>
                <div class="ck-row">
                    <span class="ck-row-glyph" aria-hidden="true"><?= slate_icon('activity') ?></span>
                    <div class="ck-row-body">
                        <div class="ck-row-title"><?= e($a['kind']) ?></div>
                        <?php if (!empty($a['duration_min'])): ?>
                            <div class="ck-row-sub"><?= (int)$a['duration_min'] ?> min</div>
                        <?php endif; ?>
                    </div>
                    <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="delete_activity">
                        <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="ck-icon-btn"
                                aria-label="<?= e('Remove ' . $a['kind']) ?>">×</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <form method="post" class="ck-inline-form" style="margin-top:<?= $todayActivity ? '12px' : '0' ?>;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="add_activity">
                <input type="hidden" name="day" value="<?= e($day) ?>">
                <input type="text" name="kind" required class="ck-input"
                       placeholder="Walk, yoga, cardio…" aria-label="Activity type">
                <input type="number" name="duration_min" class="ck-input" style="flex:0 1 90px;"
                       placeholder="min" aria-label="Duration in minutes">
                <button type="submit" class="ck-btn ck-btn--primary ck-btn--sm">Log</button>
            </form>
        </section>

        <!-- Small wins.
             Last of the half-width run: meals + goals + activity + wins always
             render (4, even), and the challenge / chat tiles above are each
             conditional. So exactly one of them present makes the run odd and
             strands this tile alone in a half-empty row — which is the state a
             typical client is in (a challenge set, no message yet). When the
             run is odd this one goes full-width and closes the row instead. -->
        <?php $ckHalfRunOdd = ((int)(bool)$activeChallenge + (int)(bool)$latestMsg) % 2 === 1; ?>
        <section class="ck-tile <?= $ckHalfRunOdd ? 'ck-s12' : 'ck-s6' ?>">
            <?php coaching_tile_head('Bonus', 'Small wins'); ?>
            <?php if ($extraActions): ?>
                <?php foreach ($extraActions as $ea): ?>
                    <div class="ck-row">
                        <span class="ck-row-glyph" aria-hidden="true">⭐</span>
                        <div class="ck-row-body">
                            <div class="ck-row-title"><?= e($ea['action_text']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="ck-tile-sub" style="margin-top:0;">
                    Something extra today? Every small win counts.
                </p>
            <?php endif; ?>
            <form method="post" class="ck-inline-form" style="margin-top:12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="extra_action">
                <input type="hidden" name="day" value="<?= e($day) ?>">
                <input type="text" name="action_text" required class="ck-input"
                       placeholder="e.g. Took the stairs at work" aria-label="Small win">
                <button type="submit" class="ck-btn ck-btn--primary ck-btn--sm">Add</button>
            </form>
        </section>

        <!-- Program areas -->
        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Your program', 'Everything else'); ?>
            <div class="ck-navgrid">
                <?php foreach (coaching_program_links() as [$slug, $glyph, $label]): ?>
                    <a href="?view=<?= e($slug) ?>" class="ck-navcard">
                        <span aria-hidden="true"><?= $glyph ?></span>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

    </div>
    <?php
}

function coaching_render_profile(int $cid, ?array $flash): void {
    $p = CoachingAPI::getProfile($cid) ?? [];
    $intolerances = is_array($p['intolerances'] ?? null) ? implode(', ', $p['intolerances']) : '';
    $dietPref     = is_array($p['dietary_preferences'] ?? null) ? implode(', ', $p['dietary_preferences']) : '';
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-alert ck-alert--success" role="status" id="ck-profile-saved-banner" style="display:none;">
        <span aria-hidden="true">✓</span>
        <span>Profile updated.</span>
    </div>
    <div class="ck-alert ck-alert--danger" role="alert" id="ck-profile-error-banner" style="display:none;">
        <span aria-hidden="true">!</span>
        <span>Couldn't save just now -- check your connection and try again.</span>
    </div>

    <form method="post" id="ck-profile-form">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_profile">

        <div class="ck-bento">

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('About you', 'Identity'); ?>
                <div class="ck-grid-2">
                    <div class="ck-field">
                        <label class="ck-label" for="dob">Date of birth</label>
                        <input class="ck-input" type="date" id="dob" name="dob" value="<?= e($p['dob'] ?? '') ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="gender">Gender</label>
                        <select class="ck-input" id="gender" name="gender">
                            <?php foreach ([
                                ''            => '— pick one —',
                                'female'      => 'Female',
                                'male'        => 'Male',
                                'other'       => 'Other',
                                'undisclosed' => 'Prefer not to say',
                            ] as $v => $lbl): ?>
                                <option value="<?= e($v) ?>" <?= (($p['gender'] ?? '') === $v) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="body_type">Body type</label>
                    <input class="ck-input" type="text" id="body_type" name="body_type" maxlength="80"
                           value="<?= e($p['body_type'] ?? '') ?>"
                           placeholder="Ectomorph / Mesomorph / Endomorph…">
                </div>
            </section>

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('Measurements', 'Body'); ?>
                <div class="ck-grid-2">
                    <div class="ck-field">
                        <label class="ck-label" for="height_cm">Height (cm)</label>
                        <input class="ck-input" type="number" step="0.1" id="height_cm" name="height_cm"
                               value="<?= e($p['height_cm'] ?? '') ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="weight_kg">Weight (kg)</label>
                        <input class="ck-input" type="number" step="0.1" id="weight_kg" name="weight_kg"
                               value="<?= e($p['weight_kg'] ?? '') ?>">
                    </div>
                </div>
                <?php if (!empty($p['bmi']) || !empty($p['bmr'])): ?>
                    <div class="ck-grid-auto" style="margin-top:6px;">
                        <div>
                            <div class="ck-stat-value"><?= $p['bmi'] ? e(number_format((float)$p['bmi'], 1)) : '—' ?></div>
                            <div class="ck-stat-label">BMI</div>
                        </div>
                        <div>
                            <div class="ck-stat-value"><?= $p['bmr'] ? (int)$p['bmr'] : '—' ?><small> kcal</small></div>
                            <div class="ck-stat-label">BMR / day</div>
                        </div>
                        <div>
                            <div class="ck-stat-value"><?= $p['tdee'] ? (int)$p['tdee'] : '—' ?><small> kcal</small></div>
                            <div class="ck-stat-label">TDEE / day</div>
                        </div>
                    </div>
                    <p class="ck-hint">Calculated from your height, weight and date of birth.</p>
                <?php endif; ?>
            </section>

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('Nutrition', 'Diet'); ?>
                <div class="ck-field">
                    <label class="ck-label" for="intolerances_csv">Intolerances</label>
                    <input class="ck-input" type="text" id="intolerances_csv" name="intolerances_csv"
                           value="<?= e($intolerances) ?>" placeholder="gluten, lactose, peanuts…">
                    <div class="ck-hint">Comma-separated.</div>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="dietary_pref_csv">Dietary preferences</label>
                    <input class="ck-input" type="text" id="dietary_pref_csv" name="dietary_pref_csv"
                           value="<?= e($dietPref) ?>" placeholder="vegetarian, low sugar…">
                    <div class="ck-hint">Anything I should know when I plan meals for you.</div>
                </div>
            </section>

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('Private', 'Medical'); ?>
                <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                    These stay between us. Skip anything you're not ready to share —
                    you can always update it later.
                </p>
                <div class="ck-field">
                    <label class="ck-label" for="pathologies">Pathologies (past / current)</label>
                    <textarea class="ck-input" id="pathologies" name="pathologies" rows="3"><?= e($p['pathologies'] ?? '') ?></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ongoing_care">Ongoing care</label>
                    <textarea class="ck-input" id="ongoing_care" name="ongoing_care" rows="3"><?= e($p['ongoing_care'] ?? '') ?></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="alternative_medicine">Alternative medicine follow-up</label>
                    <textarea class="ck-input" id="alternative_medicine" name="alternative_medicine" rows="2"><?= e($p['alternative_medicine'] ?? '') ?></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="personal_issues">Personal issues (stress, weight, work…)</label>
                    <textarea class="ck-input" id="personal_issues" name="personal_issues" rows="3"><?= e($p['personal_issues'] ?? '') ?></textarea>
                </div>
            </section>

        </div>

        <div class="ck-savebar">
            <div class="ck-savebar-inner">
                <a href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>"
                   class="ck-btn ck-btn--quiet">Sign out</a>
                <div class="ck-savebar-right">
                    <button type="submit" class="ck-btn ck-btn--primary">Save profile</button>
                </div>
            </div>
        </div>
    </form>
    <script>
    (function () {
        // "still reloading on some options" (2026-09-06): Save profile was a
        // plain form post -- full reload for a form with no navigation
        // purpose. Same fetch()+_ajax=1 pattern as the diary entry page.
        var form = document.getElementById('ck-profile-form');
        if (!form) return;
        var savedBanner = document.getElementById('ck-profile-saved-banner');
        var errorBanner = document.getElementById('ck-profile-error-banner');
        var saveBtn = form.querySelector('button[type="submit"]');
        var bannerTimer = null;

        function flashBanner(el) {
            if (!el) return;
            [savedBanner, errorBanner].forEach(function (b) { if (b && b !== el) b.style.display = 'none'; });
            el.style.display = '';
            clearTimeout(bannerTimer);
            bannerTimer = setTimeout(function () { el.style.display = 'none'; }, 4000);
        }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (saveBtn) { saveBtn.disabled = true; saveBtn.dataset.label = saveBtn.textContent; saveBtn.textContent = 'Saving\u2026'; }
            var data = new FormData(form);
            data.set('_ajax', '1');
            fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('bad response')); })
                .then(function (res) {
                    if (!res || !res.ok) return Promise.reject(new Error('save failed'));
                    flashBanner(savedBanner);
                })
                .catch(function () { flashBanner(errorBanner); })
                .then(function () {
                    if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = saveBtn.dataset.label || 'Save profile'; }
                });
        });
    })();
    </script>
    <?php
}

function coaching_render_goals(int $cid, ?array $flash): void {
    $goals = CoachingAPI::listGoals($cid, null, true);
    $day   = date('Y-m-d');
    $tid   = current_tenant_id();

    $checkins = [];
    foreach (Database::rows(
        "SELECT goal_id, status FROM coaching_goal_checkin
          WHERE tenant_id = ? AND customer_id = ? AND day = ?", [$tid, $cid, $day]) as $r) {
        $checkins[(int)$r['goal_id']] = $r['status'];
    }

    $grouped = [];
    foreach ($goals as $g) { $grouped[$g['scope']][] = $g; }

    $dailyGoals = $grouped['daily'] ?? [];
    $dailyDone  = 0;
    foreach ($dailyGoals as $g) {
        if (in_array($checkins[(int)$g['id']] ?? '', ['achieved', 'exceeded'], true)) $dailyDone++;
    }
    $dailyPct = count($dailyGoals) > 0 ? ($dailyDone / count($dailyGoals)) * 100 : 0;

    $scopeLabels = [
        'daily'    => 'Daily goals',
        'weekly'   => 'Weekly goals',
        'monthly'  => 'Monthly goals',
        'general'  => 'General goals',
        'personal' => 'Personal goals',
    ];
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <?php if (!$goals): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty(slate_icon('target'), 'No goals set yet',
                    'Your practitioner will set your daily, weekly and monthly goals here.'); ?>
            </section>
        <?php else: ?>

            <section class="ck-tile ck-s4">
                <?php coaching_tile_head('Today', 'Scorecard'); ?>
                <div class="ck-rings" style="grid-template-columns:1fr;">
                    <?= coaching_ring($dailyPct, '--ck-ring-1', slate_icon('target'),
                            (int)$dailyDone . '/' . count($dailyGoals), 'Daily goals') ?>
                </div>
            </section>

            <div class="ck-s8" style="display:grid;gap:16px;">
            <?php foreach ($scopeLabels as $scope => $label):
                if (empty($grouped[$scope])) continue;
                $isDaily = ($scope === 'daily');
            ?>
                <section class="ck-tile">
                    <?php coaching_tile_head(ucfirst($scope), $label); ?>
                    <?php foreach ($grouped[$scope] as $g):
                        $current = $checkins[(int)$g['id']] ?? '';
                    ?>
                        <div class="ck-goal">
                            <div class="ck-goal-title"><?= e($g['title']) ?></div>
                            <?php if (!empty($g['description'])): ?>
                                <div class="ck-goal-desc"><?= e($g['description']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($g['target_count'])): ?>
                                <div class="ck-goal-desc">Target: <?= (int)$g['target_count'] ?>/day</div>
                            <?php endif; ?>

                            <?php if ($isDaily): ?>
                                <form method="post" class="ck-goal-btns">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="checkin">
                                    <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                                    <input type="hidden" name="day" value="<?= e($day) ?>">
                                    <?php
                                    $statuses = [
                                        'not_achieved' => ['miss',   'Missed'],
                                        'partial'      => ['part',   'Partial'],
                                        'achieved'     => ['done',   'Done'],
                                        'exceeded'     => ['exceed', 'Exceeded'],
                                    ];
                                    foreach ($statuses as $value => [$mod, $lbl]):
                                        $on = ($current === $value);
                                    ?>
                                        <button type="submit" name="status" value="<?= e($value) ?>"
                                                class="ck-gbtn ck-gbtn--<?= e($mod) ?> <?= $on ? 'is-on' : '' ?>"
                                                aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($lbl) ?></button>
                                    <?php endforeach; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>
    <?php
}

function coaching_render_diary(int $cid, ?array $flash): void {
    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-13 days'));
    $entries = CoachingAPI::listDiaryEntries($cid, $from, $to);

    $byDay = [];
    foreach ($entries as $en) { $byDay[$en['day']][] = $en; }

    $mealEmoji = coaching_meal_emoji();
    $emotions  = CoachingAPI::emotions();
    $daysWithEntries = count($byDay);
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Last 14 days', 'Consistency',
                '<a href="?view=entry" class="ck-tile-action">+ New entry</a>'); ?>
            <div class="ck-grid-auto">
                <div>
                    <div class="ck-stat-value"><?= (int)count($entries) ?></div>
                    <div class="ck-stat-label">Entries</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$daysWithEntries ?><small>/14</small></div>
                    <div class="ck-stat-label">Days logged</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)round(($daysWithEntries / 14) * 100) ?><small>%</small></div>
                    <div class="ck-stat-label">Consistency</div>
                </div>
            </div>
        </section>

        <?php if (!$byDay): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty(slate_icon('book'), 'Nothing logged yet',
                    'Meals, snacks and drinks you log show up here. '
                    . '<a href="?view=entry">Log your first meal →</a>'); ?>
            </section>
        <?php else: foreach ($byDay as $day => $items): ?>
            <section class="ck-tile ck-s6">
                <?php coaching_tile_head(I18n::localDate('j M', strtotime($day)), coaching_day_label($day),
                    '<span class="ck-count">' . count($items) . '</span>'); ?>
                <?php foreach ($items as $en): ?>
                    <a href="?view=entry&amp;id=<?= (int)$en['id'] ?>" class="ck-row"
                       style="text-decoration:none;color:inherit;">
                        <span class="ck-row-glyph" aria-hidden="true"><?= $mealEmoji[$en['meal_type']] ?? slate_icon('utensils') ?></span>
                        <span class="ck-row-body">
                            <span class="ck-row-title" style="text-transform:capitalize;">
                                <?= e($en['meal_type']) ?>
                                <?php if (!empty($en['started_at'])): ?>
                                    · <?= e(substr((string)$en['started_at'], 0, 5)) ?>
                                <?php endif; ?>
                            </span>
                            <span class="ck-row-sub">
                                <?php if (!empty($en['emotion'])): ?>
                                    <?= e($emotions[$en['emotion']] ?? $en['emotion']) ?><?= !empty($en['summary']) ? ' · ' : '' ?>
                                <?php endif; ?>
                                <?= e((string)($en['summary'] ?? '')) ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endforeach; endif; ?>

    </div>
    <?php
}

/**
 * Renders the diary-entry photo grid's inner HTML (shared by the initial
 * page render and the save_entry AJAX response, so a freshly uploaded
 * photo appears without a page reload).
 */
function coaching_render_diary_photos_html(array $photos): string {
    if (!$photos) return '';
    ob_start(); ?>
        <?php foreach ($photos as $ph): ?>
            <div class="ck-photo">
                <img src="<?= e(SLATE_URL . '/' . ltrim($ph['file_path'], '/')) ?>" alt="">
                <form method="post" class="ck-photo-del-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete_photo">
                    <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
                    <input type="hidden" name="entry_id" value="<?= (int)$ph['entry_id'] ?>">
                    <button type="submit" class="ck-photo-del" aria-label="Delete this photo">×</button>
                </form>
            </div>
        <?php endforeach;
    return (string) ob_get_clean();
}

function coaching_render_entry(int $cid, ?array $flash): void {
    $entryId = (int)($_GET['id'] ?? 0);
    $entry   = $entryId > 0 ? CoachingAPI::getDiaryEntry($entryId, $cid) : null;
    if ($entry && (int)$entry['customer_id'] !== $cid) $entry = null;

    // Emotion preselected from ?emotion=key on the home mood picker.
    $prefEmotion = (string)($_GET['emotion'] ?? '');
    $saved = !empty($_GET['saved']);

    $foods = $entry['foods'] ?? [];
    while (count($foods) < 4) $foods[] = ['name' => '', 'category' => '', 'is_pleasure_food' => 0];

    $categories   = CoachingAPI::foodCategories();
    $emotions     = CoachingAPI::emotions();
    $contexts     = CoachingAPI::contexts();
    $emotionEmoji = coaching_emotion_emoji();
    $mealEmoji    = coaching_meal_emoji();
    $contextEmoji = coaching_context_emoji();

    $mealLabels = [
        'breakfast'=>'Breakfast','lunch'=>'Lunch','dinner'=>'Dinner','snack'=>'Snack',
        'binge'=>'Binge','drink'=>'Drink','other'=>'Other',
    ];

    $curMeal    = (string)($entry['meal_type'] ?? 'other');
    $curEmotion = (string)($entry['emotion'] ?? $prefEmotion);
    $curContext = (string)($entry['context'] ?? '');
    $curHunger  = (int)($entry['hunger_before'] ?? 3);
    $curSatiety = (int)($entry['satiety_after'] ?? 3);
    ?>

    <div style="margin-bottom:14px;">
        <a href="?view=diary" class="ck-btn ck-btn--ghost ck-btn--sm">← All entries</a>
    </div>

    <?php if ($saved): ?>
        <div class="ck-alert ck-alert--success" role="status">
            <span aria-hidden="true">✓</span>
            <span>Saved — your practitioner sees it live.</span>
        </div>
    <?php else: coaching_alert($flash); endif; ?>

    <div class="ck-alert ck-alert--success" role="status" id="ck-entry-saved-banner" style="display:none;">
        <span aria-hidden="true">✓</span>
        <span>Saved — your practitioner sees it live.</span>
    </div>
    <div class="ck-alert ck-alert--danger" role="alert" id="ck-entry-error-banner" style="display:none;">
        <span aria-hidden="true">!</span>
        <span>Couldn't save just now — check your connection and try again.</span>
    </div>

    <form method="post" enctype="multipart/form-data" id="ck-entry-form">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_entry">
        <input type="hidden" name="id" id="ck-entry-id-input" value="<?= (int)($entry['id'] ?? 0) ?>">
        <input type="hidden" name="meal_type"     id="ck-meal-input"    value="<?= e($curMeal) ?>">
        <input type="hidden" name="emotion"       id="ck-emotion-input" value="<?= e($curEmotion) ?>">
        <input type="hidden" name="context"       id="ck-context-input" value="<?= e($curContext) ?>">
        <input type="hidden" name="hunger_before" id="ck-hunger-input"  value="<?= (int)$curHunger ?>">
        <input type="hidden" name="satiety_after" id="ck-satiety-input" value="<?= (int)$curSatiety ?>">

        <div class="ck-bento">

            <!-- When & what kind -->
            <section class="ck-tile ck-s12">
                <?php coaching_tile_head('The moment', 'When & what kind'); ?>
                <div class="ck-grid-auto">
                    <div class="ck-field">
                        <label class="ck-label" for="ck-day">Day</label>
                        <input class="ck-input" type="date" id="ck-day" name="day" required
                               value="<?= e($entry['day'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="ck-started">Started at</label>
                        <input class="ck-input" type="time" id="ck-started" name="started_at"
                               value="<?= e(substr((string)($entry['started_at'] ?? ''), 0, 5)) ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="ck-duration">Duration (min)</label>
                        <input class="ck-input" type="number" id="ck-duration" name="duration_min"
                               min="0" max="600" value="<?= e((string)($entry['duration_min'] ?? '')) ?>">
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label" id="ck-meal-label">Meal type</span>
                    <div class="ck-chips" id="ck-meal-chips" role="group" aria-labelledby="ck-meal-label">
                        <?php foreach ($mealLabels as $key => $label):
                            $on = ($curMeal === $key);
                        ?>
                            <button type="button" class="ck-chip <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= e($key) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
                                <span class="ck-chip-glyph" aria-hidden="true"><?= $mealEmoji[$key] ?? slate_icon('utensils') ?></span>
                                <?= e($label) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <label class="ck-label" for="ck-qty">Quantity note</label>
                    <input class="ck-input" type="text" id="ck-qty" name="quantity_note" maxlength="300"
                           value="<?= e((string)($entry['quantity_note'] ?? '')) ?>"
                           placeholder="small plate, medium bowl, one glass…">
                </div>
            </section>

            <!-- Foods -->
            <section class="ck-tile ck-s7">
                <?php coaching_tile_head('Nutrition', 'What you ate'); ?>
                <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                    One item per line. Pick a category so I can build your food-group chart.
                </p>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($foods as $i => $f): ?>
                        <div style="display:grid;grid-template-columns:1.7fr 1fr auto;gap:8px;align-items:center;">
                            <input class="ck-input" type="text" name="food_name[]" maxlength="200"
                                   value="<?= e((string)$f['name']) ?>" placeholder="e.g. green salad"
                                   aria-label="Food item <?= (int)$i + 1 ?>">
                            <select class="ck-input" name="food_category[]"
                                    aria-label="Category for item <?= (int)$i + 1 ?>">
                                <option value="">— category —</option>
                                <?php foreach ($categories as $v => $lbl): ?>
                                    <option value="<?= e($v) ?>" <?= (($f['category'] ?? '') === $v) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="ck-tag" style="cursor:pointer;padding:9px 11px;display:inline-flex;gap:6px;align-items:center;"
                                   title="Pleasure food">
                                <input type="checkbox" name="food_pleasure[<?= (int)$i ?>]" value="1"
                                       <?= !empty($f['is_pleasure_food']) ? 'checked' : '' ?>
                                       aria-label="Item <?= (int)$i + 1 ?> is a pleasure food">
                                <span aria-hidden="true"><?= slate_icon('cookie') ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Feeling -->
            <section class="ck-tile ck-s5">
                <?php coaching_tile_head('Right then', 'How were you feeling?'); ?>

                <div class="ck-field">
                    <span class="ck-label" id="ck-emo-label">Emotion</span>
                    <div class="ck-emoji-grid" id="ck-emotion-chips" role="group" aria-labelledby="ck-emo-label">
                        <?php foreach ($emotionEmoji as $key => $glyph):
                            $on = ($curEmotion === $key);
                        ?>
                            <button type="button" class="ck-emoji <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= e($key) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
                                <span class="ck-emoji-glyph" aria-hidden="true"><?= $glyph ?></span>
                                <span class="ck-emoji-label"><?= e($emotions[$key] ?? $key) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label ck-scale-label" id="ck-hunger-label">
                        <span>Hunger before</span><span>1 starving · 5 not hungry</span>
                    </span>
                    <div class="ck-dots" id="ck-hunger-dots" role="group" aria-labelledby="ck-hunger-label">
                        <?php for ($h = 1; $h <= 5; $h++):
                            $on = ($curHunger === $h);
                        ?>
                            <button type="button" class="ck-dot <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= $h ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"
                                    aria-label="Hunger <?= $h ?> of 5"><?= $h ?></button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label ck-scale-label" id="ck-satiety-label">
                        <span>Satiety after</span><span>1 hungry · 5 overfull</span>
                    </span>
                    <div class="ck-dots" id="ck-satiety-dots" role="group" aria-labelledby="ck-satiety-label">
                        <?php for ($h = 1; $h <= 5; $h++):
                            $on = ($curSatiety === $h);
                        ?>
                            <button type="button" class="ck-dot <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= $h ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"
                                    aria-label="Satiety <?= $h ?> of 5"><?= $h ?></button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label" id="ck-ctx-label">Where / with whom</span>
                    <div class="ck-chips" id="ck-context-chips" role="group" aria-labelledby="ck-ctx-label">
                        <?php foreach ($contexts as $key => $label):
                            $on = ($curContext === $key);
                        ?>
                            <button type="button" class="ck-chip <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= e($key) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
                                <span class="ck-chip-glyph" aria-hidden="true"><?= $contextEmoji[$key] ?? slate_icon('map-pin') ?></span>
                                <?= e($label) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <label class="ck-label" for="ck-emotion-note">Anything to add</label>
                    <input class="ck-input" type="text" id="ck-emotion-note" name="emotion_note" maxlength="500"
                           value="<?= e((string)($entry['emotion_note'] ?? '')) ?>"
                           placeholder="quick note about how you felt">
                </div>
            </section>

            <!-- Photos & notes -->
            <section class="ck-tile ck-s12">
                <?php coaching_tile_head('Extra', 'Photos & notes'); ?>

                <?php $entryPhotosHtml = coaching_render_diary_photos_html($entry['photos'] ?? []); ?>
                <div class="ck-photos" id="ck-photos-list" style="<?= $entryPhotosHtml === '' ? 'display:none;' : '' ?>"><?= $entryPhotosHtml ?></div>

                <label class="ck-dropzone" for="ck-photo" id="ck-dropzone-label">
                    <span aria-hidden="true"><?= slate_icon('camera') ?></span>
                    <span>Add a photo</span>
                    <small>up to 10 MB</small>
                </label>
                <input type="file" id="ck-photo" name="photo" accept="image/*" style="display:none;">

                <div class="ck-field" style="margin-top:14px;">
                    <label class="ck-label" for="ck-notes">Notes</label>
                    <textarea class="ck-input" id="ck-notes" name="notes" rows="4"
                              placeholder="Anything else on your mind about this meal…"><?= e((string)($entry['notes'] ?? '')) ?></textarea>
                </div>
            </section>

        </div>

        <div class="ck-savebar">
            <div class="ck-savebar-inner">
                <a href="?view=home" class="ck-btn ck-btn--quiet" id="ck-cancel-link"
                   style="<?= $entry ? 'display:none;' : '' ?>">Cancel</a>
                <button type="button" class="ck-btn ck-btn--danger ck-btn--sm" id="ck-delete-entry"
                        style="<?= $entry ? '' : 'display:none;' ?>">
                    <?= slate_icon('trash') ?> Delete
                </button>
                <div class="ck-savebar-right">
                    <a href="?view=home" class="ck-btn ck-btn--ghost ck-btn--sm" id="ck-cancel-link-sm"
                       style="<?= $entry ? '' : 'display:none;' ?>">Cancel</a>
                    <button type="submit" class="ck-btn ck-btn--primary">✓ Save entry</button>
                </div>
            </div>
        </div>
    </form>

    <form method="post" id="ck-delete-form" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="delete_entry">
        <input type="hidden" name="entry_id" id="ck-delete-entry-id" value="<?= (int)($entry['id'] ?? 0) ?>">
    </form>

    <script>
    (function () {
        // Chip / emoji / dot pickers all follow the same contract: a group of
        // buttons carrying data-value, writing into one hidden input. State
        // lives entirely in the class + aria-pressed, so CSS owns the look.
        function bindGroup(groupId, hiddenId) {
            var group  = document.getElementById(groupId);
            var hidden = document.getElementById(hiddenId);
            if (!group || !hidden) return;

            group.addEventListener('click', function (ev) {
                var btn = ev.target.closest('button[data-value]');
                if (!btn || !group.contains(btn)) return;
                hidden.value = btn.dataset.value;
                group.querySelectorAll('button[data-value]').forEach(function (b) {
                    var on = (b === btn);
                    b.classList.toggle('is-on', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            });
        }

        bindGroup('ck-meal-chips',    'ck-meal-input');
        bindGroup('ck-context-chips', 'ck-context-input');
        bindGroup('ck-emotion-chips', 'ck-emotion-input');
        bindGroup('ck-hunger-dots',   'ck-hunger-input');
        bindGroup('ck-satiety-dots',  'ck-satiety-input');

        // -- Save entry on-page (2026-09-06) --------------------------------
        // Was a plain form post -> full page reload on every save. Now a
        // fetch() with the same FormData (so the photo upload still works),
        // flagged _ajax=1 so the router replies with JSON instead of a
        // redirect. Chips/dots above already update instantly with no
        // reload -- this closes the loop for the one action that still did.
        var form   = document.getElementById('ck-entry-form');
        var idInput = document.getElementById('ck-entry-id-input');
        var savedBanner = document.getElementById('ck-entry-saved-banner');
        var errorBanner = document.getElementById('ck-entry-error-banner');
        var photosList  = document.getElementById('ck-photos-list');
        var photoInput  = document.getElementById('ck-photo');
        var dropzoneLbl = document.getElementById('ck-dropzone-label');
        var saveBtn     = form ? form.querySelector('button[type="submit"]') : null;
        var bannerTimer = null;

        function flashBanner(el) {
            if (!el) return;
            [savedBanner, errorBanner].forEach(function (b) { if (b && b !== el) b.style.display = 'none'; });
            el.style.display = '';
            clearTimeout(bannerTimer);
            bannerTimer = setTimeout(function () { el.style.display = 'none'; }, 4000);
        }

        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (saveBtn) { saveBtn.disabled = true; saveBtn.dataset.label = saveBtn.textContent; saveBtn.textContent = 'Saving…'; }

                var data = new FormData(form);
                data.set('_ajax', '1');

                fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('bad response')); })
                    .then(function (res) {
                        if (!res || !res.ok) return Promise.reject(new Error('save failed'));

                        // First save of a brand-new entry: it now has a real id.
                        // Swap the URL in place (no navigation) so a refresh or
                        // the back button lands on the saved entry, not a blank
                        // "new entry" form.
                        if (idInput && (!idInput.value || idInput.value === '0')) {
                            idInput.value = res.id;
                            var url = new URL(location.href);
                            url.searchParams.set('id', res.id);
                            history.replaceState(null, '', url);

                            // This entry didn't exist a moment ago -- reveal
                            // the Delete button (and swap the "no entry yet"
                            // Cancel for the ghost one next to Save, matching
                            // how an existing entry's savebar looks), and
                            // point the hidden delete form at the new id.
                            var deleteBtn    = document.getElementById('ck-delete-entry');
                            var cancelLink   = document.getElementById('ck-cancel-link');
                            var cancelLinkSm = document.getElementById('ck-cancel-link-sm');
                            var deleteIdInput = document.getElementById('ck-delete-entry-id');
                            if (deleteBtn) deleteBtn.style.display = '';
                            if (cancelLink) cancelLink.style.display = 'none';
                            if (cancelLinkSm) cancelLinkSm.style.display = '';
                            if (deleteIdInput) deleteIdInput.value = res.id;
                        }

                        // Refresh the photo grid in place if a photo was
                        // attached, and clear the pending file picker.
                        if (photosList) {
                            photosList.innerHTML = res.photosHtml || '';
                            photosList.style.display = res.photosHtml ? '' : 'none';
                        }
                        if (photoInput) photoInput.value = '';
                        if (dropzoneLbl) {
                            var zone = dropzoneLbl.querySelector('span:nth-of-type(2)');
                            if (zone) zone.textContent = 'Add a photo';
                        }

                        flashBanner(savedBanner);
                    })
                    .catch(function () { flashBanner(errorBanner); })
                    .then(function () {
                        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = saveBtn.dataset.label || '✓ Save entry'; }
                    });
            });
        }

        // -- Delete a photo on-page -----------------------------------------
        // Delegated to the wrapper (not bound per-photo) so it still works
        // on photos injected after an AJAX save above.
        if (photosList) {
            photosList.addEventListener('submit', function (ev) {
                var delForm = ev.target.closest('.ck-photo-del-form');
                if (!delForm) return;
                ev.preventDefault();
                var tile = delForm.closest('.ck-photo');
                var btn  = delForm.querySelector('button');
                if (btn) btn.disabled = true;

                var data = new FormData(delForm);
                data.set('_ajax', '1');
                fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('bad response')); })
                    .then(function (res) {
                        if (!res || !res.ok) return Promise.reject(new Error('delete failed'));
                        if (tile) tile.remove();
                        if (photosList && !photosList.querySelector('.ck-photo')) photosList.style.display = 'none';
                    })
                    .catch(function () { if (btn) btn.disabled = false; flashBanner(errorBanner); });
            });
        }

        var del = document.getElementById('ck-delete-entry');
        var delForm = document.getElementById('ck-delete-form');
        if (del && delForm) {
            del.addEventListener('click', function () {
                if (confirm('Delete this entry? This cannot be undone.')) delForm.submit();
            });
        }

        // Show the chosen filename so the dropzone confirms the pick.
        var photo = document.getElementById('ck-photo');
        if (photo) {
            photo.addEventListener('change', function () {
                if (!photo.files || !photo.files.length) return;
                var zone = document.querySelector('label[for="ck-photo"] span:nth-of-type(2)');
                if (zone) zone.textContent = photo.files[0].name;
            });
        }
    })();
    </script>
    <?php
}

function coaching_render_charts(int $cid, ?array $flash): void {
    $range = (int)($_GET['range'] ?? 30);
    if (!in_array($range, [7, 14, 30, 90], true)) $range = 30;
    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));

    $tid = current_tenant_id();
    $meals = (int) Database::value(
        "SELECT COUNT(*) FROM coaching_diary_entry
          WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
        [$tid, $cid, $from, $to]);
    $daysLogged = (int) Database::value(
        "SELECT COUNT(DISTINCT day) FROM coaching_diary_entry
          WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
        [$tid, $cid, $from, $to]);

    ob_start();
    ?>
    <div class="ck-chips">
        <?php foreach ([7 => '7d', 14 => '14d', 30 => '30d', 90 => '90d'] as $v => $lbl): ?>
            <a href="?view=charts&amp;range=<?= (int)$v ?>"
               class="ck-chip ck-chip--link <?= $range === $v ? 'is-on' : '' ?>"
               <?= $range === $v ? 'aria-current="true"' : '' ?>><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
    <?php
    $rangeChips = ob_get_clean();
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Insights', 'Last ' . $range . ' days', $rangeChips); ?>
            <div class="ck-grid-auto">
                <div>
                    <div class="ck-stat-value"><?= (int)$meals ?></div>
                    <div class="ck-stat-label">Meals logged</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$daysLogged ?><small>/<?= (int)$range ?></small></div>
                    <div class="ck-stat-label">Days with entries</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= $range > 0 ? (int)round(($daysLogged / $range) * 100) : 0 ?><small>%</small></div>
                    <div class="ck-stat-label">Consistency</div>
                </div>
            </div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Hydration', 'Litres per day'); ?>
            <div class="ck-chart"><?= CoachingCharts::hydrationLine($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Nutrition', 'Food groups'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                Every classified food item, grouped by category.
            </p>
            <div class="ck-chart"><?= CoachingCharts::foodDistributionPie($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Mood', 'Dominant emotions'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                Emotions logged at meal times.
            </p>
            <div class="ck-chart"><?= CoachingCharts::emotionPie($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Pattern', 'Emotion → food choices'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                What you tend to reach for depending on how you're feeling.
                A longer bar means more entries with that emotion.
            </p>
            <div class="ck-chart"><?= CoachingCharts::emotionFoodCorrelation($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Progress', 'Goal check-ins'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                Daily goal check-ins across the period.
            </p>
            <div class="ck-chart"><?= CoachingCharts::goalProgress($cid, $from, $to) ?></div>
        </section>

    </div>
    <?php
}

/**
 * Renders one chat bubble's inner HTML (shared by the initial page render
 * and the live-poll JSON endpoint above, so the two can never drift).
 */
function coaching_render_bubble_html(array $m, bool $mine): string {
    $stamp = $m['sent_at'] ?: $m['created_at'];
    ob_start(); ?>
        <div class="ck-bubble ck-bubble--<?= $mine ? 'me' : 'them' ?>" data-msg-id="<?= (int)$m['id'] ?>">
            <?php if (!empty($m['photo_path'])): ?>
                <a href="<?= e(SLATE_URL . '/' . ltrim($m['photo_path'], '/')) ?>"
                   target="_blank" rel="noopener">
                    <img src="<?= e(SLATE_URL . '/' . ltrim($m['photo_path'], '/')) ?>" alt="Shared photo">
                </a>
            <?php endif; ?>
            <?php if (!empty($m['body'])): ?>
                <div class="ck-bubble-text"><?= e($m['body']) ?></div>
            <?php endif; ?>
            <div class="ck-bubble-meta">
                <span><?= e(date('H:i', strtotime($stamp))) ?></span>
                <?php if ($mine): ?>
                    <span class="ck-seen"
                          aria-label="<?= !empty($m['seen_at']) ? 'Seen' : 'Sent' ?>"
                          title="<?= !empty($m['seen_at']) ? 'Seen' : 'Sent' ?>"><?= !empty($m['seen_at']) ? '✓✓' : '✓' ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php
    return (string) ob_get_clean();
}

function coaching_render_chat(int $cid, ?array $flash): void {
    $threadId = CoachingAPI::ensureThread($cid);
    // Mark all incoming (practitioner-sent) messages as read.
    CoachingAPI::markThreadRead($threadId, 'customer');
    $messages = CoachingAPI::listMessages($threadId, false, 500);
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">
        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Coach', 'Direct line to your practitioner'); ?>

            <div class="ck-thread" id="ck-thread" data-last-id="<?= $messages ? (int)end($messages)['id'] : 0 ?>" data-last-day="<?= e($messages ? date('Y-m-d', strtotime(end($messages)['sent_at'] ?: end($messages)['created_at'])) : '') ?>">
                <?php if (!$messages): ?>
                    <?php coaching_empty(slate_icon('message'), 'No messages yet',
                        'Send the first one below — this replaces WhatsApp for the program.'); ?>
                <?php else:
                    $lastDay = '';
                    foreach ($messages as $m):
                        $stamp = $m['sent_at'] ?: $m['created_at'];
                        $day   = date('Y-m-d', strtotime($stamp));
                        if ($day !== $lastDay):
                            $lastDay = $day;
                        ?>
                            <div class="ck-daydiv"><?= e(coaching_day_label($day)) ?></div>
                        <?php endif;
                        echo coaching_render_bubble_html($m, $m['sender'] === 'customer');
                    endforeach; endif; ?>
            </div>

            <form method="post" enctype="multipart/form-data" class="ck-composer">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="send_message">
                <textarea class="ck-input" name="body" rows="2"
                          placeholder="Write a message…" aria-label="Message"></textarea>
                <label class="ck-btn ck-btn--ghost" style="cursor:pointer;" title="Attach a photo">
                    <span aria-hidden="true"><?= slate_icon('camera') ?></span>
                    <span class="sr-only">Attach a photo</span>
                    <input type="file" name="photo" accept="image/*" style="display:none;"
                           onchange="this.form.submit();">
                </label>
                <button type="submit" class="ck-btn ck-btn--primary">Send</button>
            </form>
        </section>
    </div>

    <script>
    (function () {
        var thread = document.getElementById('ck-thread');
        if (!thread) return;
        thread.scrollTop = thread.scrollHeight;

        // 2026-09-06: "real live chat" — poll for new messages from the
        // practitioner without a page reload, and chime when one arrives.
        var lastId  = parseInt(thread.getAttribute('data-last-id') || '0', 10);
        var lastDay = thread.getAttribute('data-last-day') || '';
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
        function nearBottom() { return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80; }
        function poll() {
            fetch('?view=chat&poll=1&after=' + lastId, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !data.messages || !data.messages.length) return;
                    var stick = nearBottom();
                    var gotIncoming = false;
                    data.messages.forEach(function (m) {
                        if (m.day !== lastDay) {
                            lastDay = m.day;
                            var div = document.createElement('div');
                            div.className = 'ck-daydiv';
                            div.textContent = m.dayLabel;
                            thread.appendChild(div);
                        }
                        var wrap = document.createElement('div');
                        wrap.innerHTML = m.html;
                        thread.appendChild(wrap.firstElementChild);
                        lastId = m.id;
                        if (!m.mine) gotIncoming = true;
                    });
                    thread.setAttribute('data-last-id', String(lastId));
                    thread.setAttribute('data-last-day', lastDay);
                    if (gotIncoming) chime();
                    if (stick) thread.scrollTop = thread.scrollHeight;
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
    <?php
}

function coaching_render_structure(int $cid): void {
    $items = CoachingAPI::listMealStructure($cid);
    $slotLabels = [
        'breakfast' => [slate_icon('egg'), 'Breakfast'],
        'lunch'     => [slate_icon('salad'), 'Lunch'],
        'dinner'    => [slate_icon('plate'), 'Dinner'],
        'snack'     => [slate_icon('cookie'), 'Snacks'],
        'note'      => [slate_icon('bulb'), 'Notes'],
    ];
    ?>
    <div class="ck-bento">
        <?php if (!$items): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty(slate_icon('stack'), 'Nothing here yet',
                    'Your practitioner hasn&rsquo;t shared a meal structure yet.'); ?>
            </section>
        <?php else:
            $grouped = ['breakfast' => [], 'lunch' => [], 'dinner' => [], 'snack' => [], 'note' => []];
            foreach ($items as $it) { $grouped[$it['slot']][] = $it; }

            foreach ($slotLabels as $slot => [$glyph, $label]):
                if (empty($grouped[$slot])) continue;
        ?>
            <section class="ck-tile ck-s6">
                <div class="ck-row-glyph" aria-hidden="true" style="margin-bottom:6px;"><?= $glyph ?></div>
                <?php coaching_tile_head('Structure', $label); ?>
                <?php foreach ($grouped[$slot] as $it): ?>
                    <div class="ck-row" style="align-items:flex-start;">
                        <div class="ck-row-body">
                            <div class="ck-row-title">
                                <?= e($it['title']) ?>
                                <?php foreach ($it['tags'] as $t): ?>
                                    <span class="ck-tag"><?= e($t) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($it['notes_html'])): ?>
                                <div class="ck-prose" style="margin-top:6px;">
                                    <?= strip_tags($it['notes_html'], '<ul><ol><li><strong><em><br><p>') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; endif; ?>
    </div>
    <?php
}

function coaching_render_shopping(int $cid): void {
    $lists = CoachingAPI::listShoppingLists($cid);
    ?>
    <div class="ck-bento">
        <?php if (!$lists): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty(slate_icon('cart'), 'Nothing here yet',
                    'Your practitioner hasn&rsquo;t shared a shopping list yet.'); ?>
            </section>
        <?php else: foreach ($lists as $l): ?>
            <section class="ck-tile ck-s6">
                <?php
                ob_start();
                foreach ($l['tags'] as $t) {
                    echo '<span class="ck-tag">' . e($t) . '</span> ';
                }
                $tagsHtml = ob_get_clean();
                coaching_tile_head('Shopping', (string)$l['name'], $tagsHtml);
                ?>
                <?php foreach ($l['sections'] as $sec): ?>
                    <div style="margin-bottom:14px;">
                        <?php if (!empty($sec['heading'])): ?>
                            <div class="ck-label" style="margin-bottom:8px;"><?= e($sec['heading']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($sec['items'])): ?>
                            <ul class="ck-prose" style="margin:0;padding-left:18px;">
                                <?php foreach ($sec['items'] as $it): ?>
                                    <li><?= e($it) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; endif; ?>
    </div>
    <?php
}

function coaching_render_recipes(int $cid, ?array $flash): void {
    $recipes = CoachingAPI::listRecipes($cid);
    ?>
    <?php coaching_alert($flash); ?>

    <?php if (!empty($_GET['shared'])): ?>
        <div class="ck-alert ck-alert--success" role="status">
            <span aria-hidden="true">✓</span>
            <span>Recipe shared. Your practitioner will see it in their submissions inbox.</span>
        </div>
    <?php endif; ?>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Library', 'Recipes',
                '<span class="ck-count">' . count($recipes) . '</span>'); ?>
            <?php if (!$recipes): ?>
                <?php coaching_empty(slate_icon('book'), 'No recipes yet',
                    'Your practitioner hasn&rsquo;t shared any yet — but you can share one of yours below.'); ?>
            <?php else: ?>
                <div class="ck-recipes">
                    <?php foreach ($recipes as $r): ?>
                        <a href="?view=recipe&amp;id=<?= (int)$r['id'] ?>" class="ck-recipe">
                            <?php if (!empty($r['photo_path'])): ?>
                                <img class="ck-recipe-img"
                                     src="<?= e(SLATE_URL . '/' . ltrim($r['photo_path'], '/')) ?>" alt="">
                            <?php else: ?>
                                <div class="ck-recipe-ph" aria-hidden="true"><?= slate_icon('book') ?></div>
                            <?php endif; ?>
                            <div class="ck-recipe-body">
                                <div class="ck-recipe-title"><?= e($r['title']) ?></div>
                                <div class="ck-recipe-sub">
                                    <?= $r['author'] === 'customer'
                                        ? 'You shared this'
                                        : count($r['ingredients']) . ' ingredients' ?>
                                    <?php if ($r['author'] === 'customer' && !empty($r['notes'])): ?>
                                        · Practitioner commented
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Your turn', 'Share a recipe'); ?>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="share_recipe">
                <div class="ck-grid-2">
                    <div class="ck-field">
                        <label class="ck-label" for="ck-recipe-title">Title</label>
                        <input class="ck-input" type="text" id="ck-recipe-title" name="title"
                               required maxlength="200" placeholder="My favourite quick lunch">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="ck-recipe-photo">Photo</label>
                        <input class="ck-input" type="file" id="ck-recipe-photo" name="photo" accept="image/*">
                    </div>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ck-ingredients">Ingredients (one per line)</label>
                    <textarea class="ck-input" id="ck-ingredients" name="ingredients_text" rows="5"></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ck-instructions">How you make it</label>
                    <textarea class="ck-input" id="ck-instructions" name="instructions_html" rows="5"></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ck-recipe-notes">Personal note</label>
                    <textarea class="ck-input" id="ck-recipe-notes" name="notes" rows="2"
                              placeholder="Anything you want me to know about this one?"></textarea>
                </div>
                <div style="text-align:right;margin-top:14px;">
                    <button type="submit" class="ck-btn ck-btn--primary">Share with my practitioner</button>
                </div>
            </form>
        </section>

    </div>
    <?php
}

function coaching_render_recipe_detail(int $cid): void {
    $rid = (int)($_GET['id'] ?? 0);
    $r   = CoachingAPI::getRecipe($rid, $cid);

    if (!$r) {
        ?>
        <div class="ck-bento">
            <section class="ck-tile ck-s12">
                <?php coaching_empty(slate_icon('search'), 'Recipe not found',
                    '<a href="?view=recipes">← Back to recipes</a>'); ?>
            </section>
        </div>
        <?php
        return;
    }
    ?>
    <div style="margin-bottom:14px;">
        <a href="?view=recipes" class="ck-btn ck-btn--ghost ck-btn--sm">← All recipes</a>
    </div>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head(
                $r['author'] === 'customer' ? 'Your submission' : 'From your practitioner',
                (string)$r['title']); ?>
            <?php if (!empty($r['photo_path'])): ?>
                <img class="ck-hero-img" src="<?= e(SLATE_URL . '/' . ltrim($r['photo_path'], '/')) ?>" alt="">
            <?php endif; ?>
            <?php if ($r['author'] === 'customer' && !empty($r['notes'])): ?>
                <div class="ck-alert ck-alert--info" style="margin:14px 0 0;">
                    <span aria-hidden="true"><?= slate_icon('message') ?></span>
                    <span><strong>Practitioner comment:</strong> <?= e($r['notes']) ?></span>
                </div>
            <?php endif; ?>
        </section>

        <section class="ck-tile ck-s4">
            <?php coaching_tile_head('Recipe', 'Ingredients'); ?>
            <?php if (!$r['ingredients']): ?>
                <p class="ck-tile-sub" style="margin-top:0;">None listed.</p>
            <?php else: ?>
                <ul class="ck-prose" style="margin:0;padding-left:18px;">
                    <?php foreach ($r['ingredients'] as $it): ?>
                        <li><?= e($it) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="ck-tile ck-s8">
            <?php coaching_tile_head('Recipe', 'Instructions'); ?>
            <?php if (!empty($r['instructions_html'])): ?>
                <div class="ck-prose">
                    <?= strip_tags($r['instructions_html'], '<ul><ol><li><strong><em><br><p>') ?>
                </div>
            <?php else: ?>
                <p class="ck-tile-sub" style="margin-top:0;">None yet.</p>
            <?php endif; ?>
            <?php if (!empty($r['video_url'])): ?>
                <p style="margin-top:14px;">
                    <a class="ck-btn ck-btn--ghost ck-btn--sm"
                       href="<?= e($r['video_url']) ?>" target="_blank" rel="noopener">▶ Watch the video</a>
                </p>
            <?php endif; ?>
        </section>

    </div>
    <?php
}

function coaching_render_motivation(int $cid, ?array $flash): void {
    $active = CoachingAPI::listChallenges($cid, true);
    $tid = current_tenant_id();
    $completed = Database::rows(
        "SELECT * FROM coaching_challenge
          WHERE tenant_id = ? AND customer_id = ? AND completed_at IS NOT NULL
          ORDER BY completed_at DESC LIMIT 20", [$tid, $cid]);
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <?php if (!$active): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty(slate_icon('dumbbell'), 'No active challenges',
                    'You&rsquo;re all caught up — your practitioner will send new ones as you go.'); ?>
            </section>
        <?php else: foreach ($active as $ch): ?>
            <section class="ck-tile ck-s6">
                <?php coaching_tile_head(
                    $ch['kind'] === 'exercise' ? 'Exercise' : 'Challenge',
                    (string)$ch['title']); ?>

                <div class="ck-tile-sub" style="margin:-8px 0 12px;">
                    From <?= e(I18n::localDate('j M', strtotime($ch['starts_at']))) ?>
                    <?php if (!empty($ch['ends_at'])): ?>
                        to <?= e(I18n::localDate('j M', strtotime($ch['ends_at']))) ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($ch['description_html'])): ?>
                    <div class="ck-prose">
                        <?= strip_tags($ch['description_html'], '<ul><ol><li><strong><em><br><p>') ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($ch['video_url'])): ?>
                    <p style="margin:12px 0 0;">
                        <a class="ck-btn ck-btn--ghost ck-btn--sm"
                           href="<?= e($ch['video_url']) ?>" target="_blank" rel="noopener">▶ Watch the video</a>
                    </p>
                <?php endif; ?>

                <form method="post" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--ck-line);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="complete_challenge">
                    <input type="hidden" name="challenge_id" value="<?= (int)$ch['id'] ?>">
                    <div class="ck-field">
                        <label class="ck-label" for="ck-note-<?= (int)$ch['id'] ?>">How did it go? (optional)</label>
                        <textarea class="ck-input" id="ck-note-<?= (int)$ch['id'] ?>" name="client_note" rows="2"
                                  placeholder="A quick reflection to share with your practitioner"></textarea>
                    </div>
                    <div style="text-align:right;">
                        <button type="submit" class="ck-btn ck-btn--primary ck-btn--sm">✓ Mark done</button>
                    </div>
                </form>
            </section>
        <?php endforeach; endif; ?>

        <?php if ($completed): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_tile_head('History', 'Completed',
                    '<span class="ck-count">' . count($completed) . '</span>'); ?>
                <?php foreach ($completed as $ch): ?>
                    <div class="ck-row" style="align-items:flex-start;">
                        <span class="ck-row-glyph" aria-hidden="true"><?= slate_icon('check') ?></span>
                        <div class="ck-row-body">
                            <div class="ck-row-title">
                                <?= e($ch['title']) ?>
                                <span class="ck-tag"><?= e(I18n::localDate('j M', strtotime($ch['completed_at']))) ?></span>
                            </div>
                            <?php if (!empty($ch['client_note'])): ?>
                                <div class="ck-row-sub" style="font-style:italic;">
                                    “<?= e($ch['client_note']) ?>”
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

    </div>
    <?php
}

function coaching_render_summary(int $cid): void {
    $s = CoachingAPI::getSummary($cid);

    if (!$s || empty($s['data'])) {
        ?>
        <div class="ck-bento">
            <section class="ck-tile ck-s12">
                <?php coaching_empty(slate_icon('file'), 'Not ready yet',
                    'Your summary is generated near the end of your program.'); ?>
            </section>
        </div>
        <?php
        return;
    }

    $d = $s['data'];
    $emotions = CoachingAPI::emotions();
    ?>
    <div class="ck-bento">

        <section class="ck-tile ck-hero ck-s12">
            <div class="ck-hero-eyebrow">Body &amp; Soul Program</div>
            <h1 class="ck-hero-name">Your summary</h1>
            <p class="ck-hero-sub">
                <?= e(I18n::localDate('j F Y', strtotime($d['period']['start']))) ?>
                → <?= e(I18n::localDate('j F Y', strtotime($d['period']['end']))) ?>
            </p>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('The numbers', 'At a glance'); ?>
            <div class="ck-grid-auto">
                <div>
                    <div class="ck-stat-value"><?= (int)$d['period']['days'] ?></div>
                    <div class="ck-stat-label">Days</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$d['metrics']['meals_logged'] ?></div>
                    <div class="ck-stat-label">Meals logged</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$d['metrics']['consistency_pct'] ?><small>%</small></div>
                    <div class="ck-stat-label">Consistency</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= number_format((float)$d['metrics']['avg_hydration_l'], 1) ?><small>L</small></div>
                    <div class="ck-stat-label">Avg hydration</div>
                </div>
                <?php if (!empty($d['metrics']['dominant_emotion'])): ?>
                    <div>
                        <div class="ck-stat-value" style="font-size:20px;">
                            <?= e($emotions[$d['metrics']['dominant_emotion']] ?? $d['metrics']['dominant_emotion']) ?>
                        </div>
                        <div class="ck-stat-label">Dominant emotion</div>
                    </div>
                <?php endif; ?>
                <?php if ((int)$d['metrics']['challenges_done'] > 0): ?>
                    <div>
                        <div class="ck-stat-value"><?= (int)$d['metrics']['challenges_done'] ?></div>
                        <div class="ck-stat-label">Challenges done</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Wins', 'Successes'); ?>
            <ul class="ck-prose" style="margin:0;padding-left:18px;">
                <?php foreach ($d['successes'] as $line): ?>
                    <li><?= e($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Next', 'A recommendation'); ?>
            <div class="ck-prose"><?= e($d['recommendation']) ?></div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Personal', 'A word from your practitioner'); ?>
            <blockquote class="ck-quote">“<?= e($d['message']) ?>”</blockquote>
        </section>

    </div>
    <?php
}
