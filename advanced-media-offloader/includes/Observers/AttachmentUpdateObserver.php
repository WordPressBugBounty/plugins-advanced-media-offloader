<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class AttachmentUpdateObserver implements ObserverInterface
{
    use OffloaderTrait;

    private const TRANSIENT_IMAGE_EDIT_ERROR_PREFIX = 'advmo_image_edit_error_';

    /**
     * @var S3_Provider
     */
    private S3_Provider $cloudProvider;

    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($cloudProvider);
    }

    public function register(): void
    {
        add_filter('wp_update_attachment_metadata', [$this, 'run'], 99, 2);
        add_action('admin_notices', [$this, 'renderImageEditErrorNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueImageEditErrorAssets']);
    }

    public function run($metadata, $attachment_id)
    {
        $operation = $this->getImageEditorOperation();
        if ($operation === null || !$this->is_offloaded($attachment_id)) {
            return $metadata;
        }

        $oldMetadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        if (!is_array($oldMetadata)) {
            $oldMetadata = [];
        }

        $newMetadata = is_array($metadata) ? $metadata : [];
        if ($operation === 'image_edit') {
            $newMetadata = $this->resolveGeneratedImageEditFile(
                (int) $attachment_id,
                $newMetadata,
                $oldMetadata
            );
        }
        if (!$this->cloudAttachmentUploader->uploadUpdatedAttachment(
            (int) $attachment_id,
            $newMetadata,
            $operation
        )) {
            // update_attached_file() runs before this filter in both core image
            // operations. Point it back at the metadata that is still committed
            // so URL rewriting never selects a cloud object that failed upload.
            if (!empty($oldMetadata['file']) && is_string($oldMetadata['file'])) {
                update_attached_file($attachment_id, $oldMetadata['file']);
            }

            $this->cloudAttachmentUploader->cancelImageOperationCleanup((int) $attachment_id);

            if ($operation === 'image_edit') {
                $message = __(
                    'Image edit was not saved because Advanced Media Offloader could not safely upload all new files. The previous image and the new local files were kept. Check the cloud connection and try again.',
                    'advanced-media-offloader'
                );

                if (!$this->sendImageEditorAjaxError((int) $attachment_id, $message)) {
                    $this->storeImageEditErrorNotice((int) $attachment_id, $message);
                }
            }

            // Returning false from this filter would make WordPress delete the
            // attachment metadata. Returning the unchanged stored array makes
            // update_post_meta() report false safely. Restore Original checks
            // that result. Image Save does not, so its AJAX request is ended
            // with an explicit error above.
            return $oldMetadata;
        }

        // wp_update_attachment_metadata() writes the filtered value only after
        // this callback returns. Cleanup therefore runs at shutdown (or via the
        // independent retry worker), after the exact metadata can be verified.
        register_shutdown_function(
            [$this->cloudAttachmentUploader, 'resumePendingLocalCleanup'],
            (int) $attachment_id
        );

        return $newMetadata;
    }

    /**
     * Use the file that the image editor actually created for this edit.
     *
     * Modern Image Formats can change its preferred output after an attachment
     * was created. WordPress then builds the next edit filename with the old
     * extension, while WP_Image_Editor saves the new preferred format under a
     * different extension. Core ignores the path returned by the editor and
     * leaves the new metadata pointing at a file that does not exist.
     *
     * This only corrects the current image-edit generation. It does not repair
     * stored metadata or guess when no exact same-token output file exists.
     */
    private function resolveGeneratedImageEditFile(
        int $attachmentId,
        array $newMetadata,
        array $oldMetadata
    ): array {
        $expectedFile = (string) get_attached_file($attachmentId, true);
        if ($expectedFile === '' || is_file($expectedFile)) {
            return $newMetadata;
        }

        $expectedTokens = $this->getImageEditTokens([$expectedFile]);
        if (empty($expectedTokens)) {
            return $newMetadata;
        }

        $oldFile = !empty($oldMetadata['file']) && is_string($oldMetadata['file'])
            ? wp_basename($oldMetadata['file'])
            : '';
        if ($oldFile !== '' && wp_basename($expectedFile) === $oldFile) {
            return $newMetadata;
        }

        $expectedStem = pathinfo($expectedFile, PATHINFO_FILENAME);
        $directoryFiles = glob(trailingslashit(dirname($expectedFile)) . '*');
        if (!is_array($directoryFiles)) {
            return $newMetadata;
        }

        $candidates = [];
        foreach ($directoryFiles as $directoryFile) {
            if (
                !is_file($directoryFile)
                || pathinfo($directoryFile, PATHINFO_FILENAME) !== $expectedStem
                || !$this->pathHasImageEditToken($directoryFile, $expectedTokens)
            ) {
                continue;
            }

            $mimeType = function_exists('wp_get_image_mime')
                ? wp_get_image_mime($directoryFile)
                : (wp_check_filetype($directoryFile)['type'] ?? false);
            if (!is_string($mimeType) || 0 !== strpos($mimeType, 'image/')) {
                continue;
            }

            $candidates[$this->normalizeFilePath($directoryFile)] = $mimeType;
        }

        $actualFile = $this->selectGeneratedImageEditFile(
            $attachmentId,
            $expectedFile,
            $candidates,
            $newMetadata
        );
        if ($actualFile === null || !$this->isPathInsideUploads($actualFile)) {
            return $newMetadata;
        }

        if (!update_attached_file($attachmentId, $actualFile)) {
            return $newMetadata;
        }

        $fileSize = filesize($actualFile);
        $mimeType = $candidates[$actualFile];
        $newMetadata['file'] = _wp_relative_upload_path($actualFile);
        if ($fileSize !== false) {
            $newMetadata['filesize'] = $fileSize;
        }

        if (!isset($newMetadata['sources']) || !is_array($newMetadata['sources'])) {
            $newMetadata['sources'] = [];
        }
        $newMetadata['sources'][$mimeType] = [
            'file' => wp_basename($actualFile),
            'filesize' => $fileSize !== false ? $fileSize : 0,
        ];

        return $newMetadata;
    }

    /**
     * Pick one exact same-token image output without guessing between formats.
     *
     * @param array<string,string> $candidates Normalized path => MIME type.
     */
    private function selectGeneratedImageEditFile(
        int $attachmentId,
        string $expectedFile,
        array $candidates,
        array $metadata
    ): ?string
    {
        if (empty($candidates)) {
            return null;
        }

        $mimeCounts = [];
        foreach (($metadata['sizes'] ?? []) as $sizeData) {
            if (!is_array($sizeData) || empty($sizeData['mime-type']) || !is_string($sizeData['mime-type'])) {
                continue;
            }

            $mimeType = $sizeData['mime-type'];
            $mimeCounts[$mimeType] = ($mimeCounts[$mimeType] ?? 0) + 1;
        }

        $preferredMime = null;
        if (!empty($mimeCounts)) {
            arsort($mimeCounts);
            $mimeCountsList = array_values($mimeCounts);
            if (!isset($mimeCountsList[1]) || $mimeCountsList[0] > $mimeCountsList[1]) {
                $preferredMime = array_key_first($mimeCounts);
            }
        }

        // Small images may have no sub-sizes. Ask the same output-format
        // filter used by WP_Image_Editor which MIME type it selected.
        if ($preferredMime === null) {
            $sourceMime = get_post_mime_type($attachmentId);
            if (!is_string($sourceMime) || $sourceMime === '') {
                return null;
            }

            $outputFormats = apply_filters(
                'image_editor_output_format',
                [],
                $expectedFile,
                $sourceMime
            );
            $preferredMime = is_array($outputFormats) && !empty($outputFormats[$sourceMime])
                ? (string) $outputFormats[$sourceMime]
                : $sourceMime;
        }

        $matchingFiles = array_keys($candidates, $preferredMime, true);

        return count($matchingFiles) === 1 ? $matchingFiles[0] : null;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function getImageEditTokens(array $paths): array
    {
        $tokens = [];

        foreach ($paths as $path) {
            if (preg_match_all('/-e([0-9]{13})(?:[.-]|$)/', wp_basename($path), $matches)) {
                foreach ($matches[1] as $token) {
                    $tokens[(string) $token] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    /**
     * @param string[] $editTokens
     */
    private function pathHasImageEditToken(string $path, array $editTokens): bool
    {
        $basename = wp_basename($path);

        foreach ($editTokens as $editToken) {
            if (preg_match('/-e' . preg_quote($editToken, '/') . '(?:[.-]|$)/', $basename)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeFilePath(string $path): string
    {
        return untrailingslashit(wp_normalize_path($path));
    }

    private function isPathInsideUploads(string $path): bool
    {
        $uploadDir = wp_get_upload_dir();
        if (empty($uploadDir['basedir']) || !is_string($uploadDir['basedir'])) {
            return false;
        }

        $uploadsBase = trailingslashit($this->normalizeFilePath($uploadDir['basedir']));
        $candidate = $this->normalizeFilePath($path);

        return 0 === strpos($candidate, $uploadsBase);
    }

    /**
     * Send the error shape expected by WordPress's image editor JavaScript.
     *
     * WordPress ignores wp_update_attachment_metadata() failures in wp_save_image(),
     * so returning old metadata alone would still produce an "Image saved"
     * response. Ending only the matching AJAX request prevents that false success.
     */
    private function sendImageEditorAjaxError(int $attachmentId, string $message): bool
    {
        if (
            !wp_doing_ajax()
            || ($_POST['action'] ?? '') !== 'image-editor'
            || (int) ($_POST['postid'] ?? 0) !== $attachmentId
        ) {
            return false;
        }

        $action = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        if ($action === 'save') {
            wp_send_json_error((object) [
                'error' => esc_html($message),
                'advmo_image_edit_error' => true,
                'attachment_id' => $attachmentId,
            ]);
            return true;
        }

        if ($action === 'scale' && function_exists('wp_image_editor')) {
            $result = (object) [
                'error' => $message,
            ];

            ob_start();
            wp_image_editor($attachmentId, $result);
            $html = (string) ob_get_clean();

            wp_send_json_error([
                'message' => $result,
                'html' => $html,
                'advmo_image_edit_error' => true,
                'attachment_id' => $attachmentId,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Keep offload errors visible after WordPress closes the image editor view.
     *
     * Core inserts image-save errors into the editor response element and then
     * calls imageEdit.close(). In a Media Library modal, that response element
     * belongs to the view that was just hidden. This small client-side handler
     * moves only errors marked by this observer into the active modal view.
     *
     * @param mixed $hook Current admin page hook.
     */
    public function enqueueImageEditErrorAssets($hook = ''): void
    {
        if (!is_string($hook) || !in_array($hook, [
            'upload.php',
            'media.php',
            'post.php',
            'post-new.php',
            'site-editor.php',
            'widgets.php',
            'customize.php',
        ], true)) {
            return;
        }

        wp_enqueue_style(
            'advmo-image-edit-error',
            $this->getAssetUrl('css/image-edit-error.css'),
            [],
            $this->getAssetVersion('css/image-edit-error.css')
        );

        wp_enqueue_script(
            'advmo-image-edit-error',
            $this->getAssetUrl('js/image-edit-error.js'),
            ['jquery'],
            $this->getAssetVersion('js/image-edit-error.js'),
            true
        );

        wp_localize_script('advmo-image-edit-error', 'advmoImageEditError', [
            'dismiss' => __('Dismiss this notice.', 'advanced-media-offloader'),
        ]);
    }

    private function getAssetUrl(string $path): string
    {
        return plugins_url('assets/' . $path, dirname(__DIR__, 2) . '/advanced-media-offloader.php');
    }

    private function getAssetVersion(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/assets/' . $path;
        if (file_exists($file)) {
            return (string) filemtime($file);
        }

        return defined('ADVMO_VERSION') ? (string) constant('ADVMO_VERSION') : '1.0.0';
    }

    private function storeImageEditErrorNotice(int $attachmentId, string $message): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }

        set_transient(
            self::TRANSIENT_IMAGE_EDIT_ERROR_PREFIX . $userId,
            [
                'attachment_id' => $attachmentId,
                'message' => $message,
            ],
            2 * MINUTE_IN_SECONDS
        );
    }

    public function renderImageEditErrorNotice(): void
    {
        if (!is_admin() || !current_user_can('upload_files')) {
            return;
        }

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }

        $key = self::TRANSIENT_IMAGE_EDIT_ERROR_PREFIX . $userId;
        $payload = get_transient($key);
        if (!is_array($payload) || empty($payload['message'])) {
            return;
        }

        delete_transient($key);

        $attachmentId = isset($payload['attachment_id']) ? (int) $payload['attachment_id'] : 0;
        $suffix = $attachmentId > 0
            ? sprintf(
                /* translators: %d: attachment ID. */
                __(' (Attachment ID: %d)', 'advanced-media-offloader'),
                $attachmentId
            )
            : '';

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s%s</p></div>',
            esc_html((string) $payload['message']),
            esc_html($suffix)
        );
    }
}
