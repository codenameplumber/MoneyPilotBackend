<?php

namespace App\Domain;

/**
 * The survival floor.
 *
 * This is step 3 of the allocation pipeline and it is the entire thesis of
 * MoneyPilot. Every other budgeting app computes "distribute what's left by
 * preference weight" and calls it a product. The reason this is a different
 * thing is that the floor underneath the budget is MEASURED, not assumed:
 * the actual cost of feeding this many people, in this LGA, at this month's
 * median prices.
 *
 * Two users with identical incomes in Ikeja and Nsukka get materially
 * different food allocations, and the app can show them the reports that
 * produced the gap.
 */
final class Basket
{
    /**
     * Monthly quantity per adult. Deliberately a modest cook-at-home basket:
     * this is a FLOOR, not a target lifestyle. Being generous here is not
     * kindness — it inflates the floor, eats the slack, and makes the engine
     * declare people broke who are merely stretched.
     *
     * @var array<string,float> item slug => quantity per adult per month
     */
    public const FOOD = [
        'rice' => 3.5, 'beans' => 1.5, 'garri' => 2.5, 'yam' => 1.0,
        'spaghetti' => 2.0, 'bread' => 2.0, 'egg' => 0.4, 'chicken' => 0.3,
        'fish' => 0.8, 'tomato' => 1.5, 'pepper' => 0.8, 'onion' => 1.0,
        'palmoil' => 0.6, 'vegoil' => 0.4, 'salt' => 0.25, 'sugar' => 0.4,
        'maggi' => 1.2, 'milk' => 4.0,
    ];

    /** Per household, not per head — a pot of soup costs the same to heat. */
    public const UTILITIES = [
        'gas'   => ['qty' => 6.0, 'perHead' => 1.2],
        'water' => ['qty' => 8.0, 'perHead' => 4.0],
        'soap'  => ['qty' => 3.0, 'perHead' => 1.5],
    ];

    /**
     * Households do not scale linearly — shared cooking, shared staples.
     * 1 person = 1.00, 2 = 1.75, 3 = 2.50, 4 = 3.25 adult-equivalents.
     */
    public static function adultEquivalents(int $householdSize): float
    {
        $n = max(1, $householdSize);
        return 1 + ($n - 1) * 0.75;
    }

    /**
     * Cost the floor for a household in a place.
     *
     * @param callable(string):array $priceFor  slug => TruePrice::aggregate() result
     */
    public static function cost(callable $priceFor, array $profile): array
    {
        $ae = self::adultEquivalents($profile['household_size'] ?? 1);

        $line = function (string $slug, float $qty) use ($priceFor): ?array {
            $agg = $priceFor($slug);
            if (!$agg['ok']) return null;

            return [
                'item'       => $slug,
                'qty'        => round($qty, 1),
                'unit_price' => $agg['price'],
                'amount'     => (int) round($agg['price'] * $qty),
                'confidence' => $agg['confidence'],
                'scope'      => $agg['scope'],
                'freshest'   => $agg['freshestDays'],
                'nUsed'      => $agg['nUsed'],
            ];
        };

        $food = [];
        foreach (self::FOOD as $slug => $qty) {
            if ($l = $line($slug, $qty * $ae)) $food[] = $l;
        }

        $utilities = [];
        foreach (self::UTILITIES as $slug => $spec) {
            if ($l = $line($slug, $spec['qty'] + $spec['perHead'] * ($ae - 1))) $utilities[] = $l;
        }

        // Transport is priced off real local fares rather than a flat
        // percentage, and only the people who actually travel are counted.
        $trips     = max(0, $profile['trips_per_day'] ?? 2);
        $daysOut   = max(0, $profile['days_out'] ?? 22);
        $commuters = max(1, ($profile['household_size'] ?? 1) - ($profile['dependents'] ?? 0));
        $tripCount = $trips * $daysOut * $commuters;

        $transport = [];
        if ($l = $line('busfare', $tripCount)) $transport[] = $l;

        $groups = [
            'food'      => ['lines' => $food,      'total' => Money::sum(array_column($food, 'amount'))],
            'utilities' => ['lines' => $utilities, 'total' => Money::sum(array_column($utilities, 'amount'))],
            'transport' => ['lines' => $transport, 'total' => Money::sum(array_column($transport, 'amount'))],
        ];

        $all = array_merge($food, $utilities, $transport);
        $confidence = $all
            ? array_sum(array_column($all, 'confidence')) / count($all)
            : 0.0;

        // Any line that fell back past the LGA is called out by name, so a
        // thin-data budget visibly presents itself as an estimate.
        $widened = array_filter($all, static fn ($l) => $l['scope'] !== 'lga');
        $stale   = array_filter($all, static fn ($l) => $l['freshest'] > 14);

        $household = $profile['household_size'] ?? 1;
        $assumptions = [
            sprintf('Basket priced for %d %s (%.2f adult-equivalents).',
                $household, $household === 1 ? 'person' : 'people', $ae),
            sprintf('%d bus trips a month — %d/day x %d days x %d %s.',
                $tripCount, $trips, $daysOut, $commuters, $commuters === 1 ? 'commuter' : 'commuters'),
        ];
        if ($widened) {
            $assumptions[] = sprintf('%d item%s used a wider area than your LGA — too few nearby reports.',
                count($widened), count($widened) === 1 ? '' : 's');
        }
        if ($stale) {
            $assumptions[] = sprintf('%d item%s have no report in the last fortnight.',
                count($stale), count($stale) === 1 ? '' : 's');
        }

        return $groups + [
            'total'            => $groups['food']['total'] + $groups['utilities']['total'] + $groups['transport']['total'],
            'confidence'       => round($confidence, 3),
            'assumptions'      => $assumptions,
            'thin'             => count($widened) + count($stale),
            'adultEquivalents' => $ae,
        ];
    }
}
