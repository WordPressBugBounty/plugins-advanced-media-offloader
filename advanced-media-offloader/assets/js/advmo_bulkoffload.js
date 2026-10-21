(() => {
	const state = { runId: null, generation: 0, timer: null, cancelling: false };
	const ids = {
		startButton: "bulk-offload-button", cancelButton: "bulk-offload-cancel-button",
		progressContainer: "progress-container", progressBar: "offload-progress",
		progressTitle: "progress-title", progressText: "progress-text",
		processedCount: "processed-count", totalCount: "total-count",
	};
	const elements = Object.fromEntries(Object.entries(ids).map(([key, id]) => [key, document.getElementById(id)]));
	elements.progressBarContainer = document.querySelector(".progress-bar-container");
	const message = (key, fallback) => advmo_ajax_object.bulk_messages?.[key] ?? fallback;
	const clearPoll = () => { clearTimeout(state.timer); state.timer = null; };
	const request = async (action, values = {}) => {
		const body = new FormData();
		body.append("action", action);
		body.append("bulk_offload_nonce", advmo_ajax_object.bulk_offload_nonce);
		Object.entries(values).forEach(([key, value]) => body.append(key, value));
		const response = await fetch(advmo_ajax_object.ajax_url, { method: "POST", credentials: "same-origin", body });
		const result = await response.json();
		if (!result.success) throw new Error(result.data?.message || message("request_failed", "The request failed. Refresh the run status and try again."));
		return result.data;
	};
	const finish = (text) => {
		clearPoll();
		elements.progressText.textContent = text;
		elements.startButton.disabled = false;
		elements.startButton.textContent = message("offload", "Offload Now");
		elements.cancelButton.disabled = true;
		state.cancelling = false;
	};
	const update = (data) => {
		if (state.runId && data.run_id && state.runId !== data.run_id) {
			finish(message("changed_run", "Another bulk run has started. Refresh this page to manage it."));
			return;
		}
		state.runId = data.run_id || state.runId;
		const processed = Math.max(0, parseInt(data.processed, 10) || 0);
		const total = Math.max(0, parseInt(data.total, 10) || 0);
		const percent = total > 0 ? Math.min(100, processed / total * 100) : 0;
		elements.processedCount.textContent = String(processed);
		elements.totalCount.textContent = String(total);
		elements.progressBar.style.width = `${percent}%`;
		elements.progressBar.setAttribute("aria-valuenow", percent);
		elements.progressText.textContent = `${Math.round(percent)}%`;
		const status = data.run_status || data.status;
		if (status === "cancelled" || status === "completed" || status === "idle") {
			const key = status === "cancelled" ? "cancelled" : (total === 0 ? "no_files" : "complete");
			finish(message(key, status === "cancelled" ? "Bulk offload cancelled." : (total === 0 ? "No files to offload." : "Offload complete.")));
			if (status === "completed" && Number(data.errors) > 0) {
				elements.progressText.textContent += ` ${message("failed_files", "Files that failed to offload:")} ${data.errors}`;
			}
			return;
		}
		if (status === "paused") {
			finish(message("paused", "Bulk offload paused. Resolve the problem before resuming."));
			elements.startButton.textContent = message("resume", "Resume offload");
			elements.cancelButton.disabled = false;
			return;
		}
		// Older replacement handlers may return counts without the new run fields.
		if (!data.run_id && !data.run_status && (processed === total || total === 0)) {
			finish(message(total === 0 ? "no_files" : "complete", total === 0 ? "No files to offload." : "Offload complete."));
			return;
		}
		state.cancelling = state.cancelling || status === "cancelling";
		elements.startButton.disabled = true;
		elements.cancelButton.disabled = state.cancelling;
		if (state.cancelling) elements.progressText.textContent = message("cancelling", "Cancelling after the active upload finishes…");
		clearPoll();
		state.timer = setTimeout(checkProgress, 5000);
	};
	const checkProgress = async () => {
		clearPoll();
		const generation = state.generation;
		try {
			const data = await request("advmo_check_bulk_offload_progress");
			if (generation === state.generation) update(data);
		} catch (error) {
			if (generation !== state.generation) return;
			elements.progressText.textContent = error.message;
			state.timer = setTimeout(checkProgress, 5000);
		}
	};
	const start = async (event) => {
		event.preventDefault();
		if (elements.startButton.disabled) return;
		const generation = ++state.generation;
		clearPoll();
		state.runId = null;
		state.cancelling = false;
		elements.startButton.disabled = true;
		elements.cancelButton.disabled = true;
		elements.progressContainer.style.display = "block";
		elements.progressText.textContent = message("preparing", "Preparing…");
		try {
			const data = await request("advmo_start_bulk_offload", { batch_size: 50 });
			if (generation !== state.generation) return;
			state.runId = data.run_id || null;
			// Read the canonical state, including an already active or paused run.
			checkProgress();
		} catch (error) {
			if (generation === state.generation) finish(error.message);
		}
	};
	const cancel = async (event) => {
		event.preventDefault();
		if (elements.cancelButton.disabled) return;
		const generation = ++state.generation;
		clearPoll();
		state.cancelling = true;
		elements.startButton.disabled = true;
		elements.cancelButton.disabled = true;
		elements.progressText.textContent = message("cancelling", "Cancelling after the active upload finishes…");
		try {
			await request("advmo_cancel_bulk_offload", state.runId ? { run_id: state.runId } : {});
			if (generation === state.generation) checkProgress();
		} catch (error) {
			if (generation !== state.generation) return;
			state.cancelling = false;
			elements.progressText.textContent = error.message;
			// A failed response can still follow a saved cancellation. Read before offering controls.
			state.timer = setTimeout(checkProgress, 5000);
		}
	};
	document.addEventListener("DOMContentLoaded", () => {
		if (typeof advmo_ajax_object === "undefined" || Object.values(elements).some((element) => !element)) return;
		elements.startButton.addEventListener("click", start);
		elements.cancelButton.addEventListener("click", cancel);
		state.runId = elements.progressContainer.dataset.runId || null;
		if (["starting", "processing", "paused", "cancelling"].includes(elements.progressContainer.dataset.status)) {
			state.cancelling = elements.progressContainer.dataset.status === "cancelling";
			checkProgress();
		}
	});
})();
