<?php namespace LDing\LaravelCategory;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations'); // load package migrations
    }

    public function register()
    {
        //
    }
}
