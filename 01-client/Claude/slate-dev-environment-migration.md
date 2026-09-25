# Moving development off the production host

**Status:** planned, not started. Written 2 Sep 2026.
**Companion to** `Claude/slate-cleanup-plan.md` — this is the change that stops that plan being needed again.

---

## The problem in one line

Development happens on `premium106.web-hosting.com`, which is shared production hosting. Git worktrees, PHP test suites and coding agents all run there, on a box provisioned to serve PHP pages and nothing else.

Everything that has gone wrong this week traces back to that:

| Symptom | Cause |
|---|---|
| SSH drops mid-run | shared host kills idle connections and long processes |
| Inodes at 93.3% (279,932 / 300,000) | development artefacts on a hosting account — but see Step 4: the composition has not been measured, and the worktrees are only 1.7% of it |
| Production matched no git ref — 63 modified, 37 untracked | the docroot *is* a checkout, so editing live is one `vim` away |
| `multilang-translate` existed only on one server and one laptop | live edits never had to pass through git |
| Three Slate trees, one publicly reachable test copy | testing needed somewhere to live, and the server was there |
| `rsync --delete` would have removed 71 live files | the server holds work git has never seen |

None of these are separate problems. They are one habit with six faces.

## Target state

**The Mac** — full clone, local PHP and MySQL, the app runs on `localhost`. All editing, all tests, all agents.

**The server** — a deploy target. No git checkout in the docroot, no worktrees, no editing. Reached for deploys, logs, and incidents only.

**The path between them** — branch → PR → CI → merge → Deploy workflow. One direction. Nothing moves server→laptop except database dumps and logs.

---

## Prerequisite — do not start before this is true

**Phase 1 of the cleanup plan must be finished: production reproducible from a named git ref.**

**✅ Met — 2 Sep 2026. This gate is open and the migration can start.**

This was not optional sequencing: you cannot develop locally against a codebase that partly
exists only on the server. When this document was drafted that meant the six Phase C/D
content-builder files, a modified `ContentBuilderAPI.php` calling into them, and 37
untracked files nobody had classified. Cloning before that was resolved would have meant
developing against a codebase production did not have.

All of it is now resolved:

- the six Phase C/D content-builder files are on `develop`
- the 37 untracked resolved to **20**, every one `plugins/multilang-translate/`, merged as
  **#48** — the plugin had been running in production while existing on no branch
- of the 63 modified tracked files, 56 were Phase C/D work that has since merged and 6 more
  were live *behind* `develop`; exactly one had live ahead, the hand-applied SEC-1
  `.htaccess` rule, merged as **#49**

`develop` is now a superset of the live tree: everything on the server is in git, and a
`--delete` deploy would remove one file, `.ftpquota`, a cPanel artefact that regenerates.
See the Phase 1 section of `Claude/slate-cleanup-plan.md` for the full accounting.

---

## Step 1 — Local runtime

Match production: PHP 8.3 and 8.4 are both in CI, MySQL, Apache-style rewrites.

**Recommended: Docker Compose.** One `docker-compose.yml` with php-fpm, nginx or apache, and MySQL, checked into the repo. Reproducible, disposable, and the same for any machine you or a future collaborator use. The alternative — Herd, Valet, MAMP, or the XAMPP you already have — is faster to start and drifts from CI within a month.

Two things to get right:

- **PHP version pinned to CI's.** A local 8.2 that passes and an 8.4 that fails in CI wastes more time than the setup saved.
- **LiteSpeed vs Apache.** Production runs LiteSpeed. `.htaccess` is broadly compatible but not identical — rewrite edge cases and `Require` directives can differ. Anything touching `.htaccess` still needs verifying against the live host after deploy, exactly as we did for SEC-1.

## Step 2 — Database, without the client data

`mysqldump` the schema. **Do not copy production rows to a laptop.**

Slate holds medical data: `membership_profiles` (medical conditions, allergies, emergency contacts), `coaching_profile` (pathologies, ongoing care, body measurements, photographs). PRIV-1 already establishes there is no deletion path for any of it. Copying it onto a laptop multiplies the exposure and adds a device you cannot wipe from the admin.

So:

- schema only (`mysqldump --no-data`)
- a seed script producing synthetic tenants, customers, bookings and memberships
- if you need realistic volume, generate it — don't import it

The seed script belongs in the repo. It is also the thing that makes the CI integration suite meaningful for anyone who joins later.

## Step 3 — Local configuration

- `.env.local`, gitignored, with its **own `APP_SECRET`** — never the production one
- Stripe **test** keys only
- Mail driver writes to a file or MailHog; never SMTP
- Cron disabled locally, or pointed at a local queue
- `slate-test` is no longer needed once this exists — that's the whole point of it

## Step 4 — Cut the server over to deploy-target

Once local dev works and Phase 1 is done:

1. Verify `develop` deploys cleanly with `dry_run: true`, read the deletion list, then run a real deploy.
2. **Remove the git checkout from the docroot.** The deployed tree should be files, not a repository — that's what makes editing-in-place stop being possible.
3. Remove `/home/rakilluy/slate-worktrees/*`. Archive first, delete a week later.
4. Remove `~/public_html/slate-test` and `~/public_html/slate` per cleanup Phase 2.
5. Point Claude Code and any other agent at the local clone.

**On inodes — say only what has been measured.** The docroot worktrees under `.claude/`
are **4,813 of 279,932, or 1.7%**. Removing them will not move the ceiling meaningfully.
`~/slate-worktrees/*`, `~/public_html/slate-test` and `~/public_html/slate` are whole
application copies and are plausibly much larger, but **none of them has been measured**.

An earlier draft of this document claimed step 3–4 "alone resolves the 93% problem". That
was never measured and should not be relied on.

**Task, before treating this migration as the remedy for the inode ceiling: establish what
the 279,932 actually consists of.** A per-directory count down to the top ten consumers is
enough. Do it first, because the failure mode otherwise is that the whole migration lands,
the ceiling is still at 93%, and the thing actually filling it has never been looked at.

## Step 5 — The rules that keep it fixed

1. **Nothing runs in production that isn't in git.** A WIP branch is free.
2. **No test copy under a public document root.** Ever.
3. **One app per domain.**
4. **Editing on the server is an incident action, not a workflow.** If you do it — and sometimes you will, at 3am — it gets committed the same day. The `.htaccess` SEC-1 fix is the model: applied live, then carried on a branch so the next deploy doesn't undo it.

## Definition of done

- [ ] `git status` in the deployed tree returns nothing, because there is no git in the deployed tree
- [ ] no worktrees under `/home/rakilluy/`
- [ ] the app runs on your Mac from a fresh clone plus one command
- [ ] a new developer, or a new agent, is productive from `git clone` alone
- [ ] no production personal data exists on any laptop
- [ ] inode usage back under 50%

---

## What this costs

Realistically a day, maybe two spread over a week — most of it in step 1 and the seed script in step 2. The prerequisite (cleanup Phase 1) is separate and probably another half-day.

## What it buys

The SSH drops stop. The inode ceiling stops being a risk. Production stops drifting, because drifting requires editing on the box and there will be nothing to edit. Every finding in the queue becomes a normal code change with a normal review and a normal deploy.

And the next person who joins — or the next agent you point at this — starts from `git clone` instead of from an archaeology exercise.
