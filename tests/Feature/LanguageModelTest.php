<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpenAiService;
use App\Services\CoachService;
use Illuminate\Support\Facades\Http;
use Tests\SeededTestCase;

/**
 * The safety argument for putting a language model near somebody's budget.
 *
 * The claim is not "the model is careful". It is that the model is handed the
 * finished answer and the finished figures — never the question — and that any
 * rewrite which loses, alters, or invents a naira figure is thrown away in
 * favour of the engine's own wording.
 *
 * These tests hold the code to that with a faked API, so they run without a key
 * and without spending anything.
 */
class LanguageModelTest extends SeededTestCase
{
    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    /** The shape the Chat Completions API actually returns. */
    private function reply(string $text): array
    {
        return [
            'id'      => 'chatcmpl-test',
            'object'  => 'chat.completion',
            'model'   => 'gpt-4o-mini',
            'choices' => [[
                'index'         => 0,
                'finish_reason' => 'stop',
                'message'       => ['role' => 'assistant', 'content' => $text],
            ]],
        ];
    }

    private function enabled(): void
    {
        config([
            'services.openai.enabled' => true,
            'services.openai.key'     => 'sk-test-not-a-real-key',
            'services.openai.model'   => 'gpt-4o-mini',
        ]);
    }

    /* ---------------- off by default ---------------- */

    public function test_with_no_key_the_engines_own_wording_is_returned_untouched(): void
    {
        Http::fake();   // any outbound call at all would be a failure

        config(['services.openai.enabled' => false, 'services.openai.key' => null]);

        $ai = app(OpenAiService::class);

        $this->assertFalse($ai->enabled());
        $this->assertSame('You have ₦4,088 a day.', $ai->rephrase('You have ₦4,088 a day.'));
        $this->assertNull($ai->summarise(['income' => '₦118,000']));

        Http::assertNothingSent();
    }

    public function test_a_key_without_the_switch_stays_off(): void
    {
        Http::fake();

        config(['services.openai.enabled' => false, 'services.openai.key' => 'sk-real-looking']);

        $this->assertFalse(app(OpenAiService::class)->enabled());
        Http::assertNothingSent();
    }

    /* ---------------- the request ---------------- */

    public function test_it_calls_openai_the_way_chat_completions_expects(): void
    {
        $this->enabled();
        Http::fake(['api.openai.com/*' => Http::response($this->reply('₦4,088 a day, plainly put.'))]);

        app(OpenAiService::class)->rephrase('₦4,088 a day.');

        Http::assertSent(function ($request) {
            $this->assertSame('https://api.openai.com/v1/chat/completions', $request->url());

            // Bearer token, not a bespoke header — get this wrong and every
            // call 401s in production while every test still passes.
            $this->assertSame('Bearer sk-test-not-a-real-key', $request->header('Authorization')[0]);

            $body = $request->data();
            $this->assertSame('gpt-4o-mini', $body['model']);

            // The rules go in a system message. Putting them in the user turn
            // makes them just another thing the model may choose to ignore.
            $this->assertSame('system', $body['messages'][0]['role']);
            $this->assertSame('user', $body['messages'][1]['role']);
            $this->assertStringContainsString('Reproduce amounts EXACTLY', $body['messages'][0]['content']);

            return true;
        });
    }

    /** A response with no usable text must not be passed off as a rewrite. */
    public function test_an_empty_completion_falls_back_to_the_engine(): void
    {
        $this->enabled();

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => '   ']]],
        ])]);

        $engine = 'You have ₦28,618 left, about ₦4,088 a day.';

        $this->assertSame($engine, app(OpenAiService::class)->rephrase($engine));
    }

    /* ---------------- the figure guard ---------------- */

    public function test_a_rewrite_that_keeps_every_figure_is_used(): void
    {
        $this->enabled();

        Http::fake(['api.openai.com/*' => Http::response(
            $this->reply('You have got ₦4,088 to work with each day for the next 7 days.'),
        )]);

        $engine = '₦4,088 a day for 7 days.';
        $out    = app(OpenAiService::class)->rephrase($engine);

        $this->assertStringContainsString('₦4,088', $out);
        $this->assertNotSame($engine, $out, 'a clean rewrite should have been accepted');
    }

    /**
     * The guard that matters most. A hallucinated sentence is embarrassing;
     * a hallucinated naira figure is money somebody does not have.
     */
    public function test_a_rewrite_that_changes_a_figure_is_discarded(): void
    {
        $this->enabled();

        Http::fake(['api.openai.com/*' => Http::response(
            $this->reply('You have got about ₦40,880 a day — plenty!'),
        )]);

        $engine = '₦4,088 a day for 7 days.';

        $this->assertSame($engine, app(OpenAiService::class)->rephrase($engine),
            'a rewrite that moved the decimal point must never reach a member');
    }

    public function test_a_rewrite_that_drops_a_figure_entirely_is_discarded(): void
    {
        $this->enabled();

        Http::fake(['api.openai.com/*' => Http::response(
            $this->reply('You are doing fine this month, no need to worry.'),
        )]);

        $engine = 'You have ₦28,618 left, about ₦4,088 a day.';

        $this->assertSame($engine, app(OpenAiService::class)->rephrase($engine));
    }

    /** The more dangerous direction: money the engine never authorised. */
    public function test_a_rewrite_that_invents_a_new_figure_is_discarded(): void
    {
        $this->enabled();

        Http::fake(['api.openai.com/*' => Http::response(
            $this->reply('You have ₦4,088 a day, so you could put ₦10,000 aside too.'),
        )]);

        $engine = '₦4,088 a day for 7 days.';

        $this->assertSame($engine, app(OpenAiService::class)->rephrase($engine),
            'the model must not add a figure in the engine\'s voice');
    }

    /* ---------------- failure ---------------- */

    public function test_an_api_failure_falls_back_to_the_engine_rather_than_erroring(): void
    {
        $this->enabled();

        Http::fake(['api.openai.com/*' => Http::response(
            ['error' => ['type' => 'rate_limit_error']], 429,
        )]);

        $engine = 'You have ₦28,618 left.';

        $this->assertSame($engine, app(OpenAiService::class)->rephrase($engine));
        $this->assertNull(app(OpenAiService::class)->summarise(['left' => '₦28,618']));
    }

    /** A 5xx from the provider. */
    public function test_an_overloaded_api_falls_back_rather_than_erroring(): void
    {
        $this->enabled();

        Http::fake(['api.openai.com/*' => Http::response(
            ['error' => ['type' => 'overloaded_error']], 529,
        )]);

        $engine = 'You have ₦28,618 left.';

        $this->assertSame($engine, app(OpenAiService::class)->rephrase($engine));
    }

    /**
     * A genuine connection failure, through the real HTTP client.
     *
     * Faking a thrown ConnectionException segfaults PHP on Windows, so this
     * points the endpoint at a closed local port instead — which exercises the
     * actual catch block rather than a mock of it, and needs no network.
     */
    public function test_an_unreachable_api_never_throws(): void
    {
        $this->enabled();
        config(['services.openai.endpoint' => 'http://127.0.0.1:9/v1/chat/completions']);

        $engine = 'You have ₦28,618 left.';

        $this->assertSame($engine, app(OpenAiService::class)->rephrase($engine));
        $this->assertNull(app(OpenAiService::class)->summarise(['left' => '₦28,618']));
    }

    /* ---------------- the ordering that is the whole argument ---------------- */

    public function test_the_model_is_never_shown_the_members_question(): void
    {
        $this->enabled();
        Http::fake(['api.openai.com/*' => Http::response($this->reply('Rephrased, with ₦8,000 kept.'))]);

        $coach  = app(CoachService::class);
        $result = $coach->ask('Can I afford ₦8,000 for dinner tonight?', $this->member());

        $coach->phrase($result);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            $this->assertStringNotContainsString('Can I afford', $body,
                'the question must never be sent — the model would then be deciding');
            $this->assertStringContainsString('Rewrite this answer', $body);

            return true;
        });
    }

    public function test_a_refused_question_is_never_sent_to_the_model_at_all(): void
    {
        $this->enabled();
        Http::fake();

        $coach  = app(CoachService::class);
        $result = $coach->ask('should I buy bitcoin?', $this->member());

        $this->assertSame('refused', $result['intent']);
        $this->assertSame($result['answer'], $coach->phrase($result));

        Http::assertNothingSent();
    }

    /* ---------------- one seam, one call ---------------- */

    /**
     * Every answer costs exactly one request, whatever the intent.
     *
     * The "summary" intent used to cost two: summarise() phrased the month and
     * threw the result away, then phrase() did the real work. Nothing failed
     * and nothing looked wrong — it just quietly doubled the bill on one of
     * the most natural questions a member can ask. A count is the only thing
     * that catches that class of bug.
     */
    public function test_a_summary_question_costs_exactly_one_request(): void
    {
        $this->enabled();
        Http::fake(['api.openai.com/*' => Http::response($this->reply('Plainly put, ₦118,000 came in.'))]);

        $coach  = app(CoachService::class);
        $result = $coach->ask('how are my finances?', $this->member());
        $coach->phrase($result);

        $this->assertSame('summary', $result['intent']);

        $sent = 0;
        Http::assertSent(function () use (&$sent) { $sent++; return true; });
        $this->assertSame(1, $sent, 'one answer must never cost more than one request');
    }

    /** And the seam still runs for that intent — one call, not zero. */
    public function test_the_summary_answer_still_goes_through_the_figure_guard(): void
    {
        $this->enabled();
        Http::fake(['api.openai.com/*' => Http::response($this->reply('You are fine, relax.'))]);

        $coach  = app(CoachService::class);
        $result = $coach->ask('how are my finances?', $this->member());

        // The rewrite dropped every figure, so the engine's wording must win.
        $this->assertSame($result['answer'], $coach->phrase($result));
        Http::assertSentCount(1);
    }

    /* ---------------- the key ---------------- */

    public function test_the_key_never_reaches_the_browser(): void
    {
        $this->enabled();
        Http::fake(['api.openai.com/*' => Http::response($this->reply('ok'))]);

        $html = $this->actingAs($this->member())->get('/app/coach')->getContent();

        $this->assertStringNotContainsString('sk-test-not-a-real-key', $html);
        // 'sk-' catches any real key shape, not just this fixture's. The
        // assertion here read 'ANTHROPIC' until the provider swap, at which
        // point it could no longer fail for any reason.
        $this->assertStringNotContainsString('sk-', $html);
        $this->assertStringNotContainsString('OPENAI', $html);
        $this->assertStringNotContainsString('Bearer ', $html);
    }
}
