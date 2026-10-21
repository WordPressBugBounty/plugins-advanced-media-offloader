<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

use wpdb;

class BulkQueueStore
{
    public function __construct(private wpdb $database)
    {
    }

    public function batches(string $identifier, int $limit = 0): array
    {
        $sql = "SELECT option_name, option_value FROM {$this->database->options} WHERE option_name LIKE %s ORDER BY option_id ASC";
        $args = [$this->database->esc_like($identifier . '_batch_') . '%'];
        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $args[] = $limit;
        }
        return $this->database->get_results($this->database->prepare($sql, $args));
    }

    public function hasLegacyNetworkQueue(string $identifier): bool
    {
        if (!is_multisite()) {
            return false;
        }
        // Old network batches contain IDs only, so their originating site cannot be inferred safely.
        return (bool) $this->database->get_var($this->database->prepare(
            "SELECT meta_id FROM {$this->database->sitemeta} WHERE site_id = %d AND (meta_key LIKE %s OR meta_key = %s) LIMIT 1",
            get_current_network_id(),
            $this->database->esc_like($identifier . '_batch_') . '%',
            $identifier . '_status'
        ));
    }
}
