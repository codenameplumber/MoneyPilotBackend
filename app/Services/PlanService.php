<?php

namespace App\Services;

use App\Domain\Money;
use App\Domain\Prng;
use App\Models\Item;
use App\Models\Location;

/**
 * Where the food and transport budgets stop being numbers and become a plan.
 *
 * A budget line says "₦42,000 for food". That is information. A week of actual
 * meals, costed at this week's local prices, that fits inside ₦42,000, is a
 * decision somebody can act on tonight. This service is the bridge between the
 * two — and the same machinery that prices a bag of rice prices a month of
 * getting to work, because TruePrice carries fares as ordinary items.
 */
class PlanService
{
    /** Quantities are PER SERVING, in the item's own unit. */
    public const MEALS = [
        ['id' => 'pap-akara',    'label' => 'Pap & akara',            'slot' => 'breakfast',
         'items' => ['beans' => 0.10, 'vegoil' => 0.04, 'sugar' => 0.02]],
        ['id' => 'bread-tea',    'label' => 'Bread & tea',            'slot' => 'breakfast',
         'items' => ['bread' => 0.33, 'milk' => 1.0, 'sugar' => 0.03]],
        ['id' => 'yam-egg',      'label' => 'Boiled yam & egg sauce', 'slot' => 'breakfast',
         'items' => ['yam' => 0.25, 'egg' => 0.07, 'tomato' => 0.10, 'vegoil' => 0.03]],
        ['id' => 'noodles-egg',  'label' => 'Noodles & egg',          'slot' => 'breakfast',
         'items' => ['noodles' => 1.0, 'egg' => 0.07, 'vegoil' => 0.02]],

        ['id' => 'jollof',       'label' => 'Jollof rice & chicken',  'slot' => 'lunch',
         'items' => ['rice' => 0.18, 'chicken' => 0.15, 'tomato' => 0.12, 'pepper' => 0.05, 'vegoil' => 0.04, 'maggi' => 0.08]],
        ['id' => 'rice-stew',    'label' => 'White rice & fish stew', 'slot' => 'lunch',
         'items' => ['rice' => 0.18, 'fish' => 0.12, 'tomato' => 0.12, 'pepper' => 0.05, 'palmoil' => 0.04]],
        ['id' => 'beans-garri',  'label' => 'Beans & garri',          'slot' => 'lunch',
         'items' => ['beans' => 0.15, 'garri' => 0.10, 'palmoil' => 0.04, 'pepper' => 0.03]],
        ['id' => 'spag-sauce',   'label' => 'Spaghetti & sauce',      'slot' => 'lunch',
         'items' => ['spaghetti' => 0.5, 'tomato' => 0.10, 'vegoil' => 0.04, 'maggi' => 0.08]],

        ['id' => 'eba-soup',     'label' => 'Eba & vegetable soup',   'slot' => 'dinner',
         'items' => ['garri' => 0.15, 'fish' => 0.10, 'palmoil' => 0.05, 'pepper' => 0.05, 'maggi' => 0.08]],
        ['id' => 'yam-porridge', 'label' => 'Yam porridge',           'slot' => 'dinner',
         'items' => ['yam' => 0.30, 'palmoil' => 0.05, 'pepper' => 0.05, 'fish' => 0.08]],
        ['id' => 'moi-moi',      'label' => 'Moi moi & pap',          'slot' => 'dinner',
         'items' => ['beans' => 0.16, 'pepper' => 0.04, 'vegoil' => 0.04, 'egg' => 0.04]],
        ['id' => 'fried-rice',   'label' => 'Fried rice & chicken',   'slot' => 'dinner',
         'items' => ['rice' => 0.18, 'chicken' => 0.14, 'vegoil' => 0.05, 'maggi' => 0.08]],
    ];

    public const MODES = [
        ['id' => 'busfare', 'label' => 'Bus (danfo)', 'note' => 'Cheapest per drop, slowest and least predictable.'],
        ['id' => 'keke',    'label' => 'Keke napep',  'note' => 'Short hops, good where buses do not turn in.'],
        ['id' => 'okada',   'label' => 'Okada',       'note' => 'Fastest door to door, and the most expensive per trip.'],
    ];

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function __construct(private PriceService $prices) {}

    /**
     * slug => label, loaded once.
     *
     * Costing a week is twelve meals of four or five ingredients each, and
     * looking the label up per ingredient turned one indexed read into nearly
     * fifty. The prices themselves were already batched; this was the half that
     * was not.
     *
     * @var array<string,string>|null
     */
    private ?array $labels = null;

    private function labelFor(string $slug): string
    {
        $this->labels ??= Item::pluck('label', 'slug')->all();

        return $this->labels[$slug] ?? $slug;
    }

    /* ---------------- meals ---------------- */

    /** Cost one serving at local median prices. */
    public function costMeal(array $meal, ?Location $location): array
    {
        $lines   = [];
        $missing = 0;

        foreach ($meal['items'] as $slug => $qty) {
            $agg = $this->prices->cached($slug, $location);

            if (! $agg['ok']) {
                $missing++;
                continue;
            }

            $lines[] = [
                'slug'       => $slug,
                'label'      => $this->labelFor($slug),
                'qty'        => $qty,
                'unit_price' => $agg['price'],
                'amount'     => (int) round($agg['price'] * $qty),
                'confidence' => $agg['confidence'],
            ];
        }

        return [
            'meal'       => $meal,
            'lines'      => $lines,
            'perServing' => Money::sum(array_column($lines, 'amount')),
            'missing'    => $missing,
            'confidence' => $lines ? array_sum(array_column($lines, 'confidence')) / count($lines) : 0.0,
        ];
    }

    /**
     * Build a week that fits the food budget.
     *
     * Greedy with a variety rule: each slot takes the most interesting meal
     * still affordable and never repeats yesterday's choice. When nothing
     * fits, it takes the cheapest and SAYS SO, rather than quietly blowing the
     * budget and presenting the result as a plan.
     */
    public function planWeek(?Location $location, int $weeklyBudget, int $servings = 1): array
    {
        $costed = [];
        foreach (self::MEALS as $meal) {
            $c = $this->costMeal($meal, $location);
            if ($c['lines'] && $c['missing'] === 0) {
                $costed[] = $c;
            }
        }

        if (! $costed) {
            return ['ok' => false, 'days' => [], 'reason' => 'There are not enough local prices to cost a week of meals yet.'];
        }

        $bySlot = function (string $slot) use ($costed) {
            $pool = array_values(array_filter($costed, fn ($c) => $c['meal']['slot'] === $slot));
            usort($pool, fn ($a, $b) => $a['perServing'] <=> $b['perServing']);

            return $pool;
        };

        $slots = ['breakfast' => $bySlot('breakfast'), 'lunch' => $bySlot('lunch'), 'dinner' => $bySlot('dinner')];

        $dailyBudget = intdiv($weeklyBudget, 7);
        $r           = Prng::seeded('mealplan', (string) ($location?->id ?? 0), (string) $servings);
        $days        = [];
        $lastUsed    = [];

        for ($d = 0; $d < 7; $d++) {
            $chosen   = [];
            $dayTotal = 0;

            // Lunch and dinner are decided first: they are the meals worth
            // getting right, and letting breakfast claim the headroom first
            // produced weeks of bread and tea followed by beans every night.
            foreach (['lunch', 'dinner', 'breakfast'] as $slot) {
                $pool = $slots[$slot];
                if (! $pool) {
                    continue;
                }

                $remaining = count(array_diff(['lunch', 'dinner', 'breakfast'], array_keys($chosen)));
                $headroom  = max(0, $dailyBudget - $dayTotal);
                $share     = $remaining > 0 ? $headroom / $remaining : $headroom;

                $affordable = array_values(array_filter(
                    $pool,
                    fn ($c) => $c['perServing'] * $servings <= $share * 1.25
                        && $c['meal']['id'] !== ($lastUsed[$slot] ?? null),
                ));

                $candidates = $affordable ?: array_values(array_filter(
                    $pool,
                    fn ($c) => $c['meal']['id'] !== ($lastUsed[$slot] ?? null),
                ));

                $choice = $candidates
                    ? $r->pick(array_slice($candidates, 0, 3))
                    : $pool[0];

                $chosen[$slot]   = $choice;
                $lastUsed[$slot] = $choice['meal']['id'];
                $dayTotal       += $choice['perServing'] * $servings;
            }

            $days[] = [
                'day'         => self::DAYS[$d],
                'meals'       => $chosen,
                'total'       => (int) round($dayTotal),
                'overBudget'  => $dayTotal > $dailyBudget,
            ];
        }

        $total = Money::sum(array_column($days, 'total'));

        return [
            'ok'           => true,
            'days'         => $days,
            'total'        => $total,
            'weeklyBudget' => $weeklyBudget,
            'dailyBudget'  => $dailyBudget,
            'fits'         => $total <= $weeklyBudget,
            'over'         => max(0, $total - $weeklyBudget),
            'servings'     => $servings,
            'confidence'   => array_sum(array_column($costed, 'confidence')) / count($costed),
        ];
    }

    /** The shopping list rolled up from a plan — quantity and cost per item. */
    public function shoppingList(array $plan): array
    {
        $bag = [];

        foreach ($plan['days'] as $day) {
            foreach ($day['meals'] as $c) {
                foreach ($c['lines'] as $l) {
                    $bag[$l['slug']] ??= [
                        'slug' => $l['slug'], 'label' => $l['label'],
                        'qty' => 0.0, 'amount' => 0, 'unit_price' => $l['unit_price'],
                    ];
                    $bag[$l['slug']]['qty']    += $l['qty'] * $plan['servings'];
                    $bag[$l['slug']]['amount'] += $l['amount'] * $plan['servings'];
                }
            }
        }

        $out = array_map(static fn ($e) => [
            'slug'       => $e['slug'],
            'label'      => $e['label'],
            'qty'        => round($e['qty'], 2),
            'amount'     => (int) round($e['amount']),
            'unit_price' => $e['unit_price'],
        ], array_values($bag));

        usort($out, static fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $out;
    }

    /* ---------------- transport ---------------- */

    /**
     * Compare every mode, plus the mixed pattern people actually run, against
     * the transport allocation. Cheapest first.
     */
    public function compareTransport(?Location $location, array $profile, int $allocation = 0): array
    {
        $pattern = [
            'trips_per_day' => $profile['trips_per_day'] ?? 2,
            'days_out'      => $profile['days_out'] ?? 22,
            'commuters'     => max(1, ($profile['household_size'] ?? 1) - ($profile['dependents'] ?? 0)),
        ];

        $trips = max(0, $pattern['trips_per_day']) * max(0, $pattern['days_out']) * $pattern['commuters'];
        $pure  = [];

        foreach (self::MODES as $mode) {
            $agg = $this->prices->cached($mode['id'], $location);
            if (! $agg['ok']) {
                continue;
            }
            $pure[] = [
                'id'      => $mode['id'],
                'label'   => $mode['label'],
                'note'    => $mode['note'],
                'fare'    => $agg['price'],
                'trips'   => $trips,
                'monthly' => (int) round($agg['price'] * $trips),
                'agg'     => $agg,
            ];
        }

        if (! $pure) {
            return ['options' => [], 'pattern' => $pattern, 'allocation' => $allocation];
        }

        $options = $pure;
        $bus     = current(array_filter($pure, fn ($o) => $o['id'] === 'busfare')) ?: null;
        $okada   = current(array_filter($pure, fn ($o) => $o['id'] === 'okada')) ?: null;

        if ($bus && $okada) {
            $okadaTrips = (int) round($trips * 0.2);
            $busTrips   = $trips - $okadaTrips;

            $options[] = [
                'id'      => 'mixed',
                'label'   => 'Mostly bus, okada when late',
                'note'    => '80% bus, 20% okada — what most people actually do.',
                'fare'    => null,
                'trips'   => $trips,
                'monthly' => (int) round($bus['fare'] * $busTrips + $okada['fare'] * $okadaTrips),
                'agg'     => $bus['agg'],
                'mixed'   => ['bus' => $busTrips, 'okada' => $okadaTrips],
            ];
        }

        usort($options, static fn ($a, $b) => $a['monthly'] <=> $b['monthly']);

        $cheapest = $options[0];
        $current  = current(array_filter($options, fn ($o) => $o['id'] === 'busfare')) ?: $cheapest;

        return [
            'options' => array_map(static fn ($o) => $o + [
                'fitsBudget'    => $allocation > 0 ? $o['monthly'] <= $allocation : null,
                'vsAllocation'  => $allocation > 0 ? $o['monthly'] - $allocation : null,
            ], $options),
            'cheapest'   => $cheapest,
            'current'    => $current,
            'saving'     => $current['monthly'] - $cheapest['monthly'],
            'pattern'    => $pattern,
            'allocation' => $allocation,
        ];
    }

    /** What trimming days out of the month is worth, at the local fare. */
    public function daysOutCurve(?Location $location, array $profile): array
    {
        $fare = $this->prices->cached('busfare', $location);
        if (! $fare['ok']) {
            return [];
        }

        $commuters = max(1, ($profile['household_size'] ?? 1) - ($profile['dependents'] ?? 0));
        $trips     = $profile['trips_per_day'] ?? 2;
        $base      = $profile['days_out'] ?? 22;

        $out = [];
        for ($days = max(8, $base - 8); $days <= $base + 2; $days += 2) {
            $out[] = [
                'days'    => $days,
                'monthly' => (int) round($fare['price'] * $trips * $days * $commuters),
                'current' => $days === $base,
            ];
        }

        return $out;
    }
}
