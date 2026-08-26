<?php

namespace Database\Seeders;

use App\Domain\HealthCheck;
use App\Domain\Prng;
use App\Domain\SampleStatement;
use App\Domain\Statement;
use App\Models\CoachQuery;
use App\Models\Item;
use App\Models\PriceReport;
use App\Models\StatementAnalysis;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CoachService;
use App\Services\PriceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Use of the product, as opposed to existence of members.
 *
 * The owner dashboard measures three things a member base alone cannot show:
 * whether people log spending in the app, whether they contribute prices, and
 * what they ask the assistant. Seeding members without any of that leaves every
 * activation metric at 0% next to a headline count of 120, which reads as a
 * broken product rather than a fresh install.
 *
 * Every row here is generated the way the app would generate it:
 *
 *   - Coach answers come from CoachService, not from a list of canned strings.
 *     If the engine changes its wording, this corpus changes with it.
 *   - Statement analyses come from HealthCheck reading a real CSV.
 *   - Price reports go through the same aggregate refresh a live report does.
 *
 * Which means none of it can drift away from what the product actually does.
 */
class ActivitySeeder extends Seeder
{
    /** Roughly what proportion of members do each thing. */
    private const LOGS_SPENDING   = 0.42;
    private const REPORTS_PRICES  = 0.28;
    private const ASKS_ASSISTANT  = 0.35;
    private const RUNS_HEALTH     = 0.14;

    private const QUESTIONS = [
        'How much can I spend today?',
        'Can I afford ₦8,000 for dinner tonight?',
        'Am I on track this month?',
        'What should I cut?',
        'Why is my food budget that much?',
        'What is my biggest expense?',
        'How much is rice?',
        'Where is rice cheapest?',
        'How much have I spent on transport?',
        'How much is left for food?',
        'Can I afford ₦25,000 for a new phone?',
        'How much is gas near me?',
        'Where is tomatoes cheapest?',
        'Am I doing ok?',
        'How much have I spent on data?',
    ];

    /**
     * The four demo accounts are left alone.
     *
     * They are what a judge opens, so they stay in a known, curated state: an
     * empty assistant thread ready to be asked the first question live, and a
     * contributor record still at zero so reputation can be seen being earned.
     * Random seeded activity on top of that makes the demo worse, and it also
     * quietly broke two tests that (correctly) assumed these are pristine.
     */
    public const CURATED = [
        'chidi@student.unilag.ng',
        'amaka.eze@gmail.com',
        'a.okafor@moneypilot.ng',
        't.bakare@moneypilot.ng',
    ];

    public function run(): void
    {
        $r       = Prng::seeded('activity', 'v1');
        $coach   = app(CoachService::class);
        $prices  = app(PriceService::class);
        // Explicitly ordered, for the same reason the cohort is: without it the
        // row order is whatever MySQL feels like, so which member gets which
        // branch of the shared generator changes between runs.
        $members = User::where('role', 'member')
            ->whereNotIn('email', self::CURATED)
            ->with('profile')
            ->orderBy('id')
            ->get();

        $spenders = $askers = $reporters = $checkers = 0;

        foreach ($members as $user) {
            if ($r->next() < self::LOGS_SPENDING) {
                $this->logSpending($user, $r);
                $spenders++;
            }

            if ($user->profile?->location_id && $r->next() < self::REPORTS_PRICES) {
                $this->reportPrices($user, $r, $prices);
                $reporters++;
            }

            if ($r->next() < self::ASKS_ASSISTANT) {
                $this->askQuestions($user, $r, $coach);
                $askers++;
            }
        }

        // The health check is the heaviest thing to seed — a full statement
        // parse and analysis each — so it runs over a small sample.
        foreach ($members->take((int) round($members->count() * self::RUNS_HEALTH)) as $user) {
            $this->runHealthCheck($user);
            $checkers++;
        }

        // Member reports have entered the corpus, so the published medians are
        // now stale by exactly those rows.
        $prices->refreshAggregates();

        $this->command?->info(sprintf(
            '  activity: %d logging spending · %d reporting prices · %d asking the assistant · %d health checks',
            $spenders, $reporters, $askers, $checkers,
        ));
    }

    /**
     * Entries logged IN the app, which is what activation counts. The cohort's
     * imported history is marked `seed` and deliberately does not qualify.
     */
    private function logSpending(User $user, Prng $r): void
    {
        $notes = [
            ['food',      'Cooked food'],  ['food',      'Market run'],
            ['transport', 'Bus fare'],     ['transport', 'Okada'],
            ['data',      'Airtime'],      ['personal',  'Barber'],
            ['social',    'Outing'],       ['health',    'Pharmacy'],
        ];

        $rows = [];
        $now  = now();
        $n    = 2 + (int) floor($r->next() * 9);

        for ($i = 0; $i < $n; $i++) {
            [$category, $note] = $r->pick($notes);

            $rows[] = [
                'user_id'     => $user->id,
                // Clamped to now: this stamps a random hour onto a date that
                // may be today, so a run earlier in the day produced entries
                // dated in the future — which the dashboard then rendered,
                // accurately, as "spent 14h from now".
                'occurred_at' => $now->copy()->subDays((int) floor($r->next() * (int) $now->format('j')))
                                    ->setTime(8 + (int) floor($r->next() * 12), 0)->min($now->copy()),
                'amount'      => (int) round((200_00 + $r->next() * 400_000) / 100) * 100,
                'category'    => $category,
                'item_id'     => null,
                'note'        => $note,
                'source'      => 'manual',
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        DB::table('transactions')->insert($rows);
    }

    /** Prices contributed through the app, against the member's own area. */
    private function reportPrices(User $user, Prng $r, PriceService $prices): void
    {
        /*
         * Picked with the seeded generator, not inRandomOrder().
         *
         * MySQL's RAND() is not seeded from anything this class controls, so it
         * made the corpus — and therefore every median downstream of it —
         * different on every run. "Deterministic" has to mean the whole seeder,
         * not the parts that were convenient.
         */
        $all      = Item::orderBy('id')->get();
        $wanted   = 1 + (int) floor($r->next() * 5);
        $items    = collect();

        for ($i = 0; $i < $wanted; $i++) {
            $items->push($all[(int) floor($r->next() * $all->count()) % $all->count()]);
        }

        $items    = $items->unique('id');
        $location = $user->profile->location;
        $filed    = $user->priceReports()->count();

        foreach ($items as $item) {
            $agg = $prices->cached($item->slug, $location);
            if (! $agg['ok']) {
                continue;
            }

            // Around the going rate, not on top of it — a contributor whose
            // reports all sat 30% high would be flagged, correctly.
            $price = (int) round($agg['price'] * (1 + $r->jitter(0.09)) / 100) * 100;

            PriceReport::create([
                'user_id'     => $user->id,
                'item_id'     => $item->id,
                'location_id' => $location->id,
                'market_id'   => null,
                'price'       => max(100, $price),
                'reported_at' => now()->subDays((int) floor($r->next() * 21)),
                'status'      => 'live',
                'reputation'  => $filed >= 5 ? 1.0 : 0.7,
            ]);

            $filed++;
        }
    }

    /**
     * Real questions through the real engine.
     *
     * used_llm is false throughout, and honestly so: no key is configured while
     * seeding, so every one of these is the engine's own wording. The owner
     * dashboard's "phrased by a model" figure therefore starts at 0% and only
     * moves once Claude is switched on — which is the truth.
     */
    private function askQuestions(User $user, Prng $r, CoachService $coach): void
    {
        $n    = 1 + (int) floor($r->next() * 6);
        $rows = [];
        $now  = now();

        for ($i = 0; $i < $n; $i++) {
            $q      = $r->pick(self::QUESTIONS);
            $result = $coach->ask($q, $user);

            $rows[] = [
                'user_id'    => $user->id,
                'question'   => $q,
                'intent'     => $result['intent'],
                'answer'     => $result['answer'],
                'used_llm'   => false,
                'detail'     => json_encode([
                    'tone'      => $result['tone'] ?? 'info',
                    'detail'    => $result['detail'] ?? [],
                    'note'      => $result['note'] ?? null,
                    'followups' => $result['followups'] ?? [],
                    'engine'    => $result['answer'],
                ]),
                'created_at' => $now->copy()->subDays((int) floor($r->next() * 30)),
                'updated_at' => $now,
            ];
        }

        DB::table('coach_queries')->insert($rows);
    }

    /** A real statement, really parsed, really analysed. */
    private function runHealthCheck(User $user): void
    {
        $parsed = Statement::parse(SampleStatement::csv());

        if (! $parsed['ok']) {
            return;
        }

        $a = HealthCheck::analyse($parsed['rows']);

        if (! $a['ok']) {
            return;
        }

        StatementAnalysis::create([
            'user_id'       => $user->id,
            'period_from'   => date('Y-m-d', $a['from']),
            'period_to'     => date('Y-m-d', $a['to']),
            'tx_count'      => $a['txCount'],
            'monthly_in'    => max(0, $a['monthlyIn']),
            'monthly_out'   => max(0, $a['monthlyOut']),
            'net_per_month' => $a['netPerMonth'],
            'payday'        => $a['timing']['payday'],
            'exposed_total' => $a['timing']['exposedTotal'],
            'score'         => $a['score']['value'],
            'band'          => $a['score']['band'],
            // Findings only. No narration ever reaches the database.
            'findings'      => array_map(
                static fn ($f) => ['severity' => $f['severity'], 'title' => $f['title'], 'body' => $f['body']],
                $a['findings'],
            ),
            'advice'        => $a['advice'],
            'spending'      => array_map(
                static fn ($c) => [
                    'category' => $c['category'], 'label' => $c['label'],
                    'monthly'  => $c['monthly'],  'share' => round($c['share'], 4),
                    'count'    => $c['count'],
                ],
                $a['spending'],
            ),
        ]);
    }
}
