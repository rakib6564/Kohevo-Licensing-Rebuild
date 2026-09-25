<?php
/**
 * MCP Gateway — bootstrap.
 *
 * Deliberately has NO public/ directory. The prior AI-gateway plugin
 * (archive/plugins/slate-mcp) was found still reachable by third-party AI
 * connectors after being "deactivated" — Slate's .htaccess lets anything
 * under a plugin's public/ dir be fetched directly regardless of what the
 * plugins table says. Mounting under the core /api/v1 gateway instead means
 * these routes only exist at all while this plugin's boot() actually runs:
 * deactivate the plugin and the route registration simply never happens on
 * the next request — there is no leftover file for the webserver to keep
 * serving. See README.md for the full containment rationale.
 */
require_once __DIR__ . '/McpGatewayAPI.php';
require_once __DIR__ . '/CoreMcpTools.php';
require_once __DIR__ . '/ReportingMcpTools.php';
require_once __DIR__ . '/AiProviderClient.php';

class McpGateway extends Plugin {

    public function boot(): void {
        McpGatewayAPI::ensureSchema();

        Hook::addFilter('admin_nav_items',    [$this, 'addAdminNav']);
        Hook::addFilter('api_v1_modules',     [$this, 'addApiModule']);
        Hook::addFilter('api_v1_routes',      [$this, 'addApiRoutes']);
        Hook::addFilter('api_v1_authenticate',[$this, 'authenticateApiV1Token'], 10, 2);
        Hook::addFilter('public_routes',      [$this, 'addPublicRoutes']);
        Hook::addFilter('i18n_lang_paths',    [$this, 'addLangPath']);
        Hook::addAction('customer_head',      [$this, 'renderCustomerWidget']);

        // Phase 1 core tools (settings, media, notifications, audit, debug,
        // cron, tests) — registered through the same extension point a
        // third-party plugin's <Plugin>McpHandler.php uses.
        CoreMcpTools::register();

        // Phase 3: aggregated business reporting.
        ReportingMcpTools::register();
    }

    /**
     * Second, independent kill switch (MCP_GATEWAY_ENABLED, see config.php)
     * checked here too — not just inside McpGatewayAPI::handleApiRoute() —
     * so that when it's off, the route never even gets registered, rather
     * than existing and 503ing. Belt and suspenders with the plugin-active
     * check that already gates whether boot() runs at all.
     */
    public function addApiRoutes(array $routes): array {
        if (!defined('MCP_GATEWAY_ENABLED') || !MCP_GATEWAY_ENABLED) return $routes;
        $routes['mcp'] = [McpGatewayAPI::class, 'handleApiRoute'];
        return $routes;
    }

    /**
     * The customer-facing site assistant's chat endpoint. Not gated by
     * MCP_GATEWAY_ENABLED — that flag is specifically about the external
     * MCP token protocol; this is a separate, tool-free feature gated by
     * its own "customer_assistant_enabled" setting (checked inside the
     * handler) and by whether an AI provider is configured at all.
     */
    public function addPublicRoutes(array $routes): array {
        $routes['ai-assistant'] = [
            'handler' => __DIR__ . '/CustomerAssistant.php',
            'methods' => ['POST', 'OPTIONS'],
        ];
        return $routes;
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    /** Floating chat widget markup, injected on every customer-facing page (login/register/portal). */
    public function renderCustomerWidget(): void {
        if ((string) Database::setting('mcp-gateway.customer_assistant_enabled') !== '1') return;
        if (!class_exists('AiProviderClient') || !AiProviderClient::isConfigured()) return;
        echo $this->widgetHtml();
    }

    /** Tenant's brand accent color, same setting Global Styles/Membership already read — falls back to Slate's default blue. */
    private function brandAccent(): string {
        $accent = trim((string) Database::setting('brand_accent_color'));
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent) ? $accent : '#2563EB';
    }

    private function widgetHtml(): string {
        $endpoint = SLATE_URL . '/ai-assistant';
        $accent   = $this->brandAccent();
        return <<<HTML
<button id="slate-ai-toggle" aria-label="Chat with us" style="position:fixed;z-index:9999;right:18px;bottom:18px;width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;background:{$accent};color:#fff;box-shadow:0 10px 24px rgba(0,0,0,.28);font-size:24px;line-height:56px;text-align:center;touch-action:none;user-select:none;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">💬</button>
<div id="slate-ai-panel" style="display:none;position:fixed;z-index:9999;right:18px;bottom:18px;width:320px;max-width:88vw;height:440px;max-height:70vh;background:#fff;border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,.22);overflow:hidden;flex-direction:column;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <div style="padding:12px 14px;background:{$accent};color:#fff;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;">
    <span>Ask us anything</span>
    <button id="slate-ai-close" aria-label="Close" style="background:none;border:0;color:#fff;font-size:18px;cursor:pointer;line-height:1;">×</button>
  </div>
  <div id="slate-ai-log" style="flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;font-size:13.5px;"></div>
  <form id="slate-ai-form" style="display:flex;gap:6px;padding:10px;border-top:1px solid #eee;">
    <input id="slate-ai-input" type="text" placeholder="Type a question…" autocomplete="off" style="flex:1;border:1px solid #ddd;border-radius:20px;padding:8px 12px;font-size:13.5px;">
    <button type="submit" style="border:0;background:{$accent};color:#fff;border-radius:20px;padding:8px 14px;cursor:pointer;font-size:13.5px;">Send</button>
  </form>
</div>
<style>
@keyframes slateAiDot { 0%,60%,100%{opacity:.25;transform:translateY(0);} 30%{opacity:1;transform:translateY(-3px);} }
.slate-ai-typing{align-self:flex-start;display:flex;gap:4px;padding:9px 12px;background:#f1f5f9;border-radius:12px;}
.slate-ai-typing span{width:6px;height:6px;border-radius:50%;background:#94a3b8;display:inline-block;animation:slateAiDot 1.1s infinite ease-in-out;}
.slate-ai-typing span:nth-child(2){animation-delay:.15s;}
.slate-ai-typing span:nth-child(3){animation-delay:.3s;}
</style>
<script>
(function(){
  var endpoint = {$this->jsString($endpoint)};
  var accent = {$this->jsString($accent)};
  var history = [];
  var toggle = document.getElementById('slate-ai-toggle');
  var panel  = document.getElementById('slate-ai-panel');
  var closeBtn = document.getElementById('slate-ai-close');
  var log = document.getElementById('slate-ai-log');
  var form = document.getElementById('slate-ai-form');
  var input = document.getElementById('slate-ai-input');

  // ── Drag the closed bubble anywhere; the panel always opens bottom-right ──
  var POS_KEY = 'slate_ai_toggle_pos';
  var dragging = false, moved = false, startX = 0, startY = 0, startLeft = 0, startTop = 0;

  function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

  function applyPos(left, top) {
    var w = toggle.offsetWidth || 56, h = toggle.offsetHeight || 56;
    left = clamp(left, 6, window.innerWidth - w - 6);
    top  = clamp(top, 6, window.innerHeight - h - 6);
    toggle.style.left = left + 'px';
    toggle.style.top  = top + 'px';
    toggle.style.right = 'auto';
    toggle.style.bottom = 'auto';
  }

  try {
    var saved = JSON.parse(localStorage.getItem(POS_KEY) || 'null');
    if (saved && typeof saved.left === 'number') applyPos(saved.left, saved.top);
  } catch (e) {}

  function pointerXY(e) {
    if (e.touches && e.touches.length) return {x: e.touches[0].clientX, y: e.touches[0].clientY};
    return {x: e.clientX, y: e.clientY};
  }

  function dragStart(e) {
    dragging = true; moved = false;
    var p = pointerXY(e);
    startX = p.x; startY = p.y;
    var r = toggle.getBoundingClientRect();
    startLeft = r.left; startTop = r.top;
  }
  function dragMove(e) {
    if (!dragging) return;
    var p = pointerXY(e);
    var dx = p.x - startX, dy = p.y - startY;
    if (Math.abs(dx) > 4 || Math.abs(dy) > 4) moved = true;
    if (moved) { e.preventDefault(); applyPos(startLeft + dx, startTop + dy); }
  }
  function dragEnd() {
    if (!dragging) return;
    dragging = false;
    if (moved) {
      var r = toggle.getBoundingClientRect();
      try { localStorage.setItem(POS_KEY, JSON.stringify({left: r.left, top: r.top})); } catch (e) {}
    } else {
      openPanel();
    }
  }

  toggle.addEventListener('mousedown', dragStart);
  document.addEventListener('mousemove', dragMove);
  document.addEventListener('mouseup', dragEnd);
  toggle.addEventListener('touchstart', dragStart, {passive: true});
  document.addEventListener('touchmove', dragMove, {passive: false});
  document.addEventListener('touchend', dragEnd);

  function openPanel() {
    panel.style.display = 'flex';
    if (!log.childElementCount) bubble('assistant', "Hi! Ask me about our services, plans, or your own account.");
  }
  closeBtn.addEventListener('click', function () { panel.style.display = 'none'; });

  function bubble(role, text) {
    var d = document.createElement('div');
    d.textContent = text;
    d.style.maxWidth = '85%';
    d.style.padding = '8px 11px';
    d.style.borderRadius = '12px';
    d.style.whiteSpace = 'pre-wrap';
    if (role === 'user') { d.style.alignSelf='flex-end'; d.style.background=accent; d.style.color='#fff'; }
    else { d.style.alignSelf='flex-start'; d.style.background='#f1f5f9'; d.style.color='#1e293b'; }
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    return d;
  }

  function typingBubble() {
    var d = document.createElement('div');
    d.className = 'slate-ai-typing';
    d.innerHTML = '<span></span><span></span><span></span>';
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    return d;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    bubble('user', text);
    history.push({role:'user', content:text});
    var thinking = typingBubble();

    fetch(endpoint, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({message: text, history: history.slice(-12)})
    }).then(function (r) { return r.json(); }).then(function (data) {
      thinking.remove();
      var reply = data && data.reply ? data.reply : (data && data.error ? data.error : 'Something went wrong.');
      bubble('assistant', reply);
      history.push({role:'assistant', content: reply});
    }).catch(function () {
      thinking.remove();
      bubble('assistant', 'Something went wrong reaching the assistant.');
    });
  });
})();
</script>
HTML;
    }

    private function jsString(string $s): string {
        return json_encode($s, JSON_UNESCAPED_SLASHES);
    }

    public function addApiModule(array $modules): array {
        if (defined('MCP_GATEWAY_ENABLED') && MCP_GATEWAY_ENABLED) $modules[] = 'mcp';
        return $modules;
    }

    public function authenticateApiV1Token($auth, string $token) {
        if ($auth !== null) return $auth;
        if (!defined('MCP_GATEWAY_ENABLED') || !MCP_GATEWAY_ENABLED) return $auth;
        $context = McpGatewayAPI::authenticate($token);
        if ($context) {
            return [
                'authenticated' => true,
                'type'          => 'mcp_token',
                'tenant_id'     => $context['tenant_id'],
                'scopes'        => $context['scopes'],
                'token_id'      => $context['token_id'],
            ];
        }
        return $auth;
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('mcp-gateway.manage') && !Auth::isSuperAdmin()) return $items;
        $items[] = [
            'slug' => 'mcp-gateway', 'label' => __('mcp_gateway_nav', 'AI Access'),
            'href' => $this->url('admin/index.php'), 'icon' => 'shield',
            'perm' => 'mcp-gateway.manage', 'order' => 285, 'group' => 'mcp-ai-hub',
        ];
        $items[] = [
            'slug' => 'mcp-gateway-ai-settings', 'label' => __('mcp_gateway_ai_settings_nav', 'AI Connection'),
            'href' => $this->url('admin/ai-settings.php'), 'icon' => 'zap',
            'perm' => 'mcp-gateway.manage', 'order' => 286, 'group' => 'mcp-ai-hub',
        ];
        $items[] = [
            'slug' => 'mcp-gateway-chat', 'label' => __('mcp_gateway_chat_nav', 'AI Command Center'),
            'href' => $this->url('admin/chat.php'), 'icon' => 'message-circle',
            'perm' => 'mcp-gateway.manage', 'order' => 287, 'group' => 'mcp-ai-hub',
        ];
        return $items;
    }
}
