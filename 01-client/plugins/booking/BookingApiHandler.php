<?php
/**
 * Booking — Headless & Mobile API Handler.
 *
 * Implements RESTful endpoints for mobile clients (iOS & Android) and headless apps:
 *   GET  /api/v1/booking/services
 *   GET  /api/v1/booking/slots?service_id=X&date=YYYY-MM-DD
 *   POST /api/v1/booking/appointments
 *   GET  /api/v1/booking/sync?since=YYYY-MM-DD+HH:MM:SS
 */

declare(strict_types=1);

use Slate\Kernel\Http\ApiRouter;

class BookingApiHandler {

    public static function handle(string $subPath, string $method, array $auth): void {
        $subPath = trim($subPath, '/');
        $parts   = explode('/', $subPath);
        $action  = $parts[0] ?? '';

        switch ($action) {
            case 'services':
                self::listServices();
                break;

            case 'slots':
                self::listSlots();
                break;

            case 'appointments':
                if ($method === 'POST') {
                    self::createAppointment($auth);
                } else {
                    self::getAppointment($parts[1] ?? '', $auth);
                }
                break;

            case 'sync':
                self::syncChanges($auth);
                break;

            default:
                ApiRouter::respondError("Unknown booking endpoint: '{$subPath}'", 'NOT_FOUND', 404);
        }
    }

    private static function listServices(): void {
        $tid = current_tenant_id();
        $rows = Database::rows(
            "SELECT s.id, s.name, s.slug, s.description, s.duration_min, s.price_cents,
                    s.payment_mode, s.deposit_value, s.capacity, s.category_id,
                    c.name AS category_name, s.updated_at
               FROM booking_services s
          LEFT JOIN booking_categories c ON c.id = s.category_id AND c.tenant_id = s.tenant_id
              WHERE s.tenant_id = ? AND s.is_active = 1
              ORDER BY s.sort_order ASC, s.name ASC",
            [$tid]
        );

        $services = array_map(static function (array $r): array {
            return [
                'id'               => (int)$r['id'],
                'name'             => (string)$r['name'],
                'slug'             => (string)$r['slug'],
                'description'      => (string)($r['description'] ?? ''),
                'duration_minutes' => (int)$r['duration_min'],
                'price_cents'      => (int)$r['price_cents'],
                'payment_mode'     => (string)$r['payment_mode'],
                'deposit_value'    => (int)($r['deposit_value'] ?? 0),
                'capacity'         => (int)($r['capacity'] ?? 1),
                'category'         => !empty($r['category_id']) ? [
                    'id'   => (int)$r['category_id'],
                    'name' => (string)($r['category_name'] ?? ''),
                ] : null,
                'updated_at'       => (string)$r['updated_at'],
            ];
        }, $rows);

        ApiRouter::respondSuccess($services, ['count' => count($services)]);
    }

    private static function listSlots(): void {
        $serviceId = (int)($_GET['service_id'] ?? 0);
        $date      = trim((string)($_GET['date'] ?? ''));
        $providerId= (int)($_GET['provider_id'] ?? 0);

        if ($serviceId <= 0) {
            ApiRouter::respondError("Missing or invalid 'service_id' query parameter.", 'INVALID_ARGUMENT', 400);
            return;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            ApiRouter::respondError("Missing or invalid 'date' parameter. Expected format: YYYY-MM-DD.", 'INVALID_ARGUMENT', 400);
            return;
        }

        $service = BookingAPI::getService($serviceId);
        if (!$service || empty($service['is_active'])) {
            ApiRouter::respondError("Service not found or inactive.", 'NOT_FOUND', 404);
            return;
        }

        if ($providerId > 0) {
            $times = BookingAPI::computeAvailableSlots($serviceId, $providerId, $date);
            $slots = array_map(static fn($t) => ['time' => $t, 'provider_id' => $providerId], $times);
        } else {
            $providers = BookingAPI::getProvidersForService($serviceId);
            $slotsMap = [];
            foreach ($providers as $p) {
                $pid = (int)$p['id'];
                $times = BookingAPI::computeAvailableSlots($serviceId, $pid, $date);
                foreach ($times as $t) {
                    if (!isset($slotsMap[$t])) {
                        $slotsMap[$t] = [
                            'time' => $t,
                            'available_providers' => [],
                            'provider_id' => $pid,
                        ];
                    }
                    $slotsMap[$t]['available_providers'][] = $pid;
                }
            }
            ksort($slotsMap);
            $slots = array_values($slotsMap);
        }

        ApiRouter::respondSuccess($slots, [
            'service_id' => $serviceId,
            'date'       => $date,
            'count'      => count($slots),
        ]);
    }

    private static function createAppointment(array $auth): void {
        $raw = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $serviceId  = (int)($body['service_id'] ?? 0);
        $providerId = (int)($body['provider_id'] ?? 0);
        $date       = trim((string)($body['date'] ?? ''));
        $slot       = trim((string)($body['slot'] ?? ''));
        $party      = max(1, (int)($body['party'] ?? 1));

        $name       = trim((string)($body['customer_name'] ?? ''));
        $email      = trim((string)($body['customer_email'] ?? ''));
        $phone      = trim((string)($body['customer_phone'] ?? ''));
        $notes      = trim((string)($body['notes'] ?? ''));

        if ($serviceId <= 0) {
            ApiRouter::respondError("Field 'service_id' is required.", 'VALIDATION_ERROR', 422);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $slot)) {
            ApiRouter::respondError("Valid 'date' (YYYY-MM-DD) and 'slot' (HH:MM) are required.", 'VALIDATION_ERROR', 422);
            return;
        }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ApiRouter::respondError("Valid 'customer_name' and 'customer_email' are required.", 'VALIDATION_ERROR', 422);
            return;
        }

        $service = BookingAPI::getService($serviceId);
        if (!$service || empty($service['is_active'])) {
            ApiRouter::respondError("Service not found or inactive.", 'NOT_FOUND', 404);
            return;
        }

        // Auto-assign provider if 0
        if ($providerId <= 0) {
            $providers = BookingAPI::getProvidersForService($serviceId);
            foreach ($providers as $p) {
                $pid = (int)$p['id'];
                $times = BookingAPI::computeAvailableSlots($serviceId, $pid, $date, $party);
                if (in_array($slot, $times, true)) {
                    $providerId = $pid;
                    break;
                }
            }
            if ($providerId <= 0 && !empty($providers)) {
                $providerId = (int)$providers[0]['id'];
            }
        }

        $startsAt = $date . ' ' . $slot . ':00';
        $duration = (int)($service['duration_min'] ?? 30);
        $endsAt   = date('Y-m-d H:i:s', strtotime($startsAt) + ($duration * 60));

        $res = BookingAPI::createAppointment([
            'service_id'     => $serviceId,
            'provider_id'    => $providerId > 0 ? $providerId : null,
            'starts_at'      => $startsAt,
            'ends_at'        => $endsAt,
            'party_size'     => $party,
            'customer_name'  => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'notes'          => $notes,
            'source'         => 'online',
        ]);

        if (empty($res['ok'])) {
            ApiRouter::respondError($res['error'] ?? 'Booking creation failed.', 'BOOKING_REJECTED', 422);
            return;
        }

        $apptId = (int)($res['id'] ?? 0);
        $appt = BookingAPI::getAppointment($apptId);

        ApiRouter::respondSuccess([
            'appointment_id' => $apptId,
            'ref'            => (string)($appt['ref'] ?? $res['ref'] ?? ''),
            'status'         => (string)($appt['status'] ?? $res['status'] ?? 'confirmed'),
            'service_name'   => (string)$service['name'],
            'starts_at'      => $startsAt,
            'ends_at'        => $endsAt,
            'manage_url'     => !empty($appt['manage_token']) ? SLATE_URL . '/book/manage?token=' . rawurlencode((string)$appt['manage_token']) : null,
        ], [], 201);
    }

    private static function getAppointment(string $refOrId, array $auth): void {
        $refOrId = trim($refOrId);
        if ($refOrId === '') {
            ApiRouter::respondError("Appointment reference or ID required.", 'INVALID_ARGUMENT', 400);
            return;
        }

        $isStaff = !empty($auth['authenticated']) && ($auth['type'] ?? '') === 'mcp_token';

        if ($isStaff) {
            // An authenticated bearer token stands for a staff/integration
            // caller (an MCP tool acting for the tenant), not a customer — it
            // may look up any of ITS OWN tenant's appointments by id/ref.
            // Scope explicitly to the token's own tenant via with_tenant(),
            // the same convention McpGatewayAPI::handleApiRoute() already uses
            // for every authenticated call, rather than trusting whatever the
            // process's ambient current_tenant_id() happens to be.
            $appt = with_tenant((int)$auth['tenant_id'], static function () use ($refOrId): ?array {
                if (is_numeric($refOrId)) {
                    return BookingAPI::getAppointment((int)$refOrId);
                }
                return BookingAPI::getAppointmentByRef($refOrId) ?? BookingAPI::findByManageToken($refOrId);
            });
        } else {
            // No credential presented: the only thing that may authorize
            // returning this appointment's PII to an anonymous caller is
            // possession of its manage_token — the same 128-bit secret the
            // confirmation email and the /book/manage page already require
            // (BookingAPI::findByManageToken(), tenant-scoped internally). A
            // raw numeric id or the public "BK-XXXXXXXX" reference is printed
            // on receipts/confirmations and is not a secret, so neither may
            // substitute for it here — that substitution was the bug.
            $appt = BookingAPI::findByManageToken($refOrId);
        }

        if (!$appt) {
            ApiRouter::respondError("Appointment not found.", 'NOT_FOUND', 404);
            return;
        }

        ApiRouter::respondSuccess([
            'id'             => (int)$appt['id'],
            'ref'            => (string)$appt['ref'],
            'status'         => (string)$appt['status'],
            'starts_at'      => (string)$appt['starts_at'],
            'ends_at'        => (string)$appt['ends_at'],
            'customer_name'  => (string)$appt['customer_name'],
            'customer_email' => (string)$appt['customer_email'],
            'service_name'   => (string)$appt['service_name'],
            'provider_name'  => (string)($appt['provider_name'] ?? ''),
        ]);
    }

    private static function syncChanges(array $auth): void {
        // A bulk, all-appointments delta sync has no per-customer credential
        // that could authorize it (unlike getAppointment(), a manage_token is
        // scoped to exactly one appointment) — it is inherently a staff/
        // integration operation, so it requires the same authenticated bearer
        // token every other privileged /api/v1 caller needs, and is scoped to
        // that token's own tenant rather than the process's ambient tenant.
        if (empty($auth['authenticated']) || ($auth['type'] ?? '') !== 'mcp_token') {
            ApiRouter::respondError('Unauthorized or expired API token.', 'UNAUTHORIZED', 401);
            return;
        }

        $tid = (int)$auth['tenant_id'];
        $since = trim((string)($_GET['since'] ?? ''));
        $now = slate_db_now();

        $serviceParams = [$tid];
        $serviceSql = "SELECT id, name, slug, description, duration_min, price_cents, payment_mode, category_id, is_active, updated_at
                         FROM booking_services
                        WHERE tenant_id = ?";
        if ($since !== '') {
            $serviceSql .= " AND updated_at >= ?";
            $serviceParams[] = $since;
        }
        $services = Database::rows($serviceSql, $serviceParams);

        $apptParams = [$tid];
        $apptSql = "SELECT id, ref, service_id, provider_id, status, starts_at, ends_at, customer_name, customer_email, updated_at
                      FROM booking_appointments
                     WHERE tenant_id = ?";
        if ($since !== '') {
            $apptSql .= " AND updated_at >= ?";
            $apptParams[] = $since;
        } else {
            $apptSql .= " AND starts_at >= CURDATE()";
        }
        $apptSql .= " ORDER BY starts_at ASC LIMIT 200";
        $appointments = Database::rows($apptSql, $apptParams);

        ApiRouter::respondSuccess([
            'server_time'         => $now,
            'services'            => $services,
            'appointments'        => $appointments,
            'deleted_service_ids' => [],
        ], [
            'since' => $since ?: null,
        ]);
    }
}
