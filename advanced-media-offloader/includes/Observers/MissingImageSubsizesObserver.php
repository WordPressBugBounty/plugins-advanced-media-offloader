<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Interfaces\ObserverInterface;

/**
 * Class MissingImageSubsizesObserver
 *
 * Silences PHP warnings emitted on every editor media fetch when local files
 * have been removed after offload (Full Cloud Migration).
 *
 * For images that were scaled on upload (wider than the big-image threshold,
 * typically photography), WordPress stores the true original in the
 * 'original_image' metadata key. wp_get_missing_image_subsizes() — called by
 * the REST attachments controller to compute the 'missing_image_sizes' field
 * each time the block editor fetches an attachment in edit context — then
 * reads that local original via wp_getimagesize(). With the local file
 * deleted, the failed read logs unsuppressed exif_imagetype() and
 * file_get_contents() "Failed to open stream" warnings (plus a getimagesize()
 * warning under WP_DEBUG) on every fetch, flooding the error log.
 *
 * The guard: when wp_get_missing_image_subsizes() fetches the attachment
 * metadata, drop 'original_image' from the returned array if the attachment
 * is offloaded and the local original no longer exists. Core then skips the
 * file read entirely and falls back to the stored width/height — its own
 * graceful path for images without an original — which yields the same
 * (empty) missing-sizes result, since all sub-sizes were generated before
 * offload. Nothing can be regenerated without a local file anyway.
 *
 * The unset is scoped to wp_get_missing_image_subsizes() via a backtrace
 * check (the same approach as OffloaderTrait::isImageEditorOperation())
 * because neither blunter option is safe:
 * - Returning false/'' from the 'wp_get_original_image_path' filter reaches
 *   getimagesize() outside any try/catch and throws an uncaught ValueError
 *   ("Path cannot be empty") on PHP 8 — a fatal instead of a warning.
 * - Unsetting 'original_image' for every wp_get_attachment_metadata() call
 *   would hide the original from the media modal (originalImageURL /
 *   originalImageName), from REST media_details, and from this plugin's own
 *   cloud-file deletion, orphaning the original object in the bucket.
 */
class MissingImageSubsizesObserver implements ObserverInterface
{
    public function register(): void
    {
        add_filter('wp_get_attachment_metadata', [$this, 'filter'], 10, 2);
    }

    /**
     * Drop 'original_image' from attachment metadata while
     * wp_get_missing_image_subsizes() is computing, if the local original
     * file was removed after offload.
     *
     * @param array|false $data          Attachment metadata.
     * @param int         $attachment_id The attachment ID.
     * @return array|false Possibly-modified metadata.
     */
    public function filter($data, $attachment_id)
    {
        if (!is_array($data) || empty($data['original_image'])) {
            return $data;
        }

        $is_offloaded = (bool) get_post_meta($attachment_id, 'advmo_offloaded', true);
        if (!$is_offloaded) {
            return $data;
        }

        if (!$this->isComputingMissingImageSubsizes()) {
            return $data;
        }

        // Resolve the local original path without wp_get_original_image_path(),
        // which calls wp_get_attachment_metadata() and would re-enter this
        // filter. Unfiltered on purpose: we want the real local path, not one
        // rewritten by 'get_attached_file' filters.
        $attached_file = get_attached_file($attachment_id, true);
        if (empty($attached_file)) {
            return $data;
        }

        $original_image = path_join(dirname($attached_file), $data['original_image']);
        if (file_exists($original_image)) {
            return $data;
        }

        unset($data['original_image']);

        return $data;
    }

    /**
     * Whether the current metadata fetch originates from
     * wp_get_missing_image_subsizes().
     *
     * @return bool
     */
    private function isComputingMissingImageSubsizes(): bool
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($trace as $frame) {
            if (isset($frame['function']) && 'wp_get_missing_image_subsizes' === $frame['function'] && empty($frame['class'])) {
                return true;
            }
        }

        return false;
    }
}
