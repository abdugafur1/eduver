<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\QuizQuestion;
use App\Models\QuizSession;
use App\Models\StudySet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The issuing and grading half of the quiz. QuizRewardsTest covers the money.
 *
 * The through-line: the browser is never told which answer is correct and can
 * never assert that it was. Every test here that looks like a permissions test
 * is really testing that.
 */
class QuizFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Built explicitly rather than through a factory: cards_count is a
     * denormalised column maintained alongside inserts, and recountCards() is
     * the model's own way of reconciling it.
     */
    private function makeSet(int $cards, ?User $owner = null, string $visibility = 'public'): StudySet
    {
        $owner ??= User::factory()->create();

        $set = StudySet::create([
            'slug' => 'set-'.str()->random(10),
            'user_id' => $owner->getKey(),
            'title' => 'Test set',
            'visibility' => $visibility,
        ]);

        for ($i = 1; $i <= $cards; $i++) {
            Card::create([
                'study_set_id' => $set->getKey(),
                'position' => $i,
                'term' => "term {$i}",
                'definition' => "definition {$i}",
            ]);
        }

        $set->recountCards();

        return $set->refresh();
    }

    /** The index of the right answer, read from the database rather than the page. */
    private function correctChoice(QuizQuestion $question): int
    {
        return array_search($question->expected_answer, $question->options(), true);
    }

    private function wrongChoice(QuizQuestion $question): int
    {
        foreach ($question->options() as $i => $option) {
            if ($option !== $question->expected_answer) {
                return $i;
            }
        }

        return 0;
    }

    public function test_guests_cannot_start_a_quiz(): void
    {
        $set = $this->makeSet(5);

        $this->get(route('sets.quiz.multiple_choice', $set))
            ->assertRedirect(route('login'));
    }

    public function test_starting_issues_every_question_up_front(): void
    {
        $set = $this->makeSet(20);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.quiz.multiple_choice', $set));

        $session = QuizSession::sole();

        // questions_total is written at issue time, so it must match the rows
        // that exist — nothing later adds questions to a session.
        $this->assertSame(10, $session->questions_total);
        $this->assertSame(10, $session->questions()->count());

        foreach ($session->questions as $question) {
            $this->assertNotEmpty($question->expected_answer);
            $this->assertContains($question->expected_answer, $question->options());
            $this->assertCount(4, $question->options());
            $this->assertNull($question->answered_at);
            $this->assertTrue($question->expires_at->isFuture());
        }
    }

    public function test_a_session_never_asks_the_same_term_twice(): void
    {
        $set = $this->makeSet(4);

        // The legacy import left duplicate card rows in some sets. A duplicate
        // must not become two questions about the same term — in true/false
        // that produces contradictory claims in one run.
        Card::create([
            'study_set_id' => $set->getKey(),
            'position' => 99,
            'term' => 'term 1',
            'definition' => 'definition 1',
        ]);
        $set->recountCards();

        $this->actingAs(User::factory()->create())
            ->get(route('sets.quiz.multiple_choice', $set));

        $terms = QuizSession::sole()->questions()->with('card:id,term')->get()
            ->map(fn ($q) => $q->card->term);

        $this->assertSame($terms->unique()->count(), $terms->count());
    }

    public function test_the_page_never_reveals_which_option_is_correct(): void
    {
        $set = $this->makeSet(8);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();

        $html = $this->actingAs($user)->get(route('quiz.play', $session))->getContent();

        // The correct answer is on the page — it's one of the options. What
        // must not be there is anything marking it as correct: the form posts
        // an index, and no attribute names the expected value.
        $this->assertStringNotContainsString('expected_answer', $html);
        $this->assertStringNotContainsString('is_correct', $html);
    }

    public function test_a_correct_answer_is_recorded_and_counted(): void
    {
        $set = $this->makeSet(8);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();
        $question = $session->questions()->ordered()->first();

        $this->actingAs($user)->post(route('quiz.answer', $session), [
            'question_id' => $question->getKey(),
            'choice' => $this->correctChoice($question),
        ])->assertRedirect(route('quiz.play', $session));

        $question->refresh();
        $session->refresh();

        $this->assertTrue($question->is_correct);
        $this->assertNotNull($question->answered_at);
        $this->assertSame($question->expected_answer, $question->submitted_answer);
        $this->assertSame(1, $session->questions_answered);
        $this->assertSame(1, $session->correct_count);

        // Aggregate difficulty: this card was tested and not missed.
        $card = Card::find($question->card_id);
        $this->assertSame(1, $card->times_tested);
        $this->assertSame(0, $card->times_wrong);
    }

    public function test_a_wrong_answer_increments_the_cards_miss_counter(): void
    {
        $set = $this->makeSet(8);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();
        $question = $session->questions()->ordered()->first();

        $this->actingAs($user)->post(route('quiz.answer', $session), [
            'question_id' => $question->getKey(),
            'choice' => $this->wrongChoice($question),
        ]);

        $question->refresh();

        $this->assertFalse($question->is_correct);
        $this->assertSame(0, $session->refresh()->correct_count);

        $card = Card::find($question->card_id);
        $this->assertSame(1, $card->times_tested);
        $this->assertSame(1, $card->times_wrong);
    }

    /**
     * The old site's exploit, restated as a test: nothing tied a points claim
     * to a question, so a loop could farm the leaderboard. Here the second
     * submission of a question must change nothing at all.
     */
    public function test_answering_the_same_question_twice_counts_once(): void
    {
        $set = $this->makeSet(8);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();
        $question = $session->questions()->ordered()->first();
        $choice = $this->correctChoice($question);

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($user)->post(route('quiz.answer', $session), [
                'question_id' => $question->getKey(),
                'choice' => $choice,
            ]);
        }

        $session->refresh();

        $this->assertSame(1, $session->questions_answered);
        $this->assertSame(1, $session->correct_count);
        $this->assertSame(1, Card::find($question->card_id)->times_tested);
    }

    public function test_an_option_index_that_was_never_offered_is_ignored(): void
    {
        $set = $this->makeSet(8);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();
        $question = $session->questions()->ordered()->first();

        // The client posts an index into presented_options, so it cannot
        // submit answer text that was never on the page — and an index past
        // the end is a no-op rather than a crash.
        $this->actingAs($user)->post(route('quiz.answer', $session), [
            'question_id' => $question->getKey(),
            'choice' => 99,
        ])->assertRedirect(route('quiz.play', $session));

        $this->assertNull($question->refresh()->answered_at);
        $this->assertSame(0, $session->refresh()->questions_answered);
    }

    public function test_a_question_from_another_session_cannot_be_answered(): void
    {
        $user = User::factory()->create();
        $mine = $this->makeSet(8);
        $theirs = $this->makeSet(8);

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $mine));
        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $theirs));

        [$sessionA, $sessionB] = QuizSession::orderBy('id')->get()->all();
        $foreign = $sessionB->questions()->ordered()->first();

        $this->actingAs($user)->post(route('quiz.answer', $sessionA), [
            'question_id' => $foreign->getKey(),
            'choice' => $this->correctChoice($foreign),
        ]);

        $this->assertNull($foreign->refresh()->answered_at);
        $this->assertSame(0, $sessionA->refresh()->questions_answered);
    }

    /** 404, not 403 — someone else's session shouldn't be confirmed to exist. */
    public function test_another_users_session_is_not_found(): void
    {
        $set = $this->makeSet(8);

        $this->actingAs(User::factory()->create())->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();

        $this->actingAs(User::factory()->create())
            ->get(route('quiz.play', $session))
            ->assertNotFound();
    }

    public function test_an_expired_question_is_scored_where_it_was_left(): void
    {
        $set = $this->makeSet(8);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();

        QuizQuestion::where('quiz_session_id', $session->getKey())
            ->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($user)->get(route('quiz.play', $session))
            ->assertRedirect(route('quiz.result', $session));

        $this->assertNotNull($session->refresh()->completed_at);

        // A session is capped by the set as well as by QUESTIONS_PER_SESSION —
        // eight cards means eight questions — so this counts against the rows
        // that exist rather than a hardcoded ten.
        $this->assertSame(8, $session->questions()->count());

        // is_correct stays null on every one of them: never answered is not
        // the same as answered wrong.
        $this->assertSame(8, $session->questions()->whereNull('is_correct')->count());
        $this->assertSame(0, $session->questions_answered);
    }

    public function test_starting_again_resumes_the_session_in_progress(): void
    {
        $set = $this->makeSet(8);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $first = QuizSession::sole();

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set))
            ->assertRedirect(route('quiz.play', $first));

        // Without resuming, every press of the button would strand a session.
        $this->assertSame(1, QuizSession::count());
    }

    public function test_a_set_with_one_card_cannot_be_quizzed(): void
    {
        $set = $this->makeSet(1);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.quiz.multiple_choice', $set))
            ->assertRedirect(route('sets.show', $set))
            ->assertSessionHas('quiz.error');

        $this->assertSame(0, QuizSession::count());
    }

    public function test_a_private_set_cannot_be_quizzed_by_a_stranger(): void
    {
        $set = $this->makeSet(8, visibility: 'private');

        $this->actingAs(User::factory()->create())
            ->get(route('sets.quiz.multiple_choice', $set))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- true/false

    public function test_true_false_balances_its_claims(): void
    {
        $set = $this->makeSet(20);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.quiz.true_false', $set));

        $answers = QuizSession::sole()->questions()->pluck('expected_answer');

        // A coin flip per question produces a run of eight trues often enough
        // that "always answer True" becomes a viable strategy.
        $this->assertSame(5, $answers->filter(fn ($a) => $a === 'True')->count());
        $this->assertSame(5, $answers->filter(fn ($a) => $a === 'False')->count());
    }

    public function test_true_false_stores_the_claim_it_rendered(): void
    {
        $set = $this->makeSet(20);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.quiz.true_false', $set));

        foreach (QuizSession::sole()->questions()->with('card')->get() as $question) {
            $this->assertTrue($question->isTrueFalse());
            $this->assertFalse($question->isMultipleChoice());
            $this->assertSame(['True', 'False'], $question->options());
            $this->assertNotNull($question->claim());

            // The claim is what makes the result page reviewable: a true/false
            // session without it is True/False against True/False.
            $matches = $question->claim() === $question->card->definition;
            $this->assertSame($matches, $question->expected_answer === 'True');
        }
    }
}
