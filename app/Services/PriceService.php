<?php

namespace App\Services;

use App\Domain\TruePrice;
use App\Models\Item;
use App\Models\Location;
use App\Models\PriceAggregate;
use App\Models\PriceReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The bridge between the database and the pure TruePrice class.
 *
 * The engine knows nothing about Eloquent — it is handed rows and returns a
 * verdict. This class is the only thing that knows both, which is what keeps
 * the engine testable without a database and portable to a queue worker.
 *
 * Two read paths, deliberately:
 *
 *   aggregate()  computes live from reports. Correct, and too slow to call
 *                162 times in a request.
 *   cached()     reads the materialised row a scheduled job wrote. What
 *                every screen actually uses.
 */
class PriceService
{
    /** Reports are loaded once per request and reused across every lookup. */
    private array $corpus = [];

    /** Materialised aggregates, loaded a whole location at a time. */
    private array $memo = [];

    /**
     * Live aggregation for one item in one place.
     *
     * @param  string|Item      $item
     * @param  int|Location|null $location  null = national
     */
    public function aggregate(
        string|Item $item,
        int|Location|null $location = null,
        ?int $asOf = null,
        int $windowDays = TruePrice::WINDOW_DAYS,
    ): array {
        $item = $item instanceof Item ? $item : Item::where('slug', $item)->first();
        if (!$item) {
            return TruePrice::miss();
        }

        $locationId = $location instanceof Location ? $location->id : $location;
        $state = $location instanceof Location
            ? $location->state
            : ($locationId ? Location::find($locationId)?->state : null);

        return TruePrice::aggregate(
            $this->rowsFor($item->id, $windowDays),
            $locationId,
            $state,
            $asOf ?? time(),
            $windowDays,
        );
    }

    public function trend(
        string|Item $item,
        int|Location|null $location = null,
        ?int $asOf = null,
    ): array {
        $item = $item instanceof Item ? $item : Item::where('slug', $item)->first();
        if (!$item) {
            return ['ok' => false, 'change' => 0.0, 'absolute' => 0, 'now' => TruePrice::miss(), 'prev' => TruePrice::miss()];
        }

        $locationId = $location instanceof Location ? $location->id : $location;
        $state = $location instanceof Location
            ? $location->state
            : ($locationId ? Location::find($locationId)?->state : null);

        // The previous window needs twice the history, so widen the load.
        return TruePrice::trend(
            $this->rowsFor($item->id, TruePrice::WINDOW_DAYS * 2 + 2),
            $locationId,
            $state,
            $asOf ?? time(),
        );
    }

    /**
     * A price history for one item in one place, ready to plot.
     *
     * @return array<array{at:int,value:int,n:int}>
     */
    public function series(
        string|Item $item,
        int|Location|null $location = null,
        ?int $asOf = null,
        int $days = 84,
        int $buckets = 14,
    ): array {
        $item = $item instanceof Item ? $item : Item::where('slug', $item)->first();
        if (!$item) {
            return [];
        }

        $locationId = $location instanceof Location ? $location->id : $location;
        $state = $location instanceof Location
            ? $location->state
            : ($locationId ? Location::find($locationId)?->state : null);

        return TruePrice::series(
            $this->rowsFor($item->id, $days + 30),
            $locationId,
            $state,
            $asOf ?? time(),
            $days,
            $buckets,
        );
    }

    /**
     * A closure the Basket/Allocator can call by item slug.
     *
     * Reading the materialised aggregate keeps a budget build to a single
     * indexed query per item instead of a median over the whole corpus.
     */
    public function priceResolver(?Location $location, bool $live = false): callable
    {
        return function (string $slug) use ($location, $live): array {
            return $live
                ? $this->aggregate($slug, $location)
                : $this->cached($slug, $location);
        };
    }

    /**
     * Read the materialised median, falling back to live if it is missing.
     *
     * The whole location is loaded on the first call rather than one row at a
     * time. Costing a basket asks for eighteen items and the Prices screen asks
     * for all of them, so per-slug queries turned one indexed read into
     * fifty-four round trips — measurably the slowest thing in the request.
     */
    public function cached(string $slug, ?Location $location): array
    {
        $key = 'agg:' . ($location?->id ?? 0);

        if (!isset($this->memo[$key])) {
            $slugById = Item::pluck('slug', 'id');

            $this->memo[$key] = PriceAggregate::where('location_id', $location?->id)
                ->where('window_days', TruePrice::WINDOW_DAYS)
                ->get()
                ->mapWithKeys(fn ($row) => [$slugById[$row->item_id] => $row->toEngineArray()])
                ->all();
        }

        if (isset($this->memo[$key][$slug])) {
            return $this->memo[$key][$slug];
        }

        // A pair the refresh job has not reached yet still has to answer.
        $item = Item::where('slug', $slug)->first();

        return $item ? $this->aggregate($item, $location) : TruePrice::miss();
    }

    /**
     * Every item priced for one place, with its trend and a chart series.
     *
     * The medians come from the materialised table, but a trend and a series
     * are rolling aggregates that no single row can hold — computing them for
     * every item took roughly two and a half seconds, which is the difference
     * between a screen that feels live and one that feels broken.
     *
     * They are cached for ten minutes, keyed by location and by the refresh
     * that produced the underlying aggregates. Reporting a price triggers a
     * refresh, so the board follows the corpus rather than a wall clock.
     */
    public function board(?Location $location, ?int $asOf = null): array
    {
        $stamp = PriceAggregate::where('location_id', $location?->id)->max('computed_at') ?? 'none';
        $key   = 'prices:board:' . ($location?->id ?? 0) . ':' . md5((string) $stamp);

        return Cache::remember($key, now()->addMinutes(10), function () use ($location, $asOf) {
            return Item::orderByDesc('commonness')->orderBy('label')->get()
                ->map(fn (Item $item) => [
                    'slug'   => $item->slug,
                    'agg'    => $this->cached($item->slug, $location),
                    'trend'  => $this->trend($item, $location, $asOf),
                    'series' => $this->series($item, $location, $asOf, 56, 10),
                ])
                ->keyBy('slug')
                ->all();
        });
    }

    /**
     * Recompute one item everywhere, immediately.
     *
     * Called the moment a member reports a price. The screen tells them their
     * report "is now part of the median for your area", and that has to be
     * true on the next page load rather than after the next scheduled run —
     * otherwise the most motivating moment in the product is a lie.
     *
     * Only one item's rows are touched, so this is cheap enough to do inline.
     */
    public function refreshItem(Item $item, ?int $asOf = null): void
    {
        $asOf = $asOf ?? time();
        $rows = $this->rowsFor($item->id, TruePrice::WINDOW_DAYS, fresh: true);
        $now  = now();

        $targets = Location::all()->map(fn ($l) => ['id' => $l->id, 'state' => $l->state])->all();
        $targets[] = ['id' => null, 'state' => null];

        foreach ($targets as $target) {
            $agg = TruePrice::aggregate($rows, $target['id'], $target['state'], $asOf);

            PriceAggregate::updateOrCreate(
                ['item_id' => $item->id, 'location_id' => $target['id'], 'window_days' => TruePrice::WINDOW_DAYS],
                [
                    'price' => $agg['price'], 'reports_total' => $agg['n'], 'reports_used' => $agg['nUsed'],
                    'outliers' => $agg['outliers'], 'scope' => $agg['scope'], 'confidence' => $agg['confidence'],
                    'freshest_days' => min(9999, $agg['freshestDays']),
                    'low' => $agg['low'], 'high' => $agg['high'],
                    'by_market' => $agg['byMarket'], 'computed_at' => $now,
                ],
            );
        }

        // The board is keyed by the newest computed_at, so the write above has
        // already invalidated it. Clearing the per-request memo matters more:
        // the same request is about to re-render with the old figures.
        $this->memo = [];
    }

    /**
     * Rebuild every materialised aggregate.
     *
     * Called by the scheduler. Loads the whole live corpus once, then
     * computes every (item x location) pair plus the national row in memory,
     * which is far cheaper than 162 separate median queries.
     */
    public function refreshAggregates(?int $asOf = null): int
    {
        $asOf      = $asOf ?? time();
        $items     = Item::all();
        $locations = Location::all();
        $written   = 0;
        $now       = now();

        foreach ($items as $item) {
            $rows = $this->rowsFor($item->id, TruePrice::WINDOW_DAYS, fresh: true);

            $targets = $locations->map(fn ($l) => ['id' => $l->id, 'state' => $l->state])->all();
            $targets[] = ['id' => null, 'state' => null];    // the national row

            foreach ($targets as $target) {
                $agg = TruePrice::aggregate($rows, $target['id'], $target['state'], $asOf);

                PriceAggregate::updateOrCreate(
                    [
                        'item_id'     => $item->id,
                        'location_id' => $target['id'],
                        'window_days' => TruePrice::WINDOW_DAYS,
                    ],
                    [
                        'price'         => $agg['price'],
                        'reports_total' => $agg['n'],
                        'reports_used'  => $agg['nUsed'],
                        'outliers'      => $agg['outliers'],
                        'scope'         => $agg['scope'],
                        'confidence'    => $agg['confidence'],
                        'freshest_days' => min(9999, $agg['freshestDays']),
                        'low'           => $agg['low'],
                        'high'          => $agg['high'],
                        'by_market'     => $agg['byMarket'],
                        'computed_at'   => $now,
                    ],
                );
                $written++;
            }
        }

        return $written;
    }

    /**
     * Reports for one item, in the shape the engine expects.
     *
     * Held and rejected reports are excluded here rather than inside the
     * engine: a moderator's decision is a property of the data, not of the
     * maths.
     */
    private function rowsFor(int $itemId, int $windowDays, bool $fresh = false): array
    {
        /*
         * Windows are rounded up to a few coarse buckets before they become a
         * cache key.
         *
         * The Prices screen asks for the same item at three different widths —
         * 30 days for the median, 62 for the trend, 114 for the chart — and
         * keying on the exact number would fetch the same rows three times
         * over. The aggregator filters by date itself, so a wider set is
         * always a correct answer to a narrower question.
         */
        $bucket = match (true) {
            $windowDays <= 31  => 31,
            $windowDays <= 65  => 65,
            $windowDays <= 130 => 130,
            default            => $windowDays,
        };
        $windowDays = $bucket;

        $key = $itemId . ':' . $windowDays;

        if (!$fresh && isset($this->corpus[$key])) {
            return $this->corpus[$key];
        }

        $rows = DB::table('price_reports')
            ->join('locations', 'locations.id', '=', 'price_reports.location_id')
            ->where('price_reports.item_id', $itemId)
            ->where('price_reports.status', 'live')
            ->where('price_reports.reported_at', '>=', now()->subDays($windowDays + 1))
            ->select(
                'price_reports.price',
                'price_reports.location_id',
                'price_reports.market_id',
                'price_reports.reputation',
                'price_reports.reported_at',
                'locations.state',
            )
            ->get()
            ->map(fn ($r) => [
                'price'       => (int) $r->price,
                'location_id' => (int) $r->location_id,
                'state'       => $r->state,
                'market_id'   => $r->market_id ? (int) $r->market_id : null,
                'at'          => strtotime($r->reported_at),
                'reputation'  => (float) $r->reputation,
            ])
            ->all();

        return $this->corpus[$key] = $rows;
    }
}
