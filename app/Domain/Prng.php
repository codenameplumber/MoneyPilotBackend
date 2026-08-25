<?php

namespace App\Domain;

/**
 * Deterministic pseudo-randomness — mulberry32, ported from the prototype.
 *
 * The seeded price corpus must be byte-identical on every run, or the demo
 * tells a different story each time it is opened in front of a judge.
 * mt_rand() seeded globally is not enough: seeders run in an order that can
 * change, and one extra call anywhere shifts every subsequent value. An
 * explicit stream per (item, location) pair is immune to that.
 */
final class Prng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
    }

    /** Seed a stream from any number of string parts. */
    public static function seeded(string ...$parts): self
    {
        return new self(self::hash(implode('|', $parts)));
    }

    /** FNV-1a, 32-bit — the same hash the JavaScript engine uses. */
    public static function hash(string $s): int
    {
        $h = 2166136261;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $h ^= ord($s[$i]);
            // 32-bit multiply without overflowing into float territory.
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        return $h;
    }

    /** Next float in [0, 1). */
    public function next(): float
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = ($this->imul($t ^ ($t >> 15), $t | 1)) & 0xFFFFFFFF;
        $t ^= ($t + $this->imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;
        $t = ($t ^ ($t >> 14)) & 0xFFFFFFFF;

        return $t / 4294967296;
    }

    /** Roughly-normal deviate in [-spread, spread], flat tails trimmed. */
    public function jitter(float $spread = 1.0): float
    {
        return (($this->next() + $this->next() + $this->next()) / 3 - 0.5) * 2 * $spread;
    }

    public function pick(array $items): mixed
    {
        return $items[(int) floor($this->next() * count($items)) % count($items)];
    }

    /** 32-bit integer multiply, matching Math.imul. */
    private function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $aHi = ($a >> 16) & 0xFFFF;
        $aLo = $a & 0xFFFF;
        $bHi = ($b >> 16) & 0xFFFF;
        $bLo = $b & 0xFFFF;

        return ((($aLo * $bLo) + ((((($aHi * $bLo) + ($aLo * $bHi)) & 0xFFFF) << 16))) & 0xFFFFFFFF);
    }
}
