# Backups

Automated daily backup of the whole database and `uploads/` tree, synced to
Google Drive, with retention pruning and a manual "run now" button.
Super Admin only — Admin → Backups.

## Setup

1. In [Google Cloud Console](https://console.cloud.google.com), create (or
   select) a project and enable the **Google Drive API**.
2. Configure the OAuth consent screen (Testing is fine for single-user use;
   add yourself as a test user). Only the narrow `drive.file` scope is ever
   requested — this app can only see files it creates, never your whole Drive.
3. Create an OAuth **Client ID** (type: Web application) with this exact
   Authorized redirect URI:
   `<your site>/plugins/backups/admin/gdrive-callback.php`
4. Paste the Client ID + Client Secret into Admin → Backups, save, click
   **Connect Google Drive**, and approve the consent screen.
5. Turn on "Run automatically once a day" and set how many backups to keep.

## How it works

See `BackupRunner.php` — a `backups_runs` row advances through
`dumping → zipping → uploading → pruning → done` one bounded chunk per
`frequent_cron` tick (the site's existing ~5-minute cron), so a backup of any
size never needs one long-running request. The DB dump is pure PHP (no
`mysqldump` — not guaranteed on shared hosting); the archive is a plain
`ZipArchive` containing `dump.sql` plus the full `uploads/` tree.

Local staging (`db_backups/run-<id>/`) is deleted the moment the Drive
upload succeeds, or on failure — a live DB dump is never left sitting on disk
longer than one backup's processing time.

## Restoring / migrating to a new host

This plugin deliberately has **no one-click restore button** — restoring
overwrites a live database and should never be a single accidental click away.
See `docs/13-Operations/backups.md` for the full runbook; the short version:
download the latest zip from your Drive "Slate Backups" folder, extract it,
point a fresh install's `.env` at the target database, then run

```bash
php bin/restore-backup.php /path/to/extracted/dump.sql --confirm
```

and copy the extracted `uploads/` folder into place.
