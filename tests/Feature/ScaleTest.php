<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\CoachQuery;
use App\Models\PriceReport;
use App\Models\StatementAnalysis;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NetworkService;
use Illuminate\Support\Facades\DB;
use Tests\SeededTestCase;

/**
 * Does it still hold up with a real population behind it?
 *
 * Two failure modes this suite exists to catch, both of which only appear once
 * there is more than a handful of rows:
 *
 *   - N+1 queries. A console page that runs one query per member is fine at 4
 *     members and unusable at 120, and nothing about the rendered output says
 *     which one you are looking at.
 *   - Wall-clock. A screen that takes three seconds is a screen a judge assumes
 *     is broken.
 *
 * The thresholds are deliberately loose. They are there to catch an order of
 * magnitude, not to police a hundred milliseconds on somebody else's laptop.
 */
class ScaleTest extends SeededTestCase
{
    private const SLOW_MS  = 2500;
    private const N_PLUS_1 = 120;

    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    private function ops(): User
    {
        return User::where('email', 'a.okafor@moneypilot.ng')->firstOrFail();
    }

    private function owner(): User
    {
        return User::where('email', 't.bakare@moneypilot.ng')->firstOrFail();
    }

    /** @return array{ms:float,queries:int} */
    private function measure(User $as, string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = microtime(true);
        $this->actingAs($as)->get($uri)->assertOk();
        $ms = (microtime(true) - $started) * 1000;

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['ms' => $ms, 'queries' => $queries];
    }

    /* ---------------- the seed is actually big ---------------- */

    public function test_the_seeded_population_is_large_enough_to_mean_something(): void
    {
        $members = User::where('role', 'member')->count();

        $this->assertGreaterThanOrEqual(100, $members,
            'the console reads like a demo below about a hundred members');

        $this->assertGreaterThanOrEqual(2000, PriceReport::count());
        $this->assertGreaterThanOrEqual(100, Budget::where('month', date('Y-m'))->count());
    }

    /**
     * Feasibility is an OUTPUT. If every member balanced, the honest branch
     * would never be exercised; if none did, the engine would be broken.
     */
    public function test_the_cohort_contains_both_kinds_of_month(): void
    {
        $month    = date('Y-m');
        $feasible = Budget::where('month', $month)->where('feasible', true)->count();
        $short    = Budget::where('month', $month)->where('feasible', false)->count();

        $this->assertGreaterThan(10, $feasible, 'nobody balancing means the engine is wrong');
        $this->assertGreaterThan(10, $short, 'everybody balancing means the floor is not biting');
    }

    public function test_the_console_has_real_activity_to_report(): void
    {
        $this->assertGreaterThan(50, CoachQuery::count());
        $this->assertGreaterThan(5, CoachQuery::distinct()->count('intent'),
            'one intent everywhere would mean the classifier is not classifying');
        $this->assertGreaterThan(5, StatementAnalysis::count());
        $this->assertGreaterThan(50, Transaction::where('source', 'manual')->count());
        $this->assertGreaterThan(20, PriceReport::whereNotNull('user_id')->count());
    }

    /** The demo accounts stay curated, whatever the cohort does. */
    public function test_the_demo_personas_are_left_pristine(): void
    {
        $this->assertSame(0, CoachQuery::where('user_id', $this->member()->id)->count(),
            'the assistant thread should be empty and ready to demo live');

        $amaka = User::where('email', 'amaka.eze@gmail.com')->firstOrFail();

        $this->assertSame(0, $amaka->priceReports()->count(),
            'reputation has to be seen being earned from zero');
    }

    /* ---------------- no N+1 ---------------- */

    /**
     * The console pages are the ones at risk: they iterate members, items and
     * locations, and every one of those loops is a query per row waiting to
     * happen.
     */
    public function test_console_pages_do_not_run_a_query_per_row(): void
    {
        foreach ([
            '/console'             => $this->ops(),
            '/console/members'     => $this->ops(),
            '/console/contributors'=> $this->ops(),
            '/console/coverage'    => $this->ops(),
            '/console/index'       => $this->ops(),
            '/console/moderation'  => $this->ops(),
            '/console/business'    => $this->owner(),
        ] as $uri => $as) {
            $result = $this->measure($as, $uri);

            $this->assertLessThan(
                self::N_PLUS_1,
                $result['queries'],
                sprintf('%s ran %d queries — that is a query per row, not a page',
                    $uri, $result['queries']),
            );
        }
    }

    public function test_member_pages_do_not_run_a_query_per_row(): void
    {
        foreach (['/app', '/app/budget', '/app/spend', '/app/prices', '/app/plan', '/app/goals'] as $uri) {
            $result = $this->measure($this->member(), $uri);

            $this->assertLessThan(
                self::N_PLUS_1,
                $result['queries'],
                sprintf('%s ran %d queries', $uri, $result['queries']),
            );
        }
    }

    /* ---------------- wall clock ---------------- */

    public function test_every_member_screen_renders_quickly_enough_to_feel_live(): void
    {
        $slow = [];

        foreach ([
            '/app', '/app/budget', '/app/spend', '/app/prices', '/app/prices/rice',
            '/app/plan', '/app/goals', '/app/health', '/app/coach', '/app/profile',
        ] as $uri) {
            $result = $this->measure($this->member(), $uri);

            if ($result['ms'] > self::SLOW_MS) {
                $slow[] = sprintf('%s %.0fms (%d queries)', $uri, $result['ms'], $result['queries']);
            }
        }

        $this->assertEmpty($slow, "Slower than " . self::SLOW_MS . "ms:\n  " . implode("\n  ", $slow));
    }

    public function test_every_console_screen_renders_quickly_enough_to_feel_live(): void
    {
        $slow = [];

        $pages = [
            '/console' => $this->ops(), '/console/moderation' => $this->ops(),
            '/console/coverage' => $this->ops(), '/console/contributors' => $this->ops(),
            '/console/index' => $this->ops(), '/console/members' => $this->ops(),
            '/console/business' => $this->owner(),
        ];

        foreach ($pages as $uri => $as) {
            $result = $this->measure($as, $uri);

            if ($result['ms'] > self::SLOW_MS) {
                $slow[] = sprintf('%s %.0fms (%d queries)', $uri, $result['ms'], $result['queries']);
            }
        }

        $this->assertEmpty($slow, "Slower than " . self::SLOW_MS . "ms:\n  " . implode("\n  ", $slow));
    }

    /* ---------------- integrity at scale ---------------- */

    /**
     * The rule at the top of the migration, checked against every budget in the
     * database rather than one hand-picked scenario.
     */
    public function test_every_feasible_budget_sums_to_exactly_its_income(): void
    {
        $wrong = [];

        Budget::with('lines')->where('month', date('Y-m'))->where('feasible', true)
            ->chunk(50, function ($budgets) use (&$wrong) {
                foreach ($budgets as $b) {
                    $sum = (int) $b->lines->sum('amount');
                    if ($sum !== $b->income) {
                        $wrong[] = "#{$b->id}: lines {$sum} vs income {$b->income}";
                    }
                }
            });

        $this->assertEmpty($wrong,
            "Budgets that do not add up:\n  " . implode("\n  ", array_slice($wrong, 0, 10)));
    }

    /** No float ever touched a money column. */
    public function test_every_money_column_holds_a_whole_number_of_kobo(): void
    {
        $checks = [
            'budgets'       => ['income', 'floor_total', 'goal_monthly', 'shortfall'],
            'budget_lines'  => ['amount'],
            'transactions'  => ['amount'],
            'price_reports' => ['price'],
            'goals'         => ['target', 'saved'],
        ];

        foreach ($checks as $table => $columns) {
            foreach ($columns as $column) {
                $fractional = DB::table($table)
                    ->whereRaw("{$column} <> FLOOR({$column})")
                    ->count();

                $this->assertSame(0, $fractional, "{$table}.{$column} contains a fractional value");
            }
        }
    }

    /** Append-only: a reversal always has a real original behind it. */
    public function test_no_reversal_points_at_a_transaction_that_does_not_exist(): void
    {
        $orphans = DB::table('transactions as t')
            ->whereNotNull('t.reverses_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('transactions as o')->whereColumn('o.id', 't.reverses_id'))
            ->count();

        $this->assertSame(0, $orphans);
    }

    /** A price with no place cannot be aggregated, so it must not exist. */
    public function test_every_price_report_has_an_item_and_a_place(): void
    {
        $this->assertSame(0, PriceReport::whereNull('location_id')->count());
        $this->assertSame(0, PriceReport::whereNull('item_id')->count());
        $this->assertSame(0, PriceReport::where('price', '<=', 0)->count());
    }

    /** The network has to be able to answer, or the product has no thesis. */
    public function test_local_coverage_is_high_enough_for_the_product_to_work(): void
    {
        $health = app(NetworkService::class)->health();

        $this->assertGreaterThan(0.75, $health['coverage'],
            'below about 75% local coverage most members are getting a widened average');
        $this->assertSame(0, $health['dark'], 'no item-area pair should be unanswerable');
    }

    /** Nothing personal from a statement is ever written down. */
    public function test_no_statement_narration_reached_the_database_at_scale(): void
    {
        $leaked = [];

        foreach (StatementAnalysis::cursor() as $row) {
            $stored = json_encode($row->only(['findings', 'advice', 'spending']));

            foreach (['SHOPRITE', 'LANDLORD', 'SPORTYBET', 'MEDPLUS', 'PIGGYVEST'] as $needle) {
                if (str_contains($stored, $needle)) {
                    $leaked[] = "#{$row->id} contains {$needle}";
                }
            }
        }

        $this->assertEmpty($leaked, implode("\n  ", $leaked));
    }
}
