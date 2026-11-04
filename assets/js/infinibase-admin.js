(function () {
	'use strict';

	function copyTextToClipboard(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var textArea = document.createElement('textarea');
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.left = '-9999px';
			textArea.style.top = '0';
			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();
			try {
				var successful = document.execCommand('copy');
				document.body.removeChild(textArea);
				if (successful) {
					resolve();
				} else {
					reject();
				}
			} catch (err) {
				document.body.removeChild(textArea);
				reject(err);
			}
		});
	}

	document.querySelectorAll('.ifaq-copy-shortcode-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var code = btn.getAttribute('data-shortcode');
			var status = btn.querySelector('.ifaq-copy-status');
			if (!code) {
				return;
			}
			copyTextToClipboard(code).then(function () {
				if (status) {
					status.textContent = 'Copied!';
					status.classList.add('is-copied');
					setTimeout(function () {
						status.textContent = 'Copy';
						status.classList.remove('is-copied');
					}, 2000);
				}
			});
		});
	});

	var providerSelect = document.getElementById('infinibase_ai_provider');
	var wpCoreNotice = document.getElementById('infinibase-wp-core-notice');
	var apiKeyRow = document.getElementById('infinibase-api-key-row');

	if (providerSelect && wpCoreNotice && apiKeyRow) {
		providerSelect.addEventListener('change', function () {
			if (providerSelect.value === 'wp_core') {
				wpCoreNotice.classList.add('is-visible');
				apiKeyRow.classList.add('ifaq-row-hidden');
			} else {
				wpCoreNotice.classList.remove('is-visible');
				apiKeyRow.classList.remove('ifaq-row-hidden');
			}
		});
	}

	document.addEventListener('click', function (e) {
		var trigger = e.target.closest('[data-confirm]');
		if (!trigger) {
			return;
		}
		var message = trigger.getAttribute('data-confirm');
		if (message && !window.confirm(message)) {
			e.preventDefault();
		}
	});
})();
