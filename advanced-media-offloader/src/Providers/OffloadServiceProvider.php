<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Providers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\BulkOffloadHandler;
use Advanced_Media_Offloader\CLI\OffloadCommand;
use Advanced_Media_Offloader\Offloader;
use AdvancedMediaOffloader\Foundation\Container;
use AdvancedMediaOffloader\Foundation\ServiceProvider;
use AdvancedMediaOffloader\Media\AttachedFileObserver;

class OffloadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Offloader::class, function (Container $app) {
            $provider = $app->get(S3_Provider::class);

            return $provider ? Offloader::get_instance($provider) : null;
        });
        $this->app->alias(Offloader::class, 'offloader');

        $this->app->singleton(AttachedFileObserver::class);

        $this->app->singleton(BulkOffloadHandler::class);
        $this->app->alias(BulkOffloadHandler::class, 'bulk_offload_handler');
    }

    public function boot(): void
    {
        $this->app->get(Offloader::class)?->initializeHooks();
        $this->app->get(BulkOffloadHandler::class);

        if ($this->app->runningInConsole()) {
            \WP_CLI::add_command('advmo offload', OffloadCommand::class);
        }
    }
}
