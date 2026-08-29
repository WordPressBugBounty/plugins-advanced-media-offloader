addEventListener("DOMContentLoaded", function () {
	const button = document.querySelector('.advmo-copy-report');
	const report = document.getElementById('advmo-system-report');

	if (!button || !report) {
		return;
	}

	button.addEventListener('click', function () {
		const restoreLabel = button.textContent.trim();
		const copiedLabel = button.getAttribute('data-copied') || 'Copied!';
		const hintLabel = button.getAttribute('data-copy-hint') || 'Press Ctrl/Cmd + C to copy';

		const flash = (label, duration) => {
			button.textContent = label;
			setTimeout(() => { button.textContent = restoreLabel; }, duration);
		};

		// No clipboard access (insecure origin, denied permission): select the
		// report so the keyboard shortcut still works, and say so — silently
		// selecting the text looks like the button did nothing.
		const selectReport = () => {
			const selection = window.getSelection();
			const range = document.createRange();
			range.selectNodeContents(report);
			selection.removeAllRanges();
			selection.addRange(range);
			flash(hintLabel, 3000);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(report.textContent).then(
				() => flash(copiedLabel, 2000),
				selectReport
			);
		} else {
			selectReport();
		}
	});
});
