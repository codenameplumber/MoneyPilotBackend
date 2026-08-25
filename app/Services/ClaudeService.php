<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The language-model seam. Anthropic's Claude.
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
 *   - If Anthropic is slow, down, unconfigured, or out of credit, the product
 *     still works. Every method here falls back to the engine's own wording.
 *
 * Calls go through Laravel's HTTP client rather than an SDK: this is a few
 * dozen lines against a stable REST endpoint, and a dependency that pins its
 * own Guzzle is how the previous provider ended up unusable on Laravel 13.
 *
 * The key lives in .env and nowhere else. It is never logged, never returned
 * in a response, and never sent to the browser.
 */
class ClaudeService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * Overridable so a deployment can route through a gateway or proxy — and
     * so the connection-failure path can be tested against a closed local port
     * rather than a faked exception, which segfaults PHP on Windows.
     */
    private function endpoint(): string
    {
        return config('services.claude.endpoint') ?: self::ENDPOINT;
    }

    /** The Messages API version this code is written against. */
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private ?string $key = null,
        private ?string $model = null,
    ) {
        $this->key   ??= config('services.claude.key');
        $this->model ??= config('services.claude.model', 'claude-sonnet-5');
    }

    /** Configured AND switched on. Both are required. */
    public function enabled(): bool
    {
        return (bool) config('services.claude.enabled', false) && filled($this->key);
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

        $text = $this->message($system, $user, maxTokens: 300);

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

        return $this->message(
            $system,
            "Summarise this month:\n" . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            maxTokens: 260,
        );
    }

    /**
     * One call to the Messages API.
     *
     * @return string|null null on any failure — callers must have a fallback.
     */
    private function message(string $system, string $user, int $maxTokens = 300): ?string
    {
        try {
            $response = Http::withHeaders([
                    'x-api-key'         => $this->key,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ])
                ->timeout(15)
                ->connectTimeout(5)
                ->retry(1, 400, throw: false)
                ->post($this->endpoint(), [
                    'model'       => $this->model,
                    'max_tokens'  => $maxTokens,
                    'temperature' => 0.4,
                    'system'      => $system,
                    'messages'    => [
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);

            if ($response->failed()) {
                // Log the status and error type. Never the key, never the
                // member's figures — this file's whole job is not leaking those.
                Log::warning('Claude request failed', [
                    'status' => $response->status(),
                    'error'  => $response->json('error.type'),
                ]);

                return null;
            }

            /*
             * The Messages API returns content as a list of blocks. Taking
             * `content.0.text` works today and breaks the day a response opens
             * with any other block type, so every text block is joined instead.
             */
            $text = collect($response->json('content') ?? [])
                ->where('type', 'text')
                ->pluck('text')
                ->implode('');

            $text = trim($text);

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('Claude unreachable', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Guard against a rewrite that quietly dropped or altered the money.
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
                Log::info('Claude rewrite dropped a figure; using engine wording', ['figure' => $figure]);

                return false;
            }
        }

        // An invented figure is the more dangerous direction: it is money the
        // engine never authorised, presented in the engine's voice.
        foreach ($after as $figure) {
            if (! in_array($figure, $before, true)) {
                Log::warning('Claude rewrite invented a figure; using engine wording', ['figure' => $figure]);

                return false;
            }
        }

        return true;
    }
}
