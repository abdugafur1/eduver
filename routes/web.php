<?php

use App\Http\Controllers\StudySetController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/sets');

Route::get('/sets', [StudySetController::class, 'index'])->name('sets.index');
Route::get('/sets/{studySet}', [StudySetController::class, 'show'])->name('sets.show');
