<?php

namespace App\Livewire\Console;

use App\Models\PriceReport;
use App\Services\NetworkService;
use App\Services\TradeoffService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Is the network healthy enough for its answers to be trusted?
 *
 * That is a different question from "what does rice cost", and the console
 * exists to answer it. The screen leads with coverage — the share of
 * (item × LGA) pairs we can answer LOCALLY — because everything else in the
 * product is downstream of it. A budget priced from a national average is not
 * the product; it is the thing the product is meant to replace.
 */
#[Layout('layouts.console')]
class Overview extends Component
{
    public function refresh(): void
    {
        \Illuminate\Support\Facades\Artisan::call('prices:refresh');
        $this->dispatch('refreshed');
    }

    public function render(NetworkService $network, TradeoffService $tradeoffs)
    {
        $health   = $network->health();
        $coverage = $network->coverage();

        // The weakest areas, because those are the ones that need a decision.
        $weakest = array_slice(array_reverse($coverage), 0, 3);

        return view('livewire.console.overview', [
            'health'   => $health,
            'coverage' => $coverage,
            'weakest'  => $weakest,
            'intake'   => $network->intake(14),
            'movers'   => $tradeoffs->movers(null, 5),
            'recent'   => PriceReport::with(['item', 'location', 'market'])
                ->latest('reported_at')->limit(8)->get(),
        ])->layoutData([
            'page'         => 'overview',
            'title'        => 'Network overview',
            'pendingCount' => $health['flagged'],
        ]);
    }
}
