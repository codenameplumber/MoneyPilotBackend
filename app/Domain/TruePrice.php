<?php

namespace App\Domain;

/**
 * TruePrice — aggregation and trust for community price reports.
 *
 * A community price network is only as good as its defence against noise.
 * Three mechanisms, all cheap, all surfaced in the UI:
 *
 *   1. Weighted MEDIAN, not a mean. One fat-fingered ₦19,000 bag of rice
 *      drags a mean badly and a median not at all.
 *   2. Staleness decay. A report's weight halves every 21 days, so a price
 *      from Tuesday outranks one from last month without a hard cutoff that
 *      throws data away.
 *   3. MAD outlier flagging plus reporter reputation. Extreme reports are
 *      held out of the aggregate and marked for review — never deleted.
 *
 * And one rule that matters more than any of them: when local data is too
 * thin, widen the geography — but SAY SO. Silently substituting a national
 * figure for a local one is the failure that would quietly destroy this
 * product's credibility.
 *
 * Pure: no database access, no Eloquent. It is handed rows and returns a
 * verdict, which is what makes it testable and what lets a scheduled job
 * call it in bulk.
 */
final class TruePrice
{
    public const HALF_LIFE_DAYS = 21;
    public const MIN_REPORTS    = 3;
    public const WINDOW_DAYS    = 30;

    private const SCOPES = [
        'lga'      => 'nearby',
        'state'    => 'state average',
        'national' => 'national average',
    ];

    /** Exponential staleness decay: weight halves every HALF_LIFE_DAYS. */
    public static function decay(float $days): float
    {
        return 0.5 ** (max(0, $days) / self::HALF_LIFE_DAYS);
    }

    /** @param array<int|float> $values */
    public static function median(array $values): int
    {
        if (!$values) return 0;
        sort($values);
        $n = count($values);
        $m = intdiv($n, 2);

        return $n % 2
            ? (int) $values[$m]
            : (int) round(($values[$m - 1] + $values[$m]) / 2);
    }

    /** Median absolute deviation — a robust spread estimate. */
    public static function mad(array $values, ?int $med = null): int
    {
        if (!$values) return 0;
        $med ??= self::median($values);

        return self::median(array_map(static fn ($v) => abs($v - $med), $values));
    }

    /**
     * Weighted median: the value at which cumulative weight crosses half.
     *
     * @param array<array{value:int|float,weight:float}> $pairs
     */
    public static function weightedMedian(array $pairs): int
    {
        if (!$pairs) return 0;

        usort($pairs, static fn ($a, $b) => $a['value'] <=> $b['value']);
        $total = array_sum(array_column($pairs, 'weight'));

        if ($total <= 0) {
            return self::median(array_column($pairs, 'value'));
        }

        $acc = 0.0;
        foreach ($pairs as $p) {
            $acc += $p['weight'];
            if ($acc >= $total / 2) return (int) round($p['value']);
        }

        return (int) round(end($pairs)['value']);
    }

    /**
     * The core lookup.
     *
     * @param array<array{price:int,location_id:?int,state:?string,market_id:?int,at:int,reputation:float}> $reports
     *        `at` is a unix timestamp in seconds.
     * @return array{ok:bool,price:int,n:int,nUsed:int,scope:string,scopeLabel:string,
     *               freshestDays:int,confidence:float,outliers:int,spread:int,
     *               low:int,high:int,byMarket:array}
     */
    public static function aggregate(
        array $reports,
        ?int $locationId,
        ?string $state,
        int $asOf,
        int $windowDays = self::WINDOW_DAYS,
        int $minReports = self::MIN_REPORTS,
    ): array {
        $oldest = $asOf - $windowDays * 86400;
        $newest = $asOf + 86400;

        $inWindow = array_values(array_filter(
            $reports,
            static fn ($r) => $r['at'] <= $newest && $r['at'] >= $oldest
        ));

        foreach (self::SCOPES as $scope => $scopeLabel) {
            $pool = array_values(array_filter(
                $inWindow,
                static fn ($r) => match ($scope) {
                    'lga'   => $locationId !== null && $r['location_id'] === $locationId,
                    'state' => $state !== null && $r['state'] === $state,
                    default => true,
                }
            ));

            if (count($pool) < $minReports && $scope !== 'national') continue;
            if (!$pool) continue;

            $values = array_column($pool, 'price');
            $med    = self::median($values);
            $dev    = self::mad($values, $med);

            // When MAD collapses to zero (many identical reports) fall back to
            // a proportional band, so a single 10x typo is still caught.
            $band = $dev > 0 ? 2.5 * $dev : max($med * 0.45, 1);

            $kept = [];
            $outliers = 0;
            foreach ($pool as $r) {
                if (abs($r['price'] - $med) > $band) { $outliers++; continue; }
                $kept[] = $r;
            }
            $use = count($kept) >= 2 ? $kept : $pool;

            $pairs = array_map(static function ($r) use ($asOf) {
                $days = ($asOf - $r['at']) / 86400;
                return [
                    'value'  => $r['price'],
                    'weight' => self::decay($days) * ($r['reputation'] ?? 1.0),
                ];
            }, $use);

            $price = self::weightedMedian($pairs);
            $effN  = array_sum(array_column($pairs, 'weight'));

            $ages = array_map(static fn ($r) => ($asOf - $r['at']) / 86400, $use);
            $freshest = max(0, min($ages));

            // Confidence is deliberately harsh. A thin, stale, or
            // widened-scope reading must present itself as an estimate.
            $scopePenalty = match ($scope) {
                'lga' => 1.0, 'state' => 0.72, default => 0.5,
            };
            $confidence = max(0.08, min(1.0,
                min(1.0, $effN / 4) * $scopePenalty * self::decay($freshest * 0.6)
            ));

            $sorted = array_column($use, 'price');
            sort($sorted);

            return [
                'ok'           => true,
                'price'        => $price,
                'n'            => count($pool),
                'nUsed'        => count($use),
                'outliers'     => $outliers,
                'scope'        => $scope,
                'scopeLabel'   => $scopeLabel,
                'freshestDays' => (int) round($freshest),
                'confidence'   => round($confidence, 3),
                'spread'       => count($sorted) > 1 ? end($sorted) - $sorted[0] : 0,
                'low'          => $sorted[0] ?? $price,
                'high'         => end($sorted) ?: $price,
                'byMarket'     => self::groupByMarket($use, $asOf),
            ];
        }

        return self::miss();
    }

    public static function miss(): array
    {
        return [
            'ok' => false, 'price' => 0, 'n' => 0, 'nUsed' => 0, 'outliers' => 0,
            'scope' => 'national', 'scopeLabel' => 'no data', 'freshestDays' => PHP_INT_MAX,
            'confidence' => 0.0, 'spread' => 0, 'low' => 0, 'high' => 0, 'byMarket' => [],
        ];
    }

    private static function groupByMarket(array $reports, int $asOf): array
    {
        $bag = [];
        foreach ($reports as $r) {
            $key = ($r['location_id'] ?? 0) . ':' . ($r['market_id'] ?? 0);
            $bag[$key] ??= [
                'location_id' => $r['location_id'] ?? null,
                'market_id'   => $r['market_id'] ?? null,
                'pairs'       => [],
                'newest'      => -INF,
            ];
            $days = ($asOf - $r['at']) / 86400;
            $bag[$key]['pairs'][] = [
                'value'  => $r['price'],
                'weight' => self::decay($days) * ($r['reputation'] ?? 1.0),
            ];
            $bag[$key]['newest'] = max($bag[$key]['newest'], -$days);
        }

        $out = array_map(static fn ($e) => [
            'location_id' => $e['location_id'],
            'market_id'   => $e['market_id'],
            'price'       => self::weightedMedian($e['pairs']),
            'n'           => count($e['pairs']),
            'ageDays'     => (int) round(-$e['newest']),
        ], array_values($bag));

        usort($out, static fn ($a, $b) => $a['price'] <=> $b['price']);

        return $out;
    }

    /**
     * Change over time: this window's median against the previous window's.
     *
     * Powers the price-shock insight — the one thing no competing budgeting
     * app can compute, because none of them have the data.
     */
    /**
     * A price history, as a sequence of medians rather than raw reports.
     *
     * Plotting individual reports would draw the noise, not the price — one
     * trader's bad Tuesday becomes a visible spike. Each point here is a small
     * rolling aggregate, so the line is the same quantity the budget is built
     * from, just measured at intervals.
     *
     * Buckets with too little evidence carry the previous value forward rather
     * than dropping to zero, and leading gaps are back-filled from the first
     * real reading — a chart that dives to nothing because nobody shopped that
     * week is a lie about the price.
     *
     * @return array<array{at:int,value:int,n:int}>
     */
    public static function series(
        array $reports,
        ?int $locationId,
        ?string $state,
        int $asOf,
        int $days = 84,
        int $buckets = 14,
    ): array {
        $width = $days / max(1, $buckets);
        $out   = [];
        $last  = null;

        for ($b = $buckets - 1; $b >= 0; $b--) {
            $end = (int) round($asOf - $b * $width * 86400);
            $agg = self::aggregate(
                $reports, $locationId, $state, $end,
                (int) max($width * 2, 10), minReports: 2,
            );

            $value = $agg['ok'] ? $agg['price'] : $last;
            if ($value !== null) {
                $last = $value;
            }

            $out[] = ['at' => $end, 'value' => $value, 'n' => $agg['n']];
        }

        $firstReal = null;
        foreach ($out as $i => $p) {
            if ($p['value'] !== null) { $firstReal = $i; break; }
        }

        if ($firstReal === null) {
            return [];
        }

        for ($i = 0; $i < $firstReal; $i++) {
            $out[$i]['value'] = $out[$firstReal]['value'];
        }

        return $out;
    }

    public static function trend(
        array $reports,
        ?int $locationId,
        ?string $state,
        int $asOf,
        int $windowDays = self::WINDOW_DAYS,
    ): array {
        $now  = self::aggregate($reports, $locationId, $state, $asOf, $windowDays);
        $prev = self::aggregate($reports, $locationId, $state, $asOf - $windowDays * 86400, $windowDays);

        if (!$now['ok'] || !$prev['ok'] || $prev['price'] === 0) {
            return ['ok' => false, 'change' => 0.0, 'absolute' => 0, 'now' => $now, 'prev' => $prev];
        }

        return [
            'ok'       => true,
            'change'   => ($now['price'] - $prev['price']) / $prev['price'],
            'absolute' => $now['price'] - $prev['price'],
            'now'      => $now,
            'prev'     => $prev,
        ];
    }
}
