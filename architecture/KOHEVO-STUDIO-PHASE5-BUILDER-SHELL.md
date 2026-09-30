# Kohevo Studio — Phase 5 Builder Shell: decisions and contract

Status: implemented (Phase 5). This records the decisions the Phase 5 approval
asked to be documented before the canvas architecture was finalized, and the
contract the builder UI relies on.

## 1. Frontend architecture

The Kohevo client had **no JavaScript build system and no React** before
Phase 5 (plain PHP, a few hand-written scripts). Following the approved
third-party decision (custom React shell + Lexical for rich text; prebuilt
assets, no Node on customer installs):

- Source: `01-client/plugins/studio-builder/ui/` (`src/`, `tests/`,
  `package.json` + `package-lock.json`, `build.mjs`). Never web-served
  (`ui/.htaccess` denies it; `node_modules/` is gitignored and excluded from
  deploys/plugin zips).
- Output (committed): `01-client/plugins/studio-builder/assets/builder/`
  (`builder.js` ES module entry, `chunks/`, `builder.css`). Customer installs
  only serve these static files.
- Bundler: **esbuild** (dev-only). Required because React/JSX needs a bundler
  and none existed; esbuild is a single zero-config binary (no second
  framework, no plugin ecosystem to maintain).
- State: React `useSyncExternalStore` over a framework-free `SyncEngine`
  (`ui/src/core/sync.mjs`) — no state-management library.
- Drag & drop: native HTML5 DnD in the structure outline plus full keyboard
  equivalents (Alt+↑/↓ move, Alt+→ into the container above, Alt+← out of
  a container, Delete). dnd-kit was not needed for Phase 5 because DnD happens
  in the outline (one document), and it would add no capability the keyboard
  alternatives + native events do not already cover.
- Rich text: **Lexical**, loaded as a lazy chunk only when a `rich_text` field
  is opened. Its HTML export is reduced to the canonical `rich_text` allowlist
  (`ui/src/core/richtext.mjs`) before it becomes an operation; Lexical's own
  JSON state is never stored.

```
StudioShell ─┬─ TopBar      page, save state, undo/redo, viewport, preview, publish
             ├─ LeftPanel   BlockPalette + Outline (tree, DnD, keyboard moves)
             ├─ CanvasArea  EditorFrame: sandboxed iframe of renderForEditor()
             └─ RightPanel  Block / Section / Page inspectors (manifest-driven)
```

## 2. Canvas / iframe decision (resolves Open Question Q17)

**Decision: same-origin iframe, `sandbox="allow-same-origin"` (no scripts),
loaded from `admin/canvas.php` = `StudioApplicationService::renderForEditor()`.**

Considered:

| Option | Verdict |
|---|---|
| Isolated preview origin/subdomain + postMessage | Strongest isolation, but the Kohevo deployment model is a single domain on shared hosting (cPanel/CloudLinux); a second origin with its own session is not available to customers. Rejected for Phase 5. |
| Same-origin iframe **with** `allow-scripts` + injected bridge script | Rejected: `allow-scripts` + `allow-same-origin` together make the sandbox meaningless. |
| React re-rendering of blocks | Forbidden by the approval (server renderer is authoritative). |
| **Same-origin iframe, sandbox without scripts** | **Chosen.** |

Rationale:

- **XSS blast radius:** nothing inside the canvas can execute. Authored values
  are already validated on write and re-sanitized on render; in addition the
  sandbox blocks scripts, forms, popups and top navigation, and the canvas
  response carries `Content-Security-Policy: script-src 'none'; form-action
  'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'`
  (`Http\StudioCanvasPolicy`).
- **Selection without postMessage:** same-origin lets the builder (parent)
  attach its own listeners to the frame document and read `data-sb-node` /
  `data-sb-type`; parent-realm listeners run even though the frame's own
  scripts cannot. Verified in a real browser (clicks select, links never
  navigate, an injected `<script>` is blocked).
- **Session/auth:** the canvas is an authenticated, tenant-scoped, EDIT-gated
  request on the normal admin session; nothing new to secure.
- **Caching/indexing:** private, no-store, noindex; never the public runtime.
- **Future DnD on the canvas:** possible from the parent with the same model
  (hit-testing `data-sb-node` elements); not needed in Phase 5.

The frame always has the exact device width (1280 lg / 820 md / 390 base) and
is scaled to fit, so the canonical breakpoints of the server CSS apply
exactly. It reloads only when the server revision changes (debounced, scroll
kept), never on keystrokes.

## 3. Command / query API (`admin/api.php` → `Http\StudioAuthoringApi`)

Thin controller; every call goes through `StudioApplicationService` (tenant →
authentication → `studio-builder` entitlement → RBAC → ownership →
concurrency → validation → persistence → audit). No repository access.

Queries (GET): `bootstrap`, `document`, `status`, `manifest`, `revisions`,
`templates`, `pages`.
Commands (POST, JSON): `operations` (every document mutation, as canonical
`DocumentOperation`s), `save_draft`, `publish`, `rollback`, `create_page`,
`lock_acquire`, `lock_refresh`, `lock_release`.

Transport rules: POST only for commands; platform CSRF (`csrf_verify()`,
`X-CSRF-Token`); `Sec-Fetch-Site` must be same-origin; `application/json`
only; body ≤ 1.5 MiB; ≤ 50 operations; strict field allowlists (a
`tenant_id` anywhere is rejected); `expected_revision_id` mandatory on every
write; per-session rate limit. Errors use a fixed vocabulary:
`validation_error, authentication_error, authorization_error,
entitlement_error, csrf_error, not_found, method_not_allowed,
concurrency_conflict, payload_too_large, unsupported_media_type,
rate_limited, server_error` — never exception text.

## 4. Editing model

- React/browser state is transient. The document is loaded from the server on
  every open/reload; nothing is kept in localStorage/sessionStorage/IndexedDB
  (guarded by tests over source and bundle).
- Optimistic: operations apply locally, then are sent; the server's resulting
  document replaces the local base (reconciliation). One command in flight;
  ids are minted by the server and provisional `tmp_` ids are remapped (a
  batch never holds more than one insert).
- Autosave: property edits debounced (~0.9 s) and coalesced; structural edits
  sent immediately; `revision_kind = autosave` (server dedups identical
  autosaves); Ctrl/⌘+S = `manual`.
- Concurrency: 409 → `conflict` state (local changes counted, nothing more
  sent, publish/undo disabled). Only exit: reload from server. No auto-merge.
- Undo/redo: each confirmed batch records only `{before, after}` revision ids;
  undo/redo are server `rollback`s to those immutable revisions (bounded to
  50 entries). No document copies in browser memory.
- Locks: existing `studiobuilder_locks` (TTL 120 s, DB clock, heartbeat 45 s,
  released on page hide). Advisory only — they warn, never block;
  `expected_revision_id` stays authoritative.

## 5. Dependency record (exact shipped versions)

| Package | Version | License | Role |
|---|---|---|---|
| react / react-dom / scheduler | 19.3.0 / 19.3.0 / 0.28.0 | MIT | runtime (bundled) |
| lexical, @lexical/{react,rich-text,list,link,html,utils,…} | 0.52.0 | MIT | rich-text chunk (bundled, lazy) |
| @floating-ui/*, tabbable, @preact/signals-core | transitive | MIT | via @lexical/react |
| esbuild | 0.28.2 | MIT | dev build only (not shipped) |

All transitive packages in `package-lock.json` are MIT (checked from each
package manifest). Shipped size: `builder.js` ≈ 270 KiB (≈ 86 KiB gzip),
rich-text chunk ≈ 275 KiB (≈ 91 KiB gzip, loaded on demand). Browser target:
ES2020 (Chrome/Edge ≥ 100, Firefox ≥ 100, Safari ≥ 15). Update policy:
versions pinned exactly; upgrades are a deliberate change with a rebuild and
the UI test suite.

Build: `cd 01-client/plugins/studio-builder/ui && npm ci && npm run build`
(`npm test` runs the node:test suites; the PHP unit suite also runs them when
node is present).
