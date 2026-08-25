<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;

/**
 * A test case that runs against the real seeded corpus.
 *
 * MoneyPilot's screens are only meaningful when there is data behind them —
 * a prices page with no reports renders perfectly and proves nothing. So the
 * suite migrates and seeds a MySQL database once per run, then wraps every
 * test in a transaction that is rolled back afterwards.
 *
 * The seed has to happen BEFORE DatabaseTransactions opens its transaction,
 * or the first test's rollback takes the entire corpus with it — and every
 * test after it fails looking for a user that was seeded and then undone.
 * setUpTraits() is the hook that sits in that gap: the application is booted,
 * the transaction is not yet open.
 */
abstract class SeededTestCase extends TestCase
{
    use DatabaseTransactions;

    private static bool $prepared = false;

    protected function setUpTraits(): array
    {
        if (! self::$prepared) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
            self::$prepared = true;
        }

        return parent::setUpTraits();
    }
}
