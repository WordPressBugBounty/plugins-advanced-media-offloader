<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Interfaces\ObserverInterface;

/**
 * Adds a “Deleting…” loading indicator to Media Library list + grid views.
 */
class AttachmentDeleteLoadingObserver implements ObserverInterface
{
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * @param string $hook Current admin page hook.
     */
    public function enqueueAssets(string $hook): void
    {
        // Media Library screen (both list + grid live under upload.php).
        if ($hook !== 'upload.php') {
            return;
        }

        wp_enqueue_style(
            'advmo-attachment-delete-loading',
            $this->getAssetUrl('css/attachment-delete-loading.css'),
            [],
            $this->getAssetVersion('css/attachment-delete-loading.css')
        );

        wp_enqueue_script(
            'advmo-attachment-delete-loading',
            $this->getAssetUrl('js/attachment-delete-loading.js'),
            ['jquery', 'media-views'],
            $this->getAssetVersion('js/attachment-delete-loading.js'),
            true
        );

        wp_localize_script('advmo-attachment-delete-loading', 'advmoDeleteLoading', [
            'i18n' => [
                'deleting' => __('Deleting…', 'advanced-media-offloader'),
            ],
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
}


