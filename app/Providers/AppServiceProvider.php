<?php

namespace App\Providers;

use App\Domains\BulkImport\Messaging\BulkImportMessaging;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Kernel\Support\ContainerTags;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([BulkImportMessaging::class], ContainerTags::MESSAGING);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
