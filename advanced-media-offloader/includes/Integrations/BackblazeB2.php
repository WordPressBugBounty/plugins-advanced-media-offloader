<?php

namespace Advanced_Media_Offloader\Integrations;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use WPFitter\Aws\S3\S3Client;

class BackblazeB2 extends S3_Provider
{
	public $providerName = "Backblaze B2";

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
		$endpoint = advmo_get_provider_credential('backblaze_b2', 'endpoint');
		if (!empty($endpoint)) {
			$endpoint = advmo_normalize_url($endpoint);
		}

		return new S3Client([
			'version' => 'latest',
			'use_aws_shared_config_files' => false,
			'endpoint' => $endpoint,
			'region' => advmo_get_provider_credential('backblaze_b2', 'region') ?: 'us-west-004',
			'credentials' => [
				'key' => advmo_get_provider_credential('backblaze_b2', 'key'),
				'secret' => advmo_get_provider_credential('backblaze_b2', 'secret'),
			],
			'retries' => 1
		]);
	}

	public function getBucket()
	{
		$bucket = advmo_get_provider_credential('backblaze_b2', 'bucket');
		return !empty($bucket) ? $bucket : null;
	}

	public function getDomain()
	{
		$domain = '';
		$domain_value = advmo_get_provider_credential('backblaze_b2', 'domain');
		if (!empty($domain_value)) {
			$normalized_url = advmo_normalize_url($domain_value);
			$domain = $normalized_url ? trailingslashit($normalized_url) : '';
		}
		return apply_filters('advmo_backblaze_b2_domain', $domain);
	}

	public function credentialsField()
	{
		$credentialFields = [
			[
				'name' => 'key',
				'label' => __('Application Key ID', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('Your Backblaze B2 Application Key ID', 'advanced-media-offloader')
			],
			[
				'name' => 'secret',
				'label' => __('Application Key', 'advanced-media-offloader'),
				'type' => 'password',
				'placeholder' => __('Your Backblaze B2 Application Key', 'advanced-media-offloader')
			],
			[
				'name' => 'endpoint',
				'label' => __('S3 Endpoint URL', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('https://s3.us-west-004.backblazeb2.com', 'advanced-media-offloader')
			],
			[
				'name' => 'region',
				'label' => __('Region', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('us-west-004', 'advanced-media-offloader')
			],
			[
				'name' => 'bucket',
				'label' => __('Bucket Name', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('Your Backblaze B2 Bucket Name', 'advanced-media-offloader')
			],
			[
				'name' => 'domain',
				'label' => __('Custom Domain (CDN URL)', 'advanced-media-offloader'),
				'type' => 'text',
				'placeholder' => __('https://media.yourdomain.com', 'advanced-media-offloader')
			],
		];

		echo $this->getCredentialsFieldHTML($credentialFields, 'backblaze_b2');
	}
}

