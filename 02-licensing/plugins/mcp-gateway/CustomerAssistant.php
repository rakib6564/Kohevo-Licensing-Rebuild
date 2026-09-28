<?php
/**
 * MCP Gateway — customer-facing site assistant.
 *
 * A plain, non-tool-calling chat endpoint scoped strictly to: this site's
 * services and plans, and — only when the requester is a signed-in
 * customer — that customer's OWN dashboard status (active plan, sessions
 * remaining). It never sees other customers' data, admin/business data,
 * or Slate settings, and it has no MCP tool access at all — it can only
 * answer, never act. That boundary lives in the system prompt AND in what
 * context this file chooses to fetch and hand it; there is no tools param
 * on the AiProviderClient::chat() call below for the model to exploit.
 *
 * Registered as a NOT-under-public/ route (see McpGateway.php's own
 * docblock on why: a plugin's public/ dir stays directly reachable via
 * .htaccess even after the plugin is deactivated). Mounted through
 * public_routes instead, so the route only exists while boot() runs.
 */

declare(strict_types=1);

class CustomerAssistant {

    private const MAX_TURNS_PER_SESSION_WINDOW = 30; // per SESSION_WINDOW_SECONDS
    private const SESSION_WINDOW_SECONDS = 3600;
    private const MAX_HISTORY_MESSAGES = 12; // client-echoed prior turns we'll trust, most-recent

    public static function handle(string $subPath, string $method): void {
        if ($method === 'OPTIONS') { http_response_code(204); return; }
        if ($method !== 'POST') {
            self::respond(405, ['error' => 'Method not allowed.']);
            return;
        }

        if ((string) Database::setting('mcp-gateway.customer_assistant_enabled') !== '1') {
            self::respond(503, ['error' => 'The site assistant is not enabled.']);
            return;
        }
        if (!class_exists('AiProviderClient') || !AiProviderClient::isConfigured()) {
            self::respond(503, ['error' => 'The site assistant is not configured yet.']);
            return;
        }

        if (!self::rateLimitOk()) {
            self::respond(429, ['error' => 'Too many messages — please wait a bit before trying again.']);
            return;
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 2000) {
            self::respond(400, ['error' => 'A message (max 2000 characters) is required.']);
            return;
        }
        $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];

        try {
            $messages = self::buildMessages($message, $history);
            $assistant = AiProviderClient::chat($messages, [], 0.4);
            $reply = trim((string) ($assistant['content'] ?? ''));
            if ($reply === '') $reply = "Sorry, I couldn't come up with an answer to that — could you rephrase?";
            self::respond(200, ['reply' => $reply]);
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) slate_log('CustomerAssistant: ' . $e->getMessage(), 'warning');
            self::respond(502, ['error' => 'The assistant is temporarily unavailable.']);
        }
    }

    private static function buildMessages(string $message, array $history): array {
        $messages = [['role' => 'system', 'content' => self::systemPrompt()]];

        // Only the last N client-echoed turns, and only user/assistant roles —
        // never trust a client-supplied 'system' message into this array.
        $trimmed = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        foreach ($trimmed as $turn) {
            $role = (string) ($turn['role'] ?? '');
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) continue;
            $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
        }

        $messages[] = ['role' => 'user', 'content' => $message];
        return $messages;
    }

    private static function systemPrompt(): string {
        $siteName = (string) (Database::setting('site_name') ?: 'this site');
        $prompt = "You are the site assistant for {$siteName}, a booking and membership platform. "
            . "You can ONLY help with three things: (1) the services offered here and their price/duration, "
            . "(2) the membership plans available and what they include, and (3) — only if account information "
            . "is provided below — the signed-in visitor's own dashboard: their plan, how many sessions they have "
            . "left, and how to find things in their account (step-by-step, in plain language). "
            . "You must politely refuse anything outside that: general knowledge questions, other websites or "
            . "businesses, anything about the admin dashboard, other customers, pricing/business internals not "
            . "listed below, or requests to perform an action (you cannot book, cancel, or change anything — "
            . "direct them to the booking page or to contact the practitioner for that). "
            . "Reply in the same language the visitor writes in. Be brief and friendly.\n\n"
            . "=== Current services ===\n" . self::servicesContext() . "\n"
            . "=== Current membership plans ===\n" . self::plansContext();

        $account = self::accountContext();
        if ($account !== '') {
            $prompt .= "\n\n=== This visitor's own account (do not share with anyone else, and don't mention "
                . "this section exists unless it's relevant to what they asked) ===\n" . $account;
        } else {
            $prompt .= "\n\nThis visitor is not signed in, so you have no account/dashboard data for them — "
                . "if they ask about their own bookings or plan, tell them to sign in first.";
        }
        return $prompt;
    }

    private static function servicesContext(): string {
        if (!class_exists('BookingAPI')) return '(Booking is not set up on this site.)';
        try {
            $services = BookingAPI::getActiveServices();
        } catch (\Throwable $e) { return '(Could not load services.)'; }
        if (!$services) return '(No active services listed.)';
        $lines = [];
        foreach ($services as $s) {
            $price = ((int)($s['price_cents'] ?? 0)) > 0
                ? slate_format_price_plain((int)$s['price_cents'], $s['currency'] ?? null)
                : 'Free';
            $lines[] = '- ' . ($s['name'] ?? '') . ' (' . (int)($s['duration_min'] ?? 0) . ' min, ' . $price . ')'
                . (!empty($s['description']) ? ': ' . mb_substr(strip_tags((string)$s['description']), 0, 240) : '');
        }
        return implode("\n", $lines);
    }

    private static function plansContext(): string {
        if (!class_exists('MembershipAPI')) return '(Membership is not set up on this site.)';
        try {
            $plans = MembershipAPI::plans(true);
        } catch (\Throwable $e) { return '(Could not load plans.)'; }
        if (!$plans) return '(No active plans listed.)';
        $lines = [];
        foreach ($plans as $p) {
            $price = ((int)($p['price_cents'] ?? 0)) > 0
                ? MembershipAPI::money((int)$p['price_cents'], $p['currency'] ?? 'USD')
                : 'Free';
            $lines[] = '- ' . MembershipAPI::planName($p) . ' (' . $price . ' / ' . (int)($p['duration_days'] ?? 0) . ' days)'
                . (!empty($p['session_quota']) ? ', ' . (int)$p['session_quota'] . ' sessions included' : '')
                . (!empty($p['description']) ? ': ' . mb_substr(strip_tags((string)$p['description']), 0, 240) : '');
        }
        return implode("\n", $lines);
    }

    /** Read-only. Only the signed-in customer's own status — never another customer's. */
    private static function accountContext(): string {
        if (!class_exists('Auth') || !class_exists('MembershipAPI')) return '';
        $cust = Auth::customer();
        if (!$cust) return '';
        $cid = (int) ($cust['id'] ?? 0);
        if ($cid <= 0) return '';

        try {
            $status = MembershipAPI::status($cid);
            $sub = $status['sub'] ?? null;
        } catch (\Throwable $e) { return ''; }

        $name = (string) ($cust['name'] ?? $cust['email'] ?? 'this visitor');
        if (!$sub) {
            return "Name: {$name}\nNo active membership plan.";
        }

        $plan = MembershipAPI::plan((int) $sub['plan_id']);
        $lines = ["Name: {$name}", 'Active plan: ' . MembershipAPI::planName($plan ?: []), 'Valid until: ' . (string) ($sub['expires_at'] ?? '—')];

        $courseId = (int) ($plan['course_id'] ?? 0);
        if ($courseId > 0) {
            $cs = MembershipAPI::courseSubscriptionStatus($cid, $courseId);
            if (($cs['quota'] ?? 0) > 0) {
                $lines[] = 'Sessions: ' . (int) $cs['used'] . ' used of ' . (int) $cs['quota'] . ' (' . (int) $cs['remaining'] . ' remaining)';
            }
        }
        return implode("\n", $lines);
    }

    /** A light per-browser-session cap so one visitor can't run up the AI bill. */
    private static function rateLimitOk(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $now = time();
        $bucket = $_SESSION['ai_assistant_rl'] ?? ['start' => $now, 'count' => 0];
        if ($now - (int) $bucket['start'] > self::SESSION_WINDOW_SECONDS) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        $bucket['count']++;
        $_SESSION['ai_assistant_rl'] = $bucket;
        return $bucket['count'] <= self::MAX_TURNS_PER_SESSION_WINDOW;
    }

    private static function respond(int $code, array $body): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

// This file IS the route handler — Slate's public router requires it
// directly (see src/Kernel/Http/PublicRouter.php: `require $handler;`),
// it isn't invoked as a callable. Normally reached only via that router,
// which already loaded config.php; the guard below is just so this file
// doesn't fatal if ever hit directly.
if (!defined('SLATE_ROOT')) {
    require_once __DIR__ . '/../../config.php';
}
CustomerAssistant::handle((string) ($_GET['_route_path'] ?? ''), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
