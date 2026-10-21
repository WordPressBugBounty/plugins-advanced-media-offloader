<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

use Advanced_Media_Offloader\Services\BulkMediaOffloader;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Throwable;

class BulkTaskRunner
{
    public function __construct(private BulkRunStore $store, private BulkOffloadService $runs)
    {
    }

    public function run(mixed $item, CloudAttachmentUploader $uploader, BulkMediaOffloader $process): mixed
    {
        $attachmentId = is_array($item) ? ($item['attachment_id'] ?? null) : $item;
        if ((!is_int($attachmentId) && (!is_string($attachmentId) || !ctype_digit($attachmentId))) || (int) $attachmentId <= 0) {
            return false;
        }
        $attachmentId = (int) $attachmentId;
        $run = $this->store->read();
        if (
            $run === null || $run['id'] !== $process->currentRunId()
            || (is_array($item) && ($item['run_id'] ?? null) !== $run['id'])
            || !in_array($run['status'], ['starting', 'processing', 'paused', 'cancelling'], true)
        ) {
            return false;
        }
        if (array_key_exists($attachmentId, $run['results'])) {
            return false;
        }
        if ($run['items'] !== [] && !in_array($attachmentId, $run['items'], true)) {
            return false;
        }
        if ($run['status'] === 'cancelling') {
            $process->requestCancellation();
            return $item;
        }
        if ($run['status'] === 'paused') {
            $process->pause();
            return $item;
        }
        try {
            $destination = $this->runs->destination();
            if (is_wp_error($destination) || !$this->runs->matches($run, $destination)) {
                $process->pause();
                $this->runs->pause($run['id']);
                return $item;
            }
        } catch (Throwable) {
            $process->pause();
            $this->runs->pause($run['id']);
            return $item;
        }
        try {
            $success = get_post_type($attachmentId) === 'attachment' && get_post_status($attachmentId) !== 'trash'
                && $uploader->uploadAttachment($attachmentId);
        } catch (Throwable) {
            $success = false;
            $errors = get_post_meta($attachmentId, 'advmo_error_log', true);
            $errors = is_array($errors) ? $errors : (is_string($errors) && $errors !== '' ? [$errors] : []);
            $errors = array_values(array_filter($errors, 'is_string'));
            $errors[] = 'The bulk upload did not finish. Local recovery state was preserved.';
            update_post_meta($attachmentId, 'advmo_error_log', array_slice(array_unique($errors), -50));
        }
        try {
            $saved = $process->recordAttachmentResult($attachmentId, $success, [$process, 'update_processed_count']);
        } catch (Throwable) {
            $saved = null;
        }
        if ($saved !== true) {
            // The file may already have committed. Keep the queue item until its result is durable.
            $process->pause();
            $this->runs->pause($run['id']);
            return $item;
        }
        return false;
    }
}
