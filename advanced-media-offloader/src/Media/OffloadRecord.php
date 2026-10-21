<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media;

class OffloadRecord
{
    /** @param string[] $errors */
    public function __construct(
        public readonly int $attachmentId,
        public readonly bool $offloaded,
        public readonly ?string $provider,
        public readonly ?string $bucket,
        public readonly ?string $path,
        public readonly ?int $offloadedAt,
        public readonly bool $cleanupPending,
        public readonly array $errors,
    ) {
    }
}
