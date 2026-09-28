<?php
/**
 * Backups — bootstrap.
 *
 * Automated daily database + uploads backup, synced to Google Drive, with
 * retention pruning. Superadmin-only — this touches the entire shared
 * database (every tenant's data) and holds OAuth credentials, so it isn't a
 * delegable per-tenant permission like most plugins.
 *
 * See BackupRunner.php for the actual dump/zip/upload state machine and
 * GoogleDriveClient.php for the Drive REST client.
 */

require_once __DIR__ . '/GoogleDriveClient.php';
require_once __DIR__ . '/BackupRunner.php';

class Backups extends Plugin {

    public function boot(): void {
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);
        Hook::addAction('daily_cron',      [BackupRunner::class, 'runDailyCron']);
        Hook::addAction('frequent_cron',   [BackupRunner::class, 'runFrequentCron']);
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    public function addAdminNav(array $items): array {
        if (!Auth::isSuperAdmin()) return $items;

        $items[] = [
            'slug'  => 'backups-settings',
            'label' => __('backups_settings', 'Backups'),
            'href'  => $this->url('admin/settings.php'),
            'icon'  => 'shield',
            'perm'  => 'backups.manage',
            'order' => 910,
            'group' => 'system',
        ];
        return $items;
    }
}
