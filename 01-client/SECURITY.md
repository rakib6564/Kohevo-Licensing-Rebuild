# Security

## Reporting a vulnerability

Do not open a public issue. Report privately through GitHub's
**Security → Report a vulnerability** on this repository.

## Configuration and secrets

Slate reads all credentials from the environment. `config.php` contains **no**
real values — only harmless fallbacks — and `.env` is gitignored and must never
be committed.

| Variable | Purpose |
| --- | --- |
| `APP_URL` | Public base URL, no trailing slash |
| `APP_SECRET` | HMAC signatures and at-rest encryption of stored secrets |
| `CRON_SECRET` | Protects `cron.php` from unauthenticated hits |
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | Database connection |

On shared hosting, prefer real environment variables set in the hosting panel
over an on-disk `.env`. If you must use `.env`, keep it at mode `600`.

CI enforces this: the `secrets` job fails the build if `.env` becomes tracked,
if `config.php` regains a non-empty `DB_PASS` fallback, or if a live-looking
provider key appears in the tree.

## Known issue — `APP_SECRET` is empty on the original install

The first production install ran with `APP_SECRET` and `CRON_SECRET` unset, so
they resolved to an empty string. Anything already encrypted at rest (for
example Stripe keys stored through the `stripe-payment` plugin) was encrypted
with that empty key.

**Setting a real `APP_SECRET` will make those existing values undecryptable.**
This is a deliberate outstanding item, not an oversight.

### Rotating `APP_SECRET` safely

1. Take a database backup: `mysqldump … | gzip > backup.sql.gz`.
2. Note every secret currently stored through the admin UI — Stripe keys, SMTP
   passwords, any OAuth credentials.
3. Generate a new value: `openssl rand -hex 32`.
4. Put it in `.env` as `APP_SECRET=…`.
5. Re-enter each secret from step 2 through the admin UI so it is re-encrypted
   under the new key.
6. Do the same for `CRON_SECRET`, then update whatever calls `cron.php`.

Until this is done, treat at-rest encryption as providing no protection.

## Supported versions

Only `main` is supported. There are no backported security fixes.
