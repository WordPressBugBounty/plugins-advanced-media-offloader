<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Providers;

use AdvancedMediaOffloader\Foundation\ServiceProvider;
use AdvancedMediaOffloader\Media\Contracts\AttachmentRepository;
use AdvancedMediaOffloader\Media\Repositories\PostMetaAttachmentRepository;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttachmentRepository::class, function () {
            global $wpdb;

            return new PostMetaAttachmentRepository($wpdb);
        });
    }
}
