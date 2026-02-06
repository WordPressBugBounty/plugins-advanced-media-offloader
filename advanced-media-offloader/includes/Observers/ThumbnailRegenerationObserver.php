<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class ThumbnailRegenerationObserver implements ObserverInterface
{
    use OffloaderTrait;

    /**
     * @var S3_Provider
     */
    private S3_Provider $cloudProvider;

    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($cloudProvider);
    }

    public function register(): void
    {
        // Priority 98 to run before AttachmentUpdateObserver (priority 99)
        add_filter('wp_update_attachment_metadata', [$this, 'run'], 98, 2);
    }

    public function run($metadata, $attachment_id)
    {
        // Only process if attachment is already offloaded
        if (!$this->is_offloaded($attachment_id)) {
            return $metadata;
        }

        // Only process image attachments
        if (!wp_attachment_is_image($attachment_id)) {
            return $metadata;
        }

        // Check if auto-offload is enabled in settings
        $options = get_option('advmo_settings', []);
        $auto_offload_enabled = isset($options['auto_offload_uploads']) ? (int) $options['auto_offload_uploads'] : 1;
        
        if (!$auto_offload_enabled) {
            return $metadata;
        }

        /**
         * Filter to determine whether an attachment should be offloaded.
         *
         * Return false to skip offloading this attachment. Useful for
         * conditional rules (file type, size, user role, taxonomy, etc.).
         *
         * @param bool $should_offload Default true.
         * @param int  $attachment_id  Attachment ID.
         */
        $should_offload = apply_filters('advmo_should_offload_attachment', true, $attachment_id);
        if (!$should_offload) {
            return $metadata;
        }

        // Get old metadata to compare
        $old_metadata = wp_get_attachment_metadata($attachment_id);

        // Check if this is a thumbnail regeneration by comparing sizes and sources
        if ($this->hasMetadataChanges($old_metadata, $metadata)) {
            // Upload regenerated thumbnails and handle local cleanup
            $this->cloudAttachmentUploader->uploadRegeneratedThumbnails($attachment_id, $metadata, $old_metadata);
        }

        return $metadata;
    }

    /**
     * Check if metadata has changes that require uploading to cloud.
     *
     * Detects new thumbnails, changed dimensions, and Modern Image Format sources.
     *
     * @param array|false $old_metadata Old attachment metadata.
     * @param array      $new_metadata New attachment metadata.
     * @return bool True if changes were detected that need uploading.
     */
    private function hasMetadataChanges($old_metadata, $new_metadata): bool
    {
        // If no old metadata, this is likely a new upload, not regeneration
        if (!$old_metadata || !is_array($old_metadata)) {
            return false;
        }

        // Check for root-level sources changes (Modern Image Formats)
        $old_root_sources = $old_metadata['sources'] ?? [];
        $new_root_sources = $new_metadata['sources'] ?? [];
        if ($old_root_sources !== $new_root_sources) {
            return true;
        }

        // If no sizes in new metadata, nothing more to process
        if (empty($new_metadata['sizes']) || !is_array($new_metadata['sizes'])) {
            return false;
        }

        $old_sizes = isset($old_metadata['sizes']) && is_array($old_metadata['sizes']) ? $old_metadata['sizes'] : [];
        $new_sizes = $new_metadata['sizes'];

        // Check if any new sizes were added or existing sizes were changed
        foreach ($new_sizes as $size_name => $size_data) {
            // New size that didn't exist before
            if (!isset($old_sizes[$size_name])) {
                return true;
            }

            // Size exists but file changed (regenerated)
            $old_file = $old_sizes[$size_name]['file'] ?? '';
            $new_file = $size_data['file'] ?? '';
            
            if ($old_file !== $new_file) {
                return true;
            }

            // Check if dimensions changed (might indicate regeneration)
            $old_width = $old_sizes[$size_name]['width'] ?? 0;
            $old_height = $old_sizes[$size_name]['height'] ?? 0;
            $new_width = $size_data['width'] ?? 0;
            $new_height = $size_data['height'] ?? 0;

            if ($old_width !== $new_width || $old_height !== $new_height) {
                return true;
            }

            // Check if sources changed (Modern Image Formats support)
            $old_sources = $old_sizes[$size_name]['sources'] ?? [];
            $new_sources = $size_data['sources'] ?? [];
            if ($old_sources !== $new_sources) {
                return true;
            }
        }

        return false;
    }
}

