<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Providers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\BulkOffloadHandler;
use Advanced_Media_Offloader\CLI\OffloadCommand;
use Advanced_Media_Offloader\Offloader;
use AdvancedMediaOffloader\Foundation\Container;
use AdvancedMediaOffloader\Bulk\BulkOperationLock;
use AdvancedMediaOffloader\Bulk\BulkQueueStore;
use AdvancedMediaOffloader\Bulk\BulkRunStore;
use AdvancedMediaOffloader\Bulk\BulkOffloadService;
use AdvancedMediaOffloader\Bulk\BulkTaskRunner;
use AdvancedMediaOffloader\Bulk\BulkWorkerRequest;
use AdvancedMediaOffloader\Foundation\ServiceProvider;
use AdvancedMediaOffloader\Media\AttachedFileObserver;
use AdvancedMediaOffloader\Offload\AttachmentActions;
use AdvancedMediaOffloader\Offload\AttachmentOperationLock;

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
        $this->app->singleton(AttachmentActions::class);
        $this->app->singleton(AttachmentOperationLock::class, function () {
            global $wpdb;

            return new AttachmentOperationLock($wpdb);
        });

        $this->app->singleton(BulkRunStore::class);
        $this->app->singleton(BulkOffloadService::class);
        $this->app->singleton(BulkTaskRunner::class);
        $this->app->singleton(BulkWorkerRequest::class);
        $this->app->singleton(BulkQueueStore::class, function () {
            global $wpdb;
            return new BulkQueueStore($wpdb);
        });
        $this->app->singleton(BulkOperationLock::class, function () {
            global $wpdb;
            return new BulkOperationLock($wpdb, static fn () => new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST));
        });
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
