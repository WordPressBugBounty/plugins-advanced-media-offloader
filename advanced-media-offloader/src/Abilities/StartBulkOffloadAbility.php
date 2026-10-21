<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use Advanced_Media_Offloader\BulkOffloadHandler;
use AdvancedMediaOffloader\Foundation\Application;
use Throwable;
use WP_Error;

class StartBulkOffloadAbility extends AbstractAbility
{
    public function __construct(private Application $app)
    {
    }

    public function name(): string
    {
        return 'advmo/start-bulk-offload';
    }

    protected function definition(): array
    {
        return [
            'label' => __('Start bulk offload', 'advanced-media-offloader'),
            'description' => __('Queue up to 50 attachments using the saved provider and retention policy, which may delete local copies. Returns the active run on repeated requests. Use the run ID to monitor or cancel this work.', 'advanced-media-offloader'),
            'input_schema' => ['type' => 'null'],
            'output_schema' => self::objectSchema([
                'run_id' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['starting', 'processing', 'paused', 'cancelling', 'completed', 'cancelled']],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'processed' => ['type' => 'integer', 'minimum' => 0],
                'errors' => ['type' => 'integer', 'minimum' => 0],
                'oversized_skipped' => ['type' => 'integer', 'minimum' => 0],
                'already_running' => ['type' => 'boolean'],
            ]),
        ];
    }

    protected function annotations(): array
    {
        return ['readonly' => false, 'destructive' => true, 'idempotent' => false];
    }

    protected function run(mixed $input): array|WP_Error
    {
        if (!$this->canExecute($input)) {
            return new WP_Error('advmo_bulk_forbidden', __('You cannot start bulk offload.', 'advanced-media-offloader'), ['status' => 403]);
        }
        try {
            return $this->app->get(BulkOffloadHandler::class)->start_run();
        } catch (Throwable) {
            return new WP_Error('advmo_bulk_start_failed', __('Bulk offload could not be started. Refresh its status before retrying.', 'advanced-media-offloader'), ['status' => 500]);
        }
    }
}
