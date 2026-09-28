# Releasing

## Versioning

- Semantic Versioning. The number lives in [`VERSION`](../../VERSION) and must equal `SLATE_VERSION` in
  `01-client/config.php`, `01-client/install.php`, `02-licensing/config.php` and `02-licensing/install.php`
  (CI fails otherwise). The client reports it to the central server on every license check-in.
- Packages carry `MAJOR.MINOR` in the file name: `KOHEVO-CLIENT-V1.6-DEPLOYMENT-READY.zip`.
- Every release gets an entry in [`CHANGELOG.md`](../../CHANGELOG.md) and a git tag `vMAJOR.MINOR.PATCH`.

## Cut a release

1. Make sure `main` is green in CI.
2. On a branch: bump `VERSION` and the four `SLATE_VERSION` constants, update `CHANGELOG.md`, merge to `main`.
3. Build the packages from the merged commit:

   ```bash
   scripts/build-release.sh                       # from main; output in the repo root
   scripts/build-release.sh --ref v1.6.0 --out /tmp/release
   PHPMAILER_SRC=/path/to/phpmailer/src scripts/build-release.sh   # use a specific PHPMailer copy
   ```

   The script builds from `git archive` of the ref — never the working tree — so ignored or uncommitted local files
   can not leak in. It refuses to build when `SLATE_VERSION` disagrees with `VERSION`.
4. Verify (below), deploy, then tag:

   ```bash
   git tag -a v1.6.0 -m "Kohevo 1.6.0" && git push origin v1.6.0
   ```

Zips are git-ignored on purpose; attach them to a GitHub Release rather than committing them.

## What a package contains

- The app tree from `git archive` **minus** `tests/`, `Claude/`, dev-only docs, `dev-server.php`, `.gitkeep` files,
  `.env` and the dev log.
- **PHPMailer** in `vendor/phpmailer/phpmailer/src` (the hosts we target have no Composer) and a deny-all
  `vendor/.htaccess`.
- `BUILD-INFO.txt` — version, source commit and build time.
- **No `.env`.** Copy `.env.example` to `.env` on the server and set production values.

## Verify before deploying

```bash
unzip -tq KOHEVO-CLIENT-V1.6-DEPLOYMENT-READY.zip               # integrity
unzip -q  KOHEVO-CLIENT-V1.6-DEPLOYMENT-READY.zip -d /tmp/pkg
find /tmp/pkg -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax'   # lint (prints nothing when clean)
unzip -l KOHEVO-CLIENT-V1.6-DEPLOYMENT-READY.zip | grep -E '\.env$|/tests/|\._'    # must print nothing
```

macOS note: archive from a clean checkout or with `COPYFILE_DISABLE=1` — otherwise Finder/tar adds `._*`
AppleDouble files that then land on the server.

## Deploy

Follow [SHARED-HOSTING.md](SHARED-HOSTING.md) for cPanel-style hosts, or [`production-artifacts/`](../../production-artifacts/)
for a VPS (Apache / Nginx / cron / env). Order: **central first**, then clients.

After uploading, run `php bin/migrate migrate` in each app, then smoke-check: the login page, the public booking
page, and one license check-in (`php bin/license-check.php` on a client).

### Settings that live in the database, not the code

A code deploy does **not** carry these; set them on every new install and re-check after a database restore:

| Setting | Where |
|---|---|
| SMTP host/credentials | Settings → SMTP (password is encrypted) |
| Default language (`default_language`) | Settings → General |
| Booking embed allow-list (`embed_allowed_origins`) | Booking → Settings |
| Site name, logo, accent colour | Settings → General / Branding |

## Rollback

Keep the previous package and a database backup (`bin/backup-db.sh`, or the Backups plugin). To roll back, restore
the previous files; run `php bin/migrate rollback` only on dev/staging — production rollbacks restore the database
backup instead.
