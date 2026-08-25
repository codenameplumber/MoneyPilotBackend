<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoneyPilot schema.
 *
 * Two rules run through every table here, carried over from the prototype
 * because both were learned the hard way:
 *
 *  1. EVERY MONEY COLUMN IS bigint KOBO. No decimals, no floats, anywhere.
 *     ₦45.00 is stored as 4500. The moment one decimal(10,2) appears,
 *     allocations stop summing to income and you lose an evening finding out
 *     why. PHP integers are 64-bit, so the ceiling is irrelevant here.
 *
 *  2. TRANSACTIONS AND PRICE REPORTS ARE APPEND-ONLY. No update, no delete.
 *     A correction is a new row pointing at the one it reverses. That buys
 *     undo, an audit trail, and the only way a moderation decision can ever
 *     be reviewed against the original evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------------------------------------------------------------
           Catalog — what can be priced, and where. Seeded, rarely written.
           --------------------------------------------------------------- */
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();          // 'lagos-mainland'
            $t->string('label');                    // 'Lagos Mainland'
            $t->string('state');
            $t->decimal('price_multiplier', 5, 3)->default(1);
            $t->decimal('density', 4, 3)->default(1);   // how well reported
            $t->timestamps();
        });

        Schema::create('markets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->string('slug');
            $t->string('label');
            $t->decimal('price_multiplier', 5, 3)->default(1);
            $t->timestamps();
            $t->unique(['location_id', 'slug']);
        });

        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();           // 'rice'
            $t->string('label');
            $t->string('unit');                     // kg, litre, trip…
            $t->string('item_group');               // staple, protein, transport…
            $t->unsignedBigInteger('base_price')->default(0);  // kobo, reference
            $t->decimal('commonness', 4, 3)->default(1);       // how often reported
            $t->timestamps();
        });

        /* ---------------------------------------------------------------
           People
           --------------------------------------------------------------- */
        Schema::create('profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The single most consequential column in the schema: it decides
            // every price the engine uses for this person.
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('household_size')->default(1);
            $t->unsignedTinyInteger('dependents')->default(0);
            $t->unsignedTinyInteger('trips_per_day')->default(2);
            $t->unsignedTinyInteger('days_out')->default(22);
            $t->json('weights')->nullable();        // discretionary priorities
            $t->timestamps();
            $t->unique('user_id');
        });

        Schema::create('income_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('label');
            $t->unsignedBigInteger('amount');       // kobo, per month
            $t->enum('reliability', ['certain', 'likely', 'variable'])->default('certain');
            $t->timestamps();
            $t->index('user_id');
        });

        Schema::create('obligations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('label');
            $t->unsignedBigInteger('amount');       // kobo, per month
            $t->string('category')->default('housing');   // housing | fees | debt
            $t->unsignedTinyInteger('due_day')->default(1);  // the timing analysis needs this
            $t->timestamps();
            $t->index('user_id');
        });

        Schema::create('goals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('label');
            $t->unsignedBigInteger('target');       // kobo
            $t->unsignedBigInteger('saved')->default(0);
            // The monthly contribution is DERIVED on every read, never stored:
            // deadlines move, and a cached figure that quietly stopped being
            // true is worse than no figure at all.
            $t->unsignedSmallInteger('months_left')->default(6);
            $t->unsignedSmallInteger('original_months')->nullable();
            $t->boolean('paused')->default(false);
            $t->timestamps();
            $t->index('user_id');
        });

        /* ---------------------------------------------------------------
           The spend log — append-only
           --------------------------------------------------------------- */
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('occurred_at');
            $t->bigInteger('amount');               // kobo; negative = a reversal
            $t->string('category');
            $t->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note')->nullable();
            $t->string('source')->default('user');  // user | seed | statement
            // A correction is its own row. Nothing is ever updated in place.
            $t->foreignId('reverses_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->timestamps();
            $t->index(['user_id', 'occurred_at']);
        });

        /* ---------------------------------------------------------------
           Budgets — a snapshot, so an old one still explains itself
           --------------------------------------------------------------- */
        Schema::create('budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('month', 7);                 // '2026-08'
            $t->unsignedBigInteger('income');
            $t->unsignedBigInteger('gross_income');
            $t->unsignedBigInteger('obligation_total');
            $t->unsignedBigInteger('floor_total');
            $t->unsignedBigInteger('goal_monthly');
            $t->bigInteger('slack');
            $t->boolean('feasible')->default(true);
            $t->unsignedBigInteger('shortfall')->default(0);
            $t->decimal('confidence', 4, 3)->default(0);
            $t->json('assumptions')->nullable();
            // So a budget built by an older engine still reports honestly
            // about how it was built.
            $t->string('engine_version', 16)->default('1.0.0');
            $t->timestamps();
            $t->unique(['user_id', 'month']);
        });

        Schema::create('budget_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $t->string('category');
            $t->string('kind');                     // obligation|floor|goal|discretionary
            $t->unsignedBigInteger('amount');
            // The explainability record. Without these two columns a member
            // cannot ask "why is this number what it is", which is the whole
            // trust argument.
            $t->text('reason');
            $t->string('source');                   // trueprice | rule | user
            $t->decimal('confidence', 4, 3)->default(1);
            $t->json('detail')->nullable();
            $t->timestamps();
            $t->index('budget_id');
        });

        /* ---------------------------------------------------------------
           TruePrice
           --------------------------------------------------------------- */
        Schema::create('price_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reporter_key')->nullable(); // pseudonymous, for seeded contributors
            $t->unsignedBigInteger('price');        // kobo
            $t->decimal('reputation', 4, 3)->default(1);
            $t->timestamp('reported_at');
            // Held out of every median until a human decides. Never deleted:
            // a price that looks wrong is sometimes a market that moved.
            $t->enum('status', ['live', 'held', 'rejected'])->default('live');
            $t->timestamps();
            $t->index(['item_id', 'location_id', 'reported_at']);
            $t->index('status');
        });

        /* Materialised medians. Computing a reputation-weighted, exponentially
           decayed median per request does not survive contact with a real
           corpus — the coverage grid alone asks for 162 of them. */
        Schema::create('price_aggregates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('window_days')->default(30);
            $t->unsignedBigInteger('price')->default(0);
            $t->unsignedInteger('reports_total')->default(0);
            $t->unsignedInteger('reports_used')->default(0);
            $t->unsignedInteger('outliers')->default(0);
            $t->enum('scope', ['lga', 'state', 'national'])->default('lga');
            $t->decimal('confidence', 4, 3)->default(0);
            $t->unsignedSmallInteger('freshest_days')->default(0);
            $t->unsignedBigInteger('low')->default(0);
            $t->unsignedBigInteger('high')->default(0);
            $t->json('by_market')->nullable();
            $t->timestamp('computed_at')->nullable();
            $t->timestamps();
            $t->unique(['item_id', 'location_id', 'window_days'], 'agg_pair_window');
        });

        Schema::create('contributor_stats', function (Blueprint $t) {
            $t->id();
            $t->string('reporter_key')->unique();
            $t->string('handle');                   // pseudonymous: "Ada O."
            $t->decimal('reputation', 4, 3)->default(1);
            $t->unsignedInteger('reports_count')->default(0);
            $t->unsignedInteger('flagged_count')->default(0);
            $t->unsignedSmallInteger('areas')->default(0);
            $t->unsignedSmallInteger('items')->default(0);
            $t->timestamp('last_reported_at')->nullable();
            $t->timestamps();
        });

        /* ---------------------------------------------------------------
           Health Check — DERIVED FINDINGS ONLY.
           No file, no raw rows. The statement is parsed in the browser and
           only the analysis is posted, so the privacy promise survives the
           move to a server.
           --------------------------------------------------------------- */
        Schema::create('statement_analyses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('period_from');
            $t->date('period_to');
            $t->unsignedInteger('tx_count');
            $t->unsignedBigInteger('monthly_in');
            $t->unsignedBigInteger('monthly_out');
            $t->bigInteger('net_per_month');
            $t->unsignedTinyInteger('payday')->nullable();
            $t->unsignedBigInteger('exposed_total')->default(0);
            $t->unsignedTinyInteger('score')->default(0);
            $t->string('band')->nullable();
            $t->json('findings')->nullable();
            $t->json('advice')->nullable();
            $t->json('spending')->nullable();
            $t->timestamps();
            $t->index('user_id');
        });

        /* Every question asked of the assistant, for auditing what it said.
           Answers are computed, so this is a record of decisions, not chat. */
        Schema::create('coach_queries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('question');
            $t->string('intent', 32);
            $t->text('answer');
            $t->boolean('used_llm')->default(false);
            $t->json('detail')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'coach_queries', 'statement_analyses', 'contributor_stats',
            'price_aggregates', 'price_reports', 'budget_lines', 'budgets',
            'transactions', 'goals', 'obligations', 'income_sources',
            'profiles', 'items', 'markets', 'locations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
