<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class UniqueFilenameObserver implements ObserverInterface
{
    use OffloaderTrait;

    private S3_Provider $cloudProvider;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    public function register(): void
    {
        add_filter('wp_unique_filename', [$this, 'filter'], 10, 3);
    }

    public function filter($filename, $ext, $dir)
    {
        // Only run when full cloud migration is enabled (retention policy = 2)
        if (!$this->isFullCloudMigrationEnabled()) {
            return $filename;
        }

        // Get the base filename without extension
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        // If no extension was passed but filename has one, use it
        if (empty($ext) && !empty($extension)) {
            $ext = '.' . $extension;
        }

        // Construct the cloud path for this file
        $cloudPath = $this->getCloudPathForFile($filename, $dir);

        // Check if file exists in cloud and make it unique if needed
        $uniqueFilename = $this->makeCloudFilenameUnique($name, $ext, $cloudPath);

        return $uniqueFilename;
    }

    /**
     * Check if full cloud migration is enabled (retention policy = 2)
     */
    private function isFullCloudMigrationEnabled(): bool
    {
        $settings = get_option('advmo_settings', []);
        $retention_policy = isset($settings['retention_policy']) ? intval($settings['retention_policy']) : 0;

        return $retention_policy === 2;
    }

    /**
     * Get the cloud path where this file would be stored
     */
    private function getCloudPathForFile(string $filename, string $localDir): string
    {
        // Get path prefix if enabled
        $pathPrefix = $this->getPathPrefix();

        // Get object version if enabled
        $objectVersion = $this->getObjectVersionForNewFile();

        // Determine if media is organized by year/month
        $isOrganizedByDate = advmo_is_media_organized_by_year_month();

        if ($isOrganizedByDate) {
            // Extract year/month from local directory
            $uploadDir = wp_upload_dir();
            $baseDir = trailingslashit($uploadDir['basedir']);
            $relativePath = str_replace($baseDir, '', trailingslashit($localDir));
            return $pathPrefix . $relativePath . $objectVersion;
        }

        return $pathPrefix . $objectVersion;
    }

    /**
     * Get path prefix for new files
     */
    private function getPathPrefix(): string
    {
        $settings = get_option('advmo_settings', []);
        $prefix_active = $settings['path_prefix_active'] ?? false;
        $path_prefix = $settings['path_prefix'] ?? '';

        if (!$prefix_active || empty($path_prefix)) {
            return '';
        }

        return trailingslashit(advmo_sanitize_path($path_prefix));
    }

    /**
     * Get object version for new files
     */
    private function getObjectVersionForNewFile(): string
    {
        $settings = get_option('advmo_settings', []);
        $object_versioning = isset($settings['object_versioning']) ? $settings['object_versioning'] : '0';

        // If versioning is not enabled, return empty string
        if (!$object_versioning) {
            return '';
        }

        // Generate a new version timestamp
        if (!advmo_is_media_organized_by_year_month()) {
            $new_version = date("YmdHis");
        } else {
            $new_version = date("dHis");
        }

        return trailingslashit($new_version);
    }

    /**
     * Make filename unique by checking cloud storage and appending numbers if needed
     */
    private function makeCloudFilenameUnique(string $name, string $ext, string $cloudPath): string
    {
        $originalFilename = $name . $ext;
        $filename = $originalFilename;
        $counter = 1;

        // Keep checking until we find a unique filename
        while ($this->cloudProvider->objectExists($cloudPath . $filename)) {
            $filename = $name . '-' . $counter . $ext;
            $counter++;

            // Safety check to prevent infinite loops
            if ($counter > 10) {
                // Fallback to timestamp-based uniqueness
                $filename = $name . '-' . time() . $ext;
                break;
            }
        }

        return $filename;
    }
}
