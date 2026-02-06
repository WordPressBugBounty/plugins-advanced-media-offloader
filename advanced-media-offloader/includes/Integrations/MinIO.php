<?php

namespace Advanced_Media_Offloader\Integrations;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use WPFitter\Aws\S3\S3Client;

class MinIO extends S3_Provider
{
    public $providerName = "Any S3‑Compatible Storage (MinIO, OVHcloud, ...)";

    public function __construct()
    {
        // Do nothing.
    }

    public function getProviderName()
    {
        // Get custom name from credentials, default to "MinIO" for backward compatibility
        $custom_name = advmo_get_provider_credential('minio', 'name');
        if (!empty($custom_name)) {
            return $custom_name;
        }
        return $this->providerName;
    }

    public function getClient()
    {
        $endpoint = advmo_get_provider_credential('minio', 'endpoint');
        if (!empty($endpoint)) {
            $endpoint = advmo_normalize_url($endpoint);
        }

        $path_style = advmo_get_provider_credential('minio', 'path_style_endpoint');
        $use_path_style = !empty($path_style) ? (bool) $path_style : false;

        return new S3Client([
            'version' => 'latest',
            'use_aws_shared_config_files' => false,
            'endpoint' => $endpoint,
            'region' => advmo_get_provider_credential('minio', 'region') ?: 'us-east-1',
            'credentials' => [
                'key' => advmo_get_provider_credential('minio', 'key'),
                'secret' => advmo_get_provider_credential('minio', 'secret'),
            ],
            'use_path_style_endpoint' => $use_path_style,
            'retries' => 1
        ]);
    }

    public function getBucket()
    {
        $bucket = advmo_get_provider_credential('minio', 'bucket');
        return !empty($bucket) ? $bucket : null;
    }

    public function getDomain()
    {
        $domain = '';
        $domain_value = advmo_get_provider_credential('minio', 'domain');
        $bucket = $this->getBucket();
        
        // Check if user wants to append bucket to domain
        // Default to true for backward compatibility (when value is null/not set)
        $append_bucket = advmo_get_provider_credential('minio', 'append_bucket_to_domain');
        $should_append_bucket = ($append_bucket === null || $append_bucket === '' || $append_bucket === 1 || $append_bucket === '1');
        
        if (!empty($domain_value)) {
            $normalized_url = advmo_normalize_url($domain_value);
            if ($normalized_url) {
                if ($should_append_bucket && $bucket) {
                    $domain = trailingslashit($normalized_url) . trailingslashit($bucket);
                } else {
                    $domain = trailingslashit($normalized_url);
                }
            }
        }
        return apply_filters('advmo_minio_domain', $domain);
    }

    public function getProviderDescription()
    {
        return __('Use this for any storage that supports the S3 API via a custom endpoint (e.g., MinIO, OVHcloud Object Storage, Scaleway, Linode, Vultr, IBM COS). Select this if your provider isn\'t listed separately.', 'advanced-media-offloader');
    }

    public function credentialsField()
    {
        $credentialFields = [
            [
                'name' => 'name',
                'label' => __('Provider Name', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('MinIO', 'advanced-media-offloader'),
                'default' => 'MinIO',
                'description' => __('A custom name to identify this cloud provider (e.g., MinIO, OVHcloud, Scaleway).', 'advanced-media-offloader')
            ],
            [
                'name' => 'key',
                'label' => __('Access Key ID', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('Your MinIO Access Key', 'advanced-media-offloader')
            ],
            [
                'name' => 'secret',
                'label' => __('Secret Access Key', 'advanced-media-offloader'),
                'type' => 'password',
                'placeholder' => __('Your MinIO Secret Key', 'advanced-media-offloader')
            ],
            [
                'name' => 'endpoint',
                'label' => __('S3 Endpoint URL', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('https://your-minio-server.com', 'advanced-media-offloader')
            ],
            [
                'name' => 'region',
                'label' => __('Region', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('us-east-1', 'advanced-media-offloader')
            ],
            [
                'name' => 'path_style_endpoint',
                'label' => __('Use Path-Style Endpoint', 'advanced-media-offloader'),
                'type' => 'checkbox',
                'description' => __('Enable this if your MinIO server requires path-style URLs (most self-hosted MinIO setups).', 'advanced-media-offloader')
            ],
            [
                'name' => 'bucket',
                'label' => __('Bucket Name', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('Your MinIO Bucket Name', 'advanced-media-offloader')
            ],
            [
                'name' => 'domain',
                'label' => __('Custom Domain (CDN URL)', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('https://media.yourdomain.com', 'advanced-media-offloader')
            ],
            [
                'name' => 'append_bucket_to_domain',
                'label' => __('Append Bucket Name to Domain', 'advanced-media-offloader'),
                'type' => 'checkbox',
                'default' => '1',
                'description' => __('Enable this to automatically append the bucket name to your custom domain URL (e.g., https://cdn.example.com/my-bucket/). Disable if your domain already points to the bucket or you\'re using a CDN with custom bucket routing.', 'advanced-media-offloader')
            ],
        ];

        echo $this->getCredentialsFieldHTML($credentialFields, 'minio', $this->getProviderDescription());
    }
}
