<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class EWWWCompatObserver implements ObserverInterface
{
    use OffloaderTrait;

    private const META_DEFERRED_RETENTION = '_advmo_ewww_deferred_retention';
    private const META_IMAGE_OPERATION_ACTIVE = '_advmo_image_operation_in_progress';
    private const META_OPTIMIZED_PRECOMMIT = '_advmo_ewww_optimized_precommit';
    private const META_RECOVERY_QUEUED = '_advmo_ewww_recovery_queued';

    /**
     * Post-meta flag set by afterEWWWOptimize() when EWWW finishes optimizing an
     * attachment before AMO has offloaded it (EWWW runs at priority 15, AMO at
     * 99). afterAdvmoUpload() reads it to learn EWWW is already done so it can
     * complete the deferred WebP upload + local cleanup itself.
     */
    private const META_OPTIMIZED_PRE_OFFLOAD = '_advmo_ewww_optimized_preoffload';

    private S3_Provider $cloudProvider;

    /**
     * The offloaded attachment currently being edited in this request.
     *
     * EWWW normally sends image edits to its background queue while WordPress
     * is still filtering, but has not stored, the new metadata. Remembering the
     * attachment lets us make that one operation synchronous so EWWW processes
     * the exact metadata and files that WordPress is about to commit.
     */
    private ?int $activeImageEditAttachmentId = null;

    /**
     * Attachment whose final regeneration metadata has been verified by the
     * uploader and is now allowed to finish EWWW cleanup.
     */
    private ?int $committedRegenerationAttachmentId = null;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    public function register(): void
    {
        add_filter('load_image_to_edit_path', [$this, 'beginOffloadedImageEdit'], 5, 3);
        add_filter(
            'ewww_image_optimizer_background_optimization',
            [$this, 'disableBackgroundOptimizationDuringImageEdit'],
            PHP_INT_MAX
        );
        add_filter('advmo_local_cleanup_deferred_by', [$this, 'deferLocalCleanup'], 10, 4);
        add_filter('advmo_upload_image_operation_sidecars', [$this, 'uploadImageOperationSidecars'], 10, 5);
        add_filter('advmo_delete_image_operation_sidecars', [$this, 'deleteImageOperationSidecars'], 10, 4);
        add_action('ewww_image_optimizer_after_optimize_attachment', [$this, 'afterEWWWOptimize'], 10, 2);
        add_action('advmo_after_upload_to_cloud', [$this, 'afterAdvmoUpload'], 10, 1);
        add_action('advmo_image_operation_committed', [$this, 'afterImageOperationCommitted'], 10, 2);
        add_action('advmo_regeneration_committed', [$this, 'afterRegenerationCommitted'], 10, 2);
        add_action('advmo_deferred_cleanup_expired', [$this, 'afterDeferredCleanupExpired'], 10, 2);
        add_filter('webp_allowed_urls', [$this, 'addCloudDomainToAllowedUrls']);
        add_filter('ewww_image_optimizer_skip_webp_rewrite', [$this, 'skipWebPRewriteIfNoWebP'], 10, 2);
        add_filter('advmo_attachment_delete_keys', [$this, 'addWebPDeleteKeys'], 10, 3);
        add_filter('advmo_regeneration_obsolete_cloud_keys', [$this, 'addRegenerationObsoleteWebPKeys'], 10, 5);
        add_action('advmo_reoffload_attachment', [$this, 'onReoffload'], 10, 2);
    }

    /**
     * Mark an offloaded core image edit before EWWW selects its image editor.
     *
     * The database marker protects against an older EWWW worker completing in
     * parallel. Such a worker must not delete files or clear cleanup state that
     * belongs to the edit that is currently being saved.
     */
    public function beginOffloadedImageEdit($path, int $attachment_id, string $size = 'full')
    {
        if (
            $size !== 'full'
            || $this->getImageEditorOperation() !== 'image_edit'
            || !$this->is_offloaded($attachment_id)
        ) {
            return $path;
        }

        $this->activeImageEditAttachmentId = $attachment_id;
        update_post_meta($attachment_id, self::META_IMAGE_OPERATION_ACTIVE, [
            'created_at' => time(),
        ]);

        return $path;
    }

    /**
     * Process WordPress image edits synchronously.
     *
     * EWWW's background queue stores only the attachment ID. If its worker
     * starts before WordPress commits the edit metadata, it combines the new
     * main filename with the old thumbnail list. Synchronous processing keeps
     * the candidate metadata in the current request and removes that race.
     */
    public function disableBackgroundOptimizationDuringImageEdit($background_enabled): bool
    {
        if ($this->activeImageEditAttachmentId !== null) {
            return false;
        }

        return (bool) $background_enabled;
    }

    public static function isEWWWActive(): bool
    {
        return defined('EWWW_IMAGE_OPTIMIZER_VERSION') && function_exists('ewww_image_optimizer_get_option');
    }

    public static function isEWWWAutoOptimizeEnabled(): bool
    {
        if (!self::isEWWWActive()) {
            return false;
        }

        return !ewww_image_optimizer_get_option('ewww_image_optimizer_noauto');
    }

    public static function isEWWWWebPEnabled(): bool
    {
        if (!self::isEWWWActive()) {
            return false;
        }

        return (bool) ewww_image_optimizer_get_option('ewww_image_optimizer_webp');
    }

    /**
     * When EWWW auto-optimize is active and retention policy would delete
     * local files, defer the deletion so EWWW can process thumbnails first.
     */
    public function deferLocalCleanup(
        $deferred_by,
        int $deletion_rule,
        int $attachment_id,
        string $context = 'initial_upload'
    ): ?string
    {
        // Restore does not invoke EWWW's optimization pipeline. Its already
        // generated sidecars are uploaded directly by this integration instead.
        if ($deletion_rule === 0 || $context === 'image_restore') {
            delete_post_meta($attachment_id, self::META_DEFERRED_RETENTION);
            return null;
        }

        if (is_string($deferred_by) && $deferred_by !== '') {
            delete_post_meta($attachment_id, self::META_DEFERRED_RETENTION);
            return $deferred_by;
        }

        if (!self::isEWWWAutoOptimizeEnabled()) {
            delete_post_meta($attachment_id, self::META_DEFERRED_RETENTION);
            return null;
        }

        if (!wp_attachment_is_image($attachment_id)) {
            delete_post_meta($attachment_id, self::META_DEFERRED_RETENTION);
            return null;
        }

        update_post_meta($attachment_id, self::META_DEFERRED_RETENTION, $deletion_rule);

        return 'ewww';
    }

    /**
     * After ADVMO uploads to cloud (synchronous EWWW mode).
     *
     * In synchronous mode, EWWW has already optimized and created WebP
     * files before ADVMO runs. Upload the WebP sidecar files and apply
     * the deferred retention policy.
     *
     * In background mode, EWWW hasn't processed yet, so we skip here
     * and let afterEWWWOptimize() handle it later.
     */
    public function afterAdvmoUpload(int $attachment_id): void
    {
        if (!self::isEWWWActive()) {
            return;
        }

        $deferred = get_post_meta($attachment_id, self::META_DEFERRED_RETENTION, true);
        if ($deferred === '' || $deferred === false) {
            delete_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD);
            return;
        }

        // In background mode AMO offloads before EWWW optimizes, so completion
        // is split: whichever of "offload done" / "optimize done" happens last
        // finishes the deferral. If EWWW already finished before this offload
        // (it runs at priority 15, and falls back to synchronous when its async
        // queue is unavailable), afterEWWWOptimize() could not act pre-offload
        // and left a marker -> complete now. Otherwise EWWW is optimizing
        // asynchronously and afterEWWWOptimize() will complete it once that job
        // runs (the attachment is offloaded by then) -> bail here.
        $optimized_pre_offload = (bool) get_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD, true);
        if (!$optimized_pre_offload) {
            return;
        }

        if (!metadata_exists('post', $attachment_id, 'advmo_path')) {
            return;
        }
        $advmo_path = (string) get_post_meta($attachment_id, 'advmo_path', true);

        $webp_uploaded = true;
        if (self::isEWWWWebPEnabled()) {
            $webp_uploaded = $this->uploadWebPFiles($attachment_id, $advmo_path);
        }

        if (!$webp_uploaded) {
            error_log("ADVMO EWWW Compat: Skipping local deletion for attachment {$attachment_id} - WebP upload incomplete");
            return;
        }

        $policy = intval($deferred);
        $pending_cleanup = get_post_meta(
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            true
        );

        if ($policy > 0) {
            if (
                !is_array($pending_cleanup)
                || get_post_meta(
                    $attachment_id,
                    CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                    true
                ) !== $pending_cleanup
            ) {
                return;
            }

            $uploader = new CloudAttachmentUploader($this->cloudProvider);
            if (!$uploader->deleteLocalFile($attachment_id, $policy, false)) {
                return;
            }
            if (!$this->deleteWebPLocalFiles($attachment_id, $policy)) {
                return;
            }
            if (
                is_array($pending_cleanup)
                && !empty($pending_cleanup)
                && !$uploader->completePendingLocalCleanup($attachment_id, $pending_cleanup)
            ) {
                return;
            }
        }

        delete_post_meta($attachment_id, self::META_DEFERRED_RETENTION);
        delete_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD);
    }

    /**
     * After EWWW finishes optimizing (both sync and background).
     *
     * In background mode, this fires after ADVMO has already offloaded
     * unoptimized files. Re-upload the optimized versions and any WebP
     * sidecar files, then apply deferred retention.
     *
     * In synchronous mode, ADVMO hasn't offloaded yet (not offloaded),
     * so we skip -- afterAdvmoUpload() handles that case.
     *
     * Also handles manual/bulk optimization of already-offloaded images.
     *
     * @param int         $attachment_id
     * @param array|false $meta EWWW passes wp_get_attachment_metadata(), which is
     *                          false when the attachment was deleted while the
     *                          job sat in EWWW's background queue. No array type
     *                          hint here: a TypeError thrown inside EWWW's
     *                          background worker prevents the job from ever being
     *                          marked complete, permanently blocking its queue.
     */
    public function afterEWWWOptimize(int $attachment_id, $meta): void
    {
        // Attachment deleted between enqueue and dispatch: nothing to offload,
        // and writing the pre-offload marker below would create orphaned meta.
        if (get_post_type($attachment_id) !== 'attachment') {
            return;
        }

        if (!is_array($meta)) {
            $meta = wp_get_attachment_metadata($attachment_id);
            if (!is_array($meta)) {
                $meta = [];
            }
        }

        if (!$this->is_offloaded($attachment_id)) {
            // EWWW finished before AMO offloaded this attachment (EWWW optimizes
            // at priority 15, the offload runs at 99). The cloud copy doesn't
            // exist yet, so leave a marker and let afterAdvmoUpload() complete
            // the deferred WebP upload + local cleanup once the offload is done.
            if ($this->shouldDeleteLocal() > 0 && self::isEWWWAutoOptimizeEnabled()) {
                update_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD, 1);
            } else {
                delete_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD);
            }
            return;
        }

        if (!metadata_exists('post', $attachment_id, 'advmo_path')) {
            return;
        }
        $advmo_path = (string) get_post_meta($attachment_id, 'advmo_path', true);

        $metadata_fingerprint = $this->getMetadataFingerprint($meta);
        $pending_cleanup = get_post_meta(
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            true
        );

        // Only the optimizer that owns this exact deferred transaction may
        // upload, delete, or clear its cleanup state. A later EWWW worker must
        // not consume an Imagify-owned edit or regeneration marker.
        if (
            is_array($pending_cleanup)
            && !empty($pending_cleanup['deferred_by'])
            && ($pending_cleanup['deferred_by'] ?? '') !== 'ewww'
        ) {
            return;
        }

        $pending_matches = $this->pendingCleanupMatchesMetadata(
            $pending_cleanup,
            $metadata_fingerprint
        );

        $regeneration_pending = $this->isEWWWRegenerationPending($pending_cleanup);
        $is_regeneration_request = $this->isThumbnailRegenerationRequest($attachment_id);
        if (
            $this->committedRegenerationAttachmentId !== $attachment_id
            && ($regeneration_pending || $is_regeneration_request)
        ) {
            // EWWW may finish while WordPress has committed only the initial
            // empty-size metadata or one intermediate thumbnail. Remember the
            // optimized version, but never clean files until the uploader has
            // verified the final regeneration metadata.
            $this->rememberOptimizedPrecommit($attachment_id, $metadata_fingerprint);

            // A background EWWW worker can finish in a later request after the
            // metadata is committed. Re-enter the transaction verifier; it
            // will finish immediately only when the stored fingerprint matches.
            if ($regeneration_pending && !$is_regeneration_request) {
                $uploader = new CloudAttachmentUploader($this->cloudProvider);
                $uploader->resumePendingLocalCleanup($attachment_id);
            }
            return;
        }

        // A worker from an older edit can finish while WordPress is saving a
        // newer edit. Never let that callback upload/delete against mixed
        // metadata or clear the newer operation's pending marker.
        if (
            ($this->hasActiveImageOperation($attachment_id) && !$pending_matches)
            || ($this->isEWWWImageOperationPending($pending_cleanup) && !$pending_matches)
        ) {
            $this->rememberOptimizedPrecommit($attachment_id, $metadata_fingerprint);
            return;
        }

        $standard_uploaded = $this->reUploadOptimizedFiles($attachment_id, $advmo_path, $meta);

        $webp_uploaded = true;
        if (self::isEWWWWebPEnabled()) {
            $webp_uploaded = $this->uploadWebPFiles($attachment_id, $advmo_path, $meta);
        }

        if (!$standard_uploaded || !$webp_uploaded) {
            return;
        }

        // A newer operation can replace the cleanup state while cloud uploads
        // are running. The operation ID in the pending state makes an identical
        // metadata generation distinct, so stale EWWW work must stop here.
        if (
            is_array($pending_cleanup)
            && !empty($pending_cleanup)
            && get_post_meta(
                $attachment_id,
                CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                true
            ) !== $pending_cleanup
        ) {
            return;
        }

        // EWWW can finish synchronously at priority 15 while WordPress is still
        // filtering the new edit metadata. Upload now, but never delete files
        // until the metadata write has committed. The shutdown completion hook
        // below re-enters this method with the stored metadata.
        if ($this->isMetadataCommitPending($attachment_id, $meta)) {
            $this->rememberOptimizedPrecommit($attachment_id, $metadata_fingerprint);
            return;
        }

        // EWWW's media queue stores only an attachment ID. A worker that read
        // the previous metadata may later fire its completion action with the
        // newly committed metadata. Confirm that EWWW actually processed every
        // eligible local file before accepting that completion.
        if ($pending_matches && !$this->hasEWWWProcessedMetadata($attachment_id, $meta)) {
            $this->queueCommittedMetadata($attachment_id, $metadata_fingerprint);
            return;
        }

        $deferred = get_post_meta($attachment_id, self::META_DEFERRED_RETENTION, true);
        $has_deferred_retention = $deferred !== '' && $deferred !== false;

        if ($has_deferred_retention) {
            $policy = intval($deferred);
        } else {
            $policy = $this->shouldDeleteLocal();
        }

        if ($policy > 0) {
            $uploader = new CloudAttachmentUploader($this->cloudProvider);
            if (!$uploader->deleteLocalFile($attachment_id, $policy, false)) {
                return;
            }
            if (!$this->deleteWebPLocalFiles($attachment_id, $policy)) {
                return;
            }
            if (
                is_array($pending_cleanup)
                && !empty($pending_cleanup)
                && !$uploader->completePendingLocalCleanup($attachment_id, $pending_cleanup)
            ) {
                return;
            }
        }

        if ($has_deferred_retention) {
            delete_post_meta($attachment_id, self::META_DEFERRED_RETENTION);
        }
        delete_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD);
        delete_post_meta($attachment_id, self::META_OPTIMIZED_PRECOMMIT);
        delete_post_meta($attachment_id, self::META_IMAGE_OPERATION_ACTIVE);
        delete_post_meta($attachment_id, self::META_RECOVERY_QUEUED);
    }

    /**
     * Complete a synchronous EWWW edit after WordPress stores its metadata.
     */
    public function afterImageOperationCommitted(int $attachment_id, array $pendingCleanup): void
    {
        if (($pendingCleanup['deferred_by'] ?? '') !== 'ewww') {
            return;
        }

        $expected = isset($pendingCleanup['metadata_fingerprint'])
            ? (string) $pendingCleanup['metadata_fingerprint']
            : '';
        if ($expected === '') {
            return;
        }

        if (!$this->hasOptimizedPrecommit($attachment_id, $expected)) {
            // This is also the recovery path for cleanup records left by an
            // older race. Queue EWWW only after WordPress's metadata is known
            // to be committed, so it sees the correct list of edited sizes.
            $this->queueCommittedMetadata($attachment_id, $expected);
            return;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            return;
        }

        $this->afterEWWWOptimize($attachment_id, $metadata);
    }

    /**
     * Queue EWWW against the committed regeneration metadata. Regeneration uses
     * a serving-file fingerprint, while EWWW keeps its own optimizer fingerprint,
     * so the latter is calculated from the committed metadata here.
     */
    public function afterRegenerationCommitted(int $attachment_id, array $pendingCleanup): void
    {
        if (($pendingCleanup['deferred_by'] ?? '') !== 'ewww') {
            return;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            return;
        }

        $fingerprint = $this->getMetadataFingerprint($metadata);
        $pendingCleanup = $this->storeRegenerationOptimizerFingerprint(
            $attachment_id,
            $pendingCleanup,
            $fingerprint
        );
        if ($pendingCleanup === null) {
            return;
        }

        $previous = $this->committedRegenerationAttachmentId;
        $this->committedRegenerationAttachmentId = $attachment_id;

        try {
            if (!$this->hasOptimizedPrecommit($attachment_id, $fingerprint)) {
                $this->queueCommittedMetadata($attachment_id, $fingerprint);
                return;
            }

            $this->afterEWWWOptimize($attachment_id, $metadata);
        } finally {
            $this->committedRegenerationAttachmentId = $previous;
        }
    }

    public function uploadImageOperationSidecars(
        bool $uploaded,
        int $attachment_id,
        string $advmo_path,
        array $metadata,
        string $operation
    ): bool
    {
        if (!$uploaded || !self::isEWWWActive() || !self::isEWWWWebPEnabled()) {
            return $uploaded;
        }

        return $this->uploadWebPFiles($attachment_id, $advmo_path, $metadata);
    }

    public function deleteImageOperationSidecars(
        bool $deleted,
        int $attachment_id,
        int $retention_policy,
        array $metadata
    ): bool
    {
        if (!$deleted || !self::isEWWWActive()) {
            return $deleted;
        }

        return $this->deleteWebPLocalFiles($attachment_id, $retention_policy);
    }

    private function isMetadataCommitPending(int $attachment_id, array $metadata): bool
    {
        $stored = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        if (!is_array($stored)) {
            return true;
        }

        return !hash_equals(
            $this->getMetadataFingerprint($metadata),
            $this->getMetadataFingerprint($stored)
        );
    }

    private function getMetadataFingerprint(array $metadata): string
    {
        return hash('sha256', maybe_serialize($this->normalizeMetadataForFingerprint($metadata)));
    }

    /**
     * Ignore optimizer-owned filesize values when identifying an image edit.
     *
     * EWWW refreshes those values after compression. Filenames, dimensions,
     * sources, and every other metadata field still remain part of the token.
     */
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

    private function isEWWWImageOperationPending($pending_cleanup): bool
    {
        return is_array($pending_cleanup)
            && ($pending_cleanup['context'] ?? '') === 'image_edit'
            && ($pending_cleanup['deferred_by'] ?? '') === 'ewww';
    }

    private function isEWWWRegenerationPending($pending_cleanup): bool
    {
        return is_array($pending_cleanup)
            && ($pending_cleanup['context'] ?? '') === 'thumbnail_regeneration'
            && ($pending_cleanup['deferred_by'] ?? '') === 'ewww';
    }

    /**
     * Detect WP-CLI and common plugin regeneration call stacks.
     */
    private function isThumbnailRegenerationRequest(int $attachment_id): bool
    {
        $detected = false;

        if (defined('WP_CLI') && WP_CLI && !empty($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            $arguments = array_map('strtolower', array_map('strval', $_SERVER['argv']));
            $media_index = array_search('media', $arguments, true);
            $detected = $media_index !== false
                && isset($arguments[$media_index + 1])
                && in_array($arguments[$media_index + 1], ['regenerate', 'prune'], true);
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

    private function pendingCleanupMatchesMetadata($pending_cleanup, string $metadata_fingerprint): bool
    {
        if (!is_array($pending_cleanup)) {
            return false;
        }

        if ($this->isEWWWImageOperationPending($pending_cleanup)) {
            $expected = isset($pending_cleanup['metadata_fingerprint'])
                ? (string) $pending_cleanup['metadata_fingerprint']
                : '';
        } elseif ($this->isEWWWRegenerationPending($pending_cleanup)) {
            $expected = isset($pending_cleanup['optimizer_metadata_fingerprint'])
                ? (string) $pending_cleanup['optimizer_metadata_fingerprint']
                : '';
        } else {
            return false;
        }

        return $expected !== '' && hash_equals($expected, $metadata_fingerprint);
    }

    /**
     * Store EWWW's exact metadata token without replacing a newer transaction.
     */
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
            error_log(sprintf(
                'ADVMO EWWW Compat: Could not save the regeneration optimizer token for attachment %d.',
                $attachment_id
            ));
            return null;
        }

        return $updated;
    }

    public function afterDeferredCleanupExpired(int $attachment_id, array $pendingCleanup): void
    {
        if (($pendingCleanup['deferred_by'] ?? '') !== 'ewww') {
            return;
        }

        delete_post_meta($attachment_id, self::META_DEFERRED_RETENTION);
        delete_post_meta($attachment_id, self::META_OPTIMIZED_PRE_OFFLOAD);
        delete_post_meta($attachment_id, self::META_OPTIMIZED_PRECOMMIT);
        delete_post_meta($attachment_id, self::META_IMAGE_OPERATION_ACTIVE);
        delete_post_meta($attachment_id, self::META_RECOVERY_QUEUED);
    }

    private function hasActiveImageOperation(int $attachment_id): bool
    {
        $active = get_post_meta($attachment_id, self::META_IMAGE_OPERATION_ACTIVE, true);
        if (!is_array($active) || empty($active['created_at'])) {
            return false;
        }

        if ((time() - (int) $active['created_at']) < (5 * MINUTE_IN_SECONDS)) {
            return true;
        }

        // A save can fail before wp_update_attachment_metadata() is reached.
        // Do not let that abandoned marker block later optimizer work forever.
        delete_post_meta($attachment_id, self::META_IMAGE_OPERATION_ACTIVE);
        return false;
    }

    /**
     * Remember every metadata version optimized while an edit is uncommitted.
     *
     * A set is used instead of a single value because an older background
     * callback may overlap the synchronous optimization of the current edit.
     */
    private function rememberOptimizedPrecommit(int $attachment_id, string $fingerprint): void
    {
        $stored = get_post_meta($attachment_id, self::META_OPTIMIZED_PRECOMMIT, true);
        $optimized = [];

        if (is_array($stored)) {
            $optimized = $stored;
        } elseif (is_string($stored) && $stored !== '') {
            // Backward compatibility with the original single-fingerprint marker.
            $optimized[$stored] = time();
        }

        $cutoff = time() - HOUR_IN_SECONDS;
        foreach ($optimized as $known_fingerprint => $created_at) {
            if (!is_string($known_fingerprint) || (int) $created_at < $cutoff) {
                unset($optimized[$known_fingerprint]);
            }
        }

        $optimized[$fingerprint] = time();
        update_post_meta($attachment_id, self::META_OPTIMIZED_PRECOMMIT, $optimized);
    }

    private function hasOptimizedPrecommit(int $attachment_id, string $expected): bool
    {
        $optimized = get_post_meta($attachment_id, self::META_OPTIMIZED_PRECOMMIT, true);

        if (is_string($optimized)) {
            return $optimized !== '' && hash_equals($expected, $optimized);
        }

        return is_array($optimized) && array_key_exists($expected, $optimized);
    }

    /**
     * Verify that EWWW processed every local file represented by this metadata.
     *
     * The completion action alone is not sufficient. Its async attachment
     * update reads metadata again, so a worker that processed only stale sizes
     * can still emit the new metadata after WordPress commits it.
     */
    private function hasEWWWProcessedMetadata(int $attachment_id, array $metadata): bool
    {
        global $wpdb;

        $base_file = (string) get_attached_file($attachment_id, true);
        if ($base_file === '') {
            return false;
        }

        $files = ['full' => $base_file];
        $file_dir = trailingslashit(dirname($base_file));
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                if (is_array($data) && !empty($data['file'])) {
                    $files[(string) $size] = $file_dir . $data['file'];
                }
            }
        }

        $disabled_sizes = ewww_image_optimizer_get_option(
            'ewww_image_optimizer_disable_resizes_opt',
            false,
            true
        );
        if (!is_array($disabled_sizes)) {
            $disabled_sizes = [];
        }

        $table = $wpdb->prefix . 'ewwwio_images';
        foreach ($files as $size => $file_path) {
            if ($size !== 'full' && !empty($disabled_sizes[$size])) {
                continue;
            }
            if (!$this->isEWWWEligibleLocalFile($file_path)) {
                continue;
            }

            $relative_path = function_exists('ewww_image_optimizer_relativize_path')
                ? ewww_image_optimizer_relativize_path($file_path)
                : $file_path;
            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT image_size, pending
                FROM {$table}
                WHERE attachment_id = %d
                AND path IN (%s, %s)
                ORDER BY id DESC
                LIMIT 1",
                $attachment_id,
                $relative_path,
                $file_path
            ), ARRAY_A);

            if (
                !is_array($record)
                || (int) $record['pending'] !== 0
                || (int) $record['image_size'] !== (int) filesize($file_path)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isEWWWEligibleLocalFile(string $file_path): bool
    {
        if (!is_file($file_path)) {
            // A size already removed by an earlier committed cleanup does not
            // need to be processed again.
            return false;
        }

        if (apply_filters('ewww_image_optimizer_bypass', false, $file_path)) {
            return false;
        }

        $file_size = filesize($file_path);
        if (!$file_size) {
            return false;
        }

        $minimum_size = (int) ewww_image_optimizer_get_option('ewww_image_optimizer_skip_size');
        if ($minimum_size > 0 && $file_size < $minimum_size) {
            return false;
        }

        $mime = function_exists('ewww_image_optimizer_quick_mimetype')
            ? ewww_image_optimizer_quick_mimetype($file_path)
            : '';
        $maximum_png_size = (int) ewww_image_optimizer_get_option('ewww_image_optimizer_skip_png_size');
        if ($mime === 'image/png' && $maximum_png_size > 0 && $file_size > $maximum_png_size) {
            return false;
        }

        return true;
    }

    /**
     * Queue one retry against metadata that is already committed.
     */
    private function queueCommittedMetadata(int $attachment_id, string $expected_fingerprint): void
    {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (
            !is_array($metadata)
            || !hash_equals($expected_fingerprint, $this->getMetadataFingerprint($metadata))
            || !function_exists('ewww_image_optimizer_add_attachment_to_queue')
        ) {
            return;
        }

        $queued = get_post_meta($attachment_id, self::META_RECOVERY_QUEUED, true);
        if (
            is_array($queued)
            && ($queued['metadata_fingerprint'] ?? '') === $expected_fingerprint
            && !empty($queued['created_at'])
            && (time() - (int) $queued['created_at']) < (15 * MINUTE_IN_SECONDS)
        ) {
            return;
        }

        update_post_meta($attachment_id, self::META_RECOVERY_QUEUED, [
            'metadata_fingerprint' => $expected_fingerprint,
            'created_at' => time(),
        ]);

        try {
            ewww_image_optimizer_add_attachment_to_queue($attachment_id, false);
        } catch (\Throwable $e) {
            delete_post_meta($attachment_id, self::META_RECOVERY_QUEUED);
            error_log(sprintf(
                'ADVMO EWWW Compat: Failed to queue committed metadata for attachment %d: %s',
                $attachment_id,
                $e->getMessage()
            ));
        }
    }

    /**
     * Inject the cloud storage domain into EWWW's Force WebP allowed URLs.
     *
     * This tells EWWW's Picture WebP and JS WebP delivery that images
     * served from the cloud domain have WebP variants, enabling
     * <picture> tag or JS-based WebP rewriting for offloaded images.
     */
    public function addCloudDomainToAllowedUrls(array $urls): array
    {
        $domain = $this->cloudProvider->getDomain();
        if (!empty($domain)) {
            $urls[] = rtrim($domain, '/');
        }
        return $urls;
    }

    /**
     * Skip WebP rewriting for cloud images where EWWW did not
     * successfully generate a WebP version.
     *
     * Queries EWWW's ewwwio_images table by file path. If no record
     * exists or webp_size is 0 (conversion failed / never attempted),
     * returns true so EWWW does not emit a <picture> tag pointing
     * to a non-existent .webp file.
     *
     * Runs before the Force WebP blanket check, giving per-image accuracy.
     */
    public function skipWebPRewriteIfNoWebP(bool $skip, string $image_url): bool
    {
        if ($skip) {
            return $skip;
        }

        $domain = $this->cloudProvider->getDomain();
        if (empty($domain)) {
            return $skip;
        }

        $domain = rtrim($domain, '/');
        if (strpos($image_url, $domain) === false) {
            return $skip;
        }

        static $cache = [];
        if (isset($cache[$image_url])) {
            return $cache[$image_url];
        }

        $relative_path = str_replace($domain . '/', '', $image_url);
        $relative_path = strtok($relative_path, '?#');

        $basename = wp_basename($relative_path);
        $upload_dir = wp_get_upload_dir();
        $uploads_rel = str_replace(trailingslashit(ABSPATH), '', trailingslashit($upload_dir['basedir']));

        if (preg_match('#(\d{4}/\d{2})/#', $relative_path, $matches)) {
            $local_rel = $uploads_rel . $matches[1] . '/' . $basename;
        } else {
            $local_rel = $uploads_rel . $basename;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ewwwio_images';
        $ewww_path = 'ABSPATH' . $local_rel;
        $absolute_path = trailingslashit(ABSPATH) . $local_rel;

        $webp_size = $wpdb->get_var($wpdb->prepare(
            "SELECT webp_size FROM {$table_name} WHERE path IN (%s, %s) LIMIT 1",
            $ewww_path,
            $absolute_path
        ));

        $should_skip = empty($webp_size);
        $cache[$image_url] = $should_skip;

        return $should_skip;
    }

    /**
     * Upload EWWW WebP sidecar files during a reoffload.
     *
     * @param int    $attachment_id The attachment ID.
     * @param string $advmo_path    The cloud storage path prefix.
     */
    public function onReoffload(int $attachment_id, string $advmo_path): void
    {
        if (!self::isEWWWActive() || !self::isEWWWWebPEnabled()) {
            return;
        }

        $this->uploadWebPFiles($attachment_id, $advmo_path);
    }

    /**
     * Append EWWW WebP sidecar keys to the list of S3 objects
     * to delete when an attachment is removed.
     *
     * Covers both naming modes (append and replace) in case the
     * mode was changed after the files were originally created.
     *
     * @param string[] $keys          Object keys collected so far.
     * @param int      $attachment_id The attachment being deleted.
     * @param string   $base_dir      The base directory prefix.
     * @return string[]
     */
    public function addWebPDeleteKeys(array $keys, int $attachment_id, string $base_dir): array
    {
        $metadata = wp_get_attachment_metadata($attachment_id);

        $main_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $main_basename = $main_file ? wp_basename($main_file) : '';

        $basenames = [];

        if (!empty($main_basename)) {
            $basenames[] = $main_basename;
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

        // Include the non-current files kept in _wp_attachment_backup_sizes
        // (the other version after an image edit/restore), so their WebP
        // sidecars are not orphaned in cloud storage.
        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        if (is_array($backup_sizes)) {
            foreach ($backup_sizes as $sizeinfo) {
                if (is_array($sizeinfo) && !empty($sizeinfo['file']) && is_string($sizeinfo['file'])) {
                    $basenames[] = $sizeinfo['file'];
                }
            }
        }

        foreach (array_unique($basenames) as $basename) {
            $webp_names = $this->getAllWebPBasenames($basename);
            foreach ($webp_names as $webp_name) {
                $keys[] = $base_dir . $webp_name;
            }
        }

        return $keys;
    }

    /**
     * Delete EWWW sidecars when regeneration removes or renames a standard
     * thumbnail. Both EWWW naming modes are covered.
     */
    public function addRegenerationObsoleteWebPKeys(
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
            $current_sidecars = array_merge($current_sidecars, $this->getAllWebPBasenames($basename));
        }

        foreach (array_diff($old_files, $new_files, $backup_files) as $basename) {
            foreach ($this->getAllWebPBasenames($basename) as $webp_name) {
                if (!in_array($webp_name, $current_sidecars, true)) {
                    $keys[] = $base_dir . $webp_name;
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
     * Re-upload standard files (main, thumbnails, original) which EWWW
     * has optimized in-place, overwriting the unoptimized cloud copies.
     */
    private function reUploadOptimizedFiles(int $attachment_id, string $advmo_path, ?array $meta = null): bool
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
            }
        }

        $metadata = $meta ?? wp_get_attachment_metadata($attachment_id);

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
                }
            }
        }

        return $all_uploaded;
    }

    /**
     * Upload EWWW-generated WebP sidecar files to cloud.
     *
     * Discovers WebP files by checking the filesystem using EWWW's
     * naming conventions (append or replace mode).
     */
    private function uploadWebPFiles(int $attachment_id, string $advmo_path, ?array $meta = null): bool
    {
        $all_uploaded = true;
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));
        $metadata = $meta ?? wp_get_attachment_metadata($attachment_id);

        $files_to_check = [$base_file];

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $data) {
                if (!empty($data['file'])) {
                    $files_to_check[] = $file_dir . $data['file'];
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $original_image = wp_get_original_image_path($attachment_id);
            if ($original_image) {
                $files_to_check[] = $original_image;
            }
        }

        // WordPress removes pre-edit files from the current metadata and keeps
        // them in its backup metadata. Manual offload must include their WebP
        // sidecars too, otherwise Restore Original points at standard files
        // whose matching WebP objects were never uploaded.
        foreach (array_keys($this->getImageEditBackupFiles($attachment_id)) as $backup_file) {
            $files_to_check[] = $file_dir . $backup_file;
        }

        $checked = [];
        foreach ($files_to_check as $file_path) {
            $webp_path = $this->getWebPPath($file_path);
            if (!$webp_path || isset($checked[$webp_path])) {
                continue;
            }
            $checked[$webp_path] = true;

            if (!file_exists($webp_path)) {
                continue;
            }

            $cloud_key = $advmo_path . wp_basename($webp_path);

            try {
                $uploaded = $this->cloudProvider->uploadFile($webp_path, $cloud_key);
                if (!$uploaded) {
                    $all_uploaded = false;
                }
            } catch (\Exception $e) {
                $all_uploaded = false;
                error_log("ADVMO EWWW Compat: Error uploading {$webp_path}: " . $e->getMessage());
            }
        }

        return $all_uploaded;
    }

    /**
     * Delete EWWW WebP sidecar files from local disk.
     * Called after the standard deleteLocalFile has handled normal sizes.
     */
    private function deleteWebPLocalFiles(int $attachment_id, int $retention_policy): bool
    {
        $base_file = get_attached_file($attachment_id, true);
        $file_dir = trailingslashit(dirname($base_file));
        $metadata = wp_get_attachment_metadata($attachment_id);
        $all_deleted = true;

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeinfo) {
                if (empty($sizeinfo['file'])) {
                    continue;
                }
                $sized_file = $file_dir . $sizeinfo['file'];
                $webp_path = $this->getWebPPath($sized_file);
                if ($webp_path && file_exists($webp_path)) {
                    wp_delete_file($webp_path);
                    if (file_exists($webp_path)) {
                        $all_deleted = false;
                    }
                }
            }
        }

        // Apply retention to WebP sidecars for WordPress image-editor backups.
        // Smart Cleanup keeps full-size backup sidecars and removes thumbnail
        // sidecars. Full Migration removes both.
        foreach ($this->getImageEditBackupFiles($attachment_id) as $backup_file => $keep_for_smart_cleanup) {
            if ($retention_policy === 1 && $keep_for_smart_cleanup) {
                continue;
            }

            $webp_path = $this->getWebPPath($file_dir . $backup_file);
            if ($webp_path && file_exists($webp_path)) {
                wp_delete_file($webp_path);
                if (file_exists($webp_path)) {
                    $all_deleted = false;
                }
            }
        }

        if ($retention_policy === 2) {
            $webp_path = $this->getWebPPath($base_file);
            if ($webp_path && file_exists($webp_path)) {
                wp_delete_file($webp_path);
                if (file_exists($webp_path)) {
                    $all_deleted = false;
                }
            }

            if (!empty($metadata['original_image'])) {
                $original_image_path = wp_get_original_image_path($attachment_id);
                if ($original_image_path) {
                    $webp_path = $this->getWebPPath($original_image_path);
                    if ($webp_path && file_exists($webp_path)) {
                        wp_delete_file($webp_path);
                        if (file_exists($webp_path)) {
                            $all_deleted = false;
                        }
                    }
                }
            }
        }

        return $all_deleted;
    }

    /**
     * Get the WebP file path for a given source image using EWWW's
     * naming convention (respects append/replace mode).
     *
     * @param string $file Full filesystem path to the source image.
     * @return string|false WebP path, or false if EWWW functions unavailable.
     */
    private function getWebPPath(string $file)
    {
        if (function_exists('ewww_image_optimizer_get_webp_path')) {
            return ewww_image_optimizer_get_webp_path($file);
        }

        return $file . '.webp';
    }

    /**
     * Get all possible WebP basenames for a given file basename.
     *
     * Returns both append and replace naming patterns to ensure cloud
     * cleanup works regardless of which naming mode was active.
     *
     * @param string $basename The source file basename (e.g. "photo.jpg").
     * @return string[] Array of WebP basenames.
     */
    private function getAllWebPBasenames(string $basename): array
    {
        if (function_exists('ewww_image_optimizer_get_all_webp_paths')) {
            $paths = ewww_image_optimizer_get_all_webp_paths($basename);
            return array_filter($paths, 'strlen');
        }

        $info = pathinfo($basename);
        $append = $basename . '.webp';
        $replace = $info['filename'] . '.webp';

        if ($append === $replace) {
            return [$append];
        }

        return [$append, $replace];
    }
}
