<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

class BulkWorkerRequest
{
    public function __construct(private BulkRunStore $runs)
    {
    }

    public function arguments(string $identifier): array
    {
        $run = $this->runs->read();
        if ($run === null) {
            return [];
        }
        return ['advmo_run' => $run['id'], 'advmo_token' => $this->signature($identifier, $run['id'])];
    }

    public function authorize(string $identifier): bool
    {
        if (isset($_REQUEST['advmo_run']) || isset($_REQUEST['advmo_token'])) {
            $runId = $_REQUEST['advmo_run'] ?? null;
            $token = $_REQUEST['advmo_token'] ?? null;
            $run = $this->runs->read();
            return is_string($runId) && is_string($token) && $run !== null && $run['id'] === $runId
                && hash_equals($this->signature($identifier, $runId), $token);
        }
        // Retain the existing cookie/nonce path for already-dispatched 4.x requests.
        return (bool) check_ajax_referer($identifier, 'nonce');
    }

    private function signature(string $identifier, string $runId): string
    {
        return hash_hmac('sha256', get_current_blog_id() . ':' . $identifier . ':' . $runId, wp_salt('nonce'));
    }
}
