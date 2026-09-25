# Content-Builder / Forms / React-Site-Bridge audit (read-only)

Scope: `plugins/content-builder/`, `plugins/forms/`, `plugins/react-site-bridge/` (18,484 lines PHP).
Tree audited: `claude/slate-platform-audit-c95b8f` @ c3a90ba. Note this branch carries the Phase C/D content-spine work not yet on `develop` — `public/api.php`, `lib/MediaKeyResolver.php`, `lib/PrecomposedBody.php`, `lib/SiteTemplate.php` and the `ContentBuilderAPI` envelope additions are **new here**, so findings touching them do not apply to `develop` as it stands.

---

## Inventory

### Tables

| table | tenant_id | person data |
|---|---|---|
| `contentbuilder_post_types` | YES | — |
| `contentbuilder_posts` | YES | — (`author_id`) |
| `contentbuilder_post_meta` | **NO** | — |
| `contentbuilder_taxonomies` | YES | — |
| `contentbuilder_terms` | YES | — |
| `contentbuilder_term_relations` | **NO** | — |
| `contentbuilder_menus` | YES | — |
| `forms_definitions` | YES | `notify_email` |
| `forms_submissions` | YES | **arbitrary submitted PII in `data_json`**, uploaded files, e-signature images |
| `forms_webhooks` | YES | — |
| `forms_webhook_log` | YES | — |
| `forms_spam_log` | YES | IP addresses |
| `forms_contacts` (runtime, `FormsAPI.php`) | YES | email, name, phone |
| `reactsitebridge_sites` | YES | — |
| `reactsitebridge_documents` | YES | — |
| `reactsitebridge_media` | YES | — |
| `reactsitebridge_revisions` | YES | — |
| `reactsitebridge_hosted_releases` | YES | — |
| `reactsitebridge_content_sources` | YES | — |

`forms_contacts` is a person table created at runtime by `FormsAPI` — a fifth independent person model alongside core `customers`, the `contacts` spine, `booking_customers`, `shop_customers` and `restaurant_customers`.

### Auth gating — admin

All 7 content-builder, 6 forms and 6 react-site-bridge admin screens call `Auth::require()` + `Auth::requirePerm(...)` in their opening lines. **No unprotected admin screen in any of the three.** Permissions: `content.view` / `content.edit` / `content.publish` / `content.manage_types`; `forms.view` / `forms.manage`; `react-site-bridge.manage`.

### Public routes

| route | auth | tenant-scoped | 404s properly |
|---|---|---|---|
| `content-builder/public/router.php` | public by design; preview gated `:35` | yes | yes |
| `content-builder/public/api.php` (new) | public by design | YES (`ContentBuilderAPI.php:721`) | yes |
| `content-builder/public/render.php` | public by design | yes | yes |
| `forms/public/router.php` | public by design (submission) | yes throughout | yes (`:501`) |
| `react-site-bridge/public/router.php` | public by design | yes | yes |
| `react-site-bridge/public/host.php` | public by design | **NO** — finding 2 | yes |
| `react-site-bridge/public/host-alias.php` | public by design | yes (site-key path) | yes |
| `react-site-bridge/public/preview.php` | public by design | yes | yes |

All four RSB endpoints and both content-builder routers consume `$_GET['_route_path']` and return real 404s — they follow the `PublicRouter` contract that membership does not (see the membership/coaching report).

### Settings keys

`content-builder`: site settings via `ContentBuilderAPI::getSiteSetting()` / `setSiteSetting()`, written only by `admin/site.php` — includes the new `api_allowed_origins`. `forms.*` written only by `forms/admin/index.php` and `admin/edit.php` (per-form settings live in `forms_definitions.settings_json`, not the settings table). `react-site-bridge.*` written only by its own admin screens. **No key is written by two screens in any of the three.**

### Messaging

`FormsAPI` composes admin notification and autoresponder emails and dispatches webhooks (`FormsAPI.php:2450+`); `forms/public/router.php:577` `forms_public_dispatch()` runs PDF generation, email and webhooks after save. Content-builder and RSB compose nothing.

### Duplicated helpers

- **Slug generation** exists three times: `ContentBuilderAPI::slugify()`, `FormsAPI::slugify()` (`:2311`), and `BookingAPI::slugify()` (`booking/BookingAPI.php:1602`). Each re-implements uniqueness against its own table.
- **Media URL building**: `ContentBuilderAPI::mediaUrl()` (`:38`) alongside core `Slate\Services\Media\Media` and the new `MediaKeyResolver`. RSB has its own media table and resolution.
- **Site/document modelling overlaps between content-builder and RSB**: `contentbuilder_posts.layout` and `reactsitebridge_documents` both store a page document; `ReactSiteBridgeContentBuilder.php` bridges them. Both can hold a version of the same page and there is no single writer, though I found no path where they actively disagree at runtime.

---

## Findings

### [high] Every HMAC token in Forms is keyed on `APP_SECRET` guarded only by `defined()`, which is always true — so when `APP_SECRET` is unset the key is the empty string and the tokens are forgeable

- evidence: `config.php:49` always defines the constant, empty by default:
  ```php
  define('APP_SECRET', env('APP_SECRET', ''));
  ```
  so every `defined()`-only guard resolves to the *empty string*, never the fallback literal. Three sites:
  - `plugins/forms/public/router.php:700` — the public "Save PDF" token
    ```php
    $secret = defined('APP_SECRET') ? (string) APP_SECRET : 'forms-pdf-fallback';
    return substr(hash_hmac('sha256', 'forms-pdf|' . $ref, $secret), 0, 24);
    ```
  - `plugins/forms/lib/FormsSpamGuard.php:497` — the time-trap anti-spam signature, identical shape.
  - `plugins/shop/storefront/includes/layout.php:127` — the storefront CSRF token, with **no guard at all**, under a docblock at `:120` asserting it "Is unguessable without APP_SECRET":
    ```php
    return hash_hmac('sha256', 'shop-csrf:' . $sid, APP_SECRET);
    ```
  The platform knows the constant can be empty and handles it correctly elsewhere: `includes/helpers.php:218-219` and `:232` test `APP_SECRET === ''` and refuse; `admin/settings.php:959`, `:1720` and `:2004` surface its status in the UI. `FormsAPI.php:2462-2463` also gets it right for webhook signing — `$signature = $secret !== '' ? hash_hmac(...) : '';`. So four call sites use the correct test and three use the weak one.
- failure case: with `APP_SECRET` empty, an attacker computes `substr(hash_hmac('sha256','forms-pdf|'.$ref,''),0,24)` offline for any `$ref` — the key is public knowledge. The only remaining barrier is the ref itself, and `FormsAPI::generateRef()` (`:2303-2305`) returns `'SUB-' . bin2hex(random_bytes(4))` — **32 bits**. Enumerating that space against `/<form>?action=pdf&ref=…&t=…` with a self-computed token yields other people's submitted PDFs, which for an e-signature form is a signed document with names, addresses and a signature image. The shop token is worse in kind: `sf_csrf_verify()` (`layout.php:135-139`) compares against the same derivable value, so storefront CSRF protection is absent rather than weakened.
- blast radius: any tenant whose `APP_SECRET` is unset or left at the `.env.example` placeholder (`change-me-to-64-random-hex-chars`, `.env.example:17` — non-empty but published in the repo, so equally known). Forms PDF disclosure, forms spam-protection bypass, and shop storefront CSRF on every state-changing storefront POST.
- fix: the three weak sites should test `APP_SECRET !== ''` as `helpers.php` and `FormsAPI` already do, and — more importantly — should refuse to issue or accept a token when the key is absent rather than silently degrading to a known key. A shared accessor that throws or returns null on an unconfigured secret would make the failure loud at every consumer instead of leaving each one to remember. Separately, `generateRef()`'s 32 bits is thin for a value that gates document access even with a sound token, and deserves widening. The shop storefront's session-free CSRF derivation is a sound idea that depends entirely on the key being real, so it should fail closed.
- **verified** as a code defect — the guard is provably wrong at all three sites. **Exploitability is conditional** on the deployment's `APP_SECRET`, which I could not read: `.env` is gitignored and absent from this worktree. An operator can confirm in seconds via the APP_SECRET panel at `admin/settings.php:1710-1720`.

### [high] A published React site is served by its public id with no tenant filter, so any tenant's domain will serve any other tenant's hosted site

- evidence: `plugins/react-site-bridge/ReactSiteBridgeAPI.php:53-59`
  ```php
  public static function getSiteByPublicId(string $publicId): ?array {
      self::ensureSchema();
      return Database::row(
          "SELECT * FROM reactsitebridge_sites WHERE public_id = ? AND status = 'published'",
          [trim($publicId)]
      ) ?: null;
  ```
  Its two siblings both scope: `:48` `WHERE id = ? AND tenant_id = ?`, and `:565` `WHERE tenant_id = ? AND site_key = ? AND status = 'published'`. Reached from two public entry points — `:309` and `:554` (`serveHostedRelease`, behind `plugins/react-site-bridge/public/host.php:19`).
- failure case: tenant B publishes a React site at `https://tenant-b/react-sites/<public-id>/`. The public id is visible in that URL to every visitor — it is not a secret. Anyone requests `https://tenant-a/react-sites/<same-public-id>/` and `getSiteByPublicId` matches B's row regardless of the active tenant; `serveHostedSite()` (`:574`) then loads B's release using the tenant id from the row itself and serves the entire site — every HTML route and static asset — under tenant A's domain. Unlike the equivalent gap in booking-plus, **no secret is required**: the identifier is public by design, which makes this the more exploitable of the two.
- blast radius: every published RSB site on a multi-tenant install. Content and branding served under the wrong tenant's origin, with SEO duplication and, for anything the site treats as origin-bound, a cookie and storage boundary crossed.
- fix: add the tenant filter to `getSiteByPublicId()` so it matches its two siblings. The `public_id` should be treated as a per-tenant identifier, not a global one, and the same reasoning applies to the release lookup it feeds. As with the booking token, the durable point is that three lookups of the same table were written separately and one omitted the boundary — consolidating site resolution behind one scoped accessor removes the chance of a fourth.
- **verified**

### [medium] Deleting a form submission leaves its uploaded files and signature images on disk permanently, with nothing left to locate them by

- evidence: all three deletion paths remove only the database row —
  `plugins/forms/admin/submission.php:95` `Database::delete('forms_submissions', 'id = ? AND tenant_id = ?', [$id, $tid]);`
  `plugins/forms/admin/submissions.php:180` (bulk delete)
  `plugins/forms/admin/index.php:28` `Database::delete('forms_submissions', 'form_id = ? AND tenant_id = ?', [$id, $tid]);` (deleting a whole form)
  None calls `Uploads::remove()` or unlinks anything. Files are written under `uploads/forms/` by `FormsAPI.php:2352` via `Uploads::handle($name, 'forms', …)`. Contrast coaching, which does clean up: `CoachingAPI::deleteDiaryPhoto()` (`plugins/coaching/CoachingAPI.php:451`) unlinks the file before deleting the row.
- failure case: a form collects an identity document and a signature. A submitter asks for their data to be removed; an admin deletes the submission — the UI confirms "This cannot be undone" (`admin/submissions.php:509`). The row disappears, so no screen can list or reach the file again, but `uploads/forms/<random>.<ext>` remains on disk and remains served by the web server to anyone holding or guessing the URL. Deleting an entire form orphans every file every submitter ever uploaded to it, in one action.
- blast radius: every tenant using forms with file-upload or signature fields; an erasure request appears to succeed while the most sensitive artefact survives.
- fix: deletion should remove the submission's files before removing the row, in all three paths — reading the file references out of `data_json` and passing them to `Uploads::remove()`, as coaching already does for diary photos. Because three call sites delete submissions, the cleanup belongs in a single `FormsAPI::deleteSubmission()` the screens call, rather than being added three times. A sweep for already-orphaned files under `uploads/forms/` is worth running once the code is fixed, since existing installs will have accumulated them.
- **verified**

### [low] The content API resolves any nested route to its final path segment, so unrelated URLs serve the same page with HTTP 200

- evidence: `plugins/content-builder/ContentBuilderAPI.php:723-729`
  ```php
  $slug = trim($route, '/');
  if ($slug === '') $slug = 'home';
  if (str_contains($slug, '/')) {
      $slug = substr($slug, strrpos($slug, '/') + 1);
  }
  $post = self::getPostBySlug('page', $slug, $tenantId);
  ```
- failure case: `GET /api/content/anything/at/all/home` returns the Home page with HTTP 200 and an `address.route` of `/home` — the discarded prefix is neither validated nor reflected. Any number of distinct URLs are live aliases for every page. This is the same "resolve loosely rather than 404" instinct as the membership router, though far less harmful here because an unknown *slug* does correctly 404 (`:730`) and only published posts are returned.
- blast radius: the new content API only, which is not on `develop`. Consumers that cache by request URL will hold unbounded duplicate entries for one document; any consumer treating the route as canonical will disagree with the API's own echoed `address.route`.
- fix: either honour the full path by resolving nested pages through `parent_id` — the column exists (`ContentBuilderAPI.php:378`) and is already populated — or reject a route containing a slash with a 404 rather than silently discarding its prefix. Given this endpoint is new and unreleased, deciding now whether routes are hierarchical is cheaper than changing the contract after consumers exist.
- **verified**

### [low] Two content-builder join tables carry no `tenant_id`

- evidence: `contentbuilder_post_meta` and `contentbuilder_term_relations` are the only two of seven content-builder tables without the column (`plugins/content-builder/install.sql`).
- failure case: **I could not construct one**, for the same reason as booking's two join tables: both are keyed on ids (`post_id`, `term_id`) that are globally unique across tenants in shared tables, and every read path reaches them via a post or term already resolved with a tenant filter. The boundary holds transitively today but is not stated in the schema, so a future query that reaches either table without first resolving its parent would have no boundary at all.
- blast radius: none currently.
- fix: add `tenant_id` to both and filter on it, as defence in depth. Schedule, do not hotfix.
- **verified as not currently exploitable** — reported for the latent constraint only

---

## What I checked and found clean

Several of these directly contradict what a browser-level audit would have assumed about a page builder, and are recorded so they are not re-investigated:

- **Content-builder's XSS surface is deliberately hardened, not accidentally safe.** Every block that emits an editor-supplied URL into `href` routes it through `slate_safe_url()` — `button.php`, `cta.php`, `hero.php` (4 uses), `icon-grid.php`, `image-grid.php`, `image.php`, `rx-hero.php`, `rx-visit.php`. The blocks that do not (`post-list.php:23`, `rx-gallery.php:17`, `rx-menu.php:18`, `rx-reviews.php:21`, `rx-story.php:9`, `testimonial.php:7`) emit only through `ContentBuilderAPI::mediaUrl()` or `publicUrl()` into `<img src>` or a CSS `background-image:url()`, where a `javascript:` scheme does not execute, and all are wrapped in `e()` so the quoting cannot be broken out of.
- **The raw-HTML escape hatch is gated server-side, not in the editor.** `lib/blocks/html.php` echoes unescaped by design, and `ContentBuilderAPI::savePost()` enforces the permission on the way in — `:367` `$mayUsePrivileged = !class_exists('Auth') || Auth::can('content.publish');` then `sanitizeLayout()` at `:375`, with a recursive `sanitizeNested()` walk (`:353`) explicitly written to stop a privileged block being smuggled inside a nested container, and grandfathering for already-published content. A hand-crafted POST from a `content.edit`-only user cannot introduce raw HTML.
- **The new content API is well built**: tenant-scoped (`ContentBuilderAPI.php:721`), published-only (`:730`), CORS is an explicit allowlist that is empty by default with no wildcard path (`public/api.php:36-47`), exception messages are never returned to the caller (`:61-68`), and `X-Content-Type-Options: nosniff` is set.
- **RSB's static file serving is guarded against traversal** — `ReactSiteBridgeAPI.php:581` rejects `..` and NUL bytes, `:582` applies an extension allowlist via `validHostedFile()`, `:588`/`:592` require `is_file`, and the release is served by `readfile()` from a resolved base directory rather than by including it.
- **Forms' public submission path is disciplined**: CSRF verified on POST (`public/router.php:114`), a spam guard with country rules, rate limiting and CAPTCHA (`:121`, `FormsSpamGuard`), an SSRF guard on outbound webhooks (`FormsAPI.php:2470`), tenant scoping on every query, and a real 404 handler (`:501`).
- **No SQL string concatenation of user input** in any of the three plugins.
- **`mediaUrl()` permits `data:` URIs through unfiltered** (`ContentBuilderAPI.php:41`), but I traced every consumer and none lands in an `href` or an `iframe src` — only `<img src>` and CSS `url()`, where `data:text/html` does not execute. Not a finding today; it would become one the moment a block puts `mediaUrl()` output into a link.

---

## Notes for the reconciler

1. **Finding 1 is cross-cutting and reaches outside this cluster** — `plugins/shop/storefront/includes/layout.php:127` is cluster 4's territory and is the most serious of the three sites, because it is CSRF protection rather than a download token. Confirm it there and promote the whole thing to a single platform-level finding about `APP_SECRET` handling rather than reporting it per-plugin. The correct pattern already exists in the codebase four times over (`helpers.php:218`, `:232`; `FormsAPI.php:2462`; `admin/settings.php:269`).
2. **The "one lookup written N times, one copy missing the tenant filter" pattern is now confirmed twice** — booking-plus's `manage_token` and RSB's `public_id`. This is the shape to grep for in the remaining cluster, and it is the strongest evidence so far for the browser audit's thesis: not that plugins duplicate *objects*, but that they duplicate *access paths* and the copies drift apart.
3. **`forms_contacts` is a fifth person table** created at runtime in `FormsAPI`, not in `install.sql`. It should join the person-table census.
4. **GDPR fan-out gains a second concrete case** (finding 3). Combined with the membership/coaching finding, the pattern is now: no core erasure path, and each plugin's own cleanup is either absent or partial. Forms is the sharpest instance because the residue is uploaded identity documents and signatures.
5. **Slug generation is duplicated three ways** (`ContentBuilderAPI`, `FormsAPI:2311`, `BookingAPI:1602`). Worth confirming shop/restaurant in cluster 4 before writing it up.
6. **Scope caveat for the final report**: `public/api.php`, `MediaKeyResolver`, `PrecomposedBody`, `SiteTemplate` and the `ContentBuilderAPI` envelope work exist only on this branch. Findings 4 and parts of the clean list do not apply to `develop`.
