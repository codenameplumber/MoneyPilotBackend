<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The order matters and is not incidental.
 *
 * Items, markets and locations must exist before a price can reference one;
 * the price corpus must exist before a member's budget can be costed from it.
 * Everything downstream is derived, which is the point — no figure in this
 * demo is typed in by hand.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogSeeder::class,
            PriceCorpusSeeder::class,
            DemoUsersSeeder::class,
        ]);

        /*
         * Materialise the medians.
         *
         * Without this every screen silently falls back to computing medians
         * live, which is correct but slow — and the coverage grid, which reads
         * only the materialised table, reports the network as entirely dark.
         * A seeded database has to be a working one.
         */
        $this->command?->info('  materialising TruePrice medians…');
        $written = app(\App\Services\PriceService::class)->refreshAggregates();
        $this->command?->info("  {$written} aggregates written.");

        // Members next, because every budget is priced FROM those aggregates.
        $this->call(MemberCohortSeeder::class);

        // Then what those members did, which is what the console measures.
        $this->call(ActivitySeeder::class);
    }
}
