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

	function pollProcessingStatus() {
		var processingBadges = document.querySelectorAll('.ifaq-status-badge.is-processing');
		if (!processingBadges.length || typeof window.infinibaseAdminData === 'undefined') {
			return;
		}

		var body = new URLSearchParams();
		body.append('action', 'infinibase_check_status');
		body.append('nonce', window.infinibaseAdminData.nonce || '');

		fetch(window.infinibaseAdminData.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					return;
				}
				var stillProcessing = payload.data.processingFiles || [];
				document.querySelectorAll('.ifaq-status-badge[data-file-status]').forEach(function (badge) {
					var fname = badge.getAttribute('data-file-status');
					var label = badge.querySelector('.ifaq-status-label');
					if (stillProcessing.indexOf(fname) === -1) {
						badge.classList.remove('is-processing');
						badge.classList.add('is-ready');
						if (label) {
							label.textContent = 'Ready';
						}
					}
				});

				if (!payload.data.allReady) {
					setTimeout(pollProcessingStatus, 3000);
				}
			})
			.catch(function () {
				setTimeout(pollProcessingStatus, 5000);
			});
	}

	if (document.querySelectorAll('.ifaq-status-badge.is-processing').length > 0) {
		setTimeout(pollProcessingStatus, 2500);
	}

	var testConnBtn = document.getElementById('infinibase-test-connection-btn');
	if (testConnBtn && typeof window.infinibaseAdminData !== 'undefined') {
		testConnBtn.addEventListener('click', function () {
			var providerEl = document.getElementById('infinibase_ai_provider');
			var keyEl = document.getElementById('infinibase_api_key');
			var providerVal = providerEl ? providerEl.value : '';
			var statusEl =
				providerVal === 'wp_core'
					? document.getElementById('infinibase-wp-core-test-status')
					: document.getElementById('infinibase-connection-status');
			var otherStatusEl =
				providerVal === 'wp_core'
					? document.getElementById('infinibase-connection-status')
					: document.getElementById('infinibase-wp-core-test-status');

			if (otherStatusEl) {
				otherStatusEl.textContent = '';
				otherStatusEl.className = 'ifaq-connection-status';
			}

			testConnBtn.disabled = true;
			testConnBtn.textContent = 'Testing...';
			if (statusEl) {
				statusEl.className = 'ifaq-connection-status is-testing';
				statusEl.textContent = 'Testing connection...';
			}

			var body = new URLSearchParams();
			body.append('action', 'infinibase_test_connection');
			body.append('nonce', window.infinibaseAdminData.nonce || '');
			body.append('provider', providerVal);
			body.append('api_key', keyEl ? keyEl.value : '');

			fetch(window.infinibaseAdminData.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (payload) {
					testConnBtn.disabled = false;
					testConnBtn.textContent = 'Test Connection';
					if (!statusEl) {
						return;
					}
					var msg =
						payload && payload.data && payload.data.message
							? payload.data.message
							: 'Unable to test connection.';
					if (payload && payload.success) {
						statusEl.className = 'ifaq-connection-status is-success';
					} else {
						statusEl.className = 'ifaq-connection-status is-error';
					}
					statusEl.textContent = msg;
				})
				.catch(function () {
					testConnBtn.disabled = false;
					testConnBtn.textContent = 'Test Connection';
					if (statusEl) {
						statusEl.className = 'ifaq-connection-status is-error';
						statusEl.textContent = 'Network error while testing connection.';
					}
				});
		});
	}

	document.querySelectorAll('.ifaq-review-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var action = btn.getAttribute('data-review-action') || 'dismiss';
			var banner = document.getElementById('infinibase-review-banner');
			if (banner) {
				banner.style.display = 'none';
			}
			if (typeof window.infinibaseAdminData === 'undefined') {
				return;
			}
			var body = new URLSearchParams();
			body.append('action', 'infinibase_review_action');
			body.append('nonce', window.infinibaseAdminData.nonce || '');
			body.append('review_action', action);

			fetch(window.infinibaseAdminData.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			});
		});
	});

	document.querySelectorAll('.ifaq-copy-debug-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var info = btn.getAttribute('data-debug-info');
			var label = btn.querySelector('.ifaq-debug-copy-label');
			if (!info) {
				return;
			}
			copyTextToClipboard(info).then(function () {
				if (label) {
					label.textContent = 'Copied Debug Info!';
					setTimeout(function () {
						label.textContent = 'Copy Debug Info';
					}, 2000);
				}
			});
		});
	});
})();
