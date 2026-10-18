# Kohevo Studio Builder — Theme Builder audit (read-only, source-driven)

Tree: `wt-final/01-client` at `71d4e83`. All paths below are relative to that tree. Nothing was run; every statement comes from reading the source. Anything I could not establish from source is listed under "Not verified".

## 1. What exists

### 1.1 Template types

| Type | Where it is declared | How it gets used on the public site | Status on the live site |
|---|---|---|---|
| header | `ThemeTemplateType::HEADER` (`src/Module/StudioBuilder/Theme/ThemeTemplateType.php:20`); stored as a page of type `header_partial` | `ChromeResolver::resolve()` -> `ThemeTemplateResolver::resolveChrome()` (`Render/Chrome/ChromeResolver.php:70-76`), called from `StudioCompiler::plan()` (`Render/Compile/StudioCompiler.php:276`) | Works (with the issues below) |
| footer | `ThemeTemplateType::FOOTER`; page type `footer_partial` | Same path as header | Works |
| single | `ThemeTemplateType::SINGLE`; the admin screen creates it as page type `page` (`plugins/studio-builder/admin/templates.php:49-56`) | `ThemeTemplateResolver::resolveSingle()` (`Theme/ThemeTemplateResolver.php:164-171`) has **no production caller** (grep: only the definition) | **Never applied.** It is only an ordinary public page (see TB-08) |
| archive | `ThemeTemplateType::ARCHIVE`; page type `system` (`templates.php:57-64`) | `StudioPublicRuntime::handlePath()` sends `category/<x>`, `tag/<x>` and `author/<x>` to `handleArchive()` (`Runtime/StudioPublicRuntime.php:78-80,117-133`) | Works |
| search | `ThemeTemplateType::SEARCH` | `handlePath('search')` -> `handleSearch()` (`StudioPublicRuntime.php:83-90,135-149`) | Renders, but the query never arrives (TB-05). The admin screen cannot create this type (no `search` entry in `$typeConfigs`, `templates.php:32-81`) |
| 404 / not_found | `NOT_FOUND`, plus `CODE_404` accepted by `isValid()` but missing from `ALL` (`ThemeTemplateType.php:25-47`) | `StudioPublicRuntime::handleNotFound()` (`:104-115`) has **no production caller**. `PublicRouter::dispatch()` goes straight to `renderNotFound()` (`src/Kernel/Http/PublicRouter.php:92-96`) | **Never served** (TB-04) |
| section_preset | Admin `$typeConfigs['section_preset']` (`templates.php:73-80`) | Reusable content only, not a theme location | n/a |

`DocumentValidator` accepts `settings.template_type` values `header, footer, single, archive, search, 404, not_found, default, custom, page_template` (`Document/DocumentValidator.php:274-276`).

### 1.2 Conditions: types and how they are scored

Conditions are read from `settings.conditions`. That can be a list of rules, `{rules:[...]}`, or a single rule object (`Theme/TemplateConditionMatcher.php:213-230`). The validator allows at most 30 rules, a closed set of keys, and scalar values of at most 100 characters (`DocumentValidator.php:289-349`; `CanonicalDocumentSchema.php:72-80`).

| Scope (`condition` / `scope` / non-include/exclude `type`) | Context key read | Score |
|---|---|---|
| (empty), `entire_site`, `all`, `global` | none | 100 (`TemplateConditionMatcher.php:93-95`) |
| `front_page`, `home` | `is_front_page`, or `slug` in {'', home, index} | 300 (`:98-102`) |
| `category` | `category` (case-insensitive) | 300 (`:105-112`) |
| `tag` | `tags[]` | 300 (`:115-124`) |
| `author` | `author_id` / `created_by` | 300 (`:127-134`) |
| `singular_id`, `id` | `id` / `page_id` | 300 (`:137-141`) |
| `slug` | `slug` | 300 (`:143-147`) |
| `singular`, `post_type`, `page_type` | `content_type` / `page_type`; `field=category`; or a slug fallback | 200, or 300 for the field/slug variants (`:150-174`) |
| `archive` | `archive_type`, or `term` when set | 200 by type, 300 by term (`:177-194`) |
| `404`, `not_found` | `is_404` | 300 (`:197-199`) |
| `search` | `is_search` | 300 (`:202-204`) |
| anything else | — | 0 |

**Evaluation order inside one template** (`TemplateConditionMatcher::score`, `:44-77`):
1. Every rule is evaluated. Rule order does not matter.
2. The action is `action`, else `type`, else `include`. Only the exact lowercase string `exclude` counts as an exclusion (`:59-60`).
3. If **any** matching rule is an exclusion, the template scores 0, whatever its includes.
4. Otherwise the score is the **highest** score among the matching includes. A template with only exclusions therefore always scores 0.

**Choosing between templates** (`ThemeTemplateResolver`):
- **Chrome** (`resolveChrome`, `:37-133`):
  - Candidates are `allOfType('header_partial'|'footer_partial', 100)`.
  - It skips any row that is not `status=published` with a `published_revision_id`.
  - It skips slug `default`, which is the fallback.
  - A row with no `conditions` is ignored, unless it has a legacy `settings.category`, which becomes a category rule (`:106-112`).
  - The highest score wins, compared with strict `>` (`:116`), so **the first candidate wins a tie**.
- **Archive / search / 404 / single** (`resolveTemplate`, `:221-297`):
  - Candidates are `allOfType('system', 50)` followed by `allOfType('page', 100)`.
  - A row is a candidate for the role if `settings.template_type` equals the role, or its slug is `<role>` or `theme-<role>` (`:255-259`).
  - With no conditions, the score is 100. With conditions, the template is dropped on 0 and otherwise takes the condition score (`:265-276`).
  - Strict `>` again, so the first candidate wins a tie.
- **Candidate order** is `ORDER BY title ASC, id ASC LIMIT n` (`Repository/PageRepository.php:56-65`). Archived rows are removed after the LIMIT.
- **Is selection deterministic?** Yes, for a fixed data set. But a tie is decided by the **template title**, so renaming a template can change which one is live. Nothing detects or warns about a conflict.

**The full chrome chain** (`ChromeResolver::resolve`, `:55-79`):
1. `hidden` -> no chrome.
2. `custom` -> the published partial whose slug equals the page slug.
3. Otherwise the conditional winner.
4. Otherwise the published `default` partial.
5. Otherwise the built-in chrome.

Only page types `page` and `landing` get chrome (`:40,57`). Archive, search and 404 templates of type `system` always render without chrome.

### 1.3 Where assignments are stored

- Conditions live in the template's canonical document (`settings.conditions`, `settings.template_type`). The builder dialog saves them as an ordinary draft operation: `PageInspector.jsx:180` -> `ops.updateSettings({conditions})`.
- **The resolver does not read the published revision.** It reads `studiobuilder_pages.settings_json` (`ThemeTemplateResolver.php:96-106,245-255`).
- `settings_json` is rewritten from the **draft** document on every draft save (`Service/StudioRevisionService.php:159-163`), on every rollback (`:382-386`) and on publish (`:275-278`). See TB-01.
- The admin "create" form also writes `settings_json` directly, including a `template_id` link (`templates.php:242-266`). The first publish drops that link (comment at `templates.php:420-421`).

### 1.4 Preview context compared with frontend rendering

Preview (`StudioApplicationService::renderPreview`, `Application/StudioApplicationService.php:1131-1168`):
- The template kind comes from `settings.template_type` or the slug (`TemplatePreviewContext::kind`, `Theme/TemplatePreviewContext.php:24-36`).
- The sample request is whitelisted: `archive_type` must be in {category, tag, author}, otherwise it becomes `category`. Text has control characters stripped and is cut to 191 characters (`TemplatePreviewContext.php:45-83`).
- A single template can be previewed on a real `page`/`landing` of the same tenant (`:1148-1154`).
- A header or footer can be previewed "as a page" through `chrome_override` (`:1158-1167`; `StudioCompiler.php:279-285`).

Differences from the live site:
- Preview **never evaluates conditions**. Any template previews, even one that would never be selected live.
- `chrome_override` replaces the region even on a host page whose `header_mode`/`footer_mode` is `hidden`. `plan()` checks only the page type (`StudioCompiler.php:280-285`).
- The live archive term comes straight from `urldecode($m[2])`, with no length cap and no control-character strip (`StudioPublicRuntime.php:79`). Preview caps it at 191.
- Preview renders the template's **draft**. Live renders the published revision but, through TB-01, applies the draft's conditions.

### 1.5 Fallbacks

| Situation | Behaviour |
|---|---|
| No conditional header/footer matches | Published `default` partial, then the built-in chrome (`ChromeResolver.php:78`) |
| No archive template | `handleArchive` returns null, so the platform 404 is shown (`StudioPublicRuntime.php:122-125`) |
| No search template | Same. A regular page with slug `search` is **shadowed**: the `/search` route returns before the slug lookup (`:83-90`) unless that page is itself picked as the search template by its slug (`ThemeTemplateResolver.php:258`) |
| No 404 template | Platform 404. A 404 template, if one exists, is never used either (TB-04) |
| Template without conditions | Chrome: ignored. Role templates: base score 100, so it applies everywhere |

### 1.6 Query context (post types, taxonomies, user-specific output)

- The chrome context is the page id, slug and page_type, plus the page's **document settings, keeping string values only** (`StudioCompiler.php:259-267`). `settings.category` is the only meaningful key. The non-string `tags` value is dropped there. The fallback lookup in `resolveChrome` re-reads it from the page row only when `category` is missing (`ThemeTemplateResolver.php:55-76`).
- **No caller supplies `content_type`, `author_id` or `is_front_page`.** A grep over `src/Module/StudioBuilder` finds none (TB-06).
- The archive context is `archive_type` and `term`, plus `category`/`tag` (`ThemeTemplateResolver.php:181-189`). The search context is `search_query` and `is_search`.
- No user-specific output: public renders use `RenderContext::forPublic`. Per-request blocks (`archive_title`, `post_*`, `field_value`) are dynamic and filled per request.

### 1.7 Caching and invalidation

- Chrome selection is part of the compiled-artifact fingerprint. `plan()` resolves the header and footer and hashes `header->key()` and `footer->key()` (`StudioCompiler.php:276-277,301-302`). `publishedArtifact()` recomputes the fingerprint on **every public request** (`:159`), so a condition change is picked up on the next request.
- Explicit invalidation also exists on partial publish/archive: `invalidateSharedContent` -> `invalidateChrome` (`StudioApplicationService.php:1791-1793`; `Render/Compile/StudioCompilationInvalidator.php:104-111`). Artifacts also expire after 3600 s (`StudioCompiler.php:60,212-226`).
- Role template selection (archive/search/404) is not cached. It is resolved per request.
- Cost: each public request runs up to two `allOfType(...,100)` queries and decodes JSON per candidate (`ThemeTemplateResolver.php:79-104`). The headers are `public, no-cache` with an HTML-derived ETag (`Render/Cache/StudioCachePolicy.php:53`; `StudioRenderService.php:98-102`).
- `theme.search_box` reads the request (`search_query`) but is **static** (`isDynamic()` returns false: `Render/Block/CoreRenderers/SearchBoxRenderer.php:21-23,30`), so it is baked into the persisted artifact (TB-07).

### 1.8 Permissions and tenant isolation

- All `PageRepository`/`TemplateRepository` reads are tenant-scoped through `StudioRepository::query()` (`Repository/StudioRepository.php:44-48`). Public handlers require `TenantContext::isScoped()` and the `studio-builder` entitlement (`StudioPublicRuntime.php:286-294`).
  - The archive, search and 404 handlers resolve the template **before** the entitlement check, and outside any try/catch (`:119-126,137-144`). See TB-11.
- Builder writes go through `StudioApplicationService` with RBAC: EDIT for drafts and rollback, PUBLISH for publish, ADMIN for template rows (`:350-415,884-886,947-949`).
- **The `admin/templates.php` POST handlers bypass that boundary**. They need only `studio-builder.edit` and use raw SQL (TB-02, TB-03).
- Isolation test: `StudioBuilderSprint6AcceptanceTest.php` "VERIFICATION 5" (lines 323-336).

## 2. Findings

| ID | Severity | Title |
|---|---|---|
| TB-01 | **High** | Draft display conditions and template_type go live without publish |
| TB-02 | **High** | `templates.php` delete_template deletes the wrong backing page (LIKE prefix match) and hard-deletes its revisions |
| TB-03 | **High** | `templates.php` publish/unpublish/create/delete bypass Studio RBAC: edit-only users can unpublish any page and manage template rows |
| TB-04 | Medium | Custom 404 templates are never served (`handleNotFound` not wired) |
| TB-05 | Medium | The live search template never receives the query (query string stripped before the runtime) |
| TB-06 | Medium | Several condition types can never match, or match the wrong thing (missing context keys, slug fallbacks) |
| TB-07 | Medium (latent) | `theme.search_box` is static, so a request's query gets baked into the persisted public artifact |
| TB-08 | Medium | "Single" templates are never applied, yet become public, sitemap-listed pages |
| TB-09 | Medium | Candidate truncation: the LIMIT runs before the status/role filters, ordered by title |
| TB-10 | Low | Ties go to the alphabetical title; no conflict detection |
| TB-11 | Low | Archive/search/404 resolve before the entitlement check and outside try/catch |
| TB-12 | Low | Exclusion pitfalls: a misspelled action becomes an include; `type`-as-scope is unvalidated; exclude-only sets are silently dead on the server |
| TB-13 | Low | Archive route: any term returns 200 with attacker-chosen title text; no length cap (preview caps at 191) |
| TB-14 | Low | Preview and live diverge: preview ignores conditions and the host's hidden chrome |
| TB-15 | Info | Archive/search/404 templates (`system`) never get site chrome; 404/search/archive condition scopes on headers can never match |
| TB-16 | Info | Per-request chrome resolution cost |

### TB-01 — High — Draft display conditions and template_type go live without publish

**Evidence**
- The resolver reads `settings_json` from the page row: `Theme/ThemeTemplateResolver.php:96-106` (chrome) and `:245-265` (role templates).
- A draft save rewrites `settings_json` from the draft document: `Service/StudioRevisionService.php:159-163`. Rollback (EDIT only) does the same: `:382-386` and `StudioApplicationService.php:947-953`.
- The builder saves conditions as a draft op: `plugins/studio-builder/ui/src/components/inspectors/PageInspector.jsx:180`.
- Package `replace_draft` writes through `createDraftRevision`: `Application/StudioPackageService.php:734-735`.

**Impact**
- A user with only `studio-builder.edit`, who must not publish, can:
  - change which header/footer/archive/search template is live, or switch one off by removing its conditions;
  - turn **any published page** into the live archive/search template by saving a draft with `settings.template_type` set (the page's published revision is then served on that route).
- Unreviewed draft edits reach visitors immediately, because chrome is re-fingerprinted on every request.
- Selection also uses the draft's `settings.category` (`resolveChrome` fallback, `ThemeTemplateResolver.php:55-76`).

**Remediation**
- Resolve conditions and `template_type` from the **published revision's** document, not from `pages.settings_json`, for example by decoding `published_revision_id`'s `document_json`.
- Alternatively, keep a separate `published_settings_json` that only `publishWorkingRevision()` writes.

**Test that proves it**
- Integration test:
  1. Publish a header with `category=A` and a page with `category=A`, and assert the header renders.
  2. `applyDocumentOperation(update_settings conditions → category=B)` **without publishing**.
  3. Assert that `handlePath('/page')` still renders the A header.
- Second case: a draft `template_type='search'` on a published page must not change what `/search` serves.

### TB-02 — High — delete_template deletes the wrong backing page and hard-deletes revisions

**Evidence**
- `plugins/studio-builder/admin/templates.php:330-343`.
- The backing page is located with `settings_json LIKE '%"template_id":<id>%'` (`:337`).
  - The pattern for id 5 also matches `"template_id":50`, `"template_id":512`, and so on.
  - `Database::row` takes whichever matching row comes first.
- The lookup runs even when `$doomed` is null, meaning the template id does not exist, belongs to a system template, or was already deleted.
- It then **hard-deletes** that page's revisions, compilations and page row (`:339-342`). This contradicts the append-only rule ("Studio revisions are immutable and cannot be deleted", `Repository/RevisionRepository.php:52-58`).
- There is no `template_in_use` check (the service has one: `StudioTemplateService.php:262-269`), no audit, and `studiobuilder_dependencies` rows are left orphaned.

**Impact**
- Irreversible loss of another template's page (possibly a live header) together with its whole revision history. Scope is within the tenant; the actor needs only edit permission (TB-03).

**Remediation**
- Route deletion through `StudioApplicationService::deleteTemplate()` (ADMIN).
- Locate the backing page by exact match: decode the JSON and compare `template_id`, or store the link in a column.
- Archive the page through `archivePage()` instead of hard-deleting, and never delete revisions.

**Test that proves it**
- Create templates 5 and 50, each with a backing page. POST `delete_template` with `template_id=5`. Assert that page 50 and its revisions still exist.
- POST `template_id=<system id>`. Assert that no page is touched.

### TB-03 — High — templates.php bypasses Studio RBAC

**Evidence**
- `templates.php:23`: `$canEdit = Auth::can('studio-builder.edit') || isSuperAdmin`.
- `unpublish_template` runs `UPDATE studiobuilder_pages SET status='draft', published_revision_id=NULL ...` for **any** `page_id` in the tenant (`:320-329`). It needs no PUBLISH permission, writes no audit entry, and does not check that the page is a template.
- `create_template` inserts `studiobuilder_templates` and a page and revision directly (`:224-283`), whereas `saveTemplate` requires ADMIN (`StudioApplicationService.php:360-362`).
- `delete_template` (TB-02), whereas `deleteTemplate` requires ADMIN (`:409-411`).
- Additional problems in the create path:
  - Exception messages are echoed to the user (`:293,299,317,327,346`).
  - The template name is not validated (the service rejects `<>` and lengths over 191: `StudioTemplateService.php:215-218`).
  - UUIDs come from `mt_rand` (`:213-219`).
  - Revision #1 is inserted without dependency rows.

**Impact**
- An editor can take the homepage, or any published page, offline.
- An editor can create and delete template rows reserved for admins.

**Remediation**
- Every action should call `StudioApplicationService`: `publish` already does; add an `unpublishPage(actor)` behind PUBLISH; use `saveTemplate`/`deleteTemplate` behind ADMIN.
- Restrict `page_id` to the template's own backing page.
- Show generic error messages.

**Test that proves it**
- An actor with VIEW+EDIT only POSTs `unpublish_template` with `page_id=<homepage>`. Expect a refusal, and the page still published.
- The same actor POSTs `create_template`/`delete_template`. Expect a refusal.

### TB-04 — Medium — Custom 404 templates are never served

**Evidence**
- `StudioPublicRuntime::handleNotFound()` (`:104-115`) is called only from `tests/integration/StudioBuilderSprint6AcceptanceTest.php:316,334`.
- `PublicRouter::dispatch` renders `renderNotFound()` after `public_fallback` returns false (`src/Kernel/Http/PublicRouter.php:92-96`).
- `StudioBuilder::servePublicPath` only calls `handlePath` (`plugins/studio-builder/StudioBuilder.php:146-159`).
- The admin screen still offers "404 Error" (`templates.php:177`) and says it publishes and activates.

**Remediation**
- Call `handleNotFound()` from the fallback when `handlePath()` returns null, or from a `public_not_found` hook, keeping the 404 status.
- Keep the anti-enumeration rule: render the same template for every unknown path.

**Test**
- Integration through `PublicRouter::dispatch('does-not-exist')` with a published 404 template. Expect status 404 and the template body.

### TB-05 — Medium — The search template never receives the query on the live site

**Evidence**
- `public.php:30-35` strips `?…` from the path before `PublicRouter::dispatch`.
- `StudioPublicRuntime::handlePath` parses `q`/`s` **from the path string** (`:73-89`) and never reads `$_GET`.
- On the live site `search_query` is therefore always `''`: the archive title shows "Search" and the search box is empty.

**Remediation**
- Pass the query string, or `$_GET['q']`, length-bounded, into `handleSearch`.

**Test**
- An integration test that goes through `public.php`/`servePublicPath` with `$_GET['q']='studio'` and `_path=search`, asserting "Search Results: studio".
- The existing test only calls `resolveSearch` directly (`tests/unit/StudioBuilderSprint6ThemeBuilderTest.php:268`).

### TB-06 — Medium — Condition types that never match, or match the wrong thing

**Evidence**
- No caller sets `content_type`, `author_id` or `is_front_page` (grep over `src/Module/StudioBuilder`). As a result:
  - **`singular` post/portfolio/services**: `$actual` falls back to `page_type` (`page`/`landing`), so these never match. The slug fallback (`TemplateConditionMatcher.php:168-171`) then gives **300**, the highest specificity, to a page whose slug is literally `post`, `portfolio` or `services`. The admin options "All Portfolio Items / All Blog Posts / All Service Offerings" (`templates.php:115-117,525-530`) behave like this.
  - **`author`** can never match: the chrome context has no `author_id`/`created_by` (`ThemeTemplateResolver.php:49-53`), and the archive context has neither either (`:183-188`).
  - **`front_page`** matches only slugs `home`/`index`/'', never the real homepage (`route_mode=homepage`) under another slug (`:98-102`). Because '' counts, every search and 404 evaluation context (no slug) **matches `front_page` with score 300**.
  - **`archive` with `term`** ignores `archive_type`, so `term=news` matches `/category/news` and `/tag/news` alike (`:181-188`). The builder UI writes this form (`ui/src/core/conditions.mjs:93`).
  - **`tag`**: `(array)` of a string `tags` value does not split on commas (`:117`).

**Remediation**
- Build one evaluation context per request: content type from PageMeta, `author_id` from `created_by`, `is_front_page` from `route_mode`.
- Remove the slug fallbacks in `singular`.
- Treat a missing slug as "not the front page".
- Honour `archive_type` together with `term`.

**Test**
- A data-provider unit test over `TemplateConditionMatcher::score` covering each scope with realistic resolver contexts:
  - a homepage with slug `welcome` plus a `front_page` rule must match;
  - a search context must not match `front_page`;
  - `singular=portfolio` must not match page slug `portfolio`;
  - `term=news` must not match a tag archive when `archive_type=category`.

### TB-07 — Medium (latent while TB-05 stands) — Search query baked into the persisted artifact

**Evidence**
- `SearchBoxRenderer::isDynamic()` returns false (`Render/Block/CoreRenderers/SearchBoxRenderer.php:21-23`), yet the renderer reads `search_query` from the request context (`:30,34`).
- Static nodes are rendered at compile time (`Render/DocumentRenderer.php:213-218`).
- `publishedArtifact()` compiles with the **request** context whenever the artifact is missing or stale (expiry 3600 s or a fingerprint change), then stores it (`StudioCompiler.php:150-173`).
- Once TB-05 is fixed, the first `/search?q=<text>` after expiry stores `<text>`, HTML-escaped, in the search box for every visitor for up to an hour. That is content spoofing / cache poisoning, and other queries show the wrong value.

**Remediation**
- Make `theme.search_box` dynamic (`isDynamic(): true`).
- Alternatively, compile persisted artifacts with a context that has the request attributes removed.

**Test**
- Unit test: `DocumentRenderer::isDynamicNode` is true for `theme.search_box`.
- Integration test: compile through `/search?q=AAA` after deleting the compilation row, then request `/search?q=BBB`, and assert the input value is `BBB`.

### TB-08 — Medium — "Single" templates are dead, and published as public pages

**Evidence**
- `resolveSingle` is never called (§1.1).
- The admin screen creates the single backing page as `page_type='page'` with slug `template-<key>` (`templates.php:49-56,241-266`).
- `seo_json` is written as `{"noindex":true}`, which is not a canonical SEO key. Publish rewrites `seo_json` from the document (`StudioRevisionService.php:277`), so the page becomes publicly routable at `/template-<key>` (`StudioPublicRuntime.php:92-96`) and is listed in `sitemap.xml` (`Runtime/StudioSitemapService.php:68-119`, which only skips `noindex` robots).
- Such pages also enter the role-template candidate list as `page` rows.

**Remediation**
- Either wire `resolveSingle` into the page render (template plus `meta_page_id`), or remove the type from the UI.
- Store templates under a non-public page type, or set `seo.robots=noindex,nofollow` in the starter document.

**Test**
- Publish a single template through the admin flow. Assert `handlePath('/template-…')` returns null and `sitemap.xml` does not list it.

### TB-09 — Medium — Candidate truncation

**Evidence**
- The candidate queries are `allOfType('header_partial'|'footer_partial', 100)`, `allOfType('system', 50)` and `allOfType('page', 100)` (`ThemeTemplateResolver.php:79-81,229-233`).
- `allOfType` applies `ORDER BY title, id LIMIT n` **before** removing archived rows, and the resolver drops drafts and non-role rows only afterwards (`PageRepository.php:56-65`).
- On a site with more than 100 `page` rows (archived ones included) or 50 `system` rows, a role template outside the first n titles is silently ignored.
- Admin-created pages are titled `"[Theme] …"` (`templates.php:254`). Whether they land early or late in that order depends on the column collation (see "Not verified").

**Remediation**
- Query only published rows that carry a template role or conditions, in SQL, with no arbitrary LIMIT. A generated `template_type` column or a JSON predicate would do.

**Test**
- Seed 120 published pages titled "A…", plus a page-typed search template titled "[Theme] Search". Assert `resolveSearch` finds it.

### TB-10 — Low — Ties are decided by title; no conflict detection

**Evidence**
- Strict `>` comparisons (`ThemeTemplateResolver.php:116,278`) over title-ordered candidates. Two `entire_site` headers: the alphabetically first wins, and renaming flips the winner. Nothing tells the author.

**Remediation**
- Add an explicit `priority` and a stable tie-break such as published_at or id.
- Report overlapping conditions in the inspector.

**Test**
- Two published headers with equal conditions, titles "B" and "A". Assert the documented winner; rename and assert the winner is unchanged.

### TB-11 — Low — Resolution happens before the entitlement check and outside try/catch

**Evidence**
- `StudioPublicRuntime.php:119-126,137-144` call `resolveArchive`/`resolveSearch` (database queries) before `serveTemplate` checks entitlement (`:287-294`).
- An exception from a repository there is uncaught, unlike `serve()`, which logs and returns null.

**Remediation**
- Check entitlement first and wrap resolution in try/catch.

**Test**
- An unentitled tenant with a throwing pages repository: `handleArchive` returns null and the repository is never touched.

### TB-12 — Low — Exclusion pitfalls

**Evidence**
- The validator accepts any `action`/`type` matching `^[a-z_0-9]{1,40}$` (`DocumentValidator.php:343-347`). The matcher treats everything except `exclude` as an include (`TemplateConditionMatcher.php:59-60`), so `excludes` or `exlude` silently turns an exclusion into an inclusion.
- A `type` used as a scope (`{type:"categroy"}`) is not checked against `ALLOWED_CONDITION_SCOPES` (only `condition`/`scope` are: `DocumentValidator.php:337-341`). It never matches.
- Exclude-only sets disable a template server-side. Only the UI warns (`ui/src/core/conditions.mjs:117`); the package, API and admin paths do not.

**Remediation**
- Validate `action` ∈ {include, exclude}.
- Validate a `type` that is neither include nor exclude against the scope list.
- Warn on exclude-only sets on save.

**Test**
- `DocumentValidator` rejects `action:"excludes"` and `type:"categroy"`.

### TB-13 — Low — Archive route accepts arbitrary terms

**Evidence**
- `#^(category|tag|author)/([^/]+)$#` followed by `urldecode` (`StudioPublicRuntime.php:78-79`). Any term renders 200 with "Category: <term>" (escaped: `ArchiveTitleRenderer.php:45-55`), with no length cap. That allows soft-404s and text injection on the tenant's domain.

**Remediation**
- Cap and clean the term the way `TemplatePreviewContext::text` does.
- Optionally return 404 when no content carries the term.

**Test**
- `/category/<300 chars>`: the title is at most 191 characters, and control characters are removed.

### TB-14 — Low — Preview and live diverge

See §1.4: preview ignores conditions, overrides hidden chrome, and uses differently sanitised sample text.

**Remediation**
- Show "would this template be selected for the sample?" in the preview, by evaluating the matcher with the same context builder.
- Honour `hidden` modes in `chrome_override`.

**Test**
- Preview a header as a page with `header_mode=hidden`. Expect no header, matching the live site.

### TB-15 — Info — No chrome on system templates; dead header scopes

- `ChromeResolver::CHROMED_PAGE_TYPES=['page','landing']` (`:40,57`), so archive, search and 404 pages (type `system`) render with no site header or footer.
- `resolveChrome` is never evaluated with `is_404`, `is_search` or `archive_type`, so the `404`, `search` and `archive` rows the condition dialog offers for headers (`ui/src/core/conditions.mjs:23-25`) can never match.

### TB-16 — Info — Per-request resolution cost

- Two `allOfType(…,100)` queries plus a JSON decode per candidate, on every public page request, because the chrome is part of the fingerprint (`StudioCompiler.php:159,276-277`).
- Consider a per-tenant memo keyed on max(updated_at) of partials.

## 3. Test coverage map

| Behaviour | Test (file :: test name) |
|---|---|
| Specificity ordering 300 > 200 > 100; no match = 0 | `tests/unit/StudioBuilderSprint6ThemeBuilderTest.php` :: "sprint6 unit: TemplateConditionMatcher scoring, specificity, and operators" (cases 1-5) |
| Exclusion overrides an include (matcher only) | same test, case 6 |
| Conditional header by category; no match returns null | `StudioBuilderSprint6ThemeBuilderTest.php` :: "sprint6 unit: ThemeTemplateResolver conditional header and footer resolution" |
| Conditional header plus default fallback, end to end | `tests/integration/StudioBuilderSprint6AcceptanceTest.php` :: "sprint6 integration: theme builder, conditional headers, archives, 404, and multi-tenant isolation" (VERIFICATION 1-2) |
| Archive/404/search resolution (no conditions) | unit "sprint6 unit: ThemeTemplateResolver archive, 404, and search resolution"; integration VERIFICATION 3-4 (calls `handleNotFound()` directly) |
| Tenant isolation of public templates | integration VERIFICATION 5 |
| Admin-created templates validate, publish, resolve | `tests/integration/StudioBuilderThemeTemplatesAdminTest.php` :: "theme templates admin: creation, canonical schema, backing page, and resolver attachment" (re-implements the inserts; **does not call the POST handlers**) |
| Validator: closed rule set, 30 rules, scalar values | `tests/unit/StudioBuilderPhase2DocumentDomainTest.php` :: "phase2 document domain: display conditions validate as a small closed rule set" |
| Preview kind and sample whitelisting | `tests/unit/StudioBuilderTemplatePreviewTest.php` (3 tests); `plugins/studio-builder/ui/e2e/template-preview.spec.mjs` (2 tests) |
| UI rows <-> rules, only-exclusions warning | `plugins/studio-builder/ui/tests/conditions.test.mjs` (4 tests) |
| Publishing a partial invalidates chromed pages | `tests/integration/StudioBuilderPhase6TemplatesComponentsTest.php` :: "phase6 int 6: chrome bindings report …; publishing a partial invalidates chromed pages, not hidden ones" |

**Not tested** (none found by grep or by reading the tests above):
- **Overlapping conditions with equal score**: tie-break and determinism (TB-10).
- **Two matching templates of different specificity at the resolver level**: covered only at matcher level.
- **Exclusion at the resolver level** (a header excluded on one category while the default shows); **exclude-only sets**; misspelled actions (TB-12).
- **Missing assignment**: a published partial with no conditions being ignored; a role template with no conditions acting as catch-all versus a conditioned one.
- **Draft versus published conditions** (TB-01).
- front_page, singular content types, author, id, slug, tag, archive term versus type, and search/404 scopes (TB-06).
- The candidate LIMIT (TB-09).
- Live 404 wiring (TB-04); live search query (TB-05); search-box artifact (TB-07).
- `templates.php` POST handlers create, publish, unpublish, delete (TB-02, TB-03).
- Single-template application and exposure (TB-08).
- Preview of hidden chrome (TB-14).

## 4. Not verified

- Runtime behaviour of any of the above. Nothing was executed.
- Whether the web server rewrite keeps `_path` free of query strings in every deployment. TB-05 relies on `public.php:30-35`.
- Database collation ordering for `"[Theme]"` titles. Under a binary collation they sort after A-Z and are cut first. Under `*_unicode_ci`, punctuation usually sorts before letters, so other templates are cut instead. The truncation itself (TB-09) holds either way.
- Whether any non-Studio plugin calls `handleNotFound()` through another hook (none found by grep in this tree).
- MCP (`src/Module/StudioBuilder/Mcp/*`) exposure of template or condition editing. The plugin MCP handler has no template/import tools (grep), but I did not read the adapter in full.
- The UI rendering of the Theme Builder admin list (only the escaping of dynamic values was spot-checked).
