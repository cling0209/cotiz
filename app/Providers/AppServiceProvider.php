<?php

namespace App\Providers;

use App\Services\NotaService;
use App\Support\MailDevelopmentLogger;
use App\Support\RenderKeepAlive;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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
        Paginator::useBootstrapFive();

        if ($this->app->environment('local')) {
            MailDevelopmentLogger::register();
        }

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Entre jobs del queue:work: mantiene despierto Render free mientras procesa.
        Queue::looping(static function (): void {
            RenderKeepAlive::pingIfDue();
        });

        View::composer('layouts.admin', function ($view) {
            $pendiente = null;

            if (auth()->check()) {
                $notaService = app(NotaService::class);
                $pendiente = $notaService->pendienteSinNumeroCotizacion(auth()->user()->username);
            }

            $view->with('cotizacionPendienteSinNumero', $pendiente);
        });
    }
}
