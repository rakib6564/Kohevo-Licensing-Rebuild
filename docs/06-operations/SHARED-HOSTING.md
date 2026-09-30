# Deploying on shared / cPanel hosting

Kohevo is plain PHP with no build step, so it runs on ordinary shared hosting. These notes cover what differs from a
VPS; the generic steps are in [`production-artifacts/`](../../production-artifacts/) and each app's `INSTALL.md`.

## Layout

Run the two apps on **separate subdomains, document roots and databases**:

```
~/central/          → licensing.example.com   (02-licensing package, its own database)
~/customer-app/     → app.example.com         (01-client package,   its own database)
```

In cPanel, point each domain's **Document Root** at the app folder (*Domains → Manage*), not at `public_html`.
Upload/extract the package there. Set up `.env` (mode `600`) from `.env.example` — packages ship none.

## PHP

- Select PHP **8.1 or newer** and enable `pdo_mysql`, `mbstring`, `curl`, `json`, `openssl`
  (*Select PHP Version / MultiPHP*). `sodium` is preferred; without it licensing falls back to a pure-PHP
  implementation, so it is not a blocker.
- Kohevo Studio's HTML/CSS import additionally needs `dom` and `libxml` (usually enabled by default). They are
  optional: without them only that import is refused (`html_import_unavailable`).
- On CloudLinux hosts the SSH shell may run inside CageFS with a different PHP than the website. Change extensions in
  the control panel, not with `selectorctl` from the shell, and check the CLI (`php -v`, `php -m`) separately from
  the web PHP when a CLI script behaves differently from the site.

## Database

Create the database and user in cPanel (*MySQL Databases*), grant all privileges, then either run the installer at
`/install.php` or `php bin/migrate migrate` over SSH. Avoid passwords with characters that need shell quoting when you
also use them in scripts.

## Cron

Add through *Cron Jobs* (binary paths differ per host). Full details: [`production-artifacts/CRON-SETUP.md`](../../production-artifacts/CRON-SETUP.md).

```
# Client — optional: license check-in (CLI script). The app also refreshes its license by itself, see below
0 2 * * *   /usr/local/bin/php ~/customer-app/bin/license-check.php >> ~/customer-app/data/cron-license.log 2>&1

# Client and central — web cron: reminders, Drive backups, daily expiry sweep (HTTP endpoint, secret in a header)
*/5 * * * * curl -fsS -H 'X-Cron-Key: <CRON_SECRET from .env>' 'https://app.example.com/cron.php' > /dev/null 2>&1
```

`cron.php` is an **HTTP** endpoint protected by `CRON_SECRET` (send it as the `X-Cron-Key` header so it stays out of
access logs; `?key=` works if your scheduler can not set headers). It is rate-limited.

**License changes reach the client on their own.** The Central Server is the source of truth; the client keeps a signed
copy. A client refreshes that copy automatically: on a normal page load, once the copy is older than
15 minutes (`LICENSE_SYNC_INTERVAL`, seconds, 300–86400), the check-in runs *after* the response is sent, so nobody waits
for it. A renewed or extended expiry, a plan or module change made on central therefore shows up within about 15 minutes
of the next visit — no cron needed. **License → Check for updates now** does it immediately. The cron lines above just
make it happen on schedule even when nobody visits. If the license server is unreachable the client keeps its last
verified license for the 7-day offline tolerance, then follows the expiry rules.

## Mail

Use the host's mailbox over authenticated SMTP (*Settings → SMTP*). Sending through `mail()` on shared hosts is
routinely flagged as spam. The SMTP password is stored encrypted with `APP_SECRET`.

## `.htaccess`

The shipped `.htaccess` handles routing and answers 403 for `.env`/`.installed`, `data/`, `db_backups/`, `includes/`,
`src/`, `bin/`, `db/`, `tests/`, `audit/` and `docs/`; `vendor/` is protected by its own `.htaccess`. Keep it as shipped. Do **not** add ModSecurity directives such as `SecRuleEngine` — hosts that
manage ModSecurity centrally answer HTTP 500 to them, and a bad `.htaccess` (for example in a WordPress install)
can take the whole site down with an HTTP 500.

## Uploading without junk files

Building or uploading from macOS with `tar`/Finder can add `._*` (AppleDouble) files. Use
[`scripts/build-release.sh`](../../scripts/build-release.sh) (clean, from git), or `COPYFILE_DISABLE=1 tar …`, and
check afterwards:

```bash
find ~/customer-app -name '._*' -type f      # should print nothing
```

## After the first deploy

1. Create a **real** admin (not the example address from documentation) and remove any placeholder account.
2. Set SMTP, default language, and — for a site that embeds the widget — the
   [embed allow-list](../05-guides/EMBEDDING.md).
3. Truncate or delete `data/slate.log` if the package came from a development machine.
4. Rotate any credential that was pasted into chat, tickets or logs during setup.
