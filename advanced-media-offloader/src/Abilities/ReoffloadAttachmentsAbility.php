<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use WP_Error;

class ReoffloadAttachmentsAbility extends OffloadAttachmentsAbility
{
    public function name(): string
    {
        return 'advmo/reoffload-attachments';
    }

    protected function definition(): array
    {
        return array_replace(parent::definition(), [
            'label' => __('Re-offload attachments', 'advanced-media-offloader'),
            'description' => __('Overwrite the recorded cloud copies of up to 20 offloaded attachments from their current local files. Requires a matching saved provider and bucket. Does not restore missing local sources or start a background job.', 'advanced-media-offloader'),
        ]);
    }

    protected function run(mixed $input): array|WP_Error
    {
        if (!$this->canExecute($input)) {
            return new WP_Error('advmo_attachment_forbidden', __('You cannot re-offload these attachments.', 'advanced-media-offloader'), ['status' => 403]);
        }
        return $this->actions->reoffload($input['attachment_ids']);
    }
}
