# MCP Gateway

Scoped, revocable, audited access for an AI agent (Claude, Manus, etc.) to
operate most of Slate's admin surface through the Model Context Protocol.

## Why this exists, and what came before it

`archive/plugins/slate-mcp` was a first attempt at this. It was deliberately
shut down: after being "deactivated" in the `plugins` table it kept serving
live traffic to third-party AI connectors, because its MCP endpoint was a
plain file under `plugins/slate-mcp/public/router.php`, and Slate's
`.htaccess` lets anything under a plugin's `public/` directory be fetched
directly by the webserver — regardless of what the app's own database says
about the plugin's active status. Deactivating the plugin did not make the
endpoint unreachable.

This plugin is built so that failure mode cannot recur:

- **No `public/` directory at all.** There is no file under this plugin
  that the webserver can serve independently of Slate's own routing.
- **Mounted under the core `/api/v1` gateway** (`src/Kernel/Http/ApiRouter.php`)
  via the `api_v1_routes` / `api_v1_authenticate` filters, which only fire
  from inside `McpGateway::boot()` — which only runs while this plugin is
  `active`. Deactivate it and the route registration simply stops happening
  on the very next request; there's no leftover file to keep answering.
- **A second, independent kill switch**: the `MCP_GATEWAY_ENABLED`
  environment variable (see `.env.example`), checked before the route is
  even registered. Two switches (plugin-active AND env flag) have to both
  be wrong for the gateway to be reachable when nobody intended it to be.

## The permanent restriction

No token — no matter what scopes it's granted — can ever be used to:

1. Change or reset anyone's password (admin or customer).
2. Delete a role.
3. Delete a user account.
4. Write a live Stripe or SMTP secret key.
5. Deactivate this plugin, or otherwise disable the gateway.

This is enforced at three independent layers, not just by omission:

- **Layer 1**: no tool for any of the above is ever registered by this
  plugin or by any `<Plugin>McpHandler.php` that hooks into
  `slate_mcp_tools` / `slate_mcp_call_tool`. Don't add one.
- **Layer 2**: `McpGatewayAPI::isBlocked()` runs a hardcoded name-pattern
  guard on every tool call before dispatch, so a tool matching those five
  categories is refused even if Layer 1 is ever missed by mistake — and the
  attempt is separately audit-logged as `mcp-gateway.blocked_attempt`.
- **Layer 3**: this document, plus the same statement in `plugin.json`'s
  description, so the boundary is visible to anyone reviewing the code —
  not just enforced silently.

Everything else — settings, content, media, reports, audits, debugging,
test runs, cron, notifications, and each installed plugin's own admin
operations (bookings, memberships, coaching, forms, etc.) — is reachable
through scoped tools, one `<Plugin>McpHandler.php` per plugin.

## Adding tools from another plugin

Follow `plugins/booking/BookingMcpHandler.php` as the template: register on
`slate_mcp_scopes` (declare scope keys + labels), `slate_mcp_tools`
(declare tools with a JSON-schema `inputSchema`, gated on the caller's
granted scopes), and `slate_mcp_call_tool` (dispatch by tool name to your
plugin's own `*API.php`, throwing on missing scope). This gateway plugin
never needs to know your plugin's internals.

## Every call is audited

`AuditLog::record('mcp-gateway.<tool_name>', ...)` fires for every call,
allowed or blocked. View them in Admin → Audit Log filtered by action
prefix `mcp-gateway.`.

## Tool classification (Phase 7)

Every tool descriptor declares a machine-readable risk classification:

```php
'classification' => ['access' => 'read' | 'write' | 'destructive', 'requires_confirmation' => bool],
```

`McpGatewayAPI::classify()` normalizes it and exposes it to MCP clients as
the standard `annotations` hints (`readOnlyHint`, `destructiveHint`). The
admin AI assistant runs a tool unattended only when it is declared
`read` without confirmation; a tool that declares nothing is treated as a
confirmed write (fail closed). Nothing infers a tool's risk from its NAME
any more — `studio_get_publish_status` would not be "safe" because of the
word `get`. External MCP clients have no confirmation UX at all: the only
authority is the server-side check inside each tool handler.

## Dispatch context (Phase 7)

Handlers receive an explicit context: `tenant_id`, `scopes`, `token_id`,
`issuer_user_id` (the token's `created_by`) and `origin` — `mcp_token` for
an external bearer token, `admin_assistant` for the in-app chat. A module
that enforces its own RBAC (Kohevo Studio) builds its actor from these
fields and treats `scopes` as visibility only.

## Kohevo Studio scopes (Phase 7)

`studio-builder.read`, `studio-builder.edit`, `studio-builder.tokens`,
`studio-builder.admin` map one-to-one onto the Studio permissions of the
same name (`read` → `studio-builder.view`). `studio-builder.publish` is
registered but grants NO tool in this release: the AI cannot publish a
Studio page; a human publishes from the Studio Builder against the exact
reviewed revision. A token's effective Studio authority is its scopes
intersected with its issuer's CURRENT Studio permissions, never super
admin. A token holding a Studio scope cannot also hold
`mcp-gateway.debug.read`, `mcp-gateway.tests.run`, `mcp-gateway.cron.write`
or `mcp-gateway.settings.write` (`McpGatewayAPI::STUDIO_INCOMPATIBLE_SCOPES`).
Studio draft writes and renders/diffs have their own per-token per-minute
budgets (`mcp-gateway.studio_rate_limit_write_per_min`, default 30;
`mcp-gateway.studio_rate_limit_render_per_min`, default 15) on top of the
generic `mcp-gateway.rate_limit_per_min`.
