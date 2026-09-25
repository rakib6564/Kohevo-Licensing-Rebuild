<?php
/**
 * Slate — AuditLog (moved).
 *
 * Phase 1 A3: the AuditLog class now lives at src/Services/Audit/AuditLog.php in
 * the Slate\Services\Audit namespace; the global name `AuditLog` is provided by
 * a class_alias in src/compat/aliases.php. This file remains only as a
 * backward-compat forwarder. Safe to remove in a future major.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/Audit/AuditLog.php';
