<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media\Repositories;

use AdvancedMediaOffloader\Media\Contracts\AttachmentRepository;
use AdvancedMediaOffloader\Media\OffloadRecord;
use InvalidArgumentException;
use wpdb;

class PostMetaAttachmentRepository implements AttachmentRepository
{
    public function __construct(private wpdb $database)
    {
    }

    public function find(int $attachmentId): ?OffloadRecord
    {
        if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
            return null;
        }

        $errors = get_post_meta($attachmentId, 'advmo_error_log', true);
        $errors = is_array($errors) ? $errors : [$errors];
        $errors = array_values(array_filter($errors, static fn ($error): bool => is_string($error) && $error !== ''));
        $offloadedAt = get_post_meta($attachmentId, 'advmo_offloaded_at', true);

        return new OffloadRecord(
            $attachmentId,
            (bool) get_post_meta($attachmentId, 'advmo_offloaded', true),
            $this->stringMeta($attachmentId, 'advmo_provider'),
            $this->stringMeta($attachmentId, 'advmo_bucket'),
            $this->stringMeta($attachmentId, 'advmo_path'),
            is_numeric($offloadedAt) && (int) $offloadedAt > 0 ? (int) $offloadedAt : null,
            !empty(get_post_meta($attachmentId, '_advmo_local_cleanup_pending', true)),
            $errors,
        );
    }

    public function counts(): array
    {
        $total = (int) $this->database->get_var(
            "SELECT COUNT(*) FROM {$this->database->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
        );
        $offloaded = $this->countOffloaded();

        return [
            'total' => $total,
            'offloaded' => $offloaded,
            'not_offloaded' => max(0, $total - $offloaded),
            'errors' => $this->countErrors(),
        ];
    }

    public function errorIds(int $page, int $perPage): array
    {
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Invalid attachment error pagination.');
        }

        $query = $this->database->prepare(
            "SELECT p.ID FROM {$this->database->posts} p WHERE {$this->errorCondition()} ORDER BY p.ID ASC LIMIT %d OFFSET %d",
            $perPage,
            ($page - 1) * $perPage
        );

        return array_map('intval', $this->database->get_col($query));
    }

    public function countErrors(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM {$this->database->posts} p WHERE {$this->errorCondition()}"
        );
    }

    public function countOffloaded(): int
    {
        $query = $this->database->prepare(
            "SELECT COUNT(DISTINCT p.ID)
            FROM {$this->database->posts} p
            INNER JOIN {$this->database->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND p.post_status != %s
            AND pm.meta_key = %s
            AND pm.meta_value != %s",
            ['attachment', 'trash', 'advmo_offloaded', '']
        );

        return (int) $this->database->get_var($query);
    }

    public function countUnoffloaded(): int
    {
        // Keep the 4.x helper's scope, including trashed attachments and duplicate meta rows.
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM {$this->database->posts} p
            LEFT JOIN {$this->database->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'advmo_offloaded'
            WHERE p.post_type = 'attachment'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')"
        );
    }

    public function allErrorIds(): array
    {
        $query = $this->database->prepare(
            "SELECT post_id FROM {$this->database->postmeta} WHERE meta_key = %s",
            'advmo_error_log'
        );

        return $this->database->get_col($query);
    }

    private function errorCondition(): string
    {
        return "p.post_type = 'attachment' AND p.post_status != 'trash'
            AND EXISTS (SELECT 1 FROM {$this->database->postmeta} em
                WHERE em.post_id = p.ID AND em.meta_key = 'advmo_error_log'
                AND em.meta_value NOT IN ('', '0', 'a:0:{}', 'b:0;', 'N;'))";
    }

    private function stringMeta(int $attachmentId, string $key): ?string
    {
        $value = get_post_meta($attachmentId, $key, true);

        return is_string($value) ? $value : null;
    }
}
