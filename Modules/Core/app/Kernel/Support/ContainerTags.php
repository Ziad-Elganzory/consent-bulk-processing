<?php

namespace Modules\Core\Kernel\Support;

/**
 * Container tags modules and features register into, and Core collects from.
 *
 * Registering through a tag (instead of listing classes in Core's config) keeps
 * Core unaware of which modules exist.
 */
final class ContainerTags
{
    /**
     * Filament\Contracts\Plugin classes added to the admin panel.
     */
    public const FILAMENT_PLUGINS = 'core.filament.plugins';

    /**
     * ModuleMessaging classes: each module's exchanges and queues, collected by the
     * RabbitMQ feature's MessagingRegistry.
     */
    public const MESSAGING = 'core.messaging';
}
