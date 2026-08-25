<?php

namespace App\Livewire\Console;

use App\Services\NetworkService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every tracked item, nationally and by area.
 *
 * The column that matters is SPREAD. A 38% gap between the cheapest and dearest
 * LGA for the same bag of rice is not noise — it is the entire argument for
 * pricing a budget locally instead of from a national figure, stated as a
 * number. If that spread ever collapsed, this product would have no reason to
 * exist, so it is measured on its own screen rather than assumed.
 */
#[Layout('layouts.console')]
class PriceIndex extends Component
{
    #[Url]
    public string $group = 'all';

    #[Url]
    public string $sort = 'spread';

    public function render(NetworkService $network)
    {
        $rows = $network->priceIndex();

        if ($this->group !== 'all') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['item']->item_group === $this->group));
        }

        usort($rows, match ($this->sort) {
            'move'    => fn ($a, $b) => abs($b['trend']['change'] ?? 0) <=> abs($a['trend']['change'] ?? 0),
            'reports' => fn ($a, $b) => $b['reports'] <=> $a['reports'],
            'price'   => fn ($a, $b) => ($b['national']->price ?? 0) <=> ($a['national']->price ?? 0),
            default   => fn ($a, $b) => $b['spreadPct'] <=> $a['spreadPct'],
        });

        $groups = collect($network->priceIndex())->pluck('item.item_group')->unique()->filter()->values();

        return view('livewire.console.index', [
            'rows'   => $rows,
            'groups' => $groups,
            'health' => $network->health(),
        ])->layoutData([
            'page'         => 'index',
            'title'        => 'Price index',
            'pendingCount' => $network->health()['flagged'],
        ]);
    }
}
