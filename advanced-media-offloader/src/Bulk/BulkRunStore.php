<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

use WP_Error;

class BulkRunStore
{
    public const OPTION = 'advmo_bulk_offload_run';

    public function read(): ?array
    {
        $this->refresh();
        $run = get_option(self::OPTION, null);
        return self::isValid($run) ? $run : null;
    }

    public static function isValid(mixed $run): bool
    {
        if (
            !is_array($run) || !is_string($run['id'] ?? null)
            || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i', $run['id'])
            || ($run['site_id'] ?? null) !== get_current_blog_id()
            || !in_array($run['status'] ?? null, ['starting', 'processing', 'paused', 'cancelling', 'completed', 'cancelled'], true)
            || !is_array($run['items'] ?? null) || !is_array($run['results'] ?? null) || !is_bool($run['selection_complete'] ?? null)
        ) {
            return false;
        }
        foreach (['provider_key', 'provider', 'bucket'] as $key) {
            if (!is_string($run[$key] ?? null)) {
                return false;
            }
        }
        foreach (['baseline_processed', 'baseline_errors', 'total', 'oversized_skipped', 'created_at', 'updated_at'] as $key) {
            if (!is_int($run[$key] ?? null) || $run[$key] < 0) {
                return false;
            }
        }
        foreach ($run['items'] as $id) {
            if (!is_int($id) || $id <= 0) {
                return false;
            }
        }
        foreach ($run['results'] as $id => $success) {
            if (!is_int($id) || $id <= 0 || !is_bool($success)) {
                return false;
            }
        }
        return true;
    }

    public function save(array $run): bool|WP_Error
    {
        $run['updated_at'] = time();
        $run['total'] = max((int) $run['total'], (int) $run['baseline_processed'] + count($run['results']));
        update_option(self::OPTION, $run, false);
        if ($this->read() !== $run) {
            return new WP_Error('advmo_bulk_state_not_saved', __('The bulk run state could not be saved. No new run was started.', 'advanced-media-offloader'), ['status' => 500]);
        }
        $processed = (int) $run['baseline_processed'] + count($run['results']);
        $errors = (int) $run['baseline_errors'] + count(array_filter($run['results'], static fn ($success): bool => $success !== true));
        $data = advmo_update_bulk_offload_data([
            'status' => $run['status'], 'processed' => $processed,
            'total' => max((int) $run['total'], $processed), 'errors' => $errors,
            'oversized_skipped' => $run['oversized_skipped'], 'last_update' => $run['updated_at'],
        ]);
        $this->refresh();
        if (get_option('advmo_bulk_offload_data', []) !== $data) {
            return new WP_Error('advmo_bulk_progress_not_saved', __('The run is recorded, but its progress could not be saved. Please try again.', 'advanced-media-offloader'), ['status' => 500]);
        }
        return true;
    }

    public function snapshot(array $run): array
    {
        return [
            'run_id' => $run['id'], 'status' => $run['status'], 'total' => max((int) $run['total'], (int) $run['baseline_processed'] + count($run['results'])),
            'processed' => (int) $run['baseline_processed'] + count($run['results']),
            'errors' => (int) $run['baseline_errors'] + count(array_filter($run['results'], static fn ($success): bool => $success !== true)),
            'oversized_skipped' => (int) $run['oversized_skipped'],
        ];
    }

    public function refresh(): void
    {
        foreach ([self::OPTION, 'advmo_bulk_offload_data', 'advmo_bulk_offload_cancelled', 'alloptions', 'notoptions'] as $key) {
            wp_cache_delete($key, 'options');
        }
    }
}
