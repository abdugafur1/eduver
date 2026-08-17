<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\QuizQuestion;
use App\Models\QuizSession;
use App\Models\StudySet;
use App\Services\QuizRewards;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 | The threat model, restated from the create_quiz_tables migration because it
 | dictates the shape of this class:
 |
 | In the old site the browser decided whether an answer was right and POSTed
 | to pointsProcess.php to claim +10. Nothing tied a claim to a question, so a
 | curl loop could farm the leaderboard.
 |
 | Here every question is issued and persisted before it is rendered, and
 | expected_answer never reaches the client. A submission names a question row;
 | it is accepted only if that row belongs to the authenticated user's session,
 | has answered_at IS NULL, and has not expired. Grading compares the submitted
 | option against the stored expected_answer — never against anything the
 | browser sent about correctness.
 |
 | The accept-once guarantee is a conditional UPDATE (see answer()), not a
 | read-then-write. Two simultaneous submissions of the same question can only
 | affect one row between them.
 */
class QuizController extends Controller
{
    private const QUESTIONS_PER_SESSION = 10;

    private const OPTIONS_PER_QUESTION = 4;

    /** How long an issued question stays answerable. */
    private const QUESTION_TTL_MINUTES = 60;

    /**
     * Start or resume a multiple-choice session.
     *
     * Resuming matters: without it, every reload of the mode strip's button
     * would abandon a session and open another, and quiz_sessions would fill
     * with one-question ghosts that scopeAbandoned() has to step around.
     */
    public function startMultipleChoice(Request $request, StudySet $studySet): RedirectResponse
    {
        return $this->start($request, $studySet, QuizSession::MODE_MULTIPLE_CHOICE);
    }

    public function startTrueFalse(Request $request, StudySet $studySet): RedirectResponse
    {
        return $this->start($request, $studySet, QuizSession::MODE_TRUE_FALSE);
    }

    private function start(Request $request, StudySet $studySet, string $mode): RedirectResponse
    {
        abort_unless($studySet->isReadableBy($request->user()), 404);

        // Both modes need a second card: multiple choice for a distractor,
        // true/false for a definition to misattribute.
        if ($studySet->cards_count < 2) {
            return redirect()
                ->route('sets.show', $studySet)
                ->with('quiz.error', 'This set needs at least two cards to quiz on.');
        }

        $existing = QuizSession::query()
            ->where('user_id', $request->user()->getKey())
            ->where('study_set_id', $studySet->getKey())
            ->ofMode($mode)
            ->abandoned()
            ->whereHas('questions', fn ($q) => $q->unanswered()->where('expires_at', '>', now()))
            ->latest('id')
            ->first();

        if ($existing) {
            return redirect()->route('quiz.play', $existing);
        }

        return redirect()->route('quiz.play', $this->issueSession($request, $studySet, $mode));
    }

    /**
     * Render the first unanswered question, or finish the session if there
     * isn't one. Nothing here selects expected_answer.
     */
    public function play(Request $request, QuizSession $quizSession): View|RedirectResponse
    {
        $this->authorizeSession($request, $quizSession);

        if ($quizSession->completed_at) {
            return redirect()->route('quiz.result', $quizSession);
        }

        $question = $quizSession->questions()
            ->unanswered()
            ->ordered()
            ->first();

        if (! $question) {
            return redirect()->route('quiz.result', $this->complete($quizSession));
        }

        // Expired questions can't be answered, and every later question in the
        // session was issued at the same moment, so the whole run is dead.
        if ($question->hasExpired()) {
            return redirect()
                ->route('quiz.result', $this->complete($quizSession))
                ->with('quiz.error', 'That session timed out, so it was scored where you left it.');
        }

        $question->load('card:id,term,hint');

        return view('quiz.play', [
            'session' => $quizSession,
            'set' => $quizSession->studySet,
            'question' => $question,
            // Ordinal for display: position is 0-based and questions_answered
            // is the count behind us.
            'number' => $quizSession->questions_answered + 1,
        ]);
    }

    /**
     * Grade one question and advance.
     *
     * Post/redirect/get, with the verdict flashed forward — the next question's
     * page shows how the previous one went. Grading inline would mean either a
     * fetch endpoint or trusting the client with the answer.
     */
    public function answer(Request $request, QuizSession $quizSession): RedirectResponse
    {
        $this->authorizeSession($request, $quizSession);

        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            // The index into presented_options, not the answer text. Sending
            // text would let a client submit an option that was never offered.
            'choice' => ['required', 'integer', 'min:0'],
        ]);

        $question = $quizSession->questions()
            ->whereKey($data['question_id'])
            ->unanswered()
            ->with('card:id,term')
            ->first();

        // Already answered, expired, or not part of this session. All three are
        // the same non-event: send them back to whatever comes next.
        if (! $question || $question->hasExpired()) {
            return redirect()->route('quiz.play', $quizSession);
        }

        // options() normalises the two shapes: a plain list for multiple
        // choice, ['claim' => ..., 'options' => [...]] for true/false.
        $options = $question->options();
        $submitted = $options[$data['choice']] ?? null;

        if ($submitted === null) {
            return redirect()->route('quiz.play', $quizSession);
        }

        $isCorrect = $submitted === $question->expected_answer;

        DB::transaction(function () use ($question, $quizSession, $submitted, $isCorrect) {
            // Conditional UPDATE, not save(): whereNull('answered_at') makes
            // this idempotent under concurrent submits. If it affects 0 rows
            // someone else already scored it and we must not double-count.
            $claimed = QuizQuestion::query()
                ->whereKey($question->getKey())
                ->whereNull('answered_at')
                ->update([
                    'submitted_answer' => $submitted,
                    'is_correct' => $isCorrect,
                    'answered_at' => now(),
                    // points_awarded stays 0 until point_transactions is wired;
                    // scoring is correct without it.
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return;
            }

            $quizSession->increment('questions_answered');

            if ($isCorrect) {
                $quizSession->increment('correct_count');
            }

            // Aggregate difficulty, not per-user mastery — this says the card
            // is hard, which is what missRate() and scopeTroublesome() read.
            Card::whereKey($question->card_id)->increment('times_tested');

            if (! $isCorrect) {
                Card::whereKey($question->card_id)->increment('times_wrong');
            }
        });

        return redirect()
            ->route('quiz.play', $quizSession)
            ->with('quiz.verdict', [
                'correct' => $isCorrect,
                'expected' => $question->expected_answer,
                'submitted' => $submitted,
                // Named, because the banner renders above the *next* question:
                // without it "Correct" reads as being about the term on screen.
                'term' => $question->card?->term,
                // True/false answers are "True"/"False", which say nothing on
                // their own once the claim has scrolled away.
                'claim' => $question->claim(),
            ]);
    }

    /**
     * Scorecard plus a per-question review. Safe to show expected_answer here:
     * every question has been answered or has expired.
     */
    public function result(Request $request, QuizSession $quizSession): View
    {
        $this->authorizeSession($request, $quizSession);

        $quizSession->load([
            'studySet:id,slug,title',
            'questions' => fn ($q) => $q->ordered()->with('card:id,term'),
        ]);

        return view('quiz.result', [
            'session' => $quizSession,
            'set' => $quizSession->studySet,
        ]);
    }

    /**
     * Issue the whole run in one transaction, so questions_total always matches
     * the rows that exist.
     */
    /**
     * Issue the whole run in one transaction, so questions_total always matches
     * the rows that exist.
     */
    private function issueSession(Request $request, StudySet $studySet, string $mode): QuizSession
    {
        // id/term/definition only — no timestamps, no difficulty counters.
        $cards = $studySet->cards()->get(['id', 'term', 'definition']);

        // Distinct definitions, so a set with repeated definitions can't offer
        // two options that are both technically correct, or assert a "false"
        // pairing that happens to be true for another card.
        $pool = $cards->pluck('definition')->unique()->values();

        // unique('term') before taking: the legacy import left duplicate card
        // rows in some sets, and without this the same term can be asked twice
        // in one session — once with its real definition and once with a false
        // one, which reads as the quiz contradicting itself.
        $chosen = $cards
            ->unique('term')
            ->shuffle()
            ->take(self::QUESTIONS_PER_SESSION)
            ->values();
        $expiresAt = now()->addMinutes(self::QUESTION_TTL_MINUTES);

        // True/false only: half the questions assert a true pairing, half a
        // false one, then the assignment is shuffled. A coin flip per question
        // would sometimes produce a run of eight trues, and "always answer
        // True" would be a winning strategy often enough to matter.
        $truths = $mode === QuizSession::MODE_TRUE_FALSE
            ? collect(array_merge(
                array_fill(0, (int) ceil($chosen->count() / 2), true),
                array_fill(0, (int) floor($chosen->count() / 2), false),
            ))->shuffle()->values()
            : collect();

        return DB::transaction(function () use ($request, $studySet, $mode, $chosen, $pool, $truths, $expiresAt) {
            $session = QuizSession::create([
                'user_id' => $request->user()->getKey(),
                'study_set_id' => $studySet->getKey(),
                'mode' => $mode,
                'questions_total' => $chosen->count(),
                'started_at' => now(),
            ]);

            foreach ($chosen as $position => $card) {
                $payload = $mode === QuizSession::MODE_TRUE_FALSE
                    ? $this->trueFalsePayload($card, $pool, (bool) $truths[$position])
                    : $this->multipleChoicePayload($card, $pool);

                $session->questions()->create([
                    'card_id' => $card->id,
                    'position' => $position,
                    'presented_options' => $payload['presented_options'],
                    'expected_answer' => $payload['expected_answer'],
                    'issued_at' => now(),
                    'expires_at' => $expiresAt,
                ]);
            }

            return $session;
        });
    }

    /** The correct definition plus up to three distractors, shuffled. */
    private function multipleChoicePayload(Card $card, $pool): array
    {
        $options = $pool
            ->reject(fn (string $d) => $d === $card->definition)
            ->shuffle()
            ->take(self::OPTIONS_PER_QUESTION - 1)
            ->push($card->definition)
            ->shuffle()
            ->values()
            ->all();

        return [
            'presented_options' => $options,
            'expected_answer' => $card->definition,
        ];
    }

    /**
     * A claim about this card, true or false as instructed.
     *
     * The claim is stored alongside the options because presented_options is
     * defined as what was actually rendered — without it the result page could
     * not show what the question asserted, only whether it was answered right.
     *
     * A set whose definitions are all identical can't produce a false claim;
     * that falls back to a true one rather than asserting something correct
     * and marking it wrong.
     */
    private function trueFalsePayload(Card $card, $pool, bool $shouldBeTrue): array
    {
        $wrong = $pool->reject(fn (string $d) => $d === $card->definition);

        $isTrue = $shouldBeTrue || $wrong->isEmpty();

        $claim = $isTrue ? $card->definition : $wrong->random();

        return [
            'presented_options' => [
                'claim' => $claim,
                'options' => ['True', 'False'],
            ],
            'expected_answer' => $isTrue ? 'True' : 'False',
        ];
    }

    /**
     * Finish and pay out. Safe to call on an already-finished session: the
     * service claims completed_at with a conditional UPDATE, so a second call
     * writes no second ledger row.
     */
    private function complete(QuizSession $quizSession): QuizSession
    {
        $quizSession->loadMissing('studySet:id,cards_count');

        return app(QuizRewards::class)->settle($quizSession);
    }

    /**
     * 404 rather than 403: a session belonging to someone else shouldn't be
     * confirmed to exist.
     */
    private function authorizeSession(Request $request, QuizSession $quizSession): void
    {
        abort_unless($quizSession->user_id === $request->user()?->getKey(), 404);
    }
}
