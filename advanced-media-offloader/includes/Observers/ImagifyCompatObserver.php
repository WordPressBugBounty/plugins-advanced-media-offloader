<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class ImagifyCompatObserver implements ObserverInterface
{
    use OffloaderTrait;

    private const META_IMAGE_EDIT_QUEUED = '_advmo_imagify_image_edit_queued';

    /** Imagify finished before the initial ADVMO offload committed. */
    private const META_OPTIMIZED_PRE_OFFLOAD = '_advmo_imagify_optimized_preoffload';

    private const INCOMPLETE_OPTIMIZATION_ERROR =
        'Imagify did not finish every expected image size. Local cleanup remains pending.';

    private S3_Provider $cloudProvider;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    public function register(): void
    {
        add_filter('advmo_local_cleanup_deferred_by', [$this, 'deferLocalCleanup'], 10, 4);
        add_filter('advmo_upload_image_operation_sidecars', [$this, 'uploadImageOperationSidecars'], 10, 5);
        add_filter('advmo_delete_image_operation_sidecars', [$this, 'deleteImageOperationSidecars'], 10, 4);
        add_action('imagify_after_optimize', [$this, 'afterImagifyOptimize'], 10, 2);
        add_action('advmo_after_upload_to_cloud', [$this, 'afterAdvmoUpload'], 10, 1);
        add_action('advmo_image_operation_committed', [$this, 'afterImageOperationCommitted'], 10, 2);
        add_action('advmo_regeneration_committed', [$this, 'afterRegenerationCommitted'], 10, 2);
        add_action('advmo_deferred_cleanup_expired', [$this, 'afterDeferredCleanupExpired'], 10, 2);
        add_filter('imagify_webp_picture_process_image', [$this, 'pictureTagProcessImage']);
        add_filter('advmo_attachment_delete_keys', [$this, 'addNextGenDeleteKeys'], 10, 3);
        add_filter('advmo_regeneration_obsolete_cloud_keys', [$this, 'addRegenerationObsoleteNextGenKeys'], 10, 5);
        add_action('advmo_reoffload_attachment', [$this, 'onReoffload'], 10, 2);
    }

    public static function isImagifyActive(): bool
    {
        return defined('IMAGIFY_VERSION') && class_exists('Imagify_Options');
    }

    public static function isImagifyAutoOptimizeEnabled(): bool
    {
        if (!self::isImagifyActive()) {
            return false;
        }

        return (bool) \Imagify_Options::get_instance()->get('auto_optimize');
    }

    /**
     * When Imagify auto-optimize is active and retention policy would delete
     * local files, defer the deletion so Imagify can process thumbnails first.
     */
    public function deferLocalCleanup(
        $deferred_by,
        int $deletion_rule,
        int $attachment_id,
        string $context = 'initial_upload'
    ): ?string
    {
        // WordPress restore does not reliably enqueue an Imagify optimization.
        // Existing sidecars are handled directly by the image-operation hooks.
        if ($deletion_rule === 0 || $context === 'image_restore') {
            delete_post_meta($attachment_id, '_advmo_imagify_deferred_retention');
            return null;
        }

        if (is_string($deferred_by) && $deferred_by !== '') {
            delete_post_meta($attachment_id, '_advmo_imagify_deferred_retention');
            return $deferred_by;
        }

        if (!self::isImagifyAutoOptimizeEnabled()) {
            delete_post_meta($attachment_id, '_advmo_imagify_deferred_retention');
            return null;
        }

        if (!wp_attachment_is_image($attachment_id)) {
            delete_post_meta($attachment_id, '_advmo_imagify_deferred_retention');
            return null;
        }

        update_post_meta($attachment_id, '_advmo_imagify_deferred_retention', $deletion_rule);

        return 'imagify';
    }

    /**
     * After Imagify finishes optimizing, re-upload all optimized files
     * and any next-gen (WebP/AVIF) sidecar files, then apply the
     * user's original retention policy.
     */
    public function afterImagifyOptimize($process, $item): void
    {
        $media_id = (int) ($item['id'] ?? 0);
        if (!$media_id) {
            return;
        }

        if (!$this->is_offloaded($media_id)) {
            // Imagify may finish at an earlier metadata priority than ADVMO.
            // Remember that callback so the later offload commit can finish the
            // deferred sidecar upload and retention cleanup itself.
            if (
                get_post_type($media_id) === 'attachment'
                && self::isImagifyAutoOptimizeEnabled()
            ) {
                update_post_meta($media_id, self::META_OPTIMIZED_PRE_OFFLOAD, 1);
            } else {
                delete_post_meta($media_id, self::META_OPTIMIZED_PRE_OFFLOAD);
            }
            return;
        }

        if (!metadata_exists('post', $media_id, 'advmo_path')) {
            return;
        }
        $advmo_path = (string) get_post_meta($media_id, 'advmo_path', true);
        $pending_cleanup = get_post_meta(
            $media_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            true
        );
        if (
            is_array($pending_cleanup)
            && !empty($pending_cleanup['deferred_by'])
            && ($pending_cleanup['deferred_by'] ?? '') !== 'imagify'
        ) {
            return;
        }
        $operation_fingerprint = isset($item['data']['advmo_metadata_fingerprint'])
            ? (string) $item['data']['advmo_metadata_fingerprint']
            : '';
        $operation_context = isset($item['data']['advmo_context'])
            ? sanitize_key((string) $item['data']['advmo_context'])
            : '';
        $operation_id = isset($item['data']['advmo_operation_id'])
            ? sanitize_text_field((string) $item['data']['advmo_operation_id'])
            : '';
        $pending_imagify_operation = is_array($pending_cleanup)
            && in_array(
                ($pending_cleanup['context'] ?? ''),
                ['image_edit', 'thumbnail_regeneration'],
                true
            )
            && ($pending_cleanup['deferred_by'] ?? '') === 'imagify';
        $pending_initial_operation = is_array($pending_cleanup)
            && empty($pending_cleanup['context'])
            && ($pending_cleanup['deferred_by'] ?? '') === 'imagify';

        // Imagify can finish while a format plugin is still filtering partial
        // regeneration metadata. Only a job queued after the exact WordPress
        // commit may upload, apply retention, or clear pending cleanup.
        if (
            ($pending_imagify_operation && $operation_fingerprint === '')
            || (
                $operation_fingerprint === ''
                && $this->isThumbnailRegenerationRequest($media_id)
            )
            || (
                $operation_fingerprint !== ''
                && !$this->isCurrentQueuedOperation(
                    $media_id,
                    $operation_fingerprint,
                    $operation_context,
                    $operation_id
                )
            )
        ) {
            // Imagify unlocks the process immediately after this callback. Retry
            // at shutdown so the newer committed edit can then be queued.
            $uploader = new CloudAttachmentUploader($this->cloudProvider);
            register_shutdown_function([$uploader, 'resumePendingLocalCleanup'], $media_id);
            return;
        }

        if (
            (
                $operation_fingerprint !== ''
                && !$this->verifyImagifyOperationResult(
                    $media_id,
                    true,
                    $operation_context === 'image_edit'
                )
            )
            || (
                $operation_fingerprint === ''
                && $pending_initial_operation
                && !$this->verifyImagifyOperationResult($media_id)
            )
        ) {
            $this->logOptimizationQueueError(
                $media_id,
                self::INCOMPLETE_OPTIMIZATION_ERROR
            );
            return;
        }

        $standard_uploaded = $this->reUploadOptimizedFiles($media_id, $advmo_path);
        $nextgen_uploaded = $this->uploadNextGenFiles($media_id, $advmo_path);

        // Never delete local files if cloud re-upload was not fully successful.
        if (!$standard_uploaded || !$nextgen_uploaded) {
            error_log("ADVMO Imagify Compat: Skipping local deletion for attachment {$media_id} - cloud upload incomplete");
            return;
        }

        if (
            is_array($pending_cleanup)
            && !empty($pending_cleanup)
            && get_post_meta(
                $media_id,
                CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                true
            ) !== $pending_cleanup
        ) {
            return;
        }

        // Uploads may take long enough for a newer edit or regeneration to
        // replace the pending state. Revalidate the exact queued operation
        // before deleting any local file.
        if (
            $operation_fingerprint !== ''
            && !$this->isCurrentQueuedOperation(
                $media_id,
                $operation_fingerprint,
                $operation_context,
                $operation_id
            )
        ) {
            return;
        }

        $deferred_retention = get_post_meta($media_id, '_advmo_imagify_deferred_retention', true);
        $has_deferred_retention = $deferred_retention !== '' && $deferred_retention !== false;

        if (
            $operation_fingerprint !== ''
            && is_array($pending_cleanup)
            && isset($pending_cleanup['rule'])
        ) {
            // Keep the policy selected when this exact edit was committed.
            $retention_policy = (int) $pending_cleanup['rule'];
        } elseif ($has_deferred_retention) {
            // New upload: use the deferred retention policy
            $retention_policy = intval($deferred_retention);
        } else {
            // Regeneration or re-optimization: use current settings
            $retention_policy = $this->shouldDeleteLocal();
        }

        if ($retention_policy === 0) {
            $this->clearIncompleteOptimizationError($media_id);
            if (is_array($pending_cleanup) && !empty($pending_cleanup)) {
                $uploader = new CloudAttachmentUploader($this->cloudProvider);
                if (!$uploader->completePendingLocalCleanup($media_id, $pending_cleanup)) {
                    return;
                }
            }
            $this->clearQueuedOperation(
                $media_id,
                $operation_fingerprint,
                $operation_context,
                $operation_id
            );
            delete_post_meta($media_id, self::META_OPTIMIZED_PRE_OFFLOAD);
            return;
        }

        $uploader = new CloudAttachmentUploader($this->cloudProvider);
        if (!$uploader->deleteLocalFile($media_id, $retention_policy, false)) {
            return;
        }
        if (!$this->deleteNextGenLocalFiles($media_id, $retention_policy)) {
            return;
        }
        $this->clearIncompleteOptimizationError($media_id);
        if (is_array($pending_cleanup) && !empty($pending_cleanup)) {
            if (!$uploader->completePendingLocalCleanup($media_id, $pending_cleanup)) {
                return;
            }
        }

        if ($has_deferred_retention) {
            delete_post_meta($media_id, '_advmo_imagify_deferred_retention');
        }
        delete_post_meta($media_id, self::META_OPTIMIZED_PRE_OFFLOAD);
        $this->clearQueuedOperation(
            $media_id,
            $operation_fingerprint,
            $operation_context,
            $operation_id
        );
    }

    /**
     * Finish an initial upload when Imagify's callback ran before ADVMO had an
     * offloaded attachment to act on.
     */
    public function afterAdvmoUpload(int $attachment_id): void
    {
        if (!self::isImagifyActive()) {
            return;
        }

        $pendingCleanup = get_post_meta(
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            true
        );
        if (
            !is_array($pendingCleanup)
            || ($pendingCleanup['deferred_by'] ?? '') !== 'imagify'
        ) {
            delete_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD);
            return;
        }

        if (!(bool) get_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD, true)) {
            return;
        }

        // A callback alone is not proof of success. Require the current local
        // optimized files or sidecars before allowing Full Cloud cleanup.
        if (!$this->verifyImagifyOperationResult($attachment_id)) {
            return;
        }

        $this->afterImagifyOptimize(null, ['id' => $attachment_id]);
    }

    /** Queue Imagify only after WordPress commits edited attachment metadata. */
    public function afterImageOperationCommitted(int $attachment_id, array $pendingCleanup): void
    {
        if (
            ($pendingCleanup['context'] ?? '') !== 'image_edit'
            || !self::isImagifyAutoOptimizeEnabled()
        ) {
            return;
        }

        $rule = isset($pendingCleanup['rule']) ? (int) $pendingCleanup['rule'] : 0;
        $deferred_by = isset($pendingCleanup['deferred_by'])
            ? (string) $pendingCleanup['deferred_by']
            : '';

        // With a deleting retention policy, only the selected cleanup owner may
        // queue optimization. Retain Locally has no owner but still needs its
        // edited files optimized and re-uploaded.
        if ($rule > 0 && $deferred_by !== 'imagify') {
            return;
        }

        // Retain Locally still needs Imagify to finish, although no files will
        // be deleted. Temporarily defer this operation so an existing Imagify
        // worker cannot make the verifier discard the retry state.
        if ($rule === 0 && $deferred_by === '') {
            $stored_pending = get_post_meta(
                $attachment_id,
                CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                true
            );
            $deferred_pending = $pendingCleanup;
            $deferred_pending['deferred'] = 1;
            $deferred_pending['deferred_by'] = 'imagify';

            update_post_meta(
                $attachment_id,
                CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                $deferred_pending,
                $stored_pending
            );

            if (
                get_post_meta(
                    $attachment_id,
                    CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                    true
                ) !== $deferred_pending
            ) {
                $this->logOptimizationQueueError($attachment_id, 'The edited image retry state could not be saved.');
                return;
            }

            $pendingCleanup = $deferred_pending;
            $deferred_by = 'imagify';
        }

        $fingerprint = isset($pendingCleanup['metadata_fingerprint'])
            ? (string) $pendingCleanup['metadata_fingerprint']
            : '';
        if ($fingerprint === '') {
            return;
        }

        $this->queueCommittedOperation(
            $attachment_id,
            $fingerprint,
            'image_edit',
            isset($pendingCleanup['operation_id'])
                ? (string) $pendingCleanup['operation_id']
                : ''
        );
    }

    /** Queue Imagify against the exact committed regeneration metadata. */
    public function afterRegenerationCommitted(int $attachment_id, array $pendingCleanup): void
    {
        if (
            ($pendingCleanup['context'] ?? '') !== 'thumbnail_regeneration'
            || ($pendingCleanup['deferred_by'] ?? '') !== 'imagify'
            || !self::isImagifyAutoOptimizeEnabled()
        ) {
            return;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            return;
        }

        $fingerprint = $this->getMetadataFingerprint($metadata);
        $updatedPending = $this->storeRegenerationOptimizerFingerprint(
            $attachment_id,
            $pendingCleanup,
            $fingerprint
        );
        if ($updatedPending === null) {
            return;
        }
        $operationId = isset($updatedPending['operation_id'])
            ? (string) $updatedPending['operation_id']
            : '';

        $existingMarker = get_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, true);
        if (
            is_array($existingMarker)
            && ($existingMarker['context'] ?? 'image_edit') === 'thumbnail_regeneration'
            && !empty($existingMarker['metadata_fingerprint'])
            && hash_equals((string) $existingMarker['metadata_fingerprint'], $fingerprint)
            && $this->operationIdsMatch(
                isset($existingMarker['operation_id']) ? (string) $existingMarker['operation_id'] : '',
                $operationId
            )
            && $this->verifyImagifyOperationResult($attachment_id, true)
        ) {
            $this->afterImagifyOptimize(null, [
                'id' => $attachment_id,
                'data' => [
                    'advmo_context' => 'thumbnail_regeneration',
                    'advmo_metadata_fingerprint' => $fingerprint,
                    'advmo_operation_id' => $operationId,
                ],
            ]);
            return;
        }

        $marker = [
            'metadata_fingerprint' => $fingerprint,
            'context' => 'thumbnail_regeneration',
            'operation_id' => $operationId,
            'created_at' => time(),
        ];
        update_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, $marker);

        // Imagify normally processes thumbnail regeneration itself. Accept that
        // result only when every current sidecar is recorded and is at least as
        // new as its regenerated standard file. This avoids a second optimizer
        // pass and rejects stale sidecars left from an older generation.
        if ($this->verifyImagifyOperationResult($attachment_id, true)) {
            $this->afterImagifyOptimize(null, [
                'id' => $attachment_id,
                'data' => [
                    'advmo_context' => 'thumbnail_regeneration',
                    'advmo_metadata_fingerprint' => $fingerprint,
                    'advmo_operation_id' => $operationId,
                ],
            ]);
            return;
        }

        delete_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, $marker);

        $this->queueCommittedOperation(
            $attachment_id,
            $fingerprint,
            'thumbnail_regeneration',
            $operationId
        );
    }

    private function queueCommittedOperation(
        int $attachment_id,
        string $fingerprint,
        string $context,
        string $operationId
    ): void {

        $pendingCleanup = get_post_meta(
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            true
        );
        $pendingOperationId = is_array($pendingCleanup) && isset($pendingCleanup['operation_id'])
            ? (string) $pendingCleanup['operation_id']
            : '';
        if (!$this->operationIdsMatch($pendingOperationId, $operationId)) {
            return;
        }

        $queued = get_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, true);
        if (
            is_array($queued)
            && !empty($queued['metadata_fingerprint'])
            && hash_equals((string) $queued['metadata_fingerprint'], $fingerprint)
            && ($queued['context'] ?? 'image_edit') === $context
            && $this->operationIdsMatch(
                isset($queued['operation_id']) ? (string) $queued['operation_id'] : '',
                $operationId
            )
            && !empty($queued['created_at'])
            && (time() - (int) $queued['created_at']) < (5 * MINUTE_IN_SECONDS)
        ) {
            // The saved job may already have completed, while an earlier strict
            // verifier kept the cleanup marker. Re-check its exact output before
            // asking Imagify's dispatcher to run anything else.
            if ($this->verifyImagifyOperationResult(
                $attachment_id,
                true,
                $context === 'image_edit'
            )) {
                $this->afterImagifyOptimize(null, [
                    'id' => $attachment_id,
                    'data' => [
                        'advmo_context' => $context,
                        'advmo_metadata_fingerprint' => $fingerprint,
                        'advmo_operation_id' => $operationId,
                    ],
                ]);
                return;
            }

            $this->dispatchImagifyQueue($attachment_id);
            return;
        }

        if (!function_exists('imagify_get_optimization_process')) {
            $this->logOptimizationQueueError($attachment_id, 'Imagify could not be started for this image operation.');
            return;
        }

        $process = imagify_get_optimization_process($attachment_id, 'wp');
        if (!is_object($process) || !method_exists($process, 'get_data') || !method_exists($process, 'optimize')) {
            $this->logOptimizationQueueError($attachment_id, 'Imagify could not create an optimization process.');
            return;
        }

        if (method_exists($process, 'is_locked') && $process->is_locked()) {
            return;
        }

        $data = $process->get_data();
        if (!is_object($data) || !method_exists($data, 'delete_optimization_data')) {
            $this->logOptimizationQueueError($attachment_id, 'Imagify could not prepare the image for optimization.');
            return;
        }

        $optimization_level = method_exists($data, 'get_optimization_level')
            ? $data->get_optimization_level()
            : null;
        $optimization_level = is_numeric($optimization_level) ? (int) $optimization_level : null;

        $marker = [
            'metadata_fingerprint' => $fingerprint,
            'context' => $context,
            'operation_id' => $operationId,
            'created_at' => time(),
        ];
        update_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, $marker);

        $stored_marker = get_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, true);
        if (
            !is_array($stored_marker)
            || empty($stored_marker['metadata_fingerprint'])
            || !hash_equals((string) $stored_marker['metadata_fingerprint'], $fingerprint)
            || !$this->operationIdsMatch(
                isset($stored_marker['operation_id']) ? (string) $stored_marker['operation_id'] : '',
                $operationId
            )
        ) {
            $this->logOptimizationQueueError($attachment_id, 'The image optimization state could not be saved.');
            return;
        }

        $data->delete_optimization_data();
        $result = $process->optimize($optimization_level, [
            'priority' => true,
            'advmo_context' => $context,
            'advmo_metadata_fingerprint' => $fingerprint,
            'advmo_operation_id' => $operationId,
        ]);

        if (is_wp_error($result) || $result !== true) {
            delete_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, $marker);
            $message = is_wp_error($result)
                ? $result->get_error_message()
                : 'Imagify did not accept the image optimization job.';
            $this->logOptimizationQueueError($attachment_id, $message);
            return;
        }

        // This hook normally runs from ADVMO's PHP shutdown verifier, after
        // Imagify's own WordPress shutdown dispatcher has already fired.
        // Dispatch the saved job now instead of waiting for the retry worker.
        $this->dispatchImagifyQueue($attachment_id);
    }

    public function uploadImageOperationSidecars(
        bool $uploaded,
        int $attachment_id,
        string $advmo_path,
        array $metadata,
        string $operation
    ): bool
    {
        if (!$uploaded || !self::isImagifyActive()) {
            return $uploaded;
        }

        return $this->uploadNextGenFiles($attachment_id, $advmo_path, $metadata);
    }

    public function deleteImageOperationSidecars(
        bool $deleted,
        int $attachment_id,
        int $retention_policy,
        array $metadata
    ): bool
    {
        if (!$deleted || !self::isImagifyActive()) {
            return $deleted;
        }

        return $this->deleteNextGenLocalFiles($attachment_id, $retention_policy);
    }

    private function isCurrentQueuedOperation(
        int $attachment_id,
        string $fingerprint,
        string $context,
        string $operationId
    ): bool
    {
        $queued = get_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, true);
        if (
            !is_array($queued)
            || empty($queued['metadata_fingerprint'])
            || !hash_equals((string) $queued['metadata_fingerprint'], $fingerprint)
            || ($queued['context'] ?? 'image_edit') !== $context
            || !$this->operationIdsMatch(
                isset($queued['operation_id']) ? (string) $queued['operation_id'] : '',
                $operationId
            )
        ) {
            return false;
        }

        $pendingCleanup = get_post_meta(
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            true
        );
        $pendingOperationId = is_array($pendingCleanup) && isset($pendingCleanup['operation_id'])
            ? (string) $pendingCleanup['operation_id']
            : '';
        if (!$this->operationIdsMatch($pendingOperationId, $operationId)) {
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        return is_array($metadata)
            && hash_equals($fingerprint, $this->getMetadataFingerprint($metadata));
    }

    private function clearQueuedOperation(
        int $attachment_id,
        string $fingerprint,
        string $context,
        string $operationId
    ): void
    {
        if ($fingerprint === '') {
            return;
        }

        $queued = get_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, true);
        if (
            is_array($queued)
            && !empty($queued['metadata_fingerprint'])
            && hash_equals((string) $queued['metadata_fingerprint'], $fingerprint)
            && ($queued['context'] ?? 'image_edit') === $context
            && $this->operationIdsMatch(
                isset($queued['operation_id']) ? (string) $queued['operation_id'] : '',
                $operationId
            )
        ) {
            delete_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, $queued);
        }
    }

    private function operationIdsMatch(string $expected, string $actual): bool
    {
        if ($expected === '') {
            return $actual === '';
        }

        return $actual !== '' && hash_equals($expected, $actual);
    }

    private function storeRegenerationOptimizerFingerprint(
        int $attachment_id,
        array $pendingCleanup,
        string $fingerprint
    ): ?array {
        if (($pendingCleanup['optimizer_metadata_fingerprint'] ?? '') === $fingerprint) {
            return $pendingCleanup;
        }

        $updated = $pendingCleanup;
        $updated['optimizer_metadata_fingerprint'] = $fingerprint;
        update_post_meta(
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            $updated,
            $pendingCleanup
        );

        $stored = get_post_meta(
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            true
        );
        if ($stored !== $updated) {
            $this->logOptimizationQueueError(
                $attachment_id,
                'The regeneration optimizer state could not be saved.'
            );
            return null;
        }

        return $updated;
    }

    public function afterDeferredCleanupExpired(int $attachment_id, array $pendingCleanup): void
    {
        if (($pendingCleanup['deferred_by'] ?? '') !== 'imagify') {
            return;
        }

        delete_post_meta($attachment_id, '_advmo_imagify_deferred_retention');
        delete_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED);
        delete_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD);
    }

    /**
     * Verify the exact custom Imagify job, not only its completion action.
     */
    private function verifyImagifyOperationResult(
        int $attachment_id,
        bool $requireFreshSidecars = false,
        bool $allowCloudOnlySizes = false
    ): bool
    {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $imagifyData = get_post_meta($attachment_id, '_imagify_data', true);
        $baseFile = (string) get_attached_file($attachment_id, true);
        $formats = function_exists('imagify_nextgen_images_formats')
            ? imagify_nextgen_images_formats()
            : [];
        if (!is_array($formats)) {
            $formats = [];
        }
        if (
            !is_array($metadata)
            || $baseFile === ''
        ) {
            return false;
        }
        if (!is_array($imagifyData)) {
            $imagifyData = [];
        }
        if (empty($formats) && (
            empty($imagifyData['sizes'])
            || !is_array($imagifyData['sizes'])
        )) {
            return false;
        }

        $files = ['full' => $baseFile];
        $directory = trailingslashit(dirname($baseFile));
        $registeredSizes = function_exists('wp_get_registered_image_subsizes')
            ? wp_get_registered_image_subsizes()
            : [];
        foreach (($metadata['sizes'] ?? []) as $size => $sizeData) {
            if (
                !is_array($sizeData)
                || empty($sizeData['file'])
                || (!empty($registeredSizes) && !isset($registeredSizes[$size]))
            ) {
                continue;
            }
            $files[(string) $size] = $directory . $sizeData['file'];
        }

        $disabledSizes = function_exists('get_imagify_option')
            ? get_imagify_option('disallowed-sizes')
            : [];
        if (!is_array($disabledSizes)) {
            $disabledSizes = [];
        }
        $cloudPrefix = (string) get_post_meta($attachment_id, 'advmo_path', true);
        $queuedOperation = $requireFreshSidecars
            ? get_post_meta($attachment_id, self::META_IMAGE_EDIT_QUEUED, true)
            : null;
        $operationStartedAt = is_array($queuedOperation) && !empty($queuedOperation['created_at'])
            ? (int) $queuedOperation['created_at']
            : 0;

        if ($requireFreshSidecars && $operationStartedAt <= 0) {
            return false;
        }

        foreach ($files as $size => $file) {
            if ($size !== 'full' && isset($disabledSizes[$size])) {
                continue;
            }

            $standardIsLocal = is_file($file);

            // With next-gen output disabled, the formats loop below is empty.
            // Require a real result for each standard file so a completion hook
            // cannot approve stale or missing Imagify data and start cleanup.
            if (empty($formats)) {
                if (!$standardIsLocal) {
                    if ($allowCloudOnlySizes) {
                        continue;
                    }
                    return false;
                }

                $standardResult = $imagifyData['sizes'][$size] ?? null;
                if (!is_array($standardResult)) {
                    return false;
                }
                if (!empty($standardResult['permanent_error'])) {
                    continue;
                }
                if (empty($standardResult['success'])) {
                    return false;
                }
                continue;
            }

            // The exact custom job is the completion proof for standard files.
            // Imagify intentionally omits per-size standard records when the
            // full image is already compressed and processes only next-gen
            // variants. The standard bytes were uploaded by ADVMO before this
            // job was queued, but they must still exist until cleanup.
            foreach ($formats as $format) {
                $format = sanitize_key((string) $format);
                if (!in_array($format, ['webp', 'avif'], true)) {
                    continue;
                }

                $nextGenResult = $imagifyData['sizes'][$size . '@imagify-' . $format] ?? null;
                if (!is_array($nextGenResult)) {
                    $nextGenResult = [];
                }
                if (!empty($nextGenResult['permanent_error'])) {
                    continue;
                }

                $nextGenFile = function_exists('imagify_path_to_nextgen')
                    ? imagify_path_to_nextgen($file, $format)
                    : $file . '.' . $format;

                // Image edits may keep an unchanged metadata size whose local
                // standard file was already removed by Full Cloud Migration.
                // Imagify cannot reprocess it. The existing sidecar is safe only
                // when the provider confirms that exact key still exists.
                if (!$standardIsLocal) {
                    if (!$allowCloudOnlySizes) {
                        return false;
                    }

                    try {
                        if (
                            $cloudPrefix !== ''
                            && $this->cloudProvider->objectExists(
                                $cloudPrefix . wp_basename((string) $nextGenFile)
                            )
                        ) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        error_log(sprintf(
                            'ADVMO Imagify Compat: Cloud sidecar verification failed for attachment %d: %s',
                            $attachment_id,
                            $e->getMessage()
                        ));
                    }

                    return false;
                }

                if (!is_string($nextGenFile) || !is_file($nextGenFile)) {
                    return false;
                }

                $nextGenModified = @filemtime($nextGenFile);
                if (
                    $requireFreshSidecars
                    && (
                        $nextGenModified === false
                        || $nextGenModified < $operationStartedAt
                    )
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Detect WP-CLI and common thumbnail regeneration call stacks. */
    private function isThumbnailRegenerationRequest(int $attachment_id): bool
    {
        $detected = false;

        if (defined('WP_CLI') && WP_CLI && !empty($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            $arguments = array_map('strtolower', array_map('strval', $_SERVER['argv']));
            $mediaIndex = array_search('media', $arguments, true);
            $detected = $mediaIndex !== false
                && isset($arguments[$mediaIndex + 1])
                && in_array($arguments[$mediaIndex + 1], ['regenerate', 'prune'], true);
        }

        if (!$detected) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24);
            foreach ($trace as $frame) {
                $function = strtolower((string) ($frame['function'] ?? ''));
                $class = strtolower((string) ($frame['class'] ?? ''));
                $call = $class . '::' . $function;

                if ($class === strtolower(self::class)) {
                    continue;
                }
                if (in_array($function, [
                    'process_regeneration',
                    'delete_unknown_image_sizes',
                    'wp_update_image_subsizes',
                ], true)) {
                    $detected = true;
                    break;
                }
                if (
                    false !== strpos($call, 'regenerat')
                    && (false !== strpos($call, 'thumbnail') || false !== strpos($call, 'media'))
                ) {
                    $detected = true;
                    break;
                }
            }
        }

        return (bool) apply_filters(
            'advmo_is_thumbnail_regeneration_request',
            $detected,
            $attachment_id
        );
    }

    private function getMetadataFingerprint(array $metadata): string
    {
        return hash('sha256', maybe_serialize($this->normalizeMetadataForFingerprint($metadata)));
    }

    private function normalizeMetadataForFingerprint(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if ($key === 'filesize') {
                unset($metadata[$key]);
                continue;
            }

            if (is_array($value)) {
                $metadata[$key] = $this->normalizeMetadataForFingerprint($value);
            }
        }

        ksort($metadata);
        return $metadata;
    }

    private function logOptimizationQueueError(int $attachment_id, string $message): void
    {
        $message = 'Imagify could not finish the Advanced Media Offloader image operation: ' . $message;
        $this->appendAttachmentError($attachment_id, $message);
        error_log("ADVMO Imagify Compat: {$message}");
    }

    private function clearIncompleteOptimizationError(int $attachment_id): void
    {
        $this->removeAttachmentError(
            $attachment_id,
            'Imagify could not finish the Advanced Media Offloader image operation: '
                . self::INCOMPLETE_OPTIMIZATION_ERROR
        );
    }

    private function dispatchImagifyQueue(int $attachment_id): void
    {
        if (!class_exists('\\Imagify\\Job\\MediaOptimization')) {
            $this->logOptimizationQueueError($attachment_id, 'Imagify could not dispatch the image optimization job.');
            return;
        }

        $job = \Imagify\Job\MediaOptimization::get_instance();
        if (!is_object($job) || !method_exists($job, 'dispatch')) {
            $this->logOptimizationQueueError($attachment_id, 'Imagify could not dispatch the image optimization job.');
            return;
        }

        $result = $job->dispatch();
        if (is_wp_error($result)) {
            $this->logOptimizationQueueError($attachment_id, $result->get_error_message());
        }
    }

    /**
     * Append Imagify WebP/AVIF sidecar keys to the list of S3 objects
     * to delete when an attachment is removed.
     *
     * @param string[] $keys          Object keys collected so far.
     * @param int      $attachment_id The attachment being deleted.
     * @param string   $base_dir      The base directory prefix.
     * @return string[]
     */
    public function addNextGenDeleteKeys(array $keys, int $attachment_id, string $base_dir): array
    {
        $imagify_data = get_post_meta($attachment_id, '_imagify_data', true);
        if (empty($imagify_data['sizes']) || !is_array($imagify_data['sizes'])) {
            return $keys;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $extensions = ['.webp', '.avif'];

        // Collect every standard file basename Imagify may have a next-gen
        // sidecar for. This includes the non-current files kept in
        // _wp_attachment_backup_sizes: after an image edit/restore the current
        // metadata points at one version while the sidecars for the other
        // version remain in the bucket, so building from current metadata
        // alone would orphan them.
        $basenames = [];

        $main_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (!empty($main_file)) {
            $basenames[] = wp_basename($main_file);
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeinfo) {
                if (!empty($sizeinfo['file'])) {
                    $basenames[] = $sizeinfo['file'];
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $basenames[] = $metadata['original_image'];
        }

        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        if (is_array($backup_sizes)) {
            foreach ($backup_sizes as $sizeinfo) {
                if (is_array($sizeinfo) && !empty($sizeinfo['file']) && is_string($sizeinfo['file'])) {
                    $basenames[] = $sizeinfo['file'];
                }
            }
        }

        foreach (array_unique($basenames) as $basename) {
            foreach ($extensions as $ext) {
                $keys[] = $base_dir . $basename . $ext;
            }
        }

        return $keys;
    }

    /**
     * Delete Imagify sidecars when regeneration removes or renames a standard
     * thumbnail.
     */
    public function addRegenerationObsoleteNextGenKeys(
        array $keys,
        int $attachment_id,
        string $base_dir,
        array $old_metadata,
        array $new_metadata
    ): array {
        $old_files = $this->getPrimarySizeFiles($old_metadata);
        $new_files = $this->getPrimarySizeFiles($new_metadata);
        $backup_files = array_map('wp_basename', array_keys($this->getImageEditBackupFiles($attachment_id)));
        $current_sidecars = [];
        foreach ($new_files as $basename) {
            $current_sidecars[] = $basename . '.webp';
            $current_sidecars[] = $basename . '.avif';
        }

        foreach (array_diff($old_files, $new_files, $backup_files) as $basename) {
            foreach ([$basename . '.webp', $basename . '.avif'] as $sidecar) {
                if (!in_array($sidecar, $current_sidecars, true)) {
                    $keys[] = $base_dir . $sidecar;
                }
            }
        }

        return $keys;
    }

    /** @return string[] */
    private function getPrimarySizeFiles(array $metadata): array
    {
        $files = [];
        foreach (($metadata['sizes'] ?? []) as $size_data) {
            if (is_array($size_data) && !empty($size_data['file']) && is_string($size_data['file'])) {
                $files[] = wp_basename($size_data['file']);
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Upload Imagify WebP/AVIF sidecar files during a reoffload.
     *
     * @param int    $attachment_id The attachment ID.
     * @param string $advmo_path    The cloud storage path prefix.
     */
    public function onReoffload(int $attachment_id, string $advmo_path): void
    {
        if (!self::isImagifyActive()) {
            return;
        }

        $this->uploadNextGenFiles($attachment_id, $advmo_path);
    }

    /**
     * Tell Imagify that WebP/AVIF versions exist on cloud storage so it
     * can generate <picture> tags for offloaded images.
     *
     * Modeled after Imagify's built-in AS3CF integration
     * (Imagify\ThirdParty\AS3CF\Main::picture_tag_webp_image).
     */
    public function pictureTagProcessImage($data)
    {
        global $wpdb;

        if (!is_array($data)) {
            return $data;
        }

        if (!empty($data['src']['webp_path'])) {
            return $data;
        }

        $match = $this->parseAdvmoUrl($data['src']['url'] ?? '');

        if (!$match) {
            return $data;
        }

        $post_id = $this->resolveAttachmentId($data, $match);

        if ($post_id <= 0) {
            return $data;
        }

        $imagify_data = get_post_meta($post_id, '_imagify_data', true);

        if (!$imagify_data || empty($imagify_data['sizes']) || !is_array($imagify_data['sizes'])) {
            return $data;
        }

        $webp_suffix = '@imagify-webp';
        $avif_suffix = '@imagify-avif';

        $src_size = $this->resolveSizeNameForUrl($post_id, $match['filename']);

        if (!empty($imagify_data['sizes'][$src_size . $webp_suffix]['success'])) {
            $data['src']['webp_exists'] = true;
        }
        if (!empty($imagify_data['sizes'][$src_size . $avif_suffix]['success'])) {
            $data['src']['avif_exists'] = true;
        }

        if (empty($data['srcset']) || !is_array($data['srcset'])) {
            return $data;
        }

        $metadata = wp_get_attachment_metadata($post_id);

        if (empty($metadata['sizes'])) {
            return $data;
        }

        $size_files = [];
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            $size_files[$size_data['file']] = $size_name;
        }

        $full_filename = !empty($metadata['file']) ? wp_basename($metadata['file']) : '';

        foreach ($data['srcset'] as $i => $srcset_data) {
            if (empty($srcset_data['webp_url'])) {
                continue;
            }
            if (!empty($srcset_data['webp_path'])) {
                continue;
            }

            $srcset_match = $this->parseAdvmoUrl($srcset_data['url'] ?? '');

            if (!$srcset_match) {
                continue;
            }

            $filename = $srcset_match['filename'];

            if ($full_filename && $filename === $full_filename) {
                $size_name = 'full';
            } elseif (isset($size_files[$filename])) {
                $size_name = $size_files[$filename];
            } else {
                continue;
            }

            if (!empty($imagify_data['sizes'][$size_name . $webp_suffix]['success'])) {
                $data['srcset'][$i]['webp_exists'] = true;
            }
            if (!empty($imagify_data['sizes'][$size_name . $avif_suffix]['success'])) {
                $data['srcset'][$i]['avif_exists'] = true;
            }
        }

        return $data;
    }

    /**
     * Parse an image URL to check if it is an ADVMO cloud URL.
     *
     * Mirrors AS3CF's is_s3_url() approach: validates the URL against
     * the configured cloud domain and extracts structural components.
     *
     * @return array|false Array with 'path', 'year_month', 'filename' on success. False otherwise.
     */
    private function parseAdvmoUrl(string $url)
    {
        if (empty($url)) {
            return false;
        }

        $domain = $this->cloudProvider->getDomain();
        if (empty($domain)) {
            return false;
        }

        $domain = rtrim($domain, '/');

        if (stripos($url, $domain) !== 0) {
            return false;
        }

        $relative_path = ltrim(substr($url, strlen($domain)), '/');

        if (empty($relative_path)) {
            return false;
        }

        $filename = wp_basename($relative_path);

        $year_month = '';
        if (preg_match('@(?:^|/)(\d{4}/\d{2})/@', $relative_path, $ym_match)) {
            $year_month = $ym_match[1] . '/';
        }

        return [
            'path'       => $relative_path,
            'year_month' => $year_month,
            'filename'   => $filename,
        ];
    }

    /**
     * Resolve attachment ID from image data.
     * Primary: wp-image-{id} class. Fallback: DB query on _wp_attached_file.
     */
    private function resolveAttachmentId(array $data, array $url_match): int
    {
        static $resolved_ids = [];

        $class_attr = $data['attributes']['class'] ?? '';
        $cache_key = $class_attr . '|' . ($url_match['year_month'] ?? '') . '|' . ($url_match['filename'] ?? '');

        if (array_key_exists($cache_key, $resolved_ids)) {
            return $resolved_ids[$cache_key];
        }

        if (!empty($data['attributes']['class'])) {
            if (preg_match('/wp-image-(\d+)/', $data['attributes']['class'], $matches)) {
                $id = (int) $matches[1];
                if ($id > 0 && $this->is_offloaded($id)) {
                    $resolved_ids[$cache_key] = $id;
                    return $id;
                }
            }
        }

        if (empty($url_match['year_month']) || empty($url_match['filename'])) {
            $resolved_ids[$cache_key] = 0;
            return 0;
        }

        global $wpdb;

        $post_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
                $url_match['year_month'] . $url_match['filename']
            )
        );

        if ($post_id > 0 && $this->is_offloaded($post_id)) {
            $resolved_ids[$cache_key] = $post_id;
            return $post_id;
        }

        $resolved_ids[$cache_key] = 0;
        return 0;
    }

    /**
     * Determine the Imagify size name for a given filename.
     * Returns 'full' for the main file, or the registered size name for thumbnails.
     */
    private function resolveSizeNameForUrl(int $attachment_id, string $filename): string
    {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!empty($metadata['file']) && wp_basename($metadata['file']) === $filename) {
            return 'full';
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if ($size_data['file'] === $filename) {
                    return $size_name;
                }
            }
        }

        return 'full';
    }

    /**
     * Re-upload standard files (main, thumbnails, original) which Imagify
     * has optimized in-place, overwriting the unoptimized cloud copies.
     */
    private function reUploadOptimizedFiles(int $attachment_id, string $advmo_path): bool
    {
        $all_uploaded = true;
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));

        if (file_exists($base_file)) {
            try {
                $uploaded = $this->cloudProvider->uploadFile($base_file, $advmo_path . wp_basename($base_file));
                if (!$uploaded) {
                    $all_uploaded = false;
                }
            } catch (\Exception $e) {
                $all_uploaded = false;
                error_log("ADVMO Imagify Compat: Error uploading {$base_file}: " . $e->getMessage());
            }
        }

        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                $size_file = $file_dir . $data['file'];
                if (file_exists($size_file)) {
                    try {
                        $uploaded = $this->cloudProvider->uploadFile($size_file, $advmo_path . $data['file']);
                        if (!$uploaded) {
                            $all_uploaded = false;
                        }
                    } catch (\Exception $e) {
                        $all_uploaded = false;
                        error_log("ADVMO Imagify Compat: Error uploading {$size_file}: " . $e->getMessage());
                    }
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $original_image = wp_get_original_image_path($attachment_id);
            if ($original_image && file_exists($original_image)) {
                try {
                    $uploaded = $this->cloudProvider->uploadFile($original_image, $advmo_path . wp_basename($original_image));
                    if (!$uploaded) {
                        $all_uploaded = false;
                    }
                } catch (\Exception $e) {
                    $all_uploaded = false;
                    error_log("ADVMO Imagify Compat: Error uploading {$original_image}: " . $e->getMessage());
                }
            }
        }

        return $all_uploaded;
    }

    /**
     * Upload Imagify-generated next-gen (WebP/AVIF) sidecar files to cloud.
     * 
     * Scans for actual files on disk rather than relying on _imagify_data success
     * flags, because during async thumbnail regeneration the metadata may not be
     * updated yet when our hook fires.
     */
    private function uploadNextGenFiles(
        int $attachment_id,
        string $advmo_path,
        ?array $metadata = null
    ): bool
    {
        $all_uploaded = true;
        $metadata = $metadata ?? wp_get_attachment_metadata($attachment_id);
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));

        $extensions = ['.webp', '.avif'];
        $files_to_upload = [];

        // Check main file for next-gen versions
        if (file_exists($base_file)) {
            foreach ($extensions as $ext) {
                $nextgen_path = $base_file . $ext;
                if (file_exists($nextgen_path)) {
                    $files_to_upload[] = $nextgen_path;
                }
            }
        }

        // Check all thumbnail sizes for next-gen versions
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (empty($size_data['file'])) {
                    continue;
                }
                $size_path = $file_dir . $size_data['file'];
                foreach ($extensions as $ext) {
                    $nextgen_path = $size_path . $ext;
                    if (file_exists($nextgen_path)) {
                        $files_to_upload[] = $nextgen_path;
                    }
                }
            }
        }

        // Check original image (WP 5.3+) for next-gen versions
        if (!empty($metadata['original_image'])) {
            $original_path = $file_dir . $metadata['original_image'];
            foreach ($extensions as $ext) {
                $nextgen_path = $original_path . $ext;
                if (file_exists($nextgen_path)) {
                    $files_to_upload[] = $nextgen_path;
                }
            }
        }

        // Include next-gen sidecars for files retained by the WordPress image
        // editor. These files are outside the current attachment metadata after
        // an edit, but they may become current again after Restore Original.
        foreach (array_keys($this->getImageEditBackupFiles($attachment_id)) as $backup_file) {
            $backup_path = $file_dir . $backup_file;
            foreach ($extensions as $ext) {
                $nextgen_path = $backup_path . $ext;
                if (file_exists($nextgen_path)) {
                    $files_to_upload[] = $nextgen_path;
                }
            }
        }

        // Upload all found next-gen files
        foreach (array_unique($files_to_upload) as $nextgen_path) {
            $cloud_key = $advmo_path . wp_basename($nextgen_path);

            try {
                $uploaded = $this->cloudProvider->uploadFile($nextgen_path, $cloud_key);
                if (!$uploaded) {
                    $all_uploaded = false;
                }
            } catch (\Exception $e) {
                $all_uploaded = false;
                error_log("ADVMO Imagify Compat: Error uploading {$nextgen_path}: " . $e->getMessage());
            }
        }

        return $all_uploaded;
    }

    /**
     * Delete Imagify next-gen sidecar files from local disk.
     * Called after the standard deleteLocalFile has handled normal sizes.
     */
    private function deleteNextGenLocalFiles(int $attachment_id, int $retention_policy): bool
    {
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));
        $metadata = wp_get_attachment_metadata($attachment_id);
        $all_deleted = true;

        $extensions = ['.webp', '.avif'];

        // Thumbnails: always delete next-gen sidecar files alongside their originals
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeinfo) {
                $sized_file = $file_dir . $sizeinfo['file'];
                foreach ($extensions as $ext) {
                    $nextgen = $sized_file . $ext;
                    if (file_exists($nextgen)) {
                        wp_delete_file($nextgen);
                        if (file_exists($nextgen)) {
                            $all_deleted = false;
                        }
                    }
                }
            }
        }

        // Apply the same retention rule to next-gen sidecars for WordPress
        // image-editor backups. Full-size backups stay under Smart Cleanup;
        // thumbnail backups do not.
        foreach ($this->getImageEditBackupFiles($attachment_id) as $backup_file => $keep_for_smart_cleanup) {
            if ($retention_policy === 1 && $keep_for_smart_cleanup) {
                continue;
            }

            $backup_path = $file_dir . $backup_file;
            foreach ($extensions as $ext) {
                $nextgen = $backup_path . $ext;
                if (file_exists($nextgen)) {
                    wp_delete_file($nextgen);
                    if (file_exists($nextgen)) {
                        $all_deleted = false;
                    }
                }
            }
        }

        // Full migration: also delete next-gen for the main file and original
        if ($retention_policy === 2) {
            foreach ($extensions as $ext) {
                $nextgen = $base_file . $ext;
                if (file_exists($nextgen)) {
                    wp_delete_file($nextgen);
                    if (file_exists($nextgen)) {
                        $all_deleted = false;
                    }
                }
            }

            if (!empty($metadata['original_image'])) {
                $original_image_path = wp_get_original_image_path($attachment_id);
                if ($original_image_path) {
                    foreach ($extensions as $ext) {
                        $nextgen = $original_image_path . $ext;
                        if (file_exists($nextgen)) {
                            wp_delete_file($nextgen);
                            if (file_exists($nextgen)) {
                                $all_deleted = false;
                            }
                        }
                    }
                }
            }
        }

        return $all_deleted;
    }
}
