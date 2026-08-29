addEventListener("DOMContentLoaded", function () {
	// Select the cloud provider dropdown
	const cloudProviderSelect = document.querySelector(
		'select[name="advmo_settings[cloud_provider]"]',
	);

	if (!cloudProviderSelect) {
		console.error("Cloud provider select not found");
		return;
	}

	// Select the form (assuming it's the parent form of the select field)
	const form = cloudProviderSelect.closest("form");

	if (!form) {
		console.error("Parent form not found");
		return;
	}

	// Localized strings (English fallbacks in case localization is missing)
	const i18n = (typeof advmo_ajax_object !== 'undefined' && advmo_ajax_object.i18n) ? advmo_ajax_object.i18n : {};

	// Function to display error message(s) as a dismissible admin notice
	function showErrorMessage(messages) {
		// Scroll to top
		window.scrollTo({ top: 0, behavior: 'smooth' });

		// Remove existing error messages
		const existingErrors = document.querySelectorAll('.advmo-ajax-error');
		existingErrors.forEach(error => error.remove());

		// Handle both string and array inputs
		const messageArray = Array.isArray(messages) ? messages : [messages];

		// Create error message element
		const errorDiv = document.createElement('div');
		errorDiv.className = 'notice notice-error is-dismissible advmo-ajax-error';

		// Build the content - if multiple messages, use a list
		if (messageArray.length === 1) {
			errorDiv.innerHTML = '<p>' + messageArray[0] + '</p>';
		} else {
			let content = '<ul style="margin: 0.5em 0; list-style: disc; padding-left: 20px;">';
			messageArray.forEach(msg => {
				content += '<li>' + msg + '</li>';
			});
			content += '</ul>';
			errorDiv.innerHTML = content;
		}

		// Insert at the top of the form
		const noticeAnchor = document.querySelector('.advmo-print-notices-after');
		if (noticeAnchor) {
			noticeAnchor.parentNode.insertBefore(errorDiv, noticeAnchor.nextSibling);
		}

		// Make dismissible work
		if (typeof wp !== 'undefined' && wp.notices) {
			wp.notices.init();
		}
	}

	// Copy the wp-config.php snippet. Delegated on the document because the
	// credentials block (and this button with it) is re-rendered on provider
	// switch.
	document.addEventListener('click', function (e) {
		const copyButton = e.target.closest('.advmo-copy-snippet');
		if (!copyButton) return;

		const details = copyButton.closest('.advmo-wpconfig-details');
		const code = details ? details.querySelector('code') : null;
		if (!code) return;

		const restoreLabel = copyButton.textContent;
		const flashLabel = (label, duration) => {
			copyButton.textContent = label;
			setTimeout(() => { copyButton.textContent = restoreLabel; }, duration);
		};
		const selectSnippet = () => {
			// No clipboard access: select the snippet so a manual copy works
			const selection = window.getSelection();
			const range = document.createRange();
			range.selectNodeContents(code);
			selection.removeAllRanges();
			selection.addRange(range);
			flashLabel(i18n.copy_failed || 'Press Ctrl/Cmd + C to copy', 3000);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(code.textContent).then(() => {
				flashLabel(i18n.copied || 'Copied!', 2000);
			}, selectSnippet);
		} else {
			selectSnippet();
		}
	});

	// Add event listener to the select field
	cloudProviderSelect.addEventListener("change", function (e) {
		const selectedProvider = e.target.value;

		// Don't do anything if empty/placeholder is selected
		if (!selectedProvider) {
			return;
		}

		// Find the credentials field container
		const credentialsField = document.querySelector('.advmo-cloud-provider-credentials');
		const cloudProviderSection = document.querySelector('.advmo-cloud-provider-settings');

		if (!credentialsField) {
			console.error('Credentials field container not found');
			return;
		}

		// Add loading overlay
		if (cloudProviderSection) {
			addOverlay(cloudProviderSection);
		}

		// Prepare AJAX request
		const data = new URLSearchParams();
		data.append('action', 'advmo_get_provider_credentials');
		data.append('security_nonce', advmo_ajax_object.get_provider_credentials_nonce);
		data.append('provider', selectedProvider);

		// Fetch the credentials HTML
		fetch(advmo_ajax_object.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: data
		})
		.then(response => response.json())
		.then(data => {
			// Remove loading overlay
			if (cloudProviderSection) {
				removeOverlay(cloudProviderSection);
			}

			if (data.success && data.data.html) {
				// Replace the credentials field content
				const fieldContent = credentialsField.querySelector('td');
				if (fieldContent) {
					fieldContent.innerHTML = data.data.html;

					// Re-initialize password toggle listeners
					initPasswordToggles();

					// Re-initialize test connection button
					initTestConnection();

					// Auto-save the provider selection
					saveProviderSelection(selectedProvider);
				}
			} else {
				// Show error message
				const errorMessage = data.data?.message || i18n.failed_load_credentials || 'Failed to load credentials fields.';
				showErrorMessage(errorMessage);
				console.error('Error loading credentials:', errorMessage);
			}
		})
		.catch(error => {
			// Remove loading overlay
			if (cloudProviderSection) {
				removeOverlay(cloudProviderSection);
			}

			showErrorMessage(i18n.error_loading_credentials || 'An error occurred while loading credentials fields. Please try again.');
			console.error('Error:', error);
		});
	});

	// Helper function to save provider selection
	function saveProviderSelection(provider) {
		const data = new URLSearchParams();
		data.append('action', 'advmo_save_general_settings');
		data.append('security_nonce', advmo_ajax_object.save_general_nonce);
		data.append('advmo_settings[cloud_provider]', provider);

		// Get other existing settings to preserve them
		const autoOffload = document.getElementById('auto_offload_uploads');
		const retentionPolicyRadios = document.querySelectorAll('input[name="advmo_settings[retention_policy]"]');
		const objectVersioning = document.getElementById('object_versioning');
		const pathPrefixActive = document.getElementById('path_prefix_active');
		const pathPrefix = document.getElementById('path_prefix');
		const mirrorDelete = document.getElementById('mirror_delete');

		if (autoOffload) {
			data.append('advmo_settings[auto_offload_uploads]', autoOffload.checked ? '1' : '0');
		}

		retentionPolicyRadios.forEach(radio => {
			if (radio.checked) {
				data.append('advmo_settings[retention_policy]', radio.value);
			}
		});

		if (objectVersioning) {
			data.append('advmo_settings[object_versioning]', objectVersioning.checked ? '1' : '0');
		}

		if (pathPrefixActive) {
			data.append('advmo_settings[path_prefix_active]', pathPrefixActive.checked ? '1' : '0');
		}

		if (pathPrefix) {
			data.append('advmo_settings[path_prefix]', pathPrefix.value);
		}

		if (mirrorDelete) {
			data.append('advmo_settings[mirror_delete]', mirrorDelete.checked ? '1' : '0');
		}

		// Save in the background
		fetch(advmo_ajax_object.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: data
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				console.log('Provider selection saved successfully');
				applySetupStatus(data.data?.setup_status);
			} else {
				showErrorMessage(data.data?.message || i18n.failed_save_provider || 'Failed to save the selected provider. Please try again.');
				console.error('Failed to save provider selection:', data.data?.message);
			}
		})
		.catch(error => {
			showErrorMessage(i18n.failed_save_provider || 'Failed to save the selected provider. Please try again.');
			console.error('Error saving provider selection:', error);
		});
	}

	// Helper function to initialize password toggle functionality
	function initPasswordToggles() {
		const passwordToggles = document.querySelectorAll('.advmo-toggle-password');

		passwordToggles.forEach(function(toggleButton) {
			// Remove any existing listeners by cloning the button
			const newToggleButton = toggleButton.cloneNode(true);
			toggleButton.parentNode.replaceChild(newToggleButton, toggleButton);

			newToggleButton.addEventListener('click', function(e) {
				e.preventDefault();

				// Find the password input field (sibling of the button)
				const passwordWrapper = newToggleButton.closest('.advmo-password-field-wrapper');
				const passwordInput = passwordWrapper.querySelector('.advmo-password-input');
				const icon = newToggleButton.querySelector('.dashicons');

				if (passwordInput.type === 'password') {
					passwordInput.type = 'text';
					icon.classList.remove('dashicons-visibility');
					icon.classList.add('dashicons-hidden');
					newToggleButton.setAttribute('aria-label', 'Hide password');
				} else {
					passwordInput.type = 'password';
					icon.classList.remove('dashicons-hidden');
					icon.classList.add('dashicons-visibility');
					newToggleButton.setAttribute('aria-label', 'Show password');
				}
			});
		});
	}

	// Helper function to initialize test connection button
	function initTestConnection() {
		const advmo_test_connection = document.querySelector(".advmo_js_test_connection");

		if (advmo_test_connection) {
			// Remove any existing listeners by cloning the button
			const newTestButton = advmo_test_connection.cloneNode(true);
			advmo_test_connection.parentNode.replaceChild(newTestButton, advmo_test_connection);

			newTestButton.addEventListener("click", function (e) {
				e.preventDefault();
				runConnectionTest(newTestButton);
			});
		}
	}

	// Run the connection test against the saved settings and render the result
	function runConnectionTest(button) {
		const testButton = button || document.querySelector('.advmo_js_test_connection');

		if (testButton) {
			// Add loading state
			testButton.classList.add('loading');
			testButton.disabled = true;
		}

		// A test started from the collapsed "Done" state would otherwise write
		// its result out of sight, so reveal the step first.
		const slot = getConnectionSlot();
		const step = slot && slot.closest('details');
		if (step) {
			step.open = true;
		}

		// Show progress where the result will appear, so the state never
		// changes silently.
		showConnectionCard('is-testing', i18n.testing || 'Testing connection…');

		const data = {
			action: "advmo_test_connection",
			security_nonce: advmo_ajax_object.nonce,
		};

		return fetch(advmo_ajax_object.ajax_url, {
			method: "POST",
			headers: {
				"Content-Type": "application/x-www-form-urlencoded",
			},
			body: new URLSearchParams(data),
		})
			.then((response) => response.json())
			.then((data) => {
				if (testButton) {
					testButton.classList.remove('loading');
					testButton.disabled = false;
				}

				if (data.data && data.data.status_html) {
					// The card comes from the same server-side renderer the
					// settings page uses, so the two never drift apart.
					replaceConnectionCard(data.data.status_html);
				} else {
					showConnectionCard(
						data.success ? 'is-success' : 'is-error',
						data.success ? (i18n.connected || 'Connected') : (i18n.connection_failed || 'Connection failed'),
						(data.data && data.data.message) || ''
					);
				}

				applySetupStatus(data.data && data.data.setup_status);
			})
			.catch((error) => {
				if (testButton) {
					testButton.classList.remove('loading');
					testButton.disabled = false;
				}

				showConnectionCard(
					'is-error',
					i18n.connection_failed || 'Connection failed',
					i18n.test_failed_network || 'Could not run the test. Check your internet connection and try again.'
				);
				console.error("Error:", error.message);
			});
	}

	// Keep the setup step badges in sync with what just happened. Without
	// this, the badges keep showing the state from page load — e.g. "Needs
	// attention" sitting above a green connection card.
	function applySetupStatus(fragments) {
		if (!fragments) return;

		function replaceStepSummary(header, html) {
			if (!header) return;

			const current = header.querySelector('.advmo-step-summary');
			if (current) {
				current.remove();
			}

			if (html) {
				const badge = header.querySelector('.advmo-step-status');
				if (badge) {
					badge.insertAdjacentHTML('beforebegin', html);
				} else {
					header.insertAdjacentHTML('beforeend', html);
				}
			}
		}

		if (fragments.step_one_badge) {
			const header = document.querySelector('.advmo-cloud-provider-settings .advmo-section-header');
			const badge = header ? header.querySelector('.advmo-step-status') : null;

			if (badge) {
				badge.outerHTML = fragments.step_one_badge;
			} else if (header) {
				header.insertAdjacentHTML('beforeend', fragments.step_one_badge);
			}
		}

		replaceStepSummary(
			document.querySelector('.advmo-cloud-provider-settings .advmo-section-header'),
			fragments.step_one_summary || ''
		);
		replaceStepSummary(
			document.querySelector('.advmo-general-settings .advmo-section-header'),
			fragments.step_two_summary || ''
		);

		if (Object.prototype.hasOwnProperty.call(fragments, 'next_action')) {
			const nextAction = document.querySelector('.advmo-next-action-slot');
			if (nextAction) {
				nextAction.innerHTML = fragments.next_action;
			}
		}

	}

	// --- Connection status card ------------------------------------------
	// The card lives in a stable aria-live slot rendered with the credential
	// fields, so screen readers announce every state change.

	function getConnectionSlot() {
		return document.querySelector('.advmo-connection-slot');
	}

	// Swap in a server-rendered card (escaped server-side)
	function replaceConnectionCard(html) {
		const slot = getConnectionSlot();
		if (slot) {
			slot.innerHTML = html;
		}
	}

	// Build a minimal card client-side (testing state, network failures)
	function showConnectionCard(state, title, message = '') {
		const slot = getConnectionSlot();
		if (!slot) return;

		const card = document.createElement('div');
		card.className = `advmo-connection-card ${state}`;

		const titleEl = document.createElement('div');
		titleEl.className = 'advmo-connection-card-title';

		const icon = document.createElement('span');
		const iconClass = state === 'is-testing'
			? 'dashicons-update advmo-spin'
			: (state === 'is-success' ? 'dashicons-yes-alt' : 'dashicons-warning');
		icon.className = `dashicons ${iconClass}`;
		icon.setAttribute('aria-hidden', 'true');
		titleEl.appendChild(icon);

		const strong = document.createElement('strong');
		strong.textContent = title;
		titleEl.appendChild(strong);
		card.appendChild(titleEl);

		if (message) {
			const messageEl = document.createElement('p');
			messageEl.className = 'advmo-connection-card-message';
			messageEl.textContent = message;
			card.appendChild(messageEl);
		}

		slot.textContent = '';
		slot.appendChild(card);
	}

	// --- Step panels: animated open and close ----------------------------
	// A <details> shows and hides its content in one frame. Doing this in CSS
	// needs interpolate-size to make an `auto` height animatable, and only
	// Chromium implements that, so the panels jumped in Firefox. Animating the
	// height from here behaves the same in every browser.
	function initSectionAnimation() {
		if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			return;
		}

		document.querySelectorAll('#advmo details.advmo-section').forEach(function (panel) {
			const summary = panel.querySelector('summary.advmo-section-header');
			const body = panel.querySelector('.advmo-section-body');

			if (!summary || !body) {
				return;
			}

			let animation = null;

			const settle = () => {
				body.style.height = '';
				body.style.overflow = '';
				animation = null;
			};

			summary.addEventListener('click', function (event) {
				// Take over the toggle so the panel can be measured before it
				// disappears, and stays visible while it closes.
				event.preventDefault();

				if (animation) {
					animation.cancel();
				}

				body.style.overflow = 'hidden';

				if (panel.open) {
					const from = body.offsetHeight;
					animation = body.animate({ height: [from + 'px', '0px'] }, { duration: 200, easing: 'ease' });
					animation.onfinish = () => {
						panel.open = false;
						settle();
					};
				} else {
					panel.open = true;
					const to = body.offsetHeight;
					animation = body.animate({ height: ['0px', to + 'px'] }, { duration: 200, easing: 'ease' });
					animation.onfinish = settle;
				}
			});
		});
	}

	initSectionAnimation();

	// Bind the test connection button rendered on page load
	initTestConnection();

	// Enable Path Prefix input if checkbox was enabled
	var pathPrefixCheckbox = document.getElementById("path_prefix_active");
	var pathPrefixInput = document.getElementById("path_prefix");

	if (pathPrefixCheckbox && pathPrefixInput) {
		pathPrefixCheckbox.addEventListener("change", function () {
			pathPrefixInput.disabled = !this.checked;
		});
	}

	// Password toggle functionality
	const passwordToggles = document.querySelectorAll('.advmo-toggle-password');

	passwordToggles.forEach(function(toggleButton) {
		toggleButton.addEventListener('click', function(e) {
			e.preventDefault();

			// Find the password input field (sibling of the button)
			const passwordWrapper = toggleButton.closest('.advmo-password-field-wrapper');
			const passwordInput = passwordWrapper.querySelector('.advmo-password-input');
			const icon = toggleButton.querySelector('.dashicons');

			if (passwordInput.type === 'password') {
				passwordInput.type = 'text';
				icon.classList.remove('dashicons-visibility');
				icon.classList.add('dashicons-hidden');
				toggleButton.setAttribute('aria-label', 'Hide password');
			} else {
				passwordInput.type = 'password';
				icon.classList.remove('dashicons-hidden');
				icon.classList.add('dashicons-visibility');
				toggleButton.setAttribute('aria-label', 'Show password');
			}
		});
	});

	// AJAX Settings Save functionality
	const settingsForm = document.querySelector('#advmo form');

	if (settingsForm) {
		// Get both sections
		const cloudProviderSection = document.querySelector('.advmo-cloud-provider-settings');
		const generalSection = document.querySelector('.advmo-general-settings');

		// Function to add overlay to a section
		function addOverlay(section) {
			if (!section) return;

			// Check if overlay already exists
			let overlay = section.querySelector('.advmo-section-overlay');
			if (!overlay) {
				overlay = document.createElement('div');
				overlay.className = 'advmo-section-overlay';
				section.style.position = 'relative';
				section.appendChild(overlay);
			}
			overlay.classList.add('active');
		}

		// Function to remove overlay from a section
		function removeOverlay(section) {
			if (!section) return;

			const overlay = section.querySelector('.advmo-section-overlay');
			if (overlay) {
				overlay.classList.remove('active');
				setTimeout(() => {
					if (!overlay.classList.contains('active')) {
						overlay.remove();
					}
				}, 300);
			}
		}

		// Function to set button loading state
		function setButtonLoading(button, isLoading) {
			if (isLoading) {
				button.classList.add('loading');
				button.disabled = true;
				// Store original text and HTML if not already stored
				if (!button.getAttribute('data-original-text')) {
					if (button.tagName === 'INPUT') {
						button.setAttribute('data-original-text', button.value);
					} else {
						button.setAttribute('data-original-text', button.textContent.trim());
						button.setAttribute('data-original-html', button.innerHTML);
					}
				}

				// For INPUT elements, we need to wrap them to show the spinner
				// since ::after doesn't work on input elements
				if (button.tagName === 'INPUT' && !button.parentElement.classList.contains('advmo-button-wrapper')) {
					const wrapper = document.createElement('span');
					wrapper.className = 'advmo-button-wrapper';
					button.parentNode.insertBefore(wrapper, button);
					wrapper.appendChild(button);
				}
			} else {
				button.classList.remove('loading');
				button.disabled = false;

				// Unwrap INPUT elements after loading
				if (button.tagName === 'INPUT' && button.parentElement.classList.contains('advmo-button-wrapper')) {
					const wrapper = button.parentElement;
					wrapper.parentNode.insertBefore(button, wrapper);
					wrapper.remove();
				}
			}
		}

		// Function to show success state on button
		function showButtonSuccess(button) {
			// Ensure button is unwrapped before showing success
			if (button.tagName === 'INPUT' && button.parentElement.classList.contains('advmo-button-wrapper')) {
				const wrapper = button.parentElement;
				wrapper.parentNode.insertBefore(button, wrapper);
				wrapper.remove();
			}

			button.classList.add('success');
			const originalText = button.getAttribute('data-original-text');
			const originalHtml = button.getAttribute('data-original-html');

			if (button.tagName === 'INPUT') {
				button.value = '✓ Saved!';
			} else {
				button.innerHTML = '<span class="dashicons dashicons-yes"></span> Saved!';
			}

			setTimeout(() => {
				button.classList.remove('success');
				if (button.tagName === 'INPUT') {
					button.value = originalText || 'Save Changes';
				} else {
					// Restore original HTML if available, otherwise reconstruct
					if (originalHtml) {
						button.innerHTML = originalHtml;
					} else {
						button.innerHTML = '<span class="dashicons dashicons-saved"></span> ' + originalText;
					}
				}
			}, 2500);
		}

		// POST a URLSearchParams payload and parse the JSON response
		function postForm(params) {
			return fetch(advmo_ajax_object.ajax_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: params
			}).then(response => response.json());
		}

		// Collect the advmo_credentials[...] fields into a request payload
		function buildCredentialsData(formData) {
			const credentialsData = new URLSearchParams();
			credentialsData.append('action', 'advmo_save_credentials');
			credentialsData.append('security_nonce', advmo_ajax_object.save_credentials_nonce);

			for (let [key, value] of formData.entries()) {
				if (key.startsWith('advmo_credentials[')) {
					credentialsData.append(key, value);
				}
			}

			return credentialsData;
		}

		// Function to handle form save via AJAX
		function saveSettings(button) {
			// Prevent multiple submissions
			if (button.disabled) return;

			// Determine if this is the credentials-only save button
			const isCredentialsButton = button.classList.contains('advmo-save-credentials');

			// Set only the clicked button to loading state
			setButtonLoading(button, true);

			// Track when the loading started for minimum duration
			const loadingStartTime = Date.now();
			const minimumLoadingDuration = 800; // 800ms minimum loading time for better UX

			// Collect form data
			const formData = new FormData(settingsForm);

			// Each action owns one clear scope: the connection button saves and
			// tests credentials, while File handling saves general settings only.
			let responsesPromise;

			if (isCredentialsButton) {
				// Only save credentials
				addOverlay(cloudProviderSection);

				responsesPromise = postForm(buildCredentialsData(formData)).then(response => [response]);
			} else {
				// Save general settings only
				addOverlay(generalSection);

				// Prepare general settings data
				const generalSettingsData = new URLSearchParams();
				generalSettingsData.append('action', 'advmo_save_general_settings');
				generalSettingsData.append('security_nonce', advmo_ajax_object.save_general_nonce);

				// Add all advmo_settings fields
				for (let [key, value] of formData.entries()) {
					if (key.startsWith('advmo_settings[')) {
						generalSettingsData.append(key, value);
					}
				}

				responsesPromise = postForm(generalSettingsData).then(response => [response]);
			}

			// Send the appropriate request(s) and ensure minimum loading duration
			Promise.all([
				responsesPromise,
				new Promise(resolve => {
					const elapsed = Date.now() - loadingStartTime;
					const remaining = Math.max(0, minimumLoadingDuration - elapsed);
					setTimeout(resolve, remaining);
				})
			])
			.then(([responses]) => {
				// Remove overlays
				if (isCredentialsButton) {
					removeOverlay(cloudProviderSection);
				} else {
					removeOverlay(generalSection);
				}

				// Remove loading state from the clicked button
				setButtonLoading(button, false);

				// Check if all requests succeeded
				const allSucceeded = responses.every(response => response.success);

				if (allSucceeded) {
					// Show success on the clicked button
					showButtonSuccess(button);

					// Refresh the step badges for saves that don't end in a
					// test (the test below sends a fresher set of its own).
					responses.forEach(response => applySetupStatus(response.data?.setup_status));

					if (isCredentialsButton) {
						// Credentials are saved now, so the connection test can run
						// against them: enable the button and test right away.
						const testButton = document.querySelector('.advmo_js_test_connection');
						const bucketInput = document.querySelector('input[name$="[bucket]"]');
						const hasBucket = bucketInput && bucketInput.value.trim() !== '';

						if (testButton && hasBucket) {
							testButton.removeAttribute('disabled');
							testButton.removeAttribute('title');
							runConnectionTest(testButton);
						}
					}
				} else {
					const fallback = isCredentialsButton
						? (i18n.failed_save_credentials || 'Failed to save credentials.')
						: (i18n.failed_save_general || 'Failed to save general settings.');
					showErrorMessage(responses[0].data?.message || fallback);
				}
			})
			.catch(error => {
				// Remove overlays
				if (isCredentialsButton) {
					removeOverlay(cloudProviderSection);
				} else {
					removeOverlay(generalSection);
				}

				// Remove loading state from the clicked button
				setButtonLoading(button, false);

				// Show error
				showErrorMessage(i18n.error_saving || 'An error occurred while saving settings. Please try again.');
				console.error('Error:', error);
			});
		}

		// Intercept form submission
		settingsForm.addEventListener('submit', function(e) {
			e.preventDefault();

			// Find which button was clicked
			const submitButton = e.submitter || settingsForm.querySelector('input[type="submit"]');
			saveSettings(submitButton);
		});
	}
});
