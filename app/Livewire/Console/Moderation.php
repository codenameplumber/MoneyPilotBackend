<?php

namespace App\Livewire\Console;

use App\Models\ContributorStat;
use App\Models\PriceReport;
use App\Livewire\Concerns\WithDataTable;
use App\Services\NetworkService;
use App\Services\PriceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The moderation queue.
 *
 * Two things are true at once and the screen has to hold both:
 *
 *   · A report that looks wrong is sometimes a market that moved. Deleting it
 *     destroys the only record of which it was, so nothing here deletes —
 *     a report is HELD or it is CONFIRMED, and both decisions are reversible.
 *
 *   · A moderator is not the first line of defence. The aggregator has already
 *     excluded these from the median, so the queue is never urgent and can be
 *     worked at a human pace. Holding a report only stops it counting toward
 *     the report totals a contributor is judged on.
 *
 * A 10× miss is almost always a typed zero. A 2× miss might be a real price.
 * The verdict column says which the maths thinks it is, and the moderator
 * disagrees whenever they know better.
 */
#[Layout('layouts.console')]
class Moderation extends Component
{
    use WithDataTable;

    #[Url]
    public string $filter = 'all';

    #[Url(as: 'q')]
    public string $filterQ = '';

    public string $flash = '';

    /** Hold a report out of every median until somebody says otherwise. */
    public function hold(int $id, PriceService $prices, NetworkService $network): void
    {
        $report = PriceReport::findOrFail($id);
        $report->update(['status' => 'held']);

        $this->after($report, $prices, $network);

        $this->flash = sprintf('%s at %s in %s is held. It counts toward no median until released.',
            $report->item->label, \App\Domain\Money::fmt($report->price), $report->location->label);
    }

    /**
     * Confirm a report as real.
     *
     * This is the decision that matters most, and it is the one a queue built
     * only to delete outliers cannot make. A genuine price shock looks exactly
     * like a typo to the statistics — the difference is a human who knows the
     * market burned down last week.
     */
    public function confirm(int $id, PriceService $prices, NetworkService $network): void
    {
        $report = PriceReport::findOrFail($id);
        $report->update(['status' => 'live']);

        // Confirming an unusual price is evidence the contributor was right.
        if ($report->reporter_key) {
            ContributorStat::where('reporter_key', $report->reporter_key)
                ->increment('reputation', 0.02);
        }

        $this->after($report, $prices, $network);

        $this->flash = sprintf('%s at %s confirmed as a real price and returned to the median.',
            $report->item->label, \App\Domain\Money::fmt($report->price));
    }

    private function after(PriceReport $report, PriceService $prices, NetworkService $network): void
    {
        // The decision has to reach the medians people budget against now, not
        // at the next scheduled run.
        $prices->refreshItem($report->item);
        $network->forgetCaches();
    }

    public function render(NetworkService $network)
    {
        $flagged = $network->flagged();

        $rows = match ($this->filter) {
            'typo'       => array_filter($flagged, fn ($f) => $f['verdict'] === 'likely-typo'),
            'suspect'    => array_filter($flagged, fn ($f) => $f['verdict'] === 'suspect'),
            'borderline' => array_filter($flagged, fn ($f) => $f['verdict'] === 'borderline'),
            'held'       => array_filter($flagged, fn ($f) => $f['report']->status === 'held'),
            default      => $flagged,
        };

        // Search across item and place together, because a moderator working
        // this queue thinks in either — "rice" or "Mile 12", not both.
        if ($this->filterQ !== '') {
            $needle = mb_strtolower($this->filterQ);

            $rows = array_filter($rows, function ($f) use ($needle) {
                $haystack = mb_strtolower(implode(' ', [
                    $f['report']->item->label ?? '',
                    $f['report']->market?->label ?? '',
                    $f['report']->location?->label ?? '',
                ]));

                return str_contains($haystack, $needle);
            });
        }

        $counts = [
            'all'        => count($flagged),
            'typo'       => count(array_filter($flagged, fn ($f) => $f['verdict'] === 'likely-typo')),
            'suspect'    => count(array_filter($flagged, fn ($f) => $f['verdict'] === 'suspect')),
            'borderline' => count(array_filter($flagged, fn ($f) => $f['verdict'] === 'borderline')),
            'held'       => PriceReport::where('status', 'held')->count(),
        ];

        $table = $this->paginate(array_values($rows), fn (array $r, string $c) => match ($c) {
            'item'    => $r['report']->item->label,
            'where'   => $r['report']->market?->label ?? $r['report']->location?->label ?? '',
            'price'   => $r['report']->price,
            'median'  => $r['median'],
            // How wrong it is, not which direction — a 10x under is as
            // interesting as a 10x over.
            'off'     => abs($r['ratio'] - 1),
            'peers'   => $r['peers'],
            'age'     => $r['ageDays'],
            'verdict' => $r['verdict'],
            'status'  => $r['report']->status,
            default   => 0,
        });

        return view('livewire.console.moderation', [
            'rows'   => $table['rows'],
            'meta'   => $table,
            'counts' => $counts,
            'total'  => $table['total'],
            'health' => $network->health(),
            'me'     => Auth::user(),
        ])->layoutData([
            'page'         => 'moderation',
            'title'        => 'Moderation queue',
            'pendingCount' => $counts['all'],
        ]);
    }
}
