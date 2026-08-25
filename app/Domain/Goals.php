<?php

namespace App\Domain;

/**
 * Savings goals.
 *
 * The required monthly contribution is DERIVED on every read and never
 * stored. Deadlines move, balances change, and a cached "₦2,760 a month"
 * that quietly stopped being true is worse than no number at all.
 */
final class Goals
{
    /** @param array{target:int,saved:int,months_left:int,original_months:?int} $goal */
    public static function required(array $goal): array
    {
        $months      = max(1, (int) ($goal['months_left'] ?? 1));
        $outstanding = max(0, (int) $goal['target'] - (int) $goal['saved']);

        return [
            'months'      => $months,
            'outstanding' => $outstanding,
            'monthly'     => Money::ceilDiv($outstanding, $months),
            'progress'    => Money::ratio($goal['saved'], max(1, $goal['target'])),
            'done'        => $outstanding === 0,
        ];
    }

    public static function requiredTotal(array $goals): int
    {
        $t = 0;
        foreach ($goals as $g) $t += self::required($g)['monthly'];
        return $t;
    }

    /**
     * Goal drift: what the goal demands now against what it demanded when it
     * was set. A rising number means the member is falling behind, and
     * saying so early is the whole point of a co-pilot.
     */
    public static function drift(array $goal): array
    {
        $now = self::required($goal);
        $originalMonths = (int) ($goal['original_months'] ?? $now['months']);
        $original = Money::ceilDiv((int) $goal['target'], max(1, $originalMonths));

        return [
            'now'      => $now['monthly'],
            'original' => $original,
            'delta'    => $now['monthly'] - $original,
            'behind'   => $now['monthly'] > $original * 1.08,
        ];
    }
}
