<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class ImagifyCompatObserver implements ObserverInterface
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
        add_action('imagify_after_optimize', [$this, 'afterImagifyOptimize'], 10, 2);
        add_filter('imagify_webp_picture_process_image', [$this, 'pictureTagProcessImage']);
        add_filter('advmo_attachment_delete_keys', [$this, 'addNextGenDeleteKeys'], 10, 3);
        add_action('advmo_reoffload_attachment', [$this, 'onReoffload'], 10, 2);
    }

    public static function isImagifyActive(): bool
    {
        return defined('IMAGIFY_VERSION') && class_exists('Imagify_Options');
    }

    public static function isImagifyAutoOptimizeEnabled(): bool
    {
        if (!self::isImagifyActive()) {
            return false;
        }

        return (bool) \Imagify_Options::get_instance()->get('auto_optimize');
    }

    /**
     * When Imagify auto-optimize is active and retention policy would delete
     * local files, defer the deletion so Imagify can process thumbnails first.
     */
    public function deferLocalDeletion(int $deletion_rule, int $attachment_id): int
    {
        if ($deletion_rule === 0) {
            return 0;
        }

        if (!self::isImagifyAutoOptimizeEnabled()) {
            return $deletion_rule;
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return $deletion_rule;
        }

        update_post_meta($attachment_id, '_advmo_imagify_deferred_retention', $deletion_rule);

        return 0;
    }

    /**
     * After Imagify finishes optimizing, re-upload all optimized files
     * and any next-gen (WebP/AVIF) sidecar files, then apply the
     * user's original retention policy.
     */
    public function afterImagifyOptimize($process, $item): void
    {
        $media_id = $item['id'] ?? 0;
        if (!$media_id) {
            return;
        }

        if (!$this->is_offloaded($media_id)) {
            return;
        }

        $advmo_path = get_post_meta($media_id, 'advmo_path', true);
        if (empty($advmo_path)) {
            return;
        }

        $standard_uploaded = $this->reUploadOptimizedFiles($media_id, $advmo_path);
        $nextgen_uploaded = $this->uploadNextGenFiles($media_id, $advmo_path);

        // Never delete local files if cloud re-upload was not fully successful.
        if (!$standard_uploaded || !$nextgen_uploaded) {
            error_log("ADVMO Imagify Compat: Skipping local deletion for attachment {$media_id} - cloud upload incomplete");
            return;
        }

        $deferred_retention = get_post_meta($media_id, '_advmo_imagify_deferred_retention', true);
        
        if ($deferred_retention !== '' && $deferred_retention !== false) {
            // New upload: use the deferred retention policy
            $retention_policy = intval($deferred_retention);
            delete_post_meta($media_id, '_advmo_imagify_deferred_retention');
        } else {
            // Regeneration or re-optimization: use current settings
            $retention_policy = $this->shouldDeleteLocal();
        }

        if ($retention_policy === 0) {
            return;
        }

        $uploader = new CloudAttachmentUploader($this->cloudProvider);
        $uploader->deleteLocalFile($media_id, $retention_policy);
        $this->deleteNextGenLocalFiles($media_id, $retention_policy);
    }

    /**
     * Append Imagify WebP/AVIF sidecar keys to the list of S3 objects
     * to delete when an attachment is removed.
     *
     * @param string[] $keys          Object keys collected so far.
     * @param int      $attachment_id The attachment being deleted.
     * @param string   $base_dir      The base directory prefix.
     * @return string[]
     */
    public function addNextGenDeleteKeys(array $keys, int $attachment_id, string $base_dir): array
    {
        $imagify_data = get_post_meta($attachment_id, '_imagify_data', true);
        if (empty($imagify_data['sizes']) || !is_array($imagify_data['sizes'])) {
            return $keys;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $extensions = ['.webp', '.avif'];

        $main_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $main_basename = $main_file ? wp_basename($main_file) : '';

        foreach ($extensions as $ext) {
            if (!empty($main_basename)) {
                $keys[] = $base_dir . $main_basename . $ext;
            }

            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $sizeinfo) {
                    if (!empty($sizeinfo['file'])) {
                        $keys[] = $base_dir . $sizeinfo['file'] . $ext;
                    }
                }
            }

            if (!empty($metadata['original_image'])) {
                $keys[] = $base_dir . $metadata['original_image'] . $ext;
            }
        }

        return $keys;
    }

    /**
     * Upload Imagify WebP/AVIF sidecar files during a reoffload.
     *
     * @param int    $attachment_id The attachment ID.
     * @param string $advmo_path    The cloud storage path prefix.
     */
    public function onReoffload(int $attachment_id, string $advmo_path): void
    {
        if (!self::isImagifyActive()) {
            return;
        }

        $this->uploadNextGenFiles($attachment_id, $advmo_path);
    }

    /**
     * Tell Imagify that WebP/AVIF versions exist on cloud storage so it
     * can generate <picture> tags for offloaded images.
     *
     * Modeled after Imagify's built-in AS3CF integration
     * (Imagify\ThirdParty\AS3CF\Main::picture_tag_webp_image).
     */
    public function pictureTagProcessImage($data)
    {
        global $wpdb;

        if (!is_array($data)) {
            return $data;
        }

        if (!empty($data['src']['webp_path'])) {
            return $data;
        }

        $match = $this->parseAdvmoUrl($data['src']['url'] ?? '');

        if (!$match) {
            return $data;
        }

        $post_id = $this->resolveAttachmentId($data, $match);

        if ($post_id <= 0) {
            return $data;
        }

        $imagify_data = get_post_meta($post_id, '_imagify_data', true);

        if (!$imagify_data || empty($imagify_data['sizes']) || !is_array($imagify_data['sizes'])) {
            return $data;
        }

        $webp_suffix = '@imagify-webp';
        $avif_suffix = '@imagify-avif';

        $src_size = $this->resolveSizeNameForUrl($post_id, $match['filename']);

        if (!empty($imagify_data['sizes'][$src_size . $webp_suffix]['success'])) {
            $data['src']['webp_exists'] = true;
        }
        if (!empty($imagify_data['sizes'][$src_size . $avif_suffix]['success'])) {
            $data['src']['avif_exists'] = true;
        }

        if (empty($data['srcset']) || !is_array($data['srcset'])) {
            return $data;
        }

        $metadata = wp_get_attachment_metadata($post_id);

        if (empty($metadata['sizes'])) {
            return $data;
        }

        $size_files = [];
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            $size_files[$size_data['file']] = $size_name;
        }

        $full_filename = !empty($metadata['file']) ? wp_basename($metadata['file']) : '';

        foreach ($data['srcset'] as $i => $srcset_data) {
            if (empty($srcset_data['webp_url'])) {
                continue;
            }
            if (!empty($srcset_data['webp_path'])) {
                continue;
            }

            $srcset_match = $this->parseAdvmoUrl($srcset_data['url'] ?? '');

            if (!$srcset_match) {
                continue;
            }

            $filename = $srcset_match['filename'];

            if ($full_filename && $filename === $full_filename) {
                $size_name = 'full';
            } elseif (isset($size_files[$filename])) {
                $size_name = $size_files[$filename];
            } else {
                continue;
            }

            if (!empty($imagify_data['sizes'][$size_name . $webp_suffix]['success'])) {
                $data['srcset'][$i]['webp_exists'] = true;
            }
            if (!empty($imagify_data['sizes'][$size_name . $avif_suffix]['success'])) {
                $data['srcset'][$i]['avif_exists'] = true;
            }
        }

        return $data;
    }

    /**
     * Parse an image URL to check if it is an ADVMO cloud URL.
     *
     * Mirrors AS3CF's is_s3_url() approach: validates the URL against
     * the configured cloud domain and extracts structural components.
     *
     * @return array|false Array with 'path', 'year_month', 'filename' on success. False otherwise.
     */
    private function parseAdvmoUrl(string $url)
    {
        if (empty($url)) {
            return false;
        }

        $domain = $this->cloudProvider->getDomain();
        if (empty($domain)) {
            return false;
        }

        $domain = rtrim($domain, '/');

        if (stripos($url, $domain) !== 0) {
            return false;
        }

        $relative_path = ltrim(substr($url, strlen($domain)), '/');

        if (empty($relative_path)) {
            return false;
        }

        $filename = wp_basename($relative_path);

        $year_month = '';
        if (preg_match('@(?:^|/)(\d{4}/\d{2})/@', $relative_path, $ym_match)) {
            $year_month = $ym_match[1] . '/';
        }

        return [
            'path'       => $relative_path,
            'year_month' => $year_month,
            'filename'   => $filename,
        ];
    }

    /**
     * Resolve attachment ID from image data.
     * Primary: wp-image-{id} class. Fallback: DB query on _wp_attached_file.
     */
    private function resolveAttachmentId(array $data, array $url_match): int
    {
        static $resolved_ids = [];

        $class_attr = $data['attributes']['class'] ?? '';
        $cache_key = $class_attr . '|' . ($url_match['year_month'] ?? '') . '|' . ($url_match['filename'] ?? '');

        if (array_key_exists($cache_key, $resolved_ids)) {
            return $resolved_ids[$cache_key];
        }

        if (!empty($data['attributes']['class'])) {
            if (preg_match('/wp-image-(\d+)/', $data['attributes']['class'], $matches)) {
                $id = (int) $matches[1];
                if ($id > 0 && $this->is_offloaded($id)) {
                    $resolved_ids[$cache_key] = $id;
                    return $id;
                }
            }
        }

        if (empty($url_match['year_month']) || empty($url_match['filename'])) {
            $resolved_ids[$cache_key] = 0;
            return 0;
        }

        global $wpdb;

        $post_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
                $url_match['year_month'] . $url_match['filename']
            )
        );

        if ($post_id > 0 && $this->is_offloaded($post_id)) {
            $resolved_ids[$cache_key] = $post_id;
            return $post_id;
        }

        $resolved_ids[$cache_key] = 0;
        return 0;
    }

    /**
     * Determine the Imagify size name for a given filename.
     * Returns 'full' for the main file, or the registered size name for thumbnails.
     */
    private function resolveSizeNameForUrl(int $attachment_id, string $filename): string
    {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!empty($metadata['file']) && wp_basename($metadata['file']) === $filename) {
            return 'full';
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if ($size_data['file'] === $filename) {
                    return $size_name;
                }
            }
        }

        return 'full';
    }

    /**
     * Re-upload standard files (main, thumbnails, original) which Imagify
     * has optimized in-place, overwriting the unoptimized cloud copies.
     */
    private function reUploadOptimizedFiles(int $attachment_id, string $advmo_path): bool
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
                error_log("ADVMO Imagify Compat: Error uploading {$base_file}: " . $e->getMessage());
            }
        }

        $metadata = wp_get_attachment_metadata($attachment_id);

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
                        error_log("ADVMO Imagify Compat: Error uploading {$size_file}: " . $e->getMessage());
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
                    error_log("ADVMO Imagify Compat: Error uploading {$original_image}: " . $e->getMessage());
                }
            }
        }

        return $all_uploaded;
    }

    /**
     * Upload Imagify-generated next-gen (WebP/AVIF) sidecar files to cloud.
     * 
     * Scans for actual files on disk rather than relying on _imagify_data success
     * flags, because during async thumbnail regeneration the metadata may not be
     * updated yet when our hook fires.
     */
    private function uploadNextGenFiles(int $attachment_id, string $advmo_path): bool
    {
        $all_uploaded = true;
        $metadata = wp_get_attachment_metadata($attachment_id);
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));

        $extensions = ['.webp', '.avif'];
        $files_to_upload = [];

        // Check main file for next-gen versions
        if (file_exists($base_file)) {
            foreach ($extensions as $ext) {
                $nextgen_path = $base_file . $ext;
                if (file_exists($nextgen_path)) {
                    $files_to_upload[] = $nextgen_path;
                }
            }
        }

        // Check all thumbnail sizes for next-gen versions
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (empty($size_data['file'])) {
                    continue;
                }
                $size_path = $file_dir . $size_data['file'];
                foreach ($extensions as $ext) {
                    $nextgen_path = $size_path . $ext;
                    if (file_exists($nextgen_path)) {
                        $files_to_upload[] = $nextgen_path;
                    }
                }
            }
        }

        // Check original image (WP 5.3+) for next-gen versions
        if (!empty($metadata['original_image'])) {
            $original_path = $file_dir . $metadata['original_image'];
            foreach ($extensions as $ext) {
                $nextgen_path = $original_path . $ext;
                if (file_exists($nextgen_path)) {
                    $files_to_upload[] = $nextgen_path;
                }
            }
        }

        // Upload all found next-gen files
        foreach ($files_to_upload as $nextgen_path) {
            $cloud_key = $advmo_path . wp_basename($nextgen_path);

            try {
                $uploaded = $this->cloudProvider->uploadFile($nextgen_path, $cloud_key);
                if (!$uploaded) {
                    $all_uploaded = false;
                }
            } catch (\Exception $e) {
                $all_uploaded = false;
                error_log("ADVMO Imagify Compat: Error uploading {$nextgen_path}: " . $e->getMessage());
            }
        }

        return $all_uploaded;
    }

    /**
     * Delete Imagify next-gen sidecar files from local disk.
     * Called after the standard deleteLocalFile has handled normal sizes.
     */
    private function deleteNextGenLocalFiles(int $attachment_id, int $retention_policy): void
    {
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));
        $metadata = wp_get_attachment_metadata($attachment_id);

        $extensions = ['.webp', '.avif'];

        // Thumbnails: always delete next-gen sidecar files alongside their originals
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeinfo) {
                $sized_file = $file_dir . $sizeinfo['file'];
                foreach ($extensions as $ext) {
                    $nextgen = $sized_file . $ext;
                    if (file_exists($nextgen)) {
                        wp_delete_file($nextgen);
                    }
                }
            }
        }

        // Full migration: also delete next-gen for the main file and original
        if ($retention_policy === 2) {
            foreach ($extensions as $ext) {
                $nextgen = $base_file . $ext;
                if (file_exists($nextgen)) {
                    wp_delete_file($nextgen);
                }
            }

            if (!empty($metadata['original_image'])) {
                $original_image_path = wp_get_original_image_path($attachment_id);
                if ($original_image_path) {
                    foreach ($extensions as $ext) {
                        $nextgen = $original_image_path . $ext;
                        if (file_exists($nextgen)) {
                            wp_delete_file($nextgen);
                        }
                    }
                }
            }
        }
    }
}
