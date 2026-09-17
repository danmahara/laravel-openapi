<?php

namespace Danmahara\LaravelOpenApi;

use Danmahara\LaravelOpenApi\Console\GenerateOpenApiCommand;
use Illuminate\Support\ServiceProvider;

class LaravelOpenApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/openapi.php', 'openapi');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-openapi');

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateOpenApiCommand::class]);

            $this->publishes([
                __DIR__.'/../config/openapi.php' => config_path('openapi.php'),
            ], 'openapi-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-openapi'),
            ], 'openapi-views');
        }
    }
}
