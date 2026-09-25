<?php
/**
 * Phase 1E C1 — \Slate\Kernel\Module\PluginLoader::boot()'s $booted guard ignored the
 * "please rebuild" signal activate()/deactivate()/uninstall() each send by
 * nulling $activeSlugs (their own comment: "// Invalidate caches").
 * isActive() correctly notices $activeSlugs === null and calls boot() to
 * rebuild it — but boot()'s guard was `if (self::$booted) return;`, which
 * is true forever after the request's first real boot, so that call did
 * nothing: $activeSlugs stayed null, and isActive() evaluated
 * isset(null[$slug]) — always false — for EVERY plugin, permanently, for
 * the rest of the process, after any single activate/deactivate/uninstall
 * call.
 *
 * This is a pure in-memory caching bug — no DB call is needed to reproduce
 * it, so it belongs in the unit suite (autoloader-only, no DB), matching
 * this harness's own stated scope.
 */

declare(strict_types=1);

unit('\Slate\Kernel\Module\PluginLoader::boot() rebuilds $activeSlugs after activate()/deactivate() null it out, instead of leaving isActive() permanently false', function (): void {
    $ref = new ReflectionClass(\Slate\Kernel\Module\PluginLoader::class);
    $booted = $ref->getProperty('booted');
    $activeSlugs = $ref->getProperty('activeSlugs');

    // Simulate: boot() already ran once this "request" (booted=true), with
    // some real active-plugin set already cached.
    $booted->setValue(null, true);
    $activeSlugs->setValue(null, ['some-plugin' => true]);
    assert_true(\Slate\Kernel\Module\PluginLoader::isActive('some-plugin'), 'sanity: the pre-seeded cache reports correctly before any invalidation');

    // Simulate exactly what activate()/deactivate()/uninstall() do: null out
    // $activeSlugs as a "please rebuild" signal, WITHOUT resetting $booted
    // (matching their actual, unmodified source).
    $activeSlugs->setValue(null, null);

    // Before the fix, this returned false forever: boot()'s guard saw
    // $booted===true and returned immediately without rebuilding, so
    // isActive() kept evaluating isset(null['some-plugin']).
    //
    // After the fix (boot() only skips when BOTH booted AND activeSlugs
    // are already populated), isActive() sees activeSlugs===null and
    // actually calls boot() again, which repopulates it — reachable here
    // because boot()'s DB query harmlessly no-ops/returns whatever is
    // really in the `plugins` table in this process (irrelevant to this
    // assertion; what matters is that $activeSlugs is no longer stuck at
    // null and isActive() is no longer unconditionally false).
    \Slate\Kernel\Module\PluginLoader::isActive('some-plugin'); // triggers the rebuild path
    assert_true($activeSlugs->getValue(null) !== null, 'boot() must actually rebuild $activeSlugs when it was nulled out, not leave it null forever');

    // Restore real state so later tests in this same process (unit suite
    // runs many files together) see the real, correct plugin set again.
    $booted->setValue(null, false);
    $activeSlugs->setValue(null, null);
    \Slate\Kernel\Module\PluginLoader::isActive('__reset_probe__'); // forces a real re-boot from the actual (test-harness-irrelevant, DB-less-here) source
});
