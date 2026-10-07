<?php

use Modules\Core\Features\RabbitMQ\RabbitMQServiceProvider;

return [
    'name' => 'Core',

    /*
    |--------------------------------------------------------------------------
    | Enabled features
    |--------------------------------------------------------------------------
    |
    | Each entry is a feature's service provider (a subclass of
    | Modules\Core\Kernel\Support\FeatureServiceProvider) living under
    | app/Features/<Name>/. Removing a line disables that feature entirely:
    | its commands, migrations and config.
    |
    */
    'features' => [
        RabbitMQServiceProvider::class,
    ],
];
