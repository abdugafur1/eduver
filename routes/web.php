<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StudySetController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/sets');

Route::get('/sets', [StudySetController::class, 'index'])->name('sets.index');
Route::get('/sets/{studySet}', [StudySetController::class, 'show'])->name('sets.show');

Route::middleware(['auth', 'password.usable'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';