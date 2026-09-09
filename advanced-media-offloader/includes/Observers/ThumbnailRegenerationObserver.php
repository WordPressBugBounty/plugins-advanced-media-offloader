<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class ThumbnailRegenerationObserver implements ObserverInterface
{
    use OffloaderTrait;

    private const MAX_REPORTED_FAILURES = 100;

    private S3_Provider $cloudProvider;

    private CloudAttachmentUploader $cloudAttachmentUploader;

    /** @var array<int,array> Metadata captured before WordPress starts partial writes. */
    private array $originalMetadata = [];

    /** @var array<int,string> */
    private array $originalAttachedFiles = [];

    /** @var array<int,array<string,bool>> Files downloaded only to support regeneration. */
    private array $hydratedFiles = [];

    /** @var array<int,bool> Regenerations that already staged an earlier metadata write. */
    private array $stagedRegeneration = [];

    /** @var array<int,bool> A format plugin is filtering the generated metadata. */
    private array $metadataGenerationInProgress = [];

    /** One fallback handles only attachments still unfinished at shutdown. */
    private bool $shutdownFallbackRegistered = false;

    /** @var array<int,bool> Prevent commit hooks from reacting to a CLI rollback. */
    private array $restoringMetadata = [];

    /** @var array<int,bool> Failed results must stay local for inspection/retry. */
    private array $syncFailed = [];

    /** @var array<int,bool> */
    private array $reportedFailures = [];

    /** @var array<int,bool> Fail delete-unknown only after its full WP-CLI batch. */
    private array $wpCliDeleteUnknownFailures = [];

    /** @var array<int,bool> Whether old cleanup was resolved before generation. */
    private array $regenerationPreflight = [];

    /** @var array<int,bool> Prevent cleanup path lookups from re-entering preflight. */
    private array $regenerationPreflightInProgress = [];

    /** @var array<int,array<string,bool>> Local files observed during this regeneration. */
    private array $regeneratedLocalFiles = [];

    /** @var array<int,string> Complete WP-CLI metadata already synced pre-commit. */
    private array $generatedMetadataSynced = [];

    /** @var array<int,bool> Prevent retention lookups from hydrating during cleanup. */
    private array $cleanupInProgress = [];

    /** @var array<int,int> Effective retention rule captured before cloud sync starts. */
    private array $regenerationRetentionPolicies = [];

    /** @var array<int,bool> Do not hydrate again after this request committed cleanup. */
    private array $completedRegenerations = [];

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($cloudProvider);
    }

    public function register(): void
    {
        // Any offloaded attachment may need its cloud source restored before
        // WP-CLI or WordPress checks file_exists() and starts image generation.
        add_filter('get_attached_file', [$this, 'hydrateAttachedFile'], 20, 2);
        add_filter('wp_get_original_image_path', [$this, 'hydrateOriginalImage'], 20, 2);

        // Format plugins can write metadata while the generated-metadata filter
        // is still running. Treat those writes as intermediate and sync only the
        // complete result returned to the regeneration caller.
        add_filter('wp_generate_attachment_metadata', [$this, 'beginGeneratedMetadataFilter'], 0, 2);
        add_filter('wp_generate_attachment_metadata', [$this, 'finishGeneratedMetadataFilter'], PHP_INT_MAX, 2);
        add_filter(
            'wp_get_missing_image_subsizes',
            [$this, 'finishMissingSubsizeCheck'],
            PHP_INT_MAX,
            3
        );

        // Priority 98 runs before AttachmentUpdateObserver. Intermediate writes
        // made by _wp_make_subsizes are observed only to preserve the true old
        // metadata; the complete final metadata is synced once by the outer call.
        add_filter('wp_update_attachment_metadata', [$this, 'run'], 98, 2);
        add_filter('wp_update_attachment_metadata', [$this, 'finishMetadataUpdateFilter'], PHP_INT_MAX, 2);
        add_action('added_post_meta', [$this, 'afterMetadataCommit'], 10, 4);
        add_action('updated_post_meta', [$this, 'afterMetadataCommit'], 10, 4);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_hook(
                'after_invoke:media regenerate',
                [$this, 'finishWpCliDeleteUnknownBatch']
            );
        }
    }

    public function beginGeneratedMetadataFilter($metadata, $attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if (
            $this->is_offloaded($attachment_id)
            && $this->isImageAttachment($attachment_id)
            && $this->isRegenerationRequest($attachment_id)
        ) {
            if (!array_key_exists($attachment_id, $this->originalMetadata)) {
                unset($this->reportedFailures[$attachment_id], $this->syncFailed[$attachment_id]);
            }
            $this->rememberOriginalMetadata($attachment_id);
            $this->captureRegenerationRetentionPolicy($attachment_id);
            $this->metadataGenerationInProgress[$attachment_id] = true;
        }

        return $metadata;
    }

    public function finishGeneratedMetadataFilter($metadata, $attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        unset($this->metadataGenerationInProgress[$attachment_id]);

        // WP-CLI treats an empty generated result as one attachment failure and
        // continues its batch. Sync the complete candidate here so a cloud error
        // can use that normal path instead of WP_CLI::error(), which aborts all
        // remaining attachments.
        if (
            is_array($metadata)
            && $this->isWpCliMediaRegenerationCommand()
            && array_key_exists($attachment_id, $this->originalMetadata)
        ) {
            $metadataToSync = $this->getWpCliMetadataCandidate($attachment_id, $metadata);
            $syncedMetadata = $this->run($metadataToSync, $attachment_id);
            if (!empty($this->syncFailed[$attachment_id])) {
                $this->clearRequestState($attachment_id, true);
                return [];
            }

            if (is_array($syncedMetadata) && !empty($this->stagedRegeneration[$attachment_id])) {
                $this->generatedMetadataSynced[$attachment_id] = $this->getMetadataIdentity($syncedMetadata);
                $metadata = $syncedMetadata;
            }
        }

        return $metadata;
    }

    /**
     * wp_update_image_subsizes() resolves the original before it knows whether
     * work is needed. If every size already exists, make that temporary cloud
     * download retryable and remove it without staging a fake regeneration.
     */
    public function finishMissingSubsizeCheck($missing_sizes, $image_meta, $attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if (
            !empty($missing_sizes)
            || empty($this->hydratedFiles[$attachment_id])
            || !$this->callStackContains('wp_update_image_subsizes')
        ) {
            return $missing_sizes;
        }

        if (
            $this->cloudAttachmentUploader->markHydratedRegenerationCleanupReady($attachment_id)
            && $this->cloudAttachmentUploader->cleanupHydratedRegenerationFiles($attachment_id)
        ) {
            $this->clearRequestState($attachment_id);
        }

        return $missing_sizes;
    }

    public function run($metadata, $attachment_id)
    {
        $attachment_id = (int) $attachment_id;

        // Image Edit and Restore Original have their own transactional flow.
        if ($this->isImageEditorOperation()) {
            return $metadata;
        }

        if (
            !$this->is_offloaded($attachment_id)
            || !$this->isImageAttachment($attachment_id)
            || !is_array($metadata)
        ) {
            return $metadata;
        }

        if (
            !empty($this->stagedRegeneration[$attachment_id])
            && isset($this->generatedMetadataSynced[$attachment_id])
            && hash_equals(
                $this->generatedMetadataSynced[$attachment_id],
                $this->getMetadataIdentity($metadata)
            )
        ) {
            return $metadata;
        }

        // WordPress saves metadata after every generated size. These are not a
        // complete regeneration result, so never delete or stage cleanup here.
        if (
            $this->isIntermediateSubsizeWrite()
            || !empty($this->metadataGenerationInProgress[$attachment_id])
        ) {
            $this->rememberOriginalMetadata($attachment_id);
            $this->rememberRegeneratedLocalFiles($attachment_id, $metadata);
            return $metadata;
        }

        $retention_policy = $this->captureRegenerationRetentionPolicy($attachment_id);

        $is_regeneration = array_key_exists($attachment_id, $this->originalMetadata)
            || $this->isRegenerationRequest($attachment_id);

        $old_metadata = $this->originalMetadata[$attachment_id] ?? get_post_meta(
            $attachment_id,
            '_wp_attachment_metadata',
            true
        );
        if (!is_array($old_metadata)) {
            $old_metadata = [];
        }

        // WordPress and format plugins can write metadata several times during
        // one regeneration. After the first safe-sync failure, keep returning
        // the original metadata without repeating uploads or error entries.
        if (!empty($this->reportedFailures[$attachment_id])) {
            return !empty($old_metadata) ? $old_metadata : $metadata;
        }

        // WP-CLI normally regenerates from wp_get_original_image_path(). For an
        // edited attachment we deliberately replace that source with the current
        // edited file below. Keep the true upload original in metadata so Restore
        // Original and later attachment deletion do not lose that object.
        if (
            $this->isEditedAttachment($attachment_id)
            && !empty($old_metadata['original_image'])
            && empty($metadata['original_image'])
        ) {
            $metadata['original_image'] = $old_metadata['original_image'];
        }

        // Keep support for tools that only update metadata and do not expose a
        // recognizable regeneration call stack, including delete/prune tools.
        if (!$is_regeneration && !$this->hasMetadataChanges($old_metadata, $metadata)) {
            return $metadata;
        }

        // Some third-party tools call the metadata filter without selecting the
        // source through get_attached_file() first. At this point generation has
        // already started, so an older cleanup must be rejected, not resumed.
        if ($is_regeneration && !$this->canSyncStartedRegeneration($attachment_id)) {
            $this->syncFailed[$attachment_id] = true;
            if (!empty($this->originalAttachedFiles[$attachment_id])) {
                update_attached_file($attachment_id, $this->originalAttachedFiles[$attachment_id]);
            }
            $this->restoreMetadataForCliFailure($attachment_id, $old_metadata);
            $this->reportRegenerationFailure($attachment_id);
            return !empty($old_metadata) ? $old_metadata : $metadata;
        }

        $this->rememberRegeneratedLocalFiles($attachment_id, $metadata);

        $delete_obsolete = (bool) apply_filters(
            'advmo_delete_obsolete_regenerated_cloud_files',
            !$this->shouldPreserveObsoleteCloudFiles(),
            $attachment_id,
            $metadata,
            $old_metadata
        );
        if (!$this->cloudAttachmentUploader->uploadRegeneratedThumbnails(
            $attachment_id,
            $metadata,
            $old_metadata,
            $delete_obsolete,
            !empty($this->stagedRegeneration[$attachment_id]),
            array_keys($this->regeneratedLocalFiles[$attachment_id] ?? []),
            $retention_policy
        )) {
            $this->syncFailed[$attachment_id] = true;
            if (!empty($this->stagedRegeneration[$attachment_id])) {
                $this->cloudAttachmentUploader->cancelRegenerationCleanup($attachment_id);
            }
            if (!empty($this->originalAttachedFiles[$attachment_id])) {
                update_attached_file($attachment_id, $this->originalAttachedFiles[$attachment_id]);
            }
            $this->restoreMetadataForCliFailure($attachment_id, $old_metadata);
            $this->reportRegenerationFailure($attachment_id);

            // Returning the previously committed metadata prevents WordPress
            // from pointing at objects that failed to upload. Generated local
            // files are intentionally kept for a safe retry.
            return !empty($old_metadata) ? $old_metadata : $metadata;
        }

        // The metadata filter runs before update_post_meta(). The post-meta hook
        // below completes this attachment immediately after its exact commit.
        // A single shutdown pass remains only as a crash-safe fallback.
        $this->stagedRegeneration[$attachment_id] = true;
        $this->registerShutdownFallback();

        return $metadata;
    }

    /**
     * Complete no-op metadata updates whose stored value already matches.
     */
    public function finishMetadataUpdateFilter($metadata, $attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if (!empty($this->syncFailed[$attachment_id])) {
            $this->clearRequestState($attachment_id, true);
            return $metadata;
        }

        $storedMetadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        if (
            !empty($this->stagedRegeneration[$attachment_id])
            && is_array($metadata)
            && $storedMetadata === $metadata
        ) {
            $this->finishAttachment($attachment_id);
        }

        return $metadata;
    }

    /**
     * Complete one attachment as soon as WordPress commits its metadata.
     */
    public function afterMetadataCommit($meta_id, $attachment_id, $meta_key, $metadata): void
    {
        $attachment_id = (int) $attachment_id;
        if (
            $meta_key !== '_wp_attachment_metadata'
            || empty($this->stagedRegeneration[$attachment_id])
            || !empty($this->restoringMetadata[$attachment_id])
        ) {
            return;
        }

        $this->finishAttachment($attachment_id);
    }

    /**
     * Restore a missing attached source for a known regeneration request.
     */
    public function hydrateAttachedFile($file, $attachment_id)
    {
        // wp_get_original_image_path() may point at original_image instead of
        // the attached scaled file. Let the later, exact-path filter hydrate it
        // so one regeneration does not download two full-size files needlessly.
        if ($this->callStackContains('wp_get_original_image_path')) {
            return $file;
        }

        return $this->hydrateRegenerationSource($file, (int) $attachment_id);
    }

    /**
     * Restore the exact original selected by wp_get_original_image_path().
     */
    public function hydrateOriginalImage($file, $attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        $is_source_lookup = $this->isRegenerationSourceLookup();

        // WP-CLI's media command selects wp_get_original_image_path() before
        // regeneration. After a WordPress image edit this points at the
        // pre-edit upload, which silently replaces the edited generation. Use
        // the currently attached edited file only for the active regeneration
        // source lookup. Other callers still receive the real original path.
        if (
            $is_source_lookup
            && $this->isEditedAttachment($attachment_id)
        ) {
            $edited_file = get_attached_file($attachment_id, true);
            if (is_string($edited_file) && $edited_file !== '') {
                $file = $edited_file;
            }
        }

        // Do not download a missing upload original merely because retention
        // cleanup asks for its path at shutdown. It is already absent locally.
        if (!$is_source_lookup && !$this->isImageEditorOperation()) {
            return $file;
        }

        return $this->hydrateRegenerationSource($file, $attachment_id);
    }

    private function hydrateRegenerationSource($file, int $attachment_id)
    {
        if (
            !is_string($file)
            || $file === ''
            || !empty($this->completedRegenerations[$attachment_id])
            || (
                !$this->isRegenerationRequest($attachment_id)
                && !$this->isImageEditorOperation()
            )
            || !$this->is_offloaded($attachment_id)
            || !$this->isImageAttachment($attachment_id)
        ) {
            return $file;
        }

        // Completing the older cleanup may call get_attached_file(). That lookup
        // belongs to cleanup itself and must not start another preflight or
        // hydrate a source that the same cleanup is about to remove.
        if (!empty($this->regenerationPreflightInProgress[$attachment_id])) {
            return $file;
        }

        if (!empty($this->cleanupInProgress[$attachment_id])) {
            return $file;
        }

        if (
            $this->isRegenerationRequest($attachment_id)
            && !$this->prepareForRegeneration($attachment_id)
        ) {
            $this->reportRegenerationFailure($attachment_id);
            return false;
        }

        $this->rememberOriginalMetadata($attachment_id);
        $retention_policy = $this->captureRegenerationRetentionPolicy($attachment_id);

        if (file_exists($file)) {
            return $file;
        }

        if (!$this->isPathInsideUploads($file) || !metadata_exists('post', $attachment_id, 'advmo_path')) {
            $this->recordHydrationError($attachment_id, 'The cloud regeneration source could not be mapped to a safe uploads path.');
            return $file;
        }

        $subdir = (string) get_post_meta($attachment_id, 'advmo_path', true);
        $cloud_key = $subdir . wp_basename($file);

        // Persist the intent before starting the download. A fatal error after
        // the atomic rename can then be recovered by the independent worker.
        if (!$this->cloudAttachmentUploader->rememberHydratedRegenerationFile(
            $attachment_id,
            $file,
            $retention_policy
        )) {
            $this->recordHydrationError(
                $attachment_id,
                'The temporary regeneration source could not be recorded for crash-safe cleanup.'
            );
            return $file;
        }

        if (!$this->cloudProvider->downloadFile($cloud_key, $file)) {
            $this->cloudAttachmentUploader->forgetHydratedRegenerationFile($attachment_id, $file);
            $this->recordHydrationError(
                $attachment_id,
                "Failed to download regeneration source '{$cloud_key}' from cloud storage."
            );
            return $file;
        }

        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($file) : false;
        if (!is_string($mime) || 0 !== strpos($mime, 'image/')) {
            wp_delete_file($file);
            $this->cloudAttachmentUploader->forgetHydratedRegenerationFile($attachment_id, $file);
            $this->recordHydrationError(
                $attachment_id,
                "Downloaded regeneration source '{$cloud_key}' is not a valid image."
            );
            return $file;
        }

        $this->hydratedFiles[$attachment_id][wp_normalize_path($file)] = true;
        $this->registerShutdownFallback();

        return $file;
    }

    /**
     * Resolve cleanup from an older operation before WordPress writes any new
     * thumbnail. A failed preflight remains failed for this request.
     */
    private function prepareForRegeneration(int $attachment_id): bool
    {
        if (array_key_exists($attachment_id, $this->regenerationPreflight)) {
            return $this->regenerationPreflight[$attachment_id];
        }

        if (!array_key_exists($attachment_id, $this->originalMetadata)) {
            unset($this->reportedFailures[$attachment_id], $this->syncFailed[$attachment_id]);
        }

        $this->regenerationPreflightInProgress[$attachment_id] = true;
        try {
            $passed = $this->cloudAttachmentUploader->prepareForThumbnailRegeneration($attachment_id);
        } catch (\Throwable $e) {
            error_log(sprintf(
                'ADVMO: Thumbnail regeneration preflight failed for attachment %d: %s',
                $attachment_id,
                $e->getMessage()
            ));
            $passed = false;
        } finally {
            unset($this->regenerationPreflightInProgress[$attachment_id]);
        }

        $this->regenerationPreflight[$attachment_id] = $passed;

        return $this->regenerationPreflight[$attachment_id];
    }

    /**
     * Once files may have been generated, never resume an unprepared cleanup.
     */
    private function canSyncStartedRegeneration(int $attachment_id): bool
    {
        if (array_key_exists($attachment_id, $this->regenerationPreflight)) {
            return $this->regenerationPreflight[$attachment_id];
        }

        if (metadata_exists(
            'post',
            $attachment_id,
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP
        )) {
            $this->regenerationPreflight[$attachment_id] = false;
            return false;
        }

        // No cleanup existed when this late-entry regeneration was first seen.
        // Cache the safe state so get_attached_file() calls made by the upload
        // phase cannot turn into a late cleanup preflight.
        $this->regenerationPreflight[$attachment_id] = true;
        return true;
    }

    private function registerShutdownFallback(): void
    {
        if ($this->shutdownFallbackRegistered) {
            return;
        }

        $this->shutdownFallbackRegistered = true;
        register_shutdown_function([$this, 'finishPendingAttachments']);
    }

    /**
     * Retry only attachments that did not finish at their own metadata commit.
     */
    public function finishPendingAttachments(): void
    {
        $attachmentIds = array_unique(array_merge(
            array_keys($this->stagedRegeneration),
            array_keys($this->hydratedFiles)
        ));

        foreach ($attachmentIds as $attachmentId) {
            $this->finishAttachment((int) $attachmentId);
        }
    }

    private function finishAttachment(int $attachment_id): bool
    {
        if (!empty($this->restoringMetadata[$attachment_id])) {
            return false;
        }

        if (!empty($this->syncFailed[$attachment_id])) {
            // A failed regeneration intentionally keeps every generated and
            // hydrated file. Only a later successful retry may clean them.
            $this->clearRequestState($attachment_id, true);
            return false;
        }

        $this->cleanupInProgress[$attachment_id] = true;
        try {
            $pending = get_post_meta(
                $attachment_id,
                CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                true
            );

            if (is_array($pending) && ($pending['context'] ?? '') === 'thumbnail_regeneration') {
                $this->cloudAttachmentUploader->resumePendingLocalCleanup($attachment_id);
                $pending = get_post_meta(
                    $attachment_id,
                    CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
                    true
                );
                if (is_array($pending) && ($pending['context'] ?? '') === 'thumbnail_regeneration') {
                    return false;
                }
            }

        } finally {
            unset($this->cleanupInProgress[$attachment_id]);
        }

        $this->completedRegenerations[$attachment_id] = true;
        $this->clearRequestState($attachment_id);
        return true;
    }

    private function clearRequestState(int $attachment_id, bool $preserve_failure = false): void
    {
        unset(
            $this->originalMetadata[$attachment_id],
            $this->originalAttachedFiles[$attachment_id],
            $this->hydratedFiles[$attachment_id],
            $this->stagedRegeneration[$attachment_id],
            $this->metadataGenerationInProgress[$attachment_id],
            $this->regenerationPreflight[$attachment_id],
            $this->regenerationPreflightInProgress[$attachment_id],
            $this->regeneratedLocalFiles[$attachment_id],
            $this->generatedMetadataSynced[$attachment_id],
            $this->cleanupInProgress[$attachment_id],
            $this->regenerationRetentionPolicies[$attachment_id],
            $this->syncFailed[$attachment_id]
        );

        // A later metadata write from the same failed operation must not retry
        // uploads or add another error. A new source lookup clears this marker.
        if (!$preserve_failure) {
            unset($this->reportedFailures[$attachment_id]);
        }
    }

    private function rememberOriginalMetadata(int $attachment_id): void
    {
        if (array_key_exists($attachment_id, $this->originalMetadata)) {
            return;
        }

        $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        $this->originalMetadata[$attachment_id] = is_array($metadata) ? $metadata : [];

        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $this->originalAttachedFiles[$attachment_id] = is_string($attached_file) ? $attached_file : '';
    }

    private function captureRegenerationRetentionPolicy(int $attachment_id): int
    {
        if (!array_key_exists($attachment_id, $this->regenerationRetentionPolicies)) {
            $this->regenerationRetentionPolicies[$attachment_id] = (int) apply_filters(
                'advmo_local_deletion_rule',
                $this->shouldDeleteLocal(),
                $attachment_id
            );
        }

        return $this->regenerationRetentionPolicies[$attachment_id];
    }

    /**
     * Remember every generated size that exists while WordPress is processing
     * this attachment. Temporary hydration sources are excluded, while new
     * main/original/root source files produced by format plugins are strict.
     */
    private function rememberRegeneratedLocalFiles(int $attachment_id, array $metadata): void
    {
        $attached_file = get_attached_file($attachment_id, true);
        if (!is_string($attached_file) || $attached_file === '') {
            return;
        }

        $directory = trailingslashit(dirname($attached_file));
        $files = [];

        foreach (($metadata['sizes'] ?? []) as $size_data) {
            if (!is_array($size_data)) {
                continue;
            }

            if (!empty($size_data['file']) && is_string($size_data['file'])) {
                $files[] = $directory . wp_basename($size_data['file']);
            }

            foreach (($size_data['sources'] ?? []) as $source_data) {
                if (is_array($source_data) && !empty($source_data['file']) && is_string($source_data['file'])) {
                    $files[] = $directory . wp_basename($source_data['file']);
                }
            }
        }

        foreach ($this->getRootSourceFiles($metadata) as $source_file) {
            if (is_string($source_file) && $source_file !== '') {
                $files[] = $directory . wp_basename($source_file);
            }
        }

        $oldMetadata = $this->originalMetadata[$attachment_id] ?? [];
        $newMain = isset($metadata['file']) ? wp_basename((string) $metadata['file']) : '';
        $oldMain = isset($oldMetadata['file']) ? wp_basename((string) $oldMetadata['file']) : '';
        if ($newMain !== '' && $newMain !== $oldMain) {
            $files[] = $directory . $newMain;
        }

        $newOriginal = isset($metadata['original_image'])
            ? wp_basename((string) $metadata['original_image'])
            : '';
        $oldOriginal = isset($oldMetadata['original_image'])
            ? wp_basename((string) $oldMetadata['original_image'])
            : '';
        if ($newOriginal !== '' && $newOriginal !== $oldOriginal) {
            $files[] = $directory . $newOriginal;
        }

        foreach ($files as $file) {
            $normalized = wp_normalize_path($file);
            if (
                is_file($file)
                && empty($this->hydratedFiles[$attachment_id][$normalized])
            ) {
                $this->regeneratedLocalFiles[$attachment_id][$normalized] = true;
            }
        }
    }

    private function isIntermediateSubsizeWrite(): bool
    {
        // wp_create_image_subsizes() first commits a metadata array with no
        // sizes, then _wp_make_subsizes() commits again after every generated
        // size. Only the outer caller has the complete regeneration result.
        return $this->callStackContains('wp_create_image_subsizes')
            || $this->callStackContains('_wp_make_subsizes');
    }

    /**
     * Detect core, WP-CLI, and common plugin regeneration entry points.
     */
    private function isRegenerationRequest(int $attachment_id): bool
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
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 18);
            foreach ($trace as $frame) {
                $function = strtolower((string) ($frame['function'] ?? ''));
                $class = strtolower((string) ($frame['class'] ?? ''));
                $call = $this->getShortClassName($class) . '::' . $function;

                // The observer's own class name contains "thumbnail" and
                // "regeneration" and must not make every file lookup look like
                // a regeneration request.
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

        /**
         * Filter whether the current request is regenerating this attachment.
         * Third-party tools with custom call stacks can opt in here.
         */
        return (bool) apply_filters(
            'advmo_is_thumbnail_regeneration_request',
            $detected,
            $attachment_id
        );
    }

    private function callStackContains(string $function): bool
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 14);
        foreach ($trace as $frame) {
            if (($frame['function'] ?? '') === $function) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether WordPress is choosing the source for a regeneration operation.
     *
     * This intentionally ignores the WP-CLI argv-only signal. The command-line
     * arguments remain present during shutdown cleanup, where original-image
     * path lookups must keep their normal meaning.
     */
    private function isRegenerationSourceLookup(): bool
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        foreach ($trace as $frame) {
            $function = strtolower((string) ($frame['function'] ?? ''));
            $class = strtolower((string) ($frame['class'] ?? ''));
            $call = $this->getShortClassName($class) . '::' . $function;

            if ($class === strtolower(self::class)) {
                continue;
            }

            if (in_array($function, [
                'process_regeneration',
                'delete_unknown_image_sizes',
                'wp_update_image_subsizes',
            ], true)) {
                return true;
            }

            if (
                false !== strpos($call, 'regenerat')
                && (false !== strpos($call, 'thumbnail') || false !== strpos($call, 'media'))
            ) {
                return true;
            }
        }

        return false;
    }

    private function getShortClassName(string $class): string
    {
        $separator = strrpos($class, '\\');
        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * WordPress records the pre-edit full image in backup sizes. A different
     * currently attached basename means Media Library is serving an edit.
     */
    private function isEditedAttachment(int $attachment_id): bool
    {
        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        if (
            !is_array($backup_sizes)
            || empty($backup_sizes['full-orig']['file'])
            || !is_string($backup_sizes['full-orig']['file'])
        ) {
            return false;
        }

        $attached_file = get_attached_file($attachment_id, true);
        return is_string($attached_file)
            && $attached_file !== ''
            && wp_basename($attached_file) !== wp_basename($backup_sizes['full-orig']['file']);
    }

    /**
     * Avoid wp_attachment_is_image() here because it calls get_attached_file(),
     * which would re-enter this observer while a cloud source is being restored.
     */
    private function isImageAttachment(int $attachment_id): bool
    {
        $post = get_post($attachment_id);
        if (!($post instanceof \WP_Post) || $post->post_type !== 'attachment') {
            return false;
        }

        // SVG and other non-raster image MIME types have no WordPress thumbnail
        // source. Do not hydrate them only to reject and delete them later.
        $attached = get_post_meta($attachment_id, '_wp_attached_file', true);
        $extension = is_string($attached)
            ? strtolower((string) pathinfo($attached, PATHINFO_EXTENSION))
            : '';
        return in_array(
            $extension,
            ['jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tif', 'tiff', 'ico', 'webp', 'avif', 'avifs', 'heic', 'heics', 'heif', 'heifs'],
            true
        );
    }

    private function isPathInsideUploads(string $path): bool
    {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return false;
        }

        $base = trailingslashit(wp_normalize_path($uploads['basedir']));
        return 0 === strpos(wp_normalize_path($path), $base);
    }

    /**
     * WP-CLI asks to preserve old thumbnails for this mode. Apply the
     * same choice to cloud objects, not only local files.
     */
    private function shouldPreserveObsoleteCloudFiles(): bool
    {
        if (!(defined('WP_CLI') && WP_CLI) || empty($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
            return false;
        }

        // WP-CLI gives --delete-unknown precedence over the other flags.
        if ($this->getCliBooleanFlag('delete-unknown')) {
            return false;
        }

        return $this->getCliBooleanFlag('only-missing')
            || $this->getCliBooleanFlag('skip-delete');
    }

    private function isWpCliMediaRegenerationCommand(): bool
    {
        if (!(defined('WP_CLI') && WP_CLI) || empty($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
            return false;
        }

        $arguments = array_map('strtolower', array_map('strval', $_SERVER['argv']));
        $mediaIndex = array_search('media', $arguments, true);
        return $mediaIndex !== false
            && isset($arguments[$mediaIndex + 1])
            && $arguments[$mediaIndex + 1] === 'regenerate';
    }

    private function getWpCliMetadataCandidate(int $attachment_id, array $generatedMetadata): array
    {
        $imageSize = $this->getCliOptionValue(['image_size', 'image-size']);
        if ($imageSize === '') {
            return $generatedMetadata;
        }

        $metadata = $this->originalMetadata[$attachment_id] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }
        if (!isset($metadata['sizes']) || !is_array($metadata['sizes'])) {
            $metadata['sizes'] = [];
        }

        if (!empty($generatedMetadata['sizes'][$imageSize])) {
            $metadata['sizes'][$imageSize] = $generatedMetadata['sizes'][$imageSize];
        } else {
            unset($metadata['sizes'][$imageSize]);
        }

        return $metadata;
    }

    /** @param string[] $names */
    private function getCliOptionValue(array $names): string
    {
        if (empty($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
            return '';
        }

        foreach ($_SERVER['argv'] as $index => $argument) {
            $argument = (string) $argument;
            foreach ($names as $name) {
                $prefix = '--' . $name;
                if (0 === strpos($argument, $prefix . '=')) {
                    return trim(substr($argument, strlen($prefix) + 1));
                }
                if ($argument === $prefix && isset($_SERVER['argv'][$index + 1])) {
                    return trim((string) $_SERVER['argv'][$index + 1]);
                }
            }
        }

        return '';
    }

    private function getMetadataIdentity(array $metadata): string
    {
        return hash('sha256', maybe_serialize($metadata));
    }

    private function getCliBooleanFlag(string $name): bool
    {
        $prefix = '--' . $name;
        foreach ($_SERVER['argv'] as $argument) {
            $argument = strtolower((string) $argument);
            if ($argument === $prefix) {
                return true;
            }
            if (0 === strpos($argument, $prefix . '=')) {
                $value = substr($argument, strlen($prefix) + 1);
                return !in_array($value, ['0', 'false', 'no', 'off'], true);
            }
        }

        return false;
    }

    /**
     * Compare generated sizes in both directions, including removed sizes.
     */
    private function hasMetadataChanges(array $old_metadata, array $new_metadata): bool
    {
        $old_relevant = [
            'sizes' => $old_metadata['sizes'] ?? [],
            'sources' => $old_metadata['sources'] ?? [],
        ];
        $new_relevant = [
            'sizes' => $new_metadata['sizes'] ?? [],
            'sources' => $new_metadata['sources'] ?? [],
        ];

        return $this->normalizeMetadata($old_relevant) !== $this->normalizeMetadata($new_relevant);
    }

    private function normalizeMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if ($key === 'filesize') {
                unset($metadata[$key]);
                continue;
            }

            if (is_array($value)) {
                $metadata[$key] = $this->normalizeMetadata($value);
            }
        }

        ksort($metadata);
        return $metadata;
    }

    private function reportRegenerationFailure(int $attachment_id): void
    {
        if (!empty($this->reportedFailures[$attachment_id])) {
            return;
        }
        $this->reportedFailures[$attachment_id] = true;
        while (count($this->reportedFailures) > self::MAX_REPORTED_FAILURES) {
            $oldestAttachmentId = array_key_first($this->reportedFailures);
            if ($oldestAttachmentId === null) {
                break;
            }
            unset($this->reportedFailures[$oldestAttachmentId]);
        }

        $message = sprintf(
            'Advanced Media Offloader could not safely sync regenerated files for attachment %d. WordPress metadata was kept unchanged and local files were preserved.',
            $attachment_id
        );
        error_log($message);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            if ($this->isWpCliDeleteUnknownCommand()) {
                // WP-CLI's delete-unknown path ignores the metadata update result
                // and increments its success count. Record this attachment now,
                // continue the batch, and fail from the after-invoke hook.
                $this->wpCliDeleteUnknownFailures[$attachment_id] = true;
                \WP_CLI::warning($message);
                return;
            }

            \WP_CLI::warning($message);
        }
    }

    /**
     * Return a non-zero status after every delete-unknown attachment ran.
     *
     * WP-CLI passes the invoked command name through this hook, so return it
     * unchanged when there was no Advanced Media Offloader failure.
     */
    public function finishWpCliDeleteUnknownBatch($command_name)
    {
        if (
            !$this->isWpCliDeleteUnknownCommand()
            || empty($this->wpCliDeleteUnknownFailures)
        ) {
            return $command_name;
        }

        $attachment_ids = array_map('intval', array_keys($this->wpCliDeleteUnknownFailures));
        sort($attachment_ids, SORT_NUMERIC);
        $count = count($attachment_ids);

        \WP_CLI::error(sprintf(
            'Advanced Media Offloader failed cloud synchronization for %d %s during --delete-unknown (IDs: %s). The remaining attachments were processed.',
            $count,
            $count === 1 ? 'attachment' : 'attachments',
            implode(', ', $attachment_ids)
        ));

        return $command_name;
    }

    private function isWpCliDeleteUnknownCommand(): bool
    {
        return $this->isWpCliMediaRegenerationCommand()
            && $this->getCliBooleanFlag('delete-unknown');
    }

    /**
     * Restore metadata before WP-CLI receives an empty generated result. Its
     * media command then counts this attachment as failed and continues.
     */
    private function restoreMetadataForCliFailure(int $attachment_id, array $old_metadata): void
    {
        if (!(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))) {
            return;
        }

        $this->restoringMetadata[$attachment_id] = true;
        try {
            if (!empty($old_metadata)) {
                update_post_meta($attachment_id, '_wp_attachment_metadata', $old_metadata);
            }
        } finally {
            unset($this->restoringMetadata[$attachment_id]);
        }
    }

    private function recordHydrationError(int $attachment_id, string $message): void
    {
        $this->appendAttachmentError($attachment_id, $message);
        error_log("ADVMO: {$message}");
    }
}
