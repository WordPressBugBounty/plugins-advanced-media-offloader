<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class EWWWCompatObserver implements ObserverInterface
{
    use OffloaderTrait;

    private S3_Provider $cloudProvider;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    public function register(): void
    {
        add_filter('advmo_local_deletion_rule', [$this, 'deferLocalDeletion'], 10, 2);
        add_action('ewww_image_optimizer_after_optimize_attachment', [$this, 'afterEWWWOptimize'], 10, 2);
        add_action('advmo_after_upload_to_cloud', [$this, 'afterAdvmoUpload'], 10, 1);
        add_filter('webp_allowed_urls', [$this, 'addCloudDomainToAllowedUrls']);
        add_filter('ewww_image_optimizer_skip_webp_rewrite', [$this, 'skipWebPRewriteIfNoWebP'], 10, 2);
        add_filter('advmo_attachment_delete_keys', [$this, 'addWebPDeleteKeys'], 10, 3);
        add_action('advmo_reoffload_attachment', [$this, 'onReoffload'], 10, 2);
    }

    public static function isEWWWActive(): bool
    {
        return defined('EWWW_IMAGE_OPTIMIZER_VERSION') && function_exists('ewww_image_optimizer_get_option');
    }

    public static function isEWWWAutoOptimizeEnabled(): bool
    {
        if (!self::isEWWWActive()) {
            return false;
        }

        return !ewww_image_optimizer_get_option('ewww_image_optimizer_noauto');
    }

    public static function isEWWWWebPEnabled(): bool
    {
        if (!self::isEWWWActive()) {
            return false;
        }

        return (bool) ewww_image_optimizer_get_option('ewww_image_optimizer_webp');
    }

    /**
     * When EWWW auto-optimize is active and retention policy would delete
     * local files, defer the deletion so EWWW can process thumbnails first.
     */
    public function deferLocalDeletion(int $deletion_rule, int $attachment_id): int
    {
        if ($deletion_rule === 0) {
            return 0;
        }

        if (!self::isEWWWAutoOptimizeEnabled()) {
            return $deletion_rule;
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return $deletion_rule;
        }

        update_post_meta($attachment_id, '_advmo_ewww_deferred_retention', $deletion_rule);

        return 0;
    }

    /**
     * After ADVMO uploads to cloud (synchronous EWWW mode).
     *
     * In synchronous mode, EWWW has already optimized and created WebP
     * files before ADVMO runs. Upload the WebP sidecar files and apply
     * the deferred retention policy.
     *
     * In background mode, EWWW hasn't processed yet, so we skip here
     * and let afterEWWWOptimize() handle it later.
     */
    public function afterAdvmoUpload(int $attachment_id): void
    {
        if (!self::isEWWWActive()) {
            return;
        }

        $deferred = get_post_meta($attachment_id, '_advmo_ewww_deferred_retention', true);
        if ($deferred === '' || $deferred === false) {
            return;
        }

        if (function_exists('ewww_image_optimizer_test_background_opt') && ewww_image_optimizer_test_background_opt()) {
            return;
        }

        $advmo_path = $this->get_attachment_subdir($attachment_id);
        if (empty($advmo_path)) {
            return;
        }

        $webp_uploaded = true;
        if (self::isEWWWWebPEnabled()) {
            $webp_uploaded = $this->uploadWebPFiles($attachment_id, $advmo_path);
        }

        if (!$webp_uploaded) {
            error_log("ADVMO EWWW Compat: Skipping local deletion for attachment {$attachment_id} - WebP upload incomplete");
            return;
        }

        $policy = intval($deferred);
        delete_post_meta($attachment_id, '_advmo_ewww_deferred_retention');

        if ($policy > 0) {
            $uploader = new CloudAttachmentUploader($this->cloudProvider);
            $uploader->deleteLocalFile($attachment_id, $policy);
            $this->deleteWebPLocalFiles($attachment_id, $policy);
        }
    }

    /**
     * After EWWW finishes optimizing (both sync and background).
     *
     * In background mode, this fires after ADVMO has already offloaded
     * unoptimized files. Re-upload the optimized versions and any WebP
     * sidecar files, then apply deferred retention.
     *
     * In synchronous mode, ADVMO hasn't offloaded yet (not offloaded),
     * so we skip -- afterAdvmoUpload() handles that case.
     *
     * Also handles manual/bulk optimization of already-offloaded images.
     */
    public function afterEWWWOptimize(int $attachment_id, array $meta): void
    {
        if (!$this->is_offloaded($attachment_id)) {
            return;
        }

        $advmo_path = get_post_meta($attachment_id, 'advmo_path', true);
        if (empty($advmo_path)) {
            return;
        }

        $standard_uploaded = $this->reUploadOptimizedFiles($attachment_id, $advmo_path, $meta);

        $webp_uploaded = true;
        if (self::isEWWWWebPEnabled()) {
            $webp_uploaded = $this->uploadWebPFiles($attachment_id, $advmo_path, $meta);
        }

        if (!$standard_uploaded || !$webp_uploaded) {
            return;
        }

        $deferred = get_post_meta($attachment_id, '_advmo_ewww_deferred_retention', true);

        if ($deferred !== '' && $deferred !== false) {
            $policy = intval($deferred);
            delete_post_meta($attachment_id, '_advmo_ewww_deferred_retention');
        } else {
            $policy = $this->shouldDeleteLocal();
        }

        if ($policy > 0) {
            $uploader = new CloudAttachmentUploader($this->cloudProvider);
            $uploader->deleteLocalFile($attachment_id, $policy);
            $this->deleteWebPLocalFiles($attachment_id, $policy);
        }
    }

    /**
     * Inject the cloud storage domain into EWWW's Force WebP allowed URLs.
     *
     * This tells EWWW's Picture WebP and JS WebP delivery that images
     * served from the cloud domain have WebP variants, enabling
     * <picture> tag or JS-based WebP rewriting for offloaded images.
     */
    public function addCloudDomainToAllowedUrls(array $urls): array
    {
        $domain = $this->cloudProvider->getDomain();
        if (!empty($domain)) {
            $urls[] = rtrim($domain, '/');
        }
        return $urls;
    }

    /**
     * Skip WebP rewriting for cloud images where EWWW did not
     * successfully generate a WebP version.
     *
     * Queries EWWW's ewwwio_images table by file path. If no record
     * exists or webp_size is 0 (conversion failed / never attempted),
     * returns true so EWWW does not emit a <picture> tag pointing
     * to a non-existent .webp file.
     *
     * Runs before the Force WebP blanket check, giving per-image accuracy.
     */
    public function skipWebPRewriteIfNoWebP(bool $skip, string $image_url): bool
    {
        if ($skip) {
            return $skip;
        }

        $domain = $this->cloudProvider->getDomain();
        if (empty($domain)) {
            return $skip;
        }

        $domain = rtrim($domain, '/');
        if (strpos($image_url, $domain) === false) {
            return $skip;
        }

        static $cache = [];
        if (isset($cache[$image_url])) {
            return $cache[$image_url];
        }

        $relative_path = str_replace($domain . '/', '', $image_url);
        $relative_path = strtok($relative_path, '?#');

        $basename = wp_basename($relative_path);
        $upload_dir = wp_get_upload_dir();
        $uploads_rel = str_replace(trailingslashit(ABSPATH), '', trailingslashit($upload_dir['basedir']));

        if (preg_match('#(\d{4}/\d{2})/#', $relative_path, $matches)) {
            $local_rel = $uploads_rel . $matches[1] . '/' . $basename;
        } else {
            $local_rel = $uploads_rel . $basename;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ewwwio_images';
        $ewww_path = 'ABSPATH' . $local_rel;
        $absolute_path = trailingslashit(ABSPATH) . $local_rel;

        $webp_size = $wpdb->get_var($wpdb->prepare(
            "SELECT webp_size FROM {$table_name} WHERE path IN (%s, %s) LIMIT 1",
            $ewww_path,
            $absolute_path
        ));

        $should_skip = empty($webp_size);
        $cache[$image_url] = $should_skip;

        return $should_skip;
    }

    /**
     * Upload EWWW WebP sidecar files during a reoffload.
     *
     * @param int    $attachment_id The attachment ID.
     * @param string $advmo_path    The cloud storage path prefix.
     */
    public function onReoffload(int $attachment_id, string $advmo_path): void
    {
        if (!self::isEWWWActive() || !self::isEWWWWebPEnabled()) {
            return;
        }

        $this->uploadWebPFiles($attachment_id, $advmo_path);
    }

    /**
     * Append EWWW WebP sidecar keys to the list of S3 objects
     * to delete when an attachment is removed.
     *
     * Covers both naming modes (append and replace) in case the
     * mode was changed after the files were originally created.
     *
     * @param string[] $keys          Object keys collected so far.
     * @param int      $attachment_id The attachment being deleted.
     * @param string   $base_dir      The base directory prefix.
     * @return string[]
     */
    public function addWebPDeleteKeys(array $keys, int $attachment_id, string $base_dir): array
    {
        $metadata = wp_get_attachment_metadata($attachment_id);

        $main_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $main_basename = $main_file ? wp_basename($main_file) : '';

        $basenames = [];

        if (!empty($main_basename)) {
            $basenames[] = $main_basename;
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeinfo) {
                if (!empty($sizeinfo['file'])) {
                    $basenames[] = $sizeinfo['file'];
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $basenames[] = $metadata['original_image'];
        }

        foreach ($basenames as $basename) {
            $webp_names = $this->getAllWebPBasenames($basename);
            foreach ($webp_names as $webp_name) {
                $keys[] = $base_dir . $webp_name;
            }
        }

        return $keys;
    }

    /**
     * Re-upload standard files (main, thumbnails, original) which EWWW
     * has optimized in-place, overwriting the unoptimized cloud copies.
     */
    private function reUploadOptimizedFiles(int $attachment_id, string $advmo_path, ?array $meta = null): bool
    {
        $all_uploaded = true;
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));

        if (file_exists($base_file)) {
            try {
                $uploaded = $this->cloudProvider->uploadFile($base_file, $advmo_path . wp_basename($base_file));
                if (!$uploaded) {
                    $all_uploaded = false;
                }
            } catch (\Exception $e) {
                $all_uploaded = false;
            }
        }

        $metadata = $meta ?? wp_get_attachment_metadata($attachment_id);

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                $size_file = $file_dir . $data['file'];
                if (file_exists($size_file)) {
                    try {
                        $uploaded = $this->cloudProvider->uploadFile($size_file, $advmo_path . $data['file']);
                        if (!$uploaded) {
                            $all_uploaded = false;
                        }
                    } catch (\Exception $e) {
                        $all_uploaded = false;
                    }
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $original_image = wp_get_original_image_path($attachment_id);
            if ($original_image && file_exists($original_image)) {
                try {
                    $uploaded = $this->cloudProvider->uploadFile($original_image, $advmo_path . wp_basename($original_image));
                    if (!$uploaded) {
                        $all_uploaded = false;
                    }
                } catch (\Exception $e) {
                    $all_uploaded = false;
                }
            }
        }

        return $all_uploaded;
    }

    /**
     * Upload EWWW-generated WebP sidecar files to cloud.
     *
     * Discovers WebP files by checking the filesystem using EWWW's
     * naming conventions (append or replace mode).
     */
    private function uploadWebPFiles(int $attachment_id, string $advmo_path, ?array $meta = null): bool
    {
        $all_uploaded = true;
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));
        $metadata = $meta ?? wp_get_attachment_metadata($attachment_id);

        $files_to_check = [$base_file];

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $data) {
                if (!empty($data['file'])) {
                    $files_to_check[] = $file_dir . $data['file'];
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $original_image = wp_get_original_image_path($attachment_id);
            if ($original_image) {
                $files_to_check[] = $original_image;
            }
        }

        $checked = [];
        foreach ($files_to_check as $file_path) {
            $webp_path = $this->getWebPPath($file_path);
            if (!$webp_path || isset($checked[$webp_path])) {
                continue;
            }
            $checked[$webp_path] = true;

            if (!file_exists($webp_path)) {
                continue;
            }

            $cloud_key = $advmo_path . wp_basename($webp_path);

            try {
                $uploaded = $this->cloudProvider->uploadFile($webp_path, $cloud_key);
                if (!$uploaded) {
                    $all_uploaded = false;
                }
            } catch (\Exception $e) {
                $all_uploaded = false;
                error_log("ADVMO EWWW Compat: Error uploading {$webp_path}: " . $e->getMessage());
            }
        }

        return $all_uploaded;
    }

    /**
     * Delete EWWW WebP sidecar files from local disk.
     * Called after the standard deleteLocalFile has handled normal sizes.
     */
    private function deleteWebPLocalFiles(int $attachment_id, int $retention_policy): void
    {
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeinfo) {
                if (empty($sizeinfo['file'])) {
                    continue;
                }
                $sized_file = $file_dir . $sizeinfo['file'];
                $webp_path = $this->getWebPPath($sized_file);
                if ($webp_path && file_exists($webp_path)) {
                    wp_delete_file($webp_path);
                }
            }
        }

        if ($retention_policy === 2) {
            $webp_path = $this->getWebPPath($base_file);
            if ($webp_path && file_exists($webp_path)) {
                wp_delete_file($webp_path);
            }

            if (!empty($metadata['original_image'])) {
                $original_image_path = wp_get_original_image_path($attachment_id);
                if ($original_image_path) {
                    $webp_path = $this->getWebPPath($original_image_path);
                    if ($webp_path && file_exists($webp_path)) {
                        wp_delete_file($webp_path);
                    }
                }
            }
        }
    }

    /**
     * Get the WebP file path for a given source image using EWWW's
     * naming convention (respects append/replace mode).
     *
     * @param string $file Full filesystem path to the source image.
     * @return string|false WebP path, or false if EWWW functions unavailable.
     */
    private function getWebPPath(string $file)
    {
        if (function_exists('ewww_image_optimizer_get_webp_path')) {
            return ewww_image_optimizer_get_webp_path($file);
        }

        return $file . '.webp';
    }

    /**
     * Get all possible WebP basenames for a given file basename.
     *
     * Returns both append and replace naming patterns to ensure cloud
     * cleanup works regardless of which naming mode was active.
     *
     * @param string $basename The source file basename (e.g. "photo.jpg").
     * @return string[] Array of WebP basenames.
     */
    private function getAllWebPBasenames(string $basename): array
    {
        if (function_exists('ewww_image_optimizer_get_all_webp_paths')) {
            $paths = ewww_image_optimizer_get_all_webp_paths($basename);
            return array_filter($paths, 'strlen');
        }

        $info = pathinfo($basename);
        $append = $basename . '.webp';
        $replace = $info['filename'] . '.webp';

        if ($append === $replace) {
            return [$append];
        }

        return [$append, $replace];
    }
}
