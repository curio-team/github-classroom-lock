<?php

use App\Http\Controllers\JoinController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 *
 * SD Client Auth (staff)
 *
 */
Route::get('/login', function () {
    return redirect('/sdclient/redirect');
})->name('login');

Route::post('/logout', function (Request $request) {
    Auth::logout();

    return redirect('/sdclient/logout');
})->name('logout');

Route::get('/sdclient/ready', function () {
    return redirect()->route('dashboard');
});

/**
 *
 * Student onboarding (invite link + GitHub sign-in). No SD login required.
 *
 */
Route::middleware('throttle:join')->group(function () {
    // Registered before /join/{token} so "github" isn't treated as a token.
    Route::get('/join/github/callback', [JoinController::class, 'callback'])->name('join.callback');
    Route::get('/join/{token}', [JoinController::class, 'show'])->name('join.show');
    Route::post('/join/{token}/github', [JoinController::class, 'redirect'])->name('join.redirect');
    Route::get('/join/{token}/status', [JoinController::class, 'status'])->name('join.status');
});

/**
 *
 * App
 *
 */
Route::middleware(['guest'])->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function (Request $request) {
        if ($request->user()->isTeacher()) {
            return redirect()->route('exams.index');
        }

        return view('no-access');
    })->name('dashboard');

    Route::middleware(['teacher'])
        ->prefix('teacher')
        ->group(function () {
            Route::get('/exams', \App\Livewire\ExamIndexPage::class)
                ->name('exams.index');

            Route::get('/exams/{cohort}', \App\Livewire\ExamPage::class)
                ->name('exams.show');

            Route::get('/exams/{cohort}/backup', [\App\Http\Controllers\BackupController::class, 'download'])
                ->name('exams.backup');

            Route::get('/audit-log', \App\Livewire\AuditLogPage::class)
                ->name('audit-log');
        });
});
