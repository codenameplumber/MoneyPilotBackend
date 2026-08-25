<?php

namespace Tests\Unit;

use App\Domain\Allocator;
use App\Domain\Basket;
use App\Domain\Categories;
use App\Domain\Goals;
use App\Domain\Money;
use App\Domain\Prng;
use App\Domain\TruePrice;
use PHPUnit\Framework\TestCase;

/**
 * The engine, verified without a database.
 *
 * These are the same hand-checked scenarios the JavaScript prototype is held
 * to. They are the specification: if the PHP port drifts from them, a
 * member's budget quietly changes and nobody finds out from a screen.
 *
 * Everything here is pure — no Eloquent, no container, no migrations — which
 * is exactly why the engine was kept free of framework dependencies.
 */
class EngineTest extends TestCase
{
    /* ---------------- money ---------------- */

    public function test_kobo_parsing_survives_commas_currency_and_stray_text(): void
    {
        $this->assertSame(8_500_000, Money::toKobo('85,000'));
        $this->assertSame(145_050, Money::toKobo('₦1,450.50'));
        $this->assertSame(0, Money::toKobo('nonsense'));
        $this->assertSame(0, Money::toKobo(''));
    }

    /** The classic naive-rounding bug: three equal shares of 100. */
    public function test_distribute_apportions_with_no_lost_kobo(): void
    {
        $out = Money::distribute(100, ['a' => 1, 'b' => 1, 'c' => 1]);
        $this->assertSame(100, array_sum($out), 'parts must sum to the whole');

        $messy = Money::distribute(8_500_037, ['a' => 3, 'b' => 2, 'c' => 2, 'd' => 1]);
        $this->assertSame(8_500_037, array_sum($messy));
    }

    public function test_ceil_div_never_under_funds_a_goal(): void
    {
        $this->assertSame(34, Money::ceilDiv(100, 3));
        $this->assertGreaterThanOrEqual(100, Money::ceilDiv(100, 3) * 3);
    }

    public function test_fmt_renders_naira_without_inventing_kobo(): void
    {
        $this->assertSame('₦85,000', Money::fmt(8_500_000));
        $this->assertSame('−₦4,500', Money::fmt(-450_000));
    }

    /* ---------------- deterministic seeding ---------------- */

    public function test_the_prng_is_reproducible(): void
    {
        $a = Prng::seeded('prices', 'rice', 'ikeja');
        $b = Prng::seeded('prices', 'rice', 'ikeja');

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($a->next(), $b->next(), 'the seeded corpus must not move between runs');
        }
    }

    /* ---------------- TruePrice ---------------- */

    public function test_weighted_median_resists_a_10x_outlier_where_a_mean_would_not(): void
    {
        $values = [1000, 1050, 1100, 1150, 11000];
        $mean = array_sum($values) / count($values);

        $this->assertSame(1100, TruePrice::median($values));
        $this->assertGreaterThan(3000, $mean, 'the mean should indeed be wrecked');

        $weighted = TruePrice::weightedMedian(array_map(
            static fn ($v) => ['value' => $v, 'weight' => 1.0],
            $values,
        ));
        $this->assertSame(1100, $weighted);
    }

    public function test_a_typo_is_held_out_and_barely_moves_the_median(): void
    {
        $asOf = time();
        $rows = [];
        foreach ([1900, 1950, 2000, 2050, 2100, 1980] as $i => $price) {
            $rows[] = $this->row($price, 1, 'Lagos', $asOf - $i * 86400);
        }

        $clean = TruePrice::aggregate($rows, 1, 'Lagos', $asOf);

        $rows[] = $this->row(99_000_00, 1, 'Lagos', $asOf);   // a typed zero
        $dirty = TruePrice::aggregate($rows, 1, 'Lagos', $asOf);

        $this->assertGreaterThanOrEqual(1, $dirty['outliers'], 'the typo should be flagged');

        $drift = abs($dirty['price'] - $clean['price']) / $clean['price'];
        $this->assertLessThan(0.05, $drift, 'the median should barely move');
    }

    public function test_thin_local_data_widens_the_scope_and_labels_it(): void
    {
        $asOf = time();
        // Two reports, both in a different LGA than the one being asked about.
        $rows = [
            $this->row(1900, 2, 'Enugu', $asOf - 86400),
            $this->row(1950, 2, 'Enugu', $asOf - 2 * 86400),
        ];

        $agg = TruePrice::aggregate($rows, 1, 'Lagos', $asOf);

        $this->assertTrue($agg['ok']);
        $this->assertNotSame('lga', $agg['scope'], 'must not claim a local answer it does not have');
        $this->assertContains($agg['scopeLabel'], ['state average', 'national average']);
        $this->assertLessThan(0.75, $agg['confidence'], 'a widened scope must lower confidence');
    }

    public function test_staleness_decay_halves_every_21_days(): void
    {
        $this->assertEqualsWithDelta(1.0, TruePrice::decay(0), 0.001);
        $this->assertEqualsWithDelta(0.5, TruePrice::decay(21), 0.001);
        $this->assertEqualsWithDelta(0.25, TruePrice::decay(42), 0.001);
    }

    /* ---------------- the floor ---------------- */

    public function test_household_scaling_is_sub_linear(): void
    {
        $this->assertSame(1.0, Basket::adultEquivalents(1));
        $this->assertSame(3.25, Basket::adultEquivalents(4));
        $this->assertLessThan(4, Basket::adultEquivalents(4), 'four people must cost less than four singles');
    }

    public function test_the_floor_is_measured_and_explains_itself(): void
    {
        $floor = Basket::cost(
            fn (string $slug) => $this->priceOf($slug, 1000_00),
            ['household_size' => 1, 'dependents' => 0, 'trips_per_day' => 2, 'days_out' => 22],
        );

        $this->assertGreaterThan(0, $floor['food']['total']);
        $this->assertGreaterThan(0, $floor['transport']['total']);
        $this->assertGreaterThanOrEqual(2, count($floor['assumptions']), 'the floor must explain itself');
        $this->assertSame(
            $floor['food']['total'] + $floor['utilities']['total'] + $floor['transport']['total'],
            $floor['total'],
        );
    }

    /** The whole thesis: same person, different place, different floor. */
    public function test_a_dearer_area_produces_a_higher_floor(): void
    {
        $profile = ['household_size' => 1, 'dependents' => 0, 'trips_per_day' => 2, 'days_out' => 22];

        $cheap = Basket::cost(fn ($s) => $this->priceOf($s, 1000_00), $profile);
        $dear  = Basket::cost(fn ($s) => $this->priceOf($s, 1300_00), $profile);

        $this->assertGreaterThan($cheap['total'], $dear['total']);
    }

    /* ---------------- allocation ---------------- */

    public function test_variable_income_is_discounted_not_taken_at_face_value(): void
    {
        $this->assertSame(10_000_000, Allocator::expectedIncome([
            ['amount' => 10_000_000, 'reliability' => 'certain'],
        ]));
        $this->assertSame(7_000_000, Allocator::expectedIncome([
            ['amount' => 10_000_000, 'reliability' => 'variable'],
        ]));
    }

    public function test_a_feasible_budget_sums_to_exactly_the_expected_income(): void
    {
        $budget = $this->budgetFor(income: 400_000_00, floorPrice: 400_00);

        $this->assertTrue($budget['feasible'], 'this scenario should balance');
        $this->assertSame(
            $budget['income'],
            array_sum(array_column($budget['allocations'], 'amount')),
            'the budget must add up to the income printed above it',
        );
    }

    public function test_every_allocation_line_carries_a_derivation(): void
    {
        $budget = $this->budgetFor(income: 400_000_00, floorPrice: 400_00);

        foreach ($budget['allocations'] as $line) {
            $this->assertNotEmpty($line['reason'], "{$line['category']} has no reason");
            $this->assertContains($line['source'], ['trueprice', 'rule', 'user']);
        }

        $priced = array_filter($budget['allocations'], fn ($l) => $l['source'] === 'trueprice');
        $this->assertGreaterThanOrEqual(2, count($priced), 'food and transport must be priced from reports');
    }

    /**
     * The honest branch. A budgeting app that always produces a tidy budget
     * is lying to the people whose situation is not tidy.
     */
    public function test_an_impossible_month_reports_infeasible_rather_than_shrinking_the_goal(): void
    {
        $budget = $this->budgetFor(income: 60_000_00, floorPrice: 900_00);

        $this->assertFalse($budget['feasible']);
        $this->assertGreaterThan(0, $budget['shortfall']);
        $this->assertNotEmpty($budget['reason']);

        $savings = collect($budget['allocations'])->firstWhere('category', 'savings');
        $expected = Goals::required(['target' => 18_000_000, 'saved' => 0, 'months_left' => 10])['monthly'];

        $this->assertSame(
            $expected,
            $savings['amount'],
            'the savings line must not be silently reduced to make the budget fit',
        );
    }

    public function test_preference_weights_move_discretionary_but_never_the_floor(): void
    {
        $a = $this->budgetFor(400_000_00, 400_00, weights: ['social' => 5, 'buffer' => 1, 'health' => 1, 'data' => 1, 'personal' => 1, 'education' => 1, 'family' => 1]);
        $b = $this->budgetFor(400_000_00, 400_00, weights: ['social' => 0, 'buffer' => 5, 'health' => 1, 'data' => 1, 'personal' => 1, 'education' => 1, 'family' => 1]);

        $byA = Allocator::byCategory($a);
        $byB = Allocator::byCategory($b);

        $this->assertSame($byA['food'], $byB['food'], 'the food floor must not move with preferences');
        $this->assertGreaterThan($byB['social'] ?? 0, $byA['social'] ?? 0, 'weights must actually bite');
        $this->assertSame($a['income'], array_sum(array_column($a['allocations'], 'amount')));
    }

    /* ---------------- goals ---------------- */

    public function test_the_monthly_contribution_is_derived_and_never_under_funds(): void
    {
        $req = Goals::required(['target' => 18_000_000, 'saved' => 4_200_000, 'months_left' => 10]);

        $this->assertSame(13_800_000, $req['outstanding']);
        $this->assertSame(1_380_000, $req['monthly']);
        $this->assertGreaterThanOrEqual($req['outstanding'], $req['monthly'] * $req['months']);
    }

    public function test_category_health_carries_a_label_not_just_a_colour(): void
    {
        $this->assertSame('crit', Categories::health(1000, 1200)['state']);
        $this->assertSame('Over budget', Categories::health(1000, 1200)['label']);
        $this->assertSame('warn', Categories::health(1000, 900)['state']);
        $this->assertSame('good', Categories::health(1000, 300)['state']);
    }

    /* ---------------- helpers ---------------- */

    private function row(int $price, int $locationId, string $state, int $at): array
    {
        return [
            'price' => $price, 'location_id' => $locationId, 'state' => $state,
            'market_id' => 1, 'at' => $at, 'reputation' => 1.0,
        ];
    }

    /** A stand-in aggregate, so the allocator can be tested without a corpus. */
    private function priceOf(string $slug, int $price): array
    {
        return TruePrice::miss() === null ? [] : [
            'ok' => true, 'price' => $price, 'n' => 6, 'nUsed' => 6, 'outliers' => 0,
            'scope' => 'lga', 'scopeLabel' => 'nearby', 'freshestDays' => 1,
            'confidence' => 0.9, 'spread' => 0, 'low' => $price, 'high' => $price,
            'byMarket' => [],
        ];
    }

    private function budgetFor(int $income, int $floorPrice, array $weights = []): array
    {
        return Allocator::allocate([
            'profile'     => ['household_size' => 1, 'dependents' => 0, 'trips_per_day' => 2, 'days_out' => 22],
            'sources'     => [['amount' => $income, 'reliability' => 'certain', 'label' => 'Salary']],
            'obligations' => [['amount' => 25_000_00, 'category' => 'housing', 'label' => 'Rent', 'due_day' => 1]],
            'goals'       => [['label' => 'Laptop', 'target' => 18_000_000, 'saved' => 0, 'months_left' => 10]],
            'weights'     => $weights,
        ], fn (string $slug) => $this->priceOf($slug, $floorPrice));
    }
}
