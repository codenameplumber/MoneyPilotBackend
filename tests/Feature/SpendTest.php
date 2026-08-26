<?php

namespace Tests\Feature;

use App\Livewire\App\Spend;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;
use Tests\SeededTestCase;

/**
 * The ledger is append-only, and that has to be true in the product, not just
 * in the schema comment.
 */
class SpendTest extends SeededTestCase
{
    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    public function test_logging_a_purchase_writes_one_row_in_kobo(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(Spend::class)
            ->set('amount', '2,500')
            ->set('category', 'food')
            ->set('note', 'Rice, 5kg')
            ->call('log')
            ->assertHasNoErrors();

        $tx = $user->transactions()->latest('id')->first();

        $this->assertSame(250_000, $tx->amount, 'money is stored as integer kobo, never a float');
        $this->assertSame('Rice, 5kg', $tx->note);
        $this->assertSame('manual', $tx->source);
    }

    public function test_a_blank_or_nonsense_amount_is_refused(): void
    {
        Livewire::actingAs($this->member())->test(Spend::class)
            ->set('amount', 'plenty')
            ->call('log')
            ->assertHasErrors('amount');
    }

    /** The correction path: reverse, never edit. */
    public function test_correcting_an_entry_reverses_it_and_keeps_the_original(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(Spend::class)
            ->set('amount', '4,000')->set('category', 'social')->set('note', 'Typo')
            ->call('log');

        $original = $user->transactions()->latest('id')->first();

        Livewire::actingAs($user)->test(Spend::class)->call('reverse', $original->id);

        $this->assertNotNull(
            Transaction::find($original->id),
            'the original entry must survive a correction',
        );

        $reversal = Transaction::where('reverses_id', $original->id)->firstOrFail();

        $this->assertSame(-$original->amount, $reversal->amount);
        $this->assertSame(0, $original->amount + $reversal->amount, 'the pair must net to nothing');
    }

    /**
     * A reversal writes a permanent second row, so it does not happen on a
     * stray tap next to the amount somebody just typed.
     */
    public function test_reversing_asks_first_and_cancelling_changes_nothing(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(Spend::class)
            ->set('amount', '3,000')->set('category', 'food')->set('note', 'Careful now')
            ->call('log');

        $tx     = $user->transactions()->latest('id')->first();
        $before = Transaction::count();

        Livewire::actingAs($user)->test(Spend::class)
            ->call('askReverse', $tx->id)
            ->assertSet('confirming', $tx->id)
            ->assertSee('Reverse this entry?')
            ->assertSee('Careful now')
            ->call('cancelReverse')
            ->assertSet('confirming', null)
            ->assertDontSee('Reverse this entry?');

        $this->assertSame($before, Transaction::count(), 'cancelling must write nothing');
    }

    public function test_confirming_the_sheet_writes_the_reversal(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(Spend::class)
            ->set('amount', '7,500')->set('category', 'social')->call('log');

        $tx = $user->transactions()->latest('id')->first();

        Livewire::actingAs($user)->test(Spend::class)
            ->call('askReverse', $tx->id)
            ->call('confirmReverse')
            ->assertSet('confirming', null);

        $this->assertSame(
            -$tx->amount,
            (int) Transaction::where('reverses_id', $tx->id)->value('amount'),
        );
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(Spend::class)
            ->set('amount', '1,000')->set('category', 'data')->call('log');

        $tx = $user->transactions()->latest('id')->first();

        Livewire::actingAs($user)->test(Spend::class)
            ->call('reverse', $tx->id)
            ->call('reverse', $tx->id)
            ->assertSet('flash', fn ($v) => str_contains($v, 'already been corrected'));

        $this->assertSame(1, Transaction::where('reverses_id', $tx->id)->count());
    }

    /** One member must never be able to touch another member's ledger. */
    public function test_a_member_cannot_reverse_someone_elses_entry(): void
    {
        $other = User::where('email', 'amaka.eze@gmail.com')->firstOrFail();
        $their = $other->transactions()->whereNull('reverses_id')->firstOrFail();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($this->member())->test(Spend::class)->call('reverse', $their->id);
    }
}
