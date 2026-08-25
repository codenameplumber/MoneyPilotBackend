<?php

namespace App\Livewire\Console;

use App\Services\NetworkService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The coverage grid: every item against every LGA.
 *
 * This is the map of where the product actually works. A dark cell is not a
 * cosmetic gap — it is a household somewhere being handed a national average
 * for the thing they buy every week, and being told so. Recruiting one
 * contributor in the weakest LGA is worth more than a hundred in the strongest,
 * and this screen exists to make that obvious at a glance.
 */
#[Layout('layouts.console')]
class Coverage extends Component
{
    public function render(NetworkService $network)
    {
        $health   = $network->health();
        $coverage = $network->coverage();

        // Which items are thin EVERYWHERE — a different problem from an LGA
        // being thin, and one that no amount of local recruiting fixes.
        $byItem = [];
        foreach ($coverage as $row) {
            foreach ($row['cells'] as $cell) {
                $byItem[$cell['item_id']] ??= ['label' => $cell['item_label'], 'local' => 0, 'total' => 0];
                $byItem[$cell['item_id']]['total']++;
                if ($cell['level'] >= 2) {
                    $byItem[$cell['item_id']]['local']++;
                }
            }
        }

        $thinItems = array_values(array_filter($byItem, fn ($i) => $i['local'] < $i['total']));
        usort($thinItems, fn ($a, $b) => $a['local'] <=> $b['local']);

        return view('livewire.console.coverage', [
            'health'    => $health,
            'coverage'  => $coverage,
            'thinItems' => array_slice($thinItems, 0, 8),
            'items'     => $coverage[0]['cells'] ?? [],
        ])->layoutData([
            'page'         => 'coverage',
            'title'        => 'Coverage',
            'pendingCount' => $health['flagged'],
        ]);
    }
}
