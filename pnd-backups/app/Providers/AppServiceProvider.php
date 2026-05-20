<?php

namespace App\Providers;

use App\Services\InstanceDiscovery;
use App\Services\MongoBackupService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstanceDiscovery::class);
        $this->app->singleton(MongoBackupService::class);
    }

    public function boot(): void
    {
        //
    }
}
