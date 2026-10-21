<?php

namespace Advanced_Media_Offloader\Services;

use Advanced_Media_Offloader\BulkOffloadHandler;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Abstracts\S3_Provider;
use AdvancedMediaOffloader\Bulk\BulkProcess;

class BulkMediaOffloader extends BulkProcess
{
    protected $prefix = 'advmo';
    protected $action = 'bulk_offload_media_process';

    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3_Provider $cloudProvider)
    {
        parent::__construct();
        $this->cloudAttachmentUploader = advmo()->app()->make(CloudAttachmentUploader::class, ['cloudProvider' => $cloudProvider]);
    }

    protected function task($item)
    {
        return advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkTaskRunner::class)->run(
            $item,
            $this->cloudAttachmentUploader,
            $this
        );
    }

    protected function complete()
    {
        parent::complete();
        advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkOffloadService::class)->finish($this->currentRunId());
    }

    public function update_processed_count($result_status)
    {
        $service = advmo()->app()->get(\AdvancedMediaOffloader\Bulk\BulkOffloadService::class);
        if ($this->currentAttachmentId() !== null && $this->currentRunId() !== null) {
            $service->record($this->currentRunId(), $this->currentAttachmentId(), $result_status === true);
            return;
        }
        $service->increment($this->currentRunId(), $result_status === true);
    }

    public function get_identifier()
    {
        return $this->identifier;
    }
}
