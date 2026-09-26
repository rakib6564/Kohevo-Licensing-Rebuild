<?php
// Phase 12 E2E only (copied into the throwaway central by run.sh): grant/revoke an optional module on a license through LicenseService.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../plugins/licensing/LicenseService.php';
[$op, $id, $module] = [$argv[1] ?? '', (int) ($argv[2] ?? 0), (string) ($argv[3] ?? '')];
if ($op === 'grant') LicenseService::grantModules($id, [$module]);
elseif ($op === 'revoke') LicenseService::revokeModule($id, $module);
echo json_encode(LicenseService::modules($id));
