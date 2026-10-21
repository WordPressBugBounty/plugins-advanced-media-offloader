<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use AdvancedMediaOffloader\Offload\AttachmentActions;
use WP_Error;

class OffloadAttachmentsAbility extends AbstractAbility
{
    public function __construct(protected AttachmentActions $actions)
    {
    }

    public function name(): string
    {
        return 'advmo/offload-attachments';
    }

    protected function definition(): array
    {
        return [
            'label' => __('Offload attachments', 'advanced-media-offloader'),
            'description' => __('Upload up to 20 attachments using the saved cloud provider. Applies the saved local-file retention policy, which may delete local copies. Returns a result for each attachment; does not start a background job.', 'advanced-media-offloader'),
            'input_schema' => self::objectSchema([
                'attachment_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'minItems' => 1,
                    'maxItems' => AttachmentActions::MAX_ATTACHMENTS,
                    'uniqueItems' => true,
                ],
            ]),
            'output_schema' => self::objectSchema([
                'attachments' => [
                    'type' => 'array',
                    'maxItems' => AttachmentActions::MAX_ATTACHMENTS,
                    'items' => self::objectSchema([
                        'attachment_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string', 'enum' => ['offloaded', 'reoffloaded', 'already_offloaded', 'cleanup_pending', 'skipped', 'failed']],
                        'code' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                    ]),
                ],
            ]),
        ];
    }

    public function canExecute(mixed $input = null): bool
    {
        if (!parent::canExecute($input) || !current_user_can('upload_files')) {
            return false;
        }
        $input = $this->normalizeInput($input);
        if (is_wp_error($input) || !is_array($input) || !is_array($input['attachment_ids'] ?? null)) {
            return false;
        }
        foreach ($input['attachment_ids'] as $attachmentId) {
            if (!current_user_can('edit_post', $attachmentId)) {
                return false;
            }
        }
        return true;
    }

    protected function annotations(): array
    {
        return ['readonly' => false, 'destructive' => true, 'idempotent' => false];
    }

    protected function run(mixed $input): array|WP_Error
    {
        if (!$this->canExecute($input)) {
            return new WP_Error('advmo_attachment_forbidden', __('You cannot offload these attachments.', 'advanced-media-offloader'), ['status' => 403]);
        }
        return $this->actions->offload($input['attachment_ids']);
    }
}
