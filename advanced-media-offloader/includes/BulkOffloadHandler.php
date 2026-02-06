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

    # singleton
    private static $instance = null;

    public static function get_instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
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
            $this->process_all    = new BulkMediaOffloader($cloud_provider);
            add_action($this->process_all->get_identifier() . '_cancelled', array($this, 'process_is_cancelled'));

            // Check for stalled processes every 15 minutes
            add_filter('cron_schedules', [$this, 'add_cron_interval']);
            add_action('advmo_check_stalled_processes', [$this, 'check_stalled_processes']);

            if (!wp_next_scheduled('advmo_check_stalled_processes')) {
                wp_schedule_event(time(), 'advmo_fifteen_min', 'advmo_check_stalled_processes');
            }
        } catch (\Exception $e) {
            error_log('ADVMO - Error: ' . $e->getMessage());
        }
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
        if (! $this->process_all instanceof BulkMediaOffloader) {
            return;
        }

        // Check if a process is locked but not updating
        $process_lock = get_site_transient($this->process_all->get_identifier() . '_process_lock');
        $bulk_data = advmo_get_bulk_offload_data();
        $last_update = isset($bulk_data['last_update']) ? (int) $bulk_data['last_update'] : 0;

        // Falls back to legacy option for backwards compatibility.
        if (0 === $last_update) {
            $last_update = (int) get_option('advmo_bulk_offload_last_update', 0);
        }
        $current_time = time();

        // If process locked but hasn't updated in 10 minutes
        if ($process_lock && ($current_time - $last_update) > 600) {

            // Get current bulk offload data for logging
            $bulk_data = advmo_get_bulk_offload_data();

            error_log(sprintf(
                'ADVMO: Detected stalled bulk offload process. Lock time: %s, Last update: %s, Processed: %d/%d',
                date('Y-m-d H:i:s', $process_lock),
                date('Y-m-d H:i:s', $last_update),
                $bulk_data['processed'] ?? 0,
                $bulk_data['total'] ?? 0
            ));

            // Clear all process-related transients and options
            $this->force_unlock_process();

            // Update status with recovery information
            advmo_update_bulk_offload_data([
                'status' => 'recovered_from_stall',
                'last_recovery' => $current_time
            ]);

            // Schedule a cleanup of any orphaned queue items
            wp_schedule_single_event(time() + 60, 'advmo_cleanup_orphaned_queue');
        }

        // Also check for very old processes (over 1 hour) regardless of lock status
        if ($last_update > 0 && ($current_time - $last_update) > 3600) {
            $bulk_data = advmo_get_bulk_offload_data();

            // Only reset if status indicates it's still processing
            if (isset($bulk_data['status']) && in_array($bulk_data['status'], ['processing', 'starting'])) {
                error_log('ADVMO: Cleaning up very old bulk offload process (>1 hour)');

                $this->force_unlock_process();
                advmo_update_bulk_offload_data([
                    'status' => 'timeout_cleanup',
                    'last_cleanup' => $current_time
                ]);
            }
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
        delete_site_transient($this->process_all->get_identifier() . '_process_lock');

        // Clear batch lock
        delete_site_transient($this->process_all->get_identifier() . '_batch_lock');

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

        // Starting a new run should always reset any previous "cancel requested" flag.
        $prev_cancelled = get_option('advmo_bulk_offload_cancelled');
        if ($prev_cancelled) {
            delete_option('advmo_bulk_offload_cancelled');
        }

        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) {
            wp_send_json_error([
                'message' => $ready->get_error_message(),
                'code' => $ready->get_error_code(),
            ], 400);
        }

        try {
            $this->handle_all();
            $bulk_offload_data = advmo_get_bulk_offload_data();

            wp_send_json_success([
                'total'     => $bulk_offload_data['total'],
            ]);
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

        $bulk_offload_data = advmo_get_bulk_offload_data();
        $is_bulk_offload_cancelled = get_option("advmo_bulk_offload_cancelled");

        // Clear stale cancel flag when job is in terminal state (or status isn't actually cancelled anymore).
        $terminal_statuses = ['completed', 'cancelled', 'timeout_cleanup', 'recovered_from_stall'];
        if ($is_bulk_offload_cancelled && isset($bulk_offload_data['status']) && in_array($bulk_offload_data['status'], $terminal_statuses, true)) {
            delete_option('advmo_bulk_offload_cancelled');
            $is_bulk_offload_cancelled = get_option("advmo_bulk_offload_cancelled");
        }
        wp_send_json_success([
            'processed' => $bulk_offload_data['processed'],
            'total'     => $bulk_offload_data['total'],
            'status'    => $is_bulk_offload_cancelled ? "cancelled" : $bulk_offload_data['status'],
            'errors'    => $bulk_offload_data['errors'] ?? 0,
            'oversized_skipped' => $bulk_offload_data['oversized_skipped'] ?? 0,
        ]);
    }

    protected function handle_all()
    {
        // Nonce is already verified in bulk_offload() which calls this method
        // No need for redundant check here since handle_all() is only called internally

        $ready = $this->ensure_process_ready();
        if (is_wp_error($ready)) {
            throw new \Exception($ready->get_error_message());
        }

        $names = $this->get_unoffloaded_attachments();

        foreach ($names as $name) {
            $this->process_all->push_to_queue($name);
        }

        $this->process_all->save()->dispatch();
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

        $normal_attachments = $wpdb->get_col($query);

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

            $error_attachments = $wpdb->get_col($error_query);

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

        // Set the "cancel requested" flag BEFORE calling cancel(); cancellation may complete immediately.
        update_option("advmo_bulk_offload_cancelled", true);

        $this->process_all->cancel();

        wp_send_json_success([
            "message" => __('Bulk offload cancelled successfully.', 'advanced-media-offloader')
        ]);
    }

    public function process_is_cancelled()
    {
        advmo_update_bulk_offload_data([
            'status' => 'cancelled'
        ]);
        delete_option("advmo_bulk_offload_cancelled");
    }

    /**
     * Cleanup any orphaned queue batches and resume processing if needed.
     */
    public function cleanup_orphaned_queue()
    {
        if (! $this->process_all instanceof BulkMediaOffloader) {
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
