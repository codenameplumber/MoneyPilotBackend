<?php

namespace App\Support;

/**
 * The page sizes a console table offers.
 *
 * A class rather than a constant on the WithDataTable trait, because PHP does
 * not allow a trait constant to be read through the trait name — so a Blade
 * view could not reach it, and duplicating the list in the view would let the
 * dropdown and the validation drift apart.
 */
final class PageSize
{
    public const OPTIONS = [10, 25, 50, 100];

    public const DEFAULT = 25;

    public static function clamp(int $size): int
    {
        return in_array($size, self::OPTIONS, true) ? $size : self::DEFAULT;
    }
}
