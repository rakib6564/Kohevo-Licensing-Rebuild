# Studio audit (read-only) — follow-up cluster

Scope: `plugins/studio/` (12,028 lines PHP — the largest plugin, and the only adopter of the core identity spine).
Tree: `claude/slate-platform-audit-c95b8f` @ c3a90ba.

---

## Inventory

### Tables

Studio has **no `install.sql`**. Its schema lives in core migrations `db/migrations/0005_studio_core.php` through `0010_studio_seasons.php` — the only plugin whose tables are versioned by the core migration runner, and therefore the only one whose migrations have working `down()` rollbacks.

Tables referenced in `StudioAPI.php` and `tools/`: `studio_families`, `studio_family_members`, `studio_contact_roles`, `studio_classes`, `studio_enrollments`, `studio_attendance`, `studio_fees`, `studio_costumes`, `studio_recitals`, `studio_recital_tickets`, `studio_announcements`, `studio_seasons`.

### Identity — the reference implementation

Studio is the **only plugin that uses the canonical spine**. Students, parents and instructors are all `contacts` rows, related through studio-owned join tables rather than copied:

- `StudioAPI.php:344` instructors — `LEFT JOIN contacts c ON c.id = s.instructor_id`
- `StudioAPI.php:361`, `:531`, `:619` roles — `JOIN contacts c ON c.id = r.contact_id`
- `StudioAPI.php:380`, `:528`, `:597`, `:792` families — `LEFT JOIN contacts c ON c.id = f.primary_parent_id`
- `StudioAPI.php:389` members — `JOIN contacts c ON c.id = m.contact_id`
- Creation goes through the repository, not raw inserts: `admin/families.php:52-58` and `admin/classes.php:39` use `ContactRepository::resolveOrCreate()` / `create()`
- `StudioAPI.php:461` documents the discipline explicitly: *"Delete a family + its membership rows. Student/parent contacts are shared identity and kept."*

This is what the other nine plugins should look like, and it is worth citing as the target shape in any consolidation work.

### Auth gating

All 16 admin screens call `Auth::require()` + `Auth::requirePerm()` (`studio.manage_classes`, `studio.manage_families`, `studio.view_reports`). No unprotected admin screen.

Public router (`public/router.php`): a view allowlist at `:47-50`, `Auth::requireCustomer()` on the five login-gated views at `:54`, CSRF on POST at `:60`. Family is derived from the session (`StudioAPI::getFamilyByParent($cid)`, `:65`) and never from the request — ownership is correct.

---

## Findings

### [high] Six studio maintenance scripts are unauthenticated and web-reachable, three of them destructive

- evidence: `plugins/studio/tools/` contains `apply_companyb_policies.php`, `cleanup_companyb.php`, `purge_studio_orphans.php`, `raise_season_fees.php`, `seed_companyb.php`, `send_fee_reminders.php`. **None** contains a `PHP_SAPI`/`php_sapi_name()` CLI guard, a `CRON_SECRET` check, or any `Auth::` call — verified by scanning every one.

  They also bypass the application's bootstrap entirely. Rather than requiring `config.php` (which would start a session and load `Auth`), each hand-rolls credential loading — `plugins/studio/tools/purge_studio_orphans.php:30-38`:
  ```php
  $ROOT = dirname(__DIR__, 3);
  foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      …
      if (str_starts_with($k, 'DB_')) define($k, $v);
  ```
  The destructive path is gated only by a command-line flag — `:41`:
  ```php
  $APPLY = in_array('--apply', $argv, true);
  ```

  Nothing in `.htaccess` protects them. The root config denies `_*.php` scratch scripts (`:33-35`), dotfiles (`:37`), logs and backups (`:52`), and five directories (`:57-61`) — `plugins/*/tools/` is in none of those lists. The rewrite rules explicitly serve real files directly (`:84-85` `RewriteCond %{REQUEST_FILENAME} !-f`), so a real `.php` under `plugins/studio/tools/` is executed rather than routed.

  The `.htaccess` comment at `:29-32` records that this exact class of bug has already been found and fixed once here: *"Developer scratch scripts live at the document root as _name.php. They were reachable over HTTP with no authentication: one dumped form submissions with submitter email addresses, others rewrote menus, changed settings, or wrote to files on disk."* The fix was scoped to the root `_*.php` naming convention and did not generalise to `tools/` directories.

- failure case, unconditional: `GET /slate/plugins/studio/tools/purge_studio_orphans.php` runs with no credential and prints a full orphan report for the tenant — table-by-table row counts and family ids — to an anonymous caller. `send_fee_reminders.php` and `raise_season_fees.php` similarly disclose fee and season data in their dry-run output.

- failure case, conditional on `register_argc_argv`: under a web SAPI with that directive **On**, PHP populates `$argv` from the query string split on `+`. `GET …/purge_studio_orphans.php?--apply` then sets `$APPLY = true` and executes the deletions — `studio_costumes`, `studio_fees`, `studio_attendance`, `studio_enrollments`, `studio_family_members`, `studio_contact_roles` rows are removed for the tenant. `?--apply` on `cleanup_companyb.php` deletes tagged demo data; on `raise_season_fees.php` it mutates fee amounts; `send_fee_reminders.php?--send` dispatches email to every family with an outstanding balance. With the directive **Off**, `$argv` is undefined and `in_array()` raises a TypeError under PHP 8, so the request fatals before reaching the destructive branch — a 500 rather than data loss.

- blast radius: the studio tenant's enrolment, fee, attendance and costume records; unauthenticated disclosure regardless of the directive; unauthenticated destruction and outbound email if `register_argc_argv` is On. I could not read the host's PHP configuration, so I cannot resolve which case applies — `phpinfo()` or `php -i | grep register_argc_argv` settles it, and `admin/diag.php` may already surface it.

- fix: the immediate containment is an `.htaccess` deny for `plugins/*/tools/` — or better, for every path segment named `tools`, `bin` or `scripts` at any depth, matching how the existing `FilesMatch` rules already apply depth-independently. The durable fix is that each script should refuse to run outside the CLI as its first statement, the way core's `config.php:95` and `:121` already test `PHP_SAPI !== 'cli'`; a maintenance script that can be triggered by an HTTP request is a maintenance script with an authentication requirement it does not have. Note also that these scripts parse `.env` themselves rather than booting the app, which is what lets them run with database credentials but no session, no permission check and no audit trail — routing them through the normal bootstrap would give them all three. The same sweep should cover the other `bin/` and `tools/` directories in the tree (`plugins/shop/bin/migrate-images.php`, `bin/`).

- **verified** that the scripts are unauthenticated, unguarded and served directly; **the destructive step is conditional** on `register_argc_argv`, which I could not determine from the repository.

### [medium] The `.htaccess` directory denials never match, because the application is installed under a sub-path

- evidence: `.htaccess:57-61`
  ```apache
  RedirectMatch 403 ^/includes/
  RedirectMatch 403 ^/db/
  RedirectMatch 403 ^/scripts/
  RedirectMatch 403 ^/docs/
  RedirectMatch 403 ^/data/
  ```
  `RedirectMatch` matches against the URL path from the domain root. The application is not at the domain root — `.htaccess:81` sets `RewriteBase /slate/` and `:19-21` point `ErrorDocument` at `/slate/403.php`. A request for these directories therefore has the path `/slate/includes/…`, which `^/includes/` does not match. All five denials are inert.
- failure case: `GET /slate/includes/Mailer.php` is not refused by the rule intended to refuse it. In practice most of the sensitive content in those directories is caught by the *other* rules, which are depth-independent and do work: `.sql` and `.log` are denied by the `FilesMatch` at `:52`, so `db/schema.sql` and `data/slate.log` stay protected. The residue is that `.php` files under `includes/` and `db/migrations/` are executed rather than blocked — mostly harmless because they define classes, but they are reachable, they can emit warnings that disclose absolute paths, and the operator's stated intent to fence off five directories is not in force anywhere.
- blast radius: defence-in-depth only, on this install and any other sub-path install. A root-level install is unaffected, which is presumably why it went unnoticed.
- fix: make the patterns relative to the install location — either prefix them with the base path, or replace `RedirectMatch` with a `<DirectoryMatch>`/`RewriteRule` form that is anchored to the `.htaccess` file's own directory rather than the domain root, so the rules survive a move. Whichever form is chosen, the same fix should cover `plugins/*/tools/` from the finding above.
- **verified**

---

## What I checked and found clean

- **Studio is the reference implementation for identity.** Contacts are created through `ContactRepository`, related by join tables, and explicitly preserved on family deletion (`StudioAPI.php:461`). No shadow person table exists.
- **Its migrations are the only plugin migrations with working rollbacks**, because they live in `db/migrations/` and run through the core runner with `up()`/`down()`.
- **Admin authorization is complete** — 16 of 16 screens gated.
- **The public portal's ownership model is correct** — the family is resolved from the authenticated customer id (`public/router.php:65`), never from a request parameter, so the recital/ticket/fee views cannot be steered to another family's records.
- **The public router allowlists its views** (`:47-50`) rather than including a path fragment, so there is no local-file-inclusion surface.
- **CSRF is verified on the portal's POST actions** (`:60`).

---

## Notes for the reconciler

1. **The tools finding is not studio-specific in kind.** `plugins/shop/bin/migrate-images.php` and the root `bin/` directory deserve the same check. The root `_*.php` scratch files are already denied by `.htaccess:33-35`, so the pattern of "developer script left reachable" has now been found twice in this codebase — once fixed narrowly, once still open.
2. **Studio should be cited as the target shape** in any identity-consolidation work, not as a problem. It demonstrates the spine is usable in a large, real plugin.
3. **The `.htaccess` sub-path bug may have wider consequences** than the five directories: any other rule in the deployment anchored at `^/` has the same defect. Worth a dedicated pass over the server config rather than only this file.
