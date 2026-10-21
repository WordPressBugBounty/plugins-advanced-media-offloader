<?php
/*
 * Plugin Name:       Advanced Media Offloader
 * Plugin URI:        https://wpfitter.com/plugins/advanced-media-offloader/
 * Description:       Save server space & speed up your site by automatically offloading media to Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 and more.
 * Version:           4.7.0
 * Requires at least: 5.6
 * Requires PHP:      8.1
 * Author:            WP Fitter
 * Author URI:        https://wpfitter.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       advanced-media-offloader
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

if (!class_exists('ADVMO')) {
	/**
	 * The main ADVMO class
	 */
	class ADVMO
	{

		/** @var \Advanced_Media_Offloader\Core\Container */
		public $container;

		/** @var \AdvancedMediaOffloader\Foundation\Application */
		private $app;

		/**
		 * The plugin version number.
		 *
		 * @var string
		 */
		public $version;

		/**
		 * The offloader instance.
		 *
		 * @var Advanced_Media_Offloader\Offloader
		 */
		public $offloader;

		/**
		 * The plugin data array.
		 *
		 * @var array
		 */
		public $data = array();

		/**
		 * A dummy constructor to ensure WP Fitter Media Offloader is only setup once.
		 *
		 * @since   1.0.0
		 *
		 * @return  void
		 */
		public function __construct()
		{
			$plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
			$this->version = $plugin_data['Version'];
		}

		/**
		 * Sets up the Advanced Media Offloader
		 *
		 * @since   1.0.0
		 *
		 * @return  void
		 */
		public function initialize()
		{

			// Define constants.
			$this->define('ADVMO', true);
			$this->define('ADVMO_PATH', plugin_dir_path(__FILE__));
			$this->define('ADVMO_URL', plugin_dir_url(__FILE__));
			$this->define('ADVMO_BASENAME', plugin_basename(__FILE__));
			$this->define('ADVMO_VERSION', $this->version);
			$this->define('ADVMO_API_VERSION', 1);

			// Register activation hook.
			register_activation_hook(__FILE__, array($this, 'plugin_activated'));

			// Register deactivation hook.
			register_deactivation_hook(__FILE__, array($this, 'plugin_deactivated'));

			$this->setup_container();
			$this->include_files();

			// Services are resolved on plugins_loaded, once add-ons have registered their providers.
			// Priority 0 keeps them ready as early as possible after every plugin file is loaded.
			if (did_action('plugins_loaded')) {
				$this->boot();
			} else {
				add_action('plugins_loaded', array($this, 'boot'), 0);
			}
		}

		private function setup_container()
		{
			if (file_exists(ADVMO_PATH . 'vendor/scoper-autoload.php')) {
				require_once ADVMO_PATH . 'vendor/scoper-autoload.php';
			} elseif (file_exists(ADVMO_PATH . 'vendor/autoload.php')) {
				require_once ADVMO_PATH . 'vendor/autoload.php';
			}

			$this->app = new \AdvancedMediaOffloader\Foundation\Application(__FILE__);
			$this->container = new \Advanced_Media_Offloader\Core\Container($this->app);
			$this->app->instance(self::class, $this);
			$this->app->instance(\Advanced_Media_Offloader\Core\Container::class, $this->container);

			$this->app->register(\AdvancedMediaOffloader\Providers\StorageServiceProvider::class);
			$this->app->register(\AdvancedMediaOffloader\Providers\AdminServiceProvider::class);
			$this->app->register(\AdvancedMediaOffloader\Providers\OffloadServiceProvider::class);
			$this->app->register(\AdvancedMediaOffloader\Providers\MediaServiceProvider::class);
			$this->app->register(\AdvancedMediaOffloader\Providers\AbilitiesServiceProvider::class);
		}

		public function app(): \AdvancedMediaOffloader\Foundation\Application
		{
			return $this->app;
		}

		/**
		 * Runs on plugins_loaded. Hooked by initialize(); not meant to be called directly.
		 */
		public function boot()
		{
			if ($this->app->isBooted()) {
				return;
			}

			// This guard covers metadata left by earlier offloads, so it must run
			// even when no cloud provider is currently selected.
			if (class_exists(\Advanced_Media_Offloader\Observers\MissingImageSubsizesObserver::class)) {
				(new \Advanced_Media_Offloader\Observers\MissingImageSubsizesObserver())->register();
			}

			# check if AWS SDK is loaded
			if (!class_exists(WPFitter\Aws\S3\S3Client::class)) {
				// Show admin notice if AWS SDK is missing.
				add_action('admin_notices', function () {
					$this->notice(__('AWS SDK for PHP is required to use Advanced Media Offloader. Please install it via Composer.', 'advanced-media-offloader'), 'error');
				});
				return;
			}

			$this->app->boot();

			do_action('advmo_loaded', $this);
		}

		private function include_files()
		{
			# include Utility Functions
			include_once ADVMO_PATH . 'utility-functions.php';
		}

		/**
		 * @deprecated 4.6.0 Use \AdvancedMediaOffloader\Providers\AdminServiceProvider::pluginActionLinks().
		 */
		public function plugin_action_links($links)
		{
			\AdvancedMediaOffloader\Support\Deprecation::function(__METHOD__, '4.6.0', '\AdvancedMediaOffloader\Providers\AdminServiceProvider::pluginActionLinks()');

			return \AdvancedMediaOffloader\Providers\AdminServiceProvider::pluginActionLinks($links);
		}

		/**
		 * Plugin Activation Hook
		 *
		 * @since 1.0.0
		 */
		public function plugin_activated()
		{
			// Set the first activated version of Advanced Media Offloader.
			if (null === get_option('advmo_first_activated_version', null)) {
				update_option('advmo_first_activated_version', ADVMO_VERSION, true);
			}

			// Show the welcome notice until it is dismissed or settings are visited.
			update_option(\Advanced_Media_Offloader\Admin\Observers\WelcomeNotice::OPTION_KEY, 1, true);
		}

		/**
		 * Plugin Deactivation Hook
		 *
		 * @since 3.3.4
		 */
		public function plugin_deactivated()
		{
			// Clear any scheduled tasks
			wp_clear_scheduled_hook('advmo_bulk_offload_media_process_cron');
			wp_clear_scheduled_hook('advmo_bulk_offload_cron'); // legacy hook name
			wp_clear_scheduled_hook('advmo_check_stalled_processes');
			wp_clear_scheduled_hook('advmo_retry_pending_local_cleanup');
			delete_option('advmo_pending_cleanup_cursor');
			// Clear bulk offload related options
			delete_option('advmo_bulk_offload_cancelled');
			delete_option('advmo_bulk_offload_last_update');
			delete_option('advmo_bulk_offload_data');
			delete_option('advmo_last_connection_check');
			delete_option('advmo_last_connection_status');
			// Clear any custom cron schedules
			if ($this->container && $this->container->has('bulk_offload_handler')) {
				try {
					$bulk_offload_handler = $this->container->get('bulk_offload_handler');
					if ($bulk_offload_handler) {
						remove_filter('cron_schedules', array($bulk_offload_handler, 'add_cron_interval'));
					}
				} catch (\Exception $e) {
					// Silently fail if handler cannot be retrieved
					error_log('ADVMO: Error removing cron schedule filter during deactivation: ' . $e->getMessage());
				}
			}

			// Note: We don't delete the cloud provider settings to preserve configuration
		}

		public function define($name, $value = true)
		{
			if (!defined($name)) {
				define($name, $value);
			}
		}

		public function notice($message, $type = 'info')
		{
			$class = 'notice notice-' . $type;
			printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_attr($message));
		}
	}

	function advmo()
	{
		global $advmo;

		// Instantiate only once.
		if (!isset($advmo)) {
			$advmo = new ADVMO();
			$advmo->initialize();
		}
		return $advmo;
	}

	// Instantiate.
	advmo();
} // class_exists check
