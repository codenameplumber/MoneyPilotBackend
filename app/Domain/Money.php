<?php

namespace App\Domain;

/**
 * Money is integer kobo. Never a float.
 *
 * ₦45.00 is 4500. Every amount in this application is an integer from the
 * moment it is parsed to the moment it is formatted. The reason is not
 * pedantry: 0.1 + 0.2 !== 0.3, and in a budgeting app that drift shows up as
 * allocations that do not sum to income and category totals off by a kobo.
 *
 * PHP integers are 64-bit, so the ceiling is around ₦92 quadrillion. Not a
 * constraint we will meet.
 */
final class Money
{
    public const KOBO = 100;

    /** Naira (string with commas, ₦, or a number) → integer kobo. */
    public static function toKobo(mixed $naira): int
    {
        if (is_int($naira)) return $naira * self::KOBO;
        if (is_float($naira)) return (int) round($naira * self::KOBO);

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $naira);
        if ($clean === '' || $clean === '-' || $clean === '.') return 0;

        return (int) round(((float) $clean) * self::KOBO);
    }

    public static function toNaira(int $kobo): float
    {
        return $kobo / self::KOBO;
    }

    /** @param iterable<int|null> $values */
    public static function sum(iterable $values): int
    {
        $total = 0;
        foreach ($values as $v) $total += (int) $v;
        return $total;
    }

    /** Integer division that never under-funds a goal. */
    public static function ceilDiv(int $a, int $b): int
    {
        if ($b <= 0) return $a;
        return (int) ceil($a / $b);
    }

    public static function ratio(int|float $a, int|float $b): float
    {
        return $b == 0 ? 0.0 : $a / $b;
    }

    public static function clamp(int|float $v, int|float $lo, int|float $hi): int|float
    {
        return min(max($v, $lo), $hi);
    }

    /**
     * Split `$total` across weighted keys so the parts sum to EXACTLY total.
     *
     * Largest-remainder apportionment — the same method used for allocating
     * parliamentary seats. Naive rounding leaves a few kobo unaccounted for,
     * which surfaces in the UI as a budget that does not add up to the income
     * printed directly above it.
     *
     * @param  array<string,int|float> $weights
     * @return array<string,int>
     */
    public static function distribute(int $total, array $weights): array
    {
        $keys = array_keys(array_filter($weights, static fn ($w) => $w > 0));
        if (!$keys || $total <= 0) {
            return array_fill_keys($keys, 0);
        }

        $weightTotal = array_sum(array_map(static fn ($k) => $weights[$k], $keys));

        $exact  = [];
        $floors = [];
        foreach ($keys as $k) {
            $exact[$k]  = ($total * $weights[$k]) / $weightTotal;
            $floors[$k] = (int) floor($exact[$k]);
        }

        $remainder = $total - array_sum($floors);

        // Hand the leftover kobo to the largest fractional parts first.
        $fractions = [];
        foreach ($keys as $k) $fractions[$k] = $exact[$k] - $floors[$k];
        arsort($fractions);

        $out = $floors;
        foreach (array_keys($fractions) as $k) {
            if ($remainder <= 0) break;
            $out[$k]++;
            $remainder--;
        }

        return $out;
    }

    /* ---------------- formatting (display boundary only) ---------------- */

    /**
     * ₦12,400 — rounded to whole naira by default.
     *
     * Kobo are hidden unless asked for. Every derived figure in this app (a
     * projection, a per-day rate, an apportioned share) lands on a fraction,
     * and "₦94,488.57 over budget" is noise pretending to be precision.
     */
    public static function fmt(int $kobo, bool $exact = false, bool $sign = false): string
    {
        $value = self::toNaira(abs($kobo));
        $body  = $exact ? number_format($value, 2) : number_format(round($value));
        $prefix = $kobo < 0 ? '−' : ($sign ? '+' : '');

        return $prefix . '₦' . $body;
    }

    /** ₦12.4k · ₦1.2m — for tiles and axis labels where width is scarce. */
    public static function fmtShort(int $kobo): string
    {
        $v = self::toNaira(abs($kobo));
        $s = $kobo < 0 ? '−' : '';

        if ($v >= 1_000_000) return $s . '₦' . number_format($v / 1_000_000, $v >= 10_000_000 ? 0 : 1) . 'm';
        if ($v >= 10_000)    return $s . '₦' . number_format($v / 1000) . 'k';
        if ($v >= 1_000)     return $s . '₦' . number_format($v / 1000, 1) . 'k';

        return $s . '₦' . number_format($v);
    }

    public static function fmtPct(float $x, int $dp = 0): string
    {
        return number_format($x * 100, $dp) . '%';
    }
}
