<?php
if (!defined('ABSPATH')) {
	die('No direct script access allowed');
}

/**
 * Include a view file from the plugin directory.
 *
 * @param string $file The file path relative to the plugin directory.
 * @return void
 */
if (!function_exists('advmo_get_view')) {
	function advmo_get_view(string $template)
	{
		if (file_exists(ADVMO_PATH . 'templates/' . $template . '.php')) {
			include ADVMO_PATH . 'templates/' . $template . '.php';
		}
	}
}

/**
 * Normalize and validate a URL, ensuring it has a proper scheme (https://).
 * 
 * This function automatically adds https:// if missing and validates the URL
 * using WordPress built-in functions.
 *
 * @param string $url The URL to normalize.
 * @return string The normalized URL with https:// scheme, or empty string if invalid.
 */
if (!function_exists('advmo_normalize_url')) {
	function advmo_normalize_url(string $url): string
	{
		// Return empty string if URL is empty
		if (empty($url)) {
			return '';
		}

		// Trim whitespace
		$url = trim($url);

		// If URL doesn't start with http:// or https://, add https://
		if (!preg_match('/^https?:\/\//i', $url)) {
			$url = 'https://' . $url;
		}

		// Validate the URL using WordPress built-in function
		$validated_url = esc_url_raw($url, ['http', 'https']);

		// Double-check with filter_var for extra validation
		if ($validated_url && filter_var($validated_url, FILTER_VALIDATE_URL)) {
			return $validated_url;
		}

		// If validation fails, log a warning and return empty string
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
				'ADVMO Warning: Invalid URL "%s" was provided. Please check your wp-config.php settings.',
				$url
			));
		}

		return '';
	}
}

/**
 * Helper: Get public URL for an attachment
 */
if (!function_exists('advmo_get_public_url')) {
	function advmo_get_public_url($attachment_id)
	{
		$url = wp_get_attachment_url($attachment_id);
		return $url;
	}
}

if (!function_exists('advmo_is_settings_page')) {
	function advmo_is_settings_page($page_name = ''): bool
	{
		$current_screen = get_current_screen();

		if (!$current_screen) {
			return false;
		}

		// Get the current page from the query string
		$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

		// Define our plugin pages
		$plugin_pages = [
			'general' => 'advmo',
			'media-overview' => 'advmo_media_overview'
		];

		// If a specific page is requested
		if (!empty($page_name)) {
			if (!isset($plugin_pages[$page_name])) {
				return false; // Invalid page name provided
			}

			// Check if the current page matches the requested page
			return $current_page === $plugin_pages[$page_name];
		}

		// Check if we're on any plugin page
		return in_array($current_page, array_values($plugin_pages));
	}
}


/**
 * Generate copyright text
 *
 * @return string
 */
/**
 * Generate copyright and support text
 *
 * @return string
 */
if (!function_exists('advmo_get_copyright_text')) {
	function advmo_get_copyright_text(): string
	{
		$year = date('Y');
		$site_url = 'https://wpfitter.com/?utm_source=wp-plugin&utm_medium=plugin&utm_campaign=advanced-media-offloader';

		$donate = '';
		if (apply_filters('advmo_show_donate_links', true)) {
			$donate_url = add_query_arg(array(
				'utm_source'   => 'wp-plugin',
				'utm_medium'   => 'admin-footer',
				'utm_campaign' => 'advanced-media-offloader',
				'utm_content'  => 'footer-donate',
			), 'https://buymeacoffee.com/wpfitter');
			$donate = sprintf(
				'<span style="opacity:.85;"> %s <a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a></span>',
				esc_html__('Enjoying the plugin?', 'advanced-media-offloader'),
				esc_url($donate_url),
				esc_attr__('Donate to support Advanced Media Offloader', 'advanced-media-offloader'),
				esc_html__('Donate', 'advanced-media-offloader')
			);
		}

		return sprintf(
			'Advanced Media Offloader plugin developed by <a href="%s" target="_blank">WPFitter</a>. %s',
			esc_url($site_url),
			$donate
		);
	}
}

/**
 * Get bulk offload data.
 *
 * @return array The bulk offload data.
 */
if (!function_exists('advmo_get_bulk_offload_data')) {
	function advmo_get_bulk_offload_data(): array
	{
		$defaults = array(
			'total' => 0,
			'status' => '',
			'processed' => 0,
			'errors' => 0,
			'last_update' => null,
			'oversized_skipped' => 0
		);

		$stored_data = get_option('advmo_bulk_offload_data', array());

		return array_merge($defaults, $stored_data);
	}
}

/**
 * Update bulk offload data.
 *
 * @param array $new_data The new data to update.
 * @return array The updated bulk offload data.
 */
if (!function_exists('advmo_update_bulk_offload_data')) {
	function advmo_update_bulk_offload_data(array $new_data): array
	{
		// Define the allowed keys
		$allowed_keys = array(
			'total',
			'status',
			'processed',
			'errors',
			'oversized_skipped',
			'last_recovery',
			'last_cleanup',
			'last_update'
		);

		// Filter the new data to only include allowed keys
		$filtered_new_data = array_intersect_key($new_data, array_flip($allowed_keys));

		// Get the existing data
		$existing_data = advmo_get_bulk_offload_data();

		// Merge the filtered new data with the existing data
		$updated_data = array_merge($existing_data, $filtered_new_data);

		// Ensure only allowed keys are in the final data set
		$final_data = array_intersect_key($updated_data, array_flip($allowed_keys));

		// Keep counters internally consistent: processed should never exceed total.
		// This can happen if background tasks overlap/retry while total comes from a smaller batch selection.
		if (isset($final_data['processed'], $final_data['total']) && (int) $final_data['processed'] > (int) $final_data['total']) {
			$final_data['total'] = (int) $final_data['processed'];
		}

		// Normalise timestamp fields.
		foreach (array('last_recovery', 'last_cleanup') as $timestamp_key) {
			if (isset($final_data[$timestamp_key])) {
				$final_data[$timestamp_key] = (int) $final_data[$timestamp_key];
			}
		}

		// Add a timestamp for the last update if not explicitly provided.
		if (!isset($filtered_new_data['last_update'])) {
			$final_data['last_update'] = time();
		} else {
			$final_data['last_update'] = (int) $filtered_new_data['last_update'];
		}

		// Update the option in the database
		update_option('advmo_bulk_offload_data', $final_data);
		update_option('advmo_bulk_offload_last_update', $final_data['last_update']);

		return $final_data;
	}
}

/**
 * Check if media is organized by year and month.
 *
 * @return bool True if media is organized by year and month, false otherwise.
 */
if (!function_exists('advmo_is_media_organized_by_year_month')) {
	function advmo_is_media_organized_by_year_month(): bool
	{
		return get_option('uploads_use_yearmonth_folders') ? true : false;
	}
}

/**
 * Sanitize a URL path.
 *
 * @param string $path The path to sanitize.
 * @return string The sanitized path.
 */
if (!function_exists('advmo_sanitize_path')) {
	function advmo_sanitize_path(string $path): string
	{
		// Remove leading and trailing whitespace
		$path = trim($path);

		// Remove or encode potentially harmful characters
		$path = wp_sanitize_redirect($path);

		// Convert to lowercase for consistency (optional, depending on your needs)
		$path = strtolower($path);

		// Remove any directory traversal attempts
		$path = str_replace(['../', './'], '', $path);

		// Normalize slashes and remove duplicate slashes
		$path = preg_replace('#/+#', '/', $path);

		// Remove leading and trailing slashes
		$path = trim($path, '/');

		// Optionally, you can use wp_normalize_path() if you want to ensure consistent directory separators
		$path = wp_normalize_path($path);

		return $path;
	}
}

/**
 * Clear bulk offload data.
 *
 * @return void
 */
if (!function_exists('advmo_clear_bulk_offload_data')) {
	function advmo_clear_bulk_offload_data(): void
	{
		delete_option('advmo_bulk_offload_data');
	}
}

/**
 * Get the cloud provider key.
 *
 * @return string The cloud provider key.
 */
if (!function_exists('advmo_get_cloud_provider_key')) {
	function advmo_get_cloud_provider_key(): string
	{
		$options = get_option('advmo_settings', []);
		return $options['cloud_provider'] ?? '';
	}
}

/**
 * Get the count of unoffloaded media items.
 *
 * @return int The count of unoffloaded media items.
 */
if (!function_exists('advmo_get_unoffloaded_media_items_count')) {
	function advmo_get_unoffloaded_media_items_count(): int
	{
		global $wpdb;

		$query = "SELECT COUNT(*) FROM {$wpdb->posts} p 
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'advmo_offloaded'
			WHERE p.post_type = 'attachment' 
			AND (pm.meta_value IS NULL OR pm.meta_value = '')";

		return (int) $wpdb->get_var($query);
	}
}

if (!function_exists('advmo_get_offloaded_media_items_count')) {
	function advmo_get_offloaded_media_items_count(): int
	{
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) 
			FROM {$wpdb->posts} p 
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
			WHERE p.post_type = %s 
			AND p.post_status != %s 
			AND pm.meta_key = %s 
			AND pm.meta_value != %s",
			array(
				'attachment',
				'trash',
				'advmo_offloaded',
				''
			)
		);

		return (int) $wpdb->get_var($query);
	}
}

/**
 * Check if a credential constant exists in wp-config.php
 *
 * @param string $constant_name The name of the constant to check.
 * @return bool True if constant is defined, false otherwise.
 */
if (!function_exists('advmo_credential_exists_in_config')) {
	function advmo_credential_exists_in_config(string $constant_name): bool
	{
		return defined($constant_name);
	}
}

/**
 * Get provider credentials from constants or saved options.
 * Constants take priority over saved options.
 *
 * @param string $provider_key The cloud provider key (e.g., 'cloudflare_r2', 'amazon_s3').
 * @param string $field_name The credential field name (e.g., 'key', 'secret', 'bucket').
 * @return string The credential value or empty string if not found.
 */
if (!function_exists('advmo_get_provider_credential')) {
	function advmo_get_provider_credential(string $provider_key, string $field_name): string
	{
		// Build constant name (e.g., ADVMO_CLOUDFLARE_R2_KEY)
		$constant_name = 'ADVMO_' . strtoupper($provider_key) . '_' . strtoupper($field_name);
		
		// Check if constant exists first (priority)
		if (defined($constant_name)) {
			$value = constant($constant_name);
			// Support scalar constant types (common in wp-config.php), especially booleans for checkboxes.
			if (is_bool($value)) {
				return $value ? '1' : '0';
			}
			if (is_int($value) || is_float($value)) {
				return (string) $value;
			}
			return is_string($value) ? $value : '';
		}
		
		// Fall back to saved options
		$credentials = get_option('advmo_credentials', []);
		if (isset($credentials[$provider_key][$field_name])) {
			return $credentials[$provider_key][$field_name];
		}
		
		return '';
	}
}

/**
 * Save provider credentials to options.
 *
 * @param string $provider_key The cloud provider key.
 * @param array $credentials Associative array of credential fields and values.
 * @return bool True on success, false on failure.
 */
if (!function_exists('advmo_save_provider_credentials')) {
	function advmo_save_provider_credentials(string $provider_key, array $credentials): bool
	{
		$all_credentials = get_option('advmo_credentials', []);
		$all_credentials[$provider_key] = $credentials;
		return update_option('advmo_credentials', $all_credentials);
	}
}

/**
 * Get all credentials for a specific provider.
 *
 * @param string $provider_key The cloud provider key.
 * @return array Associative array of all credential fields for the provider.
 */
if (!function_exists('advmo_get_provider_credentials')) {
	function advmo_get_provider_credentials(string $provider_key): array
	{
		$credentials = get_option('advmo_credentials', []);
		return $credentials[$provider_key] ?? [];
	}
}
