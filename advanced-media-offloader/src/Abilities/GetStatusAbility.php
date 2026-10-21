<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use AdvancedMediaOffloader\Diagnostics\StatusReader;

class GetStatusAbility extends AbstractReadAbility
{
    public function __construct(private StatusReader $status)
    {
    }

    public function name(): string
    {
        return 'advmo/get-status';
    }

    protected function definition(): array
    {
        $connection = self::objectSchema([
            'success' => ['type' => 'boolean'],
            'checked_at' => ['type' => ['integer', 'null']],
            'message' => ['type' => 'string'],
            'hint' => ['type' => 'string'],
            'code' => ['type' => 'string'],
        ]);
        $connection['type'] = ['object', 'null'];

        return [
            'label' => __('Get offloader status', 'advanced-media-offloader'),
            'description' => __('Read settings, media counts, and the last saved connection test. Does not contact cloud storage or verify cloud objects.', 'advanced-media-offloader'),
            'input_schema' => ['type' => 'null'],
            'output_schema' => self::objectSchema([
                'version' => ['type' => 'string'],
                'provider' => ['type' => 'string'],
                'provider_available' => ['type' => 'boolean'],
                'last_connection_test' => $connection,
                'settings' => self::objectSchema([
                    'auto_offload_uploads' => ['type' => 'boolean'],
                    'retention_policy' => ['type' => 'integer', 'enum' => [0, 1, 2]],
                    'mirror_delete' => ['type' => 'boolean'],
                    'object_versioning' => ['type' => 'boolean'],
                ]),
                'counts' => self::objectSchema([
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'offloaded' => ['type' => 'integer', 'minimum' => 0],
                    'not_offloaded' => ['type' => 'integer', 'minimum' => 0],
                    'errors' => ['type' => 'integer', 'minimum' => 0],
                ]),
            ]),
        ];
    }

    protected function run(mixed $input): array
    {
        return $this->status->read();
    }
}
