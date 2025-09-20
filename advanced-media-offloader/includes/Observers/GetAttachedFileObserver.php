<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Interfaces\ObserverInterface;

/**
 * Class GetAttachedFileObserver
 *
 * This observer hooks into the 'get_attached_file' filter to handle offloaded attachments,
 * specifically addressing issues with SVG files in WordPress and Elementor after offloading
 * to cloud storage. When the local file is missing for supported mime types (e.g., image/svg+xml),
 * it downloads a temporary copy from the cloud URL to ensure accessibility.
 *
 * Note: Even when "Smart local cleanup" is selected (which typically deletes only sized images
 * while keeping the original), SVG files may have their main file deleted due to how plugins
 * enable SVG uploads in WordPress, as SVGs do not generate sized thumbnails.
 */
class GetAttachedFileObserver implements ObserverInterface
{
    public function register(): void
    {
        add_filter('get_attached_file', [$this, 'filter'], 10, 2);
    }

    public function filter($file, $attachment_id)
    {
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return $file;
        }
        /**
         * Filters the supported mime types for temporary file fetching.
         *
         * @param array $supported_mime_types The supported mime types.
         * @param int $attachment_id The attachment ID.
         * @return array The supported mime types.
         */
        $supported_mime_types = apply_filters('advmo_temp_fetch_mime_types', ['image/svg+xml'], $attachment_id);
        if (!in_array($post->post_mime_type, $supported_mime_types, true)) {
            return $file;
        }

        if (file_exists($file)) {
            return $file;
        }

        $is_offloaded = (bool) get_post_meta($attachment_id, 'advmo_offloaded', true);
        if (!$is_offloaded) {
            return $file;
        }

        // Get cloud URL via plugin's existing rewrite logic


        // Temporarily remove the 'get_attached_file' filter before calling advmo_get_public_url to prevent recursive calls.
        // Re-add the filter afterward to maintain normal behavior.
        // This resolves fatal errors on sites with offloaded SVGs or similar mime types where local files are missing.
        remove_filter('get_attached_file', [$this, 'filter'], 10);
        $remote_url = advmo_get_public_url($attachment_id);
        add_filter('get_attached_file', [$this, 'filter'], 10, 2);

        if (empty($remote_url)) {
            return $file;
        }

        $tmp_file = get_post_meta($attachment_id, 'advmo_tmp_file', true);
        if ($tmp_file && file_exists($tmp_file)) {
            return $tmp_file;
        }

        // Ensure download_url() is available
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = download_url($remote_url, 30);
        if (is_wp_error($tmp)) {
            error_log('ADVMO - GetAttachedFileObserver - Error downloading file: ' . $tmp->get_error_message());
            return $file;
        }

        # store the tmp file to the attachment meta
        update_post_meta($attachment_id, 'advmo_tmp_file', $tmp);

        return $tmp;
    }
}
