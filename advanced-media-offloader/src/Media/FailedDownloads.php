<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media;

/**
 * Remembers a failed download next to the file it should have created, so a missing
 * object costs one request every few minutes instead of one per lookup. Changing the
 * plugin's settings or credentials allows a new attempt straight away.
 */
class FailedDownloads
{
    public function __construct(private int $retryAfter = 5 * MINUTE_IN_SECONDS)
    {
    }

    public function has(string $file): bool
    {
        $marker = $this->marker($file);
        clearstatcache(true, $marker);

        return is_file($marker)
            && filemtime($marker) > time() - $this->retryAfter
            && file_get_contents($marker) === $this->fingerprint($file);
    }

    public function remember(string $file): void
    {
        @file_put_contents($this->marker($file), $this->fingerprint($file));
    }

    public function forget(string $file): void
    {
        wp_delete_file($this->marker($file));
    }

    private function marker(string $file): string
    {
        return $file . '.failed';
    }

    private function fingerprint(string $file): string
    {
        return hash('sha256', $file . serialize([
            get_option('advmo_settings', []),
            get_option('advmo_credentials', []),
        ]));
    }
}
