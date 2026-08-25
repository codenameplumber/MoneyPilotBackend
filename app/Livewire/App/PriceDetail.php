<?php

namespace App\Livewire\App;

use App\Domain\Money;
use App\Models\Item;
use App\Models\Market;
use App\Models\PriceReport;
use App\Services\PriceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One item, shown all the way down to the reports it was computed from.
 *
 * This is the screen that has to exist for the rest of the product to be
 * believable. Anyone can print a number; the claim here is that the number can
 * be taken apart — how many reports, from which markets, how old, how far
 * apart, and which ones were held out of the median and why.
 */
#[Layout('layouts.app')]
class PriceDetail extends Component
{
    public Item $item;

    public function mount(Item $item): void
    {
        $this->item = $item;
    }

    public function render(PriceService $prices)
    {
        $user     = Auth::user()->load('profile.location');
        $location = $user->profile?->location;

        $agg    = $prices->aggregate($this->item, $location);
        $trend  = $prices->trend($this->item, $location);
        $series = $prices->series($this->item, $location, days: 84, buckets: 14);

        // The individual reports, so the median can be checked by hand.
        $reports = PriceReport::with(['market', 'location'])
            ->where('item_id', $this->item->id)
            ->where('reported_at', '>=', now()->subDays(30))
            ->when($location, fn ($q) => $q->where('location_id', $location->id))
            ->orderByDesc('reported_at')
            ->limit(40)
            ->get();

        // Which markets, cheapest first — the actionable half of the screen.
        $byMarket = collect($agg['byMarket'])
            ->filter(fn ($m) => $m['market_id'] && $m['location_id'] === $location?->id)
            ->map(fn ($m) => $m + ['label' => Market::find($m['market_id'])?->label ?? 'Unnamed market'])
            ->sortBy('price')
            ->values();

        return view('livewire.app.price', [
            'agg'      => $agg,
            'trend'    => $trend,
            'series'   => $series,
            'reports'  => $reports,
            'byMarket' => $byMarket,
            'location' => $location,
            'held'     => PriceReport::where('item_id', $this->item->id)
                ->where('status', 'held')
                ->where('reported_at', '>=', now()->subDays(30))
                ->count(),
        ])->layoutData([
            'page'  => 'prices',
            'title' => $this->item->label,
            'sub'   => $this->item->unit_label . ' · ' . ($location?->label ?? 'national'),
            'back'  => route('app.prices'),
        ]);
    }
}
