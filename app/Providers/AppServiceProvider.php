<?php

namespace App\Providers;

use App\Support\Acl;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('acl', fn () => new Acl());
        $this->app->alias('acl', Acl::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // @can_do('master/item', 'edit') ... @endcan_do
        Blade::if('can_do', function (string $path, string $ability = 'view') {
            return app('acl')->canRoute($path, $ability);
        });
    }
}
