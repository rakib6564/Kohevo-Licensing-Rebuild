# Next-Session Continuation Prompt — Phase 1 A3 core-class migration

Copy the block below verbatim into a **fresh session** to continue A3 with full
context and the approved discipline. (State is also in auto-memory
`architecture-doc-hub`.)

---

```
Continue Phase 1 A3 of the Slate platform refactor.

CONTEXT
- Repo: /home/rakilluy/greenlightinduction.rakibhasaan.com/slate (served under /slate/).
- Architecture v1.0 is FROZEN (tag `architecture-v1.0`); the doc hub under docs/ is the
  source of truth. Foundation Standard + migration map: docs/03-Standards/platform-foundation.md (§10).
- We are on branch `phase1-kernel-substrate`. A PSR-4 autoloader (Slate\ -> src/) is wired
  into config.php with a class_alias compatibility layer (src/compat/aliases.php).
- A3 migrates core classes out of includes/ into src/ (namespaced), one class per commit,
  each behind a class_alias so the old global name keeps resolving; includes/<Class>.php
  becomes a thin `require_once` forwarder. Behavior must stay IDENTICAL (no `final` added,
  no logic changes).
- DONE (7, all smoke 21/21): Hook->Slate\Kernel\Event\Hook, I18n->Slate\Services\I18n\I18n,
  AuditLog->Slate\Services\Audit\AuditLog, Notifications->Slate\Services\Notifications\Notifications,
  Uploads->Slate\Services\Media\Uploads, Media->Slate\Services\Media\Media,
  Mailer->Slate\Services\Notifications\Mailer.
- Design reviews for the remaining four: docs/09-Roadmap/a3-core-reviews/.

TASK — migrate the final four core classes in THIS approved order:
  1. PublicRouter -> Slate\Kernel\Http\PublicRouter
  2. Database     -> Slate\Data\Database
  3. Auth         -> Slate\Services\Auth\Auth
  4. PluginLoader -> Slate\Kernel\Module\PluginLoader

CONSTRAINTS
- Keep Auth and PluginLoader INTACT. Do NOT split responsibilities — A3 is namespace
  migration with full backward compatibility, not architectural decomposition. The SRP
  splits are Phase-3 work, already documented.
- Leave the `Plugin` base class GLOBAL for now (defer to Phase 3 SDK work). PluginLoader
  references it as `\Plugin`. When qualifying PluginLoader, target `Plugin` occurrences
  EXPLICITLY (?Plugin, Plugin::class, Plugin $x, array<string,Plugin>) — a blind replace
  would corrupt the substring in `PluginLoader`.

PER-CLASS DISCIPLINE (one class = one commit):
  a. Read includes/<Class>.php; identify cross-namespace static calls (X:: -> \X::) and
     global classes (new X -> new \X, X::CONST -> \X::CONST). Same-namespace siblings stay
     bareword. Functions/constants fall back to global automatically.
  b. Move to src/<path>/<Class>.php with declare(strict_types=1) + namespace; keep behavior
     identical (do NOT add `final`).
  c. Add class_alias(\New\FQCN::class,'OldName') to src/compat/aliases.php.
  d. Replace includes/<Class>.php with `require_once __DIR__.'/../src/<path>/<Class>.php';`.
  e. `php -l` both files + grep to confirm no unqualified cross-ns refs remain.
  f. Run `php tests/smoke.php` — MUST stay 21/21 (or better).
  g. Runtime/alias verification via a scratchpad PHP FILE (multi-line `php -r` is broken by
     this host's EA-PHP CLI wrapper — write a temp script instead).
  h. Commit ONLY that class's files.

SPECIAL VERIFICATION
- AFTER Database: extra pass exercising the already-migrated services (Notifications::unreadCount,
  Media::counts, Mailer::verifyConnection, I18n::translate, AuditLog::recent) to confirm they
  still resolve `\Database::` through the alias, plus a real page load.
- BEFORE the PluginLoader commit: extended verification — full plugin boot, active-plugin
  discovery (isActive for each active slug), admin plugin manager (listAll +
  renderQueuedStyles/Scripts), public routing (a live /book + /forms route), event registration
  (hooks fire), service/API registration (cross-plugin class_exists('BookingAPI') etc.), and
  the smoke suite.

STOP RULE: if anything behaves differently from the current platform, STOP immediately and
report before committing.

Do NOT start Phase B/C until A3 is 100% complete and verified. Housekeeping: `plugins/sitehub/*`
changes are the user's intentional WIP — do not touch. Start with PublicRouter.
```

---

**Assessment carried into next session:** the remaining work is primarily
implementation, not architecture — the major decisions are documented, validated,
frozen, and phased. The dominant risk is **regression while migrating core
infrastructure**, so the cautious, incremental, verify-every-step approach stays in
force.
