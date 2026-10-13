<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use AdvancedMediaOffloader\Storage\ObjectKey;

/**
 * Private local copies of cloud-only attachments, for code that needs a real file.
 */
class AttachedFileCache
{
    private const DIRECTORY = 'advanced-media-offloader-attached-files';

    private ?string $root = null;

    public function __construct(
        private S3_Provider $cloudProvider,
        private FailedDownloads $failedDownloads,
    ) {
    }

    /**
     * Where the attachment's current cloud object is cached, or '' when it cannot be.
     */
    public function path(int $attachmentId, string $canonical): string
    {
        $directory = $this->attachmentDirectory($attachmentId);
        $basename = wp_basename($canonical);
        if ($directory === '' || $basename === '') {
            return '';
        }

        try {
            $bucket = $this->cloudProvider->getProviderName() . '|' . $this->cloudProvider->getBucket();
        } catch (\Throwable $e) {
            error_log('ADVMO: Unable to build the attached-file cache path: ' . $e->getMessage());
            return '';
        }

        $key = $this->keys($attachmentId, $canonical)[0];
        $token = substr(hash('sha256', $bucket . '|' . $key), 0, 20);

        return "{$directory}/{$token}/{$basename}";
    }

    /**
     * The cached copy, downloaded first if needed. Null when it cannot be had.
     */
    public function fetch(int $attachmentId, string $canonical): ?string
    {
        $file = $this->path($attachmentId, $canonical);
        if ($file === '') {
            return null;
        }

        if ($this->isUsable($file)) {
            return $file;
        }

        if (is_file($file)) {
            wp_delete_file($file);
        }

        if ($this->failedDownloads->has($file)) {
            return null;
        }

        $keys = $this->keys($attachmentId, $canonical);
        if ($this->ensureDirectory(dirname($file))) {
            foreach ($keys as $key) {
                if ($this->cloudProvider->downloadFile($key, $file) && $this->isUsable($file)) {
                    $this->failedDownloads->forget($file);
                    return $file;
                }
            }
        }

        $this->failedDownloads->remember($file);
        error_log(sprintf(
            'ADVMO: Failed to cache attachment %d from cloud object "%s".',
            $attachmentId,
            implode('" or "', $keys)
        ));

        return null;
    }

    public function clear(int $attachmentId): void
    {
        $directory = $this->attachmentDirectory($attachmentId);
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    @rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folders of the plugin's own cache.
                } else {
                    wp_delete_file($item->getPathname());
                }
            }
        } catch (\UnexpectedValueException $e) {
            error_log('ADVMO: Unable to read the attached-file cache: ' . $e->getMessage());
            return;
        }

        @rmdir($directory); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folder of the plugin's own cache.
    }

    /** @return string[] */
    private function keys(int $attachmentId, string $canonical): array
    {
        return ObjectKey::candidates(
            (string) get_post_meta($attachmentId, 'advmo_path', true),
            wp_basename($canonical)
        );
    }

    private function attachmentDirectory(int $attachmentId): string
    {
        $root = $this->root();

        return $root === '' || $attachmentId <= 0 ? '' : "{$root}/{$attachmentId}";
    }

    private function root(): string
    {
        if ($this->root !== null) {
            return $this->root;
        }

        $temporary = get_temp_dir();
        if (!is_string($temporary) || $temporary === '') {
            return $this->root = '';
        }

        // macOS resolves /var to /private/var in tempnam(); the provider's download
        // checks that its temporary file lands in this same folder.
        $temporary = realpath($temporary) ?: $temporary;
        $site = substr(hash('sha256', (defined('ABSPATH') ? ABSPATH : '') . '|' . get_current_blog_id()), 0, 16);

        return $this->root = trailingslashit(wp_normalize_path($temporary)) . self::DIRECTORY . '/' . $site;
    }

    private function isUsable(string $file): bool
    {
        clearstatcache(true, $file);

        return is_file($file) && is_readable($file) && (int) filesize($file) > 0;
    }

    private function ensureDirectory(string $directory): bool
    {
        // Another request may create it between the two checks.
        return is_dir($directory) || wp_mkdir_p($directory) || is_dir($directory);
    }
}
