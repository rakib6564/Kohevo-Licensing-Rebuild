# Kohevo Studio builder UI (developer source)

React shell for the Kohevo Studio builder. **Developer build only** — the
prebuilt output in `../assets/builder/` is what ships; customer installs never
run Node. This directory is denied to the web (`.htaccess`).

```bash
npm ci            # exact versions from package-lock.json
npm run build     # -> ../assets/builder/ (commit the result)
npm test          # node:test suites (core, sync engine, components, storage)
```

- `src/core/*.mjs` — framework-free logic (document rules, canonical
  operations, sync/autosave/conflict engine, field model, API client, locks,
  canvas bridge, rich-text allowlist). Tested directly with `node --test`.
- `src/components/*.jsx` — the shell (TopBar, LeftPanel, CanvasArea,
  RightPanel, inspectors, metadata-driven field controls, dialogs).
- `tests/fixtures/manifest.json` — the real editor manifest generated from the
  PHP block registry; `tests/unit/StudioBuilderPhase5BuilderTest.php` fails if
  it drifts from the registry.

Decisions, API contract and dependency record:
`architecture/KOHEVO-STUDIO-PHASE5-BUILDER-SHELL.md` (repository root).
