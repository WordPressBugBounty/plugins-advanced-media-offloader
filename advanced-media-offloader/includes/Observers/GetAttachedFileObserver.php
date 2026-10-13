<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use AdvancedMediaOffloader\Media\AttachedFileCache;
use AdvancedMediaOffloader\Media\AttachedFileObserver;
use AdvancedMediaOffloader\Media\FailedDownloads;
use AdvancedMediaOffloader\Support\Deprecation;

/**
 * @deprecated 4.6.0 Use \AdvancedMediaOffloader\Media\AttachedFileObserver.
 */
class GetAttachedFileObserver extends AttachedFileObserver
{
    public function __construct(S3_Provider $cloudProvider)
    {
        Deprecation::function(__METHOD__, '4.6.0', AttachedFileObserver::class);

        parent::__construct(new AttachedFileCache($cloudProvider, new FailedDownloads()));
    }

    // The 4.x parameter names stay: callers may pass them by name.

    public function filter($file, $attachment_id)
    {
        return parent::filter($file, $attachment_id);
    }

    public function protectAttachedFileMetadata($file, $attachment_id)
    {
        return parent::protectAttachedFileMetadata($file, $attachment_id);
    }

    public function invalidateForMetaChange($meta_id, $object_id, $meta_key, $meta_value): void
    {
        parent::invalidateForMetaChange($meta_id, $object_id, $meta_key, $meta_value);
    }
}
