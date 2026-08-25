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
use App\Services\BudgetService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A member base for the console to talk about.
 *
 * These are REAL rows, not fixtures. Each one gets a profile, income sources,
 * obligations and goals, and then the real allocation engine builds their real
 * budget from the real price corpus. Whether a member's month balances is
 * therefore an output, not a field. About half come out infeasible, and that
 * is not a tuning failure: a household of four in Ikeja needs roughly ₦200,000
 * a month for food, transport and utilities alone at current medians, which a
 * great many real incomes in this range do not cover. The number the owner
 * dashboard shows is the one the engine produced.
 *
 * The alternative was a `members()` function returning invented rows with an
 * invented `feasible` flag, which is precisely the kind of figure this product
 * exists to argue against. A console reporting a made-up number about honesty
 * would be an unusually poor joke.
 *
 * Everything is deterministic: same seed, same cohort, every run.
 *
 * 120 members is enough that the console stops reading like a demo — coverage,
 * shortfall rate and contributor spread all become distributions rather than
 * anecdotes — while still seeding in about a minute.
 */
class MemberCohortSeeder extends Seeder
{
    private const COUNT = 120;

    private const FIRST = [
        'Ada', 'Ife', 'Musa', 'Ngozi', 'Zainab', 'Emeka', 'Bisi', 'Yusuf',
        'Amara', 'Segun', 'Halima', 'Obi', 'Funke', 'Kelechi', 'Aisha', 'Femi',
        'Uche', 'Sadiq', 'Chioma', 'Bola', 'Idris', 'Nneka', 'Kunle', 'Rahma',
        'Ebere', 'Dare', 'Larai', 'Ikenna', 'Tola', 'Hauwa', 'Chuka', 'Simi',
        'Gbenga', 'Amina', 'Nkem', 'Deji', 'Fatima', 'Ozioma', 'Seyi', 'Maryam',
    ];

    private const LAST = [
        'Okafor', 'Adeyemi', 'Bello', 'Nwosu', 'Ibrahim', 'Balogun', 'Eze',
        'Yakubu', 'Chukwu', 'Lawal', 'Obi', 'Danjuma', 'Adebayo', 'Musa',
    ];

    private const JOBS = [
        'Teacher', 'Trader', 'Nurse', 'Driver', 'Student', 'Tailor', 'Civil servant',
        'Shop assistant', 'Mechanic', 'Hairdresser', 'Security officer', 'Caterer',
    ];

    private const WANTS = [
        ['Emergency fund',     30_000_00,  120_000_00],
        ['School fees fund',   40_000_00,  200_000_00],
        ['A phone',            60_000_00,  180_000_00],
        ['Rent for next year', 150_000_00, 600_000_00],
        ['A sewing machine',   45_000_00,  140_000_00],
        ['Generator',          80_000_00,  250_000_00],
    ];

    public function run(): void
    {
        $r         = Prng::seeded('cohort', 'v1');
        $locations = Location::all();
        $items     = Item::pluck('id', 'slug');
        $budgets   = app(BudgetService::class);

        $made = $feasible = 0;

        for ($i = 0; $i < self::COUNT; $i++) {
            $first = self::FIRST[$i % count(self::FIRST)];
            $last  = $r->pick(self::LAST);
            $name  = $first . ' ' . $last;
            $email = mb_strtolower($first . '.' . $last . ($i + 1)) . '@example.ng';

            $user = User::updateOrCreate(['email' => $email], [
                'name'              => $name,
                'password'          => 'moneypilot',
                'role'              => 'member',
                'title'             => $r->pick(self::JOBS),
                'email_verified_at' => now(),
                'created_at'        => now()->subDays((int) floor($r->next() * 240)),
            ]);

            $household  = 1 + (int) floor($r->next() * 5);
            $dependents = $household > 1 ? (int) floor($r->next() * ($household - 1)) : 0;

            Profile::updateOrCreate(['user_id' => $user->id], [
                'location_id'    => $locations[(int) floor($r->next() * $locations->count())]->id,
                'household_size' => $household,
                'dependents'     => $dependents,
                'trips_per_day'  => $r->next() > 0.25 ? 2 : 4,
                'days_out'       => 18 + (int) floor($r->next() * 8),
                'weights'        => Categories::defaultWeights(),
            ]);

            /* Income: a main source, and for many people a second, less
               reliable one. Nigerian household income is rarely one number. */
            $user->incomeSources()->delete();

            /*
             * The income spread is the one real judgement call in this seeder.
             *
             * It is deliberately NOT tuned to produce a comfortable-looking
             * infeasibility rate. This is a plausible spread for the personas
             * above — teachers, traders, drivers, students — and whatever
             * proportion of them cannot cover a measured survival floor is the
             * finding, not a number to be adjusted until it flatters the demo.
             */
            $main = (int) round((60_000 + $r->next() * 300_000) / 1_000) * 1_000 * 100;

            IncomeSource::create([
                'user_id'     => $user->id,
                'label'       => 'Main income',
                'amount'      => $main,
                'reliability' => $r->next() > 0.30 ? 'certain' : 'likely',
            ]);

            if ($r->next() > 0.45) {
                IncomeSource::create([
                    'user_id'     => $user->id,
                    'label'       => 'Side work',
                    'amount'      => (int) round($main * (0.12 + $r->next() * 0.35)),
                    'reliability' => 'variable',
                ]);
            }

            /* Commitments. Rent is spread monthly, which is how people
               actually experience a yearly payment. */
            $user->obligations()->delete();

            Obligation::create([
                'user_id'  => $user->id,
                'label'    => 'Rent (spread monthly)',
                'amount'   => (int) round($main * (0.12 + $r->next() * 0.18)),
                'category' => 'housing',
                'due_day'  => 1 + (int) floor($r->next() * 27),
            ]);

            if ($dependents > 0 && $r->next() > 0.35) {
                Obligation::create([
                    'user_id'  => $user->id,
                    'label'    => "Children's school fees",
                    'amount'   => (int) round($main * (0.06 + $r->next() * 0.10)) * max(1, intdiv($dependents, 2)),
                    'category' => 'fees',
                    'due_day'  => 1 + (int) floor($r->next() * 10),
                ]);
            }

            if ($r->next() > 0.55) {
                Obligation::create([
                    'user_id'  => $user->id,
                    'label'    => $r->pick(['Cooperative loan', 'Carbon repayment', 'FairMoney repayment']),
                    'amount'   => (int) round($main * (0.05 + $r->next() * 0.11)),
                    'category' => 'debt',
                    'due_day'  => 1 + (int) floor($r->next() * 27),
                ]);
            }

            $user->goals()->delete();

            if ($r->next() > 0.25) {
                [$label, $lo, $hi] = $r->pick(self::WANTS);
                $months = 3 + (int) floor($r->next() * 15);

                Goal::create([
                    'user_id'         => $user->id,
                    'label'           => $label,
                    'target'          => (int) round($lo + $r->next() * ($hi - $lo)),
                    'saved'           => (int) round(($lo + $r->next() * ($hi - $lo)) * $r->next() * 0.4),
                    'months_left'     => $months,
                    'original_months' => $months,
                ]);
            }

            $this->spendFor($user, $r, $items);

            // The engine decides whether this month balances. Nothing sets it.
            $budget = $budgets->rebuild($user->fresh());

            $made++;
            $feasible += $budget->feasible ? 1 : 0;
        }

        $this->command?->info(sprintf(
            '  %d members seeded — %d balance, %d genuinely short (the engine decided, not the seeder).',
            $made, $feasible, $made - $feasible,
        ));
    }

    /** A fortnight of plausible spending, so pace figures mean something. */
    private function spendFor(User $user, Prng $r, $items): void
    {
        $user->transactions()->delete();

        $patterns = [
            ['food',      'rice',    'Rice',            950_00, 0.28],
            ['food',      null,      'Cooked food',     180_00, 0.80],
            ['food',      'tomato',  'Tomatoes',        320_00, 0.50],
            ['transport', 'busfare', 'Bus fare',         90_00, 0.90],
            ['data',      null,      'Airtime',         200_00, 0.20],
            ['utilities', 'gas',     'Gas refill',      620_00, 0.06],
            ['social',    null,      'Outing',          450_00, 0.10],
            ['health',    null,      'Pharmacy',        380_00, 0.05],
        ];

        $rows = [];
        $now  = now();

        for ($d = (int) $now->format('j') - 1; $d >= 0; $d--) {
            foreach ($patterns as [$category, $slug, $note, $base, $freq]) {
                if ($r->next() > $freq * 0.45) {
                    continue;
                }
                $amount = (int) round(($base * (1 + $r->jitter(0.3))) / 100) * 100;
                if ($amount <= 0) {
                    continue;
                }
                $rows[] = [
                    'user_id'     => $user->id,
                    'occurred_at' => $now->copy()->subDays($d)->setTime(8 + (int) floor($r->next() * 12), 0),
                    'amount'      => $amount,
                    'category'    => $category,
                    'item_id'     => $slug ? ($items[$slug] ?? null) : null,
                    'note'        => $note,
                    'source'      => 'seed',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
    }
}
