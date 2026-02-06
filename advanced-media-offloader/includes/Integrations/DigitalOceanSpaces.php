<?php

namespace Advanced_Media_Offloader\Integrations;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use WPFitter\Aws\S3\S3Client;

class DigitalOceanSpaces extends S3_Provider
{
	public $providerName = "DigitalOcean Spaces";

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
		$endpoint = advmo_get_provider_credential('dos', 'endpoint');
		if (!empty($endpoint)) {
			$endpoint = advmo_normalize_url($endpoint);
		}

		return new S3Client([
			'version' => 'latest',
			'use_aws_shared_config_files' => false,
			'endpoint' => $endpoint,
			'region' => advmo_get_provider_credential('dos', 'region') ?: 'us-east-1',
			'credentials' => [
				'key' => advmo_get_provider_credential('dos', 'key'),
				'secret' => advmo_get_provider_credential('dos', 'secret'),
			]
		]);
	}

	public function getBucket()
	{
		$bucket = advmo_get_provider_credential('dos', 'bucket');
		return !empty($bucket) ? $bucket : null;
	}

	public function getDomain()
	{
		$domain = '';
		$domain_value = advmo_get_provider_credential('dos', 'domain');
		if (!empty($domain_value)) {
			$normalized_url = advmo_normalize_url($domain_value);
			$domain = $normalized_url ? trailingslashit($normalized_url) : '';
		}
		return apply_filters('advmo_dos_domain', $domain);
	}

	public function credentialsField()
	{
		$credentialFields = [
			[
				'name' => 'key',
				'label' => __('Access Key ID', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('Your DigitalOcean Spaces Access Key', 'advanced-media-offloader')
			],
			[
				'name' => 'secret',
				'label' => __('Secret Access Key', 'advanced-media-offloader'),
				'type' => 'password',
				'placeholder' => __('Your DigitalOcean Spaces Secret Key', 'advanced-media-offloader')
			],
			[
				'name' => 'endpoint',
				'label' => __('Endpoint URL', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('https://nyc3.digitaloceanspaces.com', 'advanced-media-offloader')
			],
			[
				'name' => 'bucket',
				'label' => __('Bucket Name', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('Your DigitalOcean Spaces Bucket Name', 'advanced-media-offloader')
			],
			[
				'name' => 'domain',
				'label' => __('Custom Domain (CDN URL)', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('https://media.yourdomain.com', 'advanced-media-offloader')
			],
		];

		echo $this->getCredentialsFieldHTML($credentialFields, 'dos');
	}
}
