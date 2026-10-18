# Kohevo Studio Builder — templates and reusable assets: lifecycle, import and export (read-only audit)

Tree: `wt-final/01-client` at `71d4e83`. Source reading only; nothing was executed. Paths are relative to the tree.

## 1. Lifecycle map

There are **two separate template implementations**. Most problems sit in the second one.

**A. The canonical path.** `StudioApplicationService` -> `StudioTemplateService`, reached through the builder JSON API at `plugins/studio-builder/admin/api.php` -> `Http/StudioAuthoringApi.php`.

**B. The legacy Theme Builder admin screen.** `plugins/studio-builder/admin/templates.php` writes raw SQL directly against `studiobuilder_templates`, `studiobuilder_pages`, `studiobuilder_revisions` and `studiobuilder_compilations`.

| Operation | Canonical path (A) | Admin screen (B) |
|---|---|---|
| Create | `saveTemplate` / `saveTemplateFromPage`. Requires **ADMIN** (`Application/StudioApplicationService.php:350-404`). Template fields are validated (`Service/StudioTemplateService.php:192-241`): key pattern, type in `StudioTemplate::ALLOWED_TEMPLATE_TYPES`, slug category, name without `<>` and at most 191 chars, description at most 1000 chars, thumbnail must be tenant media. The document goes through full `ValidatedDocument::from` validation plus preset shape rules (`:237-238,517-535`). Content is read from the stored revision, never from the client (`StudioApplicationService.php:390-403`). | `create_template` requires only **EDIT** (`templates.php:23,92-301`). It does raw inserts with no `ValidatedDocument` pass and no name validation. UUIDs come from `mt_rand` (`:213-219`). The template key has a 16-bit random suffix (`:110`). No dependency rows are written. Exception text is echoed (`:293,299`). |
| Edit | `saveTemplate` is an **upsert by key**: last write wins, there is no version or concurrency check (`StudioTemplateService.php:97-119`). System rows are immutable (`:98-102`). Pages that name the template are invalidated (`StudioApplicationService.php:365`). | The template row is never edited. The backing page is edited in the canvas (normal draft and publish). |
| Duplicate | No template duplicate operation. Pages and partials are duplicated with `duplicatePage` (EDIT): the copy is a new draft, unpublished and validated (`StudioApplicationService.php:233-257`). | None. |
| Rename | No explicit rename. The only way is a `saveTemplate` overwrite under the same key, which needs the content again (from a page). The key is immutable. | None. Renaming the backing page's title changes conditional tie-breaks (see theme report TB-10). |
| Delete | `deleteTemplate` requires ADMIN. It is refused for system templates and for templates still named by a page (`template_in_use`), and it is audited (`StudioTemplateService.php:250-272`; `StudioApplicationService.php:409-415`). | `delete_template` requires EDIT. It locates the backing page with a **LIKE prefix match** and **hard-deletes** that page, its revisions and compilations. There is no in-use check and no audit (`templates.php:330-347`). |
| Apply / insert | `applyTemplate` (EDIT, expected revision mandatory) replaces the whole working document, **settings included** (`StudioTemplateService.php:295-324`). `insertTemplate` (EDIT) inserts a copy with fresh ids through the operation pipeline (`:341-381`; `Document/DocumentCopier.php:31-63`). | n/a |
| Publish / unpublish (backing page) | `publish` requires PUBLISH and runs in one transaction with compile (`StudioApplicationService.php:884-936`). There is no unpublish command (`StudioRevisionService.php:21` mentions `unpublishPage()`, which does not exist). | `publish_template` correctly calls `app->publish`. `unpublish_template` is a raw `UPDATE … status='draft', published_revision_id=NULL` on **any** page id of the tenant, with EDIT only and no audit (`templates.php:320-329`). |
| Export | `export_package`: GET, VIEW (`StudioApplicationService.php:963-975`; `StudioPackageService.php:117-179`). | none |
| Import | `import_package` / `import_html`: POST, EDIT. Templates need ADMIN, tokens need TOKENS (`StudioPackageService.php:333-392`). | none |

Global components are `section_preset` pages referenced by uuid, so they share the page lifecycle. Archiving a referenced component is refused (`StudioGlobalComponentService.php:75`; `StudioApplicationService.php:209-224`). System section presets are seeded idempotently as `is_system=1` rows on the first `section_presets` call, a VIEW/GET path that writes (`StudioApplicationService.php:459-470,570-582`; `StudioTemplateService.php:139-179`).

## 2. Import and export security review

### 2.1 Transport, CSRF and permissions

- **POST only**, with all of the following checked in `StudioAuthoringApi::dispatch()` (`Http/StudioAuthoringApi.php:201-236`):
  - authenticated session;
  - `Sec-Fetch-Site` must be same-origin or none (`:216-218`);
  - `csrf_verify()` token valid (`:219-221`; `admin/api.php:50-53`);
  - `Content-Type: application/json` (`:222-224`);
  - body at most 1.5 MiB, i.e. 1,572,864 bytes (`:51,226-228`);
  - `json_decode` depth 64 (`:53,847`);
  - unknown request fields rejected (`:238,857-866`);
  - per-action rate limit (`:234-236`). The limit runs *after* the body is decoded (Info).
- The package actions have their own fields (`:103-106`). `dry_run` must be an explicit boolean (`:503-511`). `replace_draft` needs `target_page_id` and `expected_revision_id` (`:520-534`).
- Permission order inside the application layer: tenant -> authentication -> entitlement -> EDIT (`StudioApplicationService.php:987-1004`). AI origins cannot import (test "phase8a int 9").
- **Admin-screen destructive actions** (`templates.php`) check `csrf_verify()` (`:85`) but **do not** use the Studio RBAC levels. See TI-01.

### 2.2 Envelope and schema validation, versioning

`StudioPackageFormat::validate()` (`Package/StudioPackageFormat.php:83-164`):
- **Envelope.** The package must be an object with exactly `package_format`, `package_version`, `exported_at` and `items`; unknown keys are refused (`:97-101`). `package_format` must equal `"kohevo-studio-package"` and `package_version` must equal `"1.0"`, with no range accepted (`:102-107`). `exported_at` must be a UTC timestamp.
- **Items.** At most 25 items (`:47,117-120`). Each kind has an **exact key set** (`:59-64,143-152`), and item keys are unique slugs. String fields are plain text with no `<>` or control characters (`:176`). `page_type` must be in `page`, `landing`, `system`, `header_partial` or `footer_partial` (`:69,186-189`). `route_mode` must be one of the allowed modes.
- **Media descriptors.** Each is exactly `{key, path, mime}`, at most 250 per item (`:231-258`). The path must be `''`, an external reference, or `/uploads/…`, with no `..`, no `\` and no control characters. The mime value must match a media-type pattern.
- **Tokens.** At most 64 per item, with each ref matching `^[a-z][a-z0-9_.]{0,63}$` and each value a string of at most 200 characters (`:211-227`). Values are then checked again by `StudioThemeService::validateTokens` (`StudioPackageService.php:381-388`).
- **`content_hash`** (sha256 over a stable encoding) detects tampering only; it is not a signature (`:30-32,156-161,301-305`).
- **Documents.** Each embedded document is re-validated by `ValidatedDocument::from()` in the plan and again at commit (`StudioPackageService.php:653-660,732-740`):
  - `schema_version` must be exactly `"1.0"` (`Document/DocumentValidator.php:107-115`);
  - unknown properties are refused at every level (`:94-97,240,…,707`);
  - a block type that is unknown or unregistered gives `unknown_block_type` (`:827`);
  - a block version must equal the registry version (`:845-852`);
  - an unentitled module block gives `block_module_not_entitled` (`:831-836`);
  - document limits: 1 MiB (`CanonicalDocumentSchema.php:18`; array path `DocumentValidator.php:69-80`), 50 sections, 250 blocks, nesting depth 6, plus string length caps.
- **Versioning and migration:** none. There is one package version and one schema version, and block versions must match exactly. An export made before any block version bump becomes **unimportable** (it fails closed with `invalid_block_version`). See TI-07.

### 2.3 Malformed, oversized and malicious input

| Vector | Behaviour (evidence) |
|---|---|
| Oversized body | 413 above 1.5 MiB (`StudioAuthoringApi.php:226-228`). Each document is capped at 1 MiB (`DocumentValidator.php:76-80`). |
| Deep nesting | `json_decode` depth 64 raises a JSON error (`StudioAuthoringApi.php:847`). Block nesting is capped at 6 (`DocumentValidator.php:685-687`). `forbiddenTenantPaths` recursion is bounded by that depth (`StudioPackageFormat.php:274-291`). |
| Unknown keys | Rejected in the envelope, items, media descriptors and documents (above). |
| `tenant_id` injection | Rejected at any depth of the package (`StudioPackageFormat.php:94-96,274-291`) and of the document (`DocumentValidator.php:86-87,207-219`). Repository inserts force the session tenant (`Repository/StudioRepository.php:5-11`). |
| Script / HTML injection | Package strings exclude `<>` (`StudioPackageFormat.php:176`). URLs must pass `FieldSchema::isSafeUrl`: no `javascript:`, `data:`, `vbscript:`, `file:`, `blob:` or `about:` schemes, no `//` protocol-relative URLs, no control characters (`Schema/FieldSchema.php:259-305`). `containsExecutableOrSqlFragment` rejects `<script`, `<?php`, `eval(` and `expression(` (`:311-331`). Rich text goes through `RichTextSanitizer` (not re-read in this audit). There is no raw-HTML block type in the registry (block list from `src/Module/StudioBuilder/Registry`). |
| HTML import | Runs only after authorization (`StudioApplicationService.php:1019-1048`). Raw caps of 512 KiB HTML and 128 KiB CSS, at most 5000 elements, depth 64, plus attribute caps (`Package/Html/HtmlSourceReader.php:43-52`). Parsed with `loadHTML(..., LIBXML_NONET)`, never `NOENT` or `PARSEHUGE` (`:15-16`; `HtmlImportConverter.php:13,47-51`). CSS refuses `url(`, `expression(`, `javascript:`, `@import`, `image-set(` and `element(` (`HtmlCssParser.php:300`). The IR is capped at 2000 nodes, depth 8 and 50,000-character strings (`HtmlImportIr.php:33-36`). |
| URLs and media | No binary is ever transferred and no URL is fetched. A media reference resolves through (1) the explicit `media_map`, verified as an **image** in this tenant (`StudioPackageService.php:615-625`), then (2) the same `/uploads/` path and mime in this tenant (`:626-633`). Anything else is **downgraded**: the OG image becomes null, optional media becomes null, and a block whose media is required is omitted, with an `unresolved_media` warning (`:634-645`; `PackageDocumentMapper::rewriteMedia`). External references are allowed in descriptors but never stored. |
| Foreign ids | Section, block and child ids are always re-minted (`StudioPackageService.php:648`). Component `source_ref`s are package-local and rewritten through the package or an explicitly verified `component_map`; otherwise the section becomes an empty owned section (`:303-311,576-584`). |
| Collisions | Create mode never overwrites: a page or component slug collision is an error, as is a template key collision (system template -> `system_template_protected`) (`:324-352,440-458`). Collisions are re-checked at commit (`:725-728`). Reserved routes are refused for public page types (`:443-445`). |
| Missing block types / unsupported controls | Fail closed: the whole import is refused with validator errors in the report. There is no placeholder or partial import of an unknown block. Unentitled module blocks also fail (test "phase8a int 7"). |
| Global style / token references | Document style values may only be symbolic token refs (`CanonicalDocumentSchema::TOKEN_REF_PATTERN`, `:311`). Token *values* are imported only with `include_tokens=true` and TOKENS permission, apply **live**, and are reported as such (`StudioPackageService.php:364-392`). A missing token group in the target is not reported as an error (resolution falls back at render: not verified). |
| Code execution | **None found.** Grep over `src/Module/StudioBuilder` finds no `eval`, `unserialize`, `create_function`, `exec`/`shell_exec`/`system`/`proc_open`, `extract`, or dynamic `include`/`require`. The only `require_once` calls use fixed paths (`Runtime/StudioHttpResponder.php:101`, `Admin/PageListView.php:29`). No `file_put_contents` or `move_uploaded_file` in the import/export path. The only `fwrite` is the payment throttle file (`Payment/PayThrottle.php:42`), which is not reachable from import. The variable static calls in `DocumentValidator.php:1134,1175` use fixed method maps. The architecture test confirms that package code makes no network request and writes no table directly (`tests/unit/StudioBuilderPhase8PackageTest.php:294`). |
| Publishing | Import never publishes. Every write is a draft revision of kind `import` inside one transaction, audited after commit (`StudioApplicationService.php:1071-1118`). **Caveat:** draft settings still reach the live site through the Theme Builder resolver (TI-02). |

### 2.4 Export

- `exportPage` (VIEW) exports the page's **working draft**, the referenced components (published revision, else draft), the non-default template it names (system templates included) and, on request, the stored token overrides (`StudioPackageService.php:117-179`).
- Database ids, uuids of the page, revision/user/tenant ids and binaries are all removed. Media becomes package-local keys with path and mime, and only managed `/uploads/` paths are kept (`:224-241`).
- The filename is `kohevo-studio-<slug>.json`, where the slug already matches `SLUG_PATTERN`.
- Every export is audited (`StudioApplicationService.php:966-974`).

## 3. Findings

| ID | Severity | Title |
|---|---|---|
| TI-01 | **High** | Admin Theme Builder screen bypasses Studio RBAC and the canonical services: create/delete templates and unpublish **any** page with edit-only |
| TI-02 | **High** | Template settings (conditions / template_type) brought in by import `replace_draft`, `applyTemplate` or rollback go live without publish |
| TI-03 | **High** | `delete_template` LIKE prefix match deletes another template's page and hard-deletes immutable revisions |
| TI-04 | Medium | `applyTemplate` copies template settings (conditions, template_type, category) onto the target page |
| TI-05 | Low | `saveTemplate` is a silent upsert: no version or concurrency check; no rename/duplicate commands |
| TI-06 | Low | Admin create path skips validation and leaks exception text; weak id generation |
| TI-07 | Low | No migration path for older packages or schemas: any block version bump makes earlier exports unimportable |
| TI-08 | Info | `section_presets` (VIEW, GET) writes system rows on first use |
| TI-09 | Info | Rate limiting runs after the JSON body is decoded |
| TI-10 | Info | `media_map` only accepts images |

### TI-01 — High — The admin screen bypasses RBAC and the canonical services

**Evidence**
- `plugins/studio-builder/admin/templates.php:23` (gate: `studio-builder.edit`).
- `:224-283`: raw template, page and revision inserts. Template rows are an ADMIN operation elsewhere (`StudioApplicationService.php:360-362`).
- `:320-329`: unpublish of an arbitrary `page_id` without PUBLISH and without audit.
- `:330-347`: raw delete without ADMIN, without the in-use check and without audit (the service version is `StudioTemplateService.php:250-272`).

**Impact**
- An editor can take any published page offline (homepage included), create templates, and destroy template pages and their history.

**Remediation**
- Re-implement every action through `StudioApplicationService`: `saveTemplate`, `deleteTemplate` and `publish`, plus a new `unpublishPage` that requires PUBLISH and invalidates and audits.
- Restrict `page_id` to the template's own backing page.

**Test**
- An actor with VIEW+EDIT POSTs `unpublish_template` against the homepage, then `create_template`, then `delete_template`. All three must be refused with no change in the database.

### TI-02 — High — Draft settings from import, apply or rollback reach the live Theme Builder

**Evidence**
- `StudioPackageService::commit` `replace_draft` -> `createDraftRevision` (`Application/StudioPackageService.php:734-735`). `createDraftRevision` rewrites `pages.settings_json` (`Service/StudioRevisionService.php:159-163`), and the resolver reads that column for conditions and role (`Theme/ThemeTemplateResolver.php:96-106,245-265`).
- `applyTemplate` (EDIT) and `rollback` (EDIT, `StudioRevisionService.php:382-386`) do the same.
- The import report says `publishes: false` (`StudioPackageService.php:463-464`), which is misleading for header partials, footer partials and template pages.

**Impact**
- An editor imports a package into a published header partial or template page, or applies a template to one. Live template selection changes without a publish. Root cause and full detail: theme report TB-01.

**Remediation**
- Resolve Theme Builder settings from the published revision.

**Test**
- Run `replace_draft` on a published header partial with different conditions. Before any publish, the public page must still show the old header selection.

### TI-03 — High — delete_template: wrong page, history destroyed

**Evidence**
- `templates.php:337`: `settings_json LIKE '%"template_id":<id>%'` (5 matches 50, 512, …), run even when no template row was found.
- `:339-342`: hard delete of `studiobuilder_revisions`, `studiobuilder_compilations` and `studiobuilder_pages`. This contradicts `RevisionRepository::delete()`, which throws ("immutable", `Repository/RevisionRepository.php:52-58`).
- `studiobuilder_dependencies` rows are left orphaned.

**Remediation**
- Use an exact link (decode and compare, or a dedicated column).
- Archive rather than hard-delete, and keep revisions.
- Go through `deleteTemplate`.

**Test**
- Templates with ids 5 and 50. Deleting 5 leaves page 50 and its revisions intact. Deleting a system template id touches nothing.

### TI-04 — Medium — applyTemplate copies template settings

**Evidence**
- `StudioTemplateService.php:312-314` takes the template document whole and overrides only `document_type`. `settings.conditions`, `template_type` and `category` come along, as do `seo`.
- Applying a template saved from a header partial or template page turns the target into a conditioned template. Through TI-02 that takes effect live at once.

**Remediation**
- Keep the target page's own `settings` (or at least `conditions`, `template_type` and `category`) and `seo` when applying.
- Alternatively, strip Theme Builder keys from page templates on save.

**Test**
- Apply a page template whose settings include `template_type='search'` to a published page. The page's `settings_json` must not gain `template_type`.

### TI-05 — Low — Silent upsert; no rename or duplicate

**Evidence**
- `StudioTemplateService.php:97-119`. A `save_template` with an existing key overwrites another admin's template, with no expected version and no confirmation.
- Rename and duplicate exist only as "overwrite" or "save again under a new key".

**Remediation**
- Require `expected_updated_at`/version on overwrite, or refuse an existing key unless `overwrite=true`.
- Add `duplicate_template` and `rename_template` (metadata only).

**Test**
- Two saves with the same key and different documents. The second is refused without the overwrite flag.

### TI-06 — Low — Admin create path skips validation

**Evidence**
- `templates.php:93-110`: name length and characters unchecked, the title is `"[Theme] {$name}"`, and the key suffix is `bin2hex(random_bytes(3))` cut to 4 hex characters (16 bits), so a unique-key collision is possible.
- `:213-219`: `mt_rand` uuids.
- `:293,299,317,327,346`: `$e->getMessage()` is shown to the user (database error text).
- Revision #1 is inserted without `DependencyRepository` rows (compare `StudioRevisionService.php:156`).

**Remediation**
- Create through `createPage` + `saveTemplate`, with `newUuidV4`, and show generic error messages.

**Test**
- `create_template` with a 300-character name or `<script>` in the name is refused cleanly, with no database message in the flash.

### TI-07 — Low — No migration path

**Evidence**
- `package_version` must be exactly "1.0" (`StudioPackageFormat.php:105-107`).
- `schema_version` must be exactly "1.0" (`DocumentValidator.php:107-115`).
- A block's `version` must equal the registry version (`:845-852`).
- There is no migrator. Stored template rows carry `schema_version` but nothing upgrades them.

**Remediation**
- Add a versioned upgrade step (package -> document -> block) before validation, and a compatibility table.

**Test**
- A fixture package exported with block version N-1 imports after a bump to N, either migrated or with a precise `invalid_block_version` report.

### TI-08 / TI-09 / TI-10 — Info

- **TI-08.** `sectionPresets()` (VIEW) calls `ensureSystemPresets()`, which inserts or updates `is_system` template rows (`StudioApplicationService.php:459-470,570-582`). A GET with side effects. Harmless but surprising.
- **TI-09.** The rate limiter runs after `decodeBody` (`StudioAuthoringApi.php:229-236`). A 1.5 MiB, depth-64 decode happens before throttling.
- **TI-10.** `media_map` targets must be `kind=image` (`StudioPackageService.php:618`). Video or file media cannot be mapped and are downgraded.

## 4. Test coverage

| Behaviour | Test |
|---|---|
| Envelope strictness, unknown keys and kinds, version, oversize | `tests/unit/StudioBuilderPhase8PackageTest.php` :: "phase8a unit: a well-formed package validates; the envelope rejects unknown keys, unknown kinds, unexpected item keys, wrong format/version and oversize packages" |
| tenant_id at any depth; hash tamper detection | same file :: "phase8a unit: tenant_id is rejected at ANY depth, and tampering is detected by the content hash (which is not a signature)"; integration "phase8a int 2" |
| Media descriptors, traversal, external URLs never fetched | unit "phase8a unit: media descriptors are exactly {key, path, mime}…"; integration "phase8a int 8" |
| Id re-minting | unit "phase8a unit: re-minting replaces every section, block and nested child id…" |
| Media downgrade contract | unit "phase8a unit: media downgrade contract…" |
| Global ref remap | unit "phase8a unit: global references are remapped through the explicit map…" |
| CSRF/POST/strict fields | unit "phase8a unit: import_package is a CSRF-guarded POST with strict fields…"; integration "phase8a int 9" |
| No network, no direct table writes, never publishes | unit "phase8a architecture: package code makes no network request, writes no table directly, never publishes…" |
| Audit only after commit | unit "phase8a architecture: importPackage audits only after its own transaction commits…" |
| Dry run writes nothing | `tests/integration/StudioBuilderPhase8PackageTest.php` :: "phase8a int 3" |
| Drafts only, fresh ids, remap | "phase8a int 4" |
| Template ADMIN boundary, collisions, system protection, tokens opt-in | "phase8a int 5" |
| replace_draft concurrency, published revision untouched | "phase8a int 6" |
| Reserved routes, malformed docs, unknown blocks, wrong versions, unentitled modules | "phase8a int 7" |
| Permission matrix | "phase8a int 9" |
| Cross-tenant round trip | "phase8a int 10" |
| Rate-limit bucket | "phase8a int 11" |
| HTML import | `tests/unit/StudioBuilderPhase8HtmlImportTest.php`, `tests/integration/StudioBuilderPhase8HtmlImportTest.php`, `ui/tests/html-import.test.mjs` (not read test by test) |
| Template save/list/system immutability, apply concurrency, insert copy, delete in-use | `tests/integration/StudioBuilderPhase6TemplatesComponentsTest.php` :: "phase6 int 1", "int 2", "int 3", and `template_in_use` at line 354; "int 8" (refusals) |
| System presets cannot be overwritten or deleted | `tests/integration/StudioBuilderSectionPresetsTest.php` lines 303-305 |
| Package UI | `plugins/studio-builder/ui/tests/packages.test.mjs` |

**Not tested**
- Every `admin/templates.php` POST handler: create, publish, unpublish, delete. `StudioBuilderThemeTemplatesAdminTest` re-implements the inserts and never posts to the screen. This covers TI-01, TI-03 and TI-06.
- That import, apply or rollback does not change live template selection (TI-02, TI-04). "phase8a int 6" checks only `published_revision_id`.
- Template overwrite concurrency (TI-05).
- Migration of older packages (TI-07).

## 5. Not verified

- `RichTextSanitizer` allow-list and `PackageDocumentMapper` internals beyond the call sites cited (not read line by line).
- How the builder UI downloads the export (blob or attachment headers) and whether `filename` is used in a `Content-Disposition` header.
- Render-time fallback for token refs whose group is missing in the target tenant.
- The `Media::findByPath` / `Media::get` tenant filtering. The code comment says the platform service filters by tenant (`StudioPackageService.php:99-101`), but I did not open `Slate\Services\Media\Media`.
- Behaviour against a real database (unique indexes on `template_key`, collation, the exact text of the DB errors that TI-06 would leak).
