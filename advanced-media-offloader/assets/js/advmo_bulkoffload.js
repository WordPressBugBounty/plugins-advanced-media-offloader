(() => {
	const state = {
		isProcessing: false,
		progress: 0,
		processed: 0,
		total: 0,
	};

	const elements = {
		startButton: document.getElementById("bulk-offload-button"),
		cancelButton: document.getElementById("bulk-offload-cancel-button"),
		progressContainer: document.getElementById("progress-container"),
		progressBar: document.getElementById("offload-progress"),
		progressBarContainer: document.querySelector(".progress-bar-container"),
		progressTitle: document.getElementById("progress-title"),
		progressText: document.getElementById("progress-text"),
		processedCount: document.getElementById("processed-count"),
		totalCount: document.getElementById("total-count"),
		messageContainer: document.createElement("div"),
	};

	const init = () => {
		elements.messageContainer.id = "advmo-message-container";
		elements.progressContainer.parentNode.insertBefore(
			elements.messageContainer,
			elements.progressContainer,
		);

		if (elements.startButton) {
			elements.startButton.addEventListener("click", startBulkOffload);
		}

		if (elements.cancelButton) {
			elements.cancelButton.addEventListener("click", cancelBulkOffload);
		}

		if (elements.progressContainer.dataset.status === "processing") {
			if (elements.startButton) {
				elements.startButton.disabled = true;
			}
			elements.progressContainer.style.display = "block";
			checkProgress();
		}
	};

	const showMessage = (message, isError = false) => {
		elements.messageContainer.textContent = message;
		elements.messageContainer.className = isError
			? "error-message"
			: "success-message";
		elements.messageContainer.style.display = "block";
		setTimeout(() => {
			elements.messageContainer.style.display = "none";
		}, 5000);
	};

	const startBulkOffload = async (e) => {
		e.preventDefault();
		elements.startButton.disabled = true;
		elements.progressContainer.style.display = "block";
		elements.progressBarContainer.style.display = "block";
		elements.progressTitle.style.display = "block";

		// Reset UI immediately so we don't briefly show stale counts from a previous run.
		state.processed = 0;
		// Use server-rendered estimate (unoffloaded count) if available so we show "0 of N" immediately.
		const totalEstimate = parseInt(
			elements.progressContainer?.dataset?.totalEstimate ?? "0",
			10,
		);
		state.total =
			!Number.isNaN(totalEstimate) && totalEstimate > 0 ? totalEstimate : 0;
		state.progress = 0;
		if (elements.processedCount) elements.processedCount.textContent = "0";
		if (elements.totalCount) elements.totalCount.textContent = String(state.total);
		if (elements.progressText) elements.progressText.textContent = "Preparing...";
		if (elements.progressBar) elements.progressBar.style.width = "0%";
		if (elements.cancelButton) {
			elements.cancelButton.style.display = "inline-block";
			elements.cancelButton.disabled = false;
		}

		const formData = new FormData();
		formData.append("action", "advmo_start_bulk_offload");
		formData.append(
			"bulk_offload_nonce",
			advmo_ajax_object.bulk_offload_nonce,
		);
		formData.append("batch_size", 50);

		try {
			const response = await fetch(advmo_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				state.isProcessing = true;
				// If backend returns a total, show "0 of X" immediately (prevents "0 of 0").
				const totalFromStart = parseInt(data?.data?.total ?? "0", 10);
				if (!Number.isNaN(totalFromStart) && totalFromStart > 0) {
					state.total = totalFromStart;
					if (elements.totalCount) elements.totalCount.textContent = String(totalFromStart);
					if (elements.processedCount) elements.processedCount.textContent = "0";
				}
				checkProgress();
			} else {
				showMessage(
					`Failed to start bulk offload process: ${data.data.message}`,
					true,
				);
				elements.startButton.disabled = false;
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage(
				"An error occurred while starting the bulk offload process",
				true,
			);
			elements.startButton.disabled = false;
		}
	};

	const checkProgress = async () => {
		const formData = new FormData();
		formData.append("action", "advmo_check_bulk_offload_progress");
		formData.append(
			"bulk_offload_nonce",
			advmo_ajax_object.bulk_offload_nonce,
		);

		try {
			const response = await fetch(advmo_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				updateProgressUI(data.data);
			} else {
				console.log(data.dada);
				showMessage(`Failed to check progress.`, true);
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage("An error occurred while checking the progress", true);
		}
	};

	const updateProgressUI = (progressData) => {
		state.processed = parseInt(progressData.processed);
		state.total = parseInt(progressData.total);
		state.progress =
			state.processed !== 0 && state.total !== 0
				? Math.min((state.processed / state.total) * 100, 100)
				: 0;
		state.errors = parseInt(progressData.errors);

		requestAnimationFrame(() => {
			elements.progressBar.style.width = `${state.progress}%`;
			elements.progressBar.setAttribute("aria-valuenow", state.progress);
			elements.progressText.textContent = `${Math.round(
				state.progress,
			)}%`;
			elements.processedCount.textContent = state.processed;
			elements.totalCount.textContent = state.total;

			if (state.total === state.processed && state.total !== 0) {
				completeOffload(state.errors);
			} else if (state.total === 0) {
				noFilesToOffload();
			} else {
				setTimeout(checkProgress, 5000);
			}
		});
	};

	const completeOffload = (errors) => {
		elements.progressText.textContent = "Offload complete!";

		if (errors > 0) {
			elements.progressText.textContent = `Offload complete! ${errors} files failed to offload.`;
		}
		if (elements.startButton) {
			elements.startButton.disabled = false;
		}
		elements.cancelButton.disabled = true;
		elements.progressBarContainer.style.display = "none";
		elements.progressTitle.style.display = "none";
		elements.cancelButton.style.display = "none";
		state.isProcessing = false;
	};

	const noFilesToOffload = () => {
		elements.progressText.textContent = "No files to offload";
		if (elements.startButton) {
			elements.startButton.disabled = false;
		}
		elements.progressContainer.style.display = "none";
		showMessage("No files to offload");
		state.isProcessing = false;
	};

	const cancelBulkOffload = async (e) => {
		e.preventDefault();
		elements.cancelButton.disabled = true;

		const formData = new FormData();
		formData.append("action", "advmo_cancel_bulk_offload");
		formData.append(
			"bulk_offload_nonce",
			advmo_ajax_object.bulk_offload_nonce,
		);

		try {
			const response = await fetch(advmo_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				showMessage("Bulk offload process cancelled successfully!");
				if (elements.startButton) {
					elements.startButton.disabled = false;
				}
				state.isProcessing = false;
			} else {
				console.log(data.data.message);
				showMessage(
					`Failed to cancel bulk offload process: ${data.data.message}`,
					true,
				);
				elements.cancelButton.disabled = false;
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage(
				"An error occurred while cancelling the bulk offload process",
				true,
			);
			elements.cancelButton.disabled = false;
		}
	};

	document.addEventListener("DOMContentLoaded", init);
})();
