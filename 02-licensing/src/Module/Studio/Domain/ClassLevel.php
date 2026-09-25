<?php
/**
 * Studio — ClassLevel value object.
 *
 * Skill tier for a class series. Stored as the string `value` in
 * studio_class_series.level. Pure: no I/O, no dependencies.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

enum ClassLevel: string
{
    case BEGINNER     = 'beginner';
    case INTERMEDIATE = 'intermediate';
    case ADVANCED     = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::BEGINNER     => 'Beginner',
            self::INTERMEDIATE => 'Intermediate',
            self::ADVANCED     => 'Advanced',
        };
    }
}
