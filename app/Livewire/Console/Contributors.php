<?php

namespace App\Livewire\Console;

use App\Livewire\Concerns\WithDataTable;
use App\Services\NetworkService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Who the network is made of, and how much each one is trusted.
 *
 * Reputation is EARNED, not assigned: a reporter whose submissions sit close to
 * the eventual consensus drifts up, a persistent outlier drifts down. It is
 * shown here in full because the aggregator uses it as a weight, and a
 * weighting nobody can inspect is a black box sitting underneath somebody's
 * food budget.
 *
 * Handles are pseudonymous by design. The console has no operational reason to
 * hold a real identity to do its job, so it does not.
 */
#[Layout('layouts.console')]
class Contributors extends Component
{
    use WithDataTable;

    #[Url]
    public string $tier = 'all';

    #[Url]
    public string $q = '';


    public function render(NetworkService $network)
    {
        $all = $network->contributors();

        $rows = array_values(array_filter($all, function ($c) {
            if ($this->tier !== 'all' && $c['tier'] !== $this->tier) {
                return false;
            }
            return $this->q === '' || str_contains(mb_strtolower($c['handle']), mb_strtolower($this->q));
        }));

        $counts = ['all' => count($all)];
        foreach (['trusted', 'good', 'watch', 'restricted'] as $t) {
            $counts[$t] = count(array_filter($all, fn ($c) => $c['tier'] === $t));
        }

        $table = $this->paginate($rows, fn (array $r, string $c) => match ($c) {
            'handle' => $r['handle'],
            'tier'   => $r['tier'],
            'newest' => $r['newestDays'] ?? 9999,
            default  => $r[$c] ?? 0,
        });

        return view('livewire.console.contributors', [
            'rows'    => $table['rows'],
            'meta'    => $table,
            'total'   => $table['total'],
            'counts'  => $counts,
            'health'  => $network->health(),
            'members' => count(array_filter($all, fn ($c) => $c['member'])),
        ])->layoutData([
            'page'         => 'contributors',
            'title'        => 'Contributors',
            'pendingCount' => $network->health()['flagged'],
        ]);
    }
}
