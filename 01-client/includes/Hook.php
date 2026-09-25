<?php
/**
 * Slate — Hook system (moved).
 *
 * Phase 1 A3: the Hook class now lives at src/Kernel/Event/Hook.php in the
 * Slate\Kernel\Event namespace, and the global name `Hook` is provided by a
 * class_alias in src/compat/aliases.php. This file remains only as a
 * backward-compat forwarder so any code that `require`s includes/Hook.php still
 * loads the class (without redeclaring it). Safe to remove in a future major
 * once nothing includes this path directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Kernel/Event/Hook.php';
