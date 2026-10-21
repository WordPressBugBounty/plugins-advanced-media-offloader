<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use AdvancedMediaOffloader\Bulk\BulkStatusReader;

class GetBulkOffloadStatusAbility extends AbstractReadAbility
{
    public function __construct(private BulkStatusReader $status)
    {
    }

    public function name(): string
    {
        return 'advmo/get-bulk-offload-status';
    }

    protected function definition(): array
    {
        return [
            'label' => __('Get bulk offload status', 'advanced-media-offloader'),
            'description' => __('Read the current or last bulk offload run without changing its queue, locks, or cancellation state.', 'advanced-media-offloader'),
            'input_schema' => ['type' => 'null'],
            'output_schema' => self::objectSchema([
                'status' => ['type' => 'string', 'enum' => ['idle', 'starting', 'processing', 'paused', 'cancelling', 'completed', 'cancelled', 'timeout_cleanup', 'recovered_from_stall', 'unknown']],
                'run_id' => ['type' => ['string', 'null']],
                'processed' => ['type' => 'integer', 'minimum' => 0],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'errors' => ['type' => 'integer', 'minimum' => 0],
                'oversized_skipped' => ['type' => 'integer', 'minimum' => 0],
                'last_update' => ['type' => ['integer', 'null']],
            ]),
        ];
    }

    protected function run(mixed $input): array
    {
        return $this->status->read();
    }
}
