<?php
# bulk offlaod handler
namespace Advanced_Media_Offloader;

use Advanced_Media_Offloader\Services\BulkMediaOffloader;
use Advanced_Media_Offloader\Factories\CloudProviderFactory;

class BulkOffloadHandler
{
    /**
     * @var BulkMediaOffloader|null
     */
    protected $process_all;

    /**
     * The shared handler from the container, which an add-on may have replaced with its own subclass.
     */
    public static function get_instance()
    {
        return advmo()->app()->get(self::class);
    }

    public function __construct()
    {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_advmo_check_bulk_offload_progress', array($this, 'get_progress'));
        add_action('wp_ajax_advmo_start_bulk_offload', array($this, 'bulk_offload'));
        add_action('wp_ajax_advmo_cancel_bulk_offload', array($this, 'cancel_bulk_offload'));
        add_action('advmo_cleanup_orphaned_queue', array($this, 'cleanup_orphaned_queue'));
    }

    /**
     * Init
     */
    public function init()
    {
        try {
            $cloud_provider_key = advmo_get_cloud_provider_key();

            if (empty($cloud_provider_key)) {
                throw new \Exception(__('No cloud provider is configured.', 'advanced-media-offloader'));
            }

            $cloud_provider = CloudProviderFactory::create($cloud_provider_key);
            $this->process_all    = advmo()->app()->make(BulkMediaOffloader::class, ['cloudProvider' => $cloud_provider]);
            add_action($this->process_all->get_identifier() . '_cancelled', array($this, 'process_is_cancelled'));

            // Stall check runs only while a bulk offload job is active.
            add_filter('cron_schedules', [$this, 'add_cron_interval']);
            add_action('advmo_check_stalled_processes', [$this, 'check_stalled_processes']);

            // Drop leftover always-on schedules from older plugin versions when idle.
            $bulk_data = advmo_get_bulk_offload_data();
            $active_statuses = ['processing', 'starting'];
            if (! in_array($bulk_data['status'] ?? '', $active_statuses, true)) {
                $this->clear_stalled_check();
            }
        } catch (\Exception $e) {
            error_log('ADVMO - Error: ' . $e->getMessage());
        }
    }

    /**
     * Schedule the stalled-process healthcheck for an active bulk offload job.
     */
    public function schedule_stalled_check()
    {
        if (! wp_next_scheduled('advmo_check_stalled_processes')) {
            wp_schedule_event(time(), 'advmo_fifteen_min', 'advmo_check_stalled_processes');
        }
    }

    /**
     * Clear the stalled-process healthcheck when no bulk job is in play.
     */
    public function clear_stalled_check()
    {
        wp_clear_scheduled_hook('advmo_check_stalled_processes');
    }

    public function add_cron_interval($schedules)
    {
        $schedules['advmo_fifteen_min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 minutes', 'advanced-media-offloader')
        ];
        return $schedules;
    }

    public function check_stalled_processes()
    {
        if ($this->process_all instanceof BulkMediaOffloader) {
            advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkOffloadService::class)->recover(
                $this->process_all,
                fn () => $this->get_unoffloaded_attachments()
            );
        }
    }

    /**
     * Force unlock all process locks and clean up related data.
     */
    private function force_unlock_process()
    {
        if (! $this->process_all instanceof BulkMediaOffloader) {
            return;
        }

        // Clear process lock
        delete_transient($this->process_all->get_identifier() . '_process_lock');

        // Clear batch lock
        delete_transient($this->process_all->get_identifier() . '_batch_lock');

        // Clear any process-specific options
        delete_option('advmo_bulk_offload_cancelled');

        // Cancel any scheduled cron events for this process
        wp_clear_scheduled_hook($this->process_all->get_identifier() . '_cron');

        // Restart processing if there is pending work.
        if ($this->process_all->is_queued() && ! $this->process_all->is_processing()) {
            $this->process_all->dispatch();
        }
    }


    public function bulk_offload()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permission denied', 'advanced-media-offloader')
            ], 403);
        }

        // Use check_ajax_referer which handles sanitization and verification properly
        if (!check_ajax_referer('advmo_bulk_offload', 'bulk_offload_nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'advanced-media-offloader')
            ], 403);
        }

        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) {
            wp_send_json_error([
                'message' => $ready->get_error_message(),
                'code' => $ready->get_error_code(),
            ], 400);
        }

        try {
            $result = $this->handle_all();
            if (!is_array($result) && !is_wp_error($result)) {
                // A 4.x subclass may override handle_all() without returning a value.
                $result = ['total' => advmo_get_bulk_offload_data()['total']];
            }
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], $result->get_error_data()['status'] ?? 400);
            }
            wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('ADVMO Bulk Offload Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function get_progress()
    {
        // Verify nonce
        if (!check_ajax_referer('advmo_bulk_offload', 'bulk_offload_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'advanced-media-offloader')], 403);
            return;
        }

        // Verify user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied', 'advanced-media-offloader')], 403);
            return;
        }

        $status = advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkStatusReader::class)->read();
        wp_send_json_success([
            'processed' => $status['processed'],
            'total' => $status['total'],
            'status' => $status['status'] === 'cancelling' ? 'cancelled' : $status['status'],
            'run_status' => $status['status'],
            'run_id' => $status['run_id'],
            'errors' => $status['errors'],
            'oversized_skipped' => $status['oversized_skipped'],
        ]);
    }

    protected function handle_all()
    {
        $result = $this->start_run();
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; output handlers escape the message.
        }
        return $result;
    }

    public function start_run()
    {
        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) { return $ready; }
        return advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkOffloadService::class)->start(
            $this->process_all,
            fn () => $this->get_unoffloaded_attachments()
        );
    }

    public function cancel_run(?string $run_id = null)
    {
        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) { return $ready; }
        return advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkOffloadService::class)->cancel($this->process_all, $run_id);
    }

    protected function get_unoffloaded_attachments($batch_size = 50)
    {
        global $wpdb;

        // Max batch size in MB (150MB total per batch)
        $max_batch_size_mb = 150;
        $current_batch_size = 0;
        $filtered_attachments = [];
        $oversized_files = 0;

        // First, get attachments without errors
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'advmo_offloaded'
            LEFT JOIN {$wpdb->postmeta} em ON p.ID = em.post_id AND em.meta_key = 'advmo_error_log'
            WHERE p.post_type = 'attachment' 
            AND (pm.meta_value IS NULL OR pm.meta_value = '') 
            AND em.meta_id IS NULL
            ORDER BY p.post_date ASC
            LIMIT %d",
            $batch_size * 2
        );

        $normal_attachments = $wpdb->get_col($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.

        // Process normal attachments first, with size limitations
        foreach ($normal_attachments as $attachment_id) {
            // Skip if we've hit batch size limit
            if (count($filtered_attachments) >= $batch_size) {
                break;
            }

            // Allow 3rd-parties to skip certain attachments entirely
            if (false === apply_filters('advmo_should_offload_attachment', true, $attachment_id)) {
                continue;
            }

            $file_path = get_attached_file($attachment_id);
            if (file_exists($file_path)) {
                $file_size = filesize($file_path) / (1024 * 1024); // Size in MB

                // Skip massive files entirely (> 10MB)
                if ($file_size > 25) {
                    $error_msg = sprintf(
                        /* translators: %s: maximum file size in megabytes. */
                        __('File exceeds maximum size (%s MB) for bulk processing', 'advanced-media-offloader'),
                        '25'
                    );
                    update_post_meta($attachment_id, 'advmo_error_log', $error_msg);
                    $oversized_files++;
                    continue;
                }

                // Add to batch if we're under the total size limit
                if (($current_batch_size + $file_size) <= $max_batch_size_mb) {
                    $filtered_attachments[] = $attachment_id;
                    $current_batch_size += $file_size;
                } else {
                    // We've hit the batch size limit
                    break;
                }
            } else {
                // Include files that don't exist on disk (metadata only)
                $filtered_attachments[] = $attachment_id;
            }
        }

        // If we have remaining capacity, try to process some error files
        $remaining_slots = $batch_size - count($filtered_attachments);

        // If there are remaining slots, fill them with attachments that have errors
        if ($remaining_slots > 0) {
            $error_query = $wpdb->prepare(
                "SELECT p.ID 
                FROM {$wpdb->posts} p 
                JOIN {$wpdb->postmeta} pm_error ON (p.ID = pm_error.post_id AND pm_error.meta_key = 'advmo_error_log')
                LEFT JOIN {$wpdb->postmeta} pm_offload ON (p.ID = pm_offload.post_id AND pm_offload.meta_key = 'advmo_offloaded')
                WHERE p.post_type = 'attachment'
                AND (pm_offload.meta_value IS NULL OR pm_offload.meta_value = '')
                AND pm_error.meta_value IS NOT NULL
                AND pm_error.meta_value != ''
                ORDER BY p.post_date ASC
                LIMIT %d",
                $remaining_slots * 2
            );

            $error_attachments = $wpdb->get_col($error_query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.

            // Process error attachments with size limitations
            foreach ($error_attachments as $attachment_id) {
                if (count($filtered_attachments) >= $batch_size) {
                    break;
                }

                // Allow 3rd-parties to skip certain attachments entirely
                if (false === apply_filters('advmo_should_offload_attachment', true, $attachment_id)) {
                    continue;
                }

                $file_path = get_attached_file($attachment_id);
                if (file_exists($file_path)) {
                    $file_size = filesize($file_path) / (1024 * 1024); // Size in MB

                    // Skip massive files
                    if ($file_size > 25) {
                        continue;
                    }

                    // Add if under size limit
                    if (($current_batch_size + $file_size) <= $max_batch_size_mb) {
                        $filtered_attachments[] = $attachment_id;
                        $current_batch_size += $file_size;
                    } else {
                        break;
                    }
                } else {
                    // Include non-existent files
                    $filtered_attachments[] = $attachment_id;
                }
            }
        }

        // Update bulk offload data
        $attachment_count = count($filtered_attachments);
        if ($attachment_count > 0) {
            advmo_update_bulk_offload_data(array(
                'total' => $attachment_count,
                'status' => 'processing',
                'processed' => 0,
                'errors' => 0,
                'oversized_skipped' => $oversized_files
            ));
        } else {
            advmo_clear_bulk_offload_data();
        }

        return $filtered_attachments;
    }

    public function cancel_bulk_offload()
    {
        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permission denied', 'advanced-media-offloader')
            ], 403);
        }

        // Use check_ajax_referer which handles sanitization and verification properly
        if (!check_ajax_referer('advmo_bulk_offload', 'bulk_offload_nonce', false)) {
            wp_send_json_error([
                'message' => __('Invalid nonce', 'advanced-media-offloader')
            ], 403);
        }

        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) {
            wp_send_json_error([
                'message' => $ready->get_error_message(),
                'code' => $ready->get_error_code(),
            ], 400);
        }

        $run_id = isset($_POST['run_id']) && is_string($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : null;
        $result = $this->cancel_run($run_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], $result->get_error_data()['status'] ?? 400);
        }

        wp_send_json_success([
            "message" => __('Bulk offload cancelled successfully.', 'advanced-media-offloader'),
            "run_id" => $result['run_id'],
            "status" => $result['status'],
        ]);
    }

    public function process_is_cancelled()
    {
        advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkOffloadService::class)->finish(
            $this->process_all instanceof BulkMediaOffloader ? $this->process_all->currentRunId() : null,
            true
        );
    }

    /**
     * Cleanup any orphaned queue batches and resume processing if needed.
     */
    public function cleanup_orphaned_queue()
    {
        if (! $this->process_all instanceof BulkMediaOffloader) {
            return;
        }

        if (advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkOperationLock::class)->isBusy('worker')) {
            return;
        }
        $batches = $this->process_all->get_batches();
        $has_pending_items = false;

        foreach ($batches as $batch) {
            if (empty($batch->data)) {
                $this->process_all->delete($batch->key);
                continue;
            }

            $has_pending_items = true;
        }

        if ($has_pending_items && ! $this->process_all->is_processing()) {
            $this->process_all->dispatch();
        }
    }

    /**
     * Ensure the background process is ready before handling actions.
     *
     * @return true|\WP_Error
     */
    private function ensure_process_ready()
    {
        if ($this->process_all instanceof BulkMediaOffloader) {
            if (advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkQueueStore::class)->hasLegacyNetworkQueue($this->process_all->get_identifier())) {
                return new \WP_Error(
                    'advmo_legacy_network_queue',
                    __('An older shared network queue needs review before starting bulk offload on this site.', 'advanced-media-offloader')
                );
            }
            return true;
        }

        return new \WP_Error(
            'advmo_offloader_unavailable',
            __('Bulk offload requires a configured cloud provider. Please configure one before trying again.', 'advanced-media-offloader')
        );
    }

    public function bulk_offload_cron_healthcheck()
    {
        $this->process_all->handle_cron_healthcheck();
        wp_send_json_success();
    }
}
