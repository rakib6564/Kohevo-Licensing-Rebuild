<?php
/**
 * Slate — PluginLoader (moved).
 *
 * Phase 1 A3: the PluginLoader class now lives at
 * src/Kernel/Module/PluginLoader.php in the Slate\Kernel\Module namespace;
 * the global name `PluginLoader` is provided by a class_alias in
 * src/compat/aliases.php. This file remains only as a backward-compat forwarder.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Kernel/Module/PluginLoader.php';
