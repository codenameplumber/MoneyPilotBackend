<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three roles, because the product genuinely has three audiences:
 *   member — budgeting their own money
 *   ops    — TruePrice network operations: moderation, coverage, trust
 *   owner  — the business view: growth, retention, whether this works
 *
 * The prototype could only ever role-play this: a browser can verify a
 * password but never enforce a permission. Here it is a column, and the
 * gates that read it run on the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->enum('role', ['member', 'ops', 'owner'])->default('member')->after('email');
            $t->string('title')->nullable()->after('role');
            $t->string('persona')->nullable()->after('title');   // demo seeding only
            $t->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['role']);
            $t->dropColumn(['role', 'title', 'persona']);
        });
    }
};
