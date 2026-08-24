<?php

namespace Tests\Concerns;

use App\Models\QuizSession;
use App\Models\StudySet;
use App\Models\User;

/**
 * Playing a quiz for real, for tests that need what one leaves behind.
 *
 * Lifted out of QuizRewardsTest when QuestTest needed a genuine
 * daily_activities row rather than a hand-written one. The distinction
 * matters: a fixture agrees with whatever the test author believed the
 * column was called, and Quests::QUESTS names its metric in a string. Only a
 * settled session proves the two ends are talking about the same counter.
 */
trait PlaysQuizzes
{
    /**
     * Play a whole session correctly and return it, settled.
     *
     * Answers are read from the database, which is the only place the correct
     * one exists — the same reason a real client can't do this.
     */
    protected function playPerfectly(User $user, StudySet $set, string $route = 'sets.quiz.multiple_choice'): QuizSession
    {
        $this->actingAs($user)->get(route($route, $set));

        $session = QuizSession::query()
            ->where('user_id', $user->getKey())
            ->where('study_set_id', $set->getKey())
            ->whereNull('completed_at')
            ->latest('id')
            ->sole();

        foreach ($session->questions()->ordered()->get() as $question) {
            $this->actingAs($user)->post(route('quiz.answer', $session), [
                'question_id' => $question->getKey(),
                'choice' => array_search($question->expected_answer, $question->options(), true),
            ]);
        }

        // Settlement happens when play() finds no unanswered question left.
        $this->actingAs($user)->get(route('quiz.play', $session));

        return $session->refresh();
    }
}
