<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

abstract class AbstractReadAbility extends AbstractAbility
{
    protected function annotations(): array
    {
        return ['readonly' => true, 'destructive' => false, 'idempotent' => true];
    }
}
