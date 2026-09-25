# 04 — Accessibility

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The accessibility baseline every Component, Block, Theme, and rendered Page must
meet. Accessibility is a property of the **Component Library and Design Tokens**,
not something each module re-solves — get it right once at the primitive layer
and everything built on top inherits it.

**Target:** WCAG 2.1 **AA** across admin and public surfaces.

---

## 1. Why this lives in the design system

Because Components are the only things that emit UI markup ([boundary table](../README.md#layer-boundary-table-what-belongs-where)),
accessibility is enforced where markup is born. A Block composes Components; a
Theme supplies token *values*. If a Button component ships correct focus,
contrast, and semantics, every Block using it — and every module — is accessible
by construction. This is the same "one concept, one owner" logic as tokens.

---

## 2. Color & contrast (from tokens)

- Semantic color tokens ([design-tokens.md](design-tokens.md)) are defined in
  **contrast-safe pairs** (`--slate-color-fg` on `--slate-color-bg`,
  `--slate-color-on-accent` on `--slate-color-accent`). Text pairs meet **4.5:1**
  (normal) / **3:1** (large); UI/borders meet **3:1**.
- **Themes must not break contrast.** A tenant Theme overrides token *values*;
  the theme validator (build-free, run at save) checks each foreground/background
  pair against its ratio and refuses or warns on a failing palette. Contrast is a
  guarantee of the token system, not a per-tenant gamble.
- Never encode meaning in color alone — pair status color with an icon/label
  (a Badge shows both).

---

## 3. Keyboard & focus

- Every interactive Component is reachable and operable by keyboard; **focus
  order follows DOM order** (no positive `tabindex`).
- A visible focus ring (`--slate-focus-ring`) on every focusable element;
  never `outline:none` without a replacement.
- Composite Components (Tabs, Modal, Menu, DataRow expander) implement the
  expected key interactions (arrow keys, `Esc` to close, focus trap + restore for
  Modal) per the WAI-ARIA Authoring Practices.
- Progressive enhancement ([00-Vision](../00-Vision/)): content and navigation
  work without JS; JS adds affordances, never gates access.

---

## 4. Semantics & ARIA

- Prefer **native elements** (`<button>`, `<a>`, `<label>`, `<nav>`, `<main>`)
  over ARIA-retrofitted `<div>`s. ARIA is the fallback, not the default.
- Components own their roles/names: an icon-only Button requires an
  `aria-label`; form Fields wire `<label for>` + `aria-describedby` for
  hint/error text; Alerts use `role="status"`/`role="alert"` appropriately.
- One `<h1>` per page; heading levels never skip. Blocks emit heading levels
  relative to their Section context so a composed Page stays well-structured.

---

## 5. Touch, motion, media

- **Tap targets ≥ 44×44px** (a token: `--slate-tap-min`), enforced on mobile
  (Slate's responsive breakpoint model).
- Respect `prefers-reduced-motion`: transitions/animation collapse to none;
  never rely on motion to convey state.
- Media components require `alt` text (the Media Manager stores it); decorative
  images get empty `alt`. Video/audio provide captions/transcripts where
  applicable.

---

## 6. Where it's checked

| Stage | Check |
|---|---|
| Component authoring | semantics, focus, ARIA per this doc (review checklist) |
| Theme save | token contrast-pair validation (build-free) |
| CI | automated axe-style audit on representative rendered pages ([12-Testing](../12-Testing/)) |
| Handoff | `design:accessibility-review` for net-new patterns |

Accessibility regressions are treated as defects, not polish — they fail the
same gate as a broken test.

---

## Related

- [design-tokens.md](design-tokens.md) · [component-library.md](component-library.md) · [README.md](README.md)
- [05-Rendering](../05-Rendering/) · [12-Testing](../12-Testing/)
