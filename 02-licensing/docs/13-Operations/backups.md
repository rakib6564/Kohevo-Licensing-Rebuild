# Backups — setup & restore runbook

**Status:** Draft · Companion to [deployment.md](deployment.md) §6 ("Backups &
recovery"). Implemented by `plugins/backups/`.

---

## What it does

A daily, automatic backup of the whole shared database plus the `uploads/`
tree, synced to a Google Drive folder ("Slate Backups") the site owner
controls, with retention pruning (keep the last N). A manual "Run backup now"
button is available from Admin → Backups (Super Admin only).

This is in addition to, not a replacement for, whatever backups your hosting
provider already runs — an independent copy in your own Google Drive protects
against host-side failure, account issues, or accidental deletion at the host.

## Setup (one-time)

1. In [Google Cloud Console](https://console.cloud.google.com), create or
   select a project, then enable the **Google Drive API**
   (APIs & Services → Library).
2. **OAuth consent screen**: choose External or Internal (either works for
   single-user use), fill in the required app info, and add your own Google
   account under "Test users". The app only ever requests the narrow
   `drive.file` scope — it can see/manage only files it creates, never your
   whole Drive.
3. **Credentials → Create Credentials → OAuth client ID**, type **Web
   application**. Add this Authorized redirect URI exactly:
   `<your site>/plugins/backups/admin/gdrive-callback.php`
4. Copy the Client ID and Client Secret into Admin → Backups, save, then click
   **Connect Google Drive** and approve the consent screen — that click has
   to be done by whoever owns the Google account, in their own browser.
5. Turn on "Run automatically once a day" and set how many backups to keep.

**Watch for**: a Google Cloud OAuth app left in "Testing" publishing status
can require re-authorizing after a while. If scheduled backups silently stop
appearing in Drive, check the connection status on the Backups settings page
first — reconnecting takes under a minute.

No cron setup is needed beyond what's already running — the existing
`cron.php` pinger (documented in [deployment.md](deployment.md) §5) already
covers scheduling; this plugin just listens on the `daily_cron` and
`frequent_cron` hooks it already fires.

## How a backup runs

See `plugins/backups/BackupRunner.php`. Because shared hosting has no
`mysqldump`/shell access and Slate has no background-worker/queue system, a
backup is a small state machine (`backups_runs.status`) advanced by one
bounded chunk of work per cron tick (~every 5 minutes):

`pending → dumping → zipping → uploading → pruning → done` (or `failed`)

A pure-PHP dump (via `INFORMATION_SCHEMA` + `SHOW CREATE TABLE` + batched
`SELECT`s) and the `uploads/` tree are zipped together into one archive,
uploaded to Drive via a resumable upload (so an interrupted connection
resumes instead of restarting), and pruned against the retention count. Local
staging files are deleted the moment the upload succeeds, or on failure — a
live database dump full of customer data is never left on disk longer than
necessary.

## Restoring — deliberately not a website button

Restoring overwrites the target database. This plugin has **no web-based
restore/import** — that action is reachable only from the command line, with
an explicit `--confirm` flag and a typed confirmation, so it can never be
triggered by an accidental click in the admin UI.

### Restoring in place (disaster recovery on the same install)

1. In your "Slate Backups" Google Drive folder, download the backup zip you
   want (each admin-panel history row also links straight to its file).
2. Extract it — you'll get `dump.sql` and an `uploads/` folder.
3. `php bin/restore-backup.php /path/to/dump.sql` first, with **no**
   `--confirm` — this only prints what it would do (target DB, size,
   statement count) and changes nothing. Check that it's pointed at the
   database you expect.
4. `php bin/restore-backup.php /path/to/dump.sql --confirm`, then type the
   database name when prompted.
5. Copy the extracted `uploads/` folder over the site's existing one
   (back up the existing `uploads/` first if you want to keep it).

### Migrating to a new host

1. Same download + extract as above.
2. Stand up Slate on the new host (upload the codebase, run `/install` to get
   a fresh empty database and `.env` pointed at it — or hand-write a `.env`
   if you're scripting this).
3. `php bin/restore-backup.php /path/to/dump.sql --confirm` against the new
   host's database.
4. Copy `uploads/` into place on the new host.
5. Update DNS/`SLATE_URL` as needed. Re-run through the Google Drive OAuth
   connect step on the new host if you want scheduled backups to continue
   from there — credentials don't travel with the DB dump for security
   reasons (`APP_SECRET`, which they're encrypted against, is host-specific).

**Restores are tested; a backup you haven't restored is a hope, not a
backup** (echoing [deployment.md](deployment.md) §6) — periodically practice
this against a throwaway database, not just when you actually need it.

---

## Related

- [deployment.md](deployment.md) · [shared-hosting-compatibility.md](shared-hosting-compatibility.md)
- `plugins/backups/README.md`
