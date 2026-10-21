<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media;

use AdvancedMediaOffloader\Diagnostics\DiagnosticMessages;

class AttachmentStatusReader
{
    public function __construct(private DiagnosticMessages $messages)
    {
    }

    public function read(OffloadRecord $record): array
    {
        $url = wp_get_attachment_url($record->attachmentId);

        return [
            'attachment_id' => $record->attachmentId,
            'offloaded' => $record->offloaded,
            'provider' => $record->provider,
            'bucket' => $record->bucket,
            'path' => $record->path,
            'offloaded_at' => $record->offloadedAt,
            'url' => is_string($url) && $url !== '' ? $this->messages->text($url) : null,
            'cleanup_pending' => $record->cleanupPending,
            'errors' => $this->messages->clean($record->errors),
        ];
    }
}
