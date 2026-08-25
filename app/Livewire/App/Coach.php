<?php

namespace App\Livewire\App;

use App\Models\CoachQuery;
use App\Services\CoachService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ask MoneyPilot.
 *
 * The conversation is persisted, which the prototype could not do — a member
 * can close the app and come back to what they were told, and support can see
 * the exact answer somebody acted on.
 *
 * Every answer records whether a language model touched the wording, so the
 * distinction between "computed" and "phrased" is auditable rather than a
 * claim in a README.
 */
#[Layout('layouts.app')]
class Coach extends Component
{
    public string $question = '';

    /** Kept in the component so the thread reads as a conversation. */
    public array $thread = [];

    public function mount(CoachService $coach): void
    {
        // The last few exchanges, oldest first — continuity across sessions.
        $this->thread = CoachQuery::where('user_id', Auth::id())
            ->latest('id')->limit(6)->get()->reverse()
            ->map(fn ($q) => [
                'question' => $q->question,
                'answer'   => $q->answer,
                'intent'   => $q->intent,
                'usedLlm'  => $q->used_llm,
                'tone'     => $q->detail['tone'] ?? 'info',
                'detail'   => $q->detail['detail'] ?? [],
                'note'     => $q->detail['note'] ?? null,
                'followups' => $q->detail['followups'] ?? [],
                'at'       => $q->created_at,
            ])->values()->all();
    }

    public function suggest(string $text): void
    {
        $this->question = $text;
        $this->ask(app(CoachService::class));
    }

    public function ask(CoachService $coach): void
    {
        $q = trim($this->question);

        if ($q === '') {
            return;
        }

        /*
         * Throttled per member. Each question can reach OpenAI, and an
         * unbounded text box wired to a paid API is a bill waiting to happen —
         * the deterministic engine is free, the phrasing is not.
         */
        $key = 'coach:' . Auth::id();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->thread[] = [
                'question' => $q,
                'answer'   => 'Give me a moment — that is a lot of questions at once. '
                            . 'Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
                'intent'   => 'throttled', 'usedLlm' => false, 'tone' => 'warn',
                'detail'   => [], 'note' => null, 'followups' => [], 'at' => now(),
            ];
            $this->question = '';
            return;
        }

        RateLimiter::hit($key, 60);

        $result = $coach->ask($q, Auth::user());

        // The engine decides; the model only phrases. If the rewrite differs
        // from the engine's wording, a model was involved and we say so.
        $phrased = $coach->phrase($result);
        $usedLlm = $phrased !== $result['answer'];

        CoachQuery::create([
            'user_id'  => Auth::id(),
            'question' => $q,
            'intent'   => $result['intent'],
            'answer'   => $phrased,
            'used_llm' => $usedLlm,
            'detail'   => [
                'tone'      => $result['tone'] ?? 'info',
                'detail'    => $result['detail'] ?? [],
                'note'      => $result['note'] ?? null,
                'followups' => $result['followups'] ?? [],
                'engine'    => $result['answer'],
            ],
        ]);

        $this->thread[] = [
            'question'  => $q,
            'answer'    => $phrased,
            'intent'    => $result['intent'],
            'usedLlm'   => $usedLlm,
            'tone'      => $result['tone'] ?? 'info',
            'detail'    => $result['detail'] ?? [],
            'note'      => $result['note'] ?? null,
            'followups' => $result['followups'] ?? [],
            'at'        => now(),
        ];

        $this->question = '';
    }

    public function clear(): void
    {
        CoachQuery::where('user_id', Auth::id())->delete();
        $this->thread = [];
    }

    public function render(CoachService $coach)
    {
        return view('livewire.app.coach', [
            'suggestions' => CoachService::SUGGESTIONS,
            'aiEnabled'   => $coach->aiEnabled(),
        ])->layoutData([
            'page'  => 'coach',
            'title' => 'Ask MoneyPilot',
            'sub'   => 'Answers computed from your own figures',
        ]);
    }
}
