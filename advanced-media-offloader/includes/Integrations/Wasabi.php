<?php

namespace Advanced_Media_Offloader\Integrations;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use WPFitter\Aws\S3\S3Client;

class Wasabi extends S3_Provider
{
    public $providerName = "Wasabi";

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
            'endpoint' => $this->getEndpoint(),
            'region' => $this->getRegion(),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => advmo_get_provider_credential('wasabi', 'key'),
                'secret' => advmo_get_provider_credential('wasabi', 'secret'),
            ],
        ]);
    }

    public function getBucket()
    {
        $bucket = advmo_get_provider_credential('wasabi', 'bucket');
        return !empty($bucket) ? $bucket : null;
    }

    public function getDomain()
    {
        $domain = '';
        $domain_value = advmo_get_provider_credential('wasabi', 'domain');
        if (!empty($domain_value)) {
            $normalized_url = advmo_normalize_url($domain_value);
            $domain = $normalized_url ? trailingslashit($normalized_url) : '';
        }
        return apply_filters('advmo_wasabi_domain', $domain);
    }

    private function getRegion(): string
    {
        return advmo_get_provider_credential('wasabi', 'region') ?: 'us-east-1';
    }

    private function getEndpoint(): string
    {
        return sprintf(
            'https://s3.%swasabisys.com',
            ($region = $this->getRegion()) ? "{$region}." : ''
        );
    }

    public function credentialsField()
    {
        $credentialFields = [
            [
                'name' => 'key',
                'label' => __('Access Key ID', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('Your Wasabi Access Key', 'advanced-media-offloader')
            ],
            [
                'name' => 'secret',
                'label' => __('Secret Access Key', 'advanced-media-offloader'),
                'type' => 'password',
                'placeholder' => __('Your Wasabi Secret Key', 'advanced-media-offloader')
            ],
            [
                'name' => 'bucket',
                'label' => __('Bucket Name', 'advanced-media-offloader'),
                'type' => 'text',
                'placeholder' => __('Your Wasabi Bucket Name', 'advanced-media-offloader')
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

        echo $this->getCredentialsFieldHTML($credentialFields, 'wasabi');
    }
}
