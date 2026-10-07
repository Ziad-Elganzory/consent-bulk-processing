<?php

namespace Modules\Core\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Core';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'core';

    /**
     * Merge Core's config, then register the module's own providers and every enabled feature.
     *
     * nwidart only merges module config during boot(), which is too late for the feature
     * list, so Core merges its config here, before anything reads it.
     */
    public function register(): void
    {
        $this->registerConfig();

        parent::register();

        foreach (config('core.features', []) as $feature) {
            $this->app->register($feature);
        }
    }
}
