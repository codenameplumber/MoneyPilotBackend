<?php

namespace App\Services;

use App\Domain\TruePrice;
use App\Models\ContributorStat;
use App\Models\Item;
use App\Models\Location;
use App\Models\PriceAggregate;
use App\Models\PriceReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The console's data layer.
 *
 * The member side asks "what does rice cost near me?". The console asks a
 * different question — "is the network healthy enough for that answer to be
 * trustworthy?" — which means coverage, freshness, outliers and contributor
 * reliability, not prices.
 *
 * Coverage is read from the materialised aggregates rather than recomputed.
 * The grid alone is 162 medians over the whole corpus; asking for it live once
 * per page view is how an operations console becomes a page nobody opens.
 */
class NetworkService
{
    public function __construct(private PriceService $prices) {}

    /* ---------------- headline health ---------------- */

    public function health(?int $asOf = null): array
    {
        $asOf = $asOf ?? time();

        return Cache::remember('network:health:' . date('Y-m-d-H', $asOf), now()->addMinutes(5),
            function () use ($asOf) {
                $since = fn (int $days) => date('Y-m-d H:i:s', $asOf - $days * 86400);

                $total  = PriceReport::count();
                $last30 = PriceReport::where('reported_at', '>=', $since(30))->count();
                $last7  = PriceReport::where('reported_at', '>=', $since(7))->count();
                $prev7  = PriceReport::whereBetween('reported_at', [$since(14), $since(7)])->count();

                $contributors = ContributorStat::count()
                    + PriceReport::whereNotNull('user_id')->distinct()->count('user_id');

                $active = PriceReport::where('reported_at', '>=', $since(30))
                    ->distinct()
                    ->count(DB::raw('COALESCE(reporter_key, CONCAT("u", user_id))'));

                /*
                 * A pair is (item × LGA). Coverage is the share of pairs with
                 * enough recent reports to answer LOCALLY rather than falling
                 * back to a wider area — the single number that decides
                 * whether the product works at all.
                 */
                $itemCount = Item::count();
                $lgaCount  = Location::count();
                $pairs     = $itemCount * $lgaCount;

                $scopes = PriceAggregate::whereNotNull('location_id')
                    ->where('window_days', TruePrice::WINDOW_DAYS)
                    ->selectRaw('scope, SUM(reports_used > 0) answered, COUNT(*) total')
                    ->groupBy('scope')
                    ->get();

                $local = (int) ($scopes->firstWhere('scope', 'lga')->answered ?? 0);
                $ok    = (int) $scopes->sum('answered');
                $dark  = max(0, $pairs - $ok);

                return [
                    'total'        => $total,
                    'last30'       => $last30,
                    'last7'        => $last7,
                    'weekDelta'    => $prev7 ? ($last7 - $prev7) / $prev7 * 100 : 0.0,
                    'contributors' => $contributors,
                    'active'       => $active,
                    'pairs'        => $pairs,
                    'local'        => $local,
                    'widened'      => max(0, $ok - $local),
                    'dark'         => $dark,
                    'coverage'     => $pairs ? $local / $pairs : 0.0,
                    'flagged'      => count($this->flagged($asOf)),
                    'held'         => PriceReport::where('status', 'held')->count(),
                    'lgas'         => $lgaCount,
                    'items'        => $itemCount,
                    'markets'      => DB::table('markets')->count(),
                ];
            });
    }

    /* ---------------- intake over time ---------------- */

    /**
     * Reports per day, COMPLETE days only.
     *
     * Today is a partial bucket — at 09:00 it holds nine hours of submissions
     * against twenty-four for every bar beside it. Charting it produces a cliff
     * at the right-hand edge that reads as "reporting has collapsed" every
     * single morning, and an operator who learns to ignore the last bar has
     * learned to ignore the chart.
     */
    public function intake(int $days = 14, ?int $asOf = null): array
    {
        $asOf      = $asOf ?? time();
        $endOfLast = strtotime(date('Y-m-d 00:00:00', $asOf));

        $counts = PriceReport::where('reported_at', '>=', date('Y-m-d H:i:s', $endOfLast - $days * 86400))
            ->where('reported_at', '<', date('Y-m-d H:i:s', $endOfLast))
            ->selectRaw('DATE(reported_at) d, COUNT(*) n')
            ->groupBy('d')
            ->pluck('n', 'd');

        $values = [];
        $labels = [];

        for ($i = $days; $i >= 1; $i--) {
            $ts       = $endOfLast - $i * 86400;
            $values[] = (int) ($counts[date('Y-m-d', $ts)] ?? 0);
            $labels[] = date('j M', $ts);
        }

        return [
            'values'      => $values,
            'labels'      => $labels,
            // Reports filed since midnight — shown as its own reading rather
            // than as a bar that looks like a crash.
            'partial'     => PriceReport::where('reported_at', '>=', date('Y-m-d H:i:s', $endOfLast))->count(),
            'throughDate' => date('j M Y', $endOfLast - 86400),
        ];
    }

    /* ---------------- moderation queue ---------------- */

    /**
     * Reports more than 2.5 median-absolute-deviations from their peer group.
     *
     * They are HELD, never deleted: a price that looks wrong is sometimes a
     * market that moved, and throwing the evidence away destroys the only
     * record of which it was.
     */
    public function flagged(?int $asOf = null): array
    {
        $asOf = $asOf ?? time();

        /*
         * Deliberately NOT cached, and deliberately in two passes.
         *
         * Not cached because the result carries Eloquent models so a moderator
         * can act on the row in front of them, and a serialised model comes
         * back from the cache as an incomplete object.
         *
         * Two passes because of what the shape of the work actually is. Finding
         * the outliers needs the price of every report in the window — a few
         * thousand rows — but the moderator only ever sees the eighty or so
         * that are outliers. Hydrating all of them as models with three eager
         * relations cost most of a second on this page; reading raw prices and
         * then hydrating only the flagged ones is the same answer for a
         * fraction of the work.
         */
        $since = date('Y-m-d H:i:s', $asOf - 30 * 86400);

        $groups = DB::table('price_reports')
            ->where('reported_at', '>=', $since)
            ->where('status', '!=', 'rejected')
            ->select('id', 'item_id', 'location_id', 'price', 'reported_at')
            ->get()
            ->groupBy(fn ($r) => $r->item_id . '|' . $r->location_id);

        $verdicts = [];

        foreach ($groups as $pool) {
            if ($pool->count() < 4) {
                continue;
            }

            $values = $pool->pluck('price')->map(fn ($p) => (int) $p)->all();
            $med    = TruePrice::median($values);
            $dev    = TruePrice::mad($values, $med);
            $band   = $dev > 0 ? 2.5 * $dev : max($med * 0.45, 1);

            foreach ($pool as $r) {
                $delta = (int) $r->price - $med;

                if (abs($delta) <= $band) {
                    continue;
                }

                $ratio = $med ? $r->price / $med : 0;

                $verdicts[$r->id] = [
                    'median'  => $med,
                    'delta'   => $delta,
                    'ratio'   => $ratio,
                    'ageDays' => (int) round(($asOf - strtotime($r->reported_at)) / 86400),
                    'peers'   => $pool->count(),
                    // A 10× miss is almost always a typed zero;
                    // a 2× miss might be a real market move.
                    'verdict' => abs($ratio) >= 5 ? 'likely-typo'
                        : (abs($delta) / max(1, $med) > 0.6 ? 'suspect' : 'borderline'),
                ];
            }
        }

        if (! $verdicts) {
            return [];
        }

        $reports = PriceReport::with(['item', 'location', 'market'])
            ->whereIn('id', array_keys($verdicts))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($verdicts as $id => $v) {
            if ($report = $reports->get($id)) {
                $out[] = ['report' => $report] + $v;
            }
        }

        usort($out, fn ($a, $b) => abs($b['ratio'] - 1) <=> abs($a['ratio'] - 1));

        return $out;
    }

    public function forgetCaches(): void
    {
        Cache::forget('network:health:' . date('Y-m-d-H'));
        Cache::forget('network:flagged:' . date('Y-m-d-H'));
        Cache::forget('network:coverage:' . date('Y-m-d-H'));
    }

    /* ---------------- coverage grid ---------------- */

    /**
     * The grid: every item against every LGA, as a level from 0 to 5.
     *
     * The cached payload is deliberately PLAIN DATA — ids, levels and counts,
     * never Eloquent models. A serialised model comes back from the cache as an
     * incomplete object and the page dies on the first property access, which
     * is a failure that only appears on the second page view and therefore
     * never in development. The caller joins ids to labels itself; that is one
     * indexed read, not a reason to put models in a cache.
     */
    public function coverage(?int $asOf = null): array
    {
        $asOf = $asOf ?? time();

        return Cache::remember('network:coverage:' . date('Y-m-d-H', $asOf), now()->addMinutes(5),
            function () use ($asOf) {
                $items     = Item::orderByDesc('commonness')->orderBy('label')->get();
                $locations = Location::orderBy('label')->get();

                $aggs = PriceAggregate::where('window_days', TruePrice::WINDOW_DAYS)
                    ->whereNotNull('location_id')
                    ->get()
                    ->groupBy('location_id');

                $recent = PriceReport::where('reported_at', '>=', date('Y-m-d H:i:s', $asOf - 30 * 86400))
                    ->selectRaw('location_id, COUNT(*) n, COUNT(DISTINCT COALESCE(reporter_key, CONCAT("u", user_id))) c, MAX(reported_at) newest')
                    ->groupBy('location_id')
                    ->get()
                    ->keyBy('location_id');

                $out = [];

                foreach ($locations as $loc) {
                    $byItem = ($aggs[$loc->id] ?? collect())->keyBy('item_id');
                    $cells  = [];
                    $local  = 0;
                    $conf   = 0.0;

                    foreach ($items as $item) {
                        $a = $byItem[$item->id] ?? null;

                        // Levels, not a raw count: an operator scanning a grid
                        // needs "can we answer this locally, and how well",
                        // which is five states, not twenty-seven numbers.
                        $level = match (true) {
                            !$a || $a->reports_used === 0 => 0,
                            $a->scope !== 'lga'           => 1,
                            $a->reports_used >= 8         => 5,
                            $a->reports_used >= 6         => 4,
                            $a->reports_used >= 4         => 3,
                            default                       => 2,
                        };

                        if ($level >= 2) {
                            $local++;
                        }
                        $conf += (float) ($a->confidence ?? 0);

                        $cells[] = [
                            'item_id'    => $item->id,
                            'item_label' => $item->label,
                            'level'      => $level,
                            'price'      => (int) ($a->price ?? 0),
                            'used'       => (int) ($a->reports_used ?? 0),
                            'scope'      => $a->scope ?? null,
                            'confidence' => (float) ($a->confidence ?? 0),
                        ];
                    }

                    $r = $recent[$loc->id] ?? null;

                    $out[] = [
                        'location_id'    => $loc->id,
                        'location_label' => $loc->label,
                        'state'          => $loc->state,
                        'cells'        => $cells,
                        'localCount'   => $local,
                        'coverage'     => $items->count() ? $local / $items->count() : 0.0,
                        'reports'      => (int) ($r->n ?? 0),
                        'contributors' => (int) ($r->c ?? 0),
                        'freshestDays' => $r?->newest
                            ? (int) round(($asOf - strtotime($r->newest)) / 86400)
                            : null,
                        'confidence'   => $items->count() ? $conf / $items->count() : 0.0,
                    ];
                }

                usort($out, fn ($a, $b) => $b['coverage'] <=> $a['coverage']);

                return $out;
            });
    }

    /* ---------------- contributors ---------------- */

    /**
     * Reputation is earned, not assigned: a reporter whose submissions sit
     * close to the eventual consensus drifts up, a persistent outlier drifts
     * down. Shown here so the weighting the aggregator applies is auditable
     * rather than a black box.
     */
    public function contributors(?int $asOf = null): array
    {
        $asOf    = $asOf ?? time();
        $flagged = [];

        foreach ($this->flagged($asOf) as $f) {
            $key = $f['report']->reporter_key ?? ('u' . $f['report']->user_id);
            $flagged[$key] = ($flagged[$key] ?? 0) + 1;
        }

        $rows = ContributorStat::orderByDesc('reports_count')->get()
            ->map(fn ($c) => [
                'key'        => $c->reporter_key,
                'handle'     => $c->handle,
                'reputation' => $c->reputation,
                'reports'    => $c->reports_count,
                'flagged'    => $flagged[$c->reporter_key] ?? $c->flagged_count,
                'areas'      => $c->areas,
                'items'      => $c->items,
                'newestDays' => $c->last_reported_at
                    ? (int) round(($asOf - $c->last_reported_at->getTimestamp()) / 86400)
                    : null,
                'accuracy'   => $c->reports_count
                    ? 1 - (($flagged[$c->reporter_key] ?? $c->flagged_count) / $c->reports_count)
                    : 1.0,
                'tier'       => $c->tier,
                'member'     => false,
            ])->all();

        // Members who report through the app appear alongside the seeded
        // corpus rather than in a separate list — they are the same network.
        $memberRows = PriceReport::whereNotNull('user_id')
            ->join('users', 'users.id', '=', 'price_reports.user_id')
            ->selectRaw('users.id, users.name, COUNT(*) n, AVG(price_reports.reputation) rep,
                         COUNT(DISTINCT price_reports.location_id) areas,
                         COUNT(DISTINCT price_reports.item_id) items,
                         MAX(price_reports.reported_at) newest')
            ->groupBy('users.id', 'users.name')
            ->get()
            ->map(fn ($m) => [
                'key'        => 'u' . $m->id,
                'handle'     => $m->name,
                'reputation' => (float) $m->rep,
                'reports'    => (int) $m->n,
                'flagged'    => $flagged['u' . $m->id] ?? 0,
                'areas'      => (int) $m->areas,
                'items'      => (int) $m->items,
                'newestDays' => (int) round(($asOf - strtotime($m->newest)) / 86400),
                'accuracy'   => 1 - (($flagged['u' . $m->id] ?? 0) / max(1, $m->n)),
                'tier'       => $m->rep >= 1 ? 'trusted' : ($m->rep >= 0.8 ? 'good' : ($m->rep >= 0.6 ? 'watch' : 'restricted')),
                'member'     => true,
            ])->all();

        $all = array_merge($memberRows, $rows);
        usort($all, fn ($a, $b) => $b['reports'] <=> $a['reports']);

        return $all;
    }

    /* ---------------- price index ---------------- */

    public function priceIndex(?int $asOf = null): array
    {
        $asOf      = $asOf ?? time();
        $locations = Location::orderBy('label')->get()->keyBy('id');

        $aggs = PriceAggregate::where('window_days', TruePrice::WINDOW_DAYS)
            ->get()
            ->groupBy('item_id');

        $counts = PriceReport::where('reported_at', '>=', date('Y-m-d H:i:s', $asOf - 30 * 86400))
            ->selectRaw('item_id, COUNT(*) n')
            ->groupBy('item_id')
            ->pluck('n', 'item_id');

        // The national board is already cached and already carries every
        // trend. Recomputing them here was one corpus scan per item.
        $board = $this->prices->board(null, $asOf);

        $out = [];

        foreach (Item::orderBy('item_group')->orderBy('label')->get() as $item) {
            $rows     = $aggs[$item->id] ?? collect();
            $national = $rows->firstWhere('location_id', null);

            $perLga = $rows->filter(fn ($a) => $a->location_id && $a->reports_used > 0)
                ->map(fn ($a) => ['location' => $locations[$a->location_id] ?? null, 'agg' => $a])
                ->sortBy(fn ($x) => $x['agg']->price)
                ->values();

            $prices = $perLga->pluck('agg.price');

            $out[] = [
                'item'      => $item,
                'national'  => $national,
                'trend'     => $board[$item->slug]['trend'] ?? $this->prices->trend($item, null, $asOf),
                'perLga'    => $perLga,
                'spread'    => $prices->count() ? $prices->max() - $prices->min() : 0,
                'spreadPct' => $prices->count() && $prices->min()
                    ? ($prices->max() - $prices->min()) / $prices->min()
                    : 0.0,
                'cheapest'  => $perLga->first(),
                'dearest'   => $perLga->last(),
                'reports'   => (int) ($counts[$item->id] ?? 0),
            ];
        }

        return $out;
    }
}
