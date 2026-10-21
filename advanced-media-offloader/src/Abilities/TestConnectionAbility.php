<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use AdvancedMediaOffloader\Diagnostics\DiagnosticMessages;
use AdvancedMediaOffloader\Storage\ConnectionTester;
use WP_Error;

class TestConnectionAbility extends AbstractAbility
{
    public function __construct(private ConnectionTester $tester, private DiagnosticMessages $messages)
    {
    }

    public function name(): string
    {
        return 'advmo/test-connection';
    }

    protected function definition(): array
    {
        return [
            'label' => __('Test cloud connection', 'advanced-media-offloader'),
            'description' => __('Test access to the saved cloud provider and save the result. Checks bucket access, not upload, deletion, or public media delivery. Does not modify media files.', 'advanced-media-offloader'),
            'input_schema' => ['type' => 'null'],
            'output_schema' => self::objectSchema([
                'provider' => ['type' => 'string'],
                'success' => ['type' => 'boolean'],
                'checked_at' => ['type' => 'integer'],
                'message' => ['type' => 'string'],
                'hint' => ['type' => 'string'],
                'code' => ['type' => 'string'],
            ]),
        ];
    }

    protected function annotations(): array
    {
        return ['readonly' => false, 'destructive' => false, 'idempotent' => false];
    }

    protected function run(mixed $input): array|WP_Error
    {
        $status = $this->tester->test();
        if (is_wp_error($status)) {
            return $status;
        }

        return [
            'provider' => $status['provider'],
            'success' => $status['success'],
            'checked_at' => $status['time'],
            'message' => $this->messages->text($status['message']),
            'hint' => $this->messages->text($status['hint']),
            'code' => $this->messages->text($status['code']),
        ];
    }
}
