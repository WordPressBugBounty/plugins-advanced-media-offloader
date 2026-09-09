<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;

class AttachmentUploadObserver implements ObserverInterface
{
    private const PENDING_CLEANUP_CRON_HOOK = 'advmo_retry_pending_local_cleanup';
    private const PENDING_CLEANUP_CRON_SCHEDULE = 'advmo_pending_cleanup_fifteen_min';
    private const PENDING_CLEANUP_CURSOR_OPTION = 'advmo_pending_cleanup_cursor';
    private const PENDING_CLEANUP_BATCH_SIZE = 50;

    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($cloudProvider);
    }

    public function register(): void
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'run'], 99, 2);
        add_filter('cron_schedules', [$this, 'addPendingCleanupCronSchedule']);
        add_action('init', [$this, 'schedulePendingCleanupRetry']);
        add_action(self::PENDING_CLEANUP_CRON_HOOK, [$this, 'retryPendingLocalCleanup']);
    }

    public function addPendingCleanupCronSchedule(array $schedules): array
    {
        if (!isset($schedules[self::PENDING_CLEANUP_CRON_SCHEDULE])) {
            $schedules[self::PENDING_CLEANUP_CRON_SCHEDULE] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes', 'advanced-media-offloader'),
            ];
        }

        return $schedules;
    }

    public function schedulePendingCleanupRetry(): void
    {
        if (!wp_next_scheduled(self::PENDING_CLEANUP_CRON_HOOK)) {
            wp_schedule_event(
                time() + (5 * MINUTE_IN_SECONDS),
                self::PENDING_CLEANUP_CRON_SCHEDULE,
                self::PENDING_CLEANUP_CRON_HOOK
            );
        }
    }

    /**
     * Retry a bounded, rotating batch so one permanently deferred attachment
     * cannot starve every newer pending cleanup record.
     */
    public function retryPendingLocalCleanup(): void
    {
        $attachmentIds = $this->getPendingCleanupAttachmentIds();

        foreach ($attachmentIds as $attachmentId) {
            try {
                $this->cloudAttachmentUploader->resumePendingLocalCleanup($attachmentId);

                // A hydration-only record means regeneration stopped before it
                // could stage and commit cleanup. Keep that restored source for a
                // safe retry. Successful regeneration cleanup clears hydration from
                // completePendingLocalCleanup(); a de-offloaded attachment only needs
                // its stale bookkeeping removed without deleting the local file.
                if (
                    !(bool) get_post_meta($attachmentId, 'advmo_offloaded', true)
                    || $this->cloudAttachmentUploader->isHydratedRegenerationCleanupReady($attachmentId)
                ) {
                    $this->cloudAttachmentUploader->cleanupHydratedRegenerationFiles($attachmentId);
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'ADVMO: Pending local cleanup retry failed for attachment %d: %s',
                    $attachmentId,
                    $e->getMessage()
                ));
            }
        }

        if (count($attachmentIds) === self::PENDING_CLEANUP_BATCH_SIZE) {
            update_option(
                self::PENDING_CLEANUP_CURSOR_OPTION,
                (int) end($attachmentIds),
                false
            );
        } else {
            delete_option(self::PENDING_CLEANUP_CURSOR_OPTION);
        }
    }

    /**
     * @return int[]
     */
    private function getPendingCleanupAttachmentIds(): array
    {
        $cursor = (int) get_option(self::PENDING_CLEANUP_CURSOR_OPTION, 0);
        $attachmentIds = $this->queryPendingCleanupAttachmentIds($cursor);

        // Wrap the cursor after the last page. This also recovers if the row
        // referenced by a saved cursor was deleted between cron runs.
        if (empty($attachmentIds) && $cursor > 0) {
            delete_option(self::PENDING_CLEANUP_CURSOR_OPTION);
            $attachmentIds = $this->queryPendingCleanupAttachmentIds(0);
        }

        return $attachmentIds;
    }

    /**
     * @return int[]
     */
    private function queryPendingCleanupAttachmentIds(int $afterId): array
    {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN (%s, %s, %s)
            AND pm.post_id > %d
            AND p.post_type = 'attachment'
            ORDER BY pm.post_id ASC
            LIMIT %d",
            CloudAttachmentUploader::META_PENDING_LOCAL_CLEANUP,
            '_advmo_ewww_deferred_retention',
            CloudAttachmentUploader::META_REGENERATION_HYDRATED_FILES,
            $afterId,
            self::PENDING_CLEANUP_BATCH_SIZE
        );

        return array_map('intval', $wpdb->get_col($query)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
    }

    public function run($metadata, $attachment_id)
    {
        // Check if auto-offload is enabled in settings
        $options = get_option('advmo_settings', []);
        $auto_offload_enabled = isset($options['auto_offload_uploads']) ? (int) $options['auto_offload_uploads'] : 1;
        
        if (!$auto_offload_enabled) {
            return $metadata;
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
            return $metadata;
        }

        if (!$this->cloudAttachmentUploader->uploadAttachment($attachment_id)) {
            // Log the failure but do NOT delete the attachment
            // The error is already logged by CloudAttachmentUploader
            error_log(sprintf(
                'ADVMO: Failed to offload attachment ID %d. Attachment preserved locally.',
                $attachment_id
            ));
        }

        return $metadata;
    }
}
