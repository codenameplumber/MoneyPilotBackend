<?php

namespace Database\Seeders;

use App\Domain\Prng;
use App\Models\Item;
use App\Models\Location;
use App\Models\PriceReport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The TruePrice corpus.
 *
 * An empty price network demos as a broken feature, so the app ships
 * pre-populated. Everything here flows from a seeded PRNG — no rand(), no
 * time-dependent randomness — so the same spike appears in the same market
 * on every run.
 */
class PriceCorpusSeeder extends Seeder
{
    private const WINDOW_DAYS = 90;

    /**
     * Planted price events. The product thesis is "we see prices move before
     * your budget does", so the corpus must actually contain a move, or the
     * flagship insight has nothing to fire on.
     *
     * [item slug, started days ago, magnitude, scope]
     */
    private const EVENTS = [
        ['rice',   24,  0.21, 'all'],
        ['tomato', 16,  0.15, 'Lagos'],
        ['gas',    40, -0.08, 'all'],
    ];

    private const FIRST_NAMES = [
        'Ada', 'Ife', 'Chidi', 'Musa', 'Ngozi', 'Tunde', 'Zainab', 'Emeka', 'Bisi', 'Yusuf',
        'Amara', 'Segun', 'Halima', 'Obi', 'Funke', 'Kelechi', 'Aisha', 'Femi', 'Uche', 'Sadiq',
        'Chioma', 'Bola', 'Idris', 'Nneka', 'Kunle', 'Rahma', 'Ebere', 'Dare', 'Larai', 'Ikenna',
    ];

    private const LAST_INITIALS = ['O.', 'A.', 'E.', 'M.', 'N.', 'U.', 'B.', 'S.', 'K.', 'I.'];

    public function run(): void
    {
        PriceReport::query()->delete();
        DB::table('contributor_stats')->delete();

        $reporters = $this->makeReporters();
        $items     = Item::all();
        $locations = Location::with('markets')->get();
        $now       = now();
        $rows      = [];

        foreach ($items as $item) {
            foreach ($locations as $location) {
                $r = Prng::seeded('prices', $item->slug, $location->slug);

                /* Scaled by reach rather than a flat floor plus a bonus. A
                   generous floor gives every pair enough reports to answer
                   locally, which quietly returns the network to 100% coverage
                   everywhere and leaves the coverage screen with nothing to
                   report. */
                $reach = $location->density * $item->commonness;
                $count = max(0, (int) round(2 + $r->next() * 2 + 24 * $reach));

                $markets      = $location->markets;
                $marketOffset = (int) floor($r->next() * max(1, $markets->count()));
                $phase        = $r->next();

                for ($n = 0; $n < $count; $n++) {
                    /* A random phase per pair staggers the sampling grids.
                       Without it every pair's first report lands on exactly
                       the same day, and today collects a spike five times the
                       daily average. */
                    $slot    = ($n + $phase) / max(1, $count);
                    $daysAgo = max(0, (int) round(self::WINDOW_DAYS * ($slot ** 1.08) + $r->jitter(3)));

                    /* Cycle markets rather than sampling them at random.
                       Markets inside one LGA differ by up to 13%, so random
                       sampling manufactures a 14% "price move" out of pure
                       sampling bias, which then outranks the genuine events. */
                    $market   = $markets->isEmpty() ? null : $markets[($n + $marketOffset) % $markets->count()];
                    $reporter = $r->pick($reporters);

                    $naira = ($item->base_price / 100)
                        * $location->price_multiplier
                        * ($market?->price_multiplier ?? 1.0)
                        * $this->drift($daysAgo)
                        * $this->eventMultiplier($item->slug, $location->state, $daysAgo)
                        * (1 + $r->jitter(0.02));

                    /* Sloppy reporters occasionally fat-finger a zero. This is
                       exactly the noise a mean swallows and a median shrugs
                       off, and what the moderation queue exists to catch. */
                    if ($reporter['sloppy'] && $r->next() < 0.22) {
                        $naira *= 10;
                    }

                    // Round the way a person reports a price, not to the kobo.
                    $step  = $naira > 2000 ? 50 : 10;
                    $naira = max($step, round($naira / $step) * $step);

                    $rows[] = [
                        'item_id'      => $item->id,
                        'location_id'  => $location->id,
                        'market_id'    => $market?->id,
                        'user_id'      => null,
                        'reporter_key' => $reporter['key'],
                        'price'        => (int) round($naira * 100),
                        'reputation'   => $reporter['reputation'],
                        'reported_at'  => $now->copy()->subDays($daysAgo),
                        'status'       => 'live',
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('price_reports')->insert($chunk);
        }

        $this->seedContributorStats($reporters);

        $this->command?->info(sprintf(
            '  corpus: %s reports from %d contributors',
            number_format(count($rows)),
            count($reporters)
        ));
    }

    /**
     * A small cast of reporters. Two are deliberately unreliable so the
     * outlier machinery has something real to catch.
     */
    private function makeReporters(): array
    {
        $r   = Prng::seeded('reporters', 'v1');
        $out = [];

        for ($i = 0; $i < 46; $i++) {
            $sloppy = $i === 7 || $i === 23;
            $out[] = [
                'key'        => 'rep_' . $i,
                'reputation' => round($sloppy ? 0.45 : 0.85 + $r->next() * 0.3, 3),
                'sloppy'     => $sloppy,
                'handle'     => $this->handleFor('rep_' . $i),
            ];
        }

        return $out;
    }

    /** Stable pseudonymous handles — real names are data we have no use for. */
    private function handleFor(string $key): string
    {
        $h = Prng::hash($key);

        return self::FIRST_NAMES[$h % count(self::FIRST_NAMES)]
            . ' '
            . self::LAST_INITIALS[($h >> 5) % count(self::LAST_INITIALS)];
    }

    /** Slow national drift, so nothing looks frozen. */
    private function drift(int $daysAgo): float
    {
        return 1 + (self::WINDOW_DAYS - $daysAgo) * 0.0006;
    }

    private function eventMultiplier(string $slug, string $state, int $daysAgo): float
    {
        $m = 1.0;

        foreach (self::EVENTS as [$item, $startedDaysAgo, $magnitude, $scope]) {
            if ($item !== $slug) {
                continue;
            }
            if ($scope !== 'all' && $scope !== $state) {
                continue;
            }
            if ($daysAgo > $startedDaysAgo) {
                continue;
            }

            // Ramp in rather than step, so the sparkline shows a rise a human
            // would recognise as a rise.
            $progress = 1 - $daysAgo / $startedDaysAgo;
            $m *= 1 + $magnitude * $progress;
        }

        return $m;
    }

    private function seedContributorStats(array $reporters): void
    {
        $now  = now();
        $rows = [];

        foreach ($reporters as $rep) {
            $stats = DB::table('price_reports')
                ->where('reporter_key', $rep['key'])
                ->selectRaw('COUNT(*) c, COUNT(DISTINCT location_id) areas, COUNT(DISTINCT item_id) items, MAX(reported_at) last')
                ->first();

            $rows[] = [
                'reporter_key'     => $rep['key'],
                'handle'           => $rep['handle'],
                'reputation'       => $rep['reputation'],
                'reports_count'    => $stats->c ?? 0,
                'flagged_count'    => 0,
                'areas'            => $stats->areas ?? 0,
                'items'            => $stats->items ?? 0,
                'last_reported_at' => $stats->last,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        DB::table('contributor_stats')->insert($rows);
    }
}
