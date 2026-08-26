<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CoachService;
use App\Services\OpenAiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Prove the OpenAI key works — against the real API, for real money.
 *
 * The 16 tests in LanguageModelTest run against a faked HTTP client. That
 * proves the code holds its side of the bargain; it cannot prove the key is
 * valid, that the account has credit, that the model name exists, or that a
 * corporate proxy is not eating the request. Those fail only in front of an
 * audience, which is the worst possible place to discover them.
 *
 * This spends a few tenths of a kobo and answers the question directly.
 *
 *   php artisan ai:check
 *   php artisan ai:check --live   also runs one real member question end to end
 */
class AiCheck extends Command
{
    protected $signature = 'ai:check {--live : Also ask a real member question through CoachService}';

    protected $description = 'Verify the OpenAI key, model and figure guard against the real API';

    public function handle(OpenAiService $ai, CoachService $coach): int
    {
        $key     = (string) config('services.openai.key');
        $model   = (string) config('services.openai.model');
        $enabled = (bool) config('services.openai.enabled');

        $this->line('');
        $this->line('  model     ' . ($model ?: '(unset)'));
        $this->line('  endpoint  ' . (config('services.openai.endpoint') ?: 'https://api.openai.com/v1/chat/completions'));
        // Never the key. A fingerprint is enough to tell two keys apart in a
        // screenshot without the screenshot becoming a credential leak.
        $this->line('  key       ' . ($key === ''
            ? '(empty)'
            : substr($key, 0, 3) . '…' . substr($key, -4) . '  (' . strlen($key) . ' chars)'));
        $this->line('  enabled   ' . ($enabled ? 'true' : 'false'));
        $this->line('');

        if ($key === '') {
            $this->error('  OPENAI_API_KEY is empty in .env.');
            $this->line('  The product works without it — this only affects wording.');

            return self::FAILURE;
        }

        if (! $enabled) {
            $this->warn('  OPENAI_ENABLED is false, so nothing will call the API.');
            $this->line('  Set OPENAI_ENABLED=true in .env, then: php artisan config:clear');

            return self::FAILURE;
        }

        if (! $ai->enabled()) {
            $this->error('  The service reports itself disabled despite the config above.');
            $this->line('  Most likely a stale config cache: php artisan config:clear');

            return self::FAILURE;
        }

        /* --- 1 · a rewrite that should be ACCEPTED ------------------------- */

        $engine = 'You have ₦28,618 left, about ₦4,088 a day.';
        $this->line('  engine    ' . $engine);

        $t0  = microtime(true);
        $out = $ai->rephrase($engine, ['Left' => '₦28,618', 'Safe per day' => '₦4,088']);
        $ms  = (int) round((microtime(true) - $t0) * 1000);

        if ($out === $engine) {
            $this->line('');
            $this->error("  No usable rewrite came back after {$ms}ms.");
            $this->line('  Either the request failed or the rewrite was rejected by the figure guard.');
            $this->line('  The exact reason is in storage/logs — the key is never logged.');
            $this->line('  Common causes: invalid key (401), no credit (429), unknown model (404),');
            $this->line("  or a model that rejects max_tokens. Current model: {$model}");

            return self::FAILURE;
        }

        $this->line('  model     ' . $out);
        $this->info("  ✓ rewrite accepted in {$ms}ms — both figures survived");

        /* --- 2 · optionally, one real member question ----------------------
           Before the fake below, deliberately. Http::fake() is process-wide
           and there is no clean way to lift it mid-command, so every request
           that must be real happens first. */

        if ($this->option('live')) {
            $user = User::where('email', 'chidi@student.unilag.ng')->first()
                ?? User::where('role', 'member')->first();

            if (! $user) {
                $this->line('');
                $this->warn('  No member to ask as. Run: php artisan migrate:fresh --seed');

                return self::SUCCESS;
            }

            $question = 'Can I afford ₦8,000 for dinner tonight?';
            $result   = app(CoachService::class)->ask($question, $user);
            $phrased  = app(CoachService::class)->phrase($result);

            $this->line('');
            $this->line('  question  ' . $question);
            $this->line('  intent    ' . $result['intent']);
            $this->line('  engine    ' . $result['answer']);
            $this->line('  shown     ' . $phrased);
            $this->line('');
            $this->line($phrased === $result['answer']
                ? '  the engine\'s own wording was used (rewrite rejected or unavailable)'
                : '  the model\'s wording was used — every figure checked first');
        }

        /* --- 3 · a rewrite that MUST be rejected ---------------------------
           Forced through the guard rather than hoping a real model produces a
           moved decimal point. This checks the guard, not the model — so the
           response is faked on purpose, and nothing real runs after it. */

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => [
                'role'    => 'assistant',
                'content' => 'You have ₦280,618 left — nearly ₦40,880 a day, plenty!',
            ]]],
        ])]);

        $this->line('');
        if (app(OpenAiService::class)->rephrase($engine) !== $engine) {
            $this->error('  ✗ THE FIGURE GUARD DID NOT FIRE. A moved decimal point reached the output.');

            return self::FAILURE;
        }
        $this->info('  ✓ figure guard rejected a moved decimal point and kept the engine wording');

        $this->line('');
        $this->info('  OpenAI is configured and behaving. Answers are still computed by the engine.');
        $this->line('');

        return self::SUCCESS;
    }
}
