<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;

/**
 * Provide a readable local copy of selected cloud-only attachment types.
 *
 * WordPress and page builders call get_attached_file() in places that need a
 * real filesystem path. A persistent, private cache avoids one cloud download
 * per frontend request while keeping the attachment's original basename.
 */
class GetAttachedFileObserver implements ObserverInterface
{
    private const CACHE_DIRECTORY = 'advanced-media-offloader-attached-files';
    private const LEGACY_TEMP_META = 'advmo_tmp_file';

    /**
     * Attachment metadata changes that can make a cached cloud object stale.
     */
    private const CACHE_INVALIDATION_META_KEYS = [
        '_wp_attached_file',
        '_wp_attachment_metadata',
        'advmo_path',
        'advmo_object_version',
        'advmo_offloaded',
        'advmo_offloaded_at',
        'advmo_provider',
        'advmo_bucket',
    ];

    private S3_Provider $cloudProvider;

    private ?string $cacheRoot = null;

    /**
     * Files resolved during this request.
     *
     * @var array<int,array{canonical:string,cache:string}>
     */
    private array $resolvedFiles = [];

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
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
     * Return a cached local copy when a supported offloaded file is missing.
     *
     * @param mixed $file          Current attached-file path.
     * @param mixed $attachment_id Attachment ID.
     * @return mixed
     */
    public function filter($file, $attachment_id)
    {
        $attachmentId = (int) $attachment_id;
        $post = get_post($attachmentId);
        if (!$post || $post->post_type !== 'attachment') {
            return $file;
        }

        /**
         * Filters the MIME types that can be fetched into the local cache.
         *
         * @param string[] $supported_mime_types Supported MIME types.
         * @param int      $attachment_id        Attachment ID.
         */
        $supportedMimeTypes = apply_filters(
            'advmo_temp_fetch_mime_types',
            ['image/svg+xml'],
            $attachmentId
        );
        if (
            !is_array($supportedMimeTypes)
            || !in_array($post->post_mime_type, $supportedMimeTypes, true)
        ) {
            return $file;
        }

        $this->removeLegacyTemporaryFile($attachmentId);

        if (is_string($file) && is_file($file)) {
            return $file;
        }

        if (!(bool) get_post_meta($attachmentId, 'advmo_offloaded', true)) {
            return $file;
        }

        $canonical = get_attached_file($attachmentId, true);
        if (
            !is_string($canonical)
            || $canonical === ''
            || !$this->isPathInsideUploads($canonical)
            || !metadata_exists('post', $attachmentId, 'advmo_path')
        ) {
            return $file;
        }

        if (is_file($canonical)) {
            return $canonical;
        }

        if (isset($this->resolvedFiles[$attachmentId])) {
            $resolved = $this->resolvedFiles[$attachmentId];
            if (
                $this->pathsMatch($resolved['canonical'], $canonical)
                && $this->isUsableCacheFile($resolved['cache'])
            ) {
                return $resolved['cache'];
            }

            unset($this->resolvedFiles[$attachmentId]);
        }

        $cloudKey = (string) get_post_meta($attachmentId, 'advmo_path', true)
            . wp_basename($canonical);
        $cacheFile = $this->getCacheFile($attachmentId, $canonical, $cloudKey);
        if ($cacheFile === '') {
            return $file;
        }

        if (is_file($cacheFile) && !$this->isUsableCacheFile($cacheFile)) {
            wp_delete_file($cacheFile);
        }

        if (!$this->isUsableCacheFile($cacheFile)) {
            if (
                !$this->ensureCacheDirectory(dirname($cacheFile))
                || !$this->cloudProvider->downloadFile($cloudKey, $cacheFile)
            ) {
                error_log(sprintf(
                    'ADVMO: Failed to cache attachment %d from cloud object "%s".',
                    $attachmentId,
                    $cloudKey
                ));
                return $file;
            }
        }

        if (!$this->isUsableCacheFile($cacheFile)) {
            error_log(sprintf(
                'ADVMO: Cached attachment %d is not a readable, non-empty file.',
                $attachmentId
            ));
            return $file;
        }

        $this->resolvedFiles[$attachmentId] = [
            'canonical' => $canonical,
            'cache' => $cacheFile,
        ];

        return $cacheFile;
    }

    /**
     * Do not let a compatibility cache path replace _wp_attached_file.
     *
     * @param mixed $file          Proposed attached-file path.
     * @param mixed $attachment_id Attachment ID.
     * @return mixed
     */
    public function protectAttachedFileMetadata($file, $attachment_id)
    {
        if (!is_string($file) || $file === '') {
            return $file;
        }

        $attachmentId = (int) $attachment_id;
        $canonical = get_attached_file($attachmentId, true);
        if (!is_string($canonical) || $canonical === '' || !$this->isPathInsideUploads($canonical)) {
            return $file;
        }

        if (
            isset($this->resolvedFiles[$attachmentId])
            && $this->pathsMatch($file, $this->resolvedFiles[$attachmentId]['cache'])
        ) {
            return $canonical;
        }

        if (!metadata_exists('post', $attachmentId, 'advmo_path')) {
            return $file;
        }

        $cloudKey = (string) get_post_meta($attachmentId, 'advmo_path', true)
            . wp_basename($canonical);
        $expectedCacheFile = $this->getCacheFile($attachmentId, $canonical, $cloudKey);

        return $expectedCacheFile !== '' && $this->pathsMatch($file, $expectedCacheFile)
            ? $canonical
            : $file;
    }

    /**
     * Clear a cache after attachment metadata changes.
     *
     * @param mixed $meta_id    Metadata row ID or IDs.
     * @param mixed $object_id  Object ID.
     * @param mixed $meta_key   Metadata key.
     * @param mixed $meta_value Metadata value.
     */
    public function invalidateForMetaChange($meta_id, $object_id, $meta_key, $meta_value): void
    {
        if (
            is_string($meta_key)
            && in_array($meta_key, self::CACHE_INVALIDATION_META_KEYS, true)
        ) {
            $this->clearAttachmentCache((int) $object_id);
        }
    }

    /**
     * Remove every cached version owned by one attachment.
     */
    public function clearAttachmentCache(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        unset($this->resolvedFiles[$attachmentId]);
        $this->deleteDirectory($this->getAttachmentCacheDirectory($attachmentId));
        $this->removeLegacyTemporaryFile($attachmentId);
    }

    private function getCacheFile(int $attachmentId, string $canonical, string $cloudKey): string
    {
        $attachmentDirectory = $this->getAttachmentCacheDirectory($attachmentId);
        $basename = wp_basename($canonical);
        if ($attachmentDirectory === '' || $basename === '') {
            return '';
        }

        try {
            $providerToken = (string) $this->cloudProvider->getProviderName()
                . '|'
                . (string) $this->cloudProvider->getBucket();
        } catch (\Throwable $e) {
            error_log('ADVMO: Unable to build the attached-file cache path: ' . $e->getMessage());
            return '';
        }

        $objectToken = substr(hash('sha256', $providerToken . '|' . $cloudKey), 0, 20);

        return trailingslashit($attachmentDirectory)
            . $objectToken
            . '/'
            . $basename;
    }

    private function getAttachmentCacheDirectory(int $attachmentId): string
    {
        $root = $this->getCacheRoot();
        if ($root === '' || $attachmentId <= 0) {
            return '';
        }

        return trailingslashit($root) . $attachmentId;
    }

    private function getCacheRoot(): string
    {
        if ($this->cacheRoot !== null) {
            return $this->cacheRoot;
        }

        $temporaryDirectory = get_temp_dir();
        if (!is_string($temporaryDirectory) || $temporaryDirectory === '') {
            $this->cacheRoot = '';
            return $this->cacheRoot;
        }

        // tempnam() may resolve an operating-system symlink such as macOS
        // /var to /private/var. Use the resolved directory so the provider's
        // same-directory safety check accepts its atomic temporary file.
        $realTemporaryDirectory = realpath($temporaryDirectory);
        if ($realTemporaryDirectory !== false) {
            $temporaryDirectory = $realTemporaryDirectory;
        }

        $siteIdentity = (defined('ABSPATH') ? ABSPATH : '')
            . '|'
            . (string) get_current_blog_id();
        $siteToken = substr(hash('sha256', $siteIdentity), 0, 16);

        $this->cacheRoot = trailingslashit(wp_normalize_path($temporaryDirectory))
            . self::CACHE_DIRECTORY
            . '/'
            . $siteToken;

        return $this->cacheRoot;
    }

    private function isPathInsideUploads(string $path): bool
    {
        $uploadDirectory = wp_get_upload_dir();
        if (empty($uploadDirectory['basedir']) || !is_string($uploadDirectory['basedir'])) {
            return false;
        }

        $uploadsBase = trailingslashit($this->normalizePath($uploadDirectory['basedir']));
        $candidate = $this->normalizePath($path);

        return 0 === strpos($candidate, $uploadsBase);
    }

    private function isUsableCacheFile(string $file): bool
    {
        clearstatcache(true, $file);

        return is_file($file)
            && is_readable($file)
            && (int) filesize($file) > 0;
    }

    private function ensureCacheDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        // A concurrent request may create the directory after the first check.
        // Recheck after wp_mkdir_p() before treating its false result as a failure.
        return wp_mkdir_p($directory) || is_dir($directory);
    }

    private function pathsMatch(string $first, string $second): bool
    {
        return $this->normalizePath($first) === $this->normalizePath($second);
    }

    private function normalizePath(string $path): string
    {
        return untrailingslashit(wp_normalize_path($path));
    }

    /**
     * Remove the old download_url() cache without deleting an arbitrary path.
     */
    private function removeLegacyTemporaryFile(int $attachmentId): void
    {
        $legacyFile = get_post_meta($attachmentId, self::LEGACY_TEMP_META, true);
        if (!is_string($legacyFile) || $legacyFile === '') {
            return;
        }

        delete_post_meta($attachmentId, self::LEGACY_TEMP_META);

        $realFile = realpath($legacyFile);
        $realTemporaryDirectory = realpath(get_temp_dir());
        if ($realFile === false || $realTemporaryDirectory === false) {
            return;
        }

        $temporaryBase = trailingslashit($this->normalizePath($realTemporaryDirectory));
        if (0 === strpos($this->normalizePath($realFile), $temporaryBase) && is_file($realFile)) {
            wp_delete_file($realFile);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $root = trailingslashit($this->normalizePath($this->getCacheRoot()));
        $candidate = $this->normalizePath($directory);
        if ($root === '/' || 0 !== strpos($candidate, $root)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    @rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only empty plugin-created temporary directories.
                } else {
                    wp_delete_file($item->getPathname());
                }
            }
        } catch (\UnexpectedValueException $e) {
            error_log('ADVMO: Unable to read the attached-file cache: ' . $e->getMessage());
            return;
        }

        @rmdir($directory); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only an empty plugin-created temporary directory.
    }
}
