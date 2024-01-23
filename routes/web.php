<?php

use App\Settings\GeneralSettings;
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
    Route::get('/dashboard', \App\Http\Livewire\DashboardPage::class)
        ->name('dashboard');
});

Route::get('/gpt', function(GeneralSettings $settings) {
    return view('gpt')->with('isChatActive', $settings->chat_active);
})->name('gpt');

Route::post('ai-request', [\App\Http\Controllers\ApiController::class, 'performPrompt'])->name('ai-request');
