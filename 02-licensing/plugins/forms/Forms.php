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

class Forms extends Plugin {

    public function boot(): void {
        Hook::addFilter('admin_nav_items',          [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',  [$this, 'addDashboardWidget']);
        Hook::addFilter('public_routes',            [$this, 'addPublicRoutes']);
        Hook::addFilter('i18n_lang_paths',          [$this, 'addLangPath']);

        // MCP AI Gateway integration (expose forms tools to AI assistants)
        require_once __DIR__ . '/FormsMcpHandler.php';
        FormsMcpHandler::register();

        // The form block renders inline now, so its stylesheet and logic script
        // have to reach the HOST page's <head> — the iframe used to carry them.
        // Injected only when the page actually contains a form block, so pages
        // without one pay nothing, and emitted once regardless of how many
        // blocks the page holds.
        Hook::addFilter('content_head_tags',        [$this, 'injectFormAssets'], 10, 2);

        // Content Builder integration: a "Form" block for dropping a form into
        // any page. Plugin boot order isn't guaranteed, so register now if
        // Content Builder has already booted, otherwise catch its hook.
        $this->registerContentBlock();
    }

    /** Register the "Form" block with Content Builder (order-independent). */
    private function registerContentBlock(): void {
        $register = function ($registry) {
            $opts = FormsAPI::pickerOptions();
            $registry::register('form', [
                'label'  => __('forms_block', 'Form'),
                'group'  => 'Forms',
                'fields' => [
                    ['key'=>'formSlug','type'=>'select','label'=>'Form','options'=>$opts],
                    ['key'=>'minHeight','type'=>'text','label'=>'Initial height (px)','placeholder'=>'480'],
                ],
                'render'   => ['FormsAPI', 'renderContentBlock'],
                'defaults' => ['formSlug' => ($opts[0]['v'] ?? ''), 'minHeight' => '480'],
            ]);
        };

        if (class_exists('BlockRegistry')) {
            $register('BlockRegistry');                       // CB already booted
        } else {
            Hook::addAction('content_register_blocks', $register); // CB boots later
        }
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
        return $items;
    }

    /** Stylesheet + logic script for an inline form block, once per page. */
    public function injectFormAssets($headTags, $post = null): string {
        $headTags = (string) $headTags;

        $layout = is_array($post) ? ($post['layout'] ?? []) : [];
        if (!class_exists('FormsAPI') || !FormsAPI::layoutHasBlock($layout, 'form')) {
            return $headTags;
        }

        $v = FormsAPI::ASSET_VERSION;
        return $headTags
            . '<link rel="stylesheet" href="' . e(plugin_url('forms', 'assets/css/public.css')) . '?v=' . e($v) . '">'
            . '<script src="' . e(plugin_url('forms', 'assets/js/forms-logic.js')) . '?v=' . e($v) . '" defer></script>';
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
