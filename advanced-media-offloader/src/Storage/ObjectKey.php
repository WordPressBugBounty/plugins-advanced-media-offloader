<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Storage;

class ObjectKey
{
    /**
     * The keys an offloaded file may be stored under, in the order to try them.
     *
     * Older offloads could record advmo_path with a leading slash (SVG Support
     * saves "/2025/01/logo.svg" as the attachment's file). Storage that keeps keys
     * exactly, like Amazon S3, holds such a file at "/2025/01/logo.svg". Storage
     * that folds the slashes, like Cloudflare R2, holds it at "2025/01/logo.svg",
     * where the plugin's URLs point.
     *
     * @return string[]
     */
    public static function candidates(string $path, string $filename): array
    {
        $key = $path . $filename;
        $folded = ltrim((string) preg_replace('#/{2,}#', '/', $key), '/');

        return $folded === $key ? [$key] : [$key, $folded];
    }
}
