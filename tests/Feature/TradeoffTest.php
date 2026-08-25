<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BudgetService;
use App\Services\TradeoffService;
use Tests\SeededTestCase;

/**
 * The behaviour that separates a co-pilot from a spreadsheet.
 *
 * Amaka's month genuinely does not balance. The product's claim is that it
 * says so, and then does the harder work of producing options that are costed
 * from what things actually sell for near her.
 */
class TradeoffTest extends SeededTestCase
{
    private function amaka(): User
    {
        return User::where('email', 'amaka.eze@gmail.com')->firstOrFail();
    }

    public function test_an_infeasible_month_produces_costed_options(): void
    {
        $user   = $this->amaka();
        $budget = app(BudgetService::class)->current($user);

        $this->assertFalse($budget->feasible, 'this persona is meant to be the hard case');

        $options = app(TradeoffService::class)->rank($user, $budget->shortfall);

        $this->assertNotEmpty($options, 'saying "no" without options is not advice');

        foreach ($options as $o) {
            $this->assertGreaterThan(0, $o['recovers'], 'an option that frees nothing is noise');
            $this->assertNotEmpty($o['evidence'], "{$o['id']} cannot show its working");
            $this->assertNotEmpty($o['detail']);
        }
    }

    /**
     * The ordering policy, which is the opinionated part.
     *
     * Skipping a savings contribution frees a large round number in one move,
     * so on raw naira it would always be the headline advice. It is ranked
     * last on purpose.
     */
    public function test_giving_up_a_goal_is_never_the_first_suggestion(): void
    {
        $user    = $this->amaka();
        $budget  = app(BudgetService::class)->current($user);
        $options = app(TradeoffService::class)->rank($user, $budget->shortfall);

        $kinds = array_column($options, 'kind');

        if (! in_array('pause', $kinds, true)) {
            $this->markTestSkipped('no pausable goal in this scenario');
        }

        $this->assertNotSame('pause', $kinds[0], 'abandoning a goal must never lead the list');

        $lastNonPause = max(array_keys(array_filter($kinds, fn ($k) => $k !== 'pause')));
        $firstPause   = min(array_keys(array_filter($kinds, fn ($k) => $k === 'pause')));

        $this->assertGreaterThan($lastNonPause, $firstPause, 'every gentler option comes first');
    }

    /**
     * A list where two entries matter and three are noise teaches people to
     * stop reading the list. "Shop at Ikeja Along — saves ₦69" was
     * arithmetically true and actively corrosive.
     */
    public function test_options_too_small_to_notice_are_dropped_not_listed(): void
    {
        $user   = $this->amaka();
        $budget = app(BudgetService::class)->current($user);

        $floor   = max(500_00, (int) round($budget->shortfall * 0.01));
        $options = app(TradeoffService::class)->rank($user, $budget->shortfall);

        $this->assertNotEmpty($options, 'the floor must not swallow the whole list');

        foreach ($options as $o) {
            $this->assertGreaterThanOrEqual(
                $floor,
                $o['recovers'],
                "\"{$o['title']}\" frees too little to be worth a line",
            );
        }
    }

    public function test_the_budget_screen_shows_the_options_to_the_member(): void
    {
        $this->actingAs($this->amaka())->get('/app/budget')
            ->assertOk()
            ->assertSee('What you could do instead')
            ->assertSee('Short by');
    }

    /**
     * Applying a trade-off is the member's decision and it must actually
     * change the arithmetic — an "Apply" button that only shows a toast is
     * the prototype behaviour this build exists to replace.
     */
    public function test_extending_a_deadline_lowers_the_monthly_contribution(): void
    {
        $user = $this->amaka();
        $goal = $user->goals()->where('paused', false)->firstOrFail();

        $before = $goal->requirement()['monthly'];

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\App\BudgetScreen::class)
            ->call('apply', 'extend-goal', $goal->id, 3)
            ->assertSet('applied', fn ($v) => str_contains($v, 'more month'));

        $after = $goal->fresh()->requirement()['monthly'];

        $this->assertLessThan($before, $after, 'the contribution must genuinely fall');
    }

    public function test_movers_ignore_a_price_move_that_is_only_sampling_noise(): void
    {
        $location = $this->amaka()->profile->location;
        $movers   = app(TradeoffService::class)->movers($location, 10);

        foreach ($movers as $m) {
            $this->assertGreaterThanOrEqual(
                TradeoffService::MOVER_MIN_REPORTS,
                min($m['trend']['now']['nUsed'], $m['trend']['prev']['nUsed']),
                "{$m['item']->slug} was reported as moving on too little evidence",
            );
            $this->assertGreaterThanOrEqual(TradeoffService::MOVER_MIN_CHANGE, abs($m['trend']['change']));
        }
    }
}
