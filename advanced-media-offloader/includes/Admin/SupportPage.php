<?php

namespace Advanced_Media_Offloader\Admin;

/**
 * "Need help?" page.
 *
 * Ordered by what actually resolves a problem fastest: check the things that
 * cause most reports, then read the docs, and only then write to a human —
 * with a ready-made report so the first reply can be an answer instead of a
 * request for more details.
 */
class SupportPage
{
    private const FORUM_URL   = 'https://wordpress.org/support/plugin/advanced-media-offloader/';
    private const CONTACT_URL = 'https://wpfitter.com/contact/';
    private const REVIEW_URL  = 'https://wordpress.org/support/plugin/advanced-media-offloader/reviews/#new-post';
    private const CLI_DOC_URL = 'https://wpfitter.com/blog/advmo-bulk-offload-with-wp-cli/';
    private const DONATE_URL  = 'https://buymeacoffee.com/wpfitter';

    private static $instance = null;

    private function __construct()
    {
        $this->register();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'advmo',
            __('Need Support?', 'advanced-media-offloader'),
            __('Need Support?', 'advanced-media-offloader'),
            'manage_options',
            'advmo_support',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        advmo_get_view('admin/support');
    }

    /**
     * Utm-tagged outbound link, so we can tell which page sent someone.
     */
    public static function link(string $url, string $content): string
    {
        return add_query_arg(
            [
                'utm_source'   => 'wp-plugin',
                'utm_medium'   => 'support-page',
                'utm_campaign' => 'advanced-media-offloader',
                'utm_content'  => $content,
            ],
            $url
        );
    }

    public static function forum_url(): string
    {
        return self::FORUM_URL;
    }

    public static function contact_url(): string
    {
        return self::link(self::CONTACT_URL, 'contact');
    }

    public static function review_url(): string
    {
        return self::REVIEW_URL;
    }

    public static function cli_doc_url(): string
    {
        return self::link(self::CLI_DOC_URL, 'wp-cli-guide');
    }

    public static function donate_url(): string
    {
        return self::link(self::DONATE_URL, 'support-page-donate');
    }

    /**
     * Donation links are opt-out plugin-wide, and this banner honours that
     * like every other donate link does.
     */
    public static function show_donate_links(): bool
    {
        return (bool) apply_filters('advmo_show_donate_links', true);
    }

    /**
     * A plain-text snapshot to paste into a support request.
     *
     * Credentials are deliberately absent: keys, secrets, endpoints and bucket
     * names never appear here, so the report is safe to post in a public forum.
     */
    public static function system_report(): string
    {
        global $wp_version;

        $settings = get_option('advmo_settings', []);
        $status   = get_option('advmo_last_connection_status', []);

        $provider_key = advmo_get_cloud_provider_key();
        $provider_name = __('not configured', 'advanced-media-offloader');
        if ($provider_key !== '') {
            try {
                $provider_name = \Advanced_Media_Offloader\Factories\CloudProviderFactory::create($provider_key)->getProviderName();
            } catch (\Exception $e) {
                $provider_name = $provider_key;
            }
        }

        $retention_labels = [
            0 => 'Retain local files',
            1 => 'Smart local cleanup',
            2 => 'Full cloud migration',
        ];
        $retention = isset($settings['retention_policy']) ? (int) $settings['retention_policy'] : 0;

        if (is_array($status) && isset($status['success'])) {
            $connection = !empty($status['success'])
                ? 'ok'
                : 'failed' . (!empty($status['code']) ? ' (' . $status['code'] . ')' : '');
        } else {
            $connection = 'never tested';
        }

        $lines = [
            'Advanced Media Offloader: ' . ADVMO_VERSION,
            'WordPress: ' . $wp_version,
            'PHP: ' . PHP_VERSION,
            'Multisite: ' . (is_multisite() ? 'yes' : 'no'),
            'Provider: ' . $provider_name,
            'Last connection test: ' . $connection,
            'Auto-offload new uploads: ' . ((!isset($settings['auto_offload_uploads']) || (int) $settings['auto_offload_uploads'] === 1) ? 'on' : 'off'),
            'Retention policy: ' . ($retention_labels[$retention] ?? (string) $retention),
            'Offloaded files: ' . advmo_get_offloaded_media_items_count(),
            'Files not offloaded: ' . advmo_get_unoffloaded_media_items_count(),
            'WP_DEBUG: ' . ((defined('WP_DEBUG') && WP_DEBUG) ? 'on' : 'off'),
        ];

        return implode("\n", $lines);
    }

    // Prevent cloning of the instance
    private function __clone() {}

    // Prevent unserializing of the instance
    public function __wakeup() {}
}
