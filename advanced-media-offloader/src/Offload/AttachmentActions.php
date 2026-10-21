<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Offload;

use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use AdvancedMediaOffloader\Diagnostics\DiagnosticMessages;
use AdvancedMediaOffloader\Foundation\Application;
use AdvancedMediaOffloader\Media\Contracts\AttachmentRepository;
use AdvancedMediaOffloader\Media\OffloadRecord;
use AdvancedMediaOffloader\Storage\ProviderRegistry;
use Throwable;
use WP_Error;

class AttachmentActions
{
    public const MAX_ATTACHMENTS = 20;
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;
    public const MAX_REQUEST_BYTES = 150 * 1024 * 1024;

    public function __construct(
        private Application $app,
        private AttachmentRepository $attachments,
        private ProviderRegistry $providers,
        private AttachmentOperationLock $locks,
        private DiagnosticMessages $messages,
    ) {
    }

    public function offload(array $attachmentIds): array|WP_Error
    {
        return $this->run($attachmentIds, false);
    }

    public function reoffload(array $attachmentIds): array|WP_Error
    {
        return $this->run($attachmentIds, true);
    }

    private function run(array $attachmentIds, bool $reoffload): array|WP_Error
    {
        if (!$attachmentIds || count($attachmentIds) > self::MAX_ATTACHMENTS
            || count(array_unique($attachmentIds, SORT_REGULAR)) !== count($attachmentIds)) {
            return $this->error('advmo_invalid_attachments', __('Provide 1 to 20 unique attachment IDs.', 'advanced-media-offloader'), 400);
        }
        foreach ($attachmentIds as $attachmentId) {
            if (!is_int($attachmentId) || $attachmentId < 1) {
                return $this->error('advmo_invalid_attachments', __('Attachment IDs must be positive integers.', 'advanced-media-offloader'), 400);
            }
            if (!current_user_can('upload_files') || !current_user_can('edit_post', $attachmentId)) {
                return $this->error('advmo_attachment_forbidden', __('You cannot offload one or more of these attachments.', 'advanced-media-offloader'), 403);
            }
            if (get_post_type($attachmentId) !== 'attachment' || get_post_status($attachmentId) === 'trash') {
                return $this->error('advmo_attachment_not_found', __('An attachment could not be found or is in the trash.', 'advanced-media-offloader'), 404);
            }
        }

        $results = [];
        $bytes = 0;
        $startedAt = microtime(true);
        foreach ($attachmentIds as $attachmentId) {
            try {
                if (microtime(true) - $startedAt >= 20) {
                    $result = $this->error('advmo_time_budget', __('The request time budget was reached. Retry this attachment separately.', 'advanced-media-offloader'));
                } elseif ($this->locks->isHeld($attachmentId)) {
                    $result = $this->error('advmo_attachment_busy', __('This attachment is already being processed.', 'advanced-media-offloader'));
                } else {
                    $result = $this->locks->run($attachmentId, function () use ($attachmentId, $reoffload, &$bytes) {
                        $record = $this->attachments->find($attachmentId);
                        return $this->perform($attachmentId, $record, $reoffload, $bytes);
                    });
                }
            } catch (Throwable) {
                // Partial cloud uploads cannot be rolled back safely. Keep the uploader's recovery state.
                $result = $this->error('advmo_upload_failed', __('The upload did not finish. Inspect the attachment errors before retrying.', 'advanced-media-offloader'));
            }

            if (is_wp_error($result)) {
                $results[] = [
                    'attachment_id' => $attachmentId,
                    'status' => $result->get_error_code() === 'advmo_upload_failed' ? 'failed' : 'skipped',
                    'code' => $result->get_error_code(),
                    'message' => $this->messages->text($result->get_error_message()),
                ];
            } else {
                $results[] = $result;
            }
        }

        return ['attachments' => $results];
    }

    private function perform(int $attachmentId, ?OffloadRecord $record, bool $reoffload, int &$bytes): array|WP_Error
    {
        if ($record === null || get_post_status($attachmentId) === 'trash') {
            return $this->error('advmo_attachment_not_found', __('The attachment is no longer available.', 'advanced-media-offloader'));
        }
        if (!$reoffload && $record->offloaded) {
            return $this->result($attachmentId, 'already_offloaded');
        }
        if ($reoffload && !$record->offloaded) {
            return $this->error('advmo_not_offloaded', __('Offload this attachment before requesting a re-offload.', 'advanced-media-offloader'));
        }
        if ($record->cleanupPending) {
            return $this->error('advmo_attachment_pending', __('Earlier cleanup or image optimization is still pending.', 'advanced-media-offloader'));
        }
        foreach (['_advmo_local_cleanup_pending', '_advmo_regeneration_hydrated_files', '_advmo_image_operation_in_progress', '_advmo_imagify_image_edit_queued', '_advmo_imagify_deferred_retention', '_advmo_ewww_deferred_retention', '_advmo_ewww_recovery_queued'] as $key) {
            if (get_post_meta($attachmentId, $key, true)) {
                return $this->error('advmo_attachment_pending', __('Earlier cleanup or image optimization is still pending.', 'advanced-media-offloader'));
            }
        }

        $providerKey = advmo_get_cloud_provider_key();
        if ($providerKey === '' || !$this->providers->has($providerKey)) {
            return $this->error('advmo_provider_not_configured', __('Select an available cloud provider first.', 'advanced-media-offloader'));
        }
        $provider = $this->providers->make($providerKey);
        $bucket = $provider->getBucket();
        $providerName = $provider->getProviderName();
        if (!is_string($bucket) || $bucket === '' || !is_string($providerName) || $providerName === '') {
            return $this->error('advmo_provider_not_configured', __('The saved cloud destination is incomplete.', 'advanced-media-offloader'));
        }
        if (($record->provider !== null && $record->provider !== '' && $record->provider !== $providerName)
            || ($record->bucket !== null && $record->bucket !== '' && $record->bucket !== $bucket)) {
            return $this->error('advmo_destination_mismatch', __('The recorded cloud provider or bucket differs from the saved destination.', 'advanced-media-offloader'));
        }
        if ($reoffload && ($record->provider !== $providerName || $record->bucket !== $bucket || $record->path === null
            || !metadata_exists('post', $attachmentId, 'advmo_path'))) {
            return $this->error('advmo_destination_unknown', __('The original cloud destination is incomplete. Review it before re-offloading.', 'advanced-media-offloader'));
        }

        // Check the unfiltered source before invoking filters that can hydrate cloud files.
        $file = get_attached_file($attachmentId, true);
        if (!is_string($file) || !is_file($file) || !is_readable($file)) {
            return $this->error('advmo_source_missing', __('A readable local main file is required. Restore it before retrying.', 'advanced-media-offloader'));
        }
        $filteredFile = $reoffload ? $file : get_attached_file($attachmentId);
        if (!is_string($filteredFile) || !is_file($filteredFile) || !is_readable($filteredFile)) {
            return $this->error('advmo_source_missing', __('A readable local main file is required. Restore it before retrying.', 'advanced-media-offloader'));
        }
        $size = max((int) filesize($file), (int) filesize($filteredFile));
        if ($size > self::MAX_FILE_BYTES || $bytes + $size > self::MAX_REQUEST_BYTES) {
            return $this->error('advmo_size_limit', __('The attachment exceeds the main-file size budget for this request. Use the existing bulk or command-line tools.', 'advanced-media-offloader'));
        }
        $bytes += $size;

        $uploader = $this->app->make(CloudAttachmentUploader::class, ['cloudProvider' => $provider]);
        $uploaded = $reoffload ? $uploader->reoffloadAttachment($attachmentId) : $uploader->uploadAttachment($attachmentId);
        $after = $this->attachments->find($attachmentId);
        if (!$uploaded || $after === null || !$after->offloaded || $after->errors !== []) {
            if (!$uploaded && $after !== null && !$after->offloaded && $after->errors === []) {
                return $this->error('advmo_offload_skipped', __('The uploader did not offload this attachment. Check the offload filters and local files.', 'advanced-media-offloader'));
            }
            return $this->error('advmo_upload_failed', __('The upload did not finish without errors. Inspect the attachment errors before retrying.', 'advanced-media-offloader'));
        }

        return $this->result($attachmentId, $after->cleanupPending ? 'cleanup_pending' : ($reoffload ? 'reoffloaded' : 'offloaded'));
    }

    private function result(int $attachmentId, string $status): array
    {
        return ['attachment_id' => $attachmentId, 'status' => $status, 'code' => '', 'message' => ''];
    }

    private function error(string $code, string $message, int $status = 409): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
