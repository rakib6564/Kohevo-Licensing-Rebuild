# ADR-0013 — The content document and the content API

**Status:** Accepted   **Date:** 2026-08-28

## Context

Four things in Slate currently claim to model "a page":

1. **`content-builder`** stores `contentbuilder_posts.layout` — a flat JSON array
   of `{type, props}` blocks — and renders it to HTML.
2. **`src/Presentation`** holds `DocumentSchema`, a versioned document envelope
   with `Page` / `Section` / `Block`, a `PageRenderer`, and `content_revisions`.
   It was built for exactly this problem, is bridged to content-builder through
   `ContentCoreBridge`, is parity-gated, and was deliberately never cut over.
3. **`react-site-bridge`** stores `reactsitebridge_documents.document_json`, keyed
   by `(site, route, locale)` with a `schema_key`, plus opaque `manifest_json`
   revision snapshots.
4. **`small-business-kit`** owns the theme engine, so "how the site looks" has two
   owners (see [ADR-0008](0008-one-design-token-vocabulary.md)).

The consolidation plan proposes one engine that renders HTML *and* React from one
document. That is the right shape. But it also proposes building the schema on
`react-site-bridge`'s manifests, which is the weaker of the two document models,
and it does not say where addressing lives.

Two facts from the source constrain the answer:

- **Blocks already declare typed field schemas.** All 19 registered types carry a
  `fields` definition, so a block's props are already structured data rather than
  markup. A second renderer needs a component per type, not a new content model.
- **`DocumentSchema` normalizes idempotently and deterministically** — section ids
  are filled positionally, never randomly — because revision diffing and render
  caching depend on re-normalizing to byte-identical output.
  `react-site-bridge` offers no equivalent guarantee.

## Decision

**1. `Slate\Presentation\DocumentSchema` is the document contract**, and it is
frozen at **version 1 as it stands**. It already reads today's flat layouts
back-compatibly, so no stored row is rewritten and no version bump is required.
`react-site-bridge` documents migrate *into* this shape; its manifests are not
the base.

**2. Addressing lives on the row, never inside the document.** `site`, `route`,
`locale`, `slug`, `type` and `status` are indexed columns. The document stays
content-only: `{schema, type, template, sections[], seo{}}`.

**`site` is ratified, not introduced.** Tenancy already exists and
`react-site-bridge` is already multi-site with a `site_id` on every row. This
records what is built rather than adding capability.

**`locale` stays as an addressing column with no translation UI.** It is already
indexed. Carrying it costs a column and removes a future re-keying migration of
every content row; dropping it would have to be undone the first time a second
language appears. "Not yet, but the column stays" is the decision, and it is
deliberate rather than deferred.

Putting the route inside the JSON would create two sources of truth that drift,
make lookups unindexable, and break deterministic normalization the moment a row
is moved. `type` appears in both only because the envelope carries it for
rendering; the row remains authoritative.

**3. The content API returns an envelope, not a bare document:**

```
GET /api/content/{route}?locale=en

{ "address":  { "site", "route", "locale", "type", "slug", "status" },
  "document": { …DocumentSchema envelope, verbatim… },
  "theme":    { "slug", "tokens": { "--slate-…": "…" } },
  "meta":     { "revision", "updated_at", "schema": 1 } }
```

Addressing is echoed so a consumer never has to parse it back out of the URL.
The document is passed through untouched, so the HTML and React renderers are
provably reading the same bytes.

**4. A block is the unit of dual rendering.** A block declares typed fields; each
renderer maps `type` to an implementation. Props are data and never markup, so
adding a renderer never touches content.

**Media is referenced by logical key, not by URL.** An image-bearing block stores
`{ "media": { "key": "hero.primary", "alt": "…", "focal": [x, y] } }` and the
renderer resolves the key to a URL through the existing media mapping. This is
the one genuine shape commitment in this ADR, and it is made now because it
constrains every image block written from here on.

URLs move — a CDN changes, an install moves under a sub-path, a React host serves
assets from somewhere the PHP renderer does not know about. A key does not, which
is precisely what lets one document render correctly under two renderers on two
hosts. It also folds in `react-site-bridge`'s logical-key model with its alt text
and focal point rather than discarding it.

Raw-URL props (`src`, `image` holding a path) are **deprecated**: still read for
backward compatibility, never written by new code, and resolved through the same
path so a document mixing both renders identically.

**5. Two escape hatches, both explicit.** `html` carries raw markup and stays
permission-gated behind `content.publish`, enforced server-side on save with
grandfathering; any React path must carry that authorization, not merely sanitize
output. `react` names a registered component plus props; the PHP renderer emits a
declared server-side fallback rather than nothing.

**6. Theme values are emitted as `--slate-*`**, per
[ADR-0008](0008-one-design-token-vocabulary.md). The `--cb-*` and `--sb-*`
vocabularies become **temporary aliases** generated from the same values.
ADR-0008 rejected a permanent mapping layer, so the alias layer needs an actual
box rather than the word "temporary".

**Phase E — token unification.** Definition of done: one `--slate-*` vocabulary,
both the `cb-*` and `sb-*` block families migrated to consume it, and the alias
layer deleted.

Phase E cannot start before the renderers land (block CSS is what migrates) and
may not be deferred past the consolidation's completion — the consolidation is
not finished while two token vocabularies exist. Deleting the alias layer is the
last commit of the project, not a follow-up someone schedules later.

**7. Published releases are a layer above the document, not part of it.** A
Slate-hosted static release is a snapshot of documents at particular revisions:
it references `(document_id, revision_id)` and adds hosting metadata. It changes
neither the document envelope nor the content row, so it is explicitly out of
scope for this contract and does not gate it. `reactsitebridge_hosted_releases`
stays as it is until the release layer is designed on its own terms.

## Alternatives considered

- **Base the schema on `react-site-bridge` manifests** (as the plan proposes).
  Rejected: it would rebuild the envelope core already has and lose the
  deterministic-normalization property that revision diffing depends on. Its
  genuinely valuable parts — revisions, publish flow, media mapping — are
  behaviour we keep; its storage shape is not.
- **Put `route` and `locale` inside the document.** Rejected: two sources of truth
  for a row's identity, unindexable lookups, and normalization that stops being a
  pure function of content.
- **Return a bare document from the API.** Rejected: every consumer would
  reconstruct addressing and fetch the theme separately, making a page render two
  round trips and inviting a document/theme mismatch.
- **Bump the schema to v2 to add addressing fields.** Rejected: nothing about
  rendering HTML or React needs a shape change. A version bump with no semantic
  change costs a migration and buys nothing.
- **Keep `--cb-*` / `--sb-*` permanently and map between them.** Rejected by
  ADR-0008 before this consolidation began.

## Consequences

- **Positive:** Phase B is mostly ratification rather than construction — the
  envelope, the normalizer, its tests and the revision store already exist.
  "HTML vs React" becomes an output choice. Renderers and the builder can be
  developed in parallel against fixtures once this is frozen. Adding a third
  renderer later touches no content.
- **Negative / accepted trade-offs:** `react-site-bridge` documents need a real
  migration into the block-document shape, and any `schema_key` that does not map
  cleanly needs a decision per shape. The alias layer means two token
  vocabularies coexist for the duration of the migration — acceptable only
  because it is time-boxed by point 6.
- **Resolved on acceptance (2026-08-28).** Multi-site: ratified, already built.
  Locale: kept as an addressing column, no translation UI. Hosted releases: out
  of scope by construction (point 7), so no longer gating. Media keys: folded in
  (point 4), which is the single real shape commitment here and the reason this
  ADR is worth freezing rather than leaving open.
- **The freeze is only as good as its expiry.** Point 6 gives the alias layer a
  numbered phase and a definition of done precisely because a "temporary"
  migration step with no box is how a freeze thaws.

## Addendum — dynamic blocks in the content API

**Status:** Accepted   **Date:** 2026-08-28

Some blocks are not pure functions of their props. `post-list` queries published
posts by type, taxonomy term and limit; its output depends on the database, not
only on the document.

The PHP renderer can query. The React renderer must not: it runs in a browser or
a Node host with no database, and giving it one would make "one document, two
renderers" false — the two would agree only where the second happened to have
the same data.

**The API resolves dynamic blocks server-side and carries the result beside the
document.** The envelope gains a fifth member:

```
{ "address": {…}, "document": {…}, "theme": {…}, "meta": {…},
  "resolved": { "s1.2": { "items": [ … ] } } }
```

**Resolved data is keyed by position, not embedded in the block.** The key is
`<sectionId>.<blockIndex>` — `s1.2` is the third block of the first section.

Embedding the result inside the block's props would have been simpler to consume
and would have broken §3: the document would no longer travel verbatim, and the
two renderers would no longer provably read the same bytes. A sibling map keeps
that property intact.

Position works as an identifier because normalisation is deterministic
([§1](#decision)): section ids are filled positionally and re-normalising yields
byte-identical output, so the same document always produces the same keys. Adding
ids to blocks would have been the other option, and it is rejected for the same
reason a version bump was — it changes the frozen shape to solve a problem the
existing guarantees already solve.

**A consumer that ignores `resolved` still renders.** A dynamic block with no
entry renders its empty state, exactly as the PHP renderer does when a query
returns nothing. That keeps the member additive: an existing consumer written
against the four-member envelope does not break.

**Resolution runs with the caller's permissions and only publishes what the
public renderer would.** `post-list` resolves through `ContentBuilderAPI::listPosts`
with `status => published`, so the API cannot become a way to read drafts that
`GET /api/content/{route}` already refuses to serve.

This extends what the API returns; it changes neither the document envelope nor
the content row, so the schema stays at version 1.

## Related

- [ADR-0007](0007-section-block-before-page-builder.md) ·
  [ADR-0008](0008-one-design-token-vocabulary.md) ·
  [ADR-0009](0009-semver-sdk-bc-policy.md) ·
  [ADR-0010](0010-migrations-over-ensureschema.md)
