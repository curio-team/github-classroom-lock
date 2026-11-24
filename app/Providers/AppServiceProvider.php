<?php

namespace App\Providers;

use App\GitHub\GitHub;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(GitHub::class, fn () => new GitHub(config('github')));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        setlocale(LC_ALL, config('app.locale'));

        // Invite links are public: limit how fast they can be hammered. A whole
        // classroom shares one IP (NAT) and each join takes ~4 requests, so
        // the per-IP limit is generous; per-session limits catch single abusers.
        RateLimiter::for('join', function (Request $request) {
            return [
                Limit::perMinute(300)->by('ip:' . $request->ip()),
                Limit::perMinute(30)->by('session:' . $request->session()->getId()),
            ];
        });
    }
}
