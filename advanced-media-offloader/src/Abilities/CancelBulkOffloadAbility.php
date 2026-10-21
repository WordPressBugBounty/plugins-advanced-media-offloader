<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use Advanced_Media_Offloader\BulkOffloadHandler;
use AdvancedMediaOffloader\Foundation\Application;
use Throwable;
use WP_Error;

class CancelBulkOffloadAbility extends AbstractAbility
{
    public function __construct(private Application $app)
    {
    }

    public function name(): string
    {
        return 'advmo/cancel-bulk-offload';
    }

    protected function definition(): array
    {
        return [
            'label' => __('Cancel bulk offload', 'advanced-media-offloader'),
            'description' => __('Request cancellation of the specified bulk run. The active upload may finish; remaining work stops afterward. Does not undo uploaded files or restore deleted local copies. Rejects an ID belonging to another run.', 'advanced-media-offloader'),
            'input_schema' => self::objectSchema([
                'run_id' => [
                    'type' => 'string',
                    'minLength' => 36,
                    'maxLength' => 36,
                    'pattern' => '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$',
                ],
            ]),
            'output_schema' => self::objectSchema([
                'run_id' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['starting', 'processing', 'paused', 'cancelling', 'completed', 'cancelled']],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'processed' => ['type' => 'integer', 'minimum' => 0],
                'errors' => ['type' => 'integer', 'minimum' => 0],
                'oversized_skipped' => ['type' => 'integer', 'minimum' => 0],
                'cancel_requested' => ['type' => 'boolean'],
            ]),
        ];
    }

    protected function annotations(): array
    {
        return ['readonly' => false, 'destructive' => false, 'idempotent' => true];
    }

    protected function run(mixed $input): array|WP_Error
    {
        if (!$this->canExecute($input)) {
            return new WP_Error('advmo_bulk_forbidden', __('You cannot cancel bulk offload.', 'advanced-media-offloader'), ['status' => 403]);
        }
        try {
            return $this->app->get(BulkOffloadHandler::class)->cancel_run($input['run_id']);
        } catch (Throwable) {
            return new WP_Error('advmo_bulk_cancel_failed', __('Cancellation could not be recorded. Refresh the run status before retrying.', 'advanced-media-offloader'), ['status' => 500]);
        }
    }
}
