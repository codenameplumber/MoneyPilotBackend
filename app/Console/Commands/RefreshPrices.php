<?php

namespace App\Console\Commands;

use App\Services\NetworkService;
use App\Services\PriceService;
use Illuminate\Console\Command;

/**
 * Rebuild every materialised median.
 *
 * A reputation-weighted, exponentially-decayed median over the whole corpus is
 * the right answer and the wrong thing to compute per request — the coverage
 * grid alone asks for 162 of them. This writes them down once, and every screen
 * reads a single indexed row.
 *
 * Reporting a price refreshes that one item inline, so a contributor sees their
 * own report land immediately. This command is for everything else: the decay
 * curve moves every day even when nobody reports anything, and a median nobody
 * recomputes slowly becomes a lie.
 */
class RefreshPrices extends Command
{
    protected $signature = 'prices:refresh';

    protected $description = 'Recompute every TruePrice median and clear the console caches';

    public function handle(PriceService $prices, NetworkService $network): int
    {
        $started = microtime(true);

        $written = $prices->refreshAggregates();
        $network->forgetCaches();

        $this->info(sprintf(
            '  %d aggregates written in %.1fs.',
            $written, microtime(true) - $started,
        ));

        return self::SUCCESS;
    }
}
