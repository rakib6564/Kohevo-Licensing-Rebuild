# Slate — server cleanup and consolidation plan

**Written 1 Sep 2026, from the live host and cPanel.** Companion to `claude/slate-issue-queue.md`.

---

## The one rule

**Nothing gets deleted until production is reproducible from git.**

Right now it isn't. The live tree reports HEAD `f5d712f` but carries **63 modified tracked files and 37 untracked**, and matches no ref — not `f5d712f`, not `develop`, not the audit branch. `plugins/multilang-translate/` (20 files, actively maintained) exists on **no branch at all**.

That means today the server *is* the only complete copy of some of your work. Every cleanup step below is ordered so that stops being true before anything is removed.

---

## What's actually on the host

| Path | Served at | Status |
|---|---|---|
| `~/greenlightinduction.rakibhasaan.com/slate` | `greenlightinduction.rakibhasaan.com/slate` | **The live Solaya install.** Keep. |
| `~/public_html/slate-test` | `rakibhasaan.com/slate-test/` | Full second copy. Public. Same exposed `studio/tools`, `shop/bin`. No tools rule in its `.htaccess`. |
| `~/public_html/slate` | `rakibhasaan.com/slate/` | Public. **No `.htaccess` at all.** No tools dirs. Purpose unknown. |
| `~/www/slate` | same as above | `www` is the conventional symlink to `public_html` — verify, don't assume. |
| `~/repositories/slate-v-1` | not served | cPanel Git checkout. Outside any docroot. Fine where it is. |

Confirmed from cPanel → Domains: `rakibhasaan.com` has document root `/public_html`, so **both `/slate/` and `/slate-test/` are publicly reachable today.**

---

## Phase 0 — today, no deletions

### 0.1 Commit `multilang-translate` (highest value, ten minutes)

It is live, maintained, in no branch, and not gitignored. It exists in exactly two places: this server and your local machine. One `rsync --delete` removes the server copy.

```bash
git checkout -b feat/multilang-translate develop
git add plugins/multilang-translate
git commit -m "Add multilang-translate plugin (work in progress, deployed)"
git push -u origin feat/multilang-translate
```

Commit it **unfinished**. A WIP branch is not untidy; an uncommitted live plugin is. This also removes it from the `--delete` hazard list permanently.

### 0.2 Snapshot the live tree before touching anything

```bash
cd ~ && tar czf ~/slate-live-snapshot-$(date +%Y%m%d).tar.gz \
  --exclude='*/uploads/*' --exclude='*/data/*' \
  greenlightinduction.rakibhasaan.com/slate
```

Then download it. Keep it until Phase 1 is finished. Note: this adds inodes to an account already at 93% — delete it once Phase 1 is done.

### 0.3 Close `slate-test` without deleting it

Do **not** delete it yet; it may hold the only copy of something. Take it off the public internet instead — a two-line `.htaccess` at `~/public_html/slate-test/.htaccess`:

```apache
Require all denied
```

That matches the pattern the team already uses for `db/`, `includes/`, `docs/`, `src/`, `tests/` and `vendor/`. Verify it returns 403, then leave it parked until Phase 2.

### 0.4 Apply the SEC-1 block to the live tree

The live `.htaccess` is byte-identical to `develop`'s, so the 30-line block applies cleanly. Back up outside the web root first:

```bash
cp ~/greenlightinduction.rakibhasaan.com/slate/.htaccess \
   ~/htaccess-greenlight-backup-$(date +%Y%m%d).txt
```

Insert after `RewriteBase /slate/` (line 81), before the shop rules:

```apache
    # RESERVED DIRS: tools, bin and scripts are never web-served, at any depth.
    RewriteRule (^|/)(tools|bin|scripts)/ - [F,L]
```

Then verify the site still loads and a tools path 403s. If anything breaks, restore the backup — one command, instant.

### 0.5 Widen the Cloudflare rule

The deployed rule matches `/slate/plugins/` only and misses `slate-test`. Until 0.3 lands, update *"Block plugin tools dirs (SEC-1)"* to:

```
((http.request.uri.path contains "/slate/plugins/" or
  http.request.uri.path contains "/slate-test/plugins/") and
 (http.request.uri.path contains "/tools/" or
  http.request.uri.path contains "/bin/" or
  http.request.uri.path contains "/scripts/"))
```

---

## Phase 1 — make production reproducible — ✅ COMPLETE 2 Sep 2026

**Production is reproducible from git.** `develop` is now a superset of the live
tree: every live file either matches `develop` or is behind it, and nothing runs
on the server that is absent from the repo. The gate on Phase 2 is lifted.

Four merges got there — #46 CORE-1 (anti-drift helpers + guard), #47 docs,
#48 multilang-translate (verbatim import, then two cross-tenant fixes),
#49 SEC-1 (the `tools|bin|scripts` deny, matching what was applied live by hand).

What the 63/37 figures resolved to:

| original | actual outcome |
|---|---|
| 63 modified tracked files | 56 were Phase C/D work that has since merged; of the remaining 7, **6 were live *behind* `develop`**, not drift to recover |
| — | exactly **1** file had live ahead: `.htaccess`, the hand-applied SEC-1 rule — now on `develop` via #49 |
| 37 untracked | **20**, every one `plugins/multilang-translate/`, matching `feat/multilang-translate` byte for byte |
| 6 Phase C/D content-spine files | already on `develop` and live; item 2 resolved itself |

Two genuine cross-tenant bugs surfaced while getting #48 green — see the queue
entry `SEC-8`. They were in the live plugin, not introduced by the import.

Step 5 result (deletion list, computed against `develop` @ `ca950d4`):

```
.ftpquota
```

**One file, and it is a cPanel artefact that regenerates.** Down from 71 before
the merges. The deploy would additionally add 24 files (`Claude/`, `audit/`,
`bin/anti-drift*`, test fixtures, issue templates) and update 55 — all of them
`develop` moving live forward, none of them live losing work.

Steps 1–4 done. Step 5 computed but **not executed**: the workflow is
`workflow_dispatch`-only and needs a browser or a token, so the list above is a
local reproduction of the same exclude/delete arithmetic, not the workflow's own
output. Run the real `dry_run: true` before any live deploy and confirm it says
the same thing.

Original scope, retained:

1. **Diff live against `develop`** file by file. For each of the 63 modified tracked files, decide: is the live version newer work that must come back into git, or local drift to discard?
2. **The Phase C/D content-spine files** — `content-builder/public/api.php`, `lib/MediaKeyResolver.php`, `lib/PrecomposedBody.php`, `lib/SiteTemplate.php`, `lib/blocks/react.php`, `public/assets/slate-content.js` — are live, referenced by a modified `ContentBuilderAPI.php`, and absent from `develop`. They are a coherent unit. Commit them together or not at all.
3. **The 37 untracked files** — classify each: commit, gitignore, or delete.
4. **Tag the result.** When `git status` on the live tree is clean against a named ref, production is reproducible. That is the finish line for this phase.
5. **Only then** run the deploy workflow with `dry_run: true` (supported at `deploy.yml:89`) and read the deletion list before any real run.

---

## Phase 2 — retire the duplicates

Once Phase 1 is done and the snapshot is verified:

1. **`~/public_html/slate`** — establish whether anything links to it or hits it (check the `rakibhasaan.com` access log for `/slate/`). If dead, move to `~/_archive/` first; delete a week later if nothing broke.
2. **`~/public_html/slate-test`** — decide what testing it is actually for. Options, best first:
   - **Delete it.** Test locally, as you already do with `multilang-translate`.
   - Move it to a subdomain with `Require all denied` plus an IP allow, so it is never anonymously reachable.
   - Keep it public — not recommended; it is a full copy of the platform with its own admin login and its own database.
3. **`~/repositories/slate-v-1`** — confirm it isn't a stale duplicate of the real repo. If it is, remove it; it's inodes and confusion.

4. **`.claude/worktrees/` — four full Slate installs inside the document root.** Each AI
   coding session creates a git worktree; the path is derived from the repository root, and
   the repository root here *is* the live document root. So every session leaves a complete,
   PHP-executing second copy of the platform on the production domain, running whatever an
   unreviewed branch held mid-session. Confirmed: `adr-0008-amendment-status-b776ee` returns
   200 on `admin/login.php`, `customer/login.php` and `index.php`.

   Each carries its own `.env` with a different `DB_NAME` and `APP_SECRET` — no live data
   behind them, no token crossover — but the same `DB_USER`, so a flaw reachable in one is
   reached holding credentials that also open the production database.

   **This is not a tool misconfiguration and there is no setting that fixes it.** The tool
   derives the worktree path from the repository root and is behaving correctly; the defect
   is that the repository is in the document root. The proof is on this same machine:
   `~/slate-worktrees/` holds four worktrees, 45 MB, created from a checkout outside any
   docroot — same tool, same account, not public. Only the docroot-rooted sessions become
   websites.

   **The fix is therefore Step 4.2 of the dev-environment migration — removing the git
   checkout from the document root — not a Claude Code config change.** Nothing here should
   be attempted before that migration exists to move to; removing the checkout without a
   local dev environment leaves nowhere to work.

   Interim containment, already applied:
   - `.claude/.htaccess` with `Require all denied`, applied by hand. This is what actually
     covers the current four: `Require` directives merge, so a child `.htaccess` cannot
     discard them. Deliberately **not committed** — `.claude/` is rsync-excluded and could
     never deploy.
   - A `RewriteRule (^|/)\.claude/ - [F,L]` in the root `.htaccess` (PR #55). This does
     **not** cover the existing four: each worktree is a full checkout carrying its own root
     `.htaccess` with `RewriteEngine On`, and mod_rewrite per-directory rules are not
     inherited without `RewriteOptions Inherit`. It is defence for a `.claude/` directory
     that has no `.htaccess` of its own, nothing more.

   When the migration lands: `git worktree remove` each one. `adr-0008` maps to a session
   from 29 Aug whose work already shipped as #38 and #40, so it is finished and removable
   today. Removing a worktree deletes a live application; the deny rules only hide one.

   Inodes are not the argument for this. The four are 4,813 of 279,932 — **1.7%** — and are
   not a meaningful part of the 93.3% in Phase 3.

   The owning task is **Step 4.2 of `Claude/slate-dev-environment-migration.md`** — *"Remove
   the git checkout from the docroot. The deployed tree should be files, not a repository."*
   That migration's own prerequisite is Phase 1 of this plan, which is complete, so the
   sequence is unblocked. Step 4.3 there removes `~/slate-worktrees/*` as well; the docroot
   worktrees under `.claude/` go with the checkout at 4.2, since they live inside it.

Everything moves to `~/_archive/` first. Nothing is `rm -rf`'d on the same day it is identified.

---

## Phase 3 — hygiene

- **Inodes: 279,932 / 300,000 (93.3%).** At the ceiling, writes fail account-wide — uploads, sessions, logs, backups, every site. Archiving the duplicate trees will help; so will clearing old backups and stale `node_modules`. This will bite before anything in the audit does.
- **194 databases.** Almost certainly includes abandoned installs. Cross-reference against the 18 domains and drop what nothing uses — after a dump.
- **Media library** — duplicate files (`energy-hero.jpg`, `electric-hero.jpg`, `beachside-hideaway.jpg`, four `wailea` lots each twice); 20 of 35 unused.
- **76 Cloudflare IP Access "Allow" rules** — each bypasses every security rule, account-wide, forever. Residential IPs get reassigned. Prune to the ones you can name today.
- **SSL on `rakibhasaan.com` shows Expired** in cPanel while subdomains serve fine. Worth a look.

---

## The layout to hold going forward

```
~/<domain>/                     one app per domain, the live copy, nothing else
~/_archive/                     things being retired, dated, outside every docroot
local machine + git branch      all development and testing
```

Three rules that would have prevented every item above:

1. **No test copy under a public document root.** Ever. Test locally; a branch is free.
2. **Nothing runs in production that isn't in git.** A WIP branch is the answer, not an uncommitted directory.
3. **One app per domain.** `rakibhasaan.com/slate` and `/slate-test` sitting under your main domain is how you end up with three trees and no idea which is authoritative.

---

## Status

**Phase 0: complete.**

- 0.1 `multilang-translate` committed — merged to `develop` (#48).
- 0.2 snapshot taken — `~/slate-live-snapshot-20260901.tar.gz`, 18 MB, 9,067
  entries, verified readable and containing the plugin. **Delete it now that
  Phase 1 is closed**, per this plan's own note about inodes.
- 0.3 `slate-test` parked — `~/public_html/slate-test/.htaccess` carries
  `Require all denied`.
- 0.4 SEC-1 applied live and verified 403; backup at
  `~/htaccess-greenlight-backup-20260901.txt`. Now also in git (#49), so it
  survives a deploy.
- 0.5 Cloudflare rule — still outstanding, dashboard change.

**Phase 1: complete.** See above.

**Phase 2 is unblocked.** The one rule is satisfied: nothing gets deleted until
production is reproducible from git, and it now is.

One correction to Phase 3 while it is being read: the inode figure is cPanel's
**File Usage** meter — the enforced account quota, 279,932 / 300,000. `df -i`
reports ~22% because it measures the shared filesystem, which is not the number
that fails writes. Use cPanel's.
