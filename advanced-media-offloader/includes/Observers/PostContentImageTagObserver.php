<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Traits\OffloaderTrait;

class PostContentImageTagObserver implements ObserverInterface
{
    use OffloaderTrait;

    /**
     * @var S3_Provider
     */
    private S3_Provider $cloudProvider;

    /**
     * The base URL for uploads.
     *
     * @var string
     */
    private string $upload_base_url;

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
        add_filter('wp_content_img_tag', [$this, 'run'], 10, 3);
    }

    /**
     * Rewrite the src attribute of content images to the offloaded URL.
     *
     * @param string $filtered_image Full img tag with attributes that will replace the source img tag.
     * @param string $context        Additional context, like the current filter name or the function name from where this was called.
     * @param int    $attachment_id  The image attachment ID. May be 0 in case the image is not an attachment.
     * @return string The modified image tag HTML.
     */
    public function run($filtered_image, $context, $attachment_id)
    {
        if (!$this->is_offloaded($attachment_id)) {
            return $filtered_image;
        }
        $src_attr = $this->get_image_src($filtered_image);
        if (empty($src_attr)) {
            return $filtered_image;
        }
        $domain = $this->cloudProvider->getDomain();
        if (empty($domain)) {
            return $filtered_image;
        }
        $normalized_domain = rtrim($domain, '/');
        // Already on the offloaded domain — nothing to do
        if (strpos($src_attr, $normalized_domain) === 0) {
            return $filtered_image;
        }
        // Swap domain but keep the original filename (preserves size suffix)
        $subDir = $this->get_attachment_subdir($attachment_id);
        $offloaded_src = $normalized_domain . '/' . ltrim($subDir, '/') . basename($src_attr);
        $filtered_image = str_replace($src_attr, $offloaded_src, $filtered_image);
        return $filtered_image;
    }

    private function get_image_src($image_tag)
    {
        $src = '';

        if (preg_match('/src=[\'"]?([^\'" >]+)[\'"]?/i', $image_tag, $matches)) {
            $src = $matches[1];
        }

        return $src;
    }
}
