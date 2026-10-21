<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

use Advanced_Media_Offloader\Abstracts\WP_Background_Processing\WP_Background_Process;
use Advanced_Media_Offloader\Services\BulkMediaOffloader;

abstract class BulkProcess extends WP_Background_Process
{
    private bool $dispatchAfterWorker = false;
    private ?string $runId = null;
    private ?int $counterAttachmentId = null;

    public function save()
    {
        if (!empty($this->data)) {
            update_option($this->generate_key(), $this->data, false);
        }
        $this->data = [];
        return $this;
    }

    public function update($key, $data)
    {
        if (!empty($data)) {
            update_option($key, $data, false);
        }
        return $this;
    }

    public function delete($key)
    {
        delete_option($key);
        return $this;
    }

    public function get_batches($limit = 0)
    {
        $items = advmo()->app()->get(BulkQueueStore::class)->batches($this->identifier, is_int($limit) ? max(0, $limit) : 0);
        return array_map(function ($item) {
            $batch = new \stdClass();
            $batch->key = $item->option_name;
            $batch->data = static::maybe_unserialize($item->option_value, $this->allowed_batch_data_classes);
            return $batch;
        }, $items);
    }

    public function delete_all()
    {
        foreach ($this->get_batches() as $batch) {
            $this->delete($batch->key);
        }
        delete_option($this->get_status_key());
        $this->cancelled();
    }

    public function requestCancellation(): void
    {
        update_option($this->get_status_key(), self::STATUS_CANCELLED, false);
    }

    public function clearCancellation(): void
    {
        delete_option($this->get_status_key());
    }

    public function currentRunId(): ?string
    {
        return $this->runId;
    }

    public function currentAttachmentId(): ?int
    {
        return $this->counterAttachmentId;
    }

    public function recordAttachmentResult(int $attachmentId, bool $success, callable $increment): bool|\WP_Error
    {
        $this->counterAttachmentId = $attachmentId;
        try {
            $increment($success);
        } finally {
            $this->counterAttachmentId = null;
        }
        return advmo()->app()->get(BulkOffloadService::class)->record($this->runId ?? '', $attachmentId, $success);
    }

    public function cancel()
    {
        $this->requestCancellation();
        $this->dispatch();
    }

    public function is_cancelled()
    {
        // Cancellation is written by a different request while the worker is uploading.
        $this->refreshStatus();
        return absint(get_option($this->get_status_key(), 0)) === self::STATUS_CANCELLED;
    }

    private function refreshStatus(): void
    {
        foreach ([$this->get_status_key(), 'alloptions', 'notoptions'] as $key) {
            wp_cache_delete($key, 'options');
        }
    }

    public function pause()
    {
        update_option($this->get_status_key(), self::STATUS_PAUSED, false);
    }

    public function is_paused()
    {
        $this->refreshStatus();
        return absint(get_option($this->get_status_key(), 0)) === self::STATUS_PAUSED;
    }

    public function resume()
    {
        delete_option($this->get_status_key());
        $this->schedule_event();
        $this->dispatch();
        $this->resumed();
    }

    public function is_processing()
    {
        $locks = advmo()->app()->get(BulkOperationLock::class);
        return (bool) get_transient($this->identifier . '_process_lock')
            || (!$locks->isHeld('worker') && $locks->isBusy('worker'));
    }

    protected function lock_process()
    {
        $this->start_time = time();
        $duration = property_exists($this, 'queue_lock_time') ? $this->queue_lock_time : 60;
        $duration = apply_filters($this->identifier . '_queue_lock_time', $duration);
        set_transient($this->identifier . '_process_lock', microtime(), $duration);
    }

    protected function unlock_process()
    {
        delete_transient($this->identifier . '_process_lock');
        return $this;
    }

    public function dispatch()
    {
        if (advmo()->app()->get(BulkOperationLock::class)->isHeld('worker')) {
            $this->dispatchAfterWorker = true;
            return false;
        }
        return parent::dispatch();
    }

    protected function get_query_args()
    {
        $args = parent::get_query_args();
        if ($this instanceof BulkMediaOffloader) {
            $args = array_merge($args, advmo()->app()->get(BulkWorkerRequest::class)->arguments($this->identifier));
        }
        return $args;
    }

    public function maybe_handle()
    {
        // The old handler checked cancellation and deleted batches before authenticating.
        if ($this instanceof BulkMediaOffloader) {
            if (!advmo()->app()->get(BulkWorkerRequest::class)->authorize($this->identifier)) {
                return wp_die(-1, 403);
            }
        } else {
            check_ajax_referer($this->identifier, 'nonce');
        }
        session_write_close();
        if (advmo()->app()->get(BulkQueueStore::class)->hasLegacyNetworkQueue($this->identifier)) {
            return $this->maybe_wp_die();
        }
        $suppressExit = static fn (): bool => false;
        add_filter($this->identifier . '_wp_die', $suppressExit, PHP_INT_MAX);
        try {
            $result = advmo()->app()->get(BulkOperationLock::class)->run('worker', function () {
                // A run may have changed between authentication and acquiring the worker lock.
                if (
                    $this instanceof BulkMediaOffloader && (isset($_REQUEST['advmo_run']) || isset($_REQUEST['advmo_token']))
                    && !advmo()->app()->get(BulkWorkerRequest::class)->authorize($this->identifier)
                ) {
                    return null;
                }
                if ($this instanceof BulkMediaOffloader && ($this->is_queued() || $this->is_cancelled())) {
                    $runId = advmo()->app()->get(BulkOffloadService::class)->prepareWorker($this);
                    if (is_wp_error($runId)) {
                        return $runId;
                    }
                    $this->runId = $runId;
                }
                if ($this->is_cancelled()) {
                    $this->clear_scheduled_event();
                    $this->unlock_process();
                    $this->delete_all();
                    return null;
                }
                if ($this->is_processing()) {
                    return null;
                }
                if ($this->is_paused()) {
                    $this->clear_scheduled_event();
                    $this->paused();
                    return null;
                }
                if ($this->is_queue_empty()) {
                    $this->clear_scheduled_event();
                    return null;
                }
                return parent::handle();
            });
        } finally {
            remove_filter($this->identifier . '_wp_die', $suppressExit, PHP_INT_MAX);
        }
        if ($this->dispatchAfterWorker) {
            $this->dispatchAfterWorker = false;
            $this->dispatch();
        }
        return $this->maybe_wp_die($result);
    }

    protected function complete()
    {
        delete_option($this->get_status_key());
        $this->clear_scheduled_event();
        $this->completed();
    }
}
