<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media\Contracts;

use AdvancedMediaOffloader\Media\OffloadRecord;

interface AttachmentRepository
{
    public function find(int $attachmentId): ?OffloadRecord;

    /** @return array{total: int, offloaded: int, not_offloaded: int, errors: int} */
    public function counts(): array;

    /** @return int[] */
    public function errorIds(int $page, int $perPage): array;

    public function countErrors(): int;

    public function countOffloaded(): int;

    public function countUnoffloaded(): int;

    /** @return string[] Historical error rows used by the 4.x CSV export. */
    public function allErrorIds(): array;
}
