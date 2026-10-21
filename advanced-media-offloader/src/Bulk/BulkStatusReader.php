<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

class BulkStatusReader
{
    public function read(): array
    {
        $data = get_option('advmo_bulk_offload_data', []);
        $data = is_array($data) ? $data : [];
        $run = get_option('advmo_bulk_offload_run', null);
        $runId = null;
        if (is_array($run) && BulkRunStore::isValid($run)) {
            $runId = $run['id'];
            $processed = max(0, (int) ($run['baseline_processed'] ?? 0)) + count($run['results']);
            $data = [
                'status' => $run['status'] ?? '', 'processed' => $processed,
                'total' => max($processed, (int) ($run['total'] ?? 0)),
                'errors' => max(0, (int) ($run['baseline_errors'] ?? 0)) + count(array_filter($run['results'], static fn ($success): bool => $success !== true)),
                'oversized_skipped' => $run['oversized_skipped'] ?? 0, 'last_update' => $run['updated_at'] ?? null,
            ];
        }
        $status = $data['status'] ?? '';
        $statuses = ['starting', 'processing', 'paused', 'cancelling', 'completed', 'cancelled', 'timeout_cleanup', 'recovered_from_stall'];
        $status = in_array($status, $statuses, true) ? $status : ($status === '' ? 'idle' : 'unknown');

        if (in_array($status, ['starting', 'processing'], true) && (int) get_option('advmo_bulk_offload_media_process_status', 0) === 2) {
            $status = 'paused';
        }
        if (in_array($status, ['starting', 'processing', 'paused'], true) && get_option('advmo_bulk_offload_cancelled', false)) {
            $status = 'cancelling';
        }

        $counts = [];
        foreach (['processed', 'total', 'errors', 'oversized_skipped'] as $key) {
            $counts[$key] = is_numeric($data[$key] ?? null) ? max(0, (int) $data[$key]) : 0;
        }

        return [
            'status' => $status,
            'run_id' => $runId,
            ...$counts,
            'last_update' => is_numeric($data['last_update'] ?? null) ? max(0, (int) $data['last_update']) : null,
        ];
    }
}
