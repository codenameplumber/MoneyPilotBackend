<?php

namespace App\Livewire\App;

use App\Domain\Money;
use App\Services\BudgetService;
use App\Services\PlanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The week, and the commute.
 *
 * "₦42,000 for food" is information. Seven days of real meals, costed at this
 * week's local prices, that fit inside ₦42,000, is something a person can act
 * on tonight — and the shopping list underneath it is the thing they actually
 * take to the market.
 *
 * The plan is deterministic: the same member, the same prices and the same
 * household produce the same week, so it can be compared against last week's
 * rather than reshuffling every time the page is opened.
 */
#[Layout('layouts.app')]
class Plan extends Component
{
    #[Url]
    public string $tab = 'meals';

    /** How many people are eating. Changes the plan, not just the total. */
    public int $servings = 0;

    public function mount(): void
    {
        if ($this->servings === 0) {
            $this->servings = max(1, Auth::user()->profile?->household_size ?? 1);
        }
    }

    public function render(BudgetService $budgets, PlanService $plans)
    {
        $user     = Auth::user()->load('profile.location');
        $location = $user->profile?->location;
        $budget   = $budgets->current($user);
        $alloc    = $budget->byCategory();

        $foodMonthly = $alloc['food'] ?? 0;
        // Twelve months over fifty-two weeks, not "divide by four" — the extra
        // four days a year is a whole week of food.
        $weekly = (int) round($foodMonthly * 12 / 52);

        $plan     = $plans->planWeek($location, $weekly, max(1, $this->servings));
        $shopping = $plan['ok'] ? $plans->shoppingList($plan) : [];

        $transport = $plans->compareTransport(
            $location,
            $user->profile?->toEngineArray() ?? [],
            $alloc['transport'] ?? 0,
        );

        return view('livewire.app.plan', [
            'plan'        => $plan,
            'shopping'    => $shopping,
            'shoppingSum' => Money::sum(array_column($shopping, 'amount')),
            'transport'   => $transport,
            'curve'       => $plans->daysOutCurve($location, $user->profile?->toEngineArray() ?? []),
            'foodMonthly' => $foodMonthly,
            'weekly'      => $weekly,
            'location'    => $location,
        ])->layoutData([
            'page'  => 'home',
            'title' => 'Your plan',
            'sub'   => $location?->label ?? 'Set your area in your profile',
            'back'  => route('app.dashboard'),
        ]);
    }
}
