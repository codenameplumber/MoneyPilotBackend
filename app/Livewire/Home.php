<?php

namespace App\Livewire;

use App\Domain\Basket;
use App\Models\Location;
use App\Services\NetworkService;
use App\Services\PriceService;
use App\Services\TradeoffService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The public homepage.
 *
 * Every figure on it is live. The price board is the real aggregator, the stat
 * band is the real corpus, and the city comparison recomputes the survival
 * floor when a visitor changes either dropdown.
 *
 * That is the argument, not a claim about the argument: "the same household
 * needs ₦31,000 more a month in Ikeja than in Nsukka" is only persuasive if
 * the visitor can change the cities and watch it hold.
 */
#[Layout('layouts.site')]
class Home extends Component
{
    /** ₦260,000, the household in the comparison. */
    private const INCOME = 26_000_000;

    private const HOUSEHOLD = [
        'household_size' => 4, 'dependents' => 2,
        'trips_per_day'  => 2, 'days_out'   => 24,
    ];

    public ?int $sideA = null;
    public ?int $sideB = null;

    public function mount(): void
    {
        $this->sideA ??= Location::where('slug', 'ikeja')->value('id') ?? Location::value('id');
        $this->sideB ??= Location::where('slug', 'nsukka')->value('id')
            ?? Location::where('id', '!=', $this->sideA)->value('id');
    }

    private function floorFor(?int $locationId, PriceService $prices): ?array
    {
        $location = $locationId ? Location::find($locationId) : null;
        if (! $location) {
            return null;
        }

        $floor = Basket::cost($prices->priceResolver($location), self::HOUSEHOLD);
        $floor['location'] = $location;
        $floor['left']     = self::INCOME - $floor['total'];

        return $floor;
    }

    public function render(PriceService $prices, NetworkService $network, TradeoffService $tradeoffs)
    {
        $home   = Location::where('slug', 'lagos-mainland')->first() ?? Location::first();
        $health = $network->health();
        $board  = $prices->board($home);

        /*
         * The board leads with whatever actually moved, then tops up from the
         * staples so it is always five rows deep.
         *
         * Movers alone is the honest ordering but not a reliable length — the
         * evidence threshold is strict on purpose, and on a quiet month it
         * returns two items, which reads as a broken widget rather than as a
         * calm market.
         */
        $slugs = array_map(fn ($m) => $m['item']->slug, $tradeoffs->movers($home, 5));

        foreach (['rice', 'tomato', 'beans', 'gas', 'busfare'] as $staple) {
            if (count($slugs) >= 5) {
                break;
            }
            if (! in_array($staple, $slugs, true)) {
                $slugs[] = $staple;
            }
        }

        $rows = [];
        foreach (array_slice($slugs, 0, 5) as $slug) {
            $item = \App\Models\Item::where('slug', $slug)->first();
            if (! $item) {
                continue;
            }
            $rows[] = [
                'item'   => $item,
                'agg'    => $board[$slug]['agg']    ?? $prices->cached($slug, $home),
                'trend'  => $board[$slug]['trend']  ?? ['ok' => false, 'change' => 0],
                'series' => $board[$slug]['series'] ?? [],
            ];
        }

        $a = $this->floorFor($this->sideA, $prices);
        $b = $this->floorFor($this->sideB, $prices);

        return view('livewire.home', [
            'locations' => Location::orderBy('label')->get(),
            'home'      => $home,
            'health'    => $health,
            'rows'      => $rows,
            'riceAgg'   => $board['rice']['agg'] ?? $prices->cached('rice', $home),
            'a'         => $a,
            'b'         => $b,
            'income'    => self::INCOME,
            // The captions on the photographs are live medians, not sample
            // copy — a picture of a market beside the actual price of tomatoes
            // in that market is the whole product in one glance.
            'tomato'    => $board['tomato']['agg'] ?? $prices->cached('tomato', $home),
            'bus'       => $board['busfare']['agg'] ?? $prices->cached('busfare', $home),
            'weekFood'  => (int) round(
                (Basket::cost($prices->priceResolver($home), [
                    'household_size' => 1, 'dependents' => 0,
                    'trips_per_day'  => 2, 'days_out'   => 22,
                ])['food']['total'] * 12) / 52
            ),
        ]);
    }
}
