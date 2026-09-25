# Slate — per-client onboarding

How to take Slate from "the base I keep on my laptop" to "a live
client install in production." Repeatable workflow — same shape
every time, no surprises.

---

## 0. Before the kickoff call

- Confirm the host. Shared PHP host (Namecheap, Hostinger, cPanel)
  works fine. Need PHP 8.1+, MySQL/MariaDB 5.7+, mod_rewrite.
- Decide which plugins the client needs:

  | Need | Plugin |
  |---|---|
  | Public contact / quote / intake forms | `forms` |
  | Appointment booking | `booking` |
  | Online shop | `shop` (+ `stripe-payment` for cards) |
  | Image library across plugins | `media-library` |
  | Custom shipping rules | `flat-rate-shipping` |
  | Customer-facing emails for orders | `shop-emails` |

  Everything else is in Phase 6 of the roadmap — build it when a
  paying client asks.

---

## 1. Copy the base into a client folder

```bash
cp -R slate-base/ clients/<client-slug>/
cd clients/<client-slug>/
```

`<client-slug>` is whatever you like; "acme-co", "greenlight", etc.
Treat the folder as the source of truth for that one client.

The packaging tools live in `bin/`:

- `bin/package-plugin.php <slug>` — zip one plugin for distribution
- `bin/seed-demo.php` — populate active plugins with sample data
- `bin/clean-demo.php` — clear the sample data back out

---

## 2. Create the database

On the host:

1. Create a database + user, give the user all privs on that db only.
2. Note the host, name, user, password.

---

## 3. Wire .env

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

Edit:

```
APP_URL=https://client-domain.com/slate
APP_SECRET=<run "openssl rand -hex 32" and paste>
CRON_SECRET=<run "openssl rand -hex 16" and paste>
TENANT_ID=1
DB_HOST=localhost
DB_NAME=clientname_slate
DB_USER=clientname_slate
DB_PASS=...
```

Never commit `.env`. Keep a separate `.env.production` template if
you want.

---

## 4. Upload + install

SFTP or `rsync` the entire client folder to the web root (or a
subdirectory).

Visit `https://client-domain.com/slate/install.php` and walk the
two-step wizard. It creates the admin user, writes `.installed`,
and (re)runs the schema.

If you're upgrading an existing install, skip the wizard and just
upload the new files over the old ones — schema migrations run
lazily.

---

## 5. Activate the plugins they need

1. Sign in as the admin you just created.
2. Plugins → Upload ZIP → activate each one.
3. For Shop: also activate `stripe-payment` if they take cards.
4. For Booking: also wire the cron (see §7 below).

---

## 6. White-label

Settings → Branding:

- Site name (replaces "Slate" everywhere customer-facing)
- Brand sublabel (default "Pro Admin" — change to "Acme Bookings"
  etc., shown under the site name in the sidebar logo block)
- Accent colour (logo block + buttons)
- Logo upload (replaces the letter mark)
- Email-from name + reply-to + SMTP credentials

Settings → Business:

- Business legal name, address, hours — surfaced in footers + emails.

Test the email config with the "Send test email" button. If it
fails, check `data/slate.log`.

---

## 7. Cron (only if Booking is active)

On a Namecheap-style cPanel host, add a cron under "Cron Jobs":

```cron
*/5 * * * * curl -fsS 'https://client-domain.com/slate/cron.php?key=YOUR_CRON_SECRET' > /dev/null
```

If they don't have cron, an external trigger works too —
GitHub Actions on a schedule, an Uptime Robot ping. Anything that
hits the URL every ~5 minutes.

You can test it manually:

```bash
curl -fsS 'https://client-domain.com/slate/cron.php?key=YOUR_CRON_SECRET'
```

You should get JSON back listing which actions fired.

---

## 8. Quick smoke test

- [ ] `/admin/` loads, dark sidebar renders
- [ ] You can sign in
- [ ] Each active plugin appears in the sidebar under its group
- [ ] `/customer/register` works, a real verification email lands
- [ ] If Forms is active: create a published form, submit it from
      `/forms/<slug>`, confirm the admin notification arrives and
      the submission shows up in the inbox
- [ ] If Booking is active: create a service + provider with hours,
      walk through `/book`, confirm the appointment lands and the
      confirmation email goes out
- [ ] `https://client-domain.com/slate/cron.php?key=XXX` returns 200
- [ ] `/admin/audit.php` shows the actions you just took

If any of those fail, check `data/slate.log` first.

---

## 9. Hand-off

Give the client:

- The admin URL + their login
- A one-page "how to use the dashboard" doc (per-client, edit a
  template — there isn't one in core yet, write your own)
- Your support contact + SLA

Keep your local `clients/<client-slug>/` copy in version control
(git, even a local bare repo) so you can roll back if a customisation
goes sideways.

---

## What to NOT do

- **Don't share a database across clients.** Slate has tenant_id
  plumbing but it's not active by default — give each client their
  own DB and their own copy.
- **Don't customise core files per client.** If a client needs
  custom behaviour, build it as a plugin. Diffs against the core
  base become your maintenance burden forever.
- **Don't deploy without backing up first.** cPanel → Backups,
  or a shell `mysqldump + tar`. Do it before every deploy.

---

## Updating an existing client install

The plugins ship runtime schema-guards (`ensureColumn` / `ensureIndex`
style) so most upgrades are "upload the new files, done." For larger
schema changes you'll see a `migrations/<version>.sql` file inside
the plugin folder — those need to run manually via the host's
phpMyAdmin or `mysql` CLI.

When in doubt:

1. Back up the database
2. Back up the filesystem
3. Upload the new files
4. Hit any admin page — runtime guards will create missing
   tables/columns
5. Smoke test the things that changed

If something breaks, you have a clean rollback target.
