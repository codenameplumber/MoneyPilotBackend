<?php

namespace App\Livewire\App;

use App\Domain\Money;
use App\Models\Item;
use App\Models\Market;
use App\Models\PriceReport;
use App\Services\PriceService;
use App\Services\TradeoffService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * What things actually cost near you.
 *
 * This screen is both halves of the network at once: it reads the aggregate,
 * and it is where a member contributes the next report. Keeping the two on one
 * screen is deliberate — a contribution flow hidden behind a menu is a
 * contribution flow nobody uses, and without contributions there is no
 * TruePrice to read.
 *
 * Every price carries its provenance. A median of three stale reports from the
 * next state is not the same claim as a median of forty from the market down
 * the road, and this screen never lets those two look alike.
 */
#[Layout('layouts.app')]
class Prices extends Component
{
    public string $q = '';

    public string $group = '';

    /* --- contributing a price --- */
    public ?int $reporting = null;

    #[Validate('required|string|max:24')]
    public string $price = '';

    #[Validate('nullable|integer')]
    public ?int $marketId = null;

    public string $flash = '';

    public function startReport(int $itemId): void
    {
        $this->reporting = $this->reporting === $itemId ? null : $itemId;
        $this->reset('price', 'marketId');
        $this->resetErrorBag();
    }

    /**
     * Submit a price.
     *
     * The report goes live immediately rather than waiting in a queue.
     * Moderation here is statistical first and human second: the aggregator
     * has already excluded an outlier from the median before any moderator
     * sees it, so a typo cannot move anyone's budget while it waits — and a
     * contributor is not made to feel their report vanished.
     */
    public function submitReport(PriceService $prices): void
    {
        $this->validate();

        $item = Item::findOrFail($this->reporting);
        $user = Auth::user()->load('profile');
        $kobo = Money::toKobo($this->price);

        if ($kobo <= 0) {
            $this->addError('price', 'Enter the price you paid, for example 1,450.');
            return;
        }

        if (! $user->profile?->location_id) {
            $this->addError('price', 'Set your area in your profile first — a price without a place cannot be used.');
            return;
        }

        PriceReport::create([
            'user_id'     => $user->id,
            'item_id'     => $item->id,
            'location_id' => $user->profile->location_id,
            'market_id'   => $this->marketId,
            'price'       => $kobo,
            'reported_at' => now(),
            'status'      => 'live',
            // A new contributor counts for less until they have a record.
            'reputation'  => $user->priceReports()->count() >= 5 ? 1.0 : 0.7,
        ]);

        // The median is recomputed now, not on the next scheduled run, so the
        // sentence below is true by the time it is read.
        $prices->refreshItem($item);

        $agg = $prices->cached($item->slug, $user->profile->location);

        $this->flash = sprintf(
            'Thank you — %s at %s is now part of the median for your area, which stands at %s across %d reports.',
            $item->label, Money::fmt($kobo), Money::fmt($agg['price']), $agg['nUsed'],
        );
        $this->reporting = null;
        $this->reset('price', 'marketId');
    }

    public function render(PriceService $prices, TradeoffService $tradeoffs)
    {
        $user     = Auth::user()->load('profile.location');
        $location = $user->profile?->location;
        $plain    = $this->q === '' && $this->group === '';

        $items = Item::query()
            ->when($this->q !== '', fn ($q) => $q->where('label', 'like', '%' . $this->q . '%'))
            ->when($this->group !== '', fn ($q) => $q->where('item_group', $this->group))
            ->orderByDesc('commonness')
            ->orderBy('label')
            ->get();

        // One cached board for the whole location, then filtered — computing
        // a trend and a chart series per item on every keystroke is what made
        // the search feel broken.
        $board = $prices->board($location);

        $rows = $items->map(fn (Item $item) => [
            'item'   => $item,
            'agg'    => $board[$item->slug]['agg']    ?? $prices->cached($item->slug, $location),
            'trend'  => $board[$item->slug]['trend']  ?? $prices->trend($item, $location),
            'series' => $board[$item->slug]['series'] ?? [],
        ])->all();

        return view('livewire.app.prices', [
            'rows'     => $rows,
            'location' => $location,
            'markets'  => $location
                ? Market::where('location_id', $location->id)->orderBy('label')->get()
                : collect(),
            'movers'   => $plain ? $tradeoffs->movers($location, 3) : [],
            'groups'   => Item::query()->distinct()->orderBy('item_group')->pluck('item_group')->filter()->values(),
            'mine'     => $user->priceReports()->count(),
            'total'    => PriceReport::where('status', 'live')->count(),
        ])->layoutData([
            'page'  => 'prices',
            'title' => 'Local prices',
            'sub'   => $location?->label ?? 'Set your area in your profile',
        ]);
    }
}
