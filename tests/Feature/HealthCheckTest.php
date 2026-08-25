<?php

namespace Tests\Feature;

use App\Domain\HealthCheck as Engine;
use App\Domain\SampleStatement;
use App\Domain\Statement;
use App\Livewire\App\HealthCheck;
use App\Models\StatementAnalysis;
use App\Models\User;
use Livewire\Livewire;
use Tests\SeededTestCase;

/**
 * Reading a statement, and the finding that justifies the feature.
 *
 * A category breakdown is table stakes. The claim here is that the engine
 * finds a TIMING problem — a large fixed cost leaving before payday — which no
 * pie chart can see and which is the actual reason many people borrow.
 */
class HealthCheckTest extends SeededTestCase
{
    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    private function sample(): array
    {
        $parsed = Statement::parse(SampleStatement::csv());
        $this->assertTrue($parsed['ok'], $parsed['error'] ?? '');

        return $parsed;
    }

    /* ---------------- parsing ---------------- */

    public function test_columns_are_mapped_by_shape_not_by_bank(): void
    {
        $columns = Statement::mapColumns(['Trans Date', 'Narration', 'Withdrawals', 'Lodgement', 'Running Balance']);

        $this->assertSame(0, $columns['date']);
        $this->assertSame(1, $columns['narr']);
        $this->assertSame(2, $columns['debit']);
        $this->assertSame(3, $columns['credit']);
        $this->assertSame(4, $columns['balance']);
    }

    /**
     * The greedy-matching bug: a plain "Amount" column was captured by the
     * debit alias "amount out", so every row read as money going out.
     */
    public function test_a_plain_amount_column_is_not_stolen_by_the_debit_alias(): void
    {
        $columns = Statement::mapColumns(['Date', 'Description', 'Amount', 'Balance']);

        $this->assertSame(2, $columns['amount']);
        $this->assertArrayNotHasKey('debit', $columns);
    }

    /** Nigerian statements are day-first. Guessing American order loses a month. */
    public function test_dates_are_read_day_first(): void
    {
        $this->assertSame('2026-04-03', gmdate('Y-m-d', Statement::parseDate('03/04/2026')));
        $this->assertSame('2026-04-03', gmdate('Y-m-d', Statement::parseDate('3-Apr-2026')));
        $this->assertSame('2026-04-03', gmdate('Y-m-d', Statement::parseDate('2026-04-03')));

        // Ambiguous pairs stay day-first: 12/04 is 12 April, not 4 December.
        $this->assertSame('2026-04-12', gmdate('Y-m-d', Statement::parseDate('12/04/2026')));

        // Only an impossible month forces the other reading.
        $this->assertSame('2026-04-13', gmdate('Y-m-d', Statement::parseDate('04/13/2026')));
    }

    public function test_amounts_are_read_in_every_notation_a_bank_uses(): void
    {
        $this->assertSame(850_000,  Statement::parseAmount('8,500.00'));
        $this->assertSame(850_000,  Statement::parseAmount('₦8,500'));
        $this->assertSame(-120_000, Statement::parseAmount('(1,200.00)'));
        $this->assertSame(-120_000, Statement::parseAmount('1,200.00 DR'));
        $this->assertSame(120_000,  Statement::parseAmount('1,200.00 CR'));
        $this->assertNull(Statement::parseAmount(''));
        $this->assertNull(Statement::parseAmount('-'));
    }

    public function test_a_raw_pdf_is_refused_rather_than_read_as_gibberish(): void
    {
        $result = Statement::parse("%PDF-1.4\n binary junk here");

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('CSV', $result['error']);
    }

    public function test_preamble_above_the_headings_is_skipped_and_reported(): void
    {
        $csv = "Account Name: A Person\nAccount Number: 0123456789\nPeriod: Jan - Mar\n"
             . "Date,Description,Debit,Credit,Balance\n"
             . "01/02/2026,RENT PAYMENT,85000.00,,15000.00\n"
             . "28/02/2026,SALARY PAYMENT,,245000.00,260000.00\n";

        $result = Statement::parse($csv);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['rows']);
        $this->assertStringContainsString('preamble', $result['warnings'][0]);
    }

    public function test_a_semicolon_export_is_handled_without_being_told(): void
    {
        $csv = "Date;Narration;Amount;Balance\n"
             . "01/02/2026;RENT PAYMENT TO LANDLORD;-85000.00;15000.00\n"
             . "28/02/2026;SALARY PAYMENT FOR THE MONTH;245000.00;260000.00\n";

        $result = Statement::parse($csv);

        $this->assertTrue($result['ok']);
        $this->assertSame(';', $result['delimiter']);
        $this->assertCount(2, $result['rows']);
    }

    /* ---------------- the analysis ---------------- */

    public function test_the_headline_finding_is_the_timing_trap(): void
    {
        $a = Engine::analyse($this->sample()['rows']);

        $this->assertTrue($a['ok']);
        $this->assertSame(28, $a['timing']['payday'], 'the salary cadence should be found');
        $this->assertNotNull($a['timing']['worst'], 'rent on the 24th against payday on the 28th');

        $headline = $a['findings'][0];

        $this->assertSame('crit', $headline['severity']);
        $this->assertStringContainsString('before you are paid', $headline['title']);
        $this->assertNotEmpty($headline['evidence'], 'a finding with no transactions behind it is an assertion');
    }

    /** The sample nearly balances, so timing cannot hide behind overspending. */
    public function test_the_sample_does_not_simply_overspend(): void
    {
        $a = Engine::analyse($this->sample()['rows']);

        $this->assertGreaterThan(0, $a['netPerMonth'], 'this persona is not overspending — the dates are wrong');
    }

    public function test_the_score_is_reconstructible_from_its_own_drivers(): void
    {
        $score = Engine::analyse($this->sample()['rows'])['score'];

        $deducted = array_sum(array_column($score['drivers'], 'cost'));

        $this->assertSame(100 - $deducted, $score['value'], 'the points must add up, or the number is a horoscope');
        $this->assertNotEmpty($score['band']);
    }

    public function test_every_piece_of_advice_names_an_amount_or_a_date(): void
    {
        foreach (Engine::analyse($this->sample()['rows'])['advice'] as $ad) {
            $this->assertNotEmpty($ad['impact'], $ad['title'] . ' states no impact');
            $this->assertNotEmpty($ad['body']);
        }
    }

    /* ---------------- the screen ---------------- */

    public function test_the_sample_run_stores_findings_and_never_a_transaction(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(HealthCheck::class)
            ->call('trySample')
            ->assertSet('error', '');

        $row = StatementAnalysis::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(28, $row->payday);
        $this->assertNotEmpty($row->findings);

        // The privacy promise, enforced rather than asserted.
        $stored = json_encode($row->only(['findings', 'advice', 'spending']));

        $this->assertStringNotContainsString('SHOPRITE', $stored, 'no narration may be persisted');
        $this->assertStringNotContainsString('LANDLORD', $stored);
        $this->assertStringNotContainsString('evidence', $stored);
    }

    /** The step that turns a review into a plan. */
    public function test_adopting_the_findings_rebuilds_the_budget_from_them(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(HealthCheck::class)
            ->call('trySample')
            ->call('adopt')
            ->assertSet('flash', fn ($v) => str_contains($v, 'rebuilt from this statement'));

        $user->refresh();

        $this->assertGreaterThan(0, $user->incomeSources()->count());
        $this->assertGreaterThan(0, $user->obligations()->count());

        $rent = $user->obligations()->where('category', 'housing')->first();

        $this->assertNotNull($rent, 'the rent found in the statement should become a commitment');
        $this->assertSame(85_000_00, $rent->amount);
        $this->assertSame(24, $rent->due_day, 'the due day is what the timing analysis needs');
    }
}
