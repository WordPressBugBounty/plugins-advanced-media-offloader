<?php

namespace Advanced_Media_Offloader\Abstracts;

use Advanced_Media_Offloader\Traits\OffloaderTrait;

abstract class S3_Provider
{
	use OffloaderTrait;

	protected $s3Client;
	
	/**
	 * Max number of objects per S3 DeleteObjects request.
	 *
	 * AWS S3 supports up to 1000 keys per request.
	 */
	private const MAX_DELETE_OBJECTS = 1000;

	/**
	 * Get the client instance.
	 *
	 * @return mixed
	 */
	abstract protected function getClient();

	/**
	 * Get the credentials field for the UI.
	 *
	 * @return mixed
	 */
	abstract public function credentialsField();

	/**
	 * Check for required constants and return any that are missing.
	 *
	 * @param array $constants Associative array of constant names and messages.
	 * @return array Associative array of missing constants and their messages.
	 */
	protected function checkRequiredConstants(array $constants)
	{
		$missingConstants = [];
		foreach ($constants as $constant => $message) {
			if (!defined($constant)) {
				$missingConstants[$constant] = $message;
			}
		}
		return $missingConstants;
	}

	abstract function getBucket();

	abstract function getProviderName();

	abstract function getDomain();

	/**
	 * Upload a file to the specified bucket.
	 *
	 * @param string $file Path to the file to upload.
	 * @param string $key The key to store the file under in the bucket.
	 * @param string $bucket The bucket to upload the file to.
	 * @return string URL of the uploaded object.
	 */
	public function uploadFile($file, $key)
	{
		$client = $this->getClient();

		// Allow filtering/disabling ACL. Return empty string or false to omit ACL.
		$acl = apply_filters('advmo_object_acl', 'public-read', $file, $key);

		$params = [
			'Bucket' => $this->getBucket(),
			'Key' => $key,
			'SourceFile' => $file,
		];

		// Only add ACL if a non-empty value is provided
		if (!empty($acl)) {
			$params['ACL'] = $acl;
		}

		try {
			$result = $client->putObject($params);
			return $client->getObjectUrl($this->getBucket(), $key);
		} catch (\Exception $e) {
			error_log("Advanced Media Offloader: Error uploading file to S3: {$e->getMessage()}");
			return false;
		}
	}

	/**
	 * Check if an object exists in the bucket.
	 *
	 * @param string $key The object key to check
	 * @return bool True if object exists, false otherwise
	 */
	public function objectExists(string $key): bool
	{
		$client = $this->getClient();
		try {
			$client->headObject([
				'Bucket' => $this->getBucket(),
				'Key' => $key,
			]);
			return true;
		} catch (\Exception $e) {
			// Object doesn't exist or other error
			return false;
		}
	}

	/**
	 * Check the connection to the service.
	 *
	 * @return mixed
	 */
	public function checkConnection()
	{
		$client = $this->getClient();
		try {
			# get bucket info
			$result = $client->headBucket([
				'Bucket' => $this->getBucket(),
				'@http'  => [
					'timeout' => 5,
				],
			]);
			return true;
		} catch (\Exception $e) {
			error_log("Advanced Media Offloader: Error checking connection to S3: {$e->getMessage()}");
			return false;
		}
	}

	protected function getLastCheckTime()
	{
		return get_option('advmo_last_connection_check', '');
	}

	public function TestConnectionHTMLButton()
	{
		$html = sprintf(
			'<button type="button" class="button advmo_js_test_connection">%s</button>',
			esc_html__('Test Connection', 'advanced-media-offloader')
		);

		return $html;
	}

	protected function getConnectionStatusHTML()
	{
		$last_check = $this->getLastCheckTime();
		
		if (empty($last_check)) {
			return '';
		}

		$is_connected = $this->checkConnection();
		$status_text = $is_connected ?
			esc_html__('Connected', 'advanced-media-offloader') :
			esc_html__('Disconnected', 'advanced-media-offloader');

		$icon = $is_connected ? 
			'<span class="dashicons dashicons-yes-alt"></span>' : 
			'<span class="dashicons dashicons-warning"></span>';

		$html = sprintf(
			'<div class="advmo-connection-status %s">%s <span class="advmo-status-text">%s</span> <span class="advmo-status-time">%s: %s</span></div>',
			$is_connected ? 'connected' : 'disconnected',
			$icon,
			esc_html($status_text),
			esc_html__('Last check', 'advanced-media-offloader'),
			esc_html($last_check)
		);

		return $html;
	}

	private function getConstantCodes($missingConstants)
	{
		$html = '';
		foreach ($missingConstants as $constant => $message) {
			if (is_bool($message)) {
				$html .= 'define(\'' . esc_html($constant) . '\', ' . ($message ? 'true' : 'false') . ');' . "\n";
			} else {
				$html .= 'define(\'' . esc_html($constant) . '\', \'' . esc_html(sanitize_title($message)) . '\');' . "\n";
			}
		}
		return $html;
	}

	/**
	 * Render a credential input field.
	 *
	 * @param string $provider_key The provider key (e.g., 'cloudflare_r2').
	 * @param string $field_name The field name (e.g., 'key', 'secret').
	 * @param string $field_label The label for the field.
	 * @param string $field_type The input type ('text' or 'password').
	 * @param string $placeholder Optional placeholder text.
	 * @param string $description Optional description text.
	 * @param string $default Optional default value (used when field value is empty).
	 * @return string The HTML for the field.
	 */
	protected function renderCredentialField($provider_key, $field_name, $field_label, $field_type = 'text', $placeholder = '', $description = '', $default = '')
	{
		$constant_name = 'ADVMO_' . strtoupper($provider_key) . '_' . strtoupper($field_name);
		$is_constant_defined = advmo_credential_exists_in_config($constant_name);
		$field_value = advmo_get_provider_credential($provider_key, $field_name);
		
		// Apply default value if field is empty and default is provided
		if (($field_value === null || $field_value === '') && !empty($default)) {
			$field_value = $default;
		}
		
		$input_name = "advmo_credentials[{$provider_key}][{$field_name}]";
		$input_id = "advmo_credential_{$provider_key}_{$field_name}";
		$disabled = $is_constant_defined ? 'disabled readonly' : '';
		$disabled_class = $is_constant_defined ? 'advmo-field-disabled' : '';
		
		// For password fields with constants, show masked value
		$display_value = $field_value;
		if ($is_constant_defined && $field_type === 'password' && !empty($field_value)) {
			$display_value = str_repeat('•', min(strlen($field_value), 20));
		}
		
		// Handle checkbox fields
		if ($field_type === 'checkbox') {
			$checked = !empty($field_value) ? checked(1, $field_value, false) : '';

			$html = '<div class="advmo-credential-field advmo-checkbox-option ' . esc_attr($disabled_class) . '">';
			$html .= '<input type="checkbox" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="1" ' . $checked . ' ' . $disabled . ' />';
			$html .= '<label for="' . esc_attr($input_id) . '">' . esc_html($field_label) . '</label>';
			
			if (!empty($description)) {
				$html .= '<p class="description">' . esc_html($description) . '</p>';
			}
			
			if ($is_constant_defined) {
				$html .= '<p class="description">' . sprintf(
					esc_html__('This value is set in %s and cannot be changed here.', 'advanced-media-offloader'),
					'<code>wp-config.php</code>'
				) . '</p>';
			}
			
			$html .= '</div>';
			return $html;
		}
		
		$html = '<div class="advmo-credential-field ' . esc_attr($disabled_class) . '">';
		$html .= '<label for="' . esc_attr($input_id) . '">' . esc_html($field_label) . '</label>';
		
		if ($field_type === 'password' && !$is_constant_defined) {
			$html .= '<div class="advmo-password-field-wrapper">';
			$html .= '<input type="password" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . esc_attr($field_value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text advmo-password-input" ' . $disabled . ' />';
			$html .= '<button type="button" class="button advmo-toggle-password" aria-label="' . esc_attr__('Toggle password visibility', 'advanced-media-offloader') . '">';
			$html .= '<span class="dashicons dashicons-visibility"></span>';
			$html .= '</button>';
			$html .= '</div>';
		} else {
			$html .= '<input type="' . esc_attr($field_type) . '" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . esc_attr($display_value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text" ' . $disabled . ' />';
		}
		
		if ($is_constant_defined) {
			$html .= '<p class="description">' . sprintf(
				esc_html__('This value is set in %s and cannot be changed here.', 'advanced-media-offloader'),
				'<code>wp-config.php</code>'
			) . '</p>';
		}
		
		$html .= '</div>';
		
		return $html;
	}

	public function getCredentialsFieldHTML($credentialFields, $provider_key, $provider_description = '')
	{
		$html = '<div class="advmo-credentials-container">';
		
		// Add provider description if provided
		if (!empty($provider_description)) {
			$html .= '<div class="advmo-provider-description notice notice-info inline">';
			$html .= '<p>' . esc_html($provider_description) . '</p>';
			$html .= '</div>';
		}
		
		// Add informational note about wp-config.php
		$info_note = sprintf(
			esc_html__('%s You can configure credentials here or define them in %s for enhanced security. Constants defined in wp-config.php will take priority and disable these fields.', 'advanced-media-offloader'),
			"<strong>" . esc_html__('Note:', 'advanced-media-offloader') . "</strong>",
			"<code>wp-config.php</code>"
		);
		
		$html .= '<div class="advmo-credentials-info notice notice-info inline">';
		$html .= '<p>' . $info_note . '</p>';
		$html .= '</div>';
		
		// Render credential fields
		$html .= '<div class="advmo-credential-fields">';
		foreach ($credentialFields as $field) {
			$html .= $this->renderCredentialField(
				$provider_key,
				$field['name'],
				$field['label'],
				$field['type'] ?? 'text',
				$field['placeholder'] ?? '',
				$field['description'] ?? '',
				$field['default'] ?? ''
			);
		}
		$html .= '</div>';
		
		// Add connection status if available
		$html .= $this->getConnectionStatusHTML();
		
		// Add action buttons container
		$html .= '<div class="advmo-credentials-actions">';
		$html .= '<button type="submit" class="button button-primary advmo-save-credentials">';
		$html .= '<span class="dashicons dashicons-saved"></span> ';
		$html .= esc_html__('Save Credentials', 'advanced-media-offloader');
		$html .= '</button>';
		$html .= $this->TestConnectionHTMLButton();
		$html .= '</div>';
		
		$html .= '</div>'; // Close advmo-credentials-container

		return $html;
	}

	/**
	 * Delete a file from the specified bucket.
	 *
	 * @param int $attachment_id The WordPress attachment ID.
	 * @return bool True on success, false on failure.
	 */
	public function deleteAttachment($attachment_id)
	{
		try {
			$keys = $this->collectAttachmentKeys((int) $attachment_id);
			return $this->deleteS3Objects($keys);
		} catch (\Exception $e) {
			error_log("Advanced Media Offloader: Error deleting file from S3: {$e->getMessage()}");
			return false;
		}
	}

	/**
	 * Collect all object keys that should be deleted for an attachment.
	 *
	 * Includes:
	 * - main object
	 * - generated sizes + modern format sources
	 * - original_image (when present)
	 * - root-level sources
	 * - backup sizes (_wp_attachment_backup_sizes)
	 *
	 * @param int $attachment_id
	 * @return string[]
	 */
	private function collectAttachmentKeys(int $attachment_id): array
	{
		$keys = [];

		// Main object.
		$main_key = $this->getAttachmentKey($attachment_id);
		$keys[] = $main_key;

		$metadata = wp_get_attachment_metadata($attachment_id);
		if (!is_array($metadata)) {
			return array_values(array_unique(array_filter($keys, 'strlen')));
		}

		// Important: if the object is stored at bucket root, dirname($main_key) === '.'
		// and trailingslashit('.') yields './' which would produce wrong keys like './thumb.jpg'.
		$dir = dirname($main_key);
		$base_dir = ($dir === '.' || $dir === '') ? '' : trailingslashit($dir);

		// Derived sizes and their sources.
		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $sizeinfo) {
				if (!is_array($sizeinfo)) {
					continue;
				}
				$size_files = $this->getFilesFromSizeData($sizeinfo);
				foreach ($size_files as $size_file) {
					if (!empty($size_file) && is_string($size_file)) {
						$keys[] = $base_dir . $size_file;
					}
				}
			}
		}

		// Original image (when WP creates -scaled version and stores original).
		if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
			$keys[] = $base_dir . $metadata['original_image'];
		}

		// Root-level source files (modern image formats).
		$root_source_files = $this->getRootSourceFiles($metadata);
		foreach ($root_source_files as $source_file) {
			if (!empty($source_file) && is_string($source_file)) {
				$keys[] = $base_dir . $source_file;
			}
		}

		// Backup sizes for edited images.
		$backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
		if (is_array($backup_sizes)) {
			foreach ($backup_sizes as $sizeinfo) {
				if (!is_array($sizeinfo) || empty($sizeinfo['file']) || !is_string($sizeinfo['file'])) {
					continue;
				}
				$keys[] = $base_dir . $sizeinfo['file'];
			}
		}

		// Dedupe + remove empties.
		$keys = array_values(array_unique(array_filter($keys, 'strlen')));

		return $keys;
	}

	/**
	 * Delete many objects from the bucket in as few API calls as possible.
	 *
	 * @param string[] $keys
	 * @return bool True if all deletes succeeded (or objects did not exist), false if any errors occurred.
	 */
	private function deleteS3Objects(array $keys): bool
	{
		$keys = array_values(array_unique(array_filter($keys, 'strlen')));
		if (empty($keys)) {
			return true;
		}

		$client = $this->getClient();
		$bucket = $this->getBucket();

		$all_ok = true;
		$chunks = array_chunk($keys, self::MAX_DELETE_OBJECTS);

		foreach ($chunks as $chunk) {
			$objects = array_map(static function ($key) {
				return ['Key' => $key];
			}, $chunk);

			try {
				$result = $client->deleteObjects([
					'Bucket' => $bucket,
					'Delete' => [
						'Objects' => $objects,
						'Quiet'   => true,
					],
				]);

				// Normalize SDK result to array (AWS SDK typically returns Aws\Result which has toArray()).
				$result_arr = null;
				if (is_array($result)) {
					$result_arr = $result;
				} elseif (is_object($result) && method_exists($result, 'toArray')) {
					$result_arr = $result->toArray();
				}

				// AWS SDK can return 'Errors' for per-object failures.
				$errors = is_array($result_arr) && isset($result_arr['Errors']) ? $result_arr['Errors'] : [];
				if (!empty($errors)) {
					$all_ok = false;
					error_log('Advanced Media Offloader: S3 deleteObjects returned errors: ' . wp_json_encode($errors));
				}
			} catch (\Exception $e) {
				// If batch deletion fails (e.g., provider limitation), fall back to per-object deletes.
				$all_ok = false;
				error_log('Advanced Media Offloader: S3 deleteObjects failed, falling back to single deletes. Error: ' . $e->getMessage());

				foreach ($chunk as $key) {
					try {
						$this->deleteS3Object($key);
					} catch (\Exception $inner) {
						$all_ok = false;
						error_log('Advanced Media Offloader: S3 deleteObject failed for key "' . $key . '": ' . $inner->getMessage());
					}
				}
			}
		}

		return $all_ok;
	}

	/**
	 * Delete attachment sizes from cloud storage.
	 *
	 * @param array  $metadata The attachment metadata.
	 * @param string $base_dir The base directory path in cloud storage.
	 * @return void
	 */
	private function deleteAttachmentSizes(array $metadata, string $base_dir): void
	{
		$sizes = $metadata['sizes'];

		foreach ($sizes as $size => $sizeinfo) {
			// Get all files for this size, including sources (Modern Image Formats)
			$size_files = $this->getFilesFromSizeData($sizeinfo);
			
			foreach ($size_files as $size_file) {
				$thumbnail_key = $base_dir . $size_file;
				$this->deleteS3Object($thumbnail_key);
			}
		}

		if (!empty($metadata['original_image'])) {
			$original_image = $base_dir . $metadata['original_image'];
			$this->deleteS3Object($original_image);
		}

		// Delete root-level source files (Modern Image Formats support)
		$root_source_files = $this->getRootSourceFiles($metadata);
		foreach ($root_source_files as $source_file) {
			$source_key = $base_dir . $source_file;
			$this->deleteS3Object($source_key);
		}
	}

	private function deleteImageBackupSizes($attachment_id, $base_dir)
	{
		$backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

		if (!is_array($backup_sizes)) {
			return;
		}

		foreach ($backup_sizes as $size => $sizeinfo) {
			$backup_key = $base_dir . $sizeinfo['file'];
			$this->deleteS3Object($backup_key);
		}
	}

	private function getAttachmentKey(int $attachment_id): string
	{
		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		$advmo_path = get_post_meta($attachment_id, 'advmo_path', true);

		if (empty($attached_file)) {
			throw new \Exception("Unable to find attached file for attachment ID {$attachment_id}");
		}

		// Extract and sanitize the filename from the attached file path
		$file_name = basename($attached_file);
		$file_name = sanitize_file_name($file_name);

		// Validate filename is not empty after sanitization
		if (empty($file_name)) {
			throw new \Exception("Invalid file name for attachment ID {$attachment_id}");
		}

		// Sanitize and validate the path (if provided)
		if (!empty($advmo_path)) {
			$advmo_path = advmo_sanitize_path($advmo_path);
		}

		// If path is empty (either originally or after sanitization), return filename only
		if (empty($advmo_path)) {
			return $file_name;
		}

		// Construct the key with proper directory structure
		return trailingslashit($advmo_path) . $file_name;
	}

	private function deleteS3Object(string $key): void
	{
		$client = $this->getClient();
		$bucket = $this->getBucket();

		$client->deleteObject([
			'Bucket' => $bucket,
			'Key'    => $key,
		]);
	}
}
