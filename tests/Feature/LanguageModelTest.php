<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClaudeService;
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

    /** The shape the Messages API actually returns. */
    private function reply(string $text): array
    {
        return [
            'id'      => 'msg_test',
            'type'    => 'message',
            'role'    => 'assistant',
            'model'   => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    private function enabled(): void
    {
        config([
            'services.claude.enabled' => true,
            'services.claude.key'     => 'sk-ant-test-not-a-real-key',
            'services.claude.model'   => 'claude-sonnet-5',
        ]);
    }

    /* ---------------- off by default ---------------- */

    public function test_with_no_key_the_engines_own_wording_is_returned_untouched(): void
    {
        Http::fake();   // any outbound call at all would be a failure

        config(['services.claude.enabled' => false, 'services.claude.key' => null]);

        $ai = app(ClaudeService::class);

        $this->assertFalse($ai->enabled());
        $this->assertSame('You have ₦4,088 a day.', $ai->rephrase('You have ₦4,088 a day.'));
        $this->assertNull($ai->summarise(['income' => '₦118,000']));

        Http::assertNothingSent();
    }

    public function test_a_key_without_the_switch_stays_off(): void
    {
        Http::fake();

        config(['services.claude.enabled' => false, 'services.claude.key' => 'sk-ant-real-looking']);

        $this->assertFalse(app(ClaudeService::class)->enabled());
        Http::assertNothingSent();
    }

    /* ---------------- the request ---------------- */

    public function test_it_calls_anthropic_with_the_headers_the_messages_api_requires(): void
    {
        $this->enabled();
        Http::fake(['api.anthropic.com/*' => Http::response($this->reply('₦4,088 a day, plainly put.'))]);

        app(ClaudeService::class)->rephrase('₦4,088 a day.');

        Http::assertSent(function ($request) {
            $this->assertSame('https://api.anthropic.com/v1/messages', $request->url());
            $this->assertSame('sk-ant-test-not-a-real-key', $request->header('x-api-key')[0]);
            $this->assertSame('2023-06-01', $request->header('anthropic-version')[0]);

            $body = $request->data();
            $this->assertSame('claude-sonnet-5', $body['model']);
            $this->assertArrayHasKey('system', $body, 'the rules belong in the system prompt');
            $this->assertSame('user', $body['messages'][0]['role']);

            // The Authorization header is an OpenAI-ism; sending it here would
            // mean the key is being handed to the wrong scheme.
            $this->assertEmpty($request->header('Authorization'));

            return true;
        });
    }

    public function test_a_reply_split_across_content_blocks_is_joined(): void
    {
        $this->enabled();

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'You have ₦28,618 left, '],
                ['type' => 'text', 'text' => 'about ₦4,088 a day.'],
            ],
        ])]);

        $out = app(ClaudeService::class)->rephrase('₦28,618 left, ₦4,088 a day.');

        $this->assertStringContainsString('₦28,618', $out);
        $this->assertStringContainsString('₦4,088', $out);
    }

    /* ---------------- the figure guard ---------------- */

    public function test_a_rewrite_that_keeps_every_figure_is_used(): void
    {
        $this->enabled();

        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->reply('You have got ₦4,088 to work with each day for the next 7 days.'),
        )]);

        $engine = '₦4,088 a day for 7 days.';
        $out    = app(ClaudeService::class)->rephrase($engine);

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

        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->reply('You have got about ₦40,880 a day — plenty!'),
        )]);

        $engine = '₦4,088 a day for 7 days.';

        $this->assertSame($engine, app(ClaudeService::class)->rephrase($engine),
            'a rewrite that moved the decimal point must never reach a member');
    }

    public function test_a_rewrite_that_drops_a_figure_entirely_is_discarded(): void
    {
        $this->enabled();

        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->reply('You are doing fine this month, no need to worry.'),
        )]);

        $engine = 'You have ₦28,618 left, about ₦4,088 a day.';

        $this->assertSame($engine, app(ClaudeService::class)->rephrase($engine));
    }

    /** The more dangerous direction: money the engine never authorised. */
    public function test_a_rewrite_that_invents_a_new_figure_is_discarded(): void
    {
        $this->enabled();

        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->reply('You have ₦4,088 a day, so you could put ₦10,000 aside too.'),
        )]);

        $engine = '₦4,088 a day for 7 days.';

        $this->assertSame($engine, app(ClaudeService::class)->rephrase($engine),
            'the model must not add a figure in the engine\'s voice');
    }

    /* ---------------- failure ---------------- */

    public function test_an_api_failure_falls_back_to_the_engine_rather_than_erroring(): void
    {
        $this->enabled();

        Http::fake(['api.anthropic.com/*' => Http::response(
            ['error' => ['type' => 'rate_limit_error']], 429,
        )]);

        $engine = 'You have ₦28,618 left.';

        $this->assertSame($engine, app(ClaudeService::class)->rephrase($engine));
        $this->assertNull(app(ClaudeService::class)->summarise(['left' => '₦28,618']));
    }

    /** Anthropic's own "we are overloaded" status. */
    public function test_an_overloaded_api_falls_back_rather_than_erroring(): void
    {
        $this->enabled();

        Http::fake(['api.anthropic.com/*' => Http::response(
            ['error' => ['type' => 'overloaded_error']], 529,
        )]);

        $engine = 'You have ₦28,618 left.';

        $this->assertSame($engine, app(ClaudeService::class)->rephrase($engine));
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
        config(['services.claude.endpoint' => 'http://127.0.0.1:9/v1/messages']);

        $engine = 'You have ₦28,618 left.';

        $this->assertSame($engine, app(ClaudeService::class)->rephrase($engine));
        $this->assertNull(app(ClaudeService::class)->summarise(['left' => '₦28,618']));
    }

    /* ---------------- the ordering that is the whole argument ---------------- */

    public function test_the_model_is_never_shown_the_members_question(): void
    {
        $this->enabled();
        Http::fake(['api.anthropic.com/*' => Http::response($this->reply('Rephrased, with ₦8,000 kept.'))]);

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

    /* ---------------- the key ---------------- */

    public function test_the_key_never_reaches_the_browser(): void
    {
        $this->enabled();
        Http::fake(['api.anthropic.com/*' => Http::response($this->reply('ok'))]);

        $html = $this->actingAs($this->member())->get('/app/coach')->getContent();

        $this->assertStringNotContainsString('sk-ant-test-not-a-real-key', $html);
        $this->assertStringNotContainsString('ANTHROPIC', $html);
        $this->assertStringNotContainsString('x-api-key', $html);
    }
}
