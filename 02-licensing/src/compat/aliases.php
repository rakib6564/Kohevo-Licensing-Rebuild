<?php
/**
 * Slate — backward-compatibility class aliases.
 *
 * As core classes migrate out of `includes/` into `Slate\*` namespaces (Phase 1
 * workstream A), each one registers a `class_alias` here so its **old global
 * name keeps resolving**. This is the promise that keeps the live site and every
 * installed plugin working unchanged — every `require_once`, every
 * `Database::query(...)`, and every plugin's `class_exists('BookingAPI')` keeps
 * returning the same answer.
 *
 * See docs/03-Standards/platform-foundation.md §10 and
 * docs/current-implementation/compatibility-matrix.md.
 *
 * These aliases are a COVERED compatibility surface — removed only in a future
 * major, never during Phase 1. Loaded at bootstrap immediately after the
 * autoloader.
 *
 * Phase 1 A1: intentionally EMPTY — no class has migrated yet. Entries are added
 * one at a time as each core class moves (leaf-first: Hook, I18n, AuditLog, …),
 * each with a smoke-tested commit.
 */

declare(strict_types=1);

// A3 — core classes migrated so far (leaf-first). Each keeps its old global name.
class_alias(\Slate\Kernel\Event\Hook::class, 'Hook');   // was includes/Hook.php
class_alias(\Slate\Services\I18n\I18n::class, 'I18n');  // was includes/I18n.php
class_alias(\Slate\Services\Audit\AuditLog::class, 'AuditLog'); // was includes/AuditLog.php
class_alias(\Slate\Services\Notifications\Notifications::class, 'Notifications'); // was includes/Notifications.php
class_alias(\Slate\Services\Media\Uploads::class, 'Uploads'); // was includes/Uploads.php
class_alias(\Slate\Services\Media\Media::class, 'Media'); // was includes/Media.php
class_alias(\Slate\Services\Notifications\Mailer::class, 'Mailer'); // was includes/Mailer.php
class_alias(\Slate\Kernel\Http\PublicRouter::class, 'PublicRouter'); // was includes/PublicRouter.php
class_alias(\Slate\Data\Database::class, 'Database'); // was includes/Database.php
class_alias(\Slate\Services\Auth\Auth::class, 'Auth'); // was includes/Auth.php
class_alias(\Slate\Kernel\Module\PluginLoader::class, 'PluginLoader'); // was includes/PluginLoader.php
class_alias(\Slate\Services\Portal\CustomerPortal::class, 'CustomerPortal');
class_alias(\Slate\Services\Notifications\BrandedEmail::class, 'BrandedEmail'); // shared branded email chrome, new — no includes/ predecessor
