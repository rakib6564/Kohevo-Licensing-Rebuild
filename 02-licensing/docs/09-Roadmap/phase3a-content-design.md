# Phase 3A — Content Foundation (design note)

**Status:** Implemented · **Date:** 2026-08-11 · **Branch:** `phase3a-content-foundation`

> **Outcome (all batches shipped, suite green 121 unit / 34 integration / 21 smoke):**
> - **B1** `Slate\Presentation\*` contracts + value objects (Block/BlockRegistry/Renderer, FieldSchema/LayoutSpec/Section/Page/RenderContext).
> - **B2** `DocumentSchema` — versioned envelope + BC normalizer (legacy flat array → one implicit Section, idempotent).
> - **B3** `PageRenderer` + `InMemoryBlockRegistry` + `CallbackBlock` — the one storage-agnostic traversal; default-layout sections render transparently.
> - **B4** migration `0004_content_revisions` + `RevisionStore` (owner-agnostic, schema-blind, tenant-scoped).
> - **B5** `ContentCoreBridge` — revision snapshots on save/publish + opt-in **parity-gated** core render (byte-identical to legacy on the live seeded pages).
>
> **Decisions taken:** Option A (adapter + revisions, content-builder stays the page store); snapshot on every save + publish; contracts in `Slate\Presentation\*`.
> **Deliberately deferred (needs its own reviewed step):** cutting the public render path (`content-builder/public/render.php`) over to the core renderer — production still renders through the legacy `Renderer`; the core path is proven-at-parity but not yet wired live.

## 0. Purpose & framing

3A builds the **content spine** the rest of Phase 3 (theme engine, block library,
SEO, visual builder) consumes. It is **not** a greenfield CMS — the model it must
realize is already ratified:

- **ADR-0007** — define the Section/Block/Template model *before* the visual builder.
- **ADR-0008** — one `--slate-*` token vocabulary (3B consumes; 3A only leaves room for it).
- **docs/05-Rendering/** — `blocks-and-sections.md`, `rendering-pipeline.md`,
  `theme-and-template-engine.md` already specify the hierarchy, the persistence
  shape ("ordered Section/Block tree as JSON"), the `Renderer` interface, and the
  BC clause ("content-builder's `Renderer::render(?array $layout)` is the spine
  being *promoted* … not replaced — existing flat block arrays render as a single
  implicit Section").

So 3A = **translate those specs into a concrete migration + a `Slate\Presentation`
renderer, reconciled with the live `content-builder` plugin** — additively, no BC
break, no destructive data move. This dir is production; `content-builder` already
serves real pages at `/p/<slug>`.

## 1. What already exists (extend, don't duplicate)

| Concern | Today (`plugins/content-builder`) | Gap 3A closes |
|---|---|---|
| Page store | `contentbuilder_posts` (JSON `layout`, draft/published/trash, tenant-scoped, unique `type+slug`) | — (reuse; see §5) |
| Blocks | `BlockRegistry` (array defs: `label/group/fields/defaults/tpl\|render`) + `Renderer::renderBlock` | promote registry/renderer *contract* to core; keep the plugin's blocks |
| Layout shape | **flat** JSON array of blocks; "sections" faked via full-width section-blocks (`hero`, `icon-grid`) and `columns` nesting | **first-class Section** wrapping blocks (layout vs content split) |
| Revisions | **none** — save overwrites `layout` in place | new immutable revision store |
| Draft preview | `?preview=1` re-reads the same row | preview reads the **working revision** (rendering-pipeline §5) |

## 2. The model (frozen by spec — restated for this build)

```
Page     = Template + [ Section, Section, … ]
Section  = LayoutSpec(cols/bg-token/spacing/width) + [ Block, Block, … ]
Block    = { type, props }         # props validated against the block's FieldSchema
Component= presentational primitive (3B)         # out of 3A scope
```

**Persistence = JSON**, per `blocks-and-sections.md §4` — *not* relational
section/block rows. Rationale: the document is authored, versioned, and rendered
as one unit; relational rows would be reassembled into this same tree on every
render and would fight the visual builder (3E). Indexed columns stay for routing/
status; the tree is one JSON column.

### 2.1 Document JSON schema (versioned)

```jsonc
{
  "schema": 1,                       // schema_version — enables safe upconversion
  "type": "page",                    // content type → template selection
  "template": "default",             // explicit override; else content-type default
  "sections": [
    {
      "id": "s_ab12",                // stable id (reorder/target in 3E)
      "layout": { "cols": 1, "bg": "surface", "pad": "normal", "width": "normal" },
      "blocks": [
        { "type": "hero", "props": { "heading": "…" } }
      ]
    }
  ],
  "seo": {}                          // MetaBag; populated by 3D, empty-safe now
}
```

**Legacy bridge (BC):** a flat `[{type,props},…]` array (every existing
`contentbuilder_posts.layout`) normalizes to **one implicit Section** with default
`LayoutSpec`. This is a pure read-time upconversion — **no stored data is
rewritten** in 3A. `full_html` pages (designer templates) pass through as today.

## 3. Tables

**One new core table only.** (Unprefixed, `tenant_id` column, `Slate\Data\Schema`
builder — matching 0001/0002 conventions. Ids: revision PK BIGINT; `owner_id`
BIGINT to match contact ids and be owner-agnostic.)

Migration `db/migrations/0004_content_revisions.php`:

```
content_revisions
  id              BIGINT UNSIGNED PK
  tenant_id       INT UNSIGNED (default 1)
  owner_type      VARCHAR(32)     # 'content-builder:post' now; other owners later
  owner_id        BIGINT UNSIGNED # e.g. contentbuilder_posts.id
  revision        INT UNSIGNED    # monotonic per (tenant,owner_type,owner_id)
  status          ENUM('working','published','archived')
  document        JSON            # the full versioned document (§2.1)
  schema_version  INT UNSIGNED
  author_id       INT UNSIGNED NULL
  note            VARCHAR(190) NULL
  created_at      DATETIME useCurrent
  UNIQUE (tenant_id, owner_type, owner_id, revision)
  INDEX (tenant_id, owner_type, owner_id, status)
```

- **Owner-agnostic** by design: it snapshots *any* content owner via
  `(owner_type, owner_id)`, so it is not welded to `content-builder`. If §5 later
  introduces a core `content_pages` table, revisions attach to it unchanged.
- `status='working'` = the editable draft the author previews; `status='published'`
  = the live snapshot. "Publish" copies working → a new published revision.
- Reversible: `down()` drops the table (empty at introduction). Verified against
  MySQL with a dry-run + rollback before commit (Phase-1/2A discipline).

**No `content_pages` / `content_sections` tables in 3A** — see the open decision, §5.

## 4. The renderer contract (`Slate\Presentation`)

Matches the interfaces already written in `blocks-and-sections.md §5` /
`theme-and-template-engine.md §1`. Public surface = the Block/Component contract
(platform-foundation §8); traversal internals are `@internal`.

```php
namespace Slate\Presentation;

interface Block {                    // core contract; modules implement via SDK
    public function type(): string;
    public function schema(): FieldSchema;                 // fields + defaults + prop types
    public function render(array $props, RenderContext $ctx): string;  // composes Components (3B)
}

interface BlockRegistry {
    public function register(Block $block): void;
    public function get(string $type): ?Block;
    public function all(): array;
}

interface Renderer {
    public function renderPage(Page $p, RenderContext $ctx): string;
    public function renderSection(Section $s, RenderContext $ctx): string;
    public function renderBlock(array $block, RenderContext $ctx): string;  // registry → block.render
}

final class Page      { public string $type; public string $template; public array $sections; public array $seo; }
final class Section   { public string $id; public LayoutSpec $layout; public array $blocks; public ?string $savedAs; }
final class LayoutSpec{ /* cols, bg token, pad, width — value object */ }
final class RenderContext { /* tenant, theme (3B), surface, preview flag */ }
```

**Renderer behavior in 3A:** pure Page→Section→Block traversal. `renderBlock`
resolves `type` in the `BlockRegistry` and calls `block.render`. Unknown block →
HTML comment (matches today's `Renderer` fail-soft). No Theme/Component coupling
yet — `RenderContext` carries a nullable theme so 3B slots in without a signature
change. The renderer is **storage-agnostic**: it never touches `contentbuilder_*`.

## 5. Reconciliation with content-builder — the one open decision

The renderer needs blocks to render, and 3A must not fork rendering. Two ways to
relate the new core spine to the existing plugin:

**Option A — Adapter + revisions (recommended).** `content-builder` stays the page
store. A bridge registers its existing blocks into the core `BlockRegistry` as
adapter `Block`s (reusing the current `tpl`/`render` machinery — no rewrite of the
~20 blocks). `content-builder` gains: revision snapshots on `savePost`/`publish`
(written to `content_revisions`), and an **opt-in, parity-gated** path to render a
page through the core `Renderer` — asserted byte-equal to the legacy `Renderer`
before anything cuts over (exactly the Phase-2A dual-write/parity pattern).
*Purely additive; zero data migration; lowest risk on live content.*

**Option B — Core `content_pages` table now.** Introduce the canonical core store
this phase; `content-builder` dual-writes into it and becomes a thin adapter.
Cleaner long-term, but it's a live-content migration with real BC surface and
duplicates a working system for a payoff 3B/3C don't yet need.

**Recommendation: Option A for 3A.** It honors "extend, don't greenfield" and
additive-only discipline, delivers every 3A noun (pages exist; sections + block
schema arrive as the JSON model + normalizer + contracts; revisions as the one new
table; draft/published via revision status), and leaves Option B as a clean,
deferrable follow-up because `content_revisions` is already owner-agnostic.

## 6. Proposed build order (each = one green commit; `bash tests/run.sh`)

1. **B1 — Contracts + value objects.** `Slate\Presentation\{Block,BlockRegistry,
   Renderer,Page,Section,LayoutSpec,RenderContext,FieldSchema}`. Pure, unit-tested.
2. **B2 — Document schema + normalizer.** Parse/validate document JSON; upconvert
   legacy flat array → implicit Section; `full_html` pass-through; version stamp.
   Unit-tested (fixtures incl. real `demo-landing.json` shape).
3. **B3 — Core renderer.** `PageRenderer` walking Page→Section→Block over an
   in-memory registry; one trivial built-in block for tests. Unit-tested (HTML out).
4. **B4 — Revision store.** `0004_content_revisions` migration + `RevisionStore`
   (snapshot / listForOwner / get / publish / working). MySQL-verified
   (migrate + rollback); integration-tested.
5. **B5 — content-builder bridge.** Adapter registering CB blocks into the core
   registry; revision snapshots on save/publish; opt-in core-render path behind a
   **parity gate** (byte-equal vs legacy `Renderer` for existing pages).
   Integration + parity tests. No visual UI (that's 3C/3E).

## 7. Non-goals for 3A (deferred, per roadmap)

Visual/drag UI (3C/3E), Theme/Component layer & `--slate-*` tokens (3B), SEO
scoring/sitemap (3D), core `content_pages` store (Option B), relational
section/block tables, `rx-*`/`sb-*` deduplication into modules.

## 8. Open questions for review

1. **§5 decision:** Option A (adapter + revisions) vs Option B (core `content_pages`
   now)? — *recommend A.*
2. **Revision granularity:** snapshot on every `savePost`, or only on publish +
   explicit "save draft"? (Every-save is simplest and safest; can prune later.)
3. **Contracts location:** keep the Block/Renderer interfaces under
   `Slate\Presentation\*`, or promote `Block`/`BlockRegistry` into `Slate\Contracts\*`
   (capability interface) with value objects staying in `Presentation`? The spec
   text lives in Presentation; platform-foundation §8 treats "the Block/Component
   contract" as Presentation's public surface — *lean: keep in Presentation.*
