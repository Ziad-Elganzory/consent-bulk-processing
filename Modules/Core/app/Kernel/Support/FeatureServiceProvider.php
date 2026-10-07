<?php

namespace Modules\Core\Kernel\Support;

use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for a self-contained Core feature under app/Features/<Name>/.
 *
 * Everything the feature owns is loaded by convention, relative to the folder the
 * concrete provider lives in:
 *
 *   config/<key>.php       -> config('core.<key>.*')
 *   database/migrations/   -> run with the app's migrations
 *   lang/                  -> __('core-<key>::file.key')
 *   resources/views/       -> view('core-<key>::name')
 *   $filamentPlugin        -> tagged for the admin panel to pick up
 *
 * Features expose no API: they are used from code and from the admin panel only.
 *
 * A feature may depend on Modules\Core\Kernel, never on another feature.
 */
abstract class FeatureServiceProvider extends ServiceProvider
{
    /**
     * Filament plugin class for this feature, if it contributes to the admin panel.
     */
    protected ?string $filamentPlugin = null;

    /**
     * Short snake_case key: the config key, translation/view namespace suffix.
     */
    abstract protected function key(): string;

    public function register(): void
    {
        if (is_file($config = $this->featurePath("config/{$this->key()}.php"))) {
            $this->mergeConfigFrom($config, "core.{$this->key()}");
        }

        if ($this->filamentPlugin !== null) {
            $this->app->tag([$this->filamentPlugin], ContainerTags::FILAMENT_PLUGINS);
        }
    }

    public function boot(): void
    {
        if (is_dir($migrations = $this->featurePath('database/migrations'))) {
            $this->loadMigrationsFrom($migrations);
        }

        if (is_dir($lang = $this->featurePath('lang'))) {
            $this->loadTranslationsFrom($lang, $this->namespace());
        }

        if (is_dir($views = $this->featurePath('resources/views'))) {
            $this->loadViewsFrom($views, $this->namespace());
        }
    }

    /**
     * Namespace for this feature's translations and views, e.g. "core-authentication".
     */
    protected function namespace(): string
    {
        return 'core-'.$this->key();
    }

    /**
     * Absolute path inside this feature's folder.
     */
    protected function featurePath(string $relative = ''): string
    {
        $dir = dirname((new ReflectionClass($this))->getFileName());

        return rtrim($dir.'/'.ltrim($relative, '/'), '/');
    }
}
