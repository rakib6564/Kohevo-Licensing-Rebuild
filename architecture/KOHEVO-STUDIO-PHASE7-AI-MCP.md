# Kohevo Studio — Phase 7: AI + MCP

Status: implemented (Phase 7, initial release). Records the decisions Phase 7
establishes on top of the Phase 1–6 model and the contracts the MCP gateway,
the admin assistant and the builder UI rely on. **No schema change**: the
pre-existing `revision_kind = 'ai_operation'` carries every AI draft.

## 1. Owner decision (frozen): no autonomous publish

```
AI (external MCP client or admin assistant)
  ↓ draft revision (ai_operation)
  ↓ preview of that exact revision (non-public, no-store, noindex)
  ↓ structured diff
  ↓ human reviews in the Studio Builder
  ↓ human clicks Publish (bound to expected_revision_id)
  ↓ existing StudioApplicationService::publish()
```

There is no `studio_publish`, `ai_publish` or equivalent tool; the reserved
`studio-builder.publish` scope grants no tool; the admin assistant of a
publisher still has no publish tool. `StudioApplicationService::publish()`
remains the single publish path and is reachable only through the human
Builder. Asserted by the Phase 7 unit and integration suites.

## 2. One boundary

```
MCP Gateway (McpGatewayAPI: token auth, denylist, generic rate limit, audit)
  → plugins/studio-builder/StudioBuilderMcpHandler   (module guard, Studio rate classes, wiring)
  → Mcp\StudioMcpAdapter                             (input validation, scope check, actor, tool → command, projection)
  → Application\StudioActor                          (origin, principal, permissions)
  → Application\StudioApplicationService             (tenant → auth → entitlement → RBAC → concurrency → validation → persistence → audit)
  → existing DocumentOperation / DocumentValidator / StudioRevisionService / renderer
```

The adapter, catalog and handler contain no table name, repository, service,
validator, applier, transaction or `publish(` call (static unit guard). Every
tool maps onto an existing named command; the MCP operation vocabulary IS
`DocumentOperation::ALLOWED_OPS`. No AI-specific document JSON, no AI CMS,
no second persistence.

## 3. Actor model (`Application\StudioActor`)

| origin | principal | user_id | permissions | super admin |
|---|---|---|---|---|
| `session` | user id | signed-in user | `\Auth::can()` per Studio key | session semantics |
| `mcp_token` | MCP token id | token issuer (`created_by`) | token Studio scopes ∩ issuer's **current** Studio permissions (`Mcp\IssuerAuthority`, resolved at call time: role 1 / platform admin → all; suspended, deleted or demoted issuer → nothing) | never |
| `admin_assistant` | user id | signed-in user | the human's current permissions (`fromCurrentSession()->asAdminAssistant()`) | session semantics |

No `isAI` boolean. `isAiOrigin()` is true for `mcp_token` and
`admin_assistant` and only changes how a write is *recorded*. The gateway's
`allScopeKeys()` context of the admin chat is visibility only: the adapter
filters the assistant's tool list by the human's permissions and every call
re-runs Studio RBAC.

## 4. AI revision model

`StudioApplicationService::draftKind()` decides the revision kind from the
actor, never from a client: an AI-origin actor's draft writes (operations,
template application/insertion, page creation, component creation/detach)
are always `ai_operation`; a session actor may only write `autosave` /
`manual` (`ai_operation` requested by a session → `invalid_revision_kind`,
also refused by the builder HTTP API). `publish` and `rollback` keep their
own kinds; their audit rows carry the origin. `created_by` is the delegating
human (token issuer or signed-in user).

## 5. Scopes and tools

| scope | Studio permission | tools revealed |
|---|---|---|
| `studio-builder.read` | `studio-builder.view` | `studio_list_pages`, `studio_get_page`, `studio_get_revisions`, `studio_get_templates`, `studio_get_components`, `studio_get_chrome`, `studio_get_tokens`, `studio_preview`, `studio_diff` |
| `studio-builder.edit` | `studio-builder.edit` | `studio_get_manifest`, `studio_get_structure`, `studio_get_document`, `studio_create_page`, `studio_apply_operations`, `studio_insert_template`, `studio_apply_template`, `studio_create_global_component`, `studio_detach_global_component`, `studio_rollback`, `studio_archive_page` |
| `studio-builder.admin` | `studio-builder.admin` | `studio_save_template` |
| `studio-builder.tokens` | `studio-builder.tokens` | `studio_save_tokens` |
| `studio-builder.publish` | `studio-builder.publish` | **none** (reserved) |

Every call needs scope AND Studio permission AND Studio entitlement AND the
token's tenant. Tenant comes from the authenticated token (the gateway's
`with_tenant()` + `TenantContext`); the adapter re-checks it and refuses any
`tenant_id` argument (`unknown_field`). All ids resolve through tenant-scoped
repositories inside the application layer (foreign page / revision /
component → `not_found` / `cross_tenant_or_missing_partial`).

Classification is declared per tool (`Mcp\StudioMcpToolCatalog`): reads are
`read` (no confirmation), draft mutations `write` (confirmation in the admin
chat), `studio_archive_page` `destructive`. The gateway normalizes every
handler's classification into MCP `annotations`; an undeclared tool is a
confirmed write. The admin chat no longer infers safety from tool names. UI
confirmation is a courtesy; server-side authorization is the only authority
(external MCP clients have no UI).

## 6. Concurrency, replay, rate limiting

Every draft mutation carries `expected_revision_id` (required field) and
uses the single Phase 2 check under the page row lock: a stale value is
`concurrency_conflict` (409) with `current_revision_id` /
`expected_revision_id` and a resolution hint; nothing is merged, reloaded or
retried. Replay protection follows from the same check: a repeated request
after a lost response targets a revision that no longer is the draft and is
refused, so inserts, template insertion, component creation from a section,
detach and rollback cannot duplicate content. Non-idempotent by design (no
revision guard): `studio_create_page` / blank `studio_create_global_component`
(protected only by the unique slug — a repeat with the same slug is
`duplicate_slug`, a repeat with a new slug creates a second page),
`studio_save_template` (upsert by key, idempotent), `studio_save_tokens`
(whole-group replace, idempotent), `studio_archive_page` (idempotent). No
database-wide idempotency framework was introduced.

Rate limits: the gateway's generic per-token budget
(`mcp-gateway.rate_limit_per_min`, counting `action = 'call'` rows) applies
to every call; Studio adds per-token classes on the same table and database
clock — `mcp-gateway.studio_rate_limit_write_per_min` (default 30) and
`mcp-gateway.studio_rate_limit_render_per_min` (default 15, previews and
diffs), derived from the gateway's 60/min baseline. The admin assistant (a
web session) uses the builder's session limiter.

## 7. Preview, diff, approval

`studio_preview` renders the exact revision through the Phase 4 preview
pipeline (no artifact, no revision, no audit row) and returns the human
preview URL, revision identity and render metadata (`html_bytes`,
`html_sha256`, no-store/noindex flags); HTML only on `include_html`, bounded.
`studio_diff` (and the builder query `diff`) return
`StudioApplicationService::diffRevisions()`: page, base/proposed revision
summaries, the structural `Diff\RevisionDiff` (sections / blocks added,
removed, moved, updated with changed keys and bounded before/after values;
settings, SEO, template, component references; readable lines), publish
impact (`proposed_is_current_draft`, `requires_publish`, shared content,
dependent pages) and dependency impact. Approval persistence needed no new
table: the proposed `ai_operation` revision plus the human publish against
that exact `expected_revision_id` IS the approval; a draft that moved on
after review is a 409 and nothing goes live.

Builder: an `AI draft` badge and a **Review AI draft** action when the
current draft is an `ai_operation` revision; the review dialog shows the
diff, opens the exact-revision preview and offers the existing Publish bound
to that revision; History marks AI revisions; the **AI assistant** entry
point links to the gateway's admin chat when that module is active.

## 8. Audit chain

One audit log, no shadow table. Every Studio mutation event carries
`origin`, `user_id`, `token_id` (attribution from the actor) plus
`revision_id`; the gateway's own `mcp-gateway.<tool>` event carries the same
`token_id` and redacted, summarized arguments. A human publish records
`origin = session`, `user_id`, `revision_id` and `source_revision_id` (the
AI revision it published). Bearer tokens never appear in audit metadata,
the action log or the application log (only a hash and a 16-char prefix are
stored).

## 9. Transaction rule (Phase 6, re-verified)

`StudioApplicationService::audit()` is the only audit call site and never
runs inside an owned transaction (static guard extended to `$this->audit(`).
The web-session hazard (the session repository's lazy DDL implicitly commits)
is now reproduced deterministically in the integration suite and the
admin-assistant path is verified against it.

## 10. Gateway changes (minimal)

`McpGatewayAPI`: explicit `origin` and `issuer_user_id` in every dispatch
context; `classify()` / `withClassification()` / `runsUnattended()`;
`executeToolCall()` (the production dispatch, also the test seam);
`withinClassRateLimit()`; `assertScopeCombinationAllowed()` — a token with a
Studio scope cannot also hold `mcp-gateway.debug.read`, `.tests.run`,
`.cron.write` or `.settings.write` (the flat token UI otherwise makes that
combination one click). All existing handlers declare their classification;
their behavior is unchanged.

## 11. Deliberately not done

Autonomous publish; importers; a new AI framework; an AI-specific canvas;
an approvals table; gateway redesign; idempotency keys for page creation.
