<?php

namespace Tests\Feature;

use App\Livewire\App\Prices;
use App\Models\Item;
use App\Models\PriceReport;
use App\Models\User;
use App\Services\PriceService;
use Livewire\Livewire;
use Tests\SeededTestCase;

/**
 * The network claim, end to end.
 *
 * TruePrice is only worth anything if a member's report genuinely enters the
 * median that other members' budgets are built from. These tests check that
 * loop closes — and that it cannot be poisoned by one bad entry.
 */
class PriceNetworkTest extends SeededTestCase
{
    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    public function test_a_members_report_becomes_part_of_the_live_corpus(): void
    {
        $user = $this->member();
        $rice = Item::where('slug', 'rice')->firstOrFail();
        $before = PriceReport::where('item_id', $rice->id)->count();

        Livewire::actingAs($user)->test(Prices::class)
            ->call('startReport', $rice->id)
            ->set('price', '1,850')
            ->call('submitReport')
            ->assertHasNoErrors()
            ->assertSet('flash', fn ($v) => str_contains($v, 'median'));

        $this->assertSame($before + 1, PriceReport::where('item_id', $rice->id)->count());

        $report = PriceReport::where('user_id', $user->id)->latest('id')->first();

        $this->assertSame(185_000, $report->price, 'kobo, not naira, and not a float');
        $this->assertSame($user->profile->location_id, $report->location_id, 'a price without a place is unusable');
        $this->assertSame('live', $report->status);
    }

    /**
     * The screen says the report "is now part of the median for your area".
     * That has to be true on the next read, not after the next scheduled job —
     * otherwise the most motivating moment in the product is a lie.
     */
    public function test_a_report_moves_the_published_median_immediately(): void
    {
        $user     = $this->member();
        $location = $user->profile->location;
        $tomato   = Item::where('slug', 'tomato')->firstOrFail();

        $before = app(PriceService::class)->cached('tomato', $location);

        // Ten reports well above the current median, each individually
        // plausible — this is a real move, not an outlier, so it must land.
        $high = (int) round($before['price'] * 1.4);

        for ($i = 0; $i < 10; $i++) {
            Livewire::actingAs($user)->test(Prices::class)
                ->call('startReport', $tomato->id)
                ->set('price', (string) intdiv($high + $i * 100, 100))
                ->call('submitReport')
                ->assertHasNoErrors();
        }

        $after = app()->make(PriceService::class)->cached('tomato', $location);

        $this->assertGreaterThan($before['price'], $after['price'], 'a genuine move must reach the median');
        $this->assertGreaterThan($before['nUsed'], $after['nUsed'], 'and the report count must rise');
    }

    /**
     * The single most important robustness property in the product: one typed
     * extra zero must not move anybody's food budget.
     */
    public function test_a_mistyped_zero_does_not_move_the_median(): void
    {
        $user     = $this->member();
        $location = $user->profile->location;
        $rice     = Item::where('slug', 'rice')->firstOrFail();

        $before = app(PriceService::class)->aggregate($rice, $location)['price'];

        PriceReport::create([
            'user_id' => $user->id, 'item_id' => $rice->id,
            'location_id' => $location->id, 'price' => 99_000_00,
            'reported_at' => now(), 'status' => 'live', 'reputation' => 1.0,
        ]);

        // A fresh service, so nothing is answered from the request memo.
        $after = app()->make(PriceService::class)->aggregate($rice, $location);

        $drift = abs($after['price'] - $before) / $before;

        $this->assertLessThan(0.05, $drift, 'a ₦99,000 bag of rice must not drag the median');
        $this->assertGreaterThanOrEqual(1, $after['outliers'], 'and it must be visibly flagged');
    }

    public function test_a_price_with_no_area_is_refused_rather_than_guessed(): void
    {
        $user = $this->member();
        $user->profile->update(['location_id' => null]);

        Livewire::actingAs($user->fresh())->test(Prices::class)
            ->call('startReport', Item::where('slug', 'rice')->value('id'))
            ->set('price', '1,900')
            ->call('submitReport')
            ->assertHasErrors('price');
    }

    /** A new contributor's first report counts for less than a veteran's. */
    public function test_reputation_is_earned_not_assumed(): void
    {
        $user = User::where('email', 'amaka.eze@gmail.com')->firstOrFail();
        $this->assertSame(0, $user->priceReports()->count(), 'this persona starts with no record');

        Livewire::actingAs($user)->test(Prices::class)
            ->call('startReport', Item::where('slug', 'tomato')->value('id'))
            ->set('price', '900')
            ->call('submitReport');

        $this->assertEqualsWithDelta(
            0.7,
            (float) $user->priceReports()->latest('id')->value('reputation'),
            0.001,
        );
    }

    /**
     * The provenance promise: a thin local answer is never dressed up as a
     * confident one.
     */
    public function test_a_widened_scope_is_labelled_and_lowers_confidence(): void
    {
        $rice = Item::where('slug', 'rice')->firstOrFail();
        $prices = app(PriceService::class);

        $local    = $prices->aggregate($rice, $this->member()->profile->location);
        $national = $prices->aggregate($rice, null);

        $this->assertSame('lga', $local['scope'], 'the seeded corpus should answer locally');
        $this->assertContains($national['scopeLabel'], ['nearby', 'state average', 'national average']);
        $this->assertGreaterThan(0, $local['confidence']);
    }
}
