# Kohevo Studio — Phase 6: Templates, Global Components and Design System

Status: implemented (Phase 6). Records the architectural decisions Phase 6
establishes on top of the Phase 1–5 model, and the contracts the builder UI
relies on. No schema change was made: every Phase 6 concept is expressed
with the seven existing `studiobuilder_*` tables.

## 1. Three concepts, three mechanisms

| Concept | Storage | Semantics | Reference recorded? |
|---|---|---|---|
| **Local content** | the page's own revision | the page owns its sections and blocks | — |
| **Reusable preset** (template) | `studiobuilder_templates` (`page_template`, `section_preset`, `header_preset`, `footer_preset`, `block_preset`) | inserting/applying **copies** canonical content into the page; every id is re-minted (`Document\DocumentCopier`); the page then owns the copy | no — a copy is independent; only the document-level `template_key` (pre-existing) is indexed as `partial` `template:{key}` |
| **Global component** | a **page** of type `section_preset` (`studiobuilder_pages` + its revisions) | a page section holds `global_ref = <component page uuid>` and **owns no blocks**; the renderer embeds the component's **published** revision | yes — `studiobuilder_dependencies` (`partial`, key = the uuid) on every revision of the consuming page |

The distinction is enforced server-side, not by UI: `DocumentValidator`
rejects a referencing section that carries local blocks
(`global_ref_owns_no_blocks`), a reference that does not resolve to a
non-archived component of the active tenant
(`cross_tenant_or_missing_partial`), and a reference inside a component,
header or footer document (`global_ref_not_allowed` — references are one
level deep by construction, so there are no cycles and no recursive
resolution). A reusable preset can never store a live reference
(`cannot_preset_reference`).

## 2. Global component decision (preflight #2)

The existing model was found sufficient; **no `global_components` table, no
new column, no encoding of identity into names**:

| Requirement | How the existing model provides it |
|---|---|
| stable identity | the component page's immutable `uuid` (unique per tenant); `COMPONENT_REF_PATTERN` is a v4 uuid, a subset of the pre-existing `GLOBAL_REF_PATTERN` |
| canonical content | the component page's revisions (`document_type = section_preset`) |
| reusable references | the pre-existing `section.global_ref` |
| revision history / conflict handling | `studiobuilder_revisions` + `expected_revision_id`, unchanged |
| publish behaviour | `published_revision_id`; consumers render the published revision only — in public output **and** in preview/editor, exactly like Phase 4 header/footer partials |
| tenant isolation | every lookup is a tenant-scoped repository call (`PageRepository::findComponentByRef`) |
| dependency tracking | `DependencyExtractor` already emitted `partial` for `global_ref`; the key is now the uuid |
| update propagation | `StudioCompilationInvalidator::invalidateComponent()` on publish/archive of the component + the component's published revision id in the compile fingerprint (`StudioCompiler::plan()` → `inputs.components`) |
| deletion/archive protection | `archivePage()` refuses while any page's current draft or published revision references the component (`DependencyRepository::currentDependentPageIds`) |
| builder editing | the Phase 5 builder opens a component like any page (bare render, no chrome) |
| public rendering | `DocumentRenderer::renderGlobalSection()` embeds the prepared published sections inside `<section class="sb-section sb-section--global">` |
| recompilation of dependents | dropped artifacts recompile from the published revision on the next request (Phase 4 model) |

`section_preset` is the name the Phase 1 schema gave both the page type and a
template type; the concepts are kept apart in code by
`CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE` (page) vs
`StudioTemplateService::INSERTABLE_TYPES` (templates).

## 3. Template application concurrency (preflight #1)

`StudioTemplateService::applyTemplate()` used to read the page's current
draft pointer itself and pass it as the expected revision, so "apply" was
the only mutation that could overwrite a newer draft. It now takes the
caller's `expected_revision_id` and hands it to the single existing check
(`StudioRevisionService::createDraftRevision()` under the page row lock).
`StudioApplicationService::applyTemplate()` requires the parameter; the
builder API (`apply_template`) refuses a request without it (422
`required_field`), and a stale or null value is the ordinary 409
`concurrency_conflict` that puts the builder in its conflict state. No
second concurrency model exists.

## 4. Command / query API additions (`Http\StudioAuthoringApi`)

Queries (GET): `components`, `chrome` (page), `tokens` (group).
Commands (POST, CSRF, same-origin, JSON): `apply_template`,
`insert_template`, `save_template`, `delete_template`, `create_component`,
`detach_component`, `save_tokens`. Every document write carries
`expected_revision_id`. `save_template` reads the content from the stored
working revision (page / section / block by node id) — the client never
supplies a document. Views (`StudioEditorViews`) stay allowlists; a
component view exposes the page uuid as `ref` (the value a section stores),
a template view carries a structural summary and the thumbnail URL resolved
through the tenant-scoped media resolver — never a document body.

Builder (`plugins/studio-builder/ui`): a third left tab **Library**
(templates: apply / insert copy / save / delete; global components: insert
reference / edit / publish), a "Global component" section inspector
(publish state, edit link, detach → owned copy, visibility only), chrome
bindings in the page inspector (what `inherit`/`custom`/`hidden` resolve
to, with edit/create links for the `default` and page-specific partials),
and a **Theme** dialog for design tokens. One-step server rewrites
(`SyncEngine.command`) drain pending edits, send `expected_revision_id`,
replace the base with the server document, and enter the conflict state on
409 — the Phase 5 editing model is unchanged.

## 5. Header / footer bindings

Rendering rules are the Phase 4 `ChromeResolver` rules, unchanged: `hidden`
→ no region; `inherit` → the published `header_partial`/`footer_partial`
whose slug is `default`, else built-in chrome; `custom` → the published
partial whose slug equals the page's slug, else `inherit`. Phase 6 adds
only a read query (`chromeBindings`) and invalidation: chromed documents
now record `partial` dependencies `chrome:header` / `chrome:footer` unless
the region is hidden, and publishing or archiving a partial calls
`invalidateChrome(region)` — pages with the region hidden are not rebuilt.
The published partial's revision id was already in the compile
fingerprint and remains the exact guard.

## 6. Design tokens

`Service\StudioThemeService` is the only writer of `studiobuilder_tokens`.
The runtime order is untouched: neutral defaults → tenant branding
(`TenantBranding`, the existing Settings › Branding values) → stored
Studio tokens. Only the platform-defined refs
(`ThemeResolver::DEFAULT_TOKENS`) can be stored, and only values that pass
`ThemeResolver::sanitizeValue()` for the token's category; unsafe values
are rejected on write and would be dropped again on read. There is no raw
CSS field and no `<style>` input; tokens only become `--sb-*` custom
properties. Tenant theme settings reuse the existing branding settings —
no second settings system. Saving tokens needs `studio-builder.tokens`
and invalidates the tenant's artifacts (`invalidateTokenGroup`).

Platform identity: the signature slot (`.sb-platform-signature`) is styled
with fixed values, never `var(--sb-*)`, and is rendered outside every
tenant region by `PageDocumentAssembler` as before; `PlatformSignature` /
`PlatformIdentityPolicy` are not touched.

## 7. Invalidation refinement

`StudioCompilationInvalidator::invalidateDependency()` now consults only
pages whose **current draft or published** revision carries the dependency
(`currentDependentPageIds`). A compiled artifact always belongs to the
published revision, so historical revisions can never own one; consulting
them only rebuilt unrelated pages (a page that once showed a header and now
hides it). Callers and the dependency index are unchanged.

## 8. Transaction rule (verified in the browser, not in tests)

The platform's `AuditLog::record()` resolves the session user through
`Auth::check()`, whose session repository runs a lazy
`CREATE TABLE IF NOT EXISTS` — a statement MySQL treats as an implicit
commit. Inside an application-owned transaction that turned the final
`commit()` into "There is no active transaction" (a 500 in the builder,
invisible to the test suites because they have no web session).
`StudioApplicationService` therefore never audits, and never calls an
audited command, inside a transaction it owns (`mutateDocument()` is the
un-audited pipeline; audit follows the commit). A unit test guards the rule
statically.

## 9. Remaining risks / not in scope

- Design-token writes are whole-group replacements without optimistic
  concurrency (tokens are not documents; last write wins, audited).
- No system templates are seeded yet; the protection (`is_system`) is
  enforced server-side on write and delete.
- There is no CI check for drift between `ui/src` and the committed
  `assets/builder` bundle (pre-existing).
