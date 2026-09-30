# Kohevo Studio — Phase 8A: JSON Package Export / Import

Status: implemented (Phase 8A only). Phase 8B (constrained HTML/CSS import)
and 8C/8D/8E (JSX/React/v0, ZIP, Figma) are **not** implemented. No MCP
import/export tool exists. No schema change: every Phase 8A write goes
through the existing canonical services into the seven existing
`studiobuilder_*` tables.

## 1. Where it sits

```
HTTP  admin/api.php → StudioAuthoringApi   export_package (GET)  import_package (POST, dry_run: true|false)
        transport: session actor, CSRF + Sec-Fetch-Site, JSON content type, 1.5 MB body,
        per-session rate limit (+ a 30/min package bucket), strict field allowlist
  → StudioApplicationService::exportPackage()   authorize(view)            → audit studio.package.exported
  → StudioApplicationService::importPackage()   authorize(edit), AI origin refused
        → StudioPackageService::plan()   (the dry run; also the first half of every commit)
        → [commit only] ONE transaction owned by the application service:
              StudioPackageService::commit() → StudioThemeService::saveTokens()
                                             → StudioPageAddressService::createPage(kind import)
                                             → StudioRevisionService::createDraftRevision(kind import)
                                             → StudioTemplateService::saveTemplate()
        → after commit: invalidation + audit (studio.*.imported, studio.package.imported)
```

`Application\StudioPackageService` is built only by `StudioRuntimeFactory`
and called only by `StudioApplicationService` (a unit test pins this). It
never authorizes, audits, opens a transaction, writes a table, publishes or
makes a network request. `Package\StudioPackageFormat` (envelope),
`Package\PackageDocumentMapper` (ids, references, media) and
`Package\StudioImportReport` (report) are pure.

Two validate-only extractions let the dry run apply the exact write-path
rules without writing: `StudioTemplateService::validateTemplate()` and
`StudioThemeService::validateTokens()` (the save methods call them; no rule
changed).

## 2. Package format "1.0"

```json
{ "package_format": "kohevo-studio-package", "package_version": "1.0",
  "exported_at": "2026-09-30T12:00:00Z", "items": [ … ] }
```

| kind | keys (all required, nothing else allowed) |
|---|---|
| `page` | kind key title slug page_type route_mode document media content_hash |
| `global_component` | kind key source_ref title slug document media content_hash |
| `template` | kind key template_key template_type category name description document media content_hash |
| `tokens` | kind key token_group tokens content_hash |

Media descriptor: exactly `{key, path, mime}` — `key` is local to the item,
`path` is a site-relative `/uploads/…` path (or an external reference, which
is never fetched). Unknown top-level keys, unknown kinds, unexpected item
keys, `tenant_id` at any depth, filesystem paths/traversal and more than 25
items are rejected. Documents inside are the canonical Studio document
(no second serialization); media ids inside them are the item's local keys.

`content_hash` = sha256 of the item's stable encoding (keys sorted, lists
kept, no zero-fraction floats — stable across a browser JSON round trip).
It detects accidental or naive modification; it is **not** a signature and
grants no trust — everything is validated on import regardless.

## 3. Export (studio-builder.view)

Page-oriented: the page's current working draft; optionally the Global
Components it references (their published revision, else draft), the
non-default template it names, and the **stored** token overrides of its
token group. Media ids are renumbered to local keys with `{path, mime}`
descriptors; no binary, database id, page uuid, revision id, user id or
tenant id is emitted. A `section_preset` page exports as one
`global_component` item. Audited (`studio.package.exported`, package hash).

## 4. Import

Modes: `create` (new draft pages; an existing slug is a `route_collision`,
never an overwrite) and `replace_draft` (one page item onto an explicit
target page, **`target_page_id` + `expected_revision_id`**; stale → 409; the
published revision is never touched). The target is addressed by page id
resolved tenant-scoped, like every builder command, because the builder
deliberately never receives page uuids (`StudioEditorViews::page()`,
pinned by the Phase 5 tests). There is no append mode.

Identity: new pages/components get fresh uuids from `createPage()`; every
section, block and nested child id is re-minted (the package's ids are never
trusted, even when they collide); component `source_ref`s are rewritten
through an explicit source → target map.

Order: tokens → global components → templates → pages. Components are
planned with deterministic placeholder refs; at commit the placeholders are
replaced by the created uuids and every document is **re-validated with the
real tenant-scoped checks** before it is written.

Revision kind: every draft written by an import is `import`. The kind is
reachable only through `importPackage()` (`IMPORT_REVISION_KIND`); editor
saves still accept only `autosave`/`manual` and `draftKind()` is unchanged;
an AI-origin actor cannot import at all.

### Media (no binaries, no foreign ids, no fetching)

A reference resolves through, in order:
1. the caller's explicit `media_map` (`/uploads/… path → media id`), verified
   to be an image of **this** tenant (else an error);
2. a tenant-local managed media row with the same `/uploads/` path and mime;
3. otherwise it is **downgraded**, with an `unresolved_media` warning:
   - `seo.og_image_media_id` → `null`
   - an optional `media_ref` field (e.g. `core.hero.media`) → `null`
   - a block with a required `media_ref` (e.g. `core.image`) → the block is
     omitted (at any depth).
No media row is created, no URL is stored (`media_ref` holds only a tenant
media id), and a foreign integer id is never used. Full cross-tenant media
fidelity requires an explicit `media_map`.

### References

- Global component: in the package → the new component; in `component_map`
  → verified tenant-local target; otherwise the section becomes an empty
  owned section (`unresolved_global_component` warning). A component may
  not contain a reference. **Imported components stay drafts**; references
  to them render inert until a person publishes the component (reported as
  `component_unpublished`).
- Template key: a template in the package, else an existing template of
  this site, else `default` (`unresolved_template` warning).
- Templates: key kept only when free (`template_collision`); a system
  template is never touched (`system_template_protected`); requires
  `studio-builder.admin` (the existing template boundary).
- Tokens: ignored unless `include_tokens` (`tokens_skipped`); then require
  `studio-builder.tokens`, pass the theme panel's sanitization, and — since
  tokens have no draft state — replace the group's overrides **live**
  (`tokens_replace_live` warning).
- Pages: reserved public routes are refused for `page`/`landing`
  (`reserved_route`) using `StudioReservedRoutes`.

### Dry run and report

`dry_run: true` runs the same plan with deterministic id minting and writes
nothing (no page, revision, template, token, dependency, media or audit
row). The report (`ok, dry_run, source_kind, mode, package_hash, valid,
can_commit, summary, issues, dependencies, planned_actions,
preview_documents, committed`) is byte-for-byte reproducible for the same
package and options. Stable issue codes: `invalid_package,
unknown_package_key, unknown_item_type, forbidden_tenant_id,
invalid_document, unknown_block_type, invalid_block_version,
unresolved_media, unresolved_template, unresolved_global_component,
unentitled_module, route_collision, reserved_route, template_collision,
system_template_protected, permission_denied, concurrency_conflict,
target_not_found, page_type_mismatch, invalid_tokens, tokens_skipped,
tokens_replace_live, component_unpublished`. A commit whose plan has any
error is refused (422 with the report); nothing is written.

## 5. Builder UI

TopBar "Import / Export" → `PackageDialog`: Export (download) and Import
(file → **Analyse (dry run)** → **Import into draft**, enabled only when
the analysis of exactly the current inputs says `can_commit`). Replace mode
runs through `SyncEngine.command()` (pending edits saved first,
`expected_revision_id` carried, a 409 enters the conflict state without
losing local edits, the imported draft is adopted by the editor).
