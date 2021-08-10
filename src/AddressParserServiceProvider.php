<?php

namespace Zifan\AddressParser;

use Illuminate\Support\ServiceProvider;

class AddressParserServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot(): void
    {
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'zifan');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'zifan');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/addressparser.php', 'addressparser');

        // Register the service the package provides.
        $this->app->singleton('addressparser', function ($app) {
            return new AddressParser;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['addressparser'];
    }

    /**
     * Console-specific booting.
     *
     * @return void
     */
    protected function bootForConsole(): void
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__.'/../config/addressparser.php' => config_path('addressparser.php'),
        ], 'addressparser.config');

        // Publishing the views.
        /*$this->publishes([
            __DIR__.'/../resources/views' => base_path('resources/views/vendor/zifan'),
        ], 'addressparser.views');*/

        // Publishing assets.
        /*$this->publishes([
            __DIR__.'/../resources/assets' => public_path('vendor/zifan'),
        ], 'addressparser.views');*/

        // Publishing the translation files.
        /*$this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/zifan'),
        ], 'addressparser.views');*/

        // Registering package commands.
        // $this->commands([]);
    }
}
