<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

/**
 * Observes and displays the offload status of media attachments.
 *
 * Adds visual indicators for offload status in:
 * - Attachment edit form
 * - Media library grid view
 * - Media library list view
 * - Gutenberg editor media modal
 */
class OffloadStatusObserver implements ObserverInterface
{
    use OffloaderTrait;

    /**
     * Meta key for offload timestamp.
     */
    private const META_OFFLOADED_AT = 'advmo_offloaded_at';

    /**
     * Meta key for cloud provider name.
     */
    private const META_PROVIDER = 'advmo_provider';

    /**
     * Meta key for bucket name.
     */
    private const META_BUCKET = 'advmo_bucket';

    /**
     * Cloud SVG icon (20x20 viewBox).
     */
    private const ICON_CLOUD = '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M8 10C8 7.79086 9.79086 6 12 6C14.2091 6 16 7.79086 16 10V11H17C18.933 11 20.5 12.567 20.5 14.5C20.5 16.433 18.933 18 17 18H16C15.4477 18 15 18.4477 15 19C15 19.5523 15.4477 20 16 20H17C20.0376 20 22.5 17.5376 22.5 14.5C22.5 11.7793 20.5245 9.51997 17.9296 9.07824C17.4862 6.20213 15.0003 4 12 4C8.99974 4 6.51381 6.20213 6.07036 9.07824C3.47551 9.51997 1.5 11.7793 1.5 14.5C1.5 17.5376 3.96243 20 7 20H8C8.55228 20 9 19.5523 9 19C9 18.4477 8.55228 18 8 18H7C5.067 18 3.5 16.433 3.5 14.5C3.5 12.567 5.067 11 7 11H8V10ZM15.7071 13.2929L12.7071 10.2929C12.3166 9.90237 11.6834 9.90237 11.2929 10.2929L8.29289 13.2929C7.90237 13.6834 7.90237 14.3166 8.29289 14.7071C8.68342 15.0976 9.31658 15.0976 9.70711 14.7071L11 13.4142V19C11 19.5523 11.4477 20 12 20C12.5523 20 13 19.5523 13 19V13.4142L14.2929 14.7071C14.6834 15.0976 15.3166 15.0976 15.7071 14.7071C16.0976 14.3166 16.0976 13.6834 15.7071 13.2929Z" fill="currentColor"/></svg>';

    /**
     * Warning SVG icon (20x20 viewBox).
     */
    private const ICON_WARNING = '<svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2ZM11 7C11 6.44772 11.4477 6 12 6C12.5523 6 13 6.44772 13 7V13C13 13.5523 12.5523 14 12 14C11.4477 14 11 13.5523 11 13V7ZM12 16C11.4477 16 11 16.4477 11 17C11 17.5523 11.4477 18 12 18C12.5523 18 13 17.5523 13 17C13 16.4477 12.5523 16 12 16Z"/></svg>';

    /**
     * Register the observer with WordPress hooks.
     */
    public function register(): void
    {
        // Attachment edit form
        add_filter('attachment_fields_to_edit', [$this, 'addStatusField'], 10, 2);

        // Media library grid view (JS API)
        add_filter('wp_prepare_attachment_for_js', [$this, 'addOffloadFlagToAttachment'], 10, 2);

        // Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Media library list view
        add_filter('manage_media_columns', [$this, 'addOffloadColumn']);
        add_action('manage_media_custom_column', [$this, 'renderOffloadColumn'], 10, 2);
    }

    /**
     * Add offload status field to attachment edit form.
     *
     * @param array    $form_fields The form fields for the attachment.
     * @param \WP_Post $post        The attachment post object.
     * @return array The modified form fields.
     */
    public function addStatusField(array $form_fields, \WP_Post $post): array
    {
        $status = $this->getStatusDetails($post->ID);

        $form_fields['advmo_offload_status'] = [
            'label' => __('Offload Status', 'advanced-media-offloader'),
            'input' => 'html',
            'html'  => $this->renderStatusBadge($status),
        ];

        return $form_fields;
    }

    /**
     * Expose offload status flags to JavaScript attachment objects.
     *
     * @param array    $response   The attachment response array.
     * @param \WP_Post $attachment The attachment post object.
     * @return array The modified response.
     */
    public function addOffloadFlagToAttachment(array $response, \WP_Post $attachment): array
    {
        if ($attachment->post_type === 'attachment') {
            $response['advmoOffloaded'] = $this->is_offloaded($attachment->ID);
            $response['advmoHasErrors'] = $this->has_errors($attachment->ID);
        }

        return $response;
    }

    /**
     * Add offload status column header to media list view.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function addOffloadColumn(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'title') {
                $new_columns['advmo_offload'] = sprintf(
                    '<span class="advmo-icon advmo-icon--header" title="%1$s">%2$s</span><span class="screen-reader-text">%1$s</span>',
                    esc_attr__('Offload Status', 'advanced-media-offloader'),
                    self::ICON_CLOUD
                );
            }
        }

        return $new_columns;
    }

    /**
     * Render offload status column content in media list view.
     *
     * @param string $column_name The column name.
     * @param int    $post_id     The attachment post ID.
     */
    public function renderOffloadColumn(string $column_name, int $post_id): void
    {
        if ($column_name !== 'advmo_offload') {
            return;
        }

        if ($this->is_offloaded($post_id)) {
            printf(
                '<span class="advmo-icon advmo-icon--success" title="%s">%s</span>',
                esc_attr__('Offloaded', 'advanced-media-offloader'),
                self::ICON_CLOUD
            );
            return;
        }

        if ($this->has_errors($post_id)) {
            printf(
                '<span class="advmo-icon advmo-icon--error" title="%s">%s</span>',
                esc_attr__('Offload failed', 'advanced-media-offloader'),
                self::ICON_WARNING
            );
        }
    }

    /**
     * Enqueue styles and scripts for media library and editor.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueueAssets(string $hook): void
    {
        if (!$this->shouldEnqueueAssets($hook)) {
            return;
        }

        $this->enqueueStyles();
        $this->enqueueScripts();
    }

    /**
     * Determine if assets should be enqueued on the current screen.
     *
     * @param string $hook The current admin page hook.
     * @return bool True if assets should be loaded.
     */
    private function shouldEnqueueAssets(string $hook): bool
    {
        // Media library (grid/list view)
        if ($hook === 'upload.php') {
            return true;
        }

        // Gutenberg editor (post/page/CPT edit screens)
        if (in_array($hook, ['post.php', 'post-new.php'], true)) {
            return $this->isBlockEditorActive();
        }

        return false;
    }

    /**
     * Check if the block editor is active for the current post type.
     *
     * @return bool True if block editor is active.
     */
    private function isBlockEditorActive(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen) {
            return false;
        }

        // WordPress 5.0+ block editor check
        if (method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return true;
        }

        // Fallback: check if Gutenberg is active for this post type
        $post_type = $screen->post_type ?? '';
        if ($post_type && function_exists('use_block_editor_for_post_type')) {
            return use_block_editor_for_post_type($post_type);
        }

        return false;
    }

    /**
     * Get offload status details for an attachment.
     *
     * @param int $post_id The attachment post ID.
     * @return array{status: string, type: string}
     */
    private function getStatusDetails(int $post_id): array
    {
        if ($this->is_offloaded($post_id)) {
            return [
                'status' => $this->buildOffloadedStatusMessage($post_id),
                'type'   => 'success',
            ];
        }

        if ($this->has_errors($post_id)) {
            return [
                'status' => $this->buildErrorStatusMessage(),
                'type'   => 'error',
            ];
        }

        return [
            'status' => __('Not offloaded yet', 'advanced-media-offloader'),
            'type'   => 'pending',
        ];
    }

    /**
     * Build status message for successfully offloaded attachment.
     *
     * @param int $post_id The attachment post ID.
     * @return string The formatted status message.
     */
    private function buildOffloadedStatusMessage(int $post_id): string
    {
        $provider = get_post_meta($post_id, self::META_PROVIDER, true);
        $bucket = get_post_meta($post_id, self::META_BUCKET, true);
        $offloaded_at = get_post_meta($post_id, self::META_OFFLOADED_AT, true);

        $message = sprintf(
            /* translators: %s: Cloud provider name */
            __('Offloaded to %s', 'advanced-media-offloader'),
            esc_html($provider)
        );

        if ($bucket) {
            $message .= sprintf(
                /* translators: %s: Bucket name */
                __(' (Bucket: %s)', 'advanced-media-offloader'),
                esc_html($bucket)
            );
        }

        if ($offloaded_at) {
            $date_format = get_option('date_format') . ' ' . get_option('time_format');
            $formatted_date = date_i18n($date_format, $offloaded_at);

            $message .= sprintf(
                /* translators: %s: Date and time */
                __(' on %s', 'advanced-media-offloader'),
                esc_html($formatted_date)
            );
        }

        return $message;
    }

    /**
     * Build status message for failed offload with action link.
     *
     * @return string The formatted error message with link.
     */
    private function buildErrorStatusMessage(): string
    {
        $overview_url = admin_url('admin.php?page=advmo_media_overview');

        return sprintf(
            /* translators: %s: URL to Media Overview page */
            __('Offload failed — <a href="%s">View details</a>', 'advanced-media-offloader'),
            esc_url($overview_url)
        );
    }

    /**
     * Render the status badge HTML.
     *
     * @param array $status The status details array.
     * @return string The generated HTML.
     */
    private function renderStatusBadge(array $status): string
    {
        return sprintf(
            '<span class="advmo-status-badge advmo-status-badge--%s">%s</span>',
            esc_attr($status['type']),
            wp_kses($status['status'], ['a' => ['href' => []]])
        );
    }

    /**
     * Enqueue the offload status stylesheet.
     */
    private function enqueueStyles(): void
    {
        wp_enqueue_style(
            'advmo-offload-status',
            $this->getAssetUrl('css/offload-status.css'),
            [],
            $this->getAssetVersion('css/offload-status.css')
        );
    }

    /**
     * Enqueue the offload status script.
     */
    private function enqueueScripts(): void
    {
        wp_enqueue_script(
            'advmo-offload-status',
            $this->getAssetUrl('js/offload-status.js'),
            ['jquery', 'media-views'],
            $this->getAssetVersion('js/offload-status.js'),
            true
        );

        wp_localize_script('advmo-offload-status', 'advmoOffloadStatus', [
            'cloudIcon'   => self::ICON_CLOUD,
            'warningIcon' => self::ICON_WARNING,
        ]);
    }

    /**
     * Get the URL for an asset file.
     *
     * @param string $path Relative path to the asset.
     * @return string Full URL to the asset.
     */
    private function getAssetUrl(string $path): string
    {
        return plugins_url('assets/' . $path, dirname(__DIR__, 2) . '/advanced-media-offloader.php');
    }

    /**
     * Get the version for an asset file based on file modification time.
     *
     * @param string $path Relative path to the asset.
     * @return string Version string.
     */
    private function getAssetVersion(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/assets/' . $path;

        if (file_exists($file)) {
            return (string) filemtime($file);
        }

        return '1.0.0';
    }
}
