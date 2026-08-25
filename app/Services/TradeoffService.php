<?php

namespace App\Services;

use App\Domain\Basket;
use App\Domain\Goals;
use App\Domain\Money;
use App\Models\Item;
use App\Models\Location;
use App\Models\Market;
use App\Models\User;

/**
 * What the engine says instead of "no".
 *
 * A budgeting app that always produces a tidy budget is lying to people whose
 * situation is not tidy. When the numbers do not fit, the honest move is to
 * say so and then do the work: produce concrete, costed options, ranked by
 * naira recovered against how much each one actually hurts.
 *
 * Note the ordering policy below. "Spend less on yourself" is the advice every
 * other app leads with; here it is offered LAST — after the four options that
 * come from actually knowing what things cost nearby.
 */
class TradeoffService
{
    /** How much a given move costs a person in life terms, not in naira. */
    private const FRICTION = [
        'market'     => 1.6,   // travel further to shop
        'substitute' => 2.0,   // eat differently
        'transport'  => 2.4,   // walk more, travel less
        'deadline'   => 2.8,   // wait longer for the thing you want
        'pause'      => 4.0,   // give up saving this month
    ];

    /**
     * Tiers, not just scores.
     *
     * Friction weighting alone is not enough: skipping a savings contribution
     * recovers a large round number in one move, so on raw score it floats to
     * the top and becomes the headline advice — exactly the behaviour this
     * class exists to avoid. Abandoning a goal is a last resort no matter how
     * much it frees, so it is ranked behind everything else by policy, and
     * scores only order options within a tier.
     */
    private const TIER = [
        'market' => 0, 'substitute' => 0, 'transport' => 0, 'deadline' => 0,
        'pause'  => 1,
    ];

    /** Below this a month, an option is noise rather than advice. */
    private const MIN_MATERIAL = 500_00;

    /** Real Nigerian substitutions within a food group, not "buy cheaper". */
    private const SUBSTITUTES = [
        'rice'    => ['garri', 'spaghetti', 'beans'],
        'beef'    => ['fish', 'chicken', 'egg'],
        'chicken' => ['fish', 'egg', 'beans'],
        'fish'    => ['egg', 'beans'],
        'yam'     => ['garri', 'spaghetti'],
        'bread'   => ['garri'],
        'vegoil'  => ['palmoil'],
    ];

    public function __construct(private PriceService $prices) {}

    /**
     * Ranked, costed options for closing a gap.
     *
     * @param  int $gap  the shortfall in kobo; 0 means "show me savings anyway"
     */
    public function rank(User $user, int $gap = 0, ?int $asOf = null): array
    {
        $asOf = $asOf ?? time();
        $user->loadMissing(['profile.location', 'goals']);
        $profile  = $user->profile;
        $location = $profile?->location;

        if (!$profile) {
            return [];
        }

        $options = array_filter(array_merge(
            [$this->cheaperMarket($profile->toEngineArray(), $location)],
            $this->substitutions($profile->toEngineArray(), $location, $asOf),
            [$this->fewerTrips($profile->toEngineArray(), $location)],
            $this->extendDeadlines($user->goals->where('paused', false)->all(), $gap),
            $this->pauseGoals($user->goals->where('paused', false)->all()),
        ));

        /*
         * Materiality, not just positivity.
         *
         * "Shop at Ikeja Along — saves ₦69" is arithmetically true and useless.
         * Worse, it is corrosive: a list where two entries matter and three are
         * noise teaches people to stop reading the list. The floor is ₦500 a
         * month — roughly a day of bus fare — or 1% of the gap when there is a
         * gap to close, whichever is larger.
         */
        $floor = max(self::MIN_MATERIAL, (int) round($gap * 0.01));

        $options = array_values(array_filter($options, fn ($o) => $o['recovers'] >= $floor));

        foreach ($options as &$o) {
            $o['score'] = $o['recovers'] / (self::FRICTION[$o['kind']] ?? 2.0);
            $o['tier']  = self::TIER[$o['kind']] ?? 0;
        }
        unset($o);

        usort($options, fn ($a, $b) => [$a['tier'], -$a['score']] <=> [$b['tier'], -$b['score']]);

        foreach ($options as &$o) {
            $o['closes'] = $gap > 0 ? min(1.0, $o['recovers'] / $gap) : 1.0;
        }
        unset($o);

        return $options;
    }

    /* ---------------- 1 · shop somewhere else ----------------
       TruePrice already knows the same basket costs less two markets over.
       Quoting the exact saving and naming the market is the difference
       between advice and a nudge. */
    private function cheaperMarket(array $profile, ?Location $location): ?array
    {
        $floor = Basket::cost($this->prices->priceResolver($location), $profile);
        $lines = array_merge($floor['food']['lines'], $floor['utilities']['lines']);

        if (!$lines) {
            return null;
        }

        $totals = [];
        foreach ($lines as $l) {
            $agg = $this->prices->cached($l['item'], $location);
            foreach ($agg['byMarket'] as $m) {
                if (($m['location_id'] ?? null) !== $location?->id || !$m['market_id']) {
                    continue;
                }
                $totals[$m['market_id']] ??= ['total' => 0.0, 'covered' => 0];
                $totals[$m['market_id']]['total']   += $m['price'] * $l['qty'];
                $totals[$m['market_id']]['covered'] += 1;
            }
        }

        // Only compare markets that price most of the basket — a market with
        // two cheap items is not cheaper, it is just less measured.
        $viable = [];
        foreach ($totals as $marketId => $v) {
            if ($v['covered'] < count($lines) * 0.6) {
                continue;
            }
            $viable[] = [
                'market_id' => $marketId,
                'total'     => (int) round($v['total'] / $v['covered'] * count($lines)),
            ];
        }

        if (count($viable) < 2) {
            return null;
        }

        usort($viable, fn ($a, $b) => $a['total'] <=> $b['total']);
        $best     = $viable[0];
        $current  = (int) array_sum(array_column($lines, 'amount'));
        $recovers = $current - $best['total'];

        if ($recovers <= 0) {
            return null;
        }

        $market = Market::find($best['market_id']);

        return [
            'id'       => 'market:' . $best['market_id'],
            'kind'     => 'market',
            'icon'     => 'store',
            'title'    => 'Shop at ' . ($market?->label ?? 'the cheaper market'),
            'detail'   => sprintf(
                'The same basket prices about %d%% lower there on current reports.',
                (int) round($recovers / $current * 100),
            ),
            'recovers' => $recovers,
            'evidence' => sprintf(
                '%d items compared across %d markets in %s',
                count($lines), count($viable), $location?->label ?? 'your area',
            ),
        ];
    }

    /* ---------------- 2 · swap what has spiked ---------------- */
    private function substitutions(array $profile, ?Location $location, int $asOf): array
    {
        $floor = Basket::cost($this->prices->priceResolver($location), $profile);
        $out   = [];

        foreach ($floor['food']['lines'] as $l) {
            $alts = self::SUBSTITUTES[$l['item']] ?? [];
            if (!$alts) {
                continue;
            }

            $tr = $this->prices->trend($l['item'], $location, $asOf);

            // Only suggest a swap for something that moved against them.
            if (!$tr['ok'] || $tr['change'] < 0.08) {
                continue;
            }

            $best = null;
            foreach ($alts as $altSlug) {
                $agg = $this->prices->cached($altSlug, $location);
                if (!$agg['ok']) {
                    continue;
                }
                $saving = $l['amount'] - (int) round($agg['price'] * $l['qty']);
                if ($saving > 0 && (!$best || $saving > $best['saving'])) {
                    $best = ['slug' => $altSlug, 'saving' => $saving, 'agg' => $agg];
                }
            }

            if (!$best) {
                continue;
            }

            $from = Item::where('slug', $l['item'])->value('label') ?? $l['item'];
            $to   = Item::where('slug', $best['slug'])->value('label') ?? $best['slug'];

            $out[] = [
                'id'       => 'sub:' . $l['item'] . ':' . $best['slug'],
                'kind'     => 'substitute',
                'icon'     => 'route',
                'title'    => sprintf('Swap some %s for %s', mb_strtolower($from), mb_strtolower($to)),
                'detail'   => sprintf(
                    '%s is up %d%% near you this month. %s has not moved the same way.',
                    $from, (int) round($tr['change'] * 100), $to,
                ),
                'recovers' => $best['saving'],
                'evidence' => sprintf(
                    '%s at %s · median of %d reports',
                    $to, Money::fmt($best['agg']['price']), $best['agg']['nUsed'],
                ),
            ];
        }

        usort($out, fn ($a, $b) => $b['recovers'] <=> $a['recovers']);

        return array_slice($out, 0, 2);
    }

    /* ---------------- 3 · travel less ---------------- */
    private function fewerTrips(array $profile, ?Location $location): ?array
    {
        $fare = $this->prices->cached('busfare', $location);
        if (!$fare['ok']) {
            return null;
        }

        $commuters = max(1, ($profile['household_size'] ?? 1) - ($profile['dependents'] ?? 0));
        $daysOut   = $profile['days_out'] ?? 22;
        $cut       = 4;

        return [
            'id'       => 'transport:days',
            'kind'     => 'transport',
            'icon'     => 'bus',
            'title'    => "Cut {$cut} trips out of the month",
            'detail'   => sprintf(
                'Going out %d days instead of %d — batching errands, or working from home one day a week.',
                $daysOut - $cut, $daysOut,
            ),
            'recovers' => (int) round($fare['price'] * ($profile['trips_per_day'] ?? 2) * $cut * $commuters),
            'evidence' => sprintf('Local fare median %s · %d reports', Money::fmt($fare['price']), $fare['nUsed']),
        ];
    }

    /* ---------------- 4 · move the date ---------------- */
    private function extendDeadlines(array $goals, int $gap): array
    {
        $out = [];

        foreach ($goals as $g) {
            $now = $g->requirement();
            if ($now['done']) {
                continue;
            }

            // The smallest extension that closes the gap, capped at a year.
            $months = $recovers = 0;
            for ($k = 1; $k <= 12; $k++) {
                $recovers = $now['monthly'] - Money::ceilDiv($now['outstanding'], $now['months'] + $k);
                $months   = $k;
                if ($recovers >= $gap) {
                    break;
                }
            }

            if ($recovers <= 0) {
                continue;
            }

            $out[] = [
                'id'       => 'deadline:' . $g->id,
                'kind'     => 'deadline',
                'icon'     => 'calendar',
                'title'    => sprintf('Give "%s" %d more month%s', $g->label, $months, $months === 1 ? '' : 's'),
                'detail'   => sprintf(
                    'Drops the monthly contribution from %s to %s. You still get there.',
                    Money::fmt($now['monthly']), Money::fmt($now['monthly'] - $recovers),
                ),
                'recovers' => $recovers,
                'evidence' => sprintf(
                    '%d months left · %s outstanding',
                    $now['months'], Money::fmt($now['outstanding']),
                ),
                'apply'    => ['type' => 'extend-goal', 'goal_id' => $g->id, 'months' => $months],
            ];
        }

        return $out;
    }

    /* ---------------- 5 · pause saving (offered last, deliberately) -------- */
    private function pauseGoals(array $goals): array
    {
        $out = [];

        foreach ($goals as $g) {
            $now = $g->requirement();
            if ($now['done'] || $now['monthly'] <= 0) {
                continue;
            }

            $next = Money::ceilDiv($now['outstanding'], max(1, $now['months'] - 1));

            $out[] = [
                'id'       => 'pause:' . $g->id,
                'kind'     => 'pause',
                'icon'     => 'ban',
                'title'    => sprintf('Skip "%s" this month', $g->label),
                'detail'   => 'Frees the whole contribution, but every remaining month gets harder. Use this once, not as a habit.',
                'recovers' => $now['monthly'],
                'evidence' => 'Next month would need ' . Money::fmt($next),
                'apply'    => ['type' => 'pause-goal', 'goal_id' => $g->id],
            ];
        }

        return $out;
    }

    /**
     * Items whose local price moved most this month.
     *
     * A move is only reported when there is enough evidence on BOTH sides of
     * the comparison to believe it. Four reports against four will happily
     * show a 9% swing that is pure sampling noise, and a product whose whole
     * claim is "we know what things really cost" cannot be the one crying wolf
     * about a price that never moved. Thin items simply do not appear — the
     * same honesty as the widened-scope warning, applied to change.
     */
    public const MOVER_MIN_REPORTS = 5;
    public const MOVER_MIN_CHANGE  = 0.04;

    public function movers(?Location $location, int $limit = 6, ?int $asOf = null): array
    {
        $out   = [];
        $board = $this->prices->board($location, $asOf);

        foreach (Item::all() as $item) {
            $tr = $board[$item->slug]['trend'] ?? $this->prices->trend($item, $location, $asOf);

            if (!$tr['ok']
                || abs($tr['change']) < self::MOVER_MIN_CHANGE
                || $tr['now']['nUsed'] < self::MOVER_MIN_REPORTS
                || $tr['prev']['nUsed'] < self::MOVER_MIN_REPORTS) {
                continue;
            }

            $out[] = ['item' => $item, 'trend' => $tr];
        }

        usort($out, fn ($a, $b) => abs($b['trend']['change']) <=> abs($a['trend']['change']));

        return array_slice($out, 0, $limit);
    }
}
