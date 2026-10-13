<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Providers;

use Advanced_Media_Offloader\Admin\GeneralSettings;
use Advanced_Media_Offloader\Admin\MediaOverview;
use Advanced_Media_Offloader\Admin\Observers\CurrentScreen;
use Advanced_Media_Offloader\Admin\Observers\WelcomeNotice;
use Advanced_Media_Offloader\Admin\SupportPage;
use Advanced_Media_Offloader\Factories\CloudProviderFactory;
use AdvancedMediaOffloader\Foundation\Container;
use AdvancedMediaOffloader\Foundation\ServiceProvider;

class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeneralSettings::class, function (Container $app) {
            return GeneralSettings::create($app->get(CloudProviderFactory::class));
        });
        $this->app->alias(GeneralSettings::class, 'settings_page');

        $this->app->singleton(MediaOverview::class, fn () => MediaOverview::getInstance());
        $this->app->alias(MediaOverview::class, 'media_overview_page');

        $this->app->singleton(SupportPage::class, fn () => SupportPage::getInstance());
        $this->app->alias(SupportPage::class, 'support_page');
    }

    public function boot(): void
    {
        if (!is_admin()) {
            return;
        }

        $this->app->get(GeneralSettings::class);
        $this->app->get(MediaOverview::class);
        $this->app->get(SupportPage::class);
        new CurrentScreen();
        WelcomeNotice::getInstance();

        add_filter('plugin_action_links_' . $this->app->basename(), [self::class, 'pluginActionLinks']);
    }

    public static function pluginActionLinks($links): array
    {
        $links = (array) $links;
        $settings_page_link = '<a href="' . esc_url(admin_url('admin.php?page=advmo')) . '">' . __('Settings', 'advanced-media-offloader') . '</a>';
        array_unshift($links, $settings_page_link);

        if (apply_filters('advmo_show_donate_links', true)) {
            $donate_url = add_query_arg(array(
                'utm_source'   => 'wp-plugin',
                'utm_medium'   => 'plugins-list',
                'utm_campaign' => 'advanced-media-offloader',
                'utm_content'  => 'plugin-row-donate',
            ), 'https://buymeacoffee.com/wpfitter');
            $links[] = '<a href="' . esc_url($donate_url) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__('Donate to support Advanced Media Offloader', 'advanced-media-offloader') . '">' . esc_html__('Donate', 'advanced-media-offloader') . '</a>';
        }

        return $links;
    }
}
