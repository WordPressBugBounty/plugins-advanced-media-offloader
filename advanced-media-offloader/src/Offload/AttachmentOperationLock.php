<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Offload;

use WP_Error;
use wpdb;

class AttachmentOperationLock
{
    private static array $held = [];

    public function __construct(private wpdb $database)
    {
    }

    public function isHeld(int $attachmentId): bool
    {
        return isset(self::$held[$this->ownerKey($attachmentId)]);
    }

    public function run(int $attachmentId, callable $operation): mixed
    {
        $name = $this->name($attachmentId);
        $owner = $this->ownerKey($attachmentId);
        // Uploader callbacks may complete cleanup inside the same operation.
        if (isset(self::$held[$owner])) {
            return $operation();
        }

        // Older MySQL releases the first named lock when another is acquired.
        // Refuse a nested different attachment rather than drop its owner's protection.
        $connectionPrefix = spl_object_id($this->database) . ':';
        if (version_compare($this->database->db_version(), '5.7.5', '<')) {
            foreach (array_keys(self::$held) as $heldOwner) {
                if (strpos($heldOwner, $connectionPrefix) === 0) {
                    return $this->busy();
                }
            }
        }

        // A connection-owned lock has no lease that can expire during a slow upload.
        // MySQL releases it on disconnect, including after a terminated PHP worker.
        $acquired = $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((string) $acquired !== '1') {
            return $this->busy();
        }

        self::$held[$owner] = true;
        try {
            // Another request may have committed while this request cached its metadata.
            wp_cache_delete($attachmentId, 'posts');
            wp_cache_delete($attachmentId, 'post_meta');
            return $operation();
        } finally {
            unset(self::$held[$owner]);
            $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private function busy(): WP_Error
    {
        return new WP_Error(
            'advmo_attachment_busy',
            __('The attachment is busy or could not be locked. Please try again.', 'advanced-media-offloader'),
            ['status' => 409]
        );
    }

    private function ownerKey(int $attachmentId): string
    {
        return spl_object_id($this->database) . ':' . $this->name($attachmentId);
    }

    private function name(int $attachmentId): string
    {
        return 'advmo:' . substr(hash('sha256', DB_NAME . ':' . $this->database->prefix . ':' . $attachmentId), 0, 58);
    }
}
