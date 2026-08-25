<?php

namespace Database\Seeders;

use App\Domain\Categories;
use App\Domain\Prng;
use App\Models\Goal;
use App\Models\IncomeSource;
use App\Models\Item;
use App\Models\Location;
use App\Models\Obligation;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Four demo accounts with real, checkable passwords.
 *
 * A judge who has to fill in a form before seeing anything has already formed
 * an opinion about the product, and it is not the one you want. These sign in
 * for real — the password is hashed and verified like any other.
 *
 * Chidi balances but is tight. Amaka does not balance at all, which is the
 * more persuasive demo: anyone can render a tidy pie chart, and handling the
 * hard case honestly is the whole co-pilot claim.
 */
class DemoUsersSeeder extends Seeder
{
    public const PASSWORD = 'moneypilot';

    public function run(): void
    {
        $this->member(
            persona: 'chidi',
            name: 'Chidi Okonkwo',
            email: 'chidi@student.unilag.ng',
            title: 'Student · Lagos Mainland',
            location: 'lagos-mainland',
            profile: ['household_size' => 1, 'dependents' => 0, 'trips_per_day' => 2, 'days_out' => 22],
            sources: [
                ['Monthly allowance', 9_000_000, 'certain'],
                ['Design side jobs',  4_000_000, 'variable'],
            ],
            obligations: [
                ['Hostel rent (spread)', 1_800_000, 'housing', 1],
                ['Data bundle',            700_000, 'debt',    3],
            ],
            goals: [
                ['Laptop fund', 18_000_000, 4_200_000, 10],
            ],
            monthlySpend: 5_600_000,
            pace: 1.12,
        );

        $this->member(
            persona: 'amaka',
            name: 'Amaka Eze',
            email: 'amaka.eze@gmail.com',
            title: 'Teacher · Ikeja · 3 dependents',
            location: 'ikeja',
            profile: ['household_size' => 4, 'dependents' => 3, 'trips_per_day' => 2, 'days_out' => 24],
            sources: [
                ['Teaching salary',     20_000_000, 'certain'],
                ['Partner contributes',  6_000_000, 'likely'],
            ],
            obligations: [
                ['Rent (spread monthly)',   5_500_000, 'housing', 1],
                ["Children's school fees",  3_200_000, 'fees',    5],
                ['Cooperative loan',        1_800_000, 'debt',   28],
            ],
            goals: [
                ['Emergency fund', 30_000_000, 2_000_000, 6],
            ],
            monthlySpend: 15_500_000,
            pace: 1.0,
        );

        $this->staff('ops',   'Adaeze Okafor', 'a.okafor@moneypilot.ng', 'Network operations');
        $this->staff('owner', 'Tunde Bakare',  't.bakare@moneypilot.ng', 'Founder');

        $this->command?->info('  demo accounts: chidi, amaka (members) · ops · owner — password "' . self::PASSWORD . '"');
    }

    private function staff(string $role, string $name, string $email, string $title): void
    {
        User::updateOrCreate(['email' => $email], [
            'name'              => $name,
            'password'          => self::PASSWORD,
            'role'              => $role,
            'title'             => $title,
            'email_verified_at' => now(),
        ]);
    }

    private function member(
        string $persona, string $name, string $email, string $title, string $location,
        array $profile, array $sources, array $obligations, array $goals,
        int $monthlySpend, float $pace,
    ): void {
        $user = User::updateOrCreate(['email' => $email], [
            'name'              => $name,
            'password'          => self::PASSWORD,
            'role'              => 'member',
            'title'             => $title,
            'persona'           => $persona,
            'email_verified_at' => now(),
        ]);

        Profile::updateOrCreate(['user_id' => $user->id], $profile + [
            'location_id' => Location::where('slug', $location)->value('id'),
            'weights'     => Categories::defaultWeights(),
        ]);

        $user->incomeSources()->delete();
        foreach ($sources as [$label, $amount, $reliability]) {
            IncomeSource::create(compact('label') + [
                'user_id' => $user->id, 'amount' => $amount, 'reliability' => $reliability,
            ]);
        }

        $user->obligations()->delete();
        foreach ($obligations as [$label, $amount, $category, $dueDay]) {
            Obligation::create([
                'user_id' => $user->id, 'label' => $label, 'amount' => $amount,
                'category' => $category, 'due_day' => $dueDay,
            ]);
        }

        $user->goals()->delete();
        foreach ($goals as [$label, $target, $saved, $months]) {
            Goal::create([
                'user_id' => $user->id, 'label' => $label, 'target' => $target,
                'saved' => $saved, 'months_left' => $months, 'original_months' => $months,
            ]);
        }

        $this->spendingFor($user, $persona, $monthlySpend, $pace);
    }

    /**
     * Six weeks of plausible spending.
     *
     * Normalised to a target pace at the end: generating from frequencies
     * alone gives no control over the number that actually matters — how much
     * of the month's money is gone by today. Left unnormalised, Chidi opened
     * the dashboard 99% spent on day 14 and every category screamed.
     */
    private function spendingFor(User $user, string $persona, int $monthlySpend, float $pace): void
    {
        $user->transactions()->delete();

        $r = Prng::seeded('tx', $persona, 'v1');
        $itemIds = Item::pluck('id', 'slug');

        $patterns = [
            ['food',      'rice',    'Rice, 5kg',           950_00, 0.30],
            ['food',      'tomato',  'Tomatoes & pepper',   320_00, 0.55],
            ['food',      null,      'Cooked food',         180_00, 0.85],
            ['food',      'egg',     'Eggs, half crate',    280_00, 0.22],
            ['transport', 'busfare', 'Bus fare',             90_00, 0.95],
            ['transport', 'okada',   'Okada',               140_00, 0.30],
            ['data',      null,      'Airtime top-up',      200_00, 0.18],
            ['personal',  null,      'Barber / grooming',   250_00, 0.10],
            ['social',    null,      'Outing with friends', 450_00, 0.12],
            ['utilities', 'gas',     'Gas refill',          620_00, 0.06],
            ['health',    null,      'Pharmacy',            380_00, 0.05],
        ];

        $density = $persona === 'amaka' ? 1.6 : 1.0;
        $rows    = [];
        $now     = now();

        for ($d = 42; $d >= 0; $d--) {
            foreach ($patterns as [$category, $slug, $note, $base, $freq]) {
                if ($r->next() > $freq * $density * 0.42) continue;

                $amount = (int) round(($base * (1 + $r->jitter(0.28))) / 100) * 100;
                if ($amount <= 0) continue;

                $rows[] = [
                    'user_id'     => $user->id,
                    'occurred_at' => $now->copy()->subDays($d)->setTime((int) floor($r->next() * 12 + 8), 0),
                    'amount'      => $amount,
                    'category'    => $category,
                    'item_id'     => $slug ? ($itemIds[$slug] ?? null) : null,
                    'note'        => $note,
                    'source'      => 'seed',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        // Scale this month to the pace the persona is meant to demonstrate,
        // preserving the shape (which category runs hottest, which days spiked).
        $monthStart = $now->copy()->startOfMonth();
        $elapsed    = max(1, (int) $now->format('j'));
        $monthDays  = (int) $now->format('t');

        $actual = 0;
        foreach ($rows as $row) {
            if ($row['occurred_at'] >= $monthStart) $actual += $row['amount'];
        }

        $target = $monthlySpend * ($elapsed / $monthDays) * $pace;
        if ($actual > 0 && $monthlySpend > 0) {
            $factor = $target / $actual;
            foreach ($rows as &$row) {
                $row['amount'] = max(100, (int) round($row['amount'] * $factor / 100) * 100);
            }
            unset($row);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
    }
}
