<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Foundation;

/**
 * register() only binds things. boot() runs once every provider is registered, Pro's included,
 * so it is the place to resolve services and add hooks.
 */
abstract class ServiceProvider
{
    public function __construct(protected Application $app)
    {
    }

    abstract public function register(): void;

    public function boot(): void
    {
    }
}
