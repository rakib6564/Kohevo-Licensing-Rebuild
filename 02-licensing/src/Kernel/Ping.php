<?php
/**
 * Slate — autoloader proof class (Phase 1 A1).
 *
 * The first class under `src/`. Its only purpose is to prove the PSR-4
 * autoloader resolves `Slate\Kernel\Ping` → `src/Kernel/Ping.php`. The smoke
 * suite asserts this. It has no runtime role and can be removed once real
 * kernel classes land.
 */

declare(strict_types=1);

namespace Slate\Kernel;

final class Ping
{
    public static function ok(): string
    {
        return 'slate-src-autoload-ok';
    }
}
