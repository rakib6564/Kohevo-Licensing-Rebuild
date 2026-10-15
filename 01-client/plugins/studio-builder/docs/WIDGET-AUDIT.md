# Widget design-control audit (developer tool)

Finds design controls that do nothing, and widgets that offer controls they cannot use. It is not part of CI and
never touches a real site: it renders documents in-process and drives the e2e sandbox.

## 1. Does each control reach the page? (static, no server)

```
cd 01-client
php tests/audit/render-widgets.php /tmp/fh-audit          # one HTML file per widget, props.json, index.json
node plugins/studio-builder/ui/audit/measure.mjs /tmp/fh-audit   # results.json + results.md (✓ ✗ – n/a)
```

`render-widgets.php` builds a sample of every registered block type, renders it once per probe in
`tests/audit/probes.json` (text colour and token, background colour, token and gradient, padding, margin, border,
radius, shadow, font size/weight/italic/transform/spacing/line height/family, underline, alignment, opacity, size,
transforms, filters, and the space, radius, shadow, font and border tokens), and `measure.mjs` loads each page in
Chromium and compares the computed style on the element the control should change. `–` means the widget draws no
text, so the control does not apply. A `✗` is a control that does nothing on that widget.

Limits: widgets that need a provider (booking, forms, membership, query loop) and the `payment.*` blocks render
empty here, so they are not measured.

## 2. What does the editor offer per widget? (needs the e2e sandbox)

```
cd 01-client/plugins/studio-builder/ui
SBX_URL=http://localhost:8201 AUDIT_OUT=/tmp/fh-audit npx playwright test -c audit/playwright.config.mjs
```

One test per widget inserts it and lists the controls of the Content, Style and Advanced tabs (label, kind, hidden,
disabled, invalid) into `$AUDIT_OUT/inventory/<type>.json`, with a screenshot in `shots/`. Read it for sections that
should not be there (Typography on a divider) or controls in the wrong tab. Use the sandbox, never a live site, and
never run it at the same time as another Playwright suite (the sandbox is single-threaded).
