<?php
/**
 * Studio — DanceStyle value object.
 *
 * The catalogue of class styles a studio offers. Stored as the string `value`
 * in studio_class_series.style; this enum documents the valid set and gives
 * callers a typed handle. Pure (Support-leaf level): no I/O, no dependencies.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

enum DanceStyle: string
{
    case BALLET       = 'ballet';
    case JAZZ         = 'jazz';
    case HIPHOP       = 'hiphop';
    case TAP          = 'tap';
    case CONTEMPORARY = 'contemporary';
    case ACRO         = 'acro';

    /** Human label for admin/portal display. */
    public function label(): string
    {
        return match ($this) {
            self::BALLET       => 'Ballet',
            self::JAZZ         => 'Jazz',
            self::HIPHOP       => 'Hip-Hop',
            self::TAP          => 'Tap',
            self::CONTEMPORARY => 'Contemporary',
            self::ACRO         => 'Acro',
        };
    }
}
