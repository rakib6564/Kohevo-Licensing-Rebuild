# B2-P1 — Builder Foundation & Workspace

> Planning specification only. Do not implement until the decisions marked **Blocking** are answered. Read `00-BUILDER-2-MASTER-REVIEW.md` first (§0 evidence notes, §24 decisions, §8 protected systems).
> Path convention: `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## Objective
Replace the single docked left panel with the premium **three-region workspace** (Add left · large canvas · Inspector right, hideable), introduce a real **selection model**, a **keyboard-accessible canvas overlay**, compact top/bottom chrome, and a correct **mobile shell foundation** — without changing the canonical document, operations, or any platform service. Add the DOM-level test harness every later phase needs.

## User-visible outcome
- Open the Builder and see the D01 layout: compact top bar (52–56 px), persistent Add panel (230–250 px), centred canvas, right Inspector (320–360 px).
- Click **Inspector toggle** (or the Layers/Inspector affordance) → the canvas widens immediately (D02).
- Click any node: a name chip + contextual toolbar (move, duplicate, delete, ⋯) appears **above** it, reachable by keyboard; breadcrumb and bottom bar update.
- Shift/Ctrl/Cmd-click selects several nodes on the **canvas and in Layers** and the Inspector reflects "N selected".
- On a phone: two-row compact bar, bottom nav **Blocks · Edit · Theme · Preview · More**, draggable sheets, safe-area and keyboard-aware, selecting a node shows the contextual bar and does **not** cover the canvas until **Edit** is tapped.

## Features
1. **Workspace layout (D01, D02):** `AddPanel` (left), `InspectorPanel` (right, uses/replaces the unused `RightPanel.jsx`), `CanvasStage`, `TopBar`, `BottomBar`. Add panel closable (✕); Inspector toggle; layout state in memory (see Data).
2. **Top bar:** Kohevo mark, page switcher, page-settings ⚙ (opens page settings sheet — content delivered P5), **+** quick-add menu, undo/redo, Desktop/Tablet/Mobile, **Layers** toggle, **Preview** (existing modes), **Publish ▾** (menu shell: Publish now; Schedule/Unpublish items disabled until P6), ⋮ overflow, avatar/account.
3. **Bottom bar:** breadcrumb, fit-to-screen, zoom − / % / +, guides toggle, shortcut help popover.
4. **Selection model** (`core/selection.mjs`): `{primary, ids, hover, focus}`; actions (duplicate, delete, lock, hide, move) operate on `ids`; announces via `LiveRegion`.
5. **Canvas overlay layer:** selection box, 8 handles (visual affordances only — see Risks), name chip, contextual toolbar and drop indicators rendered in the **parent DOM** from `getBoundingClientRect` of `[data-sb-node]`; replaces the in-iframe `innerHTML` toolbar. Roving-tabindex keyboard access; Esc clears.
6. **Canvas multi-select:** Shift/Ctrl/Cmd click and (optional) marquee on empty canvas; synchronised with Layers.
7. **Mobile shell foundation (M01, M02):** `useIsMobileShell()`; draggable bottom-sheet primitive (snap points, handle, backdrop); safe-area utilities; keyboard inset; two-row compact top bar with status chip (*Unsaved / Saved / Published*); Responsive-view sheet (device preset, zoom, element outlines, spacing guides, section labels) — dark-mode preview toggle present but disabled until P4.
8. **Shortcuts:** ⌘Z/⌘⇧Z, ⌘D, Delete, F2, ⌘K (focus Add search — **wire or remove the decorative badge**), Esc; shortcut help popover.
9. **Status & conflict chrome:** status chip in top bar; improved `ConflictBanner` (non-blocking toast variant + blocking only for 409).
10. **i18n sweep:** move hard-coded English in `Icons`, `MobileDock`, `LeftPanel`, palette headings, top bar into `messages.mjs` + `boot.messages` + `fr.php`.
11. **Test harness (Blocking decision D-test):** dev-only DOM/e2e harness + first suites.

## Screens affected
D01, D02 (primary); D03–D10 shells only (panels host the content built in P2/P3); D12 top-bar entry points; M01, M02; M13/M14 sheet primitives.

## Frontend work
- Split `StudioShell.jsx` into `AppShell` (engine, transport, dialogs, library, keyboard) and `WorkspaceLayout` (pure layout). Move, do not rewrite, existing handlers.
- New: `core/selection.mjs`, `components/CanvasOverlay.jsx`, `components/BottomBar.jsx`, `components/sheets/SheetPrimitive.jsx`, `hooks/useIsMobileShell.mjs`, `hooks/useDevice`/`useBreakpoint` stubs (used from P4).
- Re-home `LeftPanel` tabs: **Add** (Sections·Elements·Components·Dynamic) stays on the left; **Style/Inspector** moves right; **Layers/Pages** become a left-panel mode opened from the top-bar *Layers* button (replaces Add while open) per D07; **Library/Settings** become entries in the `+`/page-settings menus (content in P2/P5).
- `CanvasArea`: remove in-iframe toolbar creation from `core/canvas.mjs` (keep `attachCanvas` hover/click/drop/inline-edit handlers and `markSelected` class hooks); expose element rects to the overlay; keep live-sync/patch untouched.
- Split `EditorContext` into selection / document / actions contexts; memoise selectors (R10).
- Hide Inspector → recompute stage width (existing `ResizeObserver`).
- Remove the 860 literal; mobile selection no longer auto-opens the sheet.

## Backend/domain integration
**None required.** No new application-service methods or operations. (Layout preferences are session-only to respect "no browser persistence".)

## Data/model impact
- **Canonical document: none.** **Schema/tables: none.**
- Layout/panel open state lives in memory; a per-user preference (Inspector floating vs docked, panel widths) is an **open decision** — if wanted server-side it needs a user-settings key under the existing settings mechanism (no Studio table).

## Security considerations
- Canvas iframe stays script-less, same-origin, `allow-same-origin` sandbox, CSP `script-src 'none'`; the overlay lives in the parent and only *reads* geometry.
- Overlay actions call existing operations → existing layer-lock and authorization apply; multi-select bulk actions iterate operations (≤50 per request; honour the batch/insert rules).
- No `innerHTML` of untrusted strings in the overlay (names rendered as text).
- Permissions: edit controls disabled without `studio-builder.edit`; publish menu items gated by `permissions.publish`.

## Tests
- **Pure logic (`node:test`):** selection model transitions (single/multi/hover/focus, remap of `tmp_` ids, deletion of a selected node), overlay rect math (scale/zoom/scroll), `useIsMobileShell` thresholds, sheet snap logic, keyboard-inset reuse.
- **DOM/e2e (new harness):** shell layout at 1280/820/390; Inspector hide widens canvas; overlay keyboard navigation; Outline keyboard (existing behaviour pinned); mobile dock + sheets; focus return; ARIA roles/names; no overflow at 390 px.
- **Regression:** existing `ui/tests` (117) + `make test-unit`; `StudioBuilderPhase5BuilderTest` (manifest drift) unchanged.
- **Security:** assert the overlay never injects into the frame document; assert no scripts execute in the canvas.

## Dependencies
- Decisions **D1, D2m, D-test** (Blocking). Missing-screen request for D09/D11/D12 is not blocking P1.
- Pre-phase triage of master §22.3 items is independent but should precede release.

## Files/modules likely to change
`UI/components/StudioShell.jsx`, `LeftPanel.jsx`, `TopBar.jsx`, `CanvasArea.jsx`, `MobileDock.jsx`, `RightPanel.jsx`, `EditorContext.jsx`, `Outline.jsx` (selection wiring), `Icons.jsx`, `UI/core/canvas.mjs` (toolbar removal only), `UI/core/shellState.mjs`, `UI/core/messages.mjs`, `UI/builder.css`; new files listed above; `PLUGIN/admin/builder.php` (`messages` keys), `PLUGIN/lang/fr.php`, `PLUGIN/assets/builder/*` (rebuilt), `CHANGELOG.md`, test files.

## Files/modules that must NOT change
`SB/**` (document, operations, application, render, runtime, mcp), `PLUGIN/install.sql`, `core/operations.mjs` vocabulary and shapes, `core/sync.mjs` semantics (undo = server rollback; ≤1 insert per batch; `tmp_` remap), canvas sandbox/CSP (`StudioCanvasPolicy`), `data-sb-node`/`data-sb-type`, boot JSON keys, `sbx-` prefix and existing `data-testid`s, `core/layerLock.mjs`.

## Acceptance criteria
1. At ≥1280 px: Add left, canvas centre, Inspector right; toggling the Inspector changes canvas width by the Inspector's width with no reload.
2. The selection toolbar is reachable and operable by keyboard only; it is never clipped by the node's overflow.
3. Multi-select works from canvas and Layers and stays in sync; bulk duplicate/delete/lock use existing operations and respect the lock.
4. At 390 px: bottom nav = Blocks·Edit·Theme·Preview·More; no horizontal scroll; sheets drag/snap/close; keyboard open does not hide inputs; selecting a node does not cover the canvas.
5. Zero hard-coded user-visible English in the touched components (EN + FR present).
6. No change to document JSON or any server file; `make test-unit` shows no new failures; UI tests pass; new DOM suites pass.

## Definition of Done
Acceptance criteria met; bundle rebuilt and committed; CHANGELOG entry; EN/FR strings; tests added and green; accessibility checked with the harness; manual verification script run on desktop Chrome/Safari/Firefox and iOS/Android emulation (screenshots attached to the PR); no console errors; PR green on CI (MySQL + MariaDB).

## Verification plan
Run `npm test`, `npm run build`, `make test-unit`; run the new DOM suites; manual walk-through of D01/D02/M01/M02 with a seeded page; compare against the concept screens (layout, spacing, states); measure typing latency and bundle size against the budgets in master §23; confirm the canvas frame document contains no parent-injected nodes; confirm Inspector hide/show causes no iframe reload.

## Risks
- **Layout contradiction (D1)** — building the wrong layout; mitigate by deciding first.
- Overlay positioning drifts with zoom/scroll/live-patching → rAF recompute + `ResizeObserver` + tests.
- Resize handles imply a sizing model that does not exist → render as non-interactive "selection corners" or omit until P3 (`dimensions`) decides; do **not** ship handles that do nothing.
- Context split regressions → keep selectors stable; add render-count tests.
- Test-harness adoption (new dev dependency) needs approval and CI time budget.
