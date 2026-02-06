<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;

class AttachmentUploadObserver implements ObserverInterface
{
    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($cloudProvider);
    }

    public function register(): void
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'run'], 99, 2);
    }

    public function run($metadata, $attachment_id)
    {
        // Check if auto-offload is enabled in settings
        $options = get_option('advmo_settings', []);
        $auto_offload_enabled = isset($options['auto_offload_uploads']) ? (int) $options['auto_offload_uploads'] : 1;
        
        if (!$auto_offload_enabled) {
            return $metadata;
        }

        /**
         * Filter to determine whether an attachment should be offloaded.
         *
         * Return false to skip offloading this attachment. Useful for
         * conditional rules (file type, size, user role, taxonomy, etc.).
         *
         * @param bool $should_offload Default true.
         * @param int  $attachment_id  Attachment ID.
         */
        $should_offload = apply_filters('advmo_should_offload_attachment', true, $attachment_id);
        if (!$should_offload) {
            return $metadata;
        }

        if (!$this->cloudAttachmentUploader->uploadAttachment($attachment_id)) {
            // Log the failure but do NOT delete the attachment
            // The error is already logged by CloudAttachmentUploader
            error_log(sprintf(
                'ADVMO: Failed to offload attachment ID %d. Attachment preserved locally.',
                $attachment_id
            ));
        }

        return $metadata;
    }
}
