<?php

namespace Advanced_Media_Offloader\Abstracts;

use Advanced_Media_Offloader\Traits\OffloaderTrait;
use WPFitter\Aws\Exception\AwsException;
use WPFitter\GuzzleHttp\Exception\ConnectException;

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

	/** @var array<string,bool> Temporary downloads still owned by this process. */
	private static array $temporaryDownloads = [];

	private static bool $temporaryDownloadShutdownRegistered = false;

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
	 * Download one cloud object to a local path atomically.
	 *
	 * The temporary file is created beside the destination so the final rename
	 * stays on the same filesystem. A failed download never replaces an existing
	 * local file with a partial response.
	 */
	public function downloadFile(string $key, string $destination): bool
	{
		$directory = dirname($destination);
		if (!is_dir($directory) && !wp_mkdir_p($directory)) {
			error_log("Advanced Media Offloader: Unable to create download directory: {$directory}");
			return false;
		}

		// A concurrent worker may already have completed the same restore.
		if (is_file($destination)) {
			return $this->verifyDownloadedFile($destination, $directory);
		}

		$temporary = tempnam($directory, '.advmo-download-');
		if (
			$temporary === false
			|| wp_normalize_path(dirname($temporary)) !== wp_normalize_path($directory)
		) {
			if (is_string($temporary) && is_file($temporary)) {
				wp_delete_file($temporary);
			}
			error_log("Advanced Media Offloader: Unable to create a temporary download file in: {$directory}");
			return false;
		}

		// PHP timeouts and other fatal errors do not reach the catch block. Track
		// every active temporary file behind one shared shutdown callback.
		self::trackTemporaryDownload($temporary);

		$maxExecutionTime = (int) ini_get('max_execution_time');
		$requestTimeout = 120;
		if ($maxExecutionTime > 0) {
			$requestTimeout = max(1, min($requestTimeout, $maxExecutionTime - 5));
		}

		try {
			$result = $this->getClient()->getObject([
				'Bucket' => $this->getBucket(),
				'Key' => $key,
				'SaveAs' => $temporary,
				'@http' => [
					'connect_timeout' => min(10, $requestTimeout),
					'timeout' => $requestTimeout,
				],
			]);

			$downloadedSize = is_file($temporary) ? (int) filesize($temporary) : 0;
			if ($downloadedSize <= 0 || !is_readable($temporary)) {
				throw new \RuntimeException('The provider did not return a readable file.');
			}

			$contentLength = null;
			if (is_array($result) && isset($result['ContentLength'])) {
				$contentLength = (int) $result['ContentLength'];
			} elseif ($result instanceof \ArrayAccess && isset($result['ContentLength'])) {
				$contentLength = (int) $result['ContentLength'];
			}

			if ($contentLength !== null && $contentLength !== $downloadedSize) {
				throw new \RuntimeException('The downloaded file size does not match the cloud object.');
			}

			// Another worker may have restored the same source while this request
			// was downloading. Accept it only when it is complete and readable.
			if (file_exists($destination)) {
				wp_delete_file($temporary);
				self::forgetTemporaryDownload($temporary);
				return $this->verifyDownloadedFile($destination, $directory, $downloadedSize);
			}

			// An atomic same-filesystem rename prevents readers from seeing a partial download.
			if (!@rename($temporary, $destination)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem::move() does not guarantee this atomic handoff.
				throw new \RuntimeException('Unable to move the downloaded file into the uploads directory atomically.');
			}
			self::forgetTemporaryDownload($temporary);

			if (!$this->verifyDownloadedFile($destination, $directory, $downloadedSize)) {
				wp_delete_file($destination);
				throw new \RuntimeException('The restored uploads file could not be verified.');
			}

			return true;
		} catch (\Throwable $e) {
			if (file_exists($temporary)) {
				wp_delete_file($temporary);
			}
			self::forgetTemporaryDownload($temporary);
			error_log("Advanced Media Offloader: Error downloading cloud object '{$key}': {$e->getMessage()}");
			return false;
		}
	}

	private static function trackTemporaryDownload(string $file): void
	{
		self::$temporaryDownloads[$file] = true;
		if (self::$temporaryDownloadShutdownRegistered) {
			return;
		}

		self::$temporaryDownloadShutdownRegistered = true;
		register_shutdown_function(static function (): void {
			foreach (array_keys(self::$temporaryDownloads) as $temporary) {
				if (is_file($temporary)) {
					wp_delete_file($temporary);
				}
			}
			self::$temporaryDownloads = [];
		});
	}

	private static function forgetTemporaryDownload(string $file): void
	{
		unset(self::$temporaryDownloads[$file]);
	}

	private function verifyDownloadedFile(string $file, string $directory, ?int $expectedSize = null): bool
	{
		$directoryStat = @stat($directory);
		$permissions = is_array($directoryStat)
			? ($directoryStat['mode'] & 0000666)
			: (defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
		if ($permissions === 0) {
			$permissions = 0644;
		}
		@chmod($file, $permissions); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Applies the uploads directory mode to a local restored file.

		$size = is_file($file) ? (int) filesize($file) : 0;
		return $size > 0
			&& is_readable($file)
			&& ($expectedSize === null || $size === $expectedSize);
	}

	/**
	 * Check if an object exists in the bucket.
	 *
	 * @param string $key The object key to check
	 * @return bool True if object exists, false for a confirmed missing object.
	 * @throws \RuntimeException When the provider cannot verify the object.
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
		} catch (\Throwable $e) {
			if ($this->isMissingObjectException($e)) {
				return false;
			}

			throw new \RuntimeException(
				"Cloud storage could not verify object '{$key}': {$e->getMessage()}", // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped by the eventual output handler.
				0,
				$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception object is not output.
			);
		}
	}

	/**
	 * Only a provider-confirmed not-found response means an object is absent.
	 */
	private function isMissingObjectException(\Throwable $exception): bool
	{
		for ($current = $exception; $current instanceof \Throwable; $current = $current->getPrevious()) {
			if ($current instanceof AwsException) {
				$status = $current->getStatusCode();

				// Provider error names are not enough on their own. Proxies and
				// S3-compatible services can return misleading or incomplete codes
				// during permission and outage responses. Only an HTTP 404 confirms
				// that cleanup may treat the object as absent.
				if ($status === 404) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check the connection to the service.
	 *
	 * @return true|\WP_Error True when the bucket is reachable. The WP_Error
	 *                        message states what happened; its data carries
	 *                        'hint' (likely causes) and 'code' (the provider's
	 *                        raw error code) for the UI.
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

			// HEAD responses carry no error body, so the SDK usually has no
			// error code for headBucket failures — only a bare HTTP status.
			// When the endpoint itself was reachable, retry with a request
			// that returns a parseable error body, so the real cause (e.g.
			// SignatureDoesNotMatch vs NoSuchBucket) can be reported instead
			// of a guess.
			if ($e instanceof AwsException && $e->getStatusCode() !== null && $e->getAwsErrorCode() === null) {
				try {
					$client->listObjectsV2([
						'Bucket'  => $this->getBucket(),
						'MaxKeys' => 1,
						'@http'   => [
							'timeout' => 5,
						],
					]);
					// Listing works, so credentials and bucket are fine — only
					// HeadBucket is blocked for this key. That is enough to offload.
					return true;
				} catch (\Exception $probe) {
					error_log("Advanced Media Offloader: Connection probe failed: {$probe->getMessage()}");
					$e = $probe;
				}
			}

			$error = $this->classifyConnectionError($e);

			return new \WP_Error('advmo_connection_failed', $error['message'], [
				'hint' => $error['hint'],
				'code' => $error['code'],
			]);
		}
	}

	/**
	 * Describe a connection failure for the UI without guessing.
	 *
	 * Precision order: transport failures (DNS/TLS/timeout — the request never
	 * reached the provider) → the provider's own error code → the bare HTTP
	 * status. The message states what happened as a fact taken from the
	 * response; the hint lists likely causes and is deliberately hedged when
	 * more than one cause is possible, because the same response can mean
	 * different things per provider. Raw exception text is never used:
	 * AWS/Guzzle exceptions embed full request dumps.
	 *
	 * @return array{message: string, hint: string, code: string}
	 */
	protected function classifyConnectionError(\Exception $e): array
	{
		// 1. Transport-level failures: no HTTP response exists.
		$connect = $e;
		while ($connect !== null && !($connect instanceof ConnectException)) {
			$connect = $connect->getPrevious();
		}

		if ($connect instanceof ConnectException) {
			$errno = (int) ($connect->getHandlerContext()['errno'] ?? 0);
			if ($errno === 0 && preg_match('/cURL error (\d+)/', $connect->getMessage(), $matches)) {
				$errno = (int) $matches[1];
			}

			return $this->describeTransportError($errno);
		}

		if ($e instanceof AwsException) {
			// 2. The provider's own error code — the most precise signal there is.
			$code = (string) $e->getAwsErrorCode();
			if ($code !== '') {
				$described = $this->describeProviderErrorCode($code);
				if ($described !== null) {
					return $described;
				}

				// Unknown code: report the provider's own words instead of guessing.
				return [
					'message' => __('The storage provider returned an error.', 'advanced-media-offloader'),
					'hint'    => sanitize_text_field((string) $e->getAwsErrorMessage()),
					'code'    => $code,
				];
			}

			// 3. Only an HTTP status is known (typical for HEAD responses).
			$status = $e->getStatusCode();
			if ($status !== null) {
				return $this->describeHttpStatus((int) $status);
			}
		}

		return [
			'message' => __('Connection failed.', 'advanced-media-offloader'),
			'hint'    => __('Check your credentials and endpoint, then try again.', 'advanced-media-offloader'),
			'code'    => '',
		];
	}

	/**
	 * Per-provider hint overrides, keyed by the provider error code (e.g.
	 * 'AccessDenied'), an HTTP status key (e.g. 'http_403'), or a transport
	 * key ('dns', 'refused', 'timeout', 'ssl', 'unreachable'). Providers
	 * override this to describe their own quirks — e.g. Cloudflare R2 reports
	 * a wrong bucket name as AccessDenied because its tokens are per-bucket.
	 *
	 * @return array<string, string>
	 */
	protected function connectionErrorHints(): array
	{
		return [];
	}

	private function applyHintOverride(string $key, array $result): array
	{
		$overrides = $this->connectionErrorHints();
		if (isset($overrides[$key]) && is_string($overrides[$key]) && $overrides[$key] !== '') {
			$result['hint'] = $overrides[$key];
		}
		return $result;
	}

	private function describeTransportError(int $errno): array
	{
		$ssl_errnos = [35, 51, 53, 54, 58, 59, 60, 66, 77, 80, 82, 83, 90, 91];

		if ($errno === 6) {
			return $this->applyHintOverride('dns', [
				'message' => __('The endpoint address could not be found (DNS lookup failed).', 'advanced-media-offloader'),
				'hint'    => __('Check the endpoint URL for typos.', 'advanced-media-offloader'),
				'code'    => 'cURL 6',
			]);
		}

		if ($errno === 7) {
			return $this->applyHintOverride('refused', [
				'message' => __('The endpoint refused the connection.', 'advanced-media-offloader'),
				'hint'    => __('Check the endpoint URL and port, and any firewall between your server and the provider.', 'advanced-media-offloader'),
				'code'    => 'cURL 7',
			]);
		}

		if ($errno === 28) {
			return $this->applyHintOverride('timeout', [
				'message' => __('The connection timed out.', 'advanced-media-offloader'),
				'hint'    => __('The endpoint did not answer within 5 seconds — check the endpoint URL, or try again later.', 'advanced-media-offloader'),
				'code'    => 'cURL 28',
			]);
		}

		if (in_array($errno, $ssl_errnos, true)) {
			return $this->applyHintOverride('ssl', [
				'message' => __('The secure connection (SSL/TLS) could not be established.', 'advanced-media-offloader'),
				'hint'    => __('Check the endpoint URL for typos. If the URL is correct, confirm the host has a valid SSL certificate.', 'advanced-media-offloader'),
				'code'    => 'cURL ' . $errno,
			]);
		}

		return $this->applyHintOverride('unreachable', [
			'message' => __('The storage endpoint could not be reached.', 'advanced-media-offloader'),
			'hint'    => __('Check the endpoint URL and your server\'s internet connection.', 'advanced-media-offloader'),
			'code'    => $errno > 0 ? 'cURL ' . $errno : '',
		]);
	}

	/**
	 * @return array{message: string, hint: string, code: string}|null Null when the code is not in the map.
	 */
	private function describeProviderErrorCode(string $code): ?array
	{
		$map = [
			'InvalidAccessKeyId' => [
				__('The provider does not recognize this Access Key.', 'advanced-media-offloader'),
				__('Re-copy the Access Key from your provider\'s dashboard.', 'advanced-media-offloader'),
			],
			'SignatureDoesNotMatch' => [
				__('The Secret Key does not match this Access Key.', 'advanced-media-offloader'),
				__('Re-copy the Secret Key — watch for missing characters or extra spaces.', 'advanced-media-offloader'),
			],
			'AccessDenied' => [
				__('The provider refused access.', 'advanced-media-offloader'),
				__('Possible causes: a wrong bucket name, wrong keys, or a key that has no permission for this bucket.', 'advanced-media-offloader'),
			],
			'AllAccessDisabled' => [
				__('All access to this bucket is disabled on the provider side.', 'advanced-media-offloader'),
				__('Check the bucket\'s status in your provider\'s dashboard.', 'advanced-media-offloader'),
			],
			'NoSuchBucket' => [
				__('This bucket does not exist on the provider.', 'advanced-media-offloader'),
				__('Check the bucket name.', 'advanced-media-offloader'),
			],
			'PermanentRedirect' => [
				__('The bucket lives in a different region than the one configured.', 'advanced-media-offloader'),
				__('Check the region and endpoint settings.', 'advanced-media-offloader'),
			],
			'AuthorizationHeaderMalformed' => [
				__('The request was signed for the wrong region.', 'advanced-media-offloader'),
				__('Check the region setting.', 'advanced-media-offloader'),
			],
			'IllegalLocationConstraintException' => [
				__('The bucket lives in a different region than the one configured.', 'advanced-media-offloader'),
				__('Check the region and endpoint settings.', 'advanced-media-offloader'),
			],
			'RequestTimeTooSkewed' => [
				__('Your server\'s clock is wrong, so the provider rejected the signed request.', 'advanced-media-offloader'),
				__('Correct the server time (enable NTP), then try again.', 'advanced-media-offloader'),
			],
			'ExpiredToken' => [
				__('The security token has expired.', 'advanced-media-offloader'),
				__('Create fresh credentials in your provider\'s dashboard.', 'advanced-media-offloader'),
			],
			'InvalidToken' => [
				__('The security token is not valid.', 'advanced-media-offloader'),
				__('Create fresh credentials in your provider\'s dashboard.', 'advanced-media-offloader'),
			],
			'SlowDown' => [
				__('The provider is rate-limiting requests right now.', 'advanced-media-offloader'),
				__('Wait a moment and try again.', 'advanced-media-offloader'),
			],
			'ServiceUnavailable' => [
				__('The provider is temporarily unavailable.', 'advanced-media-offloader'),
				__('Try again in a few minutes.', 'advanced-media-offloader'),
			],
			'InternalError' => [
				__('The provider had an internal error.', 'advanced-media-offloader'),
				__('Try again in a few minutes.', 'advanced-media-offloader'),
			],
		];

		if (!isset($map[$code])) {
			return null;
		}

		return $this->applyHintOverride($code, [
			'message' => $map[$code][0],
			'hint'    => $map[$code][1],
			'code'    => $code,
		]);
	}

	private function describeHttpStatus(int $status): array
	{
		if ($status === 301) {
			return $this->applyHintOverride('http_301', [
				'message' => __('The provider redirected the request.', 'advanced-media-offloader'),
				'hint'    => __('The bucket is probably in a different region — check the region and endpoint settings.', 'advanced-media-offloader'),
				'code'    => 'HTTP 301',
			]);
		}

		if ($status === 400) {
			return $this->applyHintOverride('http_400', [
				'message' => __('The provider rejected the request.', 'advanced-media-offloader'),
				'hint'    => __('Check the endpoint URL and region settings.', 'advanced-media-offloader'),
				'code'    => 'HTTP 400',
			]);
		}

		if ($status === 401 || $status === 403) {
			return $this->applyHintOverride('http_403', [
				'message' => __('The provider refused access.', 'advanced-media-offloader'),
				'hint'    => __('Possible causes: a wrong bucket name, wrong keys, or a key that has no permission for this bucket.', 'advanced-media-offloader'),
				'code'    => 'HTTP ' . $status,
			]);
		}

		if ($status === 404) {
			return $this->applyHintOverride('http_404', [
				'message' => __('The bucket was not found.', 'advanced-media-offloader'),
				'hint'    => __('Check the bucket name.', 'advanced-media-offloader'),
				'code'    => 'HTTP 404',
			]);
		}

		if ($status >= 500) {
			return $this->applyHintOverride('http_5xx', [
				'message' => __('The provider had a temporary problem.', 'advanced-media-offloader'),
				'hint'    => __('Try again in a few minutes.', 'advanced-media-offloader'),
				'code'    => 'HTTP ' . $status,
			]);
		}

		return [
			'message' => __('The provider returned an unexpected response.', 'advanced-media-offloader'),
			'hint'    => __('Check your credentials and endpoint, then try again.', 'advanced-media-offloader'),
			'code'    => 'HTTP ' . $status,
		];
	}

	/**
	 * A collapsed, copy-ready wp-config.php snippet for this provider's
	 * credential constants. Constants take priority over saved values, so
	 * this is the "more secure" path without cluttering the form for
	 * everyone who does not need it.
	 */
	private function getWpConfigSnippetHTML(array $credentialFields, string $provider_key): string
	{
		$lines = [];
		foreach ($credentialFields as $field) {
			if (($field['type'] ?? 'text') === 'checkbox' || empty($field['name'])) {
				continue;
			}
			$constant = 'ADVMO_' . strtoupper($provider_key) . '_' . strtoupper($field['name']);
			$lines[] = "define( '" . $constant . "', 'your-" . str_replace('_', '-', $field['name']) . "-here' );";
		}

		if (empty($lines)) {
			return '';
		}

		$html = '<details class="advmo-wpconfig-details">';
		$html .= '<summary>' . esc_html__('Prefer to keep credentials out of the database?', 'advanced-media-offloader') . '</summary>';
		$html .= '<div class="advmo-wpconfig-body">';
		$html .= '<p class="description">' . sprintf(
			/* translators: %s: wp-config.php file name, wrapped in a code tag */
			esc_html__('Define them in %s instead. Keep only the lines you need — each constant you define takes priority and locks its field above.', 'advanced-media-offloader'),
			'<code>wp-config.php</code>'
		) . '</p>';
		$html .= '<pre class="advmo-wpconfig-snippet"><code>' . esc_html(implode("\n", $lines)) . '</code></pre>';
		$html .= '<button type="button" class="button advmo-copy-snippet">' . esc_html__('Copy snippet', 'advanced-media-offloader') . '</button>';
		$html .= '</div>';
		$html .= '</details>';

		return $html;
	}

	public function TestConnectionHTMLButton($provider_key = '')
	{
		// The test runs against SAVED settings, so it is only meaningful once
		// this provider is the saved one and has a bucket configured
		// (typed in and saved, or defined in wp-config.php).
		$has_saved_credentials = $provider_key !== ''
			&& advmo_get_cloud_provider_key() === $provider_key
			&& advmo_get_provider_credential($provider_key, 'bucket') !== '';

		if ($has_saved_credentials) {
			return sprintf(
				'<button type="button" class="button advmo_js_test_connection">%s</button>',
				esc_html__('Test Connection', 'advanced-media-offloader')
			);
		}

		return sprintf(
			'<button type="button" class="button advmo_js_test_connection" disabled title="%s">%s</button>',
			esc_attr__('Save your credentials first — the test runs against saved settings.', 'advanced-media-offloader'),
			esc_html__('Test Connection', 'advanced-media-offloader')
		);
	}

	protected function getConnectionStatusHTML($provider_key = '')
	{
		$status = get_option('advmo_last_connection_status', []);

		$card = '';
		if (is_array($status) && isset($status['success'])) {
			// Don't show another provider's result next to this provider's fields.
			$matches_provider = $provider_key === '' || empty($status['provider']) || $status['provider'] === $provider_key;
			if ($matches_provider) {
				$card = $this->renderConnectionStatusCard($status);
			}
		}

		// The slot is always rendered (even empty) so test results land in a
		// stable aria-live region that screen readers announce.
		return '<div class="advmo-connection-slot" role="status" aria-live="polite">' . $card . '</div>';
	}

	/**
	 * Render the connection status card shown under the credential fields.
	 *
	 * One renderer serves both the settings-page render (from the cached
	 * status) and the AJAX response after a test, so the two can never drift
	 * apart. Layout, top to bottom: state title → what happened → what to
	 * check → next step → small meta line (provider error code, check time).
	 *
	 * @param array $status The advmo_last_connection_status option shape.
	 */
	public function renderConnectionStatusCard(array $status): string
	{
		$is_connected = !empty($status['success']);

		$time = isset($status['time']) ? (int) $status['time'] : 0;
		$checked_at = $time > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time) : '';

		$html = sprintf(
			'<div class="advmo-connection-card %s">',
			$is_connected ? 'is-success' : 'is-error'
		);

		// Title row: the state, instantly scannable.
		$title = $is_connected
			? sprintf(
				/* translators: %s: cloud provider name */
				esc_html__('Connected to %s', 'advanced-media-offloader'),
				esc_html($this->getProviderName())
			)
			: esc_html__('Connection failed', 'advanced-media-offloader');

		$html .= sprintf(
			'<div class="advmo-connection-card-title"><span class="dashicons %s" aria-hidden="true"></span><strong>%s</strong></div>',
			$is_connected ? 'dashicons-yes-alt' : 'dashicons-warning',
			$title
		);

		if ($is_connected) {
			$options = get_option('advmo_settings', []);
			$auto_offload = !isset($options['auto_offload_uploads']) || (int) $options['auto_offload_uploads'] === 1;

			// The page-level pending-media notice owns the handoff to bulk
			// offloading, with a live count and a clear call to action.
			$html .= '<p class="advmo-connection-card-message">' . ($auto_offload
				? esc_html__('Your cloud storage is ready. New uploads are offloaded automatically.', 'advanced-media-offloader')
				: esc_html__('Your cloud storage is ready. Automatic offloading of new uploads is currently turned off.', 'advanced-media-offloader')) . '</p>';
		} else {
			if (!empty($status['message'])) {
				$html .= '<p class="advmo-connection-card-message">' . esc_html($status['message']) . '</p>';
			}

			if (!empty($status['hint'])) {
				$html .= '<p class="advmo-connection-card-hint"><strong>' . esc_html__('What to check:', 'advanced-media-offloader') . '</strong> ' . esc_html($status['hint']) . '</p>';
			}
		}

		// Small meta line: the provider's raw code (searchable, useful in
		// support tickets) and when the result was produced.
		$meta_parts = [];
		if (!$is_connected && !empty($status['code'])) {
			$meta_parts[] = sprintf(
				/* translators: %s: raw error code from the storage provider */
				esc_html__('Provider error: %s', 'advanced-media-offloader'),
				esc_html($status['code'])
			);
		}
		if ($checked_at !== '') {
			$meta_parts[] = sprintf(
				/* translators: %s: date and time of the last connection test */
				esc_html__('Last checked: %s', 'advanced-media-offloader'),
				esc_html($checked_at)
			);
		}
		if ($time > 0 && (time() - $time) > WEEK_IN_SECONDS) {
			$meta_parts[] = esc_html__('This result may be outdated — run Test Connection again.', 'advanced-media-offloader');
		}
		if (!empty($meta_parts)) {
			$html .= '<p class="advmo-connection-card-meta">' . implode(' · ', $meta_parts) . '</p>';
		}

		$html .= '</div>';

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
					/* translators: %s: wp-config.php file name. */
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
				/* translators: %s: wp-config.php file name. */
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
		
		// "How to get these credentials" for this provider. Plain helper text
		// rather than a notice box: it is guidance for filling in the fields
		// right below, and stacking coloured panels above the form made the
		// step look far busier than it is.
		if (!empty($provider_description)) {
			$html .= '<p class="advmo-credentials-help">' . wp_kses($provider_description, [
				'a'      => ['href' => [], 'target' => [], 'rel' => []],
				'strong' => [],
				'em'     => [],
				'code'   => [],
			]) . '</p>';
		}
		
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

		// The wp-config.php route is an alternative to the fields above, so it
		// follows them and stays closed until someone asks for it.
		$html .= $this->getWpConfigSnippetHTML($credentialFields, $provider_key);

		// Add connection status if available
		$html .= $this->getConnectionStatusHTML($provider_key);

		// Add action buttons container
		$html .= '<div class="advmo-credentials-actions">';
		$html .= '<button type="submit" class="button button-primary advmo-save-credentials">';
		$html .= '<span class="dashicons dashicons-saved"></span> ';
		$html .= esc_html__('Save & Test Connection', 'advanced-media-offloader');
		$html .= '</button>';
		$html .= $this->TestConnectionHTMLButton($provider_key);
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
	 * Delete an exact list of object keys.
	 *
	 * Callers must build and validate attachment-scoped keys before calling this
	 * method. Missing objects are treated as a successful idempotent delete.
	 *
	 * @param string[] $keys
	 */
	public function deleteObjects(array $keys): bool
	{
		return $this->deleteS3Objects($keys);
	}

	/**
	 * Collect all object keys that should be deleted for an attachment.
	 *
	 * Includes:
	 * - main object
	 * - generated sizes + modern format sources
	 * - original_image (when present)
	 * - root-level sources
	 * - backup sizes + their sources (_wp_attachment_backup_sizes)
	 * - backup full-image sources (_wp_attachment_backup_sources, set by webp-uploads)
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

		// Backup sizes for edited images (include Modern Image Format sources).
		$backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
		if (is_array($backup_sizes)) {
			foreach ($backup_sizes as $sizeinfo) {
				if (!is_array($sizeinfo)) {
					continue;
				}
				foreach ($this->getFilesFromSizeData($sizeinfo) as $backup_file) {
					if (!empty($backup_file) && is_string($backup_file)) {
						$keys[] = $base_dir . $backup_file;
					}
				}
			}
		}

		// Backup full-image sources for edited images (Modern Image Formats).
		// webp-uploads stores the full-size WebP/AVIF backups in its own meta
		// key (_wp_attachment_backup_sources), separate from core's
		// _wp_attachment_backup_sizes. The edited full-size source is recorded
		// only here, so it must be collected or it is orphaned on delete.
		$backup_sources = get_post_meta($attachment_id, '_wp_attachment_backup_sources', true);
		if (is_array($backup_sources)) {
			foreach ($backup_sources as $sources_set) {
				if (!is_array($sources_set)) {
					continue;
				}
				foreach ($sources_set as $source) {
					if (is_array($source) && !empty($source['file']) && is_string($source['file'])) {
						$keys[] = $base_dir . $source['file'];
					}
				}
			}
		}

		/**
		 * Filter the list of S3 object keys to delete for an attachment.
		 *
		 * Allows other observers (e.g. Imagify compatibility) to append
		 * additional keys such as WebP/AVIF sidecar files.
		 *
		 * @param string[] $keys          Object keys collected so far.
		 * @param int      $attachment_id The attachment being deleted.
		 * @param string   $base_dir      The base directory prefix for this attachment's keys.
		 */
		$keys = apply_filters('advmo_attachment_delete_keys', $keys, $attachment_id, $base_dir);

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
				error_log('Advanced Media Offloader: S3 deleteObjects failed, falling back to single deletes. Error: ' . $e->getMessage());

				$chunk_ok = true;
				foreach ($chunk as $key) {
					try {
						$this->deleteS3Object($key);
					} catch (\Exception $inner) {
						$chunk_ok = false;
						error_log('Advanced Media Offloader: S3 deleteObject failed for key "' . $key . '": ' . $inner->getMessage());
					}
				}
				$all_ok = $all_ok && $chunk_ok;
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
			throw new \Exception("Unable to find attached file for attachment ID {$attachment_id}"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; the numeric ID is safe and output handlers escape the message.
		}

		// Extract and sanitize the filename from the attached file path
		$file_name = basename($attached_file);
		$file_name = sanitize_file_name($file_name);

		// Validate filename is not empty after sanitization
		if (empty($file_name)) {
			throw new \Exception("Invalid file name for attachment ID {$attachment_id}"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; the numeric ID is safe and output handlers escape the message.
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
