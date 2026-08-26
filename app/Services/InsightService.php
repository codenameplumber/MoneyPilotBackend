<?php

namespace App\Services;

use App\Domain\Basket;
use App\Domain\Categories;
use App\Domain\Goals;
use App\Domain\HealthCheck;
use App\Domain\Money;
use App\Models\Item;
use App\Models\Market;
use App\Models\User;

/**
 * Four detectors, run on every state change.
 *
 * Each produces a plain-language card with a concrete action. All four are
 * simple arithmetic over data the app already holds — the intelligence is in
 * knowing which arithmetic is worth showing a person, not in the maths.
 *
 * PRICE SHOCK is the centrepiece, and the one no competing budgeting app can
 * compute, because none of them have the price data. "Rice is up 21% near you,
 * your food budget needs ₦4,300 more this month" is only possible when the
 * product knows what rice costs in this LGA and what this household eats.
 */
class InsightService
{
    /** Below ₦5,000 a projected overspend is not worth a card of its own. */
    private const MATERIAL_OVERSPEND = 500_000;

    /** Below ₦200 a month, a price move is not news. */
    private const MATERIAL_SHOCK = 20_000;

    private const RANK = ['crit' => 0, 'warn' => 1, 'info' => 2, 'good' => 3];

    public function __construct(
        private PriceService $prices,
        private BudgetService $budgets,
    ) {}

    /**
     * Run every detector for one member.
     *
     * @return array<array{id:string,severity:string,title:string,body:string,action?:array}>
     */
    public function detect(User $user, ?int $asOf = null): array
    {
        $asOf     = $asOf ?? time();
        $user->loadMissing(['profile.location', 'goals']);
        $position = $this->budgets->monthPosition($user, $asOf);

        $all = array_merge(
            $this->priceShock($user, $position, $asOf),
            $this->burnRate($position),
            $this->overpayment($user, $asOf),
            $this->goalDrift($user),
        );

        usort($all, fn ($a, $b) => [self::RANK[$a['severity']], -$a['sort']]
                              <=> [self::RANK[$b['severity']], -$b['sort']]);

        return $all;
    }

    /* ---------------- 1 · price shock (the centrepiece) ---------------- */

    private function priceShock(User $user, array $position, int $asOf): array
    {
        $location = $user->profile?->location;
        $ae       = Basket::adultEquivalents($user->profile?->household_size ?? 1);
        $out      = [];

        foreach (Basket::FOOD as $slug => $perAdult) {
            $tr = $this->prices->trend($slug, $location, $asOf);

            if (! $tr['ok'] || abs($tr['change']) < 0.10) {
                continue;
            }

            $qty    = $perAdult * $ae;
            $impact = (int) round($tr['absolute'] * $qty);

            if (abs($impact) < self::MATERIAL_SHOCK) {
                continue;
            }

            $item = Item::where('slug', $slug)->first();
            if (! $item) {
                continue;
            }

            $up = $tr['change'] > 0;

            $out[] = [
                'id'       => 'shock:' . $slug,
                'severity' => $up ? ($tr['change'] > 0.18 ? 'crit' : 'warn') : 'good',
                'icon'     => $up ? 'trendUp' : 'trendDown',
                'title'    => sprintf('%s %s %d%% near you',
                    $item->label, $up ? 'up' : 'down', abs(round($tr['change'] * 100))),
                'body'     => $up
                    ? sprintf(
                        '%s median is now %s %s, from %s last month. Your food budget needs about '
                        . '%s more this month — or swap part of it for something that has not moved.',
                        $tr['now']['scope'] === 'lga' ? 'The local' : 'The area',
                        Money::fmt($tr['now']['price']), $item->unit_label,
                        Money::fmt($tr['prev']['price']), Money::fmt($impact),
                    )
                    : sprintf(
                        'The median has fallen to %s. That is about %s back in your food budget '
                        . 'this month.',
                        Money::fmt($tr['now']['price']), Money::fmt(abs($impact)),
                    ),
                'action'   => ['label' => 'See where it is cheapest', 'route' => ['app.price', $item->slug]],
                'sort'     => abs($impact),
            ];
        }

        usort($out, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_slice($out, 0, 2);
    }

    /* ---------------- 2 · burn rate ---------------- */

    private function burnRate(array $position): array
    {
        if (! $position['budget']->feasible) {
            return [];
        }

        $elapsed = max(1, $position['elapsed']);
        $total   = $position['total_days'];
        $out     = [];

        foreach (Categories::LIVING as $id) {
            $allocated = $position['alloc'][$id] ?? 0;
            $used      = $position['spend'][$id] ?? 0;

            if ($allocated <= 0 || $used <= 0) {
                continue;
            }

            $perDay    = $used / $elapsed;
            $projected = (int) round($perDay * $total);

            if ($projected <= $allocated * 1.05) {
                continue;
            }

            $overBy = $projected - $allocated;

            /*
             * Materiality floor. A small category can be 30% over and still
             * only amount to a few hundred naira, and shouting about that
             * beside a headline saying "comfortably under pace" reads as noise
             * — and teaches the member to ignore the warnings that matter.
             */
            if ($overBy < self::MATERIAL_OVERSPEND) {
                continue;
            }

            $runsOut = (int) ceil($allocated / max(1, $perDay));
            $early   = $total - $runsOut;

            $out[] = [
                'id'       => 'burn:' . $id,
                'severity' => $runsOut <= $total - 5 ? 'crit' : 'warn',
                'icon'     => 'trendUp',
                'title'    => Categories::label($id) . ' is running hot',
                'body'     => $runsOut < $total
                    ? sprintf(
                        'At %s a day you will hit the limit on the %s — %d day%s early. '
                        . 'Projected overspend %s.',
                        Money::fmt((int) round($perDay)), HealthCheck::ordinal($runsOut),
                        $early, $early === 1 ? '' : 's', Money::fmt($overBy),
                    )
                    : sprintf('Projected to finish the month about %s over.', Money::fmt($overBy)),
                'action'   => ['label' => 'See your spending', 'route' => ['app.spend']],
                'sort'     => $overBy,
            ];
        }

        usort($out, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_slice($out, 0, 2);
    }

    /* ---------------- 3 · overpayment ----------------
       Only possible because a transaction can carry an item_id — the link that
       turns "you spent ₦2,300" into "you spent ₦350 more than your neighbours". */

    private function overpayment(User $user, int $asOf): array
    {
        $location = $user->profile?->location;

        $recent = $user->transactions()
            ->whereNotNull('item_id')
            ->where('occurred_at', '>=', date('Y-m-d H:i:s', $asOf - 14 * 86400))
            ->with('item')
            ->limit(40)
            ->get();

        $out = [];

        foreach ($recent as $tx) {
            if (! $tx->item) {
                continue;
            }

            $agg = $this->prices->cached($tx->item->slug, $location);

            if (! $agg['ok'] || $agg['confidence'] < 0.35) {
                continue;
            }

            // Compare only where the entry plausibly bought about one unit.
            $ratio = Money::ratio($tx->amount, $agg['price']);

            if ($ratio < 0.9 || $ratio > 1.6) {
                continue;
            }

            $over = $tx->amount - $agg['price'];

            if ($over < max(15_000, $agg['price'] * 0.12)) {
                continue;
            }

            $cheapest = collect($agg['byMarket'])
                ->filter(fn ($m) => $m['market_id'])
                ->sortBy('price')
                ->first();

            $where = $cheapest
                ? sprintf(' %s was reporting %s.',
                    Market::find($cheapest['market_id'])?->label ?? 'A nearby market',
                    Money::fmt($cheapest['price']))
                : '';

            $out[] = [
                'id'       => 'over:' . $tx->id,
                'severity' => 'warn',
                'icon'     => 'inspect',
                'title'    => 'You paid above market for ' . mb_strtolower($tx->item->label),
                'body'     => sprintf('%s against a nearby median of %s.%s',
                    Money::fmt($tx->amount), Money::fmt($agg['price']), $where),
                'action'   => ['label' => 'Check the price', 'route' => ['app.price', $tx->item->slug]],
                'sort'     => $over,
            ];
        }

        usort($out, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_slice($out, 0, 1);
    }

    /* ---------------- 4 · goal drift ---------------- */

    private function goalDrift(User $user): array
    {
        $out = [];

        foreach ($user->goals->where('paused', false) as $goal) {
            $req = $goal->requirement();

            if ($req['done']) {
                $out[] = [
                    'id'       => 'goal:' . $goal->id . ':done',
                    'severity' => 'good',
                    'icon'     => 'star',
                    'title'    => sprintf('"%s" is fully funded', $goal->label),
                    'body'     => Money::fmt($goal->target) . ' saved. Time to set the next one.',
                    'action'   => ['label' => 'Set another', 'route' => ['app.goals']],
                    'sort'     => 0,
                ];

                continue;
            }

            $drift = Goals::drift($goal->toEngineArray());

            if (! $drift['behind']) {
                continue;
            }

            $out[] = [
                'id'       => 'goal:' . $goal->id . ':drift',
                'severity' => 'warn',
                'icon'     => 'star',
                'title'    => sprintf('"%s" needs more each month now', $goal->label),
                'body'     => sprintf(
                    '%s a month to still reach %s in %d month%s — %s more than when you set it. '
                    . 'Moving the date is a fair option.',
                    Money::fmt($drift['now']), Money::fmt($goal->target),
                    $req['months'], $req['months'] === 1 ? '' : 's',
                    Money::fmt($drift['delta']),
                ),
                'action'   => ['label' => 'Adjust the goal', 'route' => ['app.goals']],
                'sort'     => $drift['delta'],
            ];
        }

        usort($out, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_slice($out, 0, 1);
    }
}
