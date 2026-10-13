<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Providers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Factories\CloudProviderFactory;
use AdvancedMediaOffloader\Foundation\Container;
use AdvancedMediaOffloader\Foundation\ServiceProvider;
use AdvancedMediaOffloader\Storage\ProviderRegistry;
use Exception;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);

        $this->app->singleton(CloudProviderFactory::class);
        $this->app->alias(CloudProviderFactory::class, 'cloud_provider_factory');

        // The provider picked in settings, or null while none is configured or the saved one cannot be loaded
        // (an add-on that added it was removed, or the option is damaged). The site must keep loading either way.
        $this->app->singleton(S3_Provider::class, function (Container $app) {
            $key = advmo_get_cloud_provider_key();

            if ($key === '') {
                return null;
            }

            try {
                return $app->get(ProviderRegistry::class)->make($key);
            } catch (Exception $e) {
                return null;
            }
        });
        $this->app->alias(S3_Provider::class, 'cloud_provider');
    }

    public function boot(): void
    {
        if (is_admin() && advmo_get_cloud_provider_key() !== '' && $this->app->get(S3_Provider::class) === null) {
            add_action('admin_notices', [$this, 'unavailableProviderNotice']);
        }
    }

    public function unavailableProviderNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
            esc_html(sprintf(
                /* translators: %s: the cloud provider key saved in the settings */
                __('Advanced Media Offloader cannot load the saved cloud provider "%s", so media is not offloaded or served from cloud storage. Choose a cloud provider to fix this.', 'advanced-media-offloader'),
                advmo_get_cloud_provider_key()
            )),
            esc_url(admin_url('admin.php?page=advmo')),
            esc_html__('Open the settings', 'advanced-media-offloader')
        );
    }
}
