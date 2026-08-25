<?php

namespace Tests\Feature;

use App\Livewire\App\Coach;
use App\Models\CoachQuery;
use App\Models\User;
use App\Services\CoachService;
use Livewire\Livewire;
use Tests\SeededTestCase;

/**
 * The assistant, held to the claim made about it.
 *
 * The claim is not "it sounds clever". It is that understanding is
 * deterministic, the arithmetic comes from the same engine that builds the
 * budget, and the same question twice gives the same figures.
 */
class CoachTest extends SeededTestCase
{
    private function coach(): CoachService
    {
        return app(CoachService::class);
    }

    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    public function test_intents_are_classified_the_way_a_person_would(): void
    {
        $c = $this->coach();

        $this->assertSame('afford',   $c->classify('Can I afford ₦8,000 for dinner tonight?'));
        $this->assertSame('daily',    $c->classify('How much can I spend today?'));
        $this->assertSame('cheapest', $c->classify('Where is rice cheapest?'));
        $this->assertSame('price',    $c->classify('How much is rice?'));
        $this->assertSame('why',      $c->classify('Why is my food budget that much?'));
        $this->assertSame('pace',     $c->classify('Am I on track this month?'));
        $this->assertSame('cut',      $c->classify('What should I cut?'));
        $this->assertSame('unknown',  $c->classify('lorem ipsum dolor'));
    }

    /** The bug that made "how much can I spend today?" ask permission. */
    public function test_a_daily_rate_question_is_not_mistaken_for_an_affordability_one(): void
    {
        $this->assertSame('daily', $this->coach()->classify('How much can I spend today?'));
        $this->assertSame('afford', $this->coach()->classify('Can I spend ₦5,000 today?'));
    }

    public function test_amounts_are_read_in_every_form_a_person_writes_them(): void
    {
        $c = $this->coach();

        $this->assertSame(800_000, $c->findAmount('can I afford ₦8,000 for dinner'));
        $this->assertSame(500_000, $c->findAmount('is 5k ok?'));
        $this->assertSame(250_000, $c->findAmount('about 2.5k'));
        // A bare small number is a count, not money.
        $this->assertNull($c->findAmount('how much for 2 people'));
    }

    public function test_the_longest_category_word_wins(): void
    {
        $this->assertSame('fees', $this->coach()->findCategory('what about school fees?'));
        $this->assertSame('education', $this->coach()->findCategory('how much on books?'));
    }

    /** Reproducibility is the whole safety argument. */
    public function test_the_same_question_twice_gives_the_same_answer(): void
    {
        $user = $this->member();
        $at   = time();

        $a = $this->coach()->ask('How much can I spend today?', $user, $at);
        $b = $this->coach()->ask('How much can I spend today?', $user, $at);

        $this->assertSame($a['answer'], $b['answer']);
        $this->assertSame($a['detail'], $b['detail']);
    }

    public function test_an_affordability_answer_is_computed_from_the_real_position(): void
    {
        $result = $this->coach()->ask('Can I afford ₦8,000 for dinner tonight?', $this->member());

        $this->assertSame('afford', $result['intent']);
        $this->assertNotEmpty($result['detail']);
        $this->assertMatchesRegularExpression('/₦[\d,]+/u', $result['answer'], 'an answer with no figure is not an answer');
    }

    /** Regulated territory is refused, not guessed at. */
    public function test_investment_questions_are_declined(): void
    {
        foreach (['should I buy bitcoin?', 'what stocks should I pick', 'is forex a good idea'] as $q) {
            $result = $this->coach()->ask($q, $this->member());
            $this->assertSame('refused', $result['intent'], "not refused: {$q}");
            $this->assertStringContainsString('do not give investment advice', $result['answer']);
        }
    }

    public function test_asking_persists_the_exchange_and_records_whether_a_model_was_used(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(Coach::class)
            ->set('question', 'Am I on track this month?')
            ->call('ask')
            ->assertSet('question', '');

        $row = CoachQuery::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame('pace', $row->intent);
        $this->assertNotEmpty($row->answer);
        $this->assertIsBool($row->used_llm);
        // With no key configured the engine's own wording must be what is stored.
        $this->assertFalse($row->used_llm, 'nothing should claim a model ran when none did');
        $this->assertSame($row->answer, $row->detail['engine']);
    }

    public function test_the_thread_survives_leaving_and_returning(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(Coach::class)
            ->set('question', 'What is my biggest expense?')
            ->call('ask');

        Livewire::actingAs($user)->test(Coach::class)
            ->assertSet('thread', fn ($t) => count($t) === 1 && $t[0]['intent'] === 'biggest');
    }

    public function test_a_flood_of_questions_is_throttled(): void
    {
        $user = $this->member();
        $c = Livewire::actingAs($user)->test(Coach::class);

        for ($i = 0; $i < 25; $i++) {
            $c->set('question', 'How much can I spend today?')->call('ask');
        }

        $last = $c->get('thread');
        $this->assertSame('throttled', end($last)['intent'], 'an unbounded box wired to a paid API is a bill');
    }
}
