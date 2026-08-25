<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * How long every screen takes, and how many queries it costs.
 *
 * ScaleTest asserts nothing is catastrophically slow. This says which page is
 * closest to the line, which is the thing you actually want before a demo.
 *
 *   php artisan perf
 */
class Perf extends Command
{
    protected $signature = 'perf {--warm : Prime the caches first}';

    protected $description = 'Time every screen and count its queries';

    public function handle(): int
    {
        $ops   = User::where('email', 'a.okafor@moneypilot.ng')->first();
        $owner = User::where('email', 't.bakare@moneypilot.ng')->first();
        $mem   = User::where('email', 'chidi@student.unilag.ng')->first();

        if (! $mem || ! $ops || ! $owner) {
            $this->error('  Demo accounts missing. Run: php artisan migrate:fresh --seed');

            return self::FAILURE;
        }

        $pages = [
            ['/', null], ['/login', null], ['/register', null], ['/forgot-password', null],
            ['/app', $mem], ['/app/budget', $mem], ['/app/spend', $mem],
            ['/app/prices', $mem], ['/app/prices/rice', $mem], ['/app/plan', $mem],
            ['/app/goals', $mem], ['/app/health', $mem], ['/app/coach', $mem],
            ['/app/profile', $mem],
            ['/console', $ops], ['/console/moderation', $ops], ['/console/coverage', $ops],
            ['/console/contributors', $ops], ['/console/index', $ops], ['/console/members', $ops],
            ['/console/business', $owner],
        ];

        if ($this->option('warm')) {
            foreach ($pages as [$uri, $as]) {
                $this->hit($uri, $as);
            }
        }

        $this->line('');
        $this->line(sprintf('  %-24s %7s %8s %7s', 'page', 'ms', 'queries', 'status'));
        $this->line('  ' . str_repeat('-', 50));

        $worst = 0;
        $rows  = [];

        foreach ($pages as [$uri, $as]) {
            $row     = $this->hit($uri, $as);
            $rows[]  = $row;
            $worst   = max($worst, $row['ms']);

            $flag = $row['ms'] > 1200 || $row['queries'] > 60 ? '!' : ' ';

            $this->line(sprintf(
                '%s %-24s %7.0f %8d %7d',
                $flag, $uri, $row['ms'], $row['queries'], $row['status'],
            ));
        }

        $this->line('');
        $this->line(sprintf(
            '  slowest %.0fms · %d queries at worst · %d pages',
            $worst, max(array_column($rows, 'queries')), count($rows),
        ));
        $this->line('');

        return self::SUCCESS;
    }

    private function hit(string $uri, ?User $as): array
    {
        if ($as) {
            Auth::login($as);
        } else {
            Auth::logout();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = microtime(true);

        $request  = \Illuminate\Http\Request::create($uri, 'GET');
        $response = app()->handle($request);

        $ms      = (microtime(true) - $started) * 1000;
        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return ['uri' => $uri, 'ms' => $ms, 'queries' => $queries, 'status' => $response->getStatusCode()];
    }
}
