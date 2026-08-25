<?php

namespace App\Livewire\App;

use App\Domain\HealthCheck as Engine;
use App\Domain\Money;
use App\Domain\SampleStatement;
use App\Domain\Statement;
use App\Models\IncomeSource;
use App\Models\Obligation;
use App\Models\StatementAnalysis;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The financial health check.
 *
 * WHAT HAPPENS TO THE FILE — the honest version, because this is the feature
 * people are most reasonably nervous about:
 *
 *   · The upload is read once, in memory, and the temporary file is deleted
 *     in the same request. It is never moved to permanent storage.
 *   · Only DERIVED findings are persisted — totals, a payday, category
 *     shares, the score and its drivers. No narration, no counterparty, no
 *     balance, no individual transaction.
 *   · Nothing is sent to OpenAI. The whole analysis is local arithmetic.
 *
 * That is a narrower promise than "it never leaves your device", and it is one
 * the code actually enforces rather than one a policy page asserts.
 */
#[Layout('layouts.app')]
class HealthCheck extends Component
{
    use WithFileUploads;

    public $file;

    /** The parse result and analysis, held for this request only. */
    public array $analysis = [];

    public array $warnings = [];

    public string $error = '';

    public string $flash = '';

    /** Findings are shown collapsed; evidence expands one at a time. */
    public ?int $openFinding = null;

    public function updatedFile(): void
    {
        $this->validate([
            'file' => 'required|file|max:4096|mimes:csv,txt,tsv',
        ], [
            'file.mimes' => 'Upload the CSV export from your bank app. A PDF statement has to be '
                          . 'exported as CSV first — the figures are readable that way, and the file is smaller.',
            'file.max'   => 'That file is larger than 4MB. A statement export is normally well under that.',
        ]);

        $this->run(file_get_contents($this->file->getRealPath()));

        // The upload is not kept. Livewire wrote it to a temporary directory;
        // it goes now rather than waiting for a cleanup job.
        $this->file->delete();
        $this->reset('file');
    }

    public function trySample(): void
    {
        $this->run(SampleStatement::csv());
        $this->flash = 'This is a sample statement, not your data. It is built around the timing '
                     . 'problem the check exists to find.';
    }

    private function run(string $text): void
    {
        $this->reset('analysis', 'warnings', 'error', 'openFinding');

        $parsed = Statement::parse($text);

        if (! $parsed['ok']) {
            $this->error = $parsed['error'];
            return;
        }

        $this->warnings = $parsed['warnings'];
        $analysis       = Engine::analyse($parsed['rows']);

        if (! $analysis['ok']) {
            $this->error = $analysis['error'];
            return;
        }

        // Evidence rows are useful on screen but are raw statement lines, so
        // they are shown and then dropped — never written down.
        $this->analysis = $analysis;

        StatementAnalysis::create([
            'user_id'       => Auth::id(),
            'period_from'   => date('Y-m-d', $analysis['from']),
            'period_to'     => date('Y-m-d', $analysis['to']),
            'tx_count'      => $analysis['txCount'],
            'monthly_in'    => max(0, $analysis['monthlyIn']),
            'monthly_out'   => max(0, $analysis['monthlyOut']),
            'net_per_month' => $analysis['netPerMonth'],
            'payday'        => $analysis['timing']['payday'],
            'exposed_total' => $analysis['timing']['exposedTotal'],
            'score'         => $analysis['score']['value'],
            'band'          => $analysis['score']['band'],
            // Titles and bodies only. The evidence rows are deliberately not
            // included — that is where the narration lives.
            'findings'      => array_map(
                static fn ($f) => ['severity' => $f['severity'], 'title' => $f['title'], 'body' => $f['body']],
                $analysis['findings'],
            ),
            'advice'        => $analysis['advice'],
            'spending'      => array_map(
                static fn ($c) => [
                    'category' => $c['category'], 'label' => $c['label'],
                    'monthly'  => $c['monthly'],  'share' => round($c['share'], 4),
                    'count'    => $c['count'],
                ],
                $analysis['spending'],
            ),
        ]);
    }

    public function toggleFinding(int $i): void
    {
        $this->openFinding = $this->openFinding === $i ? null : $i;
    }

    /**
     * Turn the review into a plan.
     *
     * This is the step that makes the feature worth having: the income and
     * commitments the analysis found become real rows, and the budget engine
     * then prices the food and transport floor at local market rates on top of
     * them. Nothing is overwritten silently — the member is told what changed.
     */
    public function adopt(BudgetService $budgets): void
    {
        if (! $this->analysis) {
            return;
        }

        $user = Auth::user();
        $a    = $this->analysis;

        $user->incomeSources()->delete();

        if ($a['salary']) {
            IncomeSource::create([
                'user_id'     => $user->id,
                'label'       => 'Salary (from your statement)',
                'amount'      => $a['salary']['amount'],
                // Detected from a real cadence, but a statement is history and
                // history is not a promise — so it is "likely", not "certain".
                'reliability' => $a['variability'] > 0.35 ? 'variable' : 'likely',
            ]);
        }

        $other = $a['monthlyIn'] - ($a['salary']['amount'] ?? 0);
        if ($other > 1_000_00) {
            IncomeSource::create([
                'user_id'     => $user->id,
                'label'       => 'Other money in',
                'amount'      => $other,
                'reliability' => 'variable',
            ]);
        }

        $user->obligations()->delete();
        $added = 0;

        foreach ($a['bigCommitments'] as $c) {
            Obligation::create([
                'user_id'  => $user->id,
                'label'    => $c['label'],
                'amount'   => $c['amount'],
                'category' => match ($c['category']) {
                    'housing'   => 'housing',
                    'fees'      => 'fees',
                    'debt'      => 'debt',
                    'utilities' => 'utilities',
                    default     => 'debt',
                },
                'due_day'  => $c['dayOfMonth'],
            ]);
            $added++;
        }

        $budget = $budgets->rebuild($user->fresh());

        $this->flash = sprintf(
            'Your budget has been rebuilt from this statement: %s of income and %d commitment%s. %s',
            Money::fmt($a['monthlyIn']), $added, $added === 1 ? '' : 's',
            $budget->feasible
                ? 'It balances.'
                : 'It does not balance — the budget screen lists what would close the gap.',
        );
    }

    public function render()
    {
        return view('livewire.app.health', [
            'history' => StatementAnalysis::where('user_id', Auth::id())
                ->latest('id')->limit(5)->get(),
        ])->layoutData([
            'page'  => 'health',
            'title' => 'Financial health check',
            'sub'   => 'What actually happened to the money you already had',
        ]);
    }
}
