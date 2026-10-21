<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use WP_Error;

abstract class AbstractAbility
{
    abstract public function name(): string;

    abstract protected function definition(): array;

    abstract protected function annotations(): array;

    abstract protected function run(mixed $input): array|WP_Error;

    public function execute(mixed $input = null): array|WP_Error
    {
        $input = $this->normalizeInput($input);

        return is_wp_error($input) ? $input : $this->run($input);
    }

    public function canExecute(mixed $input = null): bool
    {
        return current_user_can('manage_options');
    }

    public function register(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        wp_register_ability($this->name(), array_merge($this->definition(), [
            'category' => 'advmo-media',
            'permission_callback' => [$this, 'canExecute'],
            'execute_callback' => [$this, 'execute'],
            'meta' => [
                'public' => true,
                'show_in_rest' => true,
                'annotations' => $this->annotations(),
            ],
        ]));
    }

    protected static function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    protected function normalizeInput(mixed $input): mixed
    {
        // WordPress validates numeric strings without casting them for typed services.
        return rest_sanitize_value_from_schema($input, $this->definition()['input_schema']);
    }
}
