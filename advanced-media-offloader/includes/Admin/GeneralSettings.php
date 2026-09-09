<?php

namespace Advanced_Media_Offloader\Admin;

use Advanced_Media_Offloader\Factories\CloudProviderFactory;

class GeneralSettings
{
	private static $instance = null;
	protected array $cloud_providers;
	protected $cloudProviderFactory;

	private function __construct(?CloudProviderFactory $cloudProviderFactory = null)
	{
		// Allow injection or create factory if none provided
		$this->cloudProviderFactory = $cloudProviderFactory ?: new CloudProviderFactory();

		// Get available providers from the factory
		$this->cloud_providers = $this->cloudProviderFactory::getAvailableProviders();

		// Register hooks
		$this->register_hooks();
	}

	public static function getInstance(?CloudProviderFactory $cloudProviderFactory = null): self
	{
		if (self::$instance === null) {
			self::$instance = new self($cloudProviderFactory);
		}
		return self::$instance;
	}

	public static function create(CloudProviderFactory $cloudProviderFactory): self
	{
		return new self($cloudProviderFactory);
	}

	/**
	 * Create an instance of the selected cloud provider.
	 *
	 * @param string $provider_key The cloud provider key.
	 * @return \Advanced_Media_Offloader\Abstracts\S3_Provider|null
	 */
	protected function createCloudProvider(string $provider_key)
	{
		try {
			return $this->cloudProviderFactory::create($provider_key);
		} catch (\Exception $e) {
			error_log('ADVMO Error: ' . $e->getMessage());
			return null;
		}
	}

	private function register_hooks()
	{
		add_action('admin_menu', [$this, 'add_settings_page']);
		add_action('admin_init', [$this, 'initialize']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('wp_ajax_advmo_test_connection', [$this, 'check_connection_ajax']);
		add_action('wp_ajax_advmo_save_general_settings', [$this, 'save_general_settings_ajax']);
		add_action('wp_ajax_advmo_save_credentials', [$this, 'save_credentials_ajax']);
		add_action('wp_ajax_advmo_get_provider_credentials', [$this, 'get_provider_credentials_html_ajax']);
	}

	public function initialize()
	{
		register_setting('advmo', 'advmo_settings', [
			'sanitize_callback' => [$this, 'sanitize']
		]);

		register_setting('advmo', 'advmo_credentials', [
			'sanitize_callback' => [$this, 'sanitize_credentials']
		]);

		$this->add_settings_section();
		$this->add_provider_field();
		$this->add_credentials_field();
		$this->add_auto_offload_field();
		$this->add_retention_policy_field();
		$this->add_path_prefix_field();
		$this->add_object_versioning_field();
		$this->add_mirror_delete_field();
	}

	private function add_settings_section()
	{
		$step_one_done = $this->step_one_state() === 'done';
		$save_button = sprintf(
			'<button type="submit" name="submit" id="submit" class="button button-primary">%s</button>',
			esc_html__('Save Changes', 'advanced-media-offloader')
		);

		// Keep the pending bulk-offload action visible above the settings panels.
		// The stable slot also lets AJAX responses add or clear the notice without
		// reloading the page.
		add_settings_section(
			'advmo_next_action',
			'',
			[$this, 'render_next_action_slot'],
			'advmo',
			[
				'before_section' => '',
				'after_section'  => '',
			]
		);

		add_settings_section(
			'cloud_provider',
			__('Cloud storage', 'advanced-media-offloader'),
			function () {
				echo wp_kses_post($this->step_one_summary());
				echo wp_kses_post($this->step_one_status_badge());
				echo '</summary>';
				echo '<div class="advmo-section-body">';
				echo '<p class="advmo-section-intro">' . esc_html__('Choose a cloud storage provider and enter its connection credentials.', 'advanced-media-offloader') . '</p>';
			},
			'advmo',
			[
				'before_section' => '<details class="advmo-section advmo-cloud-provider-settings"' . ($step_one_done ? '' : ' open') . '><summary class="advmo-section-header">',
				'after_section' => '</div></details>',
			]
		);

		add_settings_section(
			'general_settings',
			__('File handling', 'advanced-media-offloader'),
			function () {
				echo wp_kses_post($this->step_two_summary());
				echo '</summary>';
				echo '<div class="advmo-section-body">';
				echo '<p class="advmo-section-intro">' . esc_html__('Choose what happens to new uploads and local files after they are copied to cloud storage.', 'advanced-media-offloader') . '</p>';
			},
			'advmo',
			[
				'before_section' => '<details class="advmo-section advmo-general-settings"' . ($step_one_done ? ' open' : '') . '><summary class="advmo-section-header">',
				'after_section' => '<div class="advmo-general-actions">' . $save_button . '</div></div></details>',
			]
		);
	}

	/**
	 * Honest status for the cloud storage connection: done only when
	 * the saved provider's last connection test succeeded. A configured but
	 * untested provider is reported distinctly from a failing one.
	 *
	 * @return string One of 'todo', 'untested', 'failed' or 'done'.
	 */
	private function step_one_state(): string
	{
		$provider_key = $this->get_cloud_provider_key();

		if ($provider_key === '') {
			return 'todo';
		}

		$status = get_option('advmo_last_connection_status', []);
		$has_result = is_array($status)
			&& isset($status['success'])
			&& (empty($status['provider']) || $status['provider'] === $provider_key);

		if (!$has_result) {
			return 'untested';
		}

		return !empty($status['success']) ? 'done' : 'failed';
	}

	private function step_one_status_badge(): string
	{
		switch ($this->step_one_state()) {
			case 'done':
				return $this->step_badge('done', __('Done', 'advanced-media-offloader'));

			case 'failed':
				return $this->step_badge('attention', __('Needs attention', 'advanced-media-offloader'));

			// Configured but never tested is not the same as failing, so it
			// gets its own wording rather than an alarming one.
			case 'untested':
				return $this->step_badge('attention', __('Not tested yet', 'advanced-media-offloader'));

			default:
				return $this->step_badge('todo', __('To do', 'advanced-media-offloader'));
		}
	}

	private function step_badge(string $state, string $label): string
	{
		return sprintf(
			'<span class="advmo-step-status advmo-step-status--%s">%s</span>',
			esc_attr($state),
			esc_html($label)
		);
	}

	/**
	 * The line shown in a step's header while it is closed, so collapsing a
	 * step hides its controls rather than what it is set to. Only rendered
	 * when there is something to report — an empty summary would just add
	 * noise to the header.
	 */
	private function step_summary(array $parts): string
	{
		$parts = array_filter($parts, 'strlen');

		if (empty($parts)) {
			return '';
		}

		return sprintf(
			'<span class="advmo-step-summary">%s</span>',
			esc_html(implode(' · ', $parts))
		);
	}

	private function step_one_summary(): string
	{
		$provider_key = $this->get_cloud_provider_key();

		if ($provider_key === '') {
			return '';
		}

		// Read the name from the registered provider list rather than building
		// a provider object: this runs on every page render.
		$name = $this->cloud_providers[$provider_key]['name'] ?? $provider_key;

		return $this->step_summary([
			$name,
			advmo_get_provider_credential($provider_key, 'bucket'),
		]);
	}

	private function step_two_summary(): string
	{
		$options = get_option('advmo_settings', []);

		$retention_labels = [
			0 => __('Retain Local Files', 'advanced-media-offloader'),
			1 => __('Smart Local Cleanup', 'advanced-media-offloader'),
			2 => __('Full Cloud Migration', 'advanced-media-offloader'),
		];
		$retention = isset($options['retention_policy']) ? (int) $options['retention_policy'] : 0;

		$auto_offload = !isset($options['auto_offload_uploads']) || (int) $options['auto_offload_uploads'] === 1;

		return $this->step_summary([
			$retention_labels[$retention] ?? '',
			// Only worth saying when it is off, since on is the default and the
			// expected behaviour.
			$auto_offload ? '' : __('auto-offload off', 'advanced-media-offloader'),
		]);
	}

	private function unoffloaded_media_count(): int
	{
		return $this->get_cloud_provider_key() === ''
			? 0
			: advmo_get_unoffloaded_media_items_count();
	}

	private function next_action_html(): string
	{
		if ($this->step_one_state() !== 'done') {
			return '';
		}

		$count = $this->unoffloaded_media_count();
		if ($count < 1) {
			return '';
		}

		$count_label = number_format_i18n($count);
		$overview_url = admin_url('admin.php?page=advmo_media_overview');

		$html = '<div class="advmo-next-action notice notice-info inline">';
		$html .= '<div class="advmo-next-action-message">';
		$html .= '<span class="dashicons dashicons-cloud" aria-hidden="true"></span>';
		$html .= '<p><strong>' . sprintf(
			/* translators: %s: number of media files ready to offload. */
			esc_html(_n('%s media file is ready to offload.', '%s media files are ready to offload.', $count, 'advanced-media-offloader')),
			esc_html($count_label)
		) . '</strong> ';
		$html .= esc_html__('Move existing Media Library files to cloud storage when you are ready.', 'advanced-media-offloader');
		$html .= '</p></div>';
		$html .= '<a class="button button-primary advmo-next-action-button" href="' . esc_url($overview_url) . '">';
		$html .= sprintf(
			/* translators: %s: number of media files ready to offload. */
			esc_html(_n('Offload %s file', 'Offload %s files', $count, 'advanced-media-offloader')),
			esc_html($count_label)
		);
		$html .= '</a></div>';

		return $html;
	}

	public function render_next_action_slot(): void
	{
		echo '<div class="advmo-next-action-slot" aria-live="polite">';
		echo wp_kses_post($this->next_action_html());
		echo '</div>';
	}

	private function add_provider_field()
	{
		add_settings_field(
			'cloud_provider',
			__('Cloud Provider', 'advanced-media-offloader'),
			[$this, 'cloud_provider_field'],
			'advmo',
			'cloud_provider',
			[
				'class' => 'advmo-field advmo-cloud-provider',
			]
		);
	}

	private function add_credentials_field()
	{
		add_settings_field(
			'advmo_cloud_provider_credentials',
			__('Credentials', 'advanced-media-offloader'),
			[$this, 'cloud_provider_credentials_field'],
			'advmo',
			'cloud_provider',
			[
				'class' => 'advmo-field advmo-cloud-provider-credentials',
			]
		);
	}
	private function add_retention_policy_field()
	{
		add_settings_field(
			'retention_policy',
			__('Retention Policy', 'advanced-media-offloader'),
			[$this, 'retention_policy_field'],
			'advmo',
			'general_settings',
			[
				'class' => 'advmo-field advmo-retention_policy',
			]
		);
	}
	private function add_mirror_delete_field()
	{
		add_settings_field(
			'mirror_delete',
			__('Mirror Delete', 'advanced-media-offloader'),
			[$this, 'mirror_delete_field'],
			'advmo',
			'general_settings',
			[
				'class' => 'advmo-field advmo-mirror-delete',
			]
		);
	}

	private function add_auto_offload_field()
	{
		add_settings_field(
			'auto_offload_uploads',
			__('Auto-Offload Media', 'advanced-media-offloader'),
			[$this, 'auto_offload_field'],
			'advmo',
			'general_settings',
			[
				'class' => 'advmo-field advmo-auto-offload',
			]
		);
	}

	private function add_object_versioning_field()
	{
		add_settings_field(
			'object_versioning',
			__('File Versioning', 'advanced-media-offloader'),
			[$this, 'object_versioning_field'],
			'advmo',
			'general_settings',
			[
				'class' => 'advmo-field advmo-object-versioning',
			]
		);
	}

	private function add_path_prefix_field()
	{
		add_settings_field(
			'path_prefix',
			__('Custom Path Prefix', 'advanced-media-offloader'),
			[$this, 'path_prefix_field'],
			'advmo',
			'general_settings',
			[
				'class' => 'advmo-field advmo-path-prefix',
			]
		);
	}

	public function path_prefix_field()
	{
		$options = get_option('advmo_settings');
		$path_prefix = isset($options['path_prefix']) ? $options['path_prefix'] : "wp-content/uploads/";
		$path_prefix_Active = isset($options['path_prefix_active']) ? $options['path_prefix_active'] : 0;
		echo '<div class="advmo-checkbox-option">';
		echo '<input type="checkbox" id="path_prefix_active" name="advmo_settings[path_prefix_active]" value="1" ' . checked(1, $path_prefix_Active, false) . '/>';
		echo '<label for="path_prefix_active">' . esc_html__('Use Custom Path Prefix', 'advanced-media-offloader') . '</label>';
		echo '<p class="description">' . '<input type="text" id="path_prefix" name="advmo_settings[path_prefix]" value="' . esc_html($path_prefix) . '"' . ($path_prefix_Active ? '' : ' disabled') . '/>'  . '</p>';
		echo '<p class="description">' . esc_html__('Add a common prefix to organize offloaded media files from this site in your cloud storage bucket.', 'advanced-media-offloader') . '</p>';
		echo '</div>';
	}

	public function object_versioning_field()
	{
		$options = get_option('advmo_settings');
		$object_versioning = isset($options['object_versioning']) ? $options['object_versioning'] : 0;

		echo '<div class="advmo-checkbox-option">';
		echo '<input type="checkbox" id="object_versioning" name="advmo_settings[object_versioning]" value="1" ' . checked(1, $object_versioning, false) . '/>';
		echo '<label for="object_versioning">' . esc_html__('Add Version to Bucket Path', 'advanced-media-offloader') . '</label>';
		echo '<p class="description">' . esc_html__('Automatically add unique timestamps to your media file paths to ensure the latest versions are always delivered. This prevents outdated content from being served due to CDN caching, even when you replace files with the same name. Eliminate manual cache invalidation and guarantee your visitors always see the most up-to-date media.', 'advanced-media-offloader') . '</p>';
		echo '</div>';
	}

	public function mirror_delete_field()
	{
		$options = get_option('advmo_settings');
		$mirror_delete = isset($options['mirror_delete']) ? intval($options['mirror_delete']) : 0;
		echo '<div class="advmo-checkbox-option">';
		echo '<input type="checkbox" id="mirror_delete" name="advmo_settings[mirror_delete]" value="1" ' . checked(1, $mirror_delete, false) . '/>';
		echo '<label for="mirror_delete">' . esc_html__('Sync Deletion with Cloud Storage', 'advanced-media-offloader') . '</label>';
		echo '<p class="description">' . esc_html__('When enabled, deleting a media file in WordPress will also remove it from your cloud storage.', 'advanced-media-offloader') . '</p>';
		echo '</div>';
	}

	public function auto_offload_field()
	{
		$options = get_option('advmo_settings');
		$auto_offload_uploads = isset($options['auto_offload_uploads']) ? intval($options['auto_offload_uploads']) : 1;
		echo '<div class="advmo-checkbox-option">';
		echo '<input type="checkbox" id="auto_offload_uploads" name="advmo_settings[auto_offload_uploads]" value="1" ' . checked(1, $auto_offload_uploads, false) . '/>';
		echo '<label for="auto_offload_uploads">' . esc_html__('Upload files to cloud storage', 'advanced-media-offloader') . '</label>';
		echo '<p class="description">' . esc_html__('Automatically send new uploads to cloud storage. Note: Existing offloaded files will always load from the cloud, even if this is disabled.', 'advanced-media-offloader') . '</p>';
		echo '</div>';
	}

	public function retention_policy_field()
	{
		$options = get_option('advmo_settings');
		$retention_policy = isset($options['retention_policy']) ? intval($options['retention_policy']) : 0;

		// The timing belongs to the whole group: deletion only ever runs on a
		// file that has just been copied to the cloud successfully
		// (CloudAttachmentUploader::deleteLocalFile), and nothing revisits
		// files offloaded earlier. Saying it once here lets each option
		// simply answer "what happens to the local file", instead of
		// repeating "after offloading" three times.
		echo '<p class="description advmo-radio-group-intro" id="advmo-retention-policy-desc">'
			. esc_html__('This applies to each file right after it has been copied to cloud storage successfully — nothing is deleted before the cloud copy exists. Changing this does not go back and delete files that were already offloaded.', 'advanced-media-offloader')
			. '</p>';

		$choices = [
			[
				'id'          => 'retention_policy',
				'value'       => 0,
				'title'       => __('Retain Local Files', 'advanced-media-offloader'),
				'badge'       => '',
				'description' => __('Nothing is deleted — the file stays on your server and in the cloud. Frees no disk space, and is a safe starting point until you have checked that your media loads from cloud storage.', 'advanced-media-offloader'),
			],
			[
				'id'          => 'retention_policy_cloud',
				'value'       => 1,
				'title'       => __('Smart Local Cleanup', 'advanced-media-offloader'),
				'badge'       => __('Recommended', 'advanced-media-offloader'),
				'description' => __('Deletes the thumbnail sizes WordPress generated and keeps the original file. Frees up most of the space, while your originals stay on the server as a local backup.', 'advanced-media-offloader'),
			],
			[
				'id'          => 'retention_policy_all',
				'value'       => 2,
				'title'       => __('Full Cloud Migration', 'advanced-media-offloader'),
				'badge'       => '',
				'description' => __('Deletes every local copy, including the original. Frees up the most space, and your media then exists only in cloud storage — keep your own backup.', 'advanced-media-offloader'),
			],
		];

		echo '<div class="advmo-radio-group">';

		foreach ($choices as $choice) {
			$desc_id = $choice['id'] . '_description';

			$badge = $choice['badge'] === ''
				? ''
				: ' <span class="advmo-badge-recommended">' . esc_html($choice['badge']) . '</span>';

			// Spoken name, kept to the option's visible title. The label wraps
			// the description too (so the whole card is clickable), and without
			// this the entire paragraph would become part of the radio's name
			// and be read out on every arrow key. Built from the same strings
			// as the visible text, so the two cannot drift apart.
			$aria_label = $choice['badge'] === ''
				? $choice['title']
				: sprintf(
					/* translators: 1: name of a retention option, 2: badge shown next to it, e.g. "Recommended" */
					_x('%1$s (%2$s)', 'retention option name with badge', 'advanced-media-offloader'),
					$choice['title'],
					$choice['badge']
				);

			// The whole card is the <label>, so clicking anywhere in it —
			// title, description or padding — selects the option. That is
			// native browser behaviour: no JavaScript, and keyboard and
			// screen reader support stay intact.
			printf(
				'<label class="advmo-radio-option" for="%1$s">'
					. '<input type="radio" id="%1$s" name="advmo_settings[retention_policy]" value="%2$d" aria-label="%3$s" aria-describedby="%4$s advmo-retention-policy-desc"%5$s />'
					. '<span class="advmo-radio-option-title">%6$s%7$s</span>'
					. '<span class="description" id="%4$s">%8$s</span>'
					. '</label>',
				esc_attr($choice['id']),
				(int) $choice['value'],
				esc_attr($aria_label),
				esc_attr($desc_id),
				checked($choice['value'], $retention_policy, false),
				esc_html($choice['title']),
				wp_kses_post($badge),
				esc_html($choice['description'])
			);
		}

		echo '</div>';
	}

	public function sanitize($options)
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// Initialize sanitized options with defaults
		$sanitized = [
			'cloud_provider' => '',
			'retention_policy' => 0,
			'object_versioning' => 0,
			'path_prefix' => '',
			'mirror_delete' => 0,
			'path_prefix_active' => 0,
			'auto_offload_uploads' => 1
		];

	try {
		// Validate and sanitize cloud provider
		$sanitized['cloud_provider'] = $this->sanitizeCloudProvider($options);

		// Sanitize retention policy
		$sanitized['retention_policy'] = $this->sanitizeRetentionPolicy($options);

		// Sanitize object versioning
		$sanitized['object_versioning'] = $this->sanitizeObjectVersioning($options);

		// Sanitize path prefix
		$sanitized['path_prefix'] = $this->sanitizePathPrefix($options);

		// Sanitize mirror delete
		$sanitized['mirror_delete'] = isset($options['mirror_delete']) && (int) $options['mirror_delete'] === 1 ? 1 : 0;

		// Sanitize path prefix active
		$sanitized['path_prefix_active'] = isset($options['path_prefix_active']) && (int) $options['path_prefix_active'] === 1 ? 1 : 0;

		// Sanitize auto offload uploads
		$sanitized['auto_offload_uploads'] = isset($options['auto_offload_uploads']) && (int) $options['auto_offload_uploads'] === 1 ? 1 : 0;

		add_settings_error(
			'advmo_messages',
			'advmo_message',
			__('Settings Saved', 'advanced-media-offloader'),
			'updated'
		);

		return $sanitized;
	} catch (\Exception $e) {
		add_settings_error(
			'advmo_messages',
			'advmo_message',
			$e->getMessage(),
			'error'
		);

		return $options;
	}
	}

	/**
	 * Sanitize and save cloud provider credentials
	 *
	 * @param array $credentials
	 * @return array
	 */
	public function sanitize_credentials($credentials)
	{
		if (!current_user_can('manage_options')) {
			return get_option('advmo_credentials', []);
		}

		if (empty($credentials) || !is_array($credentials)) {
			return get_option('advmo_credentials', []);
		}

		// Get existing credentials to preserve other providers
		$sanitized = get_option('advmo_credentials', []);

		// Define checkbox fields that need special handling (unchecked checkboxes don't appear in POST)
		$checkbox_fields = ['path_style_endpoint', 'append_bucket_to_domain'];

		foreach ($credentials as $provider_key => $provider_credentials) {
			if (!is_array($provider_credentials)) {
				continue;
			}

			$sanitized[$provider_key] = [];

			foreach ($provider_credentials as $field_name => $field_value) {
				// Skip if constant is defined (constants take priority)
				$constant_name = 'ADVMO_' . strtoupper($provider_key) . '_' . strtoupper($field_name);
				if (defined($constant_name)) {
					continue;
				}

				// Sanitize based on field type
				if ($field_name === 'endpoint' && $provider_key === 'cloudflare_r2') {
					// Cloudflare shows the endpoint with the bucket appended — strip any path.
					$sanitized[$provider_key][$field_name] = advmo_sanitize_r2_endpoint($field_value);
				} elseif (in_array($field_name, ['endpoint', 'domain'])) {
					// URLs - normalize and validate
					$sanitized[$provider_key][$field_name] = advmo_normalize_url($field_value);
				} elseif (in_array($field_name, ['key', 'secret'])) {
					// API keys/secrets - sanitize as text, preserve special characters
					$sanitized[$provider_key][$field_name] = sanitize_text_field($field_value);
				} elseif (in_array($field_name, $checkbox_fields)) {
					// Boolean values (checkboxes) - '1' = checked, '0' or anything else = unchecked
					$sanitized[$provider_key][$field_name] = ($field_value === '1' || $field_value === 1) ? 1 : 0;
				} else {
					// Other fields (bucket, region, etc.) - sanitize as text
					$sanitized[$provider_key][$field_name] = sanitize_text_field($field_value);
				}
			}

			// Handle unchecked checkboxes - they don't appear in POST, so set them to 0 explicitly
			foreach ($checkbox_fields as $checkbox_field) {
				// Skip if constant is defined (constants take priority)
				$constant_name = 'ADVMO_' . strtoupper($provider_key) . '_' . strtoupper($checkbox_field);
				if (defined($constant_name)) {
					continue;
				}

				// If checkbox field is not in POST data, it means it's unchecked, so set it to 0
				if (!isset($provider_credentials[$checkbox_field])) {
					$sanitized[$provider_key][$checkbox_field] = 0;
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitizes the cloud provider setting
	 *
	 * @param array $options
	 * @return string
	 * @throws Exception
	 */
	private function sanitizeCloudProvider(array $options): string
	{

		$provider = sanitize_text_field(isset($options['cloud_provider']) ? $options['cloud_provider'] : '');

		if ($provider === '') {
			throw new \Exception(esc_html__('Please select a cloud provider first.', 'advanced-media-offloader'));
		}

		if (!array_key_exists($provider, $this->cloud_providers)) {
			throw new \Exception(esc_html__('Invalid Cloud Provider!', 'advanced-media-offloader'));
		}

		return $provider;
	}

	/**
	 * Sanitizes the retention policy setting
	 *
	 * @param array $options
	 * @return int
	 */
	private function sanitizeRetentionPolicy(array $options): int
	{
		$policy = isset($options['retention_policy']) ? (int) $options['retention_policy'] : 0;
		return in_array($policy, [0, 1, 2], true) ? $policy : 0;
	}

	/**
	 * Sanitizes the object versioning setting
	 *
	 * @param array $options
	 * @return int
	 */
	private function sanitizeObjectVersioning(array $options): int
	{
		return isset($options['object_versioning']) && (int) $options['object_versioning'] === 1 ? 1 : 0;
	}

	/**
	 * Sanitizes the path prefix setting
	 *
	 * @param array $options
	 * @return string
	 */
	private function sanitizePathPrefix(array $options): string
	{
		return isset($options['path_prefix']) ? advmo_sanitize_path($options['path_prefix']) : '';
	}

	public function add_settings_page()
	{

		// Advanced Media Offload Logo as Icon
		$svg_icon = '<svg width="358" height="258" viewBox="0 0 358 258" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path fill-rule="evenodd" clip-rule="evenodd" d="M0.0100098 176.677C0.0100098 140.074 24.3664 109.179 57.758 99.2859C62.823 43.9549 109.353 0.616943 166.006 0.616943C207.398 0.616943 243.362 23.7509 261.718 57.7489C316.583 65.5989 357.99 115.763 357.99 171.712C357.99 203.324 345.663 225.134 329.249 238.845C313.363 252.115 294.346 257.237 280.921 257.383H280.848H280.776C240.825 257.383 208.247 225.816 206.624 186.263L201.404 192.33C196.595 197.919 188.166 198.551 182.577 193.741C176.988 188.932 176.356 180.503 181.165 174.914L202.899 149.657C212.952 137.974 231.116 138.198 240.879 150.125L261.374 175.166C266.044 180.872 265.204 189.283 259.498 193.953C253.792 198.623 245.381 197.783 240.711 192.077L233.261 182.975C233.261 183.039 233.261 183.104 233.261 183.168C233.261 209.385 254.494 230.643 280.701 230.683C288.571 230.579 301.441 227.284 312.132 218.353C322.328 209.836 331.29 195.601 331.29 171.712C331.29 126.154 295.784 86.5289 252.109 83.5669C250.177 83.4359 248.225 83.3689 246.256 83.3689C199.345 83.3689 161.307 121.356 161.223 168.247L161.423 176.516V176.677C161.423 221.25 125.289 257.383 80.716 257.383C36.143 257.383 0.0100098 221.25 0.0100098 176.677ZM100.765 99.042C96.041 97.338 89.913 96.4629 85.057 96.1309C91.366 57.1129 125.206 27.3169 166.006 27.3169C191.795 27.3169 214.82 39.222 229.861 57.863C175.925 65.794 134.522 112.263 134.522 168.402V168.563L134.722 176.829C134.64 206.586 110.492 230.683 80.716 230.683C50.89 230.683 26.7104 206.504 26.7104 176.677C26.7104 149.604 46.644 127.163 72.632 123.27C75.262 122.876 77.961 122.671 80.716 122.671C84.182 122.671 89.51 123.366 91.705 124.158C98.641 126.66 106.291 123.065 108.793 116.13C111.295 109.194 107.701 101.543 100.765 99.042Z" fill="#A7AAAD"/>
					</svg>';
		$icon_base64 = 'data:image/svg+xml;base64,' . base64_encode($svg_icon);

		add_menu_page(
			__('Advanced Media Offloader', 'advanced-media-offloader'),
			__('Media Offloader', 'advanced-media-offloader'),
			'manage_options',
			'advmo',
			[$this, 'general_settings_page_view'],
			$icon_base64,
			100
		);

		add_submenu_page(
			'advmo',
			__('General Settings', 'advanced-media-offloader'),
			__('General Settings', 'advanced-media-offloader'),
			'manage_options',
			'advmo',
			[$this, 'general_settings_page_view']
		);
	}

	public function cloud_provider_field($args)
	{
		$options = get_option('advmo_settings', []);
		$current_provider = isset($options['cloud_provider']) && !empty($options['cloud_provider'])
			? $options['cloud_provider']
			: '';

		echo '<select name="advmo_settings[cloud_provider]">';

		// Add placeholder option if no provider is selected
		if (empty($current_provider)) {
			echo '<option value="" selected disabled>' . esc_html__('Select a cloud provider', 'advanced-media-offloader') . '</option>';
		}

		foreach ($this->cloud_providers as $key => $provider) {
			$disabled = isset($provider['class']) && $provider['class'] === null ? 'disabled' : '';
			$selected = $current_provider === $key ? 'selected' : '';
			echo '<option value="' . esc_attr($key) . '" ' . esc_attr($selected) . ' ' . esc_attr($disabled) . '>' . esc_html($provider['name']) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Get the cloud provider key from the plugin settings.
	 *
	 * @return string The cloud provider key or an empty string if not set.
	 */
	private function get_cloud_provider_key(): string
	{
		return advmo_get_cloud_provider_key();
	}

	public function cloud_provider_credentials_field()
	{
		$cloud_provider_key = $this->get_cloud_provider_key();

		if (!empty($cloud_provider_key)) {
			try {
				// Use the CloudProviderFactory to create an instance of the selected cloud provider
				/** @var CloudProviderInterface $cloud_provider_instance */
				$cloud_provider_instance = CloudProviderFactory::create($cloud_provider_key);

				// Render the credentials fields specific to the selected cloud provider
				$cloud_provider_instance->credentialsField();
			} catch (\Exception $e) {
				// Display an error message if the cloud provider is unsupported or instantiation fails
				echo '<p class="description">' . esc_html__('Selected cloud provider is not supported or failed to initialize.', 'advanced-media-offloader') . '</p>';
			}
		} else {
			echo '<p class="description">' . esc_html__('Please select a valid cloud provider to configure credentials.', 'advanced-media-offloader') . '</p>';
		}
	}

	public function general_settings_page_view()
	{
		advmo_get_view('admin/general_settings');
	}

	public function enqueue_scripts($hook_suffix = '')
	{
		if (!advmo_is_settings_page()) {
			return;
		}

		if (advmo_is_settings_page('general')) {
			wp_enqueue_script('advmo_settings', ADVMO_URL . 'assets/js/advmo_settings.js', [], ADVMO_VERSION, true);
			wp_localize_script('advmo_settings', 'advmo_ajax_object', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('advmo_test_connection'),
				'save_general_nonce' => wp_create_nonce('advmo_save_general_settings'),
				'save_credentials_nonce' => wp_create_nonce('advmo_save_credentials'),
				'get_provider_credentials_nonce' => wp_create_nonce('advmo_get_provider_credentials'),
				'i18n' => [
					'testing' => __('Testing connection…', 'advanced-media-offloader'),
					'copied' => __('Copied!', 'advanced-media-offloader'),
					'copy_failed' => __('Press Ctrl/Cmd + C to copy', 'advanced-media-offloader'),
					'connected' => __('Connected', 'advanced-media-offloader'),
					'connection_failed' => __('Connection failed', 'advanced-media-offloader'),
					'test_failed_network' => __('Could not run the test. Check your internet connection and try again.', 'advanced-media-offloader'),
					'failed_load_credentials' => __('Failed to load credentials fields.', 'advanced-media-offloader'),
					'error_loading_credentials' => __('An error occurred while loading credentials fields. Please try again.', 'advanced-media-offloader'),
					'failed_save_provider' => __('Failed to save the selected provider. Please try again.', 'advanced-media-offloader'),
					'failed_save_credentials' => __('Failed to save credentials.', 'advanced-media-offloader'),
					'failed_save_general' => __('Failed to save general settings.', 'advanced-media-offloader'),
					'error_saving' => __('An error occurred while saving settings. Please try again.', 'advanced-media-offloader')
				]
			]);
		}

		if (advmo_is_settings_page('support')) {
			wp_enqueue_script('advmo_support', ADVMO_URL . 'assets/js/advmo_support.js', [], ADVMO_VERSION, true);
		}

		if (
			advmo_is_settings_page('media-overview')
			&& is_string($hook_suffix)
			&& strlen($hook_suffix) >= strlen('_page_advmo_media_overview')
			&& 0 === substr_compare($hook_suffix, '_page_advmo_media_overview', -strlen('_page_advmo_media_overview'))
		) {
			wp_enqueue_script('advmo_bulkoffload', ADVMO_URL . 'assets/js/advmo_bulkoffload.js', [], ADVMO_VERSION, true);
			wp_localize_script('advmo_bulkoffload', 'advmo_ajax_object', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'bulk_offload_nonce' => wp_create_nonce('advmo_bulk_offload')
			]);
		}


		wp_enqueue_style('advmo_admin', ADVMO_URL . 'assets/css/admin.css', [], ADVMO_VERSION);

		// Enqueue RTL styles if needed
		if (is_rtl()) {
			wp_enqueue_style('advmo_admin_rtl', ADVMO_URL . 'assets/css/admin-rtl.css', ['advmo_admin'], ADVMO_VERSION);
		}
	}

	public function check_connection_ajax()
	{
		$response_data = [];

		// Verify nonce
		if (!$this->verify_security_nonce('security_nonce', 'advmo_test_connection')) {
			$response_data['message'] = __('Invalid nonce!', 'advanced-media-offloader');
			wp_send_json_error($response_data);
		}

		// Check user capabilities
		if (!current_user_can('manage_options')) {
			$response_data['message'] = __('You do not have permission to perform this action.', 'advanced-media-offloader');
			wp_send_json_error($response_data);
		}

		// Retrieve plugin settings
		$cloud_provider_key = $this->get_cloud_provider_key();

		if (empty($cloud_provider_key)) {
			$response_data['message'] = __('Please select a cloud provider and save your settings first.', 'advanced-media-offloader');
			wp_send_json_error($response_data);
		}

		try {
			$cloud_provider = $this->createCloudProvider($cloud_provider_key);
			if (!$cloud_provider) {
				throw new \Exception(__('Could not initialize cloud provider', 'advanced-media-offloader'));
			}

			$connection_result = $cloud_provider->checkConnection();
			$is_connected = ($connection_result === true);

			$error_message = '';
			$error_hint = '';
			$error_code = '';
			if (!$is_connected) {
				$error_message = $connection_result->get_error_message();
				$error_data = (array) $connection_result->get_error_data();
				$error_hint = isset($error_data['hint']) ? (string) $error_data['hint'] : '';
				$error_code = isset($error_data['code']) ? (string) $error_data['code'] : '';
			}

			// The stored status records the check time regardless of result
			$stored_status = $this->store_connection_status($cloud_provider_key, $is_connected, $error_message, $error_hint, $error_code);

			// The card in the response is produced by the same renderer the
			// settings page uses, so the two presentations can never drift.
			$response_data['status_html'] = $cloud_provider->renderConnectionStatusCard($stored_status);
			$response_data['setup_status'] = $this->get_setup_status_fragments();

			if ($is_connected) {
				$response_data['message'] = __('Connection successful!', 'advanced-media-offloader');
				wp_send_json_success($response_data);
			} else {
				$response_data['message'] = $error_message;
				wp_send_json_error($response_data, 401);
			}
		} catch (\Exception $e) {
			error_log('Advanced Media Offloader Connection Error: ' . $e->getMessage());

			$response_data['message'] = sprintf(
				/* translators: %s: error message */
				__('Failed to establish a connection: %s', 'advanced-media-offloader'),
				esc_html($e->getMessage())
			);
			$stored_status = $this->store_connection_status($cloud_provider_key, false, $response_data['message']);
			if (isset($cloud_provider) && $cloud_provider instanceof \Advanced_Media_Offloader\Abstracts\S3_Provider) {
				$response_data['status_html'] = $cloud_provider->renderConnectionStatusCard($stored_status);
			}
			$response_data['setup_status'] = $this->get_setup_status_fragments();
			wp_send_json_error($response_data, 500);
		}
	}

	/**
	 * The parts of the guided setup UI that depend on live state.
	 *
	 * These are rendered server-side on page load, so any AJAX action that
	 * changes the state must send them back — otherwise the step badges keep
	 * showing what was true when the page was opened (e.g. "Needs attention"
	 * next to a green connection card).
	 *
	 * @return array{step_one_badge: string, step_one_summary: string, step_two_summary: string, next_action: string}
	 */
	private function get_setup_status_fragments(): array
	{
		return [
			'step_one_badge'   => $this->step_one_status_badge(),
			'step_one_summary' => $this->step_one_summary(),
			'step_two_summary' => $this->step_two_summary(),
			'next_action'      => $this->next_action_html(),
		];
	}

	/**
	 * Persist the result of the last connection test so the settings page can
	 * render it without a live network call.
	 */
	private function store_connection_status(string $provider_key, bool $success, string $message = '', string $hint = '', string $code = ''): array
	{
		$status = [
			'provider' => $provider_key,
			'success'  => $success,
			'message'  => $message,
			'hint'     => $hint,
			'code'     => $code,
			'time'     => time(),
		];

		update_option('advmo_last_connection_status', $status, false);

		return $status;
	}

	/**
	 * AJAX handler for saving general settings.
	 */
	public function save_general_settings_ajax()
	{
		// Verify nonce
		if (!$this->verify_security_nonce('security_nonce', 'advmo_save_general_settings')) {
			wp_send_json_error([
				'message' => __('Invalid security token!', 'advanced-media-offloader')
			]);
		}

		// Check user capabilities
		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'advanced-media-offloader')
			]);
		}

		// Get the posted settings
		$settings = isset($_POST['advmo_settings']) ? wp_unslash($_POST['advmo_settings']) : [];

		try {
			// Sanitize using a direct validation approach for AJAX
			$sanitized_settings = $this->sanitize_for_ajax($settings);

			// Update the option
			update_option('advmo_settings', $sanitized_settings);

			// Success
			wp_send_json_success([
				'message' => __('Settings saved successfully!', 'advanced-media-offloader'),
				// Switching provider changes which step is done, and no test
				// runs on this path, so refresh the step badges here too.
				'setup_status' => $this->get_setup_status_fragments()
			]);
		} catch (\Exception $e) {
			// Catch any validation errors and return them directly
			wp_send_json_error([
				'message' => $e->getMessage()
			]);
		}
	}

	/**
	 * Sanitize settings for AJAX calls (without using WordPress settings errors system)
	 *
	 * @param array $options
	 * @return array
	 * @throws \Exception
	 */
	private function sanitize_for_ajax($options)
	{
		if (!current_user_can('manage_options')) {
			throw new \Exception(esc_html__('You do not have permission to perform this action.', 'advanced-media-offloader'));
		}

		// Initialize sanitized options with defaults
		$sanitized = [
			'cloud_provider' => '',
			'retention_policy' => 0,
			'object_versioning' => 0,
			'path_prefix' => '',
			'mirror_delete' => 0,
			'path_prefix_active' => 0,
			'auto_offload_uploads' => 1
		];

		// Validate and sanitize cloud provider
		$sanitized['cloud_provider'] = $this->sanitizeCloudProvider($options);

		// Sanitize retention policy
		$sanitized['retention_policy'] = $this->sanitizeRetentionPolicy($options);

		// Sanitize object versioning
		$sanitized['object_versioning'] = $this->sanitizeObjectVersioning($options);

		// Sanitize path prefix
		$sanitized['path_prefix'] = $this->sanitizePathPrefix($options);

		// Sanitize mirror delete
		$sanitized['mirror_delete'] = isset($options['mirror_delete']) && (int) $options['mirror_delete'] === 1 ? 1 : 0;

		// Sanitize path prefix active
		$sanitized['path_prefix_active'] = isset($options['path_prefix_active']) && (int) $options['path_prefix_active'] === 1 ? 1 : 0;

		// Sanitize auto offload uploads
		$sanitized['auto_offload_uploads'] = isset($options['auto_offload_uploads']) && (int) $options['auto_offload_uploads'] === 1 ? 1 : 0;

		return $sanitized;
	}

	/**
	 * AJAX handler for saving credentials.
	 */
	public function save_credentials_ajax()
	{
		// Verify nonce
		if (!$this->verify_security_nonce('security_nonce', 'advmo_save_credentials')) {
			wp_send_json_error([
				'message' => __('Invalid security token!', 'advanced-media-offloader')
			]);
		}

		// Check user capabilities
		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'advanced-media-offloader')
			]);
		}

		// Get the posted credentials
		$credentials = isset($_POST['advmo_credentials']) ? wp_unslash($_POST['advmo_credentials']) : [];

		// Sanitize using existing method
		$sanitized_credentials = $this->sanitize_credentials($credentials);

		// Update the option
		$updated = update_option('advmo_credentials', $sanitized_credentials);

		// Success
		wp_send_json_success([
			'message' => __('Credentials saved successfully!', 'advanced-media-offloader')
		]);
	}

	/**
	 * AJAX handler for getting provider credentials HTML.
	 */
	public function get_provider_credentials_html_ajax()
	{
		// Verify nonce
		if (!$this->verify_security_nonce('security_nonce', 'advmo_get_provider_credentials')) {
			wp_send_json_error([
				'message' => __('Invalid security token!', 'advanced-media-offloader')
			]);
		}

		// Check user capabilities
		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'advanced-media-offloader')
			]);
		}

		// Get the provider from POST
		$provider_key = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';

		if (empty($provider_key)) {
			wp_send_json_error([
				'message' => __('No provider specified.', 'advanced-media-offloader')
			]);
		}

		// Validate provider exists
		if (!array_key_exists($provider_key, $this->cloud_providers)) {
			wp_send_json_error([
				'message' => __('Invalid cloud provider.', 'advanced-media-offloader')
			]);
		}

		try {
			// Create an instance of the selected cloud provider
			$cloud_provider_instance = CloudProviderFactory::create($provider_key);

			// Capture the credentials field HTML
			ob_start();
			$cloud_provider_instance->credentialsField();
			$credentials_html = ob_get_clean();

			// Success - return the HTML
			wp_send_json_success([
				'html' => $credentials_html,
				'provider' => $provider_key
			]);
		} catch (\Exception $e) {
			wp_send_json_error([
				'message' => sprintf(
					/* translators: %s: error message */
					__('Failed to load credentials fields: %s', 'advanced-media-offloader'),
					esc_html($e->getMessage())
				)
			]);
		}
	}

	/**
	 * Verify the security nonce for AJAX requests.
	 *
	 * @return bool Whether the nonce is valid.
	 */
	private function verify_security_nonce($name, $action)
	{
		$security_nonce = isset($_POST[$name]) ? sanitize_text_field($_POST[$name]) : '';
		return wp_verify_nonce($security_nonce, $action);
	}

	// Prevent cloning of the instance
	private function __clone() {}

	// Prevent unserializing of the instance
	public function __wakeup() {}
}
