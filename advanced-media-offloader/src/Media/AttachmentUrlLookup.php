<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Media;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;

/**
 * Lets attachment_url_to_postid() find offloaded files by their cloud URL.
 */
class AttachmentUrlLookup implements ObserverInterface
{
    public function __construct(private S3_Provider $cloudProvider)
    {
    }

    public function register(): void
    {
        // Not pre_attachment_url_to_postid: it only exists since WordPress 6.7.
        add_filter('attachment_url_to_postid', [$this, 'filter'], 10, 2);
    }

    /**
     * @param mixed $postId
     * @param mixed $url
     * @return mixed
     */
    public function filter($postId, $url)
    {
        $domain = rtrim((string) $this->cloudProvider->getDomain(), '/') . '/';
        if ($postId || $domain === '/' || !is_string($url)) {
            return $postId;
        }

        // Match http, https and protocol-relative copies of the same URL.
        [$domain, $url] = preg_replace('#^https?:#i', '', [$domain, $url]);
        if (stripos($url, $domain) !== 0) {
            return $postId;
        }

        $key = substr($url, strlen($domain));
        $path = strpos($key, '/') !== false ? dirname($key) . '/' : '';
        $file = basename($key);

        // advmo_path can hold a version folder that _wp_attached_file does not,
        // and older offloads saved it with a leading slash.
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT path.post_id FROM {$wpdb->postmeta} path
            JOIN {$wpdb->postmeta} file ON file.post_id = path.post_id AND file.meta_key = '_wp_attached_file'
            WHERE path.meta_key = 'advmo_path' AND path.meta_value IN (%s, %s)
            AND (file.meta_value = %s OR file.meta_value LIKE %s)
            LIMIT 2",
            $path,
            '/' . $path,
            $file,
            '%/' . $wpdb->esc_like($file)
        ));

        return count($ids) === 1 ? (int) $ids[0] : $postId;
    }
}
