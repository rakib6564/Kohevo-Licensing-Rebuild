<?php
/**
 * Slate — I18n (moved).
 *
 * Phase 1 A3: the I18n class now lives at src/Services/I18n/I18n.php in the
 * Slate\Services\I18n namespace; the global name `I18n` is provided by a
 * class_alias in src/compat/aliases.php. This file remains only as a
 * backward-compat forwarder. Safe to remove in a future major.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/I18n/I18n.php';
