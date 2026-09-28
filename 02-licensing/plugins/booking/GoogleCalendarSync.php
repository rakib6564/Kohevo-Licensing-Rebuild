<?php
/**
 * Booking — Google Calendar 2-way sync.
 *
 * Model: per-PROVIDER connection (each staff member connects their own
 * Google account from their profile in admin/providers.php), matching how
 * a real calendar/scheduling business works — not one shared calendar for
 * everyone.
 *
 * PUSH (Slate → Google): BookingAPI fires 'booking_created' /
 * 'booking_cancelled' / 'booking_rescheduled' on every appointment change
 * (these hooks already existed for reminders/messaging); Booking::boot()
 * wires listeners here that create/update/delete the matching Google event.
 * A push failure never breaks the booking flow — Hook::doAction() already
 * catches and logs listener exceptions — it just leaves the appointment's
 * google_sync_status as 'pending'/'error' so the cron sweep below retries it.
 *
 * PULL (Google → Slate): incremental sync via Calendar API's syncToken
 * (see https://developers.google.com/calendar/api/guides/sync), run on
 * every `frequent_cron` tick (the site's existing ~5-minute cron, see
 * cron.php) as the reliable baseline, PLUS an optional real-time push-
 * notification channel (Google "watch") on installs with a public HTTPS
 * SLATE_URL — see public/gcal-webhook.php. A pulled change either:
 *   - matches a google_event_id we track  → an appointment was moved or
 *     cancelled directly in Google; the change is reflected onto the
 *     Slate appointment row.
 *   - matches nothing we track            → it's the provider's own event
 *     (a meeting, a day off, anything not created by Slate) and is cached
 *     into booking_google_busy_blocks, which BookingAPI::effectiveIntervals()
 *     subtracts from availability — this is what stops Slate from offering
 *     a slot the provider is actually busy for in real life.
 *
 * Every secret (OAuth client secret, per-provider access/refresh tokens) is
 * stored via slate_encrypt_secret()/slate_decrypt_secret() (AES-256-GCM,
 * keyed off APP_SECRET) rather than in plaintext.
 */

class GoogleCalendarSync
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://www.googleapis.com/calendar/v3';
    private const SCOPES    = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly';

    // ── Configuration ────────────────────────────────────────────

    public static function isConfigured(): bool {
        return (string)(Database::setting('booking.google_client_id') ?? '') !== ''
            && (string)(Database::setting('booking.google_client_secret') ?? '') !== '';
    }

    public static function isEnabled(): bool {
        return (string)(Database::setting('booking.google_enabled') ?? '0') === '1' && self::isConfigured();
    }

    private static function clientId(): string {
        return (string)(Database::setting('booking.google_client_id') ?? '');
    }

    private static function clientSecret(): ?string {
        $enc = (string)(Database::setting('booking.google_client_secret') ?? '');
        if ($enc === '') return null;
        return slate_decrypt_secret($enc);
    }

    private static function redirectUri(): string {
        return rtrim(SLATE_URL, '/') . '/plugins/booking/admin/gcal-callback.php';
    }

    private static function webhookUrl(): string {
        return rtrim(SLATE_URL, '/') . '/plugins/booking/public/gcal-webhook.php';
    }

    /** Whether SLATE_URL is a public HTTPS address Google can actually reach with a push notification. */
    private static function canReceivePush(): bool {
        $url = SLATE_URL;
        if (!str_starts_with($url, 'https://')) return false;
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        return $host !== '' && $host !== 'localhost' && !str_ends_with($host, '.local')
            && !preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
    }

    // ── OAuth: connect / disconnect ─────────────────────────────

    /** Build the Google consent-screen URL for connecting $providerId. $state carries a CSRF nonce + the provider id. */
    public static function authUrl(int $providerId, string $csrfNonce): string {
        $state = base64_encode(json_encode(['pid' => $providerId, 'n' => $csrfNonce]));
        $params = [
            'client_id'              => self::clientId(),
            'redirect_uri'           => self::redirectUri(),
            'response_type'          => 'code',
            'scope'                  => self::SCOPES,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',        // guarantees a refresh_token even on reconnect
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /** Decode+verify the `state` param from the OAuth callback. Returns null if it doesn't check out. */
    public static function decodeState(string $state, string $expectedNonce): ?int {
        $raw = base64_decode($state, true);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['pid'], $data['n'])) return null;
        if (!hash_equals($expectedNonce, (string)$data['n'])) return null;
        return (int)$data['pid'];
    }

    /**
     * Exchange an OAuth `code` for tokens and store them on the provider row.
     * Returns ['ok'=>true,'email'=>string] or ['ok'=>false,'error'=>string].
     */
    public static function connectProvider(int $providerId, string $code): array {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'Google Calendar isn\'t configured yet — add a Client ID/Secret in Booking settings first.'];

        $resp = self::httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => self::clientId(),
            'client_secret' => (string) self::clientSecret(),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (!$resp['ok'] || empty($resp['body']['access_token'])) {
            slate_log('GoogleCalendarSync: token exchange failed: ' . ($resp['body']['error_description'] ?? $resp['error'] ?? 'unknown'), 'error');
            return ['ok' => false, 'error' => 'Google didn\'t confirm the connection. Please try again.'];
        }
        $tokens = $resp['body'];
        if (empty($tokens['refresh_token'])) {
            // Happens if the user had already granted consent and Google
            // skipped issuing a new refresh_token despite prompt=consent
            // (rare, but possible if scopes are identical to a still-valid
            // prior grant). Ask them to revoke access in their Google
            // Account and reconnect rather than storing a connection that
            // can't survive an access-token expiry.
            return ['ok' => false, 'error' => 'Google didn\'t grant a long-lived connection. In your Google Account → Security → Third-party access, remove any existing access for this app, then try connecting again.'];
        }

        $tid = current_tenant_id();
        $expiresAt = date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? 3600));

        // Discover the connected account's calendar id (== email, for the
        // primary calendar) with the token we just minted.
        $cal = self::apiGet('/calendars/primary', (string)$tokens['access_token']);
        $email = $cal['ok'] ? (string)($cal['body']['id'] ?? '') : '';

        Database::update('booking_providers', [
            'google_calendar_id'     => 'primary',
            'google_access_token'    => slate_encrypt_secret((string)$tokens['access_token']),
            'google_refresh_token'   => slate_encrypt_secret((string)$tokens['refresh_token']),
            'google_token_expires_at'=> $expiresAt,
            'google_connected_email' => $email,
            'google_sync_token'      => null, // force a full initial sync
        ], 'id = ? AND tenant_id = ?', [$providerId, $tid]);

        AuditLog::record('booking.google_connected', (string)$providerId, ['email' => $email]);

        // Best-effort: register a push channel right away if this install can receive one.
        try { self::registerWatch($providerId); } catch (\Throwable $e) { /* cron sweep will retry */ }

        // Best-effort: pull once immediately so busy blocks/availability are fresh right away.
        try { self::pullChanges($providerId); } catch (\Throwable $e) { /* cron sweep will retry */ }

        return ['ok' => true, 'email' => $email];
    }

    public static function disconnectProvider(int $providerId): void {
        $tid = current_tenant_id();
        $provider = Database::row("SELECT * FROM booking_providers WHERE id = ? AND tenant_id = ?", [$providerId, $tid]);
        if ($provider && !empty($provider['google_refresh_token'])) {
            $token = slate_decrypt_secret((string)$provider['google_refresh_token']);
            if ($token) {
                // Best-effort revoke; a failure here shouldn't block disconnecting locally.
                self::httpPost('https://oauth2.googleapis.com/revoke', ['token' => $token]);
            }
            if (!empty($provider['google_watch_channel_id'])) {
                self::stopWatch($provider);
            }
        }
        Database::update('booking_providers', [
            'google_calendar_id'      => null,
            'google_access_token'     => null,
            'google_refresh_token'    => null,
            'google_token_expires_at' => null,
            'google_connected_email'  => null,
            'google_sync_token'       => null,
            'google_watch_channel_id'   => null,
            'google_watch_resource_id' => null,
            'google_watch_expires_at'  => null,
        ], 'id = ? AND tenant_id = ?', [$providerId, $tid]);
        Database::delete('booking_google_busy_blocks', 'provider_id = ? AND tenant_id = ?', [$providerId, $tid]);
        AuditLog::record('booking.google_disconnected', (string)$providerId);
    }

    /** A valid (non-expired) access token for $provider, refreshing it first if needed. Null if not connected or refresh fails. */
    private static function accessToken(array $provider): ?string {
        if (empty($provider['google_refresh_token'])) return null;
        $expiresAt = strtotime((string)($provider['google_token_expires_at'] ?? ''));
        // 2-minute safety margin so we never send an access token that expires mid-request.
        if ($expiresAt !== false && $expiresAt > time() + 120 && !empty($provider['google_access_token'])) {
            return slate_decrypt_secret((string)$provider['google_access_token']);
        }

        $refreshToken = slate_decrypt_secret((string)$provider['google_refresh_token']);
        if (!$refreshToken || !self::isConfigured()) return null;

        $resp = self::httpPost(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => self::clientId(),
            'client_secret' => (string) self::clientSecret(),
            'grant_type'    => 'refresh_token',
        ]);
        if (!$resp['ok'] || empty($resp['body']['access_token'])) {
            slate_log('GoogleCalendarSync: access-token refresh failed for provider ' . $provider['id'] . ': ' . ($resp['body']['error'] ?? $resp['error'] ?? 'unknown'), 'warning');
            return null;
        }
        $accessToken = (string)$resp['body']['access_token'];
        $expiresAtSql = date('Y-m-d H:i:s', time() + (int)($resp['body']['expires_in'] ?? 3600));
        Database::update('booking_providers', [
            'google_access_token'     => slate_encrypt_secret($accessToken),
            'google_token_expires_at' => $expiresAtSql,
        ], 'id = ?', [(int)$provider['id']]);
        return $accessToken;
    }

    // ── PUSH: Slate change → Google event ───────────────────────

    public static function syncCreated(int $apptId): void {
        if (!self::isEnabled()) return;
        self::pushAppointment($apptId);
    }

    public static function syncRescheduled(int $apptId): void {
        if (!self::isEnabled()) return;
        self::pushAppointment($apptId);
    }

    public static function syncCancelled(int $apptId): void {
        if (!self::isEnabled()) return;
        $tid  = current_tenant_id();
        $appt = Database::row("SELECT * FROM booking_appointments WHERE id = ? AND tenant_id = ?", [$apptId, $tid]);
        if (!$appt || empty($appt['google_event_id']) || empty($appt['provider_id'])) return;
        $provider = Database::row("SELECT * FROM booking_providers WHERE id = ? AND tenant_id = ?", [(int)$appt['provider_id'], $tid]);
        if (!$provider || empty($provider['google_refresh_token'])) return;

        $token = self::accessToken($provider);
        if (!$token) return;
        self::apiDelete('/calendars/' . rawurlencode((string)$provider['google_calendar_id']) . '/events/' . rawurlencode((string)$appt['google_event_id']), $token);
        Database::update('booking_appointments', [
            'google_event_id'   => null,
            'google_sync_status'=> 'synced',
            'google_synced_at'  => slate_db_now(),
        ], 'id = ?', [$apptId]);
    }

    /** Create (or update, if already synced once) the Google event for one appointment. */
    private static function pushAppointment(int $apptId): void {
        $tid  = current_tenant_id();
        $appt = Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr FROM booking_appointments a
               JOIN booking_services s ON s.id = a.service_id
              WHERE a.id = ? AND a.tenant_id = ?",
            [$apptId, $tid]
        );
        if (!$appt || empty($appt['provider_id'])) return;
        if (in_array($appt['status'], ['cancelled'], true)) return; // syncCancelled() owns this case

        $provider = Database::row("SELECT * FROM booking_providers WHERE id = ? AND tenant_id = ?", [(int)$appt['provider_id'], $tid]);
        if (!$provider || empty($provider['google_refresh_token'])) return;

        $token = self::accessToken($provider);
        if (!$token) {
            Database::update('booking_appointments', ['google_sync_status' => 'error'], 'id = ?', [$apptId]);
            return;
        }

        $tz = (string)(Database::setting('timezone') ?: 'UTC');
        $event = [
            'summary'     => $appt['service_name'] . ' — ' . $appt['customer_name'],
            'description' => trim(
                ($appt['notes'] ? $appt['notes'] . "\n\n" : '')
                . 'Booked via Kohevo. Ref: ' . $appt['ref'] . "\n"
                . 'Customer: ' . $appt['customer_name']
                . ($appt['customer_email'] ? ' <' . $appt['customer_email'] . '>' : '')
                . ($appt['customer_phone'] ? ' · ' . $appt['customer_phone'] : '')
            ),
            'start'       => ['dateTime' => str_replace(' ', 'T', (string)$appt['starts_at']), 'timeZone' => $tz],
            'end'         => ['dateTime' => str_replace(' ', 'T', (string)$appt['ends_at']),   'timeZone' => $tz],
            'extendedProperties' => ['private' => ['slate_appointment_id' => (string)$apptId]],
        ];

        $calId = rawurlencode((string)$provider['google_calendar_id']);
        if (!empty($appt['google_event_id'])) {
            $resp = self::apiRequest('PATCH', "/calendars/{$calId}/events/" . rawurlencode((string)$appt['google_event_id']), $token, $event);
            if (!$resp['ok'] && (int)($resp['status'] ?? 0) === 404) {
                // The event was deleted on the Google side out from under us — recreate it.
                $appt['google_event_id'] = null;
            }
        }
        if (empty($appt['google_event_id'])) {
            $resp = self::apiRequest('POST', "/calendars/{$calId}/events", $token, $event);
        }

        if (!empty($resp['ok']) && !empty($resp['body']['id'])) {
            Database::update('booking_appointments', [
                'google_event_id'    => (string)$resp['body']['id'],
                'google_sync_status' => 'synced',
                'google_synced_at'   => slate_db_now(),
            ], 'id = ?', [$apptId]);
        } else {
            slate_log("GoogleCalendarSync: push failed for appointment {$apptId}: " . json_encode($resp['body'] ?? $resp['error'] ?? null), 'warning');
            Database::update('booking_appointments', ['google_sync_status' => 'error'], 'id = ?', [$apptId]);
        }
    }

    // ── PULL: Google change → Slate ──────────────────────────────

    /**
     * Incremental (or, on first run / an expired syncToken, full) sync of
     * one provider's calendar. Reconciles cancellations/moves of events we
     * created, and refreshes the busy-block cache for everything else.
     */
    public static function pullChanges(int $providerId): void {
        $tid = current_tenant_id();
        $provider = Database::row("SELECT * FROM booking_providers WHERE id = ? AND tenant_id = ?", [$providerId, $tid]);
        if (!$provider || empty($provider['google_refresh_token'])) return;

        $token = self::accessToken($provider);
        if (!$token) return;

        $calId = rawurlencode((string)$provider['google_calendar_id']);
        $syncToken = $provider['google_sync_token'] ?? null;
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $params = ['maxResults' => 250, 'showDeleted' => 'true', 'singleEvents' => 'true'];
            if ($syncToken) {
                $params['syncToken'] = $syncToken;
            } else {
                // First sync (or a stale/expired token, see the 410 handling
                // below): bound it to a sane window instead of the account's
                // entire event history — 90 days back, 1 year forward.
                $params['timeMin'] = gmdate('c', time() - 90 * 86400);
                $params['timeMax'] = gmdate('c', time() + 366 * 86400);
            }
            if ($pageToken) $params['pageToken'] = $pageToken;

            $resp = self::apiGet('/calendars/' . $calId . '/events?' . http_build_query($params), $token);

            if (!$resp['ok']) {
                if ((int)($resp['status'] ?? 0) === 410) {
                    // Sync token expired/invalid — Google's documented signal to
                    // discard it and do a full resync from scratch.
                    Database::update('booking_providers', ['google_sync_token' => null], 'id = ?', [$providerId]);
                    if ($syncToken !== null) { self::pullChanges($providerId); }
                    return;
                }
                slate_log("GoogleCalendarSync: pull failed for provider {$providerId}: " . json_encode($resp['body'] ?? $resp['error'] ?? null), 'warning');
                return;
            }

            foreach ((array)($resp['body']['items'] ?? []) as $ev) {
                self::reconcileEvent($providerId, $tid, $ev);
            }
            $pageToken = $resp['body']['nextPageToken'] ?? null;
            if (!$pageToken) $nextSyncToken = $resp['body']['nextSyncToken'] ?? null;
        } while ($pageToken);

        if ($nextSyncToken) {
            Database::update('booking_providers', ['google_sync_token' => $nextSyncToken], 'id = ?', [$providerId]);
        }
    }

    /** Apply one Google event (created/changed/deleted) to Slate's side. */
    private static function reconcileEvent(int $providerId, int $tid, array $ev): void {
        $eventId  = (string)($ev['id'] ?? '');
        if ($eventId === '') return;
        $deleted  = ($ev['status'] ?? '') === 'cancelled';
        $slateId  = (int)($ev['extendedProperties']['private']['slate_appointment_id'] ?? 0);

        if ($slateId > 0) {
            // An event Slate itself created. If it's gone or moved outside
            // of Slate, reflect that back — this is the "2-way" half of the
            // sync: the calendar is the source of truth once a human has
            // touched the event there directly.
            $appt = Database::row("SELECT * FROM booking_appointments WHERE id = ? AND tenant_id = ? AND google_event_id = ?", [$slateId, $tid, $eventId]);
            if (!$appt) return;
            if ($deleted) {
                if (!in_array($appt['status'], ['cancelled', 'completed', 'no_show'], true)) {
                    BookingAPI::cancelAppointment($slateId, 'Cancelled via Google Calendar', true);
                    if (class_exists('Notifications')) {
                        Notifications::add(__('booking_notif_gcal_cancelled', 'Booking cancelled via Google Calendar') . ' · ' . $appt['ref'], [
                            'body' => sprintf(
                                __('booking_notif_gcal_cancelled_body', 'Removed directly from Google Calendar by %s\'s connected calendar.'),
                                ($appt['customer_name'] ?: __('booking_notif_the_provider', 'the provider'))
                            ),
                            'url'  => plugin_url('booking', 'admin/appointment.php') . '?id=' . $slateId,
                            'icon' => 'x-circle',
                        ]);
                    }
                }
                return;
            }
            $newStart = isset($ev['start']['dateTime']) ? str_replace('T', ' ', substr((string)$ev['start']['dateTime'], 0, 19)) : null;
            if ($newStart && $newStart !== $appt['starts_at'] && !in_array($appt['status'], ['cancelled', 'completed', 'no_show'], true)) {
                // Move made directly in Google — apply it without re-running the
                // customer-facing self-service policy (this is a calendar-owner
                // action, equivalent to an admin reschedule). Slate's own
                // availability rules (hours, capacity, min-advance) still apply;
                // if the moved time fails them, we log it and leave the Slate
                // appointment where it was rather than silently drop the change —
                // the next pull will keep retrying until it's resolved by hand.
                $moveResult = BookingAPI::rescheduleAppointment($slateId, $newStart, true);
                if (empty($moveResult['ok'])) {
                    slate_log("GoogleCalendarSync: appointment {$slateId} was moved in Google to {$newStart} but Slate rejected it ({$moveResult['error']}) — left at its original time.", 'warning');
                } elseif (class_exists('Notifications')) {
                    Notifications::add(__('booking_notif_gcal_rescheduled', 'Booking rescheduled via Google Calendar') . ' · ' . $appt['ref'], [
                        'body' => sprintf(
                            __('booking_notif_gcal_rescheduled_body', 'Moved directly in Google Calendar to %s.'),
                            I18n::localDate('D j M, g:ia', strtotime($newStart))
                        ),
                        'url'  => plugin_url('booking', 'admin/appointment.php') . '?id=' . $slateId,
                        'icon' => 'calendar',
                    ]);
                }
            }
            return;
        }

        // Not a Slate-created event: cache it as a busy block for freebusy
        // blocking, or remove the cached block if it was deleted/cancelled.
        if ($deleted) {
            Database::delete('booking_google_busy_blocks', 'provider_id = ? AND tenant_id = ? AND google_event_id = ?', [$providerId, $tid, $eventId]);
            return;
        }
        $start = $ev['start']['dateTime'] ?? $ev['start']['date'] ?? null;
        $end   = $ev['end']['dateTime']   ?? $ev['end']['date']   ?? null;
        if (!$start || !$end) return;
        $startsAt = date('Y-m-d H:i:s', strtotime((string)$start));
        $endsAt   = date('Y-m-d H:i:s', strtotime((string)$end));

        $existing = Database::row("SELECT id FROM booking_google_busy_blocks WHERE provider_id = ? AND tenant_id = ? AND google_event_id = ?", [$providerId, $tid, $eventId]);
        if ($existing) {
            Database::update('booking_google_busy_blocks', ['starts_at' => $startsAt, 'ends_at' => $endsAt], 'id = ?', [(int)$existing['id']]);
        } else {
            Database::insert('booking_google_busy_blocks', [
                'tenant_id'       => $tid,
                'provider_id'     => $providerId,
                'google_event_id' => $eventId,
                'starts_at'       => $startsAt,
                'ends_at'         => $endsAt,
                'created_at'      => slate_db_now(),
            ]);
        }
    }

    // ── Push-notification (webhook) channels ─────────────────────

    /** Register a Google "watch" channel for near-real-time updates. No-op on installs without a public HTTPS SLATE_URL. */
    public static function registerWatch(int $providerId): void {
        if (!self::canReceivePush()) return;
        $tid = current_tenant_id();
        $provider = Database::row("SELECT * FROM booking_providers WHERE id = ? AND tenant_id = ?", [$providerId, $tid]);
        if (!$provider || empty($provider['google_refresh_token'])) return;

        $token = self::accessToken($provider);
        if (!$token) return;

        $channelId = bin2hex(random_bytes(16));
        $resp = self::apiRequest('POST', '/calendars/' . rawurlencode((string)$provider['google_calendar_id']) . '/events/watch', $token, [
            'id'      => $channelId,
            'type'    => 'web_hook',
            'address' => self::webhookUrl(),
            'token'   => (string) slate_sign('booking_gcal_watch', (string)$providerId), // returned to us on every notification; verified in the webhook
        ]);
        if (!empty($resp['ok']) && !empty($resp['body']['resourceId'])) {
            Database::update('booking_providers', [
                'google_watch_channel_id'  => $channelId,
                'google_watch_resource_id' => (string)$resp['body']['resourceId'],
                // Google returns expiration as epoch millis.
                'google_watch_expires_at'  => date('Y-m-d H:i:s', (int)(((int)($resp['body']['expiration'] ?? 0)) / 1000)),
            ], 'id = ?', [$providerId]);
        }
    }

    private static function stopWatch(array $provider): void {
        $token = self::accessToken($provider);
        if (!$token) return;
        self::apiRequest('POST', '/channels/stop', $token, [
            'id'         => (string)$provider['google_watch_channel_id'],
            'resourceId' => (string)$provider['google_watch_resource_id'],
        ]);
    }

    /** Called by public/gcal-webhook.php when Google notifies us of a change. */
    public static function handleWebhook(string $channelId, string $resourceId, string $tokenGiven): void {
        $provider = Database::row("SELECT * FROM booking_providers WHERE google_watch_channel_id = ? AND google_watch_resource_id = ?", [$channelId, $resourceId]);
        if (!$provider) return;
        if (!slate_sign_equals('booking_gcal_watch', (string)$provider['id'], $tokenGiven)) return; // forged/stale notification
        self::pullChanges((int)$provider['id']);
    }

    // ── Cron sweep (frequent_cron) ─────────────────────────────────

    /** Runs on every cron tick: pulls each connected provider, retries failed pushes, renews expiring watch channels. */
    public static function runCron(): void {
        if (!self::isEnabled()) return;
        if (!ModuleGuard::allows('booking')) return;
        $tid = current_tenant_id();

        $providers = Database::rows("SELECT * FROM booking_providers WHERE tenant_id = ? AND google_refresh_token IS NOT NULL", [$tid]);
        foreach ($providers as $provider) {
            try { self::pullChanges((int)$provider['id']); }
            catch (\Throwable $e) { slate_log('GoogleCalendarSync cron pull failed for provider ' . $provider['id'] . ': ' . $e->getMessage(), 'warning'); }

            $expiresAt = strtotime((string)($provider['google_watch_expires_at'] ?? ''));
            if (self::canReceivePush() && ($expiresAt === false || $expiresAt < time() + 86400)) {
                try { self::registerWatch((int)$provider['id']); }
                catch (\Throwable $e) { slate_log('GoogleCalendarSync watch renewal failed for provider ' . $provider['id'] . ': ' . $e->getMessage(), 'warning'); }
            }
        }

        // Safety-net retries: any appointment a push failed for earlier.
        $pending = Database::rows(
            "SELECT id FROM booking_appointments
              WHERE tenant_id = ? AND google_sync_status IN ('pending','error')
                AND status NOT IN ('cancelled') AND provider_id IS NOT NULL
                AND starts_at > ?
              LIMIT 50",
            [$tid, slate_db_now()]
        );
        foreach ($pending as $row) {
            try { self::pushAppointment((int)$row['id']); }
            catch (\Throwable $e) { slate_log('GoogleCalendarSync retry-push failed for appointment ' . $row['id'] . ': ' . $e->getMessage(), 'warning'); }
        }

        Database::setSetting('booking.google_last_cron_at', slate_db_now());
    }

    /** For the admin "Sync now" button — same work as runCron(), synchronous, returns a human summary. */
    public static function syncNow(): string {
        if (!self::isEnabled()) return 'Google Calendar sync isn\'t enabled.';
        $before = (int) Database::value("SELECT COUNT(*) FROM booking_providers WHERE tenant_id = ? AND google_refresh_token IS NOT NULL", [current_tenant_id()]);
        self::runCron();
        return $before === 0
            ? 'No providers are connected yet.'
            : "Synced {$before} connected provider" . ($before === 1 ? '' : 's') . '.';
    }

    // ── HTTP helpers (same cURL pattern as BookingAPI::twilioSend()) ─────

    private static function httpPost(string $url, array $fields): array {
        return self::curl($url, 'POST', http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    private static function apiGet(string $path, string $token): array {
        return self::curl(self::API_BASE . $path, 'GET', null, ['Authorization: Bearer ' . $token]);
    }

    private static function apiDelete(string $path, string $token): array {
        return self::curl(self::API_BASE . $path, 'DELETE', null, ['Authorization: Bearer ' . $token]);
    }

    private static function apiRequest(string $method, string $path, string $token, array $jsonBody): array {
        return self::curl(self::API_BASE . $path, $method, json_encode($jsonBody), [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
    }

    /** @return array{ok:bool, status:int, body:mixed, error:?string} */
    private static function curl(string $url, string $method, ?string $body, array $headers): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl extension unavailable'];
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) $decoded = $raw;
        }
        return ['ok' => $error === null && $code >= 200 && $code < 300, 'status' => $code, 'body' => $decoded, 'error' => $error];
    }
}
