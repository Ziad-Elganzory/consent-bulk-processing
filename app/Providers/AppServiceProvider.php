<?php

namespace App\Providers;

use App\Domains\BulkImport\Messaging\BulkImportMessaging;
use App\Domains\BulkImport\Services\Validation\ConsentRowRules;
use App\Domains\BulkImport\Services\Validation\RowRules;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Kernel\Support\ContainerTags;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RowRules::class, ConsentRowRules::class);
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
