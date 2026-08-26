<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The language-model seam. OpenAI.
 *
 * READ THIS BEFORE CHANGING ANYTHING HERE.
 *
 * The model NEVER computes. It is handed figures the engine has already
 * decided and asked to phrase them. It cannot invent an amount, because it is
 * never given the question — only the answer.
 *
 * That ordering is the entire safety argument for putting an LLM anywhere near
 * somebody's budget:
 *
 *   - A hallucinated sentence is embarrassing. A hallucinated naira figure is
 *     money somebody does not have.
 *   - Financial advice that cannot be reproduced cannot be audited. The engine
 *     gives the same answer twice; the model only changes the wording.
 *   - If OpenAI is slow, down, unconfigured, or out of credit, the product
 *     still works. Every method here falls back to the engine's own wording.
 *
 * Calls go through Laravel's HTTP client rather than an SDK: this is a few
 * dozen lines against a stable REST endpoint, and openai-php/laravel pins
 * Guzzle 7 while Laravel 13 ships Guzzle 8, so the package will not install.
 *
 * The key lives in .env and nowhere else. It is never logged, never returned
 * in a response, and never sent to the browser.
 */
class OpenAiService
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private ?string $key = null,
        private ?string $model = null,
    ) {
        $this->key   ??= config('services.openai.key');
        $this->model ??= config('services.openai.model', 'gpt-4o-mini');
    }

    /**
     * Overridable so a deployment can route through a gateway or proxy — and so
     * the connection-failure path can be tested against a closed local port
     * rather than a faked exception, which segfaults PHP on Windows.
     */
    private function endpoint(): string
    {
        return config('services.openai.endpoint') ?: self::ENDPOINT;
    }

    /** Configured AND switched on. Both are required. */
    public function enabled(): bool
    {
        return (bool) config('services.openai.enabled', false) && filled($this->key);
    }

    /**
     * Rephrase an answer the engine already computed.
     *
     * @param  string $answer  the engine's own wording — and the fallback
     * @param  array  $facts   the figures, already decided
     * @return string          prose, or the original answer on any failure
     */
    public function rephrase(string $answer, array $facts = [], ?string $tone = null): string
    {
        if (! $this->enabled()) {
            return $answer;
        }

        $system = <<<'PROMPT'
        You rewrite personal-finance answers for a Nigerian budgeting app called MoneyPilot.

        ABSOLUTE RULES:
        - Every figure is already correct. Reproduce amounts EXACTLY as given. Never
          compute, estimate, round, convert or infer any number.
        - KEEP EVERY NAIRA FIGURE that appears in the answer. All of them, even one
          that reads as redundant — do not replace "this is ₦8,000" with "this
          purchase". A rewrite missing any figure is DISCARDED and your work is
          wasted, so count them before you reply.
        - Never introduce a figure that is not in the facts provided.
        - Never give investment, tax, or credit advice. This is budgeting only.
        - If the facts do not support a claim, leave the claim out.

        STYLE:
        - Plain Nigerian English. Second person. Warm but never patronising.
        - Two or three short sentences. No preamble, no sign-off, no emoji.
        - Amounts keep the naira sign and comma grouping exactly as written.
        - Return only the rewritten answer. Do not explain what you changed.
        PROMPT;

        $user = "Rewrite this answer more naturally.\n\nANSWER: {$answer}";

        if ($facts) {
            $user .= "\n\nFACTS (already computed — reproduce exactly, add nothing):\n"
                . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        if ($tone) {
            $user .= "\n\nThe situation is: {$tone}.";
        }

        $text = $this->chat($system, $user, maxTokens: 300);

        // A rewrite that dropped or altered the figures is worse than no rewrite.
        return $text && $this->keepsFigures($answer, $text) ? $text : $answer;
    }

    /**
     * A written summary of the month, from figures the engine produced.
     * Returns null when unavailable so the caller uses its own prose.
     */
    public function summarise(array $facts): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $system = <<<'PROMPT'
        You write one short paragraph summarising a person's month for a Nigerian
        budgeting app. Use ONLY the figures provided and reproduce them exactly.
        Never compute anything. Never give investment advice. Three sentences at
        most, plain English, second person, no preamble.
        PROMPT;

        return $this->chat(
            $system,
            "Summarise this month:\n" . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            maxTokens: 260,
        );
    }

    /**
     * One call to the Chat Completions API.
     *
     * @return string|null null on any failure — callers must have a fallback.
     */
    private function chat(string $system, string $user, int $maxTokens = 300): ?string
    {
        try {
            /*
             * One attempt, and a short one.
             *
             * `retry(1, ...)` reads like "retry once" and is not: Laravel's
             * $times is the TOTAL number of attempts, so it rethrows on the
             * first failure and the 400ms backoff never runs. Rather than
             * quietly relying on dead code, the single attempt is now the
             * stated intent.
             *
             * And it is the right intent here. This call improves WORDING —
             * the answer is already computed, already correct, and already on
             * its way to the member. Fifteen seconds of "Working it out…" on a
             * phone buys a nicer sentence at the cost of the thing feeling
             * broken, so the ceiling is eight and the fallback is instant.
             */
            $response = Http::withToken($this->key)
                ->timeout(8)
                ->connectTimeout(4)
                ->retry(1, 0, throw: false)
                ->post($this->endpoint(), [
                    'model'       => $this->model,
                    'temperature' => 0.4,
                    'max_tokens'  => $maxTokens,
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $user],
                    ],
                ]);

            if ($response->failed()) {
                // Log the status and error type. Never the key, never the
                // member's figures — this file's whole job is not leaking those.
                Log::warning('OpenAI request failed', [
                    'status' => $response->status(),
                    'error'  => $response->json('error.type'),
                ]);

                return null;
            }

            $text = trim((string) $response->json('choices.0.message.content'));

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI unreachable', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Guard against a rewrite that quietly dropped, altered or invented money.
     *
     * Every naira figure in the engine's answer must survive into the rewrite,
     * and the rewrite must not introduce one that was never there. This is the
     * check that makes it safe to show a model's words to somebody about their
     * own money.
     */
    private function keepsFigures(string $original, string $rewritten): bool
    {
        preg_match_all('/₦[\d,]+/u', $original, $before);
        preg_match_all('/₦[\d,]+/u', $rewritten, $after);

        $before = array_unique($before[0]);
        $after  = array_unique($after[0]);

        foreach ($before as $figure) {
            if (! in_array($figure, $after, true)) {
                Log::info('OpenAI rewrite dropped a figure; using engine wording', ['figure' => $figure]);

                return false;
            }
        }

        // An invented figure is the more dangerous direction: it is money the
        // engine never authorised, presented in the engine's voice.
        foreach ($after as $figure) {
            if (! in_array($figure, $before, true)) {
                Log::warning('OpenAI rewrite invented a figure; using engine wording', ['figure' => $figure]);

                return false;
            }
        }

        return true;
    }
}
