<?php

namespace App\Providers;

use App\Support\MailDevelopmentLogger;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useBootstrapFive();

        if ($this->app->environment('local')) {
            MailDevelopmentLogger::register();
        }

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
