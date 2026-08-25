<?php

namespace App\Services;

use App\Domain\Allocator;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Build a member's budget and persist the snapshot.
 *
 * The arithmetic lives in App\Domain\Allocator, which knows nothing about
 * Eloquent. This class gathers the inputs, hands them over, and writes the
 * result down — including the reason on every line, because a figure a member
 * cannot interrogate is a figure they will not trust.
 */
class BudgetService
{
    public function __construct(private PriceService $prices) {}

    /**
     * Compute without touching the database.
     * Used for previews and for "what if" questions from the assistant.
     */
    public function compute(User $user, ?int $asOf = null, bool $live = false): array
    {
        $user->loadMissing(['profile.location', 'incomeSources', 'obligations', 'goals']);
        $profile = $user->profile;

        if (!$profile) {
            return ['feasible' => false, 'error' => 'no_profile', 'allocations' => []];
        }

        return Allocator::allocate([
            'profile'     => $profile->toEngineArray(),
            'sources'     => $user->incomeSources
                ->map(fn ($s) => ['amount' => $s->amount, 'reliability' => $s->reliability, 'label' => $s->label])
                ->all(),
            'obligations' => $user->obligations
                ->map(fn ($o) => ['amount' => $o->amount, 'category' => $o->category, 'label' => $o->label, 'due_day' => $o->due_day])
                ->all(),
            'goals'       => $user->goals->where('paused', false)->map->toEngineArray()->values()->all(),
            'weights'     => $profile->weights ?? [],
        ], $this->prices->priceResolver($profile->location, $live), $asOf);
    }

    /**
     * Compute and store this month's budget.
     *
     * One snapshot per user per month. Rebuilding replaces the lines rather
     * than accumulating them, so a member who edits their profile four times
     * does not end up with four overlapping food allocations.
     */
    public function rebuild(User $user, ?int $asOf = null): Budget
    {
        $asOf   = $asOf ?? time();
        $result = $this->compute($user, $asOf);
        $month  = date('Y-m', $asOf);

        return DB::transaction(function () use ($user, $result, $month) {
            $budget = Budget::updateOrCreate(
                ['user_id' => $user->id, 'month' => $month],
                [
                    'income'           => $result['income'],
                    'gross_income'     => $result['gross_income'],
                    'obligation_total' => $result['obligation_total'],
                    'floor_total'      => $result['floor_total'],
                    'goal_monthly'     => $result['goal_monthly'],
                    'slack'            => $result['slack'],
                    'feasible'         => $result['feasible'],
                    'shortfall'        => $result['shortfall'],
                    'confidence'       => $result['confidence'],
                    'assumptions'      => $result['assumptions'],
                    'engine_version'   => $result['engine_version'],
                ],
            );

            $budget->lines()->delete();

            foreach ($result['allocations'] as $line) {
                $budget->lines()->create([
                    'category'   => $line['category'],
                    'kind'       => $line['kind'],
                    'amount'     => $line['amount'],
                    'reason'     => $line['reason'],
                    'source'     => $line['source'],
                    'confidence' => $line['confidence'],
                    'detail'     => $line['detail'] ?? null,
                ]);
            }

            return $budget->fresh('lines');
        });
    }

    /** This month's stored budget, built on demand if it does not exist yet. */
    public function current(User $user): Budget
    {
        return Budget::with('lines')
            ->where('user_id', $user->id)
            ->where('month', date('Y-m'))
            ->first()
            ?? $this->rebuild($user);
    }

    /**
     * Spend so far this month, per category, derived from the append-only log.
     *
     * @return array<string,int>
     */
    public function spendByCategory(User $user, ?int $asOf = null): array
    {
        $asOf = $asOf ?? time();

        return DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [
                date('Y-m-01 00:00:00', $asOf),
                date('Y-m-t 23:59:59', $asOf),
            ])
            ->groupBy('category')
            ->selectRaw('category, SUM(amount) total')
            ->pluck('total', 'category')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Where the month stands: what is safe to spend, and whether the member
     * is ahead of pace. The dashboard's headline in one call.
     */
    public function monthPosition(User $user, ?int $asOf = null): array
    {
        $asOf   = $asOf ?? time();
        $budget = $this->current($user);
        $spend  = $this->spendByCategory($user, $asOf);
        $alloc  = $budget->byCategory();

        $allocated = 0;
        $spent     = 0;
        foreach (\App\Domain\Categories::LIVING as $id) {
            $allocated += $alloc[$id] ?? 0;
            $spent     += $spend[$id] ?? 0;
        }

        $totalDays   = (int) date('t', $asOf);
        $elapsedDays = (int) date('j', $asOf);
        $daysLeft    = max(1, $totalDays - $elapsedDays + 1);
        $left        = $allocated - $spent;

        $monthPct = $elapsedDays / $totalDays;
        $spentPct = $allocated > 0 ? $spent / $allocated : 0.0;
        $ahead    = $spentPct - $monthPct;

        return [
            'budget'      => $budget,
            'alloc'       => $alloc,
            'spend'       => $spend,
            'allocated'   => $allocated,
            'spent'       => $spent,
            'left'        => $left,
            'per_day'     => intdiv($left, $daysLeft),
            'days_left'   => $daysLeft,
            'total_days'  => $totalDays,
            'elapsed'     => $elapsedDays,
            'month_pct'   => $monthPct,
            'spent_pct'   => $spentPct,
            'projected'   => (int) round($spent / max(1, $elapsedDays) * $totalDays),
            'pace_state'  => $ahead > 0.12 ? 'crit' : ($ahead > 0.04 ? 'warn' : 'good'),
            'pace_label'  => $ahead > 0.12 ? 'Spending too fast'
                : ($ahead > 0.04 ? 'Slightly ahead of pace'
                : ($ahead < -0.08 ? 'Comfortably under pace' : 'On pace')),
        ];
    }
}
