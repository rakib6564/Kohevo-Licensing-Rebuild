# PR descriptions: the five High fixes (N1–N5)

Five small branches, each based on `origin/main` `ba4c9a5`, local only (not pushed). None depends on another or on PRs #102, 2 or 3. N3 and N5 both touch `plugins/studio-builder/admin/templates.php` in different handlers; git merges them automatically (checked in `integration/release-check-highs`). Results on the combined tree: `04-test-results.md`, section "High fixes".

---

## fix(studio-builder): post content never emits editor-typed markup raw

Branch `fix/studio-post-content-xss`, commit `c19c9d5`. 2 files.

**Problem (N1, stored XSS).** `theme.post_content`'s "Fallback Content" is a plain text field. Its write-time filter blocks `<script>` and `javascript:` but not event-handler attributes. The renderer emitted any value containing a tag unescaped, so an editor could store `<img src=x onerror=…>` and run script for every visitor and for admins previewing the page. Studio has no raw-HTML block, so this crossed a privilege boundary.

**Fix.** Markup goes through `RichTextSanitizer`, the allowlist `rich_text` blocks already use. Plain text is escaped with line breaks, as before. Content supplied by the render context gets the same treatment.

**Tests.** New Sprint6 case covering six payloads (`onerror`, `onclick`, `svg onload`, `javascript:` link, `iframe`, `script`), safe markup kept, and plain text escaped. Fails on the old code; mutation caught.

**Rollback.** Revert. No data change.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

---

## fix(mcp-gateway): settings tool writes only an allow-list of general settings

Branch `fix/mcp-settings-allowlist`, commit `3862f1c`. 6 files, same change in both trees.

**Problem (N2).** `slate_settings_set` refused only keys matching `pass|secret`. A token with the `mcp-gateway.settings.write` scope could:
- repoint `smtp_host` or `mcp-gateway.ai_base_url`, sending the stored, decrypted credentials to a server of its choosing;
- overwrite `*_token` / `*_api_key` keys with plaintext;

all without the admin page's validation.

**Fix.**
- **Writes:** `slate_settings_set` accepts 13 general settings: site name, business name/email/phone/address/hours, currency, currency position, default language, timezone, date/time format, start of week. Each is normalised and checked as `admin/settings.php` does. Any other key is refused with a pointer to the admin UI.
- **Reads:** `slate_settings_get` hides credentials using a wider key pattern, plus any `enc:v1:` value whatever its key.
- **Audit log:** it now redacts the value of a settings write whose key names a credential. Before, it only caught `password|secret`.

**Behaviour change.** MCP clients can no longer change plugin settings, mail settings or URLs through this tool. The scope description says so.

**Tests.** `McpSettingsAllowlistTest`, 5 cases per tree; the old code fails 3 or 4. The test restores every setting it touches. 4 mutations caught.

**Rollback.** Revert. No data change.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

---

## fix(studio-builder): deleting a theme template touches only its own page and keeps history

Branch `fix/studio-template-delete`, commit `885d23e`. 2 files.

**Problem (N3).** Theme Builder › delete found the backing page with `settings_json LIKE '%"template_id":<id>%'`. For template 5 that also matches pages linked to 50, 57, 512 and so on. It then hard-deleted that page with its compilations and revisions, although revisions are meant to be immutable. It also ran for an unknown or system template id.

**Fix.** The handler:
- refuses an unknown or system template;
- picks the backing page by slug, or by `settings_json.template_id` equal to the id after decoding;
- deletes the template through `StudioApplicationService::deleteTemplate` (admin permission, in-use check, audit log);
- archives the backing page through `archivePage` (revisions kept, caches invalidated, audited).

**Behaviour change.**
- Deleting a template now needs `studio-builder.admin`, the level the service always required.
- The backing page is archived rather than erased.

**Tests.** `StudioTemplateDeleteTest` drives the real page against a decoy page whose `template_id` starts with the same digits. The old handler fails both cases. The test seeds and restores a signed Studio entitlement. 2 mutations caught.

**Rollback.** Revert. Pages archived by the new handler stay archived; they can be restored.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

---

## fix(studio-builder): Theme Builder selects templates by their published settings

Branch `fix/studio-theme-published-conditions`, commit `72fd527`. 2 files.

**Problem (N4).** `ThemeTemplateResolver` scored header, footer, archive, search and 404 candidates from `pages.settings_json`. That column mirrors the current **draft**: every draft save, rollback, package import and template apply rewrites it. An unpublished edit to a template's conditions or `template_type` therefore changed the live site immediately. That was possible for a user allowed to edit but not publish, and it could turn any published page into the archive, search or 404 template.

**Fix.** Candidates are scored from the settings they were published with:
- the column, when the working draft is the published revision (it is then an exact mirror);
- otherwise, the published revision's document settings.

**Tests.** New Sprint6 case covering three drafts:
- one that widens a header's conditions;
- one that sets `template_type=archive` on an ordinary page;
- one that narrows an archive template.

Fails on the old resolver; mutation caught.

**Not changed here.** The candidate `LIMIT` before filtering (TB-09) and the rendered page's own category being read from its draft (a smaller variant of the same issue) are listed in `12-findings-register.md`.

**Rollback.** Revert. No data change.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

---

## fix(studio-builder): Theme Builder and Content pages enforce Studio permission levels

Branch `fix/studio-admin-permissions`, commit `4aa8550`. 3 files.

**Problem (N5).**
- `admin/templates.php` accepted every action from anyone with `studio-builder.edit`.
- Its unpublish action reverted **any** page in the tenant with a raw UPDATE, unaudited, and left the compiled page cached.
- `admin/content.php` only required a signed-in user, so any admin user could rewrite the public portfolio content.

**Fix.**
- **`templates.php` permissions:** create and delete need `studio-builder.admin`; publish and unpublish need `studio-builder.publish`. These are the levels the application services require.
- **Unpublish:** accepts only a Theme Builder page, drops its compiled output, and writes a `studio.page.unpublished` audit entry.
- **`content.php`:** requires `studio-builder.edit`.

**Behaviour change.** Editors without admin or publish rights no longer see those actions succeed. Assign the permissions to the roles that should keep them.

**Tests.** `StudioAdminPermissionsTest` drives both pages with a real view+edit role, a view-only role and a super admin. The old pages fail all three cases. Cleanup removes anything a regression would create. 3 mutations caught.

**Rollback.** Revert. No data change.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
