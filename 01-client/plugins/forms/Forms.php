<?php
/**
 * Forms plugin — bootstrap.
 *
 * Registers:
 *   - Admin nav items (forms list + submissions inbox)
 *   - Dashboard widget (submissions in last 7 days)
 *   - Public route prefix `/forms` → public/router.php
 *
 * Heavy lifting (validation, field rendering, webhook dispatch, etc.)
 * lives in FormsAPI.
 */

require_once __DIR__ . '/FormsAPI.php';
require_once __DIR__ . '/lib/FormsIntegrations.php';

class Forms extends Plugin {

    public function boot(): void {
        Hook::addFilter('admin_nav_items',          [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',  [$this, 'addDashboardWidget']);
        Hook::addFilter('public_routes',            [$this, 'addPublicRoutes']);
        Hook::addFilter('i18n_lang_paths',          [$this, 'addLangPath']);

        // MCP AI Gateway integration (expose forms tools to AI assistants)
        require_once __DIR__ . '/FormsMcpHandler.php';
        FormsMcpHandler::register();

        FormsIntegrations::register();

        // Studio widgets (Add › Components) are declared in studio-widgets.php.
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('forms.view') && !Auth::isSuperAdmin()) return $items;

        $items[] = [
            'slug'  => 'forms',
            'label' => __('forms', 'Forms'),
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'clipboard-list',
            'perm'  => 'forms.view',
            'order' => 230,
            'group' => 'form-builder',
        ];
        $items[] = [
            'slug'  => 'forms-submissions',
            'label' => __('forms_submissions', 'Submissions'),
            'href'  => $this->url('admin/submissions.php'),
            'icon'  => 'mail',
            'perm'  => 'forms.view',
            'order' => 231,
            'group' => 'form-builder',
        ];
        $items[] = [
            'slug'  => 'forms-contacts',
            'label' => __('forms_contacts', 'Contacts'),
            'href'  => $this->url('admin/contacts.php'),
            'icon'  => 'users',
            'perm'  => 'forms.view',
            'order' => 232,
            'group' => 'form-builder',
        ];
        $items[] = [
            'slug'  => 'forms-spam-log',
            'label' => __('forms_spam_log', 'Spam log'),
            'href'  => $this->url('admin/spam_log.php'),
            'icon'  => 'shield',
            'perm'  => 'forms.view',
            'order' => 233,
            'group' => 'form-builder',
        ];
        if (Auth::can('forms.manage') || Auth::isSuperAdmin()) {
            $items[] = [
                'slug'  => 'forms-integrations',
                'label' => __('forms_integrations', 'Integrations'),
                'href'  => $this->url('admin/integrations.php'),
                'icon'  => 'link',
                'perm'  => 'forms.manage',
                'order' => 234,
                'group' => 'form-builder',
            ];
        }
        return $items;
    }

    public function addPublicRoutes(array $routes): array {
        $routes['forms'] = [
            'handler' => $this->dir('public/router.php'),
            'methods' => ['GET', 'POST'],
        ];
        return $routes;
    }

    /** Registers plugins/forms/lang/<locale>.php with core I18n. */
    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('forms.view')) return $widgets;

        FormsAPI::ensureSchema();
        $tid = current_tenant_id();
        try {
            $recent = (int) Database::value(
                "SELECT COUNT(*) FROM forms_submissions
                  WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY)",
                [$tid]
            );
            $unread = (int) Database::value(
                "SELECT COUNT(*) FROM forms_submissions
                  WHERE tenant_id = ? AND read_at IS NULL",
                [$tid]
            );
            $formCount = (int) Database::value(
                "SELECT COUNT(*) FROM forms_definitions
                  WHERE tenant_id = ? AND status = 'published'",
                [$tid]
            );
            $latest = Database::rows(
                "SELECT s.ref, s.submitter_email, s.data_json, s.read_at, s.created_at, f.title AS form_title
                   FROM forms_submissions s
                   LEFT JOIN forms_definitions f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
                  WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $inboxUrl = $this->url('admin/submissions.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('forms', 'Forms') ?></h2>
                <a href="<?= e($inboxUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('forms_subs_7d', 'Submissions · 7d') ?></div>
                    <div class="dwidget-kpi-v"><?= (int)$recent ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('forms_unread', 'Unread') ?></div>
                    <div class="dwidget-kpi-v"><?= (int)$unread ?></div>
                </div>
            </div>
            <?php if ($latest): ?>
                <div class="dlist">
                    <?php foreach ($latest as $s):
                        $data  = json_decode($s['data_json'] ?? '{}', true) ?: [];
                        $email = trim((string)($s['submitter_email'] ?? ''));
                        $name  = FormsAPI::deriveContactName($data, $email);
                        if ($name === '') $name = $email !== '' ? $email : __('forms_anon', 'Anonymous');
                        $grav  = FormsAPI::gravatarUrl($email, 36);
                        $avaHtml = e(FormsAPI::initials($name, $email))
                                 . ($grav !== '' ? '<img src="' . e($grav) . '" alt="" loading="lazy" onerror="this.remove()">' : '');
                        $sub = $email !== '' ? $email : trim((string)($s['form_title'] ?? __('forms_form', 'Form')));
                        $isUnread = empty($s['read_at']);
                        slate_dlist_row([
                            'avatar_html'  => $avaHtml,
                            'avatar_color' => $isUnread ? 'info' : 'muted',
                            'title'        => $name,
                            'sub'          => $sub,
                            'amount'       => $isUnread ? __('forms_new', 'New') : '',
                            'time'         => $s['created_at'] ? I18n::localDate('M j', strtotime($s['created_at'])) : '',
                            'href'         => $inboxUrl,
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('forms_no_subs', 'No submissions yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }
}
