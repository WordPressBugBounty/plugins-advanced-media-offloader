<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use AdvancedMediaOffloader\Media\AttachmentStatusReader;
use AdvancedMediaOffloader\Media\Contracts\AttachmentRepository;
use WP_Error;

class GetAttachmentStatusAbility extends AbstractReadAbility
{
    public function __construct(private AttachmentRepository $attachments, private AttachmentStatusReader $status)
    {
    }

    public function name(): string
    {
        return 'advmo/get-attachment-status';
    }

    protected function definition(): array
    {
        return [
            'label' => __('Get attachment offload status', 'advanced-media-offloader'),
            'description' => __('Read the recorded offload state of up to 50 attachments. Does not verify cloud objects or download files.', 'advanced-media-offloader'),
            'input_schema' => self::objectSchema([
                'attachment_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'minItems' => 1,
                    'maxItems' => 50,
                    'uniqueItems' => true,
                ],
            ]),
            'output_schema' => self::objectSchema([
                'attachments' => [
                    'type' => 'array',
                    'maxItems' => 50,
                    'items' => self::objectSchema([
                        'attachment_id' => ['type' => 'integer'],
                        'offloaded' => ['type' => 'boolean'],
                        'provider' => ['type' => ['string', 'null']],
                        'bucket' => ['type' => ['string', 'null']],
                        'path' => ['type' => ['string', 'null']],
                        'offloaded_at' => ['type' => ['integer', 'null']],
                        'url' => ['type' => ['string', 'null']],
                        'cleanup_pending' => ['type' => 'boolean'],
                        'errors' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 50],
                    ]),
                ],
            ]),
        ];
    }

    public function canExecute(mixed $input = null): bool
    {
        if (!parent::canExecute($input)) {
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

    protected function run(mixed $input): array|WP_Error
    {
        $attachments = [];
        foreach ($input['attachment_ids'] as $attachmentId) {
            $record = $this->attachments->find($attachmentId);
            if ($record === null) {
                return new WP_Error('advmo_attachment_not_found', __('An attachment could not be found.', 'advanced-media-offloader'), ['status' => 404]);
            }
            $attachments[] = $this->status->read($record);
        }

        return ['attachments' => $attachments];
    }
}
