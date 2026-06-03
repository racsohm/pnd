<?php

namespace App\Providers;

use App\Models\Backup;
use App\Policies\BackupPolicy;
use App\Services\InstanceDiscovery;
use App\Services\MongoBackupService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Backup::class, BackupPolicy::class);
        Paginator::useTailwind();
    }
}
