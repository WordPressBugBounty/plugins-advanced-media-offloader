<?php

namespace Advanced_Media_Offloader\Services;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class CloudAttachmentUploader
{
    use OffloaderTrait;

    public const META_PENDING_LOCAL_CLEANUP = '_advmo_local_cleanup_pending';

    public const META_REGENERATION_HYDRATED_FILES = '_advmo_regeneration_hydrated_files';

    /** Keep deferred optimizer work retryable for one day, then fail safely. */
    private const DEFERRED_CLEANUP_MAX_AGE = 86400;

    private const HYDRATED_CLEANUP_ERROR =
        'Temporary cloud downloads could not be removed. Cleanup will be retried.';

    private const REGENERATION_CLOUD_CLEANUP_ERROR =
        'Failed to delete obsolete thumbnail objects from cloud storage. Cleanup will be retried.';

    private S3_Provider $cloudProvider;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    /**
     * Persist a cloud-only source restored temporarily for regeneration.
     * Relative uploads paths keep the retry record portable and path-safe.
     */
    public function rememberHydratedRegenerationFile(
        int $attachment_id,
        string $file,
        int $retention_policy
    ): bool
    {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return false;
        }

        $base = trailingslashit(wp_normalize_path($uploads['basedir']));
        $file = wp_normalize_path($file);
        if (0 !== strpos($file, $base)) {
            return false;
        }

        $relative = ltrim(substr($file, strlen($base)), '/');
        if (!$this->isSafeUploadsRelativePath($relative)) {
            return false;
        }

        $state = get_post_meta($attachment_id, self::META_REGENERATION_HYDRATED_FILES, true);
        if (!is_array($state)) {
            $state = [];
        }

        $files = $this->getHydratedRegenerationFileRules($state);
        $files[$relative] = $retention_policy;
        $state = [
            // Store the effective rule per file. This makes the record safe when
            // the site changes retention policy between regeneration attempts.
            'files' => $files,
            'created_at' => isset($state['created_at']) ? (int) $state['created_at'] : time(),
        ];

        return $this->persistAttachmentMeta(
            $attachment_id,
            self::META_REGENERATION_HYDRATED_FILES,
            $state
        );
    }

    /**
     * Mark a no-op missing-size check as safe for the retry worker to clean.
     * A later hydration replaces the state and therefore clears this marker.
     */
    public function markHydratedRegenerationCleanupReady(int $attachment_id): bool
    {
        $state = get_post_meta($attachment_id, self::META_REGENERATION_HYDRATED_FILES, true);
        if (!is_array($state) || empty($state)) {
            return true;
        }

        if (!empty($state['cleanup_ready'])) {
            return true;
        }

        $updated = $state;
        $updated['cleanup_ready'] = 1;
        update_post_meta(
            $attachment_id,
            self::META_REGENERATION_HYDRATED_FILES,
            $updated,
            $state
        );

        return get_post_meta(
            $attachment_id,
            self::META_REGENERATION_HYDRATED_FILES,
            true
        ) === $updated;
    }

    public function isHydratedRegenerationCleanupReady(int $attachment_id): bool
    {
        $state = get_post_meta($attachment_id, self::META_REGENERATION_HYDRATED_FILES, true);
        return is_array($state) && !empty($state['cleanup_ready']);
    }

    /**
     * Stop tracking one source when its download or validation did not finish.
     */
    public function forgetHydratedRegenerationFile(int $attachment_id, string $file): bool
    {
        $relative = $this->getUploadsRelativePath($file);
        if ($relative === null) {
            return false;
        }

        $state = get_post_meta($attachment_id, self::META_REGENERATION_HYDRATED_FILES, true);
        if (!is_array($state) || empty($state)) {
            return true;
        }

        $files = $this->getHydratedRegenerationFileRules($state);
        unset($files[$relative]);

        if (empty($files)) {
            delete_post_meta($attachment_id, self::META_REGENERATION_HYDRATED_FILES, $state);
            return !metadata_exists('post', $attachment_id, self::META_REGENERATION_HYDRATED_FILES);
        }

        $state['files'] = $files;
        unset($state['retention_policy']);
        return $this->persistAttachmentMeta(
            $attachment_id,
            self::META_REGENERATION_HYDRATED_FILES,
            $state
        );
    }

    /**
     * Apply the retention rule captured before each source was downloaded.
     * If the attachment is no longer offloaded, keep every local file and only
     * remove the stale bookkeeping record.
     */
    public function cleanupHydratedRegenerationFiles(int $attachment_id): bool
    {
        $state = get_post_meta($attachment_id, self::META_REGENERATION_HYDRATED_FILES, true);
        if (!is_array($state) || empty($state)) {
            // A previous request may have deleted the retry record immediately
            // before it stopped. A later pending-cleanup retry can still clear
            // the now-resolved transient error.
            $this->removeAttachmentError($attachment_id, self::HYDRATED_CLEANUP_ERROR);
            return true;
        }

        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return false;
        }

        $base = trailingslashit(wp_normalize_path($uploads['basedir']));
        $files = $this->getHydratedRegenerationFileRules($state);
        $may_delete = $this->is_offloaded($attachment_id);
        $complete = true;
        foreach ($files as $relative => $retention_policy) {
            if (!is_string($relative) || !$this->isSafeUploadsRelativePath($relative)) {
                $complete = false;
                continue;
            }

            $file = $base . $relative;
            if ($may_delete && $retention_policy === 2 && is_file($file)) {
                wp_delete_file($file);
            }
            if ($may_delete && $retention_policy === 2 && is_file($file)) {
                $complete = false;
            }
        }

        if (!$complete) {
            return false;
        }

        delete_post_meta($attachment_id, self::META_REGENERATION_HYDRATED_FILES, $state);
        $completed = !metadata_exists('post', $attachment_id, self::META_REGENERATION_HYDRATED_FILES);
        if ($completed) {
            $this->removeAttachmentError($attachment_id, self::HYDRATED_CLEANUP_ERROR);
        }

        return $completed;
    }

    /**
     * Read both the current per-file schema and the legacy policy-2 list.
     *
     * @return array<string,int>
     */
    private function getHydratedRegenerationFileRules(array $state): array
    {
        $rules = [];
        $legacy_policy = isset($state['retention_policy']) ? (int) $state['retention_policy'] : 2;

        foreach (($state['files'] ?? []) as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $rules[$value] = $legacy_policy;
                continue;
            }

            if (is_string($key) && (is_int($value) || is_numeric($value))) {
                $rules[$key] = (int) $value;
            }
        }

        return $rules;
    }

    private function getUploadsRelativePath(string $file): ?string
    {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return null;
        }

        $base = trailingslashit(wp_normalize_path($uploads['basedir']));
        $file = wp_normalize_path($file);
        if (0 !== strpos($file, $base)) {
            return null;
        }

        $relative = ltrim(substr($file, strlen($base)), '/');
        return $this->isSafeUploadsRelativePath($relative) ? $relative : null;
    }

    private function isSafeUploadsRelativePath(string $path): bool
    {
        return $path !== ''
            && false === strpos($path, "\0")
            && 0 !== strpos($path, '/')
            && !preg_match('#(^|/)\.\.(/|$)#', $path);
    }

    public function uploadAttachment(int $attachment_id): bool
    {
        // Resuming cleanup belongs to an already-committed offload and must not
        // be blocked by a filter that only decides whether a new offload starts.
        if ($this->is_offloaded($attachment_id)) {
            $pendingCleanup = get_post_meta(
                $attachment_id,
                self::META_PENDING_LOCAL_CLEANUP,
                true
            );

            // wp_generate_attachment_metadata also runs during regeneration.
            // Its normal upload observer executes before the outer caller saves
            // final metadata. A regeneration-owned marker must therefore wait
            // for the regeneration commit verifier instead of deleting its new
            // files here.
            if (
                is_array($pendingCleanup)
                && ($pendingCleanup['context'] ?? '') === 'thumbnail_regeneration'
            ) {
                return true;
            }

            $this->resumePendingLocalCleanup($attachment_id);
            return true;
        }

        /**
         * Filter to determine whether an attachment should be offloaded.
         *
         * Return false to skip offloading this attachment. Useful for
         * conditional rules (file type, size, user role, taxonomy, etc.).
         *
         * @param bool $should_offload Default true.
         * @param int  $attachment_id  Attachment ID.
         */
        $should_offload = apply_filters('advmo_should_offload_attachment', true, $attachment_id);
        if (!$should_offload) {
            return false;
        }

        // uploadToCloud() returns the exact subdir it uploaded to (or false on
        // failure). That subdir is forwarded to updateAttachmentMetadata() so
        // advmo_path is recorded from the same value used for the upload,
        // instead of being re-derived (which can diverge for object versioning,
        // whose value is time-based). An empty-string subdir is a valid success
        // value, so the guard must be a strict !== false comparison.
        $subdir = $this->uploadToCloud($attachment_id);
        if ($subdir === false) {
            return false;
        }

        $requestedDeleteLocalRule = $this->shouldDeleteLocal();

        /**
         * Filter the local file deletion rule before applying it.
         *
         * Rule 0 always means keep local files. Optimizer integrations request
         * temporary deferral through advmo_local_cleanup_deferred_by below.
         *
         * @param int $deleteLocalRule The deletion rule: 0 = keep, 1 = smart cleanup, 2 = full migration.
         * @param int $attachment_id   The attachment ID.
         */
        $deleteLocalRule = (int) apply_filters(
            'advmo_local_deletion_rule',
            $requestedDeleteLocalRule,
            $attachment_id
        );

        $deferredBy = $this->getDeferredCleanupOwner($deleteLocalRule, $attachment_id);

        $pendingCleanup = $this->buildPendingCleanupState(
            $deleteLocalRule,
            $deferredBy
        );

        // Persist the cloud location and the resumable cleanup state before any
        // local file can be deleted. advmo_offloaded is written last inside this
        // method and acts as the commit marker for the offload operation.
        if (!$this->updateAttachmentMetadata($attachment_id, $subdir, $pendingCleanup)) {
            return false;
        }

        $this->runPostCommitCleanup($attachment_id, $pendingCleanup);

        return true;
    }

    public function uploadUpdatedAttachment(
        int $attachment_id,
        array $metadata,
        string $operation = 'image_edit'
    ): bool
    {
        // Image edits and restores are re-offload paths. If the initial offload
        // never committed, WordPress owns the files and no cloud work is needed.
        if (!$this->is_offloaded($attachment_id)) {
            return true;
        }

        if (empty($metadata)) {
            $this->logError($attachment_id, 'Image operation returned empty attachment metadata.', false);
            return false;
        }

        if (!metadata_exists('post', $attachment_id, 'advmo_path')) {
            $this->logError($attachment_id, 'The committed cloud path is missing for this image operation.', false);
            return false;
        }

        $subdir = (string) get_post_meta($attachment_id, 'advmo_path', true);
        $main_file = (string) get_attached_file($attachment_id, true);
        if ($main_file === '') {
            $this->logError($attachment_id, 'Unable to determine the image path for the image operation.', false);
            return false;
        }

        $file_dir = trailingslashit(dirname($main_file));
        $deleteLocalRule = (int) apply_filters(
            'advmo_local_deletion_rule',
            $this->shouldDeleteLocal(),
            $attachment_id
        );
        $files = [
            $main_file => $subdir . wp_basename($main_file),
        ];

        // Collect every file referenced by the metadata being committed. A
        // missing local file is acceptable only when its cloud object already
        // exists (for example, an untouched size under Full Cloud Migration).
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $data) {
                if (!is_array($data)) {
                    continue;
                }
                foreach ($this->getFilesFromSizeData($data) as $size_file) {
                    $files[$file_dir . $size_file] = $subdir . $size_file;
                }
            }
        }

        if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
            $files[$file_dir . $metadata['original_image']] = $subdir . $metadata['original_image'];
        }

        foreach ($this->getRootSourceFiles($metadata) as $source_file) {
            $files[$file_dir . $source_file] = $subdir . $source_file;
        }

        foreach ($files as $local_path => $cloud_key) {
            if (!$this->uploadOrVerifyFile($attachment_id, $local_path, $cloud_key, $operation)) {
                return false;
            }
        }

        /**
         * Upload optimizer-generated sidecars for the metadata being committed.
         * Returning false prevents local cleanup and rolls the WordPress image
         * operation back to its previous metadata.
         *
         * @param bool   $uploaded      Status from earlier integrations.
         * @param int    $attachment_id Attachment ID.
         * @param string $subdir        Cloud key prefix.
         * @param array  $metadata      New image metadata.
         * @param string $operation     image_edit or image_restore.
         */
        $sidecarsUploaded = (bool) apply_filters(
            'advmo_upload_image_operation_sidecars',
            true,
            $attachment_id,
            $subdir,
            $metadata,
            $operation
        );
        if (!$sidecarsUploaded) {
            $this->logError($attachment_id, 'Failed to upload all optimizer sidecar files for the image operation.', false);
            return false;
        }

        $deferredBy = $this->getDeferredCleanupOwner(
            $deleteLocalRule,
            $attachment_id,
            $operation
        );
        $pendingCleanup = $this->buildPendingCleanupState(
            $deleteLocalRule,
            $deferredBy,
            $operation,
            $metadata
        );

        if (!$this->persistAttachmentMeta(
            $attachment_id,
            self::META_PENDING_LOCAL_CLEANUP,
            $pendingCleanup
        )) {
            $this->logError($attachment_id, 'Failed to save image-operation cleanup state. Local files were kept.', false);
            return false;
        }

        return true;
    }

    /**
     * Upload a local file, or verify that its already-offloaded cloud object
     * still exists when the local copy is absent.
     */
    private function uploadOrVerifyFile(
        int $attachment_id,
        string $localPath,
        string $cloudKey,
        string $operation,
        bool $requireLocalUpload = false
    ): bool
    {
        try {
            if (file_exists($localPath)) {
                if ($this->cloudProvider->uploadFile($localPath, $cloudKey)) {
                    return true;
                }

                $this->logError(
                    $attachment_id,
                    "Failed to upload '{$localPath}' during {$operation}."
                );
                return false;
            }

            // A file observed during the current generation must be uploaded
            // from that exact local result. An older cloud object with the same
            // key is not proof that the new bytes reached cloud storage.
            if ($requireLocalUpload) {
                $this->logError(
                    $attachment_id,
                    "Newly generated file '{$localPath}' disappeared before it could be uploaded during "
                        . str_replace('_', ' ', $operation)
                        . '.',
                    false
                );
                return false;
            }

            if ($this->cloudProvider->objectExists($cloudKey)) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                'ADVMO: Cloud check failed during %s for attachment %d and key "%s": %s',
                $operation,
                $attachment_id,
                $cloudKey,
                $e->getMessage()
            ));

            $this->logError(
                $attachment_id,
                "Cloud storage could not verify required {$operation} object '{$cloudKey}'. Local files were kept.",
                false
            );
            return false;
        }

        $this->logError(
            $attachment_id,
            "Required {$operation} file '{$localPath}' is missing locally or could not be verified in cloud storage.",
            false
        );
        return false;
    }

    /**
     * Finish cleanup from an older operation before regeneration can create
     * files that use the same local names.
     */
    public function prepareForThumbnailRegeneration(int $attachment_id): bool
    {
        if (!metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP)) {
            return true;
        }

        $this->resumePendingLocalCleanup($attachment_id);

        if (!metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP)) {
            return true;
        }

        $this->logError(
            $attachment_id,
            'Thumbnail regeneration was stopped because an earlier local cleanup is still pending. Regeneration did not start, so its files were not changed.',
            false
        );
        return false;
    }

    /**
     * Upload and stage cleanup for one complete regeneration result.
     *
     * Every current file is uploaded when it exists locally. Files absent under
     * Full Cloud Migration must already exist in cloud. No local or obsolete
     * cloud file is deleted until WordPress commits the exact new metadata.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $new_metadata  Complete metadata after regeneration.
     * @param array $old_metadata  Metadata from before regeneration started.
     * @param bool  $delete_obsolete Whether removed files may be deleted from cloud.
     * @param bool  $replace_regeneration_pending Whether this request already staged an earlier partial result.
     * @param string[] $required_local_files Files observed during this regeneration that must be uploaded locally.
     * @param int|null $retention_policy Effective rule captured when this regeneration started.
     */
    public function uploadRegeneratedThumbnails(
        int $attachment_id,
        array $new_metadata,
        array $old_metadata,
        bool $delete_obsolete = true,
        bool $replace_regeneration_pending = false,
        array $required_local_files = [],
        ?int $retention_policy = null
    ): bool {
        if (!$this->is_offloaded($attachment_id)) {
            return true;
        }

        $previous_errors = $this->getRegenerationErrors($attachment_id);

        if (empty($new_metadata)) {
            $this->logError($attachment_id, 'Thumbnail regeneration returned empty attachment metadata.', false);
            return false;
        }

        // Old cleanup must be resolved before generation begins. Never resume it
        // here because WordPress may already have created new files under the
        // same names that the old cleanup would delete.
        $stored_pending = null;
        $may_replace = false;
        if (metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP)) {
            $stored_pending = get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true);
            $may_replace = $replace_regeneration_pending
                && is_array($stored_pending)
                && ($stored_pending['context'] ?? '') === 'thumbnail_regeneration';

            if (!$may_replace && metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP)) {
                $this->logError(
                    $attachment_id,
                    'Thumbnail regeneration was not synced because an earlier cleanup appeared after regeneration started. Regenerated files were kept locally.',
                    false
                );
                return false;
            }
        }

        if (!metadata_exists('post', $attachment_id, 'advmo_path')) {
            $this->logError($attachment_id, 'The committed cloud path is missing for thumbnail regeneration.', false);
            return false;
        }

        $subdir = (string) get_post_meta($attachment_id, 'advmo_path', true);
        $main_file = (string) get_attached_file($attachment_id, true);
        if ($main_file === '') {
            $this->logError($attachment_id, 'Unable to determine the image path for thumbnail regeneration.', false);
            return false;
        }

        $file_dir = trailingslashit(dirname($main_file));
        $delete_local_rule = $retention_policy ?? (int) apply_filters(
            'advmo_local_deletion_rule',
            $this->shouldDeleteLocal(),
            $attachment_id
        );
        $files = [
            $main_file => $subdir . wp_basename($main_file),
        ];

        if (!empty($new_metadata['sizes']) && is_array($new_metadata['sizes'])) {
            foreach ($new_metadata['sizes'] as $size_data) {
                if (!is_array($size_data)) {
                    continue;
                }

                foreach ($this->getFilesFromSizeData($size_data) as $size_file) {
                    $size_basename = wp_basename($size_file);
                    $files[$file_dir . $size_basename] = $subdir . $size_basename;
                }
            }
        }

        if (!empty($new_metadata['original_image']) && is_string($new_metadata['original_image'])) {
            $original_basename = wp_basename($new_metadata['original_image']);
            $files[$file_dir . $original_basename] = $subdir . $original_basename;
        }

        foreach ($this->getRootSourceFiles($new_metadata) as $source_file) {
            $source_basename = wp_basename($source_file);
            $files[$file_dir . $source_basename] = $subdir . $source_basename;
        }

        $required_local_files = array_fill_keys(
            array_map('wp_normalize_path', array_filter($required_local_files, 'is_string')),
            true
        );

        foreach ($files as $local_path => $cloud_key) {
            $require_local_upload = isset($required_local_files[wp_normalize_path($local_path)]);
            if (!$this->uploadOrVerifyFile(
                $attachment_id,
                $local_path,
                $cloud_key,
                'thumbnail_regeneration',
                $require_local_upload
            )) {
                return false;
            }
        }

        // Reuse the verified sidecar path used by Edit Image. Optimizers may
        // upload files already produced synchronously, or defer cleanup until
        // their background regeneration finishes.
        $sidecars_uploaded = (bool) apply_filters(
            'advmo_upload_image_operation_sidecars',
            true,
            $attachment_id,
            $subdir,
            $new_metadata,
            'thumbnail_regeneration'
        );
        if (!$sidecars_uploaded) {
            $this->logError($attachment_id, 'Failed to upload all optimizer sidecar files for thumbnail regeneration.', false);
            return false;
        }

        $obsolete_keys = [];
        if ($delete_obsolete) {
            $old_files = $this->getRegenerationMetadataFiles($old_metadata);
            $new_files = $this->getRegenerationMetadataFiles($new_metadata);
            $removed_files = array_diff($old_files, $new_files);
            foreach ($removed_files as $old_file) {
                $obsolete_keys[] = $subdir . $old_file;
            }

            $allowed_obsolete_keys = $this->getAllowedRegenerationObsoleteKeys(
                $subdir,
                $removed_files
            );

            /**
             * Filter exact obsolete cloud keys after thumbnail regeneration.
             * Optimizer integrations append sidecars for removed standard files.
             *
             * @param string[] $obsolete_keys
             * @param int      $attachment_id
             * @param string   $subdir
             * @param array    $old_metadata
             * @param array    $new_metadata
             */
            $obsolete_keys = apply_filters(
                'advmo_regeneration_obsolete_cloud_keys',
                $obsolete_keys,
                $attachment_id,
                $subdir,
                $old_metadata,
                $new_metadata
            );
            // A filter may derive a sidecar name that is also a native file in
            // the new metadata. Current objects always win over cleanup hints.
            $obsolete_keys = array_diff(
                is_array($obsolete_keys) ? $obsolete_keys : [],
                array_values($files),
                $this->getImageEditBackupCloudKeys($attachment_id, $subdir)
            );
            $obsolete_keys = $this->validateAttachmentCloudKeys(
                $obsolete_keys,
                $subdir,
                $allowed_obsolete_keys
            );
        }

        $deferred_by = $this->getDeferredCleanupOwner(
            $delete_local_rule,
            $attachment_id,
            'thumbnail_regeneration'
        );
        $pending_cleanup = $this->buildPendingCleanupState(
            $delete_local_rule,
            $deferred_by,
            'thumbnail_regeneration',
            $new_metadata
        );

        if (!is_array($pending_cleanup)) {
            $this->logError($attachment_id, 'Unable to build thumbnail-regeneration cleanup state. Local files were kept.', false);
            return false;
        }

        // Several metadata filters can stage the same regeneration before its
        // final WordPress commit. Keep one identity within that request while
        // ensuring a later regeneration always receives a different identity.
        if (
            $may_replace
            && is_array($stored_pending)
            && !empty($stored_pending['operation_id'])
            && is_string($stored_pending['operation_id'])
        ) {
            $pending_cleanup['operation_id'] = $stored_pending['operation_id'];
        }

        // Regeneration commit checks care about files WordPress will serve,
        // not unrelated EXIF or plugin metadata another filter may append.
        $pending_cleanup['metadata_fingerprint'] = $this->getRegenerationMetadataFingerprint($new_metadata);
        // Only errors that existed before this retry may be resolved by it.
        $pending_cleanup['errors_to_clear'] = $previous_errors;
        $pending_cleanup['cloud_delete_keys'] = $obsolete_keys;
        $pending_cleanup['regenerated_thumbnails'] = [];
        foreach (($new_metadata['sizes'] ?? []) as $size_name => $size_data) {
            if (is_array($size_data)) {
                $pending_cleanup['regenerated_thumbnails'][] = [
                    'size' => (string) $size_name,
                    'data' => $size_data,
                ];
            }
        }

        if (!$this->persistAttachmentMeta(
            $attachment_id,
            self::META_PENDING_LOCAL_CLEANUP,
            $pending_cleanup
        )) {
            $this->logError($attachment_id, 'Failed to save thumbnail-regeneration cleanup state. Local files were kept.', false);
            return false;
        }

        return true;
    }

    /**
     * Return exact cloud keys WordPress still needs for Restore Original.
     * Regeneration may remove these names from current metadata, but they are
     * not obsolete while image-editor backup metadata still references them.
     *
     * @return string[]
     */
    private function getImageEditBackupCloudKeys(int $attachment_id, string $subdir): array
    {
        $keys = [];
        foreach (array_keys($this->getImageEditBackupFiles($attachment_id)) as $backup_file) {
            if (is_string($backup_file) && $backup_file !== '') {
                $keys[] = $subdir . wp_basename($backup_file);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Cancel only regeneration state staged by the current failed request.
     */
    public function cancelRegenerationCleanup(int $attachment_id): void
    {
        $pending = get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true);
        if (is_array($pending) && ($pending['context'] ?? '') === 'thumbnail_regeneration') {
            $this->discardPendingCleanup($attachment_id, $pending);
        }
    }

    /**
     * Return cloud basenames represented by generated sizes and modern-format
     * sources. The main/original files are excluded because regeneration does
     * not make their old URLs disposable.
     *
     * @return string[]
     */
    private function getRegenerationMetadataFiles(array $metadata): array
    {
        $files = [];
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_data) {
                if (is_array($size_data)) {
                    $files = array_merge($files, $this->getFilesFromSizeData($size_data));
                }
            }
        }

        $files = array_merge($files, $this->getRootSourceFiles($metadata));
        $files = array_map('wp_basename', $files);

        return array_values(array_unique(array_filter($files, 'strlen')));
    }

    /**
     * Fingerprint only metadata that selects regenerated files. This lets an
     * optimizer update unrelated metadata without canceling safe cleanup.
     */
    private function getRegenerationMetadataFingerprint(array $metadata): string
    {
        $relevant = [
            'file' => isset($metadata['file']) ? (string) $metadata['file'] : '',
            'width' => isset($metadata['width']) ? (int) $metadata['width'] : 0,
            'height' => isset($metadata['height']) ? (int) $metadata['height'] : 0,
            'original_image' => isset($metadata['original_image']) ? (string) $metadata['original_image'] : '',
            'sources' => isset($metadata['sources']) && is_array($metadata['sources'])
                ? $metadata['sources']
                : [],
            'sizes' => [],
        ];

        foreach (($metadata['sizes'] ?? []) as $size_name => $size_data) {
            if (!is_array($size_data)) {
                continue;
            }
            $relevant['sizes'][(string) $size_name] = [
                'file' => isset($size_data['file']) ? (string) $size_data['file'] : '',
                'width' => isset($size_data['width']) ? (int) $size_data['width'] : 0,
                'height' => isset($size_data['height']) ? (int) $size_data['height'] : 0,
                'mime-type' => isset($size_data['mime-type']) ? (string) $size_data['mime-type'] : '',
                'sources' => isset($size_data['sources']) && is_array($size_data['sources'])
                    ? $size_data['sources']
                    : [],
            ];
        }

        return hash('sha256', maybe_serialize($this->normalizeMetadataForFingerprint($relevant)));
    }

    /**
     * Keep exact-key deletion scoped to this attachment's committed cloud path.
     *
     * @param mixed  $keys
     * @return string[]
     */
    private function validateAttachmentCloudKeys($keys, string $subdir, array $allowedKeys): array
    {
        if (!is_array($keys)) {
            return [];
        }

        $allowed = array_fill_keys($allowedKeys, true);
        $valid = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || false !== strpos($key, "\0") || 0 === strpos($key, '/')) {
                continue;
            }
            if (preg_match('#(^|/)\.\.(/|$)#', $key)) {
                continue;
            }
            if ($subdir !== '' && 0 !== strpos($key, $subdir)) {
                continue;
            }
            if ($subdir === '' && false !== strpos($key, '/')) {
                continue;
            }
            if (!isset($allowed[$key])) {
                continue;
            }
            $valid[] = $key;
        }

        return array_values(array_unique($valid));
    }

    /**
     * Restrict regeneration deletion to old files and known optimizer sidecar
     * naming schemes. This remains safe even when objects live at bucket root.
     *
     * @param string[] $removedFiles
     * @return string[]
     */
    private function getAllowedRegenerationObsoleteKeys(string $subdir, array $removedFiles): array
    {
        $keys = [];
        foreach ($removedFiles as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }

            $basename = wp_basename($file);
            $keys[] = $subdir . $basename;
            $keys[] = $subdir . $basename . '.webp';
            $keys[] = $subdir . $basename . '.avif';

            $name = pathinfo($basename, PATHINFO_FILENAME);
            if ($name !== '') {
                $keys[] = $subdir . $name . '.webp';
                $keys[] = $subdir . $name . '.avif';
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Upload an attachment's files to cloud storage.
     *
     * @param int $attachment_id Attachment ID.
     * @return string|false The subdir the files were uploaded to (may be an
     *                      empty string when versioning/year-month is off), or
     *                      false on failure.
     */
    private function uploadToCloud(int $attachment_id): string|false
    {
        /**
         * Fires before the attachment is uploaded to the cloud.
         *
         * This action allows developers to perform tasks or logging before
         * the attachment is uploaded to the cloud.
         *
         * @param int $attachment_id
         */
        do_action('advmo_before_upload_to_cloud', $attachment_id);

        # remove error logs related to the attachment before starting the new upload process
        delete_post_meta($attachment_id, 'advmo_error_log');

        if (!$this->attachment_exists_on_disk($attachment_id)) {
            return false;
        }

        $file = get_attached_file($attachment_id);
        $subdir = $this->get_attachment_subdir($attachment_id);

        $uploadResult = $this->cloudProvider->uploadFile($file, $subdir . wp_basename($file));

        if (!$uploadResult) {
            $this->logError($attachment_id, 'Failed to upload main file to cloud storage.');
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $metadata_sizes = $this->uniqueMetaDataSizes($metadata['sizes']);
            $base_file = get_attached_file($attachment_id, true);
            $file_dir = trailingslashit(dirname($base_file));
            
            foreach ($metadata_sizes as $size => $data) {
                // Get all files for this size, including sources (Modern Image Formats)
                $size_files = $this->getFilesFromSizeData($data);
                
                foreach ($size_files as $size_file) {
                    $file_path = $file_dir . $size_file;
                    $uploadResult = $this->cloudProvider->uploadFile($file_path, $subdir . $size_file);
                    if (!$uploadResult) {
                        $this->logError($attachment_id, "Failed to upload size '{$size}' file '{$size_file}' to cloud storage.");
                        return false;
                    }
                }
            }
        }

        /**
         * Filter to determine whether the original image should be uploaded to the cloud.
         *
         * Return false to skip uploading the original image.
         *
         * @param bool $should_upload_original_image Default true.
         * @param int  $attachment_id                 Attachment ID.
         * @param array $metadata                      Attachment metadata.
         */
        $should_upload_original_image = apply_filters('advmo_should_upload_original_image', true, $attachment_id, $metadata);

        if ($should_upload_original_image && !empty($metadata['original_image'])) {
            $original_image = wp_get_original_image_path($attachment_id);
            $uploadResult = $this->cloudProvider->uploadFile($original_image, $subdir . wp_basename($original_image));
            if (!$uploadResult) {
                $this->logError($attachment_id, 'Failed to upload original image to cloud storage.');
                return false;
            }
        }

        // Upload root-level source files (Modern Image Formats support)
        // Only process if metadata is a valid array (may be false for non-image files before DB save)
        $root_source_files = is_array($metadata) ? $this->getRootSourceFiles($metadata) : [];
        if (!empty($root_source_files)) {
            $main_file = get_attached_file($attachment_id, true);
            $file_dir = trailingslashit(dirname($main_file));
            
            foreach ($root_source_files as $source_file) {
                $source_path = $file_dir . $source_file;
                if (file_exists($source_path)) {
                    $uploadResult = $this->cloudProvider->uploadFile($source_path, $subdir . $source_file);
                    if (!$uploadResult) {
                        $this->logError($attachment_id, "Failed to upload source file '{$source_file}' to cloud storage.");
                        return false;
                    }
                }
            }
        }

        // WordPress stores the pre-edit original and its original thumbnails
        // outside the current attachment metadata. Upload those backups too so
        // Restore Original is safe even when the image was edited before its
        // first manual offload.
        $main_file = (string) get_attached_file($attachment_id, true);
        if (!$this->uploadImageEditBackups(
            $attachment_id,
            trailingslashit(dirname($main_file)),
            $subdir
        )) {
            return false;
        }

        // Include optimizer-generated files that already existed before the
        // attachment's first offload. Image-edit and regeneration paths invoke
        // the same verified filter, but bulk and WP-CLI initial offloads used to
        // skip it, leaving existing Imagify/EWWW WebP or AVIF files behind.
        if (wp_attachment_is_image($attachment_id)) {
            $sidecarsUploaded = (bool) apply_filters(
                'advmo_upload_image_operation_sidecars',
                true,
                $attachment_id,
                $subdir,
                is_array($metadata) ? $metadata : [],
                'initial_upload'
            );
            if (!$sidecarsUploaded) {
                $this->logError(
                    $attachment_id,
                    'Failed to upload all optimizer sidecar files for the initial offload.',
                    false
                );
                return false;
            }
        }

        // Return the subdir actually used for the upload so the caller can
        // record advmo_path from it rather than re-deriving (and risking a
        // divergent object-versioning value).
        return $subdir;
    }

    /**
     * Re-upload all files for an already-offloaded attachment.
     *
     * Overwrites cloud copies with current local files, then fires
     * advmo_reoffload_attachment so integration observers (EWWW, Imagify)
     * can upload their sidecar files (WebP, AVIF).
     *
     * @param int $attachment_id Attachment ID.
     * @return bool True on success, false on failure.
     */
    public function reoffloadAttachment(int $attachment_id): bool
    {
        if (!$this->is_offloaded($attachment_id)) {
            return false;
        }

        $advmo_path = get_post_meta($attachment_id, 'advmo_path', true);
        if (empty($advmo_path)) {
            return false;
        }

        delete_post_meta($attachment_id, 'advmo_error_log');

        $base_file = get_attached_file($attachment_id, true);
        if (!file_exists($base_file)) {
            $this->logError($attachment_id, 'Main file does not exist for reoffload.');
            return false;
        }

        $file_dir = trailingslashit(dirname($base_file));
        $metadata = wp_get_attachment_metadata($attachment_id);

        $uploadResult = $this->cloudProvider->uploadFile($base_file, $advmo_path . wp_basename($base_file));
        if (!$uploadResult) {
            $this->logError($attachment_id, 'Failed to re-upload main file to cloud storage.');
            return false;
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $metadata_sizes = $this->uniqueMetaDataSizes($metadata['sizes']);

            foreach ($metadata_sizes as $size => $data) {
                $size_files = $this->getFilesFromSizeData($data);

                foreach ($size_files as $size_file) {
                    $file_path = $file_dir . $size_file;
                    if (file_exists($file_path)) {
                        $uploadResult = $this->cloudProvider->uploadFile($file_path, $advmo_path . $size_file);
                        if (!$uploadResult) {
                            $this->logError($attachment_id, "Failed to re-upload size '{$size}' file '{$size_file}' to cloud storage.");
                            return false;
                        }
                    }
                }
            }
        }

        if (!empty($metadata['original_image'])) {
            $original_image = wp_get_original_image_path($attachment_id);
            if ($original_image && file_exists($original_image)) {
                $uploadResult = $this->cloudProvider->uploadFile($original_image, $advmo_path . wp_basename($original_image));
                if (!$uploadResult) {
                    $this->logError($attachment_id, 'Failed to re-upload original image to cloud storage.');
                    return false;
                }
            }
        }

        $root_source_files = is_array($metadata) ? $this->getRootSourceFiles($metadata) : [];
        foreach ($root_source_files as $source_file) {
            $source_path = $file_dir . $source_file;
            if (file_exists($source_path)) {
                $uploadResult = $this->cloudProvider->uploadFile($source_path, $advmo_path . $source_file);
                if (!$uploadResult) {
                    $this->logError($attachment_id, "Failed to re-upload source file '{$source_file}' to cloud storage.");
                    return false;
                }
            }
        }

        if (!$this->uploadImageEditBackups($attachment_id, $file_dir, $advmo_path)) {
            return false;
        }

        /**
         * Fires after standard files have been re-uploaded during a reoffload.
         *
         * Third-party integration observers (EWWW, Imagify) should hook here
         * to upload their sidecar files (WebP, AVIF).
         *
         * @param int    $attachment_id The attachment ID.
         * @param string $advmo_path    The cloud storage path prefix for this attachment.
         */
        do_action('advmo_reoffload_attachment', $attachment_id, $advmo_path);

        return true;
    }

    /**
     * Upload files recorded in WordPress image-editor backup metadata.
     *
     * Missing historical edit versions are ignored, but an "-orig" file must
     * either exist locally or already exist in cloud storage because WordPress
     * can select it during Restore Original.
     */
    private function uploadImageEditBackups(
        int $attachment_id,
        string $fileDir,
        string $subdir
    ): bool
    {
        $backupGroups = [
            get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true),
            get_post_meta($attachment_id, '_wp_attachment_backup_sources', true),
        ];
        $seen = [];

        foreach ($backupGroups as $backupGroup) {
            if (!is_array($backupGroup)) {
                continue;
            }

            foreach ($backupGroup as $backupKey => $backupData) {
                if (!is_array($backupData)) {
                    continue;
                }

                $entries = isset($backupData['file']) ? [$backupData] : $backupData;
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $isRestorableOriginal = is_string($backupKey)
                        && strlen($backupKey) >= strlen('-orig')
                        && 0 === substr_compare($backupKey, '-orig', -strlen('-orig'));
                    foreach ($this->getFilesFromSizeData($entry) as $basename) {
                        if (isset($seen[$basename])) {
                            continue;
                        }
                        $seen[$basename] = true;

                        $localPath = $fileDir . $basename;
                        $cloudKey = $subdir . $basename;

                        if (!file_exists($localPath) && !$isRestorableOriginal) {
                            continue;
                        }

                        if (!$this->uploadOrVerifyFile(
                            $attachment_id,
                            $localPath,
                            $cloudKey,
                            'image_backup'
                        )) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    private function logError(int $attachment_id, string $specificError, bool $includeConnectionHint = true): void
    {
        $generalError = $specificError;
        if ($includeConnectionHint) {
            $generalError .= ' Please review your Cloud provider credentials or connection settings. For more details, enable debug.log and check the logs.';
        }

        $this->appendAttachmentError($attachment_id, $generalError);

        // advmo_offloaded is the commit marker used by every URL-rewriting
        // observer. Errors are recorded separately and must never demote that
        // marker: a concurrent worker may commit and delete local files between
        // a read and a conditional write here.
    }

    /**
     * Save all metadata required to serve an attachment from cloud storage.
     *
     * advmo_offloaded is intentionally written last. Local cleanup is allowed
     * only after every value, including the pending-cleanup state, can be read
     * back from the database.
     */
    private function updateAttachmentMetadata(int $attachment_id, string $subdir, ?array $pendingCleanup): bool
    {
        $metadata = [
            'advmo_path' => $subdir,
            'advmo_provider' => $this->cloudProvider->getProviderName(),
            'advmo_bucket' => $this->cloudProvider->getBucket(),
            'advmo_offloaded_at' => time(),
        ];

        foreach ($metadata as $key => $value) {
            if (!$this->persistAttachmentMeta($attachment_id, $key, $value)) {
                $this->logError(
                    $attachment_id,
                    "Failed to save offload metadata '{$key}'. Local files were kept.",
                    false
                );
                return false;
            }
        }

        if ($pendingCleanup !== null) {
            if (!$this->persistAttachmentMeta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, $pendingCleanup)) {
                $this->logError(
                    $attachment_id,
                    'Failed to save the pending local cleanup state. Local files were kept.',
                    false
                );
                return false;
            }
        } elseif (metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP)) {
            delete_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP);
            if (metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP)) {
                $this->logError(
                    $attachment_id,
                    'Failed to clear a stale local cleanup state. Local files were kept.',
                    false
                );
                return false;
            }
        }

        if (!$this->persistAttachmentMeta($attachment_id, 'advmo_offloaded', true)) {
            $this->logError(
                $attachment_id,
                'Failed to mark the attachment as offloaded. Local files were kept.',
                false
            );
            return false;
        }

        return true;
    }

    private function persistAttachmentMeta(int $attachment_id, string $key, $value): bool
    {
        update_post_meta($attachment_id, $key, $value);

        if (!metadata_exists('post', $attachment_id, $key)) {
            return false;
        }

        $storedValue = get_post_meta($attachment_id, $key, true);
        if (is_array($value)) {
            return $storedValue === $value;
        }
        if (is_bool($value)) {
            return (bool) $storedValue === $value;
        }
        if (is_int($value)) {
            return (int) $storedValue === $value;
        }

        return (string) $storedValue === (string) $value;
    }

    private function buildPendingCleanupState(
        int $deleteLocalRule,
        ?string $deferredBy,
        ?string $context = null,
        ?array $metadata = null
    ): ?array
    {
        if ($deleteLocalRule === 0 && $context === null) {
            return null;
        }

        $state = [
            'operation_id' => wp_generate_uuid4(),
            'rule' => $deleteLocalRule,
            'deferred' => $deferredBy !== null ? 1 : 0,
        ];

        if ($deferredBy !== null) {
            $state['deferred_by'] = $deferredBy;
            $state['created_at'] = time();
        }

        if ($context !== null && $metadata !== null) {
            $state['context'] = $context;
            $state['expected_file'] = isset($metadata['file']) ? (string) $metadata['file'] : '';
            $state['metadata_fingerprint'] = $this->getMetadataFingerprint($metadata);
            $state['created_at'] = time();
        }

        return $state;
    }

    private function getDeferredCleanupOwner(
        int $deleteLocalRule,
        int $attachment_id,
        string $context = 'initial_upload'
    ): ?string
    {
        /**
         * Filter the integration that temporarily owns local cleanup.
         *
         * Rule 0 is final and means keep local files, so integrations should
         * return null for it. A non-empty owner means the integration will
         * finish cleanup and call completePendingLocalCleanup().
         *
         * @param string|null $owner           Integration identifier or null.
         * @param int         $deleteLocalRule Final deletion rule after filtering.
         * @param int         $attachment_id   Attachment ID.
         * @param string      $context         initial_upload, image_edit, image_restore, etc.
         */
        $owner = apply_filters(
            'advmo_local_cleanup_deferred_by',
            null,
            $deleteLocalRule,
            $attachment_id,
            $context
        );

        if (!is_string($owner) || $owner === '') {
            return null;
        }

        $owner = sanitize_key($owner);
        return $owner !== '' ? $owner : null;
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

    /**
     * Complete or resume the post-commit phase of an initial offload.
     *
     * The pending marker is retained until local cleanup and the after-upload
     * action both complete. This makes direct deletion idempotent after a PHP
     * timeout, fatal error, or worker termination. Deferred integrations clear
     * the marker when their own cleanup finishes.
     */
    private function runPostCommitCleanup(int $attachment_id, ?array $pendingCleanup): void
    {
        if ($pendingCleanup === null) {
            $this->fireAfterUploadAction($attachment_id);
            return;
        }

        $rule = isset($pendingCleanup['rule']) ? (int) $pendingCleanup['rule'] : 0;
        $deferred = !empty($pendingCleanup['deferred']);

        if ($rule === 0) {
            $this->completePendingLocalCleanup($attachment_id, $pendingCleanup);
            $this->fireAfterUploadAction($attachment_id);
            return;
        }

        if ($deferred) {
            if ($this->isDeferredCleanupExpired($pendingCleanup)) {
                $this->expireDeferredCleanup($attachment_id, $pendingCleanup, 'initial upload');
                return;
            }

            $this->fireAfterUploadAction($attachment_id);
            return;
        }

        $cleanupSucceeded = $this->deleteLocalFile($attachment_id, $rule, false);

        $this->fireAfterUploadAction($attachment_id);

        if ($cleanupSucceeded) {
            $this->completePendingLocalCleanup($attachment_id, $pendingCleanup);
        }
    }

    private function fireAfterUploadAction(int $attachment_id): void
    {
        /**
         * Fires after the attachment has been uploaded, its offload metadata is
         * committed, and any immediate local cleanup has been attempted.
         * This action may fire again while an interrupted cleanup is resumed.
         *
         * @param int $attachment_id The ID of the attachment that was processed.
         */
        do_action('advmo_after_upload_to_cloud', $attachment_id);
    }

    public function resumePendingLocalCleanup(int $attachment_id): bool
    {
        // A pending marker can be written immediately before the commit marker.
        // A retry worker must never delete files if that final commit did not
        // happen.
        if (!$this->is_offloaded($attachment_id)) {
            return false;
        }

        $pendingCleanup = get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true);
        if (!is_array($pendingCleanup) || empty($pendingCleanup)) {
            $pendingCleanup = $this->recoverOrphanedEWWWCleanup($attachment_id);
            if ($pendingCleanup === null) {
                return true;
            }
        }

        // Older releases created deferred records without an age marker. Stamp
        // them on first sight so a dead optimizer queue cannot block cleanup
        // forever after an upgrade.
        if (!empty($pendingCleanup['deferred']) && empty($pendingCleanup['created_at'])) {
            $updatedPendingCleanup = $pendingCleanup;
            $updatedPendingCleanup['created_at'] = time();
            update_post_meta(
                $attachment_id,
                self::META_PENDING_LOCAL_CLEANUP,
                $updatedPendingCleanup,
                $pendingCleanup
            );
            $storedPendingCleanup = get_post_meta(
                $attachment_id,
                self::META_PENDING_LOCAL_CLEANUP,
                true
            );
            if ($storedPendingCleanup !== $updatedPendingCleanup) {
                $this->logError(
                    $attachment_id,
                    'Unable to timestamp legacy deferred cleanup state. Local files were kept.',
                    false
                );
                return false;
            }
            $pendingCleanup = $updatedPendingCleanup;
        }

        if (($pendingCleanup['context'] ?? '') === 'thumbnail_regeneration') {
            return $this->resumeRegenerationCleanup($attachment_id, $pendingCleanup);
        }

        if (!empty($pendingCleanup['context'])) {
            return $this->resumeImageOperationCleanup($attachment_id, $pendingCleanup);
        }

        $this->runPostCommitCleanup($attachment_id, $pendingCleanup);

        return !metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP);
    }

    /**
     * Finish regeneration cleanup only after WordPress commits the exact
     * metadata whose files were uploaded.
     */
    private function resumeRegenerationCleanup(int $attachment_id, array $pendingCleanup): bool
    {
        $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        $expectedFingerprint = isset($pendingCleanup['metadata_fingerprint'])
            ? (string) $pendingCleanup['metadata_fingerprint']
            : '';

        if (
            !is_array($metadata)
            || $expectedFingerprint === ''
            || !hash_equals($expectedFingerprint, $this->getRegenerationMetadataFingerprint($metadata))
        ) {
            $createdAt = isset($pendingCleanup['created_at']) ? (int) $pendingCleanup['created_at'] : 0;
            if ($createdAt > 0 && (time() - $createdAt) < (5 * MINUTE_IN_SECONDS)) {
                return false;
            }

            $this->logError(
                $attachment_id,
                'Canceled stale thumbnail-regeneration cleanup because WordPress did not commit the expected metadata. Local files and old cloud objects were kept.',
                false
            );
            return $this->discardPendingCleanup($attachment_id, $pendingCleanup);
        }

        if (!empty($pendingCleanup['deferred']) && $this->isDeferredCleanupExpired($pendingCleanup)) {
            return $this->expireDeferredCleanup(
                $attachment_id,
                $pendingCleanup,
                'thumbnail regeneration'
            );
        }

        /**
         * Fires after WordPress committed regenerated attachment metadata.
         * Optimizer integrations may finish deferred processing here.
         */
        do_action('advmo_regeneration_committed', $attachment_id, $pendingCleanup);

        $pendingCleanup = get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true);
        if (!is_array($pendingCleanup) || empty($pendingCleanup)) {
            return true;
        }

        if (!empty($pendingCleanup['deferred'])) {
            return false;
        }

        $rule = isset($pendingCleanup['rule']) ? (int) $pendingCleanup['rule'] : 0;
        $thumbnails = isset($pendingCleanup['regenerated_thumbnails']) && is_array($pendingCleanup['regenerated_thumbnails'])
            ? $pendingCleanup['regenerated_thumbnails']
            : [];

        if ($rule !== 0) {
            do_action('advmo_before_delete_regenerated_local_thumbnails', $attachment_id, $thumbnails, $rule);

            if (!$this->deleteLocalFile($attachment_id, $rule, false)) {
                return false;
            }

            $sidecarsDeleted = (bool) apply_filters(
                'advmo_delete_image_operation_sidecars',
                true,
                $attachment_id,
                $rule,
                $metadata
            );
            if (!$sidecarsDeleted) {
                return false;
            }

            do_action('advmo_after_delete_regenerated_local_thumbnails', $attachment_id, $thumbnails, $rule);
        }

        return $this->completePendingLocalCleanup($attachment_id, $pendingCleanup);
    }

    /**
     * Resume cleanup for an image edit or restore only after WordPress has
     * committed the exact metadata whose files were uploaded.
     */
    private function resumeImageOperationCleanup(int $attachment_id, array $pendingCleanup): bool
    {
        $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        $expectedFingerprint = isset($pendingCleanup['metadata_fingerprint'])
            ? (string) $pendingCleanup['metadata_fingerprint']
            : '';

        if (
            !is_array($metadata)
            || $expectedFingerprint === ''
            || !hash_equals($expectedFingerprint, $this->getMetadataFingerprint($metadata))
        ) {
            // The metadata write may still be in progress in another request.
            // Keep files during that short window. If it never commits, cancel
            // only the cleanup state; the uploaded cloud objects are harmless.
            $createdAt = isset($pendingCleanup['created_at']) ? (int) $pendingCleanup['created_at'] : 0;
            if ($createdAt > 0 && (time() - $createdAt) < (5 * MINUTE_IN_SECONDS)) {
                return false;
            }

            $this->logError(
                $attachment_id,
                'Canceled stale image-operation cleanup because WordPress did not commit the expected metadata. Local files were kept.',
                false
            );
            return $this->discardImageOperationCleanup($attachment_id, $pendingCleanup);
        }

        if (!empty($pendingCleanup['deferred']) && $this->isDeferredCleanupExpired($pendingCleanup)) {
            return $this->expireDeferredCleanup(
                $attachment_id,
                $pendingCleanup,
                'image operation'
            );
        }

        /**
         * Fires after WordPress committed an edited/restored metadata set.
         * Optimizer integrations may finish a synchronous pre-commit run here.
         *
         * @param int   $attachment_id Attachment ID.
         * @param array $pendingCleanup Pending cleanup state.
         */
        do_action('advmo_image_operation_committed', $attachment_id, $pendingCleanup);

        // An optimizer may have completed and cleared the state.
        $pendingCleanup = get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true);
        if (!is_array($pendingCleanup) || empty($pendingCleanup)) {
            return true;
        }

        if (!empty($pendingCleanup['deferred'])) {
            return false;
        }

        $rule = isset($pendingCleanup['rule']) ? (int) $pendingCleanup['rule'] : 0;
        if ($rule === 0) {
            return $this->completeImageOperationCleanup($attachment_id, $pendingCleanup);
        }

        if (!$this->deleteLocalFile($attachment_id, $rule, false)) {
            return false;
        }

        /**
         * Delete optimizer sidecars only after their cloud upload and the
         * standard-file cleanup both succeeded.
         */
        $sidecarsDeleted = (bool) apply_filters(
            'advmo_delete_image_operation_sidecars',
            true,
            $attachment_id,
            $rule,
            $metadata
        );
        if (!$sidecarsDeleted) {
            return false;
        }

        return $this->completeImageOperationCleanup($attachment_id, $pendingCleanup);
    }

    /**
     * Mark deferred or direct local cleanup as complete.
     *
     * Integrations that defer cleanup should call this only after their own
     * local files have also been removed.
     */
    public function completePendingLocalCleanup(int $attachment_id, ?array $expectedState = null): bool
    {
        $stored = get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true);
        if ($expectedState !== null && $stored !== $expectedState) {
            return false;
        }

        $state = $expectedState ?? (is_array($stored) ? $stored : null);

        if (
            is_array($state)
            && ($state['context'] ?? '') === 'thumbnail_regeneration'
            && !empty($state['cloud_delete_keys'])
        ) {
            $keys = is_array($state['cloud_delete_keys']) ? $state['cloud_delete_keys'] : [];
            if (!$this->cloudProvider->deleteObjects($keys)) {
                $this->logError(
                    $attachment_id,
                    self::REGENERATION_CLOUD_CLEANUP_ERROR,
                    false
                );
                return false;
            }
        }

        if (
            is_array($state)
            && in_array(
                ($state['context'] ?? ''),
                ['thumbnail_regeneration', 'image_edit', 'image_restore'],
                true
            )
            && !$this->cleanupHydratedRegenerationFiles($attachment_id)
        ) {
            // Keep the operation marker until the retry worker removes every
            // temporary cloud download. This also covers a process stopping
            // after the metadata commit but before hydration cleanup finishes.
            $this->logError(
                $attachment_id,
                self::HYDRATED_CLEANUP_ERROR,
                false
            );
            return false;
        }

        if ($expectedState !== null) {
            // Compare-and-delete prevents a late optimizer callback from
            // removing a cleanup marker written by a newer image edit.
            delete_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, $expectedState);
        } else {
            delete_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP);
        }

        $completed = !metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP);
        if ($completed && is_array($state) && ($state['context'] ?? '') === 'thumbnail_regeneration') {
            $this->clearRegenerationErrors($attachment_id, $state);
        }

        return $completed;
    }

    /** Clear recovered errors only after the matching regeneration finishes. */
    private function clearRegenerationErrors(int $attachment_id, array $state): void
    {
        // Pending records from older versions do not contain an error snapshot.
        $resolved = $state['errors_to_clear'] ?? $this->getRegenerationErrors($attachment_id);
        if (!is_array($resolved)) {
            return;
        }
        if (!empty($state['cloud_delete_keys'])) {
            // Cloud deletion may have failed after the upload was staged.
            $resolved[] = self::REGENERATION_CLOUD_CLEANUP_ERROR;
        }

        $stored = get_post_meta($attachment_id, 'advmo_error_log', true);
        $errors = is_array($stored) ? $stored : [$stored];
        $remaining = array_values(array_filter(
            $errors,
            static fn($error): bool => !in_array($error, $resolved, true)
        ));
        if (count($remaining) === count($errors)) {
            return;
        }

        // Preserve a concurrent error writer instead of replacing its report.
        if (empty($remaining)) {
            delete_post_meta($attachment_id, 'advmo_error_log', $stored);
        } else {
            update_post_meta($attachment_id, 'advmo_error_log', $remaining, $stored);
        }
    }

    /** Recognize this operation's existing string-based reports, including legacy values. */
    private function getRegenerationErrors(int $attachment_id): array
    {
        $messages = [
            'Thumbnail regeneration returned empty attachment metadata.',
            'Thumbnail regeneration was stopped because an earlier local cleanup is still pending. Regeneration did not start, so its files were not changed.',
            'Thumbnail regeneration was not synced because an earlier cleanup appeared after regeneration started. Regenerated files were kept locally.',
            'The committed cloud path is missing for thumbnail regeneration.',
            'Unable to determine the image path for thumbnail regeneration.',
            'Failed to upload all optimizer sidecar files for thumbnail regeneration.',
            'Unable to build thumbnail-regeneration cleanup state. Local files were kept.',
            'Failed to save thumbnail-regeneration cleanup state. Local files were kept.',
            'Canceled stale thumbnail-regeneration cleanup because WordPress did not commit the expected metadata. Local files and old cloud objects were kept.',
            'Canceled deferred thumbnail regeneration cleanup because the optimizer did not finish within the retry window. Local files and old cloud objects were kept.',
            'The cloud regeneration source could not be mapped to a safe uploads path.',
            'The temporary regeneration source could not be recorded for crash-safe cleanup.',
            self::REGENERATION_CLOUD_CLEANUP_ERROR,
        ];
        $patterns = [
            "~\AFailed to upload '.+' during thumbnail_regeneration\."
                . "(?: Please review your Cloud provider credentials or connection settings\. For more details, enable debug\.log and check the logs\.)?\z~s",
            "~\ANewly generated file '.+' disappeared before it could be uploaded during thumbnail regeneration\.\z~s",
            "~\ACloud storage could not verify required thumbnail_regeneration object '.+'\. Local files were kept\.\z~s",
            "~\ARequired thumbnail_regeneration file '.+' is missing locally or could not be verified in cloud storage\.\z~s",
            "~\AFailed to download regeneration source '.+' from cloud storage\.\z~s",
            "~\ADownloaded regeneration source '.+' is not a valid image\.\z~s",
        ];

        $errors = get_post_meta($attachment_id, 'advmo_error_log', true);
        return array_values(array_filter(
            is_array($errors) ? $errors : [$errors],
            static function ($error) use ($messages, $patterns): bool {
                if (!is_string($error)) {
                    return false;
                }
                if (in_array($error, $messages, true)) {
                    return true;
                }
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $error) === 1) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }

    /**
     * Clear an uncommitted operation without running its cloud cleanup.
     */
    private function discardPendingCleanup(int $attachment_id, array $expectedState): bool
    {
        delete_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, $expectedState);
        return !metadata_exists('post', $attachment_id, self::META_PENDING_LOCAL_CLEANUP);
    }

    private function completeImageOperationCleanup(int $attachment_id, array $pendingCleanup): bool
    {
        if (!$this->completePendingLocalCleanup($attachment_id, $pendingCleanup)) {
            return false;
        }

        $this->clearImageOperationMarkers($attachment_id, true);
        return true;
    }

    /**
     * Cancel an uncommitted image operation without deleting hydrated files.
     * A later successful operation may reuse and safely clean those files.
     */
    private function discardImageOperationCleanup(int $attachment_id, array $pendingCleanup): bool
    {
        if (!$this->discardPendingCleanup($attachment_id, $pendingCleanup)) {
            return false;
        }

        $this->clearImageOperationMarkers($attachment_id, true);
        return true;
    }

    private function isDeferredCleanupExpired(array $pendingCleanup): bool
    {
        if (empty($pendingCleanup['deferred']) || empty($pendingCleanup['created_at'])) {
            return false;
        }

        $maxAge = (int) apply_filters(
            'advmo_deferred_cleanup_max_age',
            self::DEFERRED_CLEANUP_MAX_AGE,
            $pendingCleanup
        );

        return $maxAge > 0 && (time() - (int) $pendingCleanup['created_at']) >= $maxAge;
    }

    /**
     * Stop retrying an optimizer that never completed. This deliberately keeps
     * local files and obsolete cloud objects; only the retry marker is removed.
     */
    private function expireDeferredCleanup(
        int $attachment_id,
        array $pendingCleanup,
        string $operation
    ): bool {
        $this->logError(
            $attachment_id,
            "Canceled deferred {$operation} cleanup because the optimizer did not finish within the retry window. Local files and old cloud objects were kept.",
            false
        );

        do_action('advmo_deferred_cleanup_expired', $attachment_id, $pendingCleanup);

        if (!$this->discardPendingCleanup($attachment_id, $pendingCleanup)) {
            return false;
        }

        $this->clearImageOperationMarkers($attachment_id, true);
        return true;
    }

    /**
     * Cancel an image-operation cleanup after an upload failure and leave the
     * already-committed offload marker untouched.
     */
    public function cancelImageOperationCleanup(int $attachment_id): void
    {
        $pendingCleanup = get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true);

        if (is_array($pendingCleanup) && !empty($pendingCleanup['context'])) {
            $this->discardImageOperationCleanup($attachment_id, $pendingCleanup);
            return;
        }

        // Preserve an older initial-offload cleanup state if one exists. The
        // pre-commit marker belongs only to the failed image operation.
        $this->clearImageOperationMarkers($attachment_id, false);

        if (!is_array($pendingCleanup) || empty($pendingCleanup)) {
            delete_post_meta($attachment_id, '_advmo_ewww_deferred_retention');
            delete_post_meta($attachment_id, '_advmo_imagify_deferred_retention');
        }
    }

    private function clearImageOperationMarkers(int $attachment_id, bool $clearDeferred): void
    {
        delete_post_meta($attachment_id, '_advmo_ewww_optimized_precommit');
        delete_post_meta($attachment_id, '_advmo_image_operation_in_progress');
        delete_post_meta($attachment_id, '_advmo_ewww_recovery_queued');

        if ($clearDeferred) {
            delete_post_meta($attachment_id, '_advmo_ewww_deferred_retention');
            delete_post_meta($attachment_id, '_advmo_imagify_deferred_retention');
        }
    }

    /**
     * Rebuild the retry state left by the old EWWW race.
     *
     * Earlier code could clear the generic pending marker while leaving the
     * EWWW deferral marker behind. The retry worker also scans that marker and
     * rebuilds an operation-scoped state from the currently committed metadata.
     */
    private function recoverOrphanedEWWWCleanup(int $attachment_id): ?array
    {
        $deferred = get_post_meta($attachment_id, '_advmo_ewww_deferred_retention', true);
        $rule = ($deferred === '' || $deferred === false) ? 0 : (int) $deferred;
        $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);

        if ($rule <= 0 || !is_array($metadata) || !wp_attachment_is_image($attachment_id)) {
            return null;
        }

        $pendingCleanup = $this->buildPendingCleanupState(
            $rule,
            'ewww',
            'image_edit',
            $metadata
        );
        if (
            !is_array($pendingCleanup)
            || !$this->persistAttachmentMeta(
                $attachment_id,
                self::META_PENDING_LOCAL_CLEANUP,
                $pendingCleanup
            )
        ) {
            return null;
        }

        return $pendingCleanup;
    }

    /**
     * Delete local attachment files according to the selected retention rule.
     *
     * Deletion is idempotent for Full Cloud Migration: an already-removed main
     * file is treated as an interrupted cleanup attempt and the remaining files
     * are still checked. False means cleanup or retention-state persistence was
     * incomplete; callers should keep the pending marker and retry.
     *
     * @param int  $attachment_id          Attachment ID.
     * @param int  $deleteLocalRule         1 = sized files only, 2 = all local files.
     * @param bool $completePendingCleanup Whether to clear the resumable cleanup marker.
     * @return bool True only when deletion and retention metadata both complete.
     */
    public function deleteLocalFile(
        int $attachment_id,
        int $deleteLocalRule,
        bool $completePendingCleanup = true
    ): bool
    {
        $pendingCleanupSnapshot = $completePendingCleanup
            ? get_post_meta($attachment_id, self::META_PENDING_LOCAL_CLEANUP, true)
            : null;

        /**
         * Fires before the local file(s) associated with an attachment are deleted.
         *
         * This action allows developers to perform tasks or logging before
         * the local files are removed following a successful cloud upload.
         *
         * @param int $attachment_id    The ID of the attachment to be processed.
         * @param int $deleteLocalRule  The rule to be applied for local file deletion:
         *                              1 - Delete only sized images, keep original.
         *                              2 - Delete all local files including the original.
         */
        do_action('advmo_before_delete_local_file', $attachment_id, $deleteLocalRule);

        $original_file = (string) get_attached_file($attachment_id, true);

        if ($original_file === '') {
            error_log("Advanced Media Offloader: Unable to determine the original file path for attachment {$attachment_id}");
            return false;
        }

        // Smart cleanup must keep the main file. A missing main file in that
        // mode is not a successful cleanup. Full migration is idempotent, so a
        // main file deleted by an interrupted earlier attempt is acceptable.
        if ($deleteLocalRule !== 2 && !file_exists($original_file)) {
            error_log("Advanced Media Offloader: Original file not found for deletion: $original_file");
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $file_dir = trailingslashit(dirname($original_file));
        $cleanupSucceeded = true;
        
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $sizeinfo) {
                // Get all files for this size, including sources (Modern Image Formats)
                $size_files = $this->getFilesFromSizeData($sizeinfo);
                
                foreach ($size_files as $size_file) {
                    $sized_file = $file_dir . $size_file;
                    if (file_exists($sized_file)) {
                        wp_delete_file($sized_file);
                        if (file_exists($sized_file)) {
                            $cleanupSucceeded = false;
                        }
                    }
                }
            }
        }

        // Image edits move the previous generation out of the current metadata
        // and into _wp_attachment_backup_sizes. Apply the same retention policy
        // to that generation after it has been uploaded: Smart Cleanup removes
        // only backup thumbnails, while Full Migration removes every backup.
        foreach ($this->getImageEditBackupFiles($attachment_id) as $backup_file => $keep_for_smart_cleanup) {
            if ($deleteLocalRule === 1 && $keep_for_smart_cleanup) {
                continue;
            }

            $backup_path = $file_dir . $backup_file;
            if (file_exists($backup_path)) {
                wp_delete_file($backup_path);
                if (file_exists($backup_path)) {
                    $cleanupSucceeded = false;
                }
            }
        }

        if ($deleteLocalRule === 2) {
            if (file_exists($original_file)) {
                wp_delete_file($original_file);
                if (file_exists($original_file)) {
                    $cleanupSucceeded = false;
                }
            }
            
            // Handle original image if exists (For scaled or processed images)
            if (!empty($metadata['original_image'])) {
                $original_image_path = wp_get_original_image_path($attachment_id);
                if ($original_image_path && file_exists($original_image_path)) {
                    wp_delete_file($original_image_path);
                    if (file_exists($original_image_path)) {
                        $cleanupSucceeded = false;
                    }
                }
            }
            
            // Delete root-level source files (Modern Image Formats support)
            // Only process if metadata is a valid array (may be false for non-image files)
            $root_source_files = is_array($metadata) ? $this->getRootSourceFiles($metadata) : [];
            foreach ($root_source_files as $source_file) {
                $source_path = $file_dir . $source_file;
                if (file_exists($source_path)) {
                    wp_delete_file($source_path);
                    if (file_exists($source_path)) {
                        $cleanupSucceeded = false;
                    }
                }
            }
        }

        if (!$cleanupSucceeded) {
            error_log("Advanced Media Offloader: Local cleanup incomplete for attachment {$attachment_id}");
            return false;
        }

        if (!$this->persistAttachmentMeta($attachment_id, 'advmo_retention_policy', $deleteLocalRule)) {
            error_log("Advanced Media Offloader: Failed to save the applied retention policy for attachment {$attachment_id}");
            return false;
        }

        /**
         * Fires after the local file(s) associated with an attachment have been deleted.
         *
         * This action allows developers to perform additional tasks or logging after
         * the local files have been removed following a successful cloud upload.
         *
         * @param int $attachment_id    The ID of the attachment that was processed.
         * @param int $deleteLocalRule  The rule applied for local file deletion:
         *                              1 - Delete only sized images, keep original.
         *                              2 - Delete all local files including the original.
         */
        do_action('advmo_after_delete_local_file', $attachment_id, $deleteLocalRule);

        if ($completePendingCleanup && is_array($pendingCleanupSnapshot) && !empty($pendingCleanupSnapshot)) {
            if (!$this->completePendingLocalCleanup($attachment_id, $pendingCleanupSnapshot)) {
                return false;
            }
        }

        return true;
    }

    protected function attachment_exists_on_disk($attachment_id)
    {
        $errors = array();

        // Get the full path to the attachment file
        $file_path = get_attached_file($attachment_id);

        // Check if the main file exists
        if (!file_exists($file_path)) {
            $errors[] = "Main file does not exist: {$file_path}";
        }

        // If it's an image, check all sizes
        if (wp_attachment_is_image($attachment_id)) {
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($metadata['sizes'])) {
                $upload_dir = wp_upload_dir();
                $base_dir = trailingslashit($upload_dir['basedir']);
                $file_dir = trailingslashit(dirname($file_path));

                foreach ($metadata['sizes'] as $size => $size_info) {
                    $size_file_path = $file_dir . $size_info['file'];
                    if (!file_exists($size_file_path)) {
                        $errors[] = "Size '{$size}' does not exist: {$size_file_path}";
                    }
                }
            }
        }

        // Save errors to post meta
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->appendAttachmentError($attachment_id, $error);
            }
        } else {
            // If there are no errors, remove any existing error log
            delete_post_meta($attachment_id, 'advmo_error_log');
        }

        // Return true if no errors, false otherwise
        return empty($errors);
    }
}
