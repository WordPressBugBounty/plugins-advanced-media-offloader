<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use AdvancedMediaOffloader\Foundation\Container;

class AbilityRegistrar
{
    public function __construct(private Container $container)
    {
    }

    public function registerHooks(): void
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    public function registerCategory(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category('advmo-media', [
            'label' => __('Media Offloader', 'advanced-media-offloader'),
            'description' => __('Cloud media diagnostics for Advanced Media Offloader.', 'advanced-media-offloader'),
        ]);
    }

    public function registerAbilities(): void
    {
        foreach ($this->container->tagged('advmo.abilities') as $ability) {
            $ability->register();
        }
    }
}
