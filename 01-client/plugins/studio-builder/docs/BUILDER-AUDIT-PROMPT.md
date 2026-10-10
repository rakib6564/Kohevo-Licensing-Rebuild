# Builder audit prompt: Elementor-class parity

Paste everything below the line into a fresh Claude Code session opened in `KOHEVO-STUDIO`. It audits and plans only; it must not change code.

---

You are auditing the Kohevo Studio Builder (`01-client/plugins/studio-builder`, UI in `ui/src`, server in `01-client/src/Module/StudioBuilder`) against one product goal:

**The builder must work the way Elementor works: a live, true-to-site canvas; a panel/inspector workflow; a real design system; a library; responsive editing; and a media workflow, at production quality.** Kohevo keeps its own brand, its own code and its own security model. Elementor (github.com/elementor/elementor, GPL-3.0) is a *behavioural reference only*: read its docs, UX and public behaviour, and never copy its code or assets.

## Known symptoms (verify, do not assume)
1. The editing canvas does not look like the live page. `StudioCodePolicy::customCssMarkup()` returns '' in Editor mode and the head snippet (fonts) is skipped, so Custom-CSS-styled sites are unstyled while editing.
2. Image blocks show a raw "Media ID" number box unless the `media-library` plugin is active and the user has `media.view` (`ui/src/components/fields/MediaControl.jsx`). Focal point falls back to two bare sliders.
3. Live canvas and editor drift apart: stale canvas, clicks that select nothing (canvas is server HTML in a script-less sandboxed iframe patched by `core/canvas.mjs`, `core/canvasLiveSync.mjs`, `CanvasArea.jsx`).
4. UI inconsistency: screens not on the shared primitives in `ui/src/components/ui`.

## Part A: Elementor parity matrix
For each capability below, score Kohevo **0 missing / 1 partial / 2 equal** with file:line evidence, then say what "equal" needs. Use Elementor's documented behaviour as the yardstick.

1. **Canvas**: true-to-site render (site CSS, fonts, global styles, header/footer in context); hover/click selection with breadcrumb and toolbars; inline text editing; drag/drop with drop indicators; right-click context menu; copy/paste/duplicate across pages; undo/redo history panel; empty-state "add section" affordances.
2. **Panel / inspector**: Content / Style / Advanced tabs per widget; per-control responsive switcher (desktop / tablet / mobile); hover/normal states; dimensions (padding/margin linked fields); typography group; background/overlay/gradient; border/shadow/radius; dynamic values and bindings; conditional controls; search in settings; reset per control.
3. **Navigator / layers**: tree with drag-reorder, rename, hide, lock, search.
4. **Design system**: global colours, global typography, spacing/radius scale, theme style (buttons, forms, headings), site settings, custom CSS per site / page / element, class or preset reuse. How does Kohevo's token layer (`save_tokens`, `surface.*`, `text.*`, `font.*`, `radius.*`) compare, and does the builder UI expose it everywhere a colour/font/size can be chosen?
5. **Library**: saved templates (page / section / block / header / footer), global/linked components, kit import/export, search and categories, thumbnails/previews. (46 `kh-*` templates exist from `examples/faisal-hossen-site`; evaluate how well the Library panel serves them.)
6. **Theme builder**: header, footer, single, archive, 404, conditions (where it shows), preview as a given page.
7. **Responsive**: breakpoint editing, per-device visibility, device preview that matches the live page.
8. **Media**: upload, library grid, search, alt text, replace, focal point on the image, SVG/video/icons, lazy-loading and srcset.
9. **Widgets / elements**: forms, tabs, accordion, carousel, nav menu, query loop, icons, maps, embeds; the element manager.
10. **Workflow safety**: autosave, revisions with restore, conflict handling, preview-as-visitor, publish/schedule, lock/collaboration.
11. **Performance and a11y**: editor load time, large pages (250-block limit: is it adequate?), keyboard operability, screen-reader labels, mobile editing.

## Part B: Root-cause audit (do all, in order)
1. **Map**: data-flow diagram: engine state -> operations -> save_draft -> renderForEditor -> canvas.php -> iframe -> click -> `pick` -> selection -> inspector. Name each file/function.
2. **Reproduce** each known symptom in the sandbox (`SBX_DIR=<sandbox>/site`, Playwright; see `ui/e2e`), record steps, expected, actual; write failing test sketches (do not commit).
3. **Canvas fidelity**: list everything the public renderer emits that the Editor render omits. For each: security requirement, performance choice or accident? Check `StudioCanvasPolicy` CSP and what tenant CSS could break (CSS exfiltration, `position:fixed` covering selection UI, `pointer-events`, hiding editor chrome). Propose the minimum safe change.
4. **Sync correctness**: list every path that changes the document (inspector, drag/drop, undo/redo, template insert, history restore, autosave, conflict reload) and whether the canvas updates by live patch or reload; find cases where DOM ids can differ from document ids (global sections, partials, query loops). Review `pick`/`nodeIdChain` and the integrity repaint for races.
5. **Media**: trace `media_ref` end to end; specify a built-in picker that does not depend on the `media-library` plugin, with the server endpoints it needs (tenant scoping, upload validation).
6. **Architecture decision**: is a server-rendered iframe canvas able to reach Elementor-class feel (inline text editing, instant style feedback, per-device editing)? Compare at least: (a) keep server render + live patching, harden it; (b) server render + a client-side style layer for instant feedback; (c) client-side rendering of the canonical document. For each: effort, risk, what it unlocks, what it breaks. Recommend one with reasons.

## Output
Print the content for `docs/BUILDER-AUDIT.md`:
1. Executive summary (10 lines).
2. Parity matrix (Part A) as a table with scores and evidence.
3. Findings table (id, area, severity, evidence file:line, user impact, root cause, fix, effort S/M/L).
4. Architecture recommendation (Part B.6).
5. Roadmap in PR-sized slices, ordered by user value, each with acceptance tests; mark which slices are prerequisites for others.
6. Risks and open questions.

Rules: separate *verified fact* from *inference*; cite file:line for every claim; if you cannot reproduce something, say so; do not edit code; never reproduce Elementor source.
