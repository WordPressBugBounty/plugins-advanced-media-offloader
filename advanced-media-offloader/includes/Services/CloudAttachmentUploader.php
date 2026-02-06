<?php

namespace Advanced_Media_Offloader\Services;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class CloudAttachmentUploader
{
    use OffloaderTrait;

    private S3_Provider $cloudProvider;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    public function uploadAttachment(int $attachment_id): bool
    {
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
            return false;
        }

        if ($this->is_offloaded($attachment_id)) {
            return true;
        }

        if ($this->uploadToCloud($attachment_id)) {
            $this->updateAttachmentMetadata($attachment_id);
            return true;
        }

        return false;
    }

    public function uploadUpdatedAttachment(int $attachment_id, array $metadata): bool
    {
        /**
         * Filter to determine whether an updated attachment should be re-offloaded.
         *
         * Return false to skip uploading the updated file and sizes.
         *
         * @param bool  $should_offload Default true.
         * @param int   $attachment_id  Attachment ID.
         * @param array $metadata       Attachment metadata.
         */
        $should_offload = apply_filters('advmo_should_offload_attachment', true, $attachment_id, $metadata);
        if (!$should_offload) {
            return true;
        }

        if ($metadata) {
            $file = get_attached_file($attachment_id);
            $subdir = $this->get_attachment_subdir($attachment_id);
            $uploadResult = $this->cloudProvider->uploadFile($file, $subdir . wp_basename($file));

            if (!$uploadResult) {
                $this->logError($attachment_id, 'Failed to upload resized main file to cloud storage.');
                return false;
            }

            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                $metadata_sizes = $this->uniqueMetaDataSizes($metadata['sizes']);
                foreach ($metadata_sizes as $size => $data) {
                    $pattern = '/\-e[0-9]+(?=\-)/';
                    if (!preg_match($pattern, $data['file'])) {
                        error_log("{$data['file']} is not a valid size file name.");
                        continue;
                    }
                    $file = get_attached_file($attachment_id, true);
                    $file = str_replace(wp_basename($file), $data['file'], $file);
                    $uploadResult = $this->cloudProvider->uploadFile($file, $subdir . wp_basename($file));
                    if (!$uploadResult) {
                        $this->logError($attachment_id, "Failed to upload size '{$size}' to cloud storage.");
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Upload regenerated thumbnails to cloud storage and handle local cleanup.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $new_metadata  New attachment metadata after regeneration.
     * @param array $old_metadata  Old attachment metadata before regeneration.
     * @return bool True on success, false on failure.
     */
    public function uploadRegeneratedThumbnails(int $attachment_id, array $new_metadata, array $old_metadata): bool
    {
        /**
         * Filter to determine whether regenerated thumbnails should be offloaded.
         *
         * Return false to skip offloading regenerated thumbnails.
         *
         * @param bool  $should_offload Default true.
         * @param int   $attachment_id  Attachment ID.
         * @param array $new_metadata   New attachment metadata.
         * @param array $old_metadata   Old attachment metadata.
         */
        $should_offload = apply_filters('advmo_should_offload_attachment', true, $attachment_id);
        if (!$should_offload) {
            return false;
        }

        // Get the subdirectory for cloud storage
        $subdir = $this->get_attachment_subdir($attachment_id);

        // Get old and new sizes
        $old_sizes = isset($old_metadata['sizes']) && is_array($old_metadata['sizes']) ? $old_metadata['sizes'] : [];
        $new_sizes = isset($new_metadata['sizes']) && is_array($new_metadata['sizes']) ? $new_metadata['sizes'] : [];

        // Find new or changed thumbnails
        $thumbnails_to_upload = [];
        $thumbnails_to_cleanup = [];

        foreach ($new_sizes as $size_name => $size_data) {
            $is_new = !isset($old_sizes[$size_name]);
            $is_changed = false;
            $sources_changed = false;

            if (!$is_new) {
                // Check if file changed
                $old_file = $old_sizes[$size_name]['file'] ?? '';
                $new_file = $size_data['file'] ?? '';
                
                if ($old_file !== $new_file) {
                    $is_changed = true;
                }

                // Check if dimensions changed
                $old_width = $old_sizes[$size_name]['width'] ?? 0;
                $old_height = $old_sizes[$size_name]['height'] ?? 0;
                $new_width = $size_data['width'] ?? 0;
                $new_height = $size_data['height'] ?? 0;

                if ($old_width !== $new_width || $old_height !== $new_height) {
                    $is_changed = true;
                }

                // Check if sources changed (Modern Image Formats support)
                $old_sources = $old_sizes[$size_name]['sources'] ?? [];
                $new_sources = $size_data['sources'] ?? [];
                if ($old_sources !== $new_sources) {
                    $sources_changed = true;
                }
            }

            if ($is_new || $is_changed || $sources_changed) {
                $thumbnails_to_upload[] = [
                    'size' => $size_name,
                    'data' => $size_data,
                ];

                // If changed, mark old file for cleanup
                if ($is_changed && isset($old_sizes[$size_name]['file'])) {
                    $thumbnails_to_cleanup[] = $old_sizes[$size_name]['file'];
                }
            }
        }

        // Upload new/changed thumbnails
        if (!empty($thumbnails_to_upload)) {
            $base_file = get_attached_file($attachment_id, true);
            $file_dir = trailingslashit(dirname($base_file));

            foreach ($thumbnails_to_upload as $thumbnail) {
                $size_data = $thumbnail['data'];
                
                // Get all files for this size, including sources (Modern Image Formats)
                $size_files = $this->getFilesFromSizeData($size_data);
                
                foreach ($size_files as $size_file) {
                    $thumbnail_file = $file_dir . $size_file;

                    // Only upload if file exists locally
                    if (file_exists($thumbnail_file)) {
                        $uploadResult = $this->cloudProvider->uploadFile($thumbnail_file, $subdir . $size_file);
                        if (!$uploadResult) {
                            $this->logError($attachment_id, "Failed to upload regenerated thumbnail '{$thumbnail['size']}' file '{$size_file}' to cloud storage.");
                            // Continue with other thumbnails even if one fails
                            continue;
                        }
                    } else {
                        error_log("Advanced Media Offloader: Regenerated thumbnail file not found: {$thumbnail_file}");
                    }
                }
            }
        }

        // Check for new root-level sources (Modern Image Formats support)
        $old_root_sources = $old_metadata['sources'] ?? [];
        $new_root_sources = $new_metadata['sources'] ?? [];
        $has_new_root_sources = ($old_root_sources !== $new_root_sources);

        if ($has_new_root_sources) {
            $base_file = get_attached_file($attachment_id, true);
            $file_dir = trailingslashit(dirname($base_file));
            $root_source_files = $this->getRootSourceFiles($new_metadata);
            
            foreach ($root_source_files as $source_file) {
                $source_path = $file_dir . $source_file;
                if (file_exists($source_path)) {
                    $uploadResult = $this->cloudProvider->uploadFile($source_path, $subdir . $source_file);
                    if (!$uploadResult) {
                        $this->logError($attachment_id, "Failed to upload root source file '{$source_file}' to cloud storage.");
                        // Continue with other files even if one fails
                    }
                } else {
                    error_log("Advanced Media Offloader: Root source file not found: {$source_path}");
                }
            }
        }

        // Handle local cleanup based on retention policy
        $deleteLocalRule = $this->shouldDeleteLocal();
        if ($deleteLocalRule !== 0 && (!empty($thumbnails_to_upload) || $has_new_root_sources)) {
            $this->deleteRegeneratedLocalThumbnails($attachment_id, $thumbnails_to_upload, $deleteLocalRule, $has_new_root_sources ? $new_metadata : null);
        }

        return true;
    }

    /**
     * Delete regenerated thumbnail files locally based on retention policy.
     *
     * @param int        $attachment_id        Attachment ID.
     * @param array      $thumbnails_to_upload Array of thumbnails that were uploaded.
     * @param int        $deleteLocalRule      Retention policy (1 = Smart Local Cleanup, 2 = Full Cloud Migration).
     * @param array|null $metadata_with_sources Optional metadata containing root sources to delete.
     * @return bool True on success, false on failure.
     */
    private function deleteRegeneratedLocalThumbnails(int $attachment_id, array $thumbnails_to_upload, int $deleteLocalRule, ?array $metadata_with_sources = null): bool
    {
        /**
         * Fires before regenerated thumbnail files are deleted locally.
         *
         * @param int   $attachment_id        Attachment ID.
         * @param array $thumbnails_to_upload Thumbnails that were uploaded.
         * @param int   $deleteLocalRule      Retention policy.
         */
        do_action('advmo_before_delete_regenerated_local_thumbnails', $attachment_id, $thumbnails_to_upload, $deleteLocalRule);

        $original_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($original_file));

        // For Smart Local Cleanup (1), delete only regenerated thumbnails, keep original
        // For Full Cloud Migration (2), also delete regenerated thumbnails (original already deleted during initial offload)
        if ($deleteLocalRule === 1 || $deleteLocalRule === 2) {
            foreach ($thumbnails_to_upload as $thumbnail) {
                // Get all files for this size, including sources (Modern Image Formats)
                $size_files = $this->getFilesFromSizeData($thumbnail['data']);
                
                foreach ($size_files as $size_file) {
                    $thumbnail_file = $file_dir . $size_file;
                    if (file_exists($thumbnail_file)) {
                        wp_delete_file($thumbnail_file);
                    }
                }
            }

            // Delete root-level source files if provided (Modern Image Formats support)
            if ($metadata_with_sources !== null) {
                $root_source_files = $this->getRootSourceFiles($metadata_with_sources);
                foreach ($root_source_files as $source_file) {
                    $source_path = $file_dir . $source_file;
                    if (file_exists($source_path)) {
                        wp_delete_file($source_path);
                    }
                }
            }
        }

        /**
         * Fires after regenerated thumbnail files have been deleted locally.
         *
         * @param int   $attachment_id        Attachment ID.
         * @param array $thumbnails_to_upload Thumbnails that were uploaded.
         * @param int   $deleteLocalRule      Retention policy.
         */
        do_action('advmo_after_delete_regenerated_local_thumbnails', $attachment_id, $thumbnails_to_upload, $deleteLocalRule);

        return true;
    }

    private function uploadToCloud(int $attachment_id): bool
    {
        /**
         * Fires before the attachment is uploaded to the cloud.
         *
         * This action allows developers to perform tasks or logging before
         * the attachment is uploaded to the cloud.
         *
         * @param int $attachment_id
         */
        do_action('advmo_before_upload_to_cloud', $attachment_id);

        # remove error logs related to the attachment before starting the new upload process
        delete_post_meta($attachment_id, 'advmo_error_log');

        if (!$this->attachment_exists_on_disk($attachment_id)) {
            return false;
        }

        $file = get_attached_file($attachment_id);
        $subdir = $this->get_attachment_subdir($attachment_id);

        $uploadResult = $this->cloudProvider->uploadFile($file, $subdir . wp_basename($file));

        if (!$uploadResult) {
            $this->logError($attachment_id, 'Failed to upload main file to cloud storage.');
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $metadata_sizes = $this->uniqueMetaDataSizes($metadata['sizes']);
            $base_file = get_attached_file($attachment_id, true);
            $file_dir = trailingslashit(dirname($base_file));
            
            foreach ($metadata_sizes as $size => $data) {
                // Get all files for this size, including sources (Modern Image Formats)
                $size_files = $this->getFilesFromSizeData($data);
                
                foreach ($size_files as $size_file) {
                    $file_path = $file_dir . $size_file;
                    $uploadResult = $this->cloudProvider->uploadFile($file_path, $subdir . $size_file);
                    if (!$uploadResult) {
                        $this->logError($attachment_id, "Failed to upload size '{$size}' file '{$size_file}' to cloud storage.");
                        return false;
                    }
                }
            }
        }

        /**
         * Filter to determine whether the original image should be uploaded to the cloud.
         *
         * Return false to skip uploading the original image.
         *
         * @param bool $should_upload_original_image Default true.
         * @param int  $attachment_id                 Attachment ID.
         * @param array $metadata                      Attachment metadata.
         */
        $should_upload_original_image = apply_filters('advmo_should_upload_original_image', true, $attachment_id, $metadata);

        if ($should_upload_original_image && !empty($metadata['original_image'])) {
            $original_image = wp_get_original_image_path($attachment_id);
            $uploadResult = $this->cloudProvider->uploadFile($original_image, $subdir . wp_basename($original_image));
            if (!$uploadResult) {
                $this->logError($attachment_id, 'Failed to upload original image to cloud storage.');
                return false;
            }
        }

        // Upload root-level source files (Modern Image Formats support)
        // Only process if metadata is a valid array (may be false for non-image files before DB save)
        $root_source_files = is_array($metadata) ? $this->getRootSourceFiles($metadata) : [];
        if (!empty($root_source_files)) {
            $main_file = get_attached_file($attachment_id, true);
            $file_dir = trailingslashit(dirname($main_file));
            
            foreach ($root_source_files as $source_file) {
                $source_path = $file_dir . $source_file;
                if (file_exists($source_path)) {
                    $uploadResult = $this->cloudProvider->uploadFile($source_path, $subdir . $source_file);
                    if (!$uploadResult) {
                        $this->logError($attachment_id, "Failed to upload source file '{$source_file}' to cloud storage.");
                        return false;
                    }
                }
            }
        }

        $deleteLocalRule = $this->shouldDeleteLocal();
        if ($deleteLocalRule !== 0) {
            $this->deleteLocalFile($attachment_id, $deleteLocalRule);
        }

        /**
         * Fires after the attachment has been uploaded to the cloud.
         *
         * This action allows developers to perform additional tasks or logging after
         * the attachment has been uploaded to the cloud.
         *
         * @param int $attachment_id    The ID of the attachment that was processed.
         */
        do_action('advmo_after_upload_to_cloud', $attachment_id);

        return true;
    }

    private function logError(int $attachment_id, string $specificError): void
    {
        $generalError = $specificError . ' Please review your Cloud provider credentials or connection settings. For more details, enable debug.log and check the logs.';

        $errorLog = get_post_meta($attachment_id, 'advmo_error_log', true);
        if (!is_array($errorLog)) {
            $errorLog = array();
        }

        $errorLog[] = $generalError;

        update_post_meta($attachment_id, 'advmo_error_log', $errorLog);
        update_post_meta($attachment_id, 'advmo_offloaded', false);
    }

    private function updateAttachmentMetadata(int $attachment_id): void
    {
        update_post_meta($attachment_id, 'advmo_path', $this->get_attachment_subdir($attachment_id));
        update_post_meta($attachment_id, 'advmo_offloaded', true);
        update_post_meta($attachment_id, 'advmo_offloaded_at', time());
        update_post_meta($attachment_id, 'advmo_provider', $this->cloudProvider->getProviderName());
        update_post_meta($attachment_id, 'advmo_bucket', $this->cloudProvider->getBucket());
    }

    private function deleteLocalFile(int $attachment_id, int $deleteLocalRule): bool
    {
        /**
         * Fires before the local file(s) associated with an attachment are deleted.
         *
         * This action allows developers to perform tasks or logging before
         * the local files are removed following a successful cloud upload.
         *
         * @param int $attachment_id    The ID of the attachment to be processed.
         * @param int $deleteLocalRule  The rule to be applied for local file deletion:
         *                              1 - Delete only sized images, keep original.
         *                              2 - Delete all local files including the original.
         */
        do_action('advmo_before_delete_local_file', $attachment_id, $deleteLocalRule);

        $original_file = get_attached_file($attachment_id, true);

        if (!file_exists($original_file)) {
            error_log("Advanced Media Offloader: Original file not found for deletion: $original_file");
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $file_dir = trailingslashit(dirname($original_file));
        
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $sizeinfo) {
                // Get all files for this size, including sources (Modern Image Formats)
                $size_files = $this->getFilesFromSizeData($sizeinfo);
                
                foreach ($size_files as $size_file) {
                    $sized_file = $file_dir . $size_file;
                    if (file_exists($sized_file)) {
                        wp_delete_file($sized_file);
                    }
                }
            }
        }

        if ($deleteLocalRule === 2) {
            wp_delete_file($original_file);
            
            // Handle original image if exists (For scaled or processed images)
            if (!empty($metadata['original_image'])) {
                $original_image_path = wp_get_original_image_path($attachment_id);
                wp_delete_file($original_image_path);
            }
            
            // Delete root-level source files (Modern Image Formats support)
            // Only process if metadata is a valid array (may be false for non-image files)
            $root_source_files = is_array($metadata) ? $this->getRootSourceFiles($metadata) : [];
            foreach ($root_source_files as $source_file) {
                $source_path = $file_dir . $source_file;
                if (file_exists($source_path)) {
                    wp_delete_file($source_path);
                }
            }
        }

        update_post_meta($attachment_id, 'advmo_retention_policy', $deleteLocalRule);

        /**
         * Fires after the local file(s) associated with an attachment have been deleted.
         *
         * This action allows developers to perform additional tasks or logging after
         * the local files have been removed following a successful cloud upload.
         *
         * @param int $attachment_id    The ID of the attachment that was processed.
         * @param int $deleteLocalRule  The rule applied for local file deletion:
         *                              1 - Delete only sized images, keep original.
         *                              2 - Delete all local files including the original.
         */
        do_action('advmo_after_delete_local_file', $attachment_id, $deleteLocalRule);

        return true;
    }

    protected function attachment_exists_on_disk($attachment_id)
    {
        $errors = array();

        // Get the full path to the attachment file
        $file_path = get_attached_file($attachment_id);

        // Check if the main file exists
        if (!file_exists($file_path)) {
            $errors[] = "Main file does not exist: {$file_path}";
        }

        // If it's an image, check all sizes
        if (wp_attachment_is_image($attachment_id)) {
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($metadata['sizes'])) {
                $upload_dir = wp_upload_dir();
                $base_dir = trailingslashit($upload_dir['basedir']);
                $file_dir = trailingslashit(dirname($file_path));

                foreach ($metadata['sizes'] as $size => $size_info) {
                    $size_file_path = $file_dir . $size_info['file'];
                    if (!file_exists($size_file_path)) {
                        $errors[] = "Size '{$size}' does not exist: {$size_file_path}";
                    }
                }
            }
        }

        // Save errors to post meta
        if (!empty($errors)) {
            $existing_errors = get_post_meta($attachment_id, 'advmo_error_log', true);
            if (!is_array($existing_errors)) {
                $existing_errors = array();
            }
            $updated_errors = array_merge($existing_errors, $errors);
            update_post_meta($attachment_id, 'advmo_error_log', $updated_errors);
        } else {
            // If there are no errors, remove any existing error log
            delete_post_meta($attachment_id, 'advmo_error_log');
        }

        // Return true if no errors, false otherwise
        return empty($errors);
    }
}
