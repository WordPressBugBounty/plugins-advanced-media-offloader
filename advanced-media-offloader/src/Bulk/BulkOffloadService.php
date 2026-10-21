<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

use Advanced_Media_Offloader\BulkOffloadHandler;
use Advanced_Media_Offloader\Services\BulkMediaOffloader;
use AdvancedMediaOffloader\Foundation\Application;
use AdvancedMediaOffloader\Storage\ProviderRegistry;
use Throwable;
use WP_Error;

class BulkOffloadService
{
    private const ACTIVE = ['starting', 'processing', 'paused', 'cancelling'];

    public function __construct(
        private Application $app,
        private BulkRunStore $runs,
        private BulkOperationLock $locks,
        private BulkQueueStore $queue,
        private ProviderRegistry $providers,
    ) {
    }

    public function start(BulkMediaOffloader $process, callable $select): array|WP_Error
    {
        $dispatch = false;
        $resume = false;
        try {
            $result = $this->locks->run('control', function () use ($process, $select, &$dispatch, &$resume) {
                if ($this->queue->hasLegacyNetworkQueue($process->get_identifier())) {
                    return $this->error('advmo_legacy_network_queue', __('An older shared network queue needs review before starting bulk offload.', 'advanced-media-offloader'));
                }
                $destination = $this->destination();
                if (is_wp_error($destination)) {
                    return $destination;
                }
                $run = $this->runs->read();
                $queued = $process->is_queued();
                $processing = $process->is_processing() || $this->locks->isBusy('worker');
                $active = $run !== null && in_array($run['status'], self::ACTIVE, true);
                if (!$active && ($queued || $processing)) {
                    if ($run !== null) {
                        return $this->error('advmo_bulk_queue_not_empty', __('An earlier worker or queue must finish before starting another run.', 'advanced-media-offloader'));
                    }
                    $run = $this->newRun($destination, true);
                    $active = true;
                }
                if ($active) {
                    if (!$this->matches($run, $destination)) {
                        return $this->error('advmo_bulk_destination_changed', __('Restore the original provider and bucket before resuming this bulk run.', 'advanced-media-offloader'));
                    }
                    if ($run['status'] === 'cancelling') {
                        if (!$queued && !$processing) {
                            $run['status'] = 'cancelled';
                            $saved = $this->runs->save($run);
                            if (is_wp_error($saved)) {
                                return $saved;
                            }
                            delete_option('advmo_bulk_offload_cancelled');
                        } else {
                            $dispatch = !$processing;
                        }
                        return $this->runs->snapshot($run) + ['already_running' => true];
                    }
                    if ($queued || $processing) {
                        if ($run['status'] === 'paused' || $process->is_paused()) {
                            $run['status'] = 'processing';
                            $resume = !$processing;
                        }
                        $saved = $this->runs->save($run);
                        if (is_wp_error($saved)) {
                            return $saved;
                        }
                        $dispatch = !$processing;
                        return $this->runs->snapshot($run) + ['already_running' => true];
                    }
                } else {
                    $run = $this->newRun($destination);
                }
                // Persist identity before selecting or saving any batch. A retry resumes this run.
                $saved = $this->runs->save($run);
                if (is_wp_error($saved)) {
                    return $saved;
                }
                if (!$run['selection_complete']) {
                    $ids = $select();
                    $run['items'] = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))), 0, 50);
                    $run['selection_complete'] = true;
                    $run['total'] = count($run['items']);
                    $data = advmo_get_bulk_offload_data();
                    $run['oversized_skipped'] = max(0, (int) ($data['oversized_skipped'] ?? 0));
                }
                $remaining = array_values(array_filter($run['items'], static fn (int $id): bool => !array_key_exists($id, $run['results'])));
                $run['status'] = $remaining === [] ? 'completed' : 'starting';
                $saved = $this->runs->save($run);
                if (is_wp_error($saved)) {
                    return $saved;
                }
                delete_option('advmo_bulk_offload_cancelled');
                $process->clearCancellation();
                foreach ($remaining as $id) {
                    $process->push_to_queue(['attachment_id' => $id, 'run_id' => $run['id']]);
                }
                if ($remaining !== []) {
                    $process->save();
                    if (!$process->is_queued()) {
                        return $this->error('advmo_bulk_queue_not_saved', __('The bulk queue could not be saved. Retry to resume the same run.', 'advanced-media-offloader'), 500);
                    }
                    $run['status'] = 'processing';
                    $saved = $this->runs->save($run);
                    if (is_wp_error($saved)) {
                        return $saved;
                    }
                    $dispatch = true;
                }
                return $this->runs->snapshot($run) + ['already_running' => $active];
            });
        } catch (Throwable) {
            return $this->error('advmo_bulk_start_failed', __('Bulk offload could not be started. Check its status before retrying.', 'advanced-media-offloader'), 500);
        }
        if (!is_wp_error($result) && $dispatch) {
            $this->app->get(BulkOffloadHandler::class)->schedule_stalled_check();
            $resume ? $process->resume() : $process->dispatch();
        }
        return $result;
    }

    public function cancel(BulkMediaOffloader $process, ?string $runId = null): array|WP_Error
    {
        $dispatch = false;
        $result = $this->locks->run('control', function () use ($process, $runId, &$dispatch) {
            $run = $this->runs->read();
            if ($run === null) {
                if ($runId !== null) {
                    return $this->stale();
                }
                $destination = $this->destination();
                if (is_wp_error($destination)) {
                    return $destination;
                }
                $run = $this->newRun($destination, true);
            }
            if ($runId !== null && !hash_equals($run['id'], $runId)) {
                return $this->stale();
            }
            if ($runId === null && in_array($run['status'], ['cancelled', 'completed'], true) && $process->is_queued()) {
                $destination = $this->destination();
                if (is_wp_error($destination)) {
                    return $destination;
                }
                $run = $this->newRun($destination, true);
            }
            if (in_array($run['status'], ['cancelled', 'completed'], true)) {
                return $this->runs->snapshot($run) + ['cancel_requested' => false];
            }
            if ($run['status'] !== 'cancelling') {
                $run['status'] = 'cancelling';
                $saved = $this->runs->save($run);
                if (is_wp_error($saved)) {
                    return $saved;
                }
            }
            update_option('advmo_bulk_offload_cancelled', true, false);
            $process->requestCancellation();
            $dispatch = true;
            return $this->runs->snapshot($run) + ['cancel_requested' => true];
        });
        if (!is_wp_error($result) && $dispatch) {
            $process->dispatch();
        }
        return $result;
    }

    public function prepareWorker(BulkMediaOffloader $process): string|WP_Error
    {
        return $this->locks->run('control', function () use ($process) {
            $run = $this->runs->read();
            $firstBatch = $process->get_batches(1)[0] ?? null;
            $legacyItems = $firstBatch !== null && is_array($firstBatch->data) && array_filter($firstBatch->data, 'is_array') === [];
            if ($run === null || (!in_array($run['status'], self::ACTIVE, true) && $legacyItems)) {
                $destination = $this->destination();
                if (is_wp_error($destination)) {
                    return $destination;
                }
                $run = $this->newRun($destination, true);
                $saved = $this->runs->save($run);
                if (is_wp_error($saved)) {
                    return $saved;
                }
            }
            return $run['id'];
        });
    }

    public function record(string $runId, int $attachmentId, bool $success): bool|WP_Error
    {
        if ($attachmentId <= 0) {
            return $this->error('advmo_invalid_attachment', __('The attachment ID is invalid.', 'advanced-media-offloader'), 400);
        }
        return $this->locks->run('control', function () use ($runId, $attachmentId, $success) {
            $run = $this->runs->read();
            if ($run === null || $run['id'] !== $runId || !in_array($run['status'], self::ACTIVE, true)) {
                return $this->stale();
            }
            if (array_key_exists($attachmentId, $run['results'])) {
                return true;
            }
            $run['results'][$attachmentId] = $success;
            return $this->runs->save($run);
        }, 5);
    }

    public function pause(string $runId): void
    {
        $this->locks->run('control', function () use ($runId) {
            $run = $this->runs->read();
            if ($run === null || $run['id'] !== $runId || $run['status'] === 'cancelling') {
                return;
            }
            $run['status'] = 'paused';
            $this->runs->save($run);
        }, 5);
    }

    public function increment(?string $runId, bool $success): void
    {
        $this->locks->run('control', function () use ($runId, $success) {
            $run = $this->runs->read();
            if ($run === null) {
                if ($runId !== null) {
                    return;
                }
                $data = advmo_get_bulk_offload_data();
                advmo_update_bulk_offload_data(['processed' => (int) $data['processed'] + 1, 'errors' => (int) ($data['errors'] ?? 0) + ($success ? 0 : 1)]);
                return;
            }
            $runId ??= $run['id'];
            if ($run['id'] !== $runId || !in_array($run['status'], self::ACTIVE, true)) {
                return;
            }
            $run['baseline_processed']++;
            if (!$success) {
                $run['baseline_errors']++;
            }
            $this->runs->save($run);
        });
    }

    public function finish(?string $runId, bool $cancelled = false): void
    {
        $this->locks->run('control', function () use ($runId, $cancelled) {
            $run = $this->runs->read();
            if ($run === null) {
                if ($runId === null) {
                    advmo_update_bulk_offload_data(['status' => $cancelled ? 'cancelled' : 'completed']);
                    delete_option('advmo_bulk_offload_cancelled');
                    $this->app->get(BulkOffloadHandler::class)->clear_stalled_check();
                }
                return;
            }
            $runId ??= $run['id'];
            if ($run['id'] !== $runId) {
                return;
            }
            $run['status'] = $cancelled || $run['status'] === 'cancelling' ? 'cancelled' : 'completed';
            if (is_wp_error($this->runs->save($run))) {
                return;
            }
            delete_option('advmo_bulk_offload_cancelled');
            delete_option('advmo_bulk_offload_media_process_status');
            $this->app->get(BulkOffloadHandler::class)->clear_stalled_check();
        }, 5);
    }

    public function recover(BulkMediaOffloader $process, callable $select): void
    {
        if ($this->locks->isBusy('worker')) {
            return;
        }
        $run = $this->runs->read();
        $data = advmo_get_bulk_offload_data();
        $updated = $run['updated_at'] ?? ($data['last_update'] ?? 0);
        if (!$updated || time() - (int) $updated <= 600 || ($run['status'] ?? '') === 'paused') {
            return;
        }
        if ($run === null && !$process->is_queued()) {
            return;
        }
        if ($run !== null && !in_array($run['status'], self::ACTIVE, true)) {
            return;
        }
        delete_transient($process->get_identifier() . '_process_lock');
        $this->start($process, $select);
    }

    public function destination(): array|WP_Error
    {
        $key = advmo_get_cloud_provider_key();
        if ($key === '' || !$this->providers->has($key)) {
            return $this->error('advmo_offloader_unavailable', __('Configure an available cloud provider before using bulk offload.', 'advanced-media-offloader'), 400);
        }
        $provider = $this->providers->make($key);
        $name = $provider->getProviderName();
        $bucket = $provider->getBucket();
        if (!is_string($name) || $name === '' || !is_string($bucket) || $bucket === '') {
            return $this->error('advmo_offloader_unavailable', __('The cloud destination is incomplete.', 'advanced-media-offloader'), 400);
        }
        return ['provider_key' => $key, 'provider' => $name, 'bucket' => $bucket];
    }

    public function matches(array $run, array $destination): bool
    {
        return $run['provider_key'] === $destination['provider_key'] && $run['provider'] === $destination['provider'] && $run['bucket'] === $destination['bucket'];
    }

    private function newRun(array $destination, bool $legacy = false): array
    {
        $data = $legacy ? advmo_get_bulk_offload_data() : [];
        return $destination + [
            'id' => wp_generate_uuid4(), 'site_id' => get_current_blog_id(),
            'status' => $legacy ? 'processing' : 'starting', 'created_at' => time(), 'updated_at' => time(),
            'items' => [], 'results' => [], 'selection_complete' => $legacy,
            'total' => max(0, (int) ($data['total'] ?? 0)), 'oversized_skipped' => max(0, (int) ($data['oversized_skipped'] ?? 0)),
            'baseline_processed' => max(0, (int) ($data['processed'] ?? 0)), 'baseline_errors' => max(0, (int) ($data['errors'] ?? 0)),
        ];
    }

    private function stale(): WP_Error
    {
        return $this->error('advmo_bulk_run_mismatch', __('This request belongs to another bulk run. Refresh the run status.', 'advanced-media-offloader'));
    }

    private function error(string $code, string $message, int $status = 409): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
