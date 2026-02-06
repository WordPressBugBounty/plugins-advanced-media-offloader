<?php

namespace Advanced_Media_Offloader\Integrations;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use WPFitter\Aws\S3\S3Client;

class Cloudflare_R2 extends S3_Provider
{
	public $providerName = "Cloudflare R2";

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
		$endpoint = advmo_get_provider_credential('cloudflare_r2', 'endpoint');
		if (!empty($endpoint)) {
			$endpoint = advmo_normalize_url($endpoint);
		}

		return new S3Client([
			'version' => 'latest',
			'use_aws_shared_config_files' => false,
			'endpoint' => $endpoint,
			'region' => advmo_get_provider_credential('cloudflare_r2', 'region') ?: 'us-east-1',
			'credentials' => [
				'key' => advmo_get_provider_credential('cloudflare_r2', 'key'),
				'secret' => advmo_get_provider_credential('cloudflare_r2', 'secret'),
			]
		]);
	}

	public function getBucket()
	{
		$bucket = advmo_get_provider_credential('cloudflare_r2', 'bucket');
		return !empty($bucket) ? $bucket : null;
	}

	public function getDomain()
	{
		$domain = '';
		$domain_value = advmo_get_provider_credential('cloudflare_r2', 'domain');
		if (!empty($domain_value)) {
			$normalized_url = advmo_normalize_url($domain_value);
			$domain = $normalized_url ? trailingslashit($normalized_url) : '';
		}
		return apply_filters('advmo_cloudflare_r2_domain', $domain);
	}

	public function credentialsField()
	{
		$credentialFields = [
			[
				'name' => 'key',
				'label' => __('Access Key ID', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('Your Cloudflare R2 Access Key', 'advanced-media-offloader')
			],
			[
				'name' => 'secret',
				'label' => __('Secret Access Key', 'advanced-media-offloader'),
				'type' => 'password',
				'placeholder' => __('Your Cloudflare R2 Secret Key', 'advanced-media-offloader')
			],
			[
				'name' => 'endpoint',
				'label' => __('Endpoint URL', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('https://your-account-id.r2.cloudflarestorage.com', 'advanced-media-offloader')
			],
			[
				'name' => 'bucket',
				'label' => __('Bucket Name', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('Your Cloudflare R2 Bucket Name', 'advanced-media-offloader')
			],
			[
				'name' => 'domain',
				'label' => __('Custom Domain (CDN URL)', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('https://media.yourdomain.com', 'advanced-media-offloader')
			],
		];

		echo $this->getCredentialsFieldHTML($credentialFields, 'cloudflare_r2');
	}
}
