<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media;

use Advanced_Media_Offloader\Interfaces\ObserverInterface;

/**
 * Gives get_attached_file() a readable local copy of supported cloud-only files,
 * for the page builders and plugins that open the file itself.
 */
class AttachedFileObserver implements ObserverInterface
{
    private const LEGACY_TEMP_META = 'advmo_tmp_file';

    private const CACHE_META_KEYS = [
        '_wp_attached_file',
        '_wp_attachment_metadata',
        'advmo_path',
        'advmo_object_version',
        'advmo_offloaded',
        'advmo_offloaded_at',
        'advmo_provider',
        'advmo_bucket',
    ];

    public function __construct(private AttachedFileCache $cache)
    {
    }

    public function register(): void
    {
        add_filter('get_attached_file', [$this, 'filter'], 10, 2);
        add_filter('update_attached_file', [$this, 'protectAttachedFileMetadata'], PHP_INT_MAX, 2);

        add_action('added_post_meta', [$this, 'invalidateForMetaChange'], 10, 4);
        add_action('updated_post_meta', [$this, 'invalidateForMetaChange'], 10, 4);
        add_action('deleted_post_meta', [$this, 'invalidateForMetaChange'], 10, 4);
        add_action('advmo_reoffload_attachment', [$this, 'clearAttachmentCache'], 10, 1);
        add_action('deleted_post', [$this, 'clearAttachmentCache'], 10, 1);
    }

    /**
     * @param mixed $file
     * @param mixed $attachmentId
     * @return mixed
     */
    public function filter($file, $attachmentId)
    {
        $attachmentId = (int) $attachmentId;
        if (!$this->isSupported($attachmentId)) {
            return $file;
        }

        $this->removeLegacyTemporaryFile($attachmentId);

        if ((is_string($file) && is_file($file)) || !get_post_meta($attachmentId, 'advmo_offloaded', true)) {
            return $file;
        }

        $canonical = $this->canonicalPath($attachmentId);
        if ($canonical === '') {
            return $file;
        }

        if (is_file($canonical)) {
            return $canonical;
        }

        return $this->cache->fetch($attachmentId, $canonical) ?? $file;
    }

    /**
     * Keeps a cache path from being saved as _wp_attached_file.
     *
     * @param mixed $file
     * @param mixed $attachmentId
     * @return mixed
     */
    public function protectAttachedFileMetadata($file, $attachmentId)
    {
        if (!is_string($file) || $file === '') {
            return $file;
        }

        $attachmentId = (int) $attachmentId;
        $canonical = $this->canonicalPath($attachmentId);
        if ($canonical === '') {
            return $file;
        }

        $cached = $this->cache->path($attachmentId, $canonical);

        return $this->normalize($file) === $this->normalize($cached) ? $canonical : $file;
    }

    /**
     * @param mixed $metaId
     * @param mixed $objectId
     * @param mixed $metaKey
     * @param mixed $metaValue
     */
    public function invalidateForMetaChange($metaId, $objectId, $metaKey, $metaValue): void
    {
        if (is_string($metaKey) && in_array($metaKey, self::CACHE_META_KEYS, true)) {
            $this->clearAttachmentCache((int) $objectId);
        }
    }

    public function clearAttachmentCache(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        $this->cache->clear($attachmentId);
        $this->removeLegacyTemporaryFile($attachmentId);
    }

    private function isSupported(int $attachmentId): bool
    {
        $post = get_post($attachmentId);
        if (!$post || $post->post_type !== 'attachment') {
            return false;
        }

        /**
         * Filters the MIME types that get a local copy of a cloud-only file.
         *
         * @param string[] $supported_mime_types Supported MIME types.
         * @param int      $attachment_id        Attachment ID.
         */
        $mimeTypes = apply_filters('advmo_temp_fetch_mime_types', ['image/svg+xml'], $attachmentId);

        return is_array($mimeTypes) && in_array($post->post_mime_type, $mimeTypes, true);
    }

    private function canonicalPath(int $attachmentId): string
    {
        $canonical = get_attached_file($attachmentId, true);
        if (
            !is_string($canonical)
            || $canonical === ''
            || !$this->isInsideUploads($canonical)
            || !metadata_exists('post', $attachmentId, 'advmo_path')
        ) {
            return '';
        }

        return $canonical;
    }

    private function isInsideUploads(string $path): bool
    {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir']) || !is_string($uploads['basedir'])) {
            return false;
        }

        return strpos($this->normalize($path), trailingslashit($this->normalize($uploads['basedir']))) === 0;
    }

    private function normalize(string $path): string
    {
        return untrailingslashit(wp_normalize_path($path));
    }

    /**
     * 4.0.2 to 4.5.0 kept a download_url() copy in advmo_tmp_file. Delete it, but only
     * when it really is inside the temporary folder.
     */
    private function removeLegacyTemporaryFile(int $attachmentId): void
    {
        $legacyFile = get_post_meta($attachmentId, self::LEGACY_TEMP_META, true);
        if (!is_string($legacyFile) || $legacyFile === '') {
            return;
        }

        delete_post_meta($attachmentId, self::LEGACY_TEMP_META);

        $realFile = realpath($legacyFile);
        $temporary = realpath(get_temp_dir());
        if ($realFile === false || $temporary === false) {
            return;
        }

        if (strpos($this->normalize($realFile), trailingslashit($this->normalize($temporary))) === 0 && is_file($realFile)) {
            wp_delete_file($realFile);
        }
    }
}
