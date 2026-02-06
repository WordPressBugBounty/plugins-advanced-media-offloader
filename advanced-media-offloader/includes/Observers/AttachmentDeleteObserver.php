<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class AttachmentDeleteObserver  implements ObserverInterface
{
    use OffloaderTrait;

    private const TRANSIENT_DELETE_ERROR_PREFIX = 'advmo_delete_error_';

    /**
     * @var S3_Provider
     */
    private S3_Provider $cloudProvider;

    /**
     * Constructor.
     *
     * @param S3_Provider $cloudProvider
     */
    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    /**
     * Register the observer with WordPress hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('delete_attachment', [$this, 'run'], 10, 2);
        add_action('admin_notices', [$this, 'renderDeleteErrorNotice']);
    }

    /**
     * Delete cloud files when an attachment is deleted.
     * @param int    $post_id The ID of the post.
     * @param \WP_Post $post The post object.
     * @return void
     * @see https://developer.wordpress.org/reference/hooks/delete_attachment/
     */
    public function run(int $post_id, \WP_Post $post): void
    {
        if ($this->shouldDeleteCloudFiles($post)) {
            $this->performCloudFileDeletion($post_id);
        }
    }

    /**
     * Perform the actual deletion of cloud files.
     *
     * @param int $post_id The ID of the post.
     * @return void
     */
    private function performCloudFileDeletion(int $post_id): void
    {
        try {
            $result = $this->cloudProvider->deleteAttachment($post_id);

            if (!$result) {
                throw new \Exception("Cloud file deletion failed");
            }
        } catch (\Exception $e) {
            $this->handleDeletionError($post_id, $e->getMessage());
        }
    }

    /**
     * Handle errors during cloud file deletion.
     *
     * @param int    $post_id The ID of the post.
     * @param string $error_message The error message.
     * @return void
     */
    private function handleDeletionError(int $post_id, string $error_message): void
    {
        $log_message = "Cloud file deletion failed for attachment ID: {$post_id}. " .
            "The file may remain in cloud storage due to an error. " .
            "Please try again or contact support if the issue persists.";

        error_log($log_message);
        
        // Persist a short-lived notice for the current user instead of hard-stopping the request.
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            set_transient(
                self::TRANSIENT_DELETE_ERROR_PREFIX . $user_id,
                [
                    'attachment_id' => $post_id,
                    'message'       => $error_message,
                ],
                2 * MINUTE_IN_SECONDS
            );
        }
    }

    /**
     * Render any pending delete error notice for the current user.
     */
    public function renderDeleteErrorNotice(): void
    {
        if (!is_admin() || !current_user_can('upload_files')) {
            return;
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        $key = self::TRANSIENT_DELETE_ERROR_PREFIX . $user_id;
        $payload = get_transient($key);
        if (empty($payload) || !is_array($payload)) {
            return;
        }

        delete_transient($key);

        $attachment_id = isset($payload['attachment_id']) ? (int) $payload['attachment_id'] : 0;
        $message = isset($payload['message']) ? (string) $payload['message'] : '';

        if ($message === '') {
            $message = __('Cloud deletion failed for a media item. The file may remain in cloud storage.', 'advanced-media-offloader');
        }

        $suffix = $attachment_id > 0
            ? sprintf(
                /* translators: %d: attachment ID */
                __(' (Attachment ID: %d)', 'advanced-media-offloader'),
                $attachment_id
            )
            : '';

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s%s</p></div>',
            esc_html($message),
            esc_html($suffix)
        );
    }
}
