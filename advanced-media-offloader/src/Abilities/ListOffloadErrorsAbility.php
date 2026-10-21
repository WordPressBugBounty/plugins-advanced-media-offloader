<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Abilities;

use AdvancedMediaOffloader\Diagnostics\DiagnosticMessages;
use AdvancedMediaOffloader\Media\Contracts\AttachmentRepository;

class ListOffloadErrorsAbility extends AbstractReadAbility
{
    public function __construct(private AttachmentRepository $attachments, private DiagnosticMessages $messages)
    {
    }

    public function name(): string
    {
        return 'advmo/list-offload-errors';
    }

    protected function definition(): array
    {
        return [
            'label' => __('List offload errors', 'advanced-media-offloader'),
            'description' => __('Read a page of recorded attachment errors, ordered by attachment ID. Trashed attachments are excluded.', 'advanced-media-offloader'),
            'input_schema' => [
                'type' => ['object', 'null'],
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => self::objectSchema([
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'items' => [
                    'type' => 'array',
                    'maxItems' => 100,
                    'items' => self::objectSchema([
                        'attachment_id' => ['type' => 'integer'],
                        'errors' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 50],
                    ]),
                ],
            ]),
        ];
    }

    protected function run(mixed $input): array
    {
        $page = $input['page'] ?? 1;
        $perPage = $input['per_page'] ?? 20;
        $items = [];

        foreach ($this->attachments->errorIds($page, $perPage) as $attachmentId) {
            if (!current_user_can('edit_post', $attachmentId)) {
                continue;
            }
            $record = $this->attachments->find($attachmentId);
            if ($record !== null) {
                $items[] = ['attachment_id' => $attachmentId, 'errors' => $this->messages->clean($record->errors)];
            }
        }

        return ['page' => $page, 'per_page' => $perPage, 'total' => $this->attachments->countErrors(), 'items' => $items];
    }
}
