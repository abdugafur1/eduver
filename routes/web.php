<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\StudySetController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\CardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/sets');

Route::get('/sets', [StudySetController::class, 'index'])->name('sets.index');

/*
        | Public, like sets.index. The board is a reason to make an account,
        | which it can't be if you need one to see it. Guests get the same rows;
        | they just have no row of their own highlighted.
        |
        | The rail already does Route::has('leaderboard.index'), so defining
        | this swaps the greyed-out trophy stub for a live link on every page —
        | no layout change needed.
        */
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

/*
 | sets.show is flashcards, not the card table. Two reasons it's the canonical
 | view rather than a mode picker:
 |
 |   - quiz_sessions.user_id is constrained() and not nullable, so multiple
 |     choice, written and true/false all require an account. A picker would
 |     land guests on four buttons, three of which they can't press.
 |   - flashcards writes nothing — no session, no counters, no last_studied_at
 |     — so it's safe to serve to anyone who can read the set.
 |
 | Every existing route('sets.show', $set) in the browse tiles keeps working;
 | the old table now lives at sets.terms. The more specific URIs are declared
 | first so they aren't swallowed by the /sets/{studySet} wildcard.
 */
Route::get('/sets/{studySet}/terms', [StudySetController::class, 'terms'])->name('sets.terms');

Route::middleware(['auth', 'password.usable'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');


    Route::get('/sets/mine', [StudySetController::class, 'mine'])->name('sets.mine');

    /*
     | The library: sets saved explicitly, plus every set a completed quiz has
     | stamped. Declared here with the other /sets routes and inside the group,
     | so the wildcard at the bottom of the file can't swallow "saved".
     */
    Route::get('/sets/saved', [StudySetController::class, 'saved'])->name('sets.saved');
    Route::post('/sets/{studySet}/save', [StudySetController::class, 'save'])->name('sets.save');
    Route::delete('/sets/{studySet}/save', [StudySetController::class, 'unsave'])->name('sets.unsave');

    Route::get('/sets/create', [StudySetController::class, 'create'])->name('sets.create');
    Route::post('/sets', [StudySetController::class, 'store'])->name('sets.store');
    Route::get('/sets/{studySet}/edit', [StudySetController::class, 'edit'])->name('sets.edit');
    Route::patch('/sets/{studySet}', [StudySetController::class, 'update'])->name('sets.update');
    Route::delete('/sets/{studySet}', [StudySetController::class, 'destroy'])->name('sets.destroy');


    Route::get('/cards/create', [CardController::class, 'chooseSet'])->name('cards.create');
    Route::get('/sets/{studySet}/cards/create', [CardController::class, 'create'])->name('sets.cards.create');
    Route::post('/sets/{studySet}/cards', [CardController::class, 'store'])->name('sets.cards.store');
    Route::get('/cards/{card}/edit', [CardController::class, 'edit'])->name('cards.edit');
    Route::patch('/cards/{card}', [CardController::class, 'update'])->name('cards.update');
    Route::delete('/cards/{card}', [CardController::class, 'destroy'])->name('cards.destroy');

    /*
     | Starting a quiz is keyed by set; playing one is keyed by session. They're
     | separate resources because a session outlives the page that opened it —
     | you can leave and come back, and start() will resume rather than reissue.
     |
     | The mode-strip partial does Route::has('sets.quiz.{mode}'), so adding
     | true/false later is a route here plus a case in the controller. The
     | strip lights up on its own.
     */
    Route::get('/sets/{studySet}/quiz/multiple-choice', [QuizController::class, 'startMultipleChoice'])
        ->name('sets.quiz.multiple_choice');

    Route::get('/sets/{studySet}/quiz/true-false', [QuizController::class, 'startTrueFalse'])
        ->name('sets.quiz.true_false');

    Route::get('/quiz/{quizSession}', [QuizController::class, 'play'])->name('quiz.play');
    Route::post('/quiz/{quizSession}', [QuizController::class, 'answer'])->name('quiz.answer');
    Route::get('/quiz/{quizSession}/result', [QuizController::class, 'result'])->name('quiz.result');
});

Route::get('/sets/{studySet}', [StudySetController::class, 'show'])->name('sets.show');

require __DIR__.'/auth.php';
