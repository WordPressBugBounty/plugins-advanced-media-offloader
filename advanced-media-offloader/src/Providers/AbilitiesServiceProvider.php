<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Providers;

use AdvancedMediaOffloader\Abilities\AbilityRegistrar;
use AdvancedMediaOffloader\Abilities\GetAttachmentStatusAbility;
use AdvancedMediaOffloader\Abilities\GetBulkOffloadStatusAbility;
use AdvancedMediaOffloader\Abilities\GetStatusAbility;
use AdvancedMediaOffloader\Abilities\ListOffloadErrorsAbility;
use AdvancedMediaOffloader\Abilities\TestConnectionAbility;
use AdvancedMediaOffloader\Abilities\OffloadAttachmentsAbility;
use AdvancedMediaOffloader\Abilities\ReoffloadAttachmentsAbility;
use AdvancedMediaOffloader\Abilities\StartBulkOffloadAbility;
use AdvancedMediaOffloader\Abilities\CancelBulkOffloadAbility;
use AdvancedMediaOffloader\Diagnostics\DiagnosticMessages;
use AdvancedMediaOffloader\Foundation\ServiceProvider;

class AbilitiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AbilityRegistrar::class);
        $this->app->singleton(DiagnosticMessages::class);

        $abilities = [
            GetStatusAbility::class,
            GetAttachmentStatusAbility::class,
            ListOffloadErrorsAbility::class,
            GetBulkOffloadStatusAbility::class,
            TestConnectionAbility::class,
            OffloadAttachmentsAbility::class,
            ReoffloadAttachmentsAbility::class,
            StartBulkOffloadAbility::class,
            CancelBulkOffloadAbility::class,
        ];

        foreach ($abilities as $ability) {
            $this->app->singleton($ability);
            $this->app->tag($ability, 'advmo.abilities');
        }
    }

    public function boot(): void
    {
        if (function_exists('wp_register_ability') && function_exists('wp_register_ability_category')) {
            $this->app->get(AbilityRegistrar::class)->registerHooks();
        }
    }
}
