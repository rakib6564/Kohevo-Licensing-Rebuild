# Kohevo Studio — Interactivity Prototype

A working prototype built to answer one question: **how much more capable would a
Kohevo Studio site become if we ship the interaction runtime?**

Open `index.html` in a browser. No build step, no dependencies, no server.

---

## The decision device: the RUNTIME toggle

Bottom centre of the page: **RUNTIME `ON` / `OFF`**.

This is the whole point of the prototype. Toggling `OFF` doesn't swap in a
different page — it makes `studio-runtime.js` **undo every mutation it made**
(`StudioRuntime.disable()`), so the *same server-rendered markup* renders
exactly like a Studio page does today.

| | RUNTIME ON | RUNTIME OFF (= today) |
|---|---|---|
| Modal | Opens, focus trapped, Esc closes, scroll locked | `:target` jump, no focus management |
| Accordion | Expands, `aria-expanded`, keyboard | All panels open, no state |
| Tabs | Only active panel shown | All panels stacked |
| Carousel | One slide, dots, autoplay, keyboard | All slides in a stack |
| Counters | Count up on scroll | Static final numbers |
| Scroll reveal | Elements fade/rise as they enter | Everything visible immediately |
| Offcanvas | Slides in, Esc, scroll lock | Hidden or fragment jump |

Toggle OFF, then ON again. The page returns to identical visual state — that
round-trip is the proof that the runtime is cleanly reversible, which is what
makes it safe to ship tenant-independently.

Also on the bar: **THEME** switcher (Phase 5 token layer) and **Reduce motion**
(a11y contract).

---

## What each section proves

| # | Section | Demonstrates | Plan phase |
|---|---|---|---|
| 1 | Hero | Staggered entrance, parallax bg | Phase 4 motion (CSS already exists, needs UI) |
| 2 | Stats | Count-up on scroll | Phase 2 runtime |
| 3 | Services grid | Hover lift, data-driven cards | **Works today** — `booking.services` |
| 4 | Teachers | Hover reveal | Phase 4 interactions |
| 5 | Timetable | Tabs | Phase 2 + Phase 6 |
| 6 | Membership | Pricing cards, featured state | **Works today** — `membership.plans` |
| 7 | Testimonials | Carousel: dots, autoplay, arrows, keyboard | Phase 2 + Phase 6 |
| 8 | FAQ | Accordion with real ARIA | Phase 2 |
| 9 | CTA → modal | Modal with focus trap + Esc + form | Phase 2 |
| 10 | Footer | Marquee, mobile offcanvas nav | Phase 2 |

Sections 3 and 6 use your **real** block types (`booking.services`,
`membership.plans`) and the real `data-sb-*` attributes the PHP renderers
already emit. This is a legitimate Studio page render, not an unrelated mock.

---

## What you keep, what you throw away

**Keep — these are the deliverables:**

- **`studio-runtime.js`** (≈12KB, no dependencies) → *is* the Phase 2 artifact.
  Written against your existing `data-sb-*` conventions so it drops into
  `PageDocumentAssembler` behind the renderer's existing asset-hash cache
  busting. Zero schema change, zero new operations.
- **`studio-tokens.css`** token layer → the Phase 5 spec for structured style
  keys (gradient, border, shadow, radius, image fit, typography scale).

**Throw away:**

- `index.html` markup and the demo script in its `<head>`.
- `test/` — a scratch harness that drove Chrome to verify the runtime.

---

## Runtime catalog (this is the ceiling)

Implemented: modal · offcanvas · accordion · tabs · carousel · counter ·
scroll-reveal · stagger · parallax · lightbox · sticky header · countdown ·
marquee · smooth anchor scroll.

That list **is** the ceiling of Phase 2a. If it feels thin for what your
customers ask for, that's the signal that Phase 2b (plugin-contributed JS via
your existing Widget SDK) is the right follow-up — it raises the ceiling
without ever executing tenant-authored code.

### What this does NOT give you

- **Freeform pixel positioning.** Studio is flow layout (sections → blocks).
  No x/y coordinates, no free z-order.
- **Arbitrary third-party JS.** GSAP timelines, Three.js, chart libraries are
  out of reach. Custom CSS (Phase 1) buys styling, not behaviour.

---

## Constraints the runtime was written against

These are deliberate and should survive into production:

1. **Progressive enhancement.** No JS → working, readable, navigable HTML.
   A modal is a fragment link; a carousel is a stack of slides.
2. **Server stays authoritative.** The runtime never persists and never invents
   document state. It only reads `data-sb-*` attributes.
3. **Compositor-only animation.** Only `transform` and `opacity` are animated,
   because the deployment target is shared hosting without GPU compositing.
4. **Accessibility is not optional.** Focus trap, `aria-expanded` /
   `aria-selected` / `aria-hidden` bookkeeping, Escape-to-close, keyboard
   parity, and a first-class `prefers-reduced-motion` contract.
5. **Counters ship their final value in the HTML.** No-JS and crawlers see real
   numbers; the runtime rewrites to `0` and animates up only when it mounts.

### Never render this in the editor canvas

The builder canvas stays `script-src 'none'` by design. Tenant code and the
runtime must be emitted **only** for public/preview render modes. See Phase 3
for the live-preview work that makes authoring interactive content tolerable
without weakening that.

---

## Tests

```
node test/run-tests.js
```

Drives real Chrome, exercises every feature (modal open → focus trap → Esc,
tab switching, accordion expansion, carousel dots, counters, and a full
`disable()` → `mount()` round-trip). 63 assertions, 0 failures.

---

## Suggested decision path

1. Toggle `RUNTIME` OFF/ON a few times. Feel the difference.
2. Open the timetable tabs, drag the testimonial carousel, open the modal with Tab.
3. Hit **Reduce motion** and confirm the page stays fully usable.
4. Switch themes — the whole brand changes from one token block.

If that ceiling is acceptable → ship Phase 1, 4, 3, 2 in that order (independent,
each delivers value alone). If it's too curated → plan Phase 2b first.