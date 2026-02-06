<?php

namespace Advanced_Media_Offloader\Integrations;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use WPFitter\Aws\S3\S3Client;

class AmazonS3 extends S3_Provider
{
    public $providerName = "Amazon S3";

    public function __construct()
    {
        // Do nothing.
    }

    public function getProviderName()
    {
        return $this->providerName;
    }

    public function getClient()
    {
        return new S3Client([
            'version' => 'latest',
            'use_aws_shared_config_files' => false,
            'region' => advmo_get_provider_credential('aws', 'region') ?: 'us-east-1',
            'credentials' => [
                'key' => advmo_get_provider_credential('aws', 'key'),
                'secret' => advmo_get_provider_credential('aws', 'secret'),
            ]
        ]);
    }

    public function getBucket()
    {
        $bucket = advmo_get_provider_credential('aws', 'bucket');
        return !empty($bucket) ? $bucket : null;
    }

    public function getDomain()
    {
        $domain = '';
        $domain_value = advmo_get_provider_credential('aws', 'domain');
        if (!empty($domain_value)) {
            $normalized_url = advmo_normalize_url($domain_value);
            $domain = $normalized_url ? trailingslashit($normalized_url) : '';
        }
        return apply_filters('advmo_aws_domain', $domain);
    }

    public function credentialsField()
    {
        $credentialFields = [
            [
                'name' => 'key',
                'label' => __('Access Key ID', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('Your AWS Access Key', 'advanced-media-offloader')
            ],
            [
                'name' => 'secret',
                'label' => __('Secret Access Key', 'advanced-media-offloader'),
                'type' => 'password',
                'placeholder' => __('Your AWS Secret Key', 'advanced-media-offloader')
            ],
            [
                'name' => 'bucket',
                'label' => __('Bucket Name', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('Your S3 Bucket Name', 'advanced-media-offloader')
            ],
            [
                'name' => 'region',
                'label' => __('Region', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('us-east-1', 'advanced-media-offloader')
            ],
            [
                'name' => 'domain',
                'label' => __('Custom Domain (CDN URL)', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('https://media.yourdomain.com', 'advanced-media-offloader')
            ],
        ];

        echo $this->getCredentialsFieldHTML($credentialFields, 'aws');
    }
}
