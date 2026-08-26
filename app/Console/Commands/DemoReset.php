<?php

namespace App\Console\Commands;

use App\Models\CoachQuery;
use App\Models\PriceReport;
use App\Models\StatementAnalysis;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\NetworkService;
use App\Services\PriceService;
use Database\Seeders\ActivitySeeder;
use Illuminate\Console\Command;

/**
 * Put the four demo accounts back to a clean, presentable state.
 *
 * Their whole value is being predictable: an empty assistant thread ready to be
 * asked a question live, a contributor record still at zero so reputation can
 * be seen being earned, and no leftover statement analyses. Any walk-through —
 * or any probe script run against the development database — quietly spends
 * that, and a full reseed takes over a minute.
 *
 * This takes about a second and touches nothing else.
 *
 *   php artisan demo:reset
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Clear demo-account activity and rebuild their budgets';

    public function handle(BudgetService $budgets, PriceService $prices, NetworkService $network): int
    {
        $users = User::whereIn('email', ActivitySeeder::CURATED)->get();

        if ($users->isEmpty()) {
            $this->error('  No demo accounts found. Run: php artisan migrate:fresh --seed');

            return self::FAILURE;
        }

        $this->line('');

        foreach ($users as $user) {
            $ids = [$user->id];

            $coach      = CoachQuery::whereIn('user_id', $ids)->delete();
            $statements = StatementAnalysis::whereIn('user_id', $ids)->delete();

            // Only entries logged IN the app. The seeded history is what makes
            // the pace figures mean anything, so it stays.
            $manual = Transaction::whereIn('user_id', $ids)->where('source', 'manual')->delete();

            $reports = PriceReport::whereIn('user_id', $ids)->pluck('item_id')->unique();
            PriceReport::whereIn('user_id', $ids)->delete();

            // A deleted report has to leave the medians it was counted in.
            foreach ($reports as $itemId) {
                if ($item = \App\Models\Item::find($itemId)) {
                    $prices->refreshItem($item);
                }
            }

            $cleared = $coach + $statements + $manual + $reports->count();

            $this->line(sprintf(
                '  %-28s %s',
                $user->email,
                $cleared
                    ? sprintf('cleared %d (%d asked, %d logged, %d reported, %d statements)',
                        $cleared, $coach, $manual, $reports->count(), $statements)
                    : 'already clean',
            ));

            if ($user->isMember()) {
                $budgets->rebuild($user->fresh());
            }
        }

        $network->forgetCaches();

        $this->line('');
        $this->info('  Demo accounts reset. Budgets rebuilt, console caches cleared.');
        $this->line('');

        return self::SUCCESS;
    }
}
