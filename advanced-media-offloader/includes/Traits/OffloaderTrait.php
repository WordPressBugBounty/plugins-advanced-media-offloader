<?php

namespace Advanced_Media_Offloader\Traits;

trait OffloaderTrait
{
    /**
     * Get the path prefix for offloaded files.
     *
     * @return string The sanitized path prefix or an empty string if not active.
     */
    private function get_path_prefix(): string
    {
        $settings = get_option('advmo_settings', []);
        $prefix_active = $settings['path_prefix_active'] ?? false;
        $path_prefix = $settings['path_prefix'] ?? '';

        if (!$prefix_active || empty($path_prefix)) {
            return '';
        }

        return trailingslashit(advmo_sanitize_path($path_prefix));
    }

    private function get_object_version($attachment_id)
    {
        // Check if we already have a version for this attachment
        $existing_version = get_post_meta($attachment_id, 'advmo_object_version', true);
        if ($existing_version) {
            return trailingslashit($existing_version);
        }

        $advmo_settings = get_option('advmo_settings');
        $object_versioning = isset($advmo_settings['object_versioning']) ? $advmo_settings['object_versioning'] : '0';

        // If versioning is not enabled, return an empty string
        if (!$object_versioning) {
            return '';
        }

        // Generate a new version
        // Use gmdate() so the version is timezone-independent (UTC), avoiding
        // any dependence on the server's local timezone configuration.
        if (!advmo_is_media_organized_by_year_month()) {
            $new_version = gmdate("YmdHis");
        } else {
            $new_version = gmdate("dHis");
        }

        // Save the new version in post meta
        update_post_meta($attachment_id, 'advmo_object_version', $new_version);

        return trailingslashit($new_version);
    }

    public function get_attachment_subdir($attachment_id)
    {
        // Check if already offlaoded, return advmo_path 
        if ($this->is_offloaded($attachment_id)) {
            return get_post_meta($attachment_id, 'advmo_path', true);
        }

        $object_version = $this->get_object_version($attachment_id);
        $path_prefix = $this->get_path_prefix();

        $metadata = wp_get_attachment_metadata($attachment_id);
        $file_path = get_attached_file($attachment_id);

        // For images, use the metadata 'file' if available
        if (isset($metadata['file'])) {
            $dirname = '';
            if (advmo_is_media_organized_by_year_month()) {
                $file_dirname = dirname($metadata['file']);
                // dirname() returns '.' when the file has no directory
                // component — e.g. an attachment stored at the uploads root
                // while year/month organization is enabled. Left untouched,
                // trailingslashit('.') would inject a spurious "./" segment
                // into advmo_path and every rewritten CDN URL
                // (".../uploads/./image.png"). Treat it as "no subdirectory",
                // matching the file-path branch used at upload time.
                if ($file_dirname !== '.' && $file_dirname !== '') {
                    $dirname = trailingslashit($file_dirname);
                }
            }
            return $path_prefix . $dirname . $object_version;
        }

        // For non-images, extract the year/month structure from the file path
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // Remove the base upload directory from the file path
        $relative_path = str_replace($base_dir . '/', '', $file_path);

        // Extract the year/month
        $path_parts = explode('/', trim($relative_path, '/'), 3);

        $response = '';
        if (count($path_parts) >= 2 && is_numeric($path_parts[0]) && is_numeric($path_parts[1])) {
            $response = trailingslashit($path_parts[0] . '/' . $path_parts[1]);
        }

        // Fallback: return empty string if we can't determine the structure
        return $path_prefix . $response . $object_version;
    }

    private function uniqueMetaDataSizes($sizes)
    {
        $uniqueSizes = [];
        $dimensionMap = [];

        foreach ($sizes as $name => $sizeInfo) {
            // Some metadata entries do not include filesize; treat as 0 to avoid PHP warnings.
            $sizeInfo['filesize'] = isset($sizeInfo['filesize']) ? (int) $sizeInfo['filesize'] : 0;
            $dimension = $sizeInfo['width'] . 'x' . $sizeInfo['height'];

            if (!isset($dimensionMap[$dimension])) {
                $dimensionMap[$dimension] = $name;
                $uniqueSizes[$name] = $sizeInfo;
            } else {
                // If this size has a larger filesize, replace the existing one
                $existingName = $dimensionMap[$dimension];
                if ($sizeInfo['filesize'] > $uniqueSizes[$existingName]['filesize']) {
                    unset($uniqueSizes[$existingName]);
                    $dimensionMap[$dimension] = $name;
                    $uniqueSizes[$name] = $sizeInfo;
                }
            }
        }

        return $uniqueSizes;
    }

    private function is_offloaded($post_id)
    {
        return (bool)get_post_meta($post_id, 'advmo_offloaded', true);
    }

    /**
     * Check if an attachment has offload errors.
     *
     * @param int $attachment_id The attachment ID.
     * @return bool True if there are errors, false otherwise.
     */
    private function has_errors(int $attachment_id): bool
    {
        $errors = get_post_meta($attachment_id, 'advmo_error_log', true);
        return !empty($errors);
    }

    /**
     * Add one attachment error without allowing retry loops to grow the value
     * forever. Repeated errors are moved to the end instead of duplicated.
     */
    private function appendAttachmentError(int $attachment_id, string $message, int $limit = 50): void
    {
        $errors = get_post_meta($attachment_id, 'advmo_error_log', true);
        if (!is_array($errors)) {
            $errors = is_string($errors) && $errors !== '' ? [$errors] : [];
        }

        $errors = array_values(array_filter(
            $errors,
            static fn($error): bool => is_string($error) && $error !== $message
        ));
        $errors[] = $message;
        $errors = array_slice($errors, -max(1, $limit));

        update_post_meta($attachment_id, 'advmo_error_log', $errors);
    }

    /** Remove one recovered operation error without hiding unrelated failures. */
    private function removeAttachmentError(int $attachment_id, string $message): void
    {
        $errors = get_post_meta($attachment_id, 'advmo_error_log', true);
        if (!is_array($errors)) {
            if (is_string($errors) && $errors === $message) {
                delete_post_meta($attachment_id, 'advmo_error_log');
            }
            return;
        }

        $errors = array_values(array_filter(
            $errors,
            static fn($error): bool => !is_string($error) || $error !== $message
        ));

        if (empty($errors)) {
            delete_post_meta($attachment_id, 'advmo_error_log');
            return;
        }

        update_post_meta($attachment_id, 'advmo_error_log', $errors);
    }

    private function shouldDeleteLocal()
    {
        $settings = get_option('advmo_settings');
        $retention_policy = isset($settings['retention_policy']) ? $settings['retention_policy'] : '0';

        // Ensure the value is a string and convert it to an integer
        return intval((string)$retention_policy);
    }

    private function shouldDeleteCloudFiles($post)
    {
        $advmo_settings = get_option('advmo_settings');
        $mirror_delete = false;
        if (isset($advmo_settings['mirror_delete'])) {
            $mirror_delete = $advmo_settings['mirror_delete'] == '1';
        }

        return $mirror_delete && $post->post_type === 'attachment' && $this->is_offloaded($post->ID);
    }

    /**
     * Get the active WordPress image-editor operation.
     *
     * wp_update_attachment_metadata is shared by image edits, restore-original,
     * and ordinary thumbnail regeneration. The call stack is the only context
     * WordPress provides to distinguish the two editor operations.
     *
     * @return string|null "image_edit", "image_restore", or null.
     */
    private function getImageEditorOperation(): ?string
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $frame) {
            if (!isset($frame['function'])) {
                continue;
            }
            if ('wp_save_image' === $frame['function']) {
                return 'image_edit';
            }
            if ('wp_restore_image' === $frame['function']) {
                return 'image_restore';
            }
        }

        return null;
    }

    private function isImageEditorOperation(): bool
    {
        return $this->getImageEditorOperation() !== null;
    }

    /**
     * Get all files from a size data entry, including sources for Modern Image Formats.
     *
     * @param array $sizeData The size data array from metadata.
     * @return array Array of unique file names.
     */
    private function getFilesFromSizeData(array $sizeData): array
    {
        $files = [];
        
        // Add the primary file
        if (!empty($sizeData['file'])) {
            $files[] = $sizeData['file'];
        }
        
        // Add files from sources array (Modern Image Formats support)
        if (!empty($sizeData['sources']) && is_array($sizeData['sources'])) {
            foreach ($sizeData['sources'] as $source) {
                if (!empty($source['file']) && !in_array($source['file'], $files, true)) {
                    $files[] = $source['file'];
                }
            }
        }
        
        return $files;
    }

    /**
     * Get root-level source files from metadata (Modern Image Formats support).
     *
     * @param array $metadata The attachment metadata.
     * @return array Array of additional source file names (excluding the main file).
     */
    private function getRootSourceFiles(array $metadata): array
    {
        $files = [];
        
        if (!empty($metadata['sources']) && is_array($metadata['sources'])) {
            $mainFile = $metadata['file'] ?? '';
            foreach ($metadata['sources'] as $source) {
                if (!empty($source['file']) && $source['file'] !== $mainFile) {
                    $files[] = $source['file'];
                }
            }
        }
        
        return $files;
    }

    /**
     * Get files retained by the WordPress image editor for later restores.
     *
     * The boolean value tells Smart Local Cleanup whether the file is a
     * full-size image that must remain local. Thumbnail backups are removable
     * after their standard and optimizer sidecar files are safely in cloud
     * storage. Full Cloud Migration may remove every returned file.
     *
     * @return array<string,bool> Basename => keep during Smart Local Cleanup.
     */
    private function getImageEditBackupFiles(int $attachment_id): array
    {
        $files = [];
        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        if (is_array($backup_sizes)) {
            foreach ($backup_sizes as $backup_key => $backup_data) {
                if (!is_array($backup_data)) {
                    continue;
                }

                $keep_for_smart_cleanup = is_string($backup_key)
                    && 0 === strpos($backup_key, 'full-');

                foreach ($this->getFilesFromSizeData($backup_data) as $file) {
                    $files[$file] = ($files[$file] ?? false) || $keep_for_smart_cleanup;
                }
            }
        }

        // The webp-uploads plugin stores backup full-image sources separately
        // from WordPress's flat backup-size map. They are full-size variants,
        // so Smart Local Cleanup keeps them alongside the main backup image.
        $backup_sources = get_post_meta($attachment_id, '_wp_attachment_backup_sources', true);
        if (is_array($backup_sources)) {
            foreach ($backup_sources as $sources_set) {
                if (!is_array($sources_set)) {
                    continue;
                }

                $entries = isset($sources_set['file']) ? [$sources_set] : $sources_set;
                foreach ($entries as $source) {
                    if (!is_array($source)) {
                        continue;
                    }

                    foreach ($this->getFilesFromSizeData($source) as $file) {
                        $files[$file] = true;
                    }
                }
            }
        }

        return $files;
    }
}
