<?php
/**
 * Membership — members list.
 *
 * Members ARE core customers; this view joins the membership profile + their
 * current subscription so staff can see status at a glance and drill in.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MembershipAPI.php';

Auth::require();
if (!Auth::can('membership.view') && !Auth::isSuperAdmin()) {
    Auth::requirePerm('membership.view');
}
ModuleGuard::require('membership');
MembershipAPI::ensureSchema();

$pageTitle  = 'Members';
$currentNav = 'membership-members';
$tid = current_tenant_id();

$q = trim((string)($_GET['q'] ?? ''));

$params = [$tid];
$where  = "c.tenant_id = ?";
if ($q !== '') {
    $where .= " AND (c.name LIKE ? OR c.email LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

// Each customer + their newest active subscription (if any).
$members = Database::rows(
    "SELECT c.id, c.name, c.email, c.created_at,
            mp.onboarding_complete, mp.avatar_path,
            s.id AS sub_id, s.status AS sub_status, s.expires_at,
            pl.name AS plan_name, pl.name_fr AS plan_name_fr, pl.plan_type
       FROM customers c
       LEFT JOIN membership_profiles mp ON mp.customer_id = c.id AND mp.tenant_id = c.tenant_id
       LEFT JOIN membership_subscriptions s ON s.id = (
            SELECT s2.id FROM membership_subscriptions s2
             WHERE s2.customer_id = c.id AND s2.tenant_id = c.tenant_id
               AND s2.status = 'active'
               AND (s2.expires_at IS NULL OR COALESCE(s2.grace_until, s2.expires_at) >= NOW())
          ORDER BY s2.expires_at DESC, s2.id DESC LIMIT 1)
       LEFT JOIN membership_plans pl ON pl.id = s.plan_id
      WHERE {$where}
   ORDER BY c.id DESC
      LIMIT 200",
    $params
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('membership', 'Membership'), 'href' => plugin_url('membership', 'admin/index.php')],
    ['label' => __('membership_members', 'Members')],
]); ?>

<div class="page-header">
    <div><h1><?= __('membership_members', 'Members') ?></h1></div>
    <div class="flex gap-2">
        <form method="get" class="flex gap-2">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('membership_search', 'Search name or email')) ?>" style="min-width:240px;">
            <button class="btn"><?= __('membership_search_btn', 'Search') ?></button>
        </form>
        <?php if (Auth::can('membership.manage_members') || Auth::isSuperAdmin()): ?>
            <a href="add-member.php" class="btn btn-primary"><?= __('membership_add_member', 'Add member') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$members): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= __('membership_no_members', 'No members found') ?></div></div></div>
<?php else: ?>
<div class="data-list" data-single-open>
    <?php foreach ($members as $m):
        $hasActive = !empty($m['sub_id']);
        $planLabel = $hasActive ? MembershipAPI::planName(['name'=>$m['plan_name']??'','name_fr'=>$m['plan_name_fr']??'']) : __('membership_none', 'None');
        $badge = $hasActive ? [__('membership_active', 'Active'), 'active'] : [__('membership_no_membership', 'No membership'), 'inactive'];
        $actions = '<a href="member.php?id=' . (int)$m['id'] . '" class="btn btn-sm">' . __('membership_view', 'View') . '</a>';
        slate_data_row([
            'avatar_html'  => slate_avatar_overlay_html(
                                    mb_substr((string)($m['name'] ?: $m['email']), 0, 1),
                                    (string)($m['email'] ?? ''),
                                    $m['avatar_path'] ?? null
                                ),
            'avatar_color' => $hasActive ? 'success' : 'muted',
            'title'        => $m['name'] ?: $m['email'],
            'meta'         => e((string)$m['email']) . ' · ' . $planLabel,
            'badge'        => $badge,
            'detail'       => [
                __('membership_plan', 'Plan')    => $planLabel,
                __('membership_expires', 'Expires') => !empty($m['expires_at']) ? slate_format_date($m['expires_at']) : '—',
                __('membership_profile', 'Profile') => !empty($m['onboarding_complete']) ? __('membership_complete', 'Complete') : __('membership_incomplete', 'Incomplete'),
                __('membership_joined', 'Joined')   => !empty($m['created_at']) ? slate_format_date($m['created_at']) : '—',
            ],
            'actions'      => $actions,
        ]);
    endforeach; ?>
</div>
<?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
