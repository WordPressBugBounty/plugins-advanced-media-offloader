<?php

namespace Advanced_Media_Offloader\Admin\Observers;

use Advanced_Media_Offloader\Interfaces\ObserverInterface;

class WelcomeNotice implements ObserverInterface
{
    private static $instance = null;

    /**
     * Option flag set on every activation; cleared when the notice is dismissed
     * or the user reaches one of the plugin's own screens.
     */
    public const OPTION_KEY = 'advmo_show_welcome_notice';

    private const NONCE_ACTION = 'advmo_dismiss_welcome_notice';

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

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
        add_action('current_screen', [$this, 'maybe_clear_on_plugin_pages']);
        add_action('wp_ajax_advmo_dismiss_welcome_notice', [$this, 'dismiss']);
    }

    /**
     * The notice's job is to get the user to the plugin screens; once they
     * arrive on their own, stop showing it everywhere else.
     */
    public function maybe_clear_on_plugin_pages(): void
    {
        if (advmo_is_settings_page() && get_option(self::OPTION_KEY)) {
            delete_option(self::OPTION_KEY);
        }
    }

    public function render(): void
    {
        if (!get_option(self::OPTION_KEY) || !current_user_can('manage_options')) {
            return;
        }

        // The plugin's own screens replace admin notices with their own header UI.
        if (advmo_is_settings_page()) {
            return;
        }

        $settings_url  = admin_url('admin.php?page=advmo');
        $overview_url  = admin_url('admin.php?page=advmo_media_overview');
        $is_configured = advmo_get_cloud_provider_key() !== '';
        $options       = get_option('advmo_settings', []);
        $auto_offload  = !isset($options['auto_offload_uploads']) || (int) $options['auto_offload_uploads'] === 1;
?>
        <div class="notice notice-info is-dismissible advmo-welcome-notice">
            <?php if ($is_configured) : ?>
                <p>
                    <strong><?php esc_html_e('Advanced Media Offloader is active.', 'advanced-media-offloader'); ?></strong>
                    <?php if ($auto_offload) : ?>
                        <?php esc_html_e('New uploads are offloaded to your cloud storage automatically.', 'advanced-media-offloader'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Automatic offloading of new uploads is turned off — you can enable it in the plugin settings.', 'advanced-media-offloader'); ?>
                    <?php endif; ?>
                </p>
                <p>
                    <a href="<?php echo esc_url($overview_url); ?>" class="button button-primary"><?php esc_html_e('Offload existing media', 'advanced-media-offloader'); ?></a>
                    <a href="<?php echo esc_url($settings_url); ?>" class="button"><?php esc_html_e('Review settings', 'advanced-media-offloader'); ?></a>
                </p>
            <?php else : ?>
                <p>
                    <strong><?php esc_html_e('Thanks for installing Advanced Media Offloader!', 'advanced-media-offloader'); ?></strong>
                    <?php esc_html_e('Connect your cloud storage (Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 and more) to start offloading new uploads automatically.', 'advanced-media-offloader'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary"><?php esc_html_e('Set up cloud storage', 'advanced-media-offloader'); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <script>
            jQuery(function ($) {
                $(document).on('click', '.advmo-welcome-notice .notice-dismiss', function () {
                    $.post(ajaxurl, {
                        action: 'advmo_dismiss_welcome_notice',
                        nonce: '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION)); ?>'
                    });
                });
            });
        </script>
<?php
    }

    public function dismiss(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed.', 'advanced-media-offloader')]);
        }

        delete_option(self::OPTION_KEY);
        wp_send_json_success();
    }

    // Prevent cloning of the instance
    private function __clone() {}

    // Prevent unserializing of the instance
    public function __wakeup() {}
}
