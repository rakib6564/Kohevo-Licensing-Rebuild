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
