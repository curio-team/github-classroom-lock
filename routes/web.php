<?php

use App\Settings\ChatSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/*
    AmoClient Auth
*/

Route::get('/login', function(){
	return redirect('/amoclient/redirect');
})->name('login');

Route::post('/logout', function(Request $request){
    Auth::logout();

	return redirect('/amoclient/logout');
})->name('logout');

Route::get('/amoclient/ready', function(){
	return redirect()->route('dashboard');
});

/*
    App
*/

Route::middleware(['guest'])->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function(Request $request) {
        if($request->user()->isTeacher()) {
            return redirect()->route('dashboard.teacher');
        } else {
            return redirect()->route('dashboard.student');
        }
    })->name('dashboard');

    Route::middleware(['teacher'])
        ->prefix('teacher')
        ->group(function () {
            Route::get('/dashboard', \App\Http\Livewire\TeacherDashboardPage::class)
                ->name('dashboard.teacher');
        });

    Route::prefix('student')
    ->group(function () {
        Route::get('/dashboard', \App\Http\Livewire\StudentDashboardPage::class)
            ->name('dashboard.student');
    });

});

Route::post('ai-request', [\App\Http\Controllers\ApiController::class, 'performPrompt'])->name('ai-request');
