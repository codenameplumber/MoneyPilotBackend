<?php

namespace App\Domain;

/**
 * The allocation engine — the intelligence.
 *
 * Not a language model. Deterministic arithmetic that turns an income and a
 * life situation into a defended budget, in well under a millisecond,
 * identically every time.
 *
 * That determinism is what buys explainability: because the same inputs
 * always produce the same output, every figure can carry the derivation that
 * produced it. "The AI decided ₦38,000" destroys trust; "5kg of rice at the
 * Ikeja median of ₦2,310, plus…" earns it.
 *
 * When a language model is added it takes the job it is actually good at —
 * the intake conversation, plain-English rationale, "can I afford this?" —
 * and this class keeps doing the arithmetic.
 */
final class Allocator
{
    public const VERSION = '1.0.0';

    /**
     * Irregular income is discounted rather than counted at face value.
     * Under-promising is the safe direction to be wrong in.
     */
    public const RELIABILITY = [
        'certain'  => ['factor' => 1.00, 'label' => 'Certain'],
        'likely'   => ['factor' => 0.90, 'label' => 'Usually arrives'],
        'variable' => ['factor' => 0.70, 'label' => 'Varies a lot'],
    ];

    /** @param array<array{amount:int,reliability:string}> $sources */
    public static function expectedIncome(array $sources): int
    {
        $total = 0;
        foreach ($sources as $s) {
            $factor = self::RELIABILITY[$s['reliability'] ?? 'certain']['factor'] ?? 1.0;
            $total += (int) round($s['amount'] * $factor);
        }
        return $total;
    }

    public static function grossIncome(array $sources): int
    {
        return Money::sum(array_column($sources, 'amount'));
    }

    /**
     * THE PIPELINE.
     *
     * @param array{profile:array,sources:array,obligations:array,goals:array,weights:array} $input
     * @param callable(string):array $priceFor
     */
    public static function allocate(array $input, callable $priceFor, ?int $asOf = null): array
    {
        $asOf   ??= time();
        $profile  = $input['profile'] ?? [];
        $sources  = $input['sources'] ?? [];
        $obligations = $input['obligations'] ?? [];
        $goals    = $input['goals'] ?? [];
        $weights  = $input['weights'] ?: Categories::defaultWeights();

        // 1 - Conservative income.
        $income = self::expectedIncome($sources);
        $gross  = self::grossIncome($sources);

        // 2 - Obligations come off the top. Non-negotiable by definition.
        $obligationLines = [];
        foreach ($obligations as $o) {
            $obligationLines[] = self::line(
                $o['category'] ?? 'housing',
                (int) $o['amount'],
                ($o['label'] ?? 'Commitment') . ' — a fixed commitment you told us about.',
                'user',
                1.0,
                ['label' => $o['label'] ?? null, 'due_day' => $o['due_day'] ?? null],
            );
        }
        $obligationTotal = Money::sum(array_column($obligationLines, 'amount'));
        $net = $income - $obligationTotal;

        // 3 - SURVIVAL FLOOR from real local prices. The differentiator.
        $floor = Basket::cost($priceFor, $profile);

        $floorLines = [];
        if ($floor['food']['total'] > 0) {
            $floorLines[] = self::line('food', $floor['food']['total'], sprintf(
                "%d staples for %d at this month's local median prices.",
                count($floor['food']['lines']), $profile['household_size'] ?? 1
            ), 'trueprice', $floor['confidence'], ['detail' => $floor['food']['lines']]);
        }
        if ($floor['transport']['total'] > 0) {
            $qty = $floor['transport']['lines'][0]['qty'] ?? 0;
            $floorLines[] = self::line('transport', $floor['transport']['total'],
                sprintf('%s bus trips at the local median fare.', $qty),
                'trueprice', $floor['confidence'], ['detail' => $floor['transport']['lines']]);
        }
        if ($floor['utilities']['total'] > 0) {
            $floorLines[] = self::line('utilities', $floor['utilities']['total'],
                'Cooking gas, water and household basics at local prices.',
                'trueprice', $floor['confidence'], ['detail' => $floor['utilities']['lines']]);
        }

        // 4 - Goal pressure, derived every time and never cached.
        $goalMonthly = 0;
        foreach ($goals as $g) $goalMonthly += Goals::required($g)['monthly'];

        $goalLines = [];
        if ($goals) {
            $reason = count($goals) === 1
                ? sprintf('%s: %d months left on the target you set.',
                    $goals[0]['label'], Goals::required($goals[0])['months'])
                : sprintf('%d active goals, at the pace their deadlines demand.', count($goals));
            $goalLines[] = self::line('savings', $goalMonthly, $reason, 'rule', 1.0, ['goals' => $goals]);
        }

        // 5 - What is actually left.
        $slack = $net - $floor['total'] - $goalMonthly;

        $base = [
            'as_of'            => $asOf,
            'engine_version'   => self::VERSION,
            'income'           => $income,
            'gross_income'     => $gross,
            'obligation_total' => $obligationTotal,
            'floor_total'      => $floor['total'],
            'goal_monthly'     => $goalMonthly,
            'slack'            => $slack,
            'floor'            => $floor,
            'assumptions'      => $floor['assumptions'],
            'confidence'       => $floor['confidence'],
        ];

        // 6 - THE HONEST BRANCH. No silent fudging of the goal.
        if ($slack < 0) {
            return $base + [
                'feasible'    => false,
                'shortfall'   => -$slack,
                'allocations' => array_merge($obligationLines, $floorLines, $goalLines),
                'total'       => $obligationTotal + $floor['total'] + $goalMonthly,
                'reason'      => 'Your fixed commitments, the local cost of food and transport, '
                    . 'and your savings target add up to more than your expected income this month.',
            ];
        }

        // 7 - Distribute the remainder by preference weight, within bounds.
        $activeWeights = [];
        $bounds = [];
        foreach (Categories::discretionary() as $id => $c) {
            $w = $weights[$id] ?? $c['weight'];
            if ($w > 0) {
                $activeWeights[$id] = $w;
                $bounds[$id] = ['min' => $c['min'], 'max' => $c['max']];
            }
        }
        $split = self::distributeClamped($slack, $activeWeights, $bounds);

        $discretionaryLines = [];
        foreach ($split as $id => $amount) {
            if ($amount <= 0) continue;
            $discretionaryLines[] = self::line($id, $amount, sprintf(
                "%d%% of what's left after essentials, at the priority you set.",
                (int) round(($amount / max(1, $slack)) * 100)
            ), 'rule', 0.9);
        }

        // 8 - Every line carries its derivation.
        $allocations = array_merge($obligationLines, $floorLines, $goalLines, $discretionaryLines);
        usort($allocations, static fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $base + [
            'feasible'    => true,
            'shortfall'   => 0,
            'allocations' => $allocations,
            'total'       => Money::sum(array_column($allocations, 'amount')),
        ];
    }

    /** Roll a budget's lines up per category — what the tracker compares against. */
    public static function byCategory(array $budget): array
    {
        $out = [];
        foreach ($budget['allocations'] as $a) {
            $out[$a['category']] = ($out[$a['category']] ?? 0) + $a['amount'];
        }
        return $out;
    }

    private static function line(
        string $category, int $amount, string $reason,
        string $source, float $confidence = 1.0, array $extra = [],
    ): array {
        return [
            'category'   => $category,
            'kind'       => Categories::kind($category),
            'amount'     => $amount,
            'reason'     => $reason,
            'source'     => $source,
            'confidence' => round($confidence, 3),
        ] + $extra;
    }

    /**
     * Weighted split with per-category floors and ceilings, reconciled so the
     * parts sum to exactly `$total`. Bounds stop one preference slider from
     * zeroing out an entire area of someone's life.
     */
    private static function distributeClamped(int $total, array $weights, array $bounds): array
    {
        $keys = array_keys(array_filter($weights, static fn ($w) => $w > 0));
        if (!$keys || $total <= 0) return array_fill_keys($keys, 0);

        $out  = [];
        $free = $keys;
        $pool = $total;

        for ($iter = 0; $iter < 5 && $free; $iter++) {
            $w = [];
            foreach ($free as $k) $w[$k] = $weights[$k];
            $part = Money::distribute(max(0, $pool), $w);

            $nextFree = [];
            $clamped  = false;

            foreach ($free as $k) {
                $lo = (int) round(($bounds[$k]['min'] ?? 0) * $total);
                $hi = (int) round(($bounds[$k]['max'] ?? 1) * $total);

                if ($part[$k] < $lo)      { $out[$k] = $lo; $pool -= $lo; $clamped = true; }
                elseif ($part[$k] > $hi)  { $out[$k] = $hi; $pool -= $hi; $clamped = true; }
                else $nextFree[] = $k;
            }

            if (!$clamped) {
                foreach ($nextFree as $k) $out[$k] = $part[$k];
                $free = [];
                $pool = 0;
                break;
            }
            $free = $nextFree;
        }

        if ($free) {
            $w = [];
            foreach ($free as $k) $w[$k] = $weights[$k];
            $out += Money::distribute(max(0, $pool), $w);
        }

        // Reconcile any residual kobo onto the largest line, so the budget
        // always adds up to the income printed above it.
        $diff = $total - array_sum($out);
        if ($diff !== 0 && $out) {
            arsort($out);
            $biggest = array_key_first($out);
            $out[$biggest] = max(0, $out[$biggest] + $diff);
        }

        return $out;
    }
}
