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

	// Scoped underline sub-tabs (KB & Settings)
	document.querySelectorAll('.ifaq-subtabs').forEach(function (group) {
		var tabs = group.querySelectorAll('.ifaq-subtab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var panelId = tab.getAttribute('data-tab');
				tabs.forEach(function (t) {
					t.classList.remove('is-active');
					var p = document.getElementById(t.getAttribute('data-tab'));
					if (p) {
						p.classList.remove('is-active');
					}
				});
				tab.classList.add('is-active');
				var activePanel = document.getElementById(panelId);
				if (activePanel) {
					activePanel.classList.add('is-active');
				}
			});
		});
	});

	// Shortcode copy tiles
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

	// File input display label
	var mdFileInput = document.getElementById('infinibase_md_file');
	var mdFileLabel = document.getElementById('infinibase-file-label');
	if (mdFileInput && mdFileLabel) {
		mdFileInput.addEventListener('change', function () {
			if (mdFileInput.files && mdFileInput.files.length > 0) {
				mdFileLabel.textContent = mdFileInput.files[0].name;
			} else {
				mdFileLabel.textContent = 'Choose Markdown file...';
			}
		});
	}

	// Visual AI Provider tile selector
	var providerHidden = document.getElementById('infinibase_ai_provider');
	var wpCoreNotice = document.getElementById('infinibase-wp-core-notice');
	var apiKeyRow = document.getElementById('infinibase-api-key-row');

	document.querySelectorAll('#infinibase-provider-grid .ifaq-provider-tile').forEach(function (tile) {
		tile.addEventListener('click', function () {
			var selectedProvider = tile.getAttribute('data-provider');
			if (!selectedProvider) {
				return;
			}
			document.querySelectorAll('#infinibase-provider-grid .ifaq-provider-tile').forEach(function (t) {
				t.classList.remove('is-selected');
			});
			tile.classList.add('is-selected');

			if (providerHidden) {
				providerHidden.value = selectedProvider;
			}
			if (wpCoreNotice && apiKeyRow) {
				if (selectedProvider === 'wp_core') {
					wpCoreNotice.classList.add('is-visible');
					apiKeyRow.classList.add('ifaq-row-hidden');
				} else {
					wpCoreNotice.classList.remove('is-visible');
					apiKeyRow.classList.remove('ifaq-row-hidden');
				}
			}
		});
	});

	// Visual Response Style (Brief / Standard) cards
	var answerLengthHidden = document.getElementById('infinibase_answer_length');
	document.querySelectorAll('#infinibase-choice-grid .ifaq-choice-card').forEach(function (card) {
		card.addEventListener('click', function () {
			var val = card.getAttribute('data-answer-length');
			if (!val) {
				return;
			}
			document.querySelectorAll('#infinibase-choice-grid .ifaq-choice-card').forEach(function (c) {
				c.classList.remove('is-selected');
			});
			card.classList.add('is-selected');
			if (answerLengthHidden) {
				answerLengthHidden.value = val;
			}
		});
	});

	// Dismiss inline alert banners
	document.addEventListener('click', function (e) {
		var closeBtn = e.target.closest('.ifaq-alert-close');
		if (!closeBtn) {
			return;
		}
		var banner = closeBtn.closest('.ifaq-alert-banner');
		if (banner) {
			banner.remove();
		}
	});

	// Confirm prompts
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

	// Background optimization live status polling
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

	// 1-Click Test Connection
	var testConnBtn = document.getElementById('infinibase-test-connection-btn');
	if (testConnBtn && typeof window.infinibaseAdminData !== 'undefined') {
		testConnBtn.addEventListener('click', function () {
			var providerVal = providerHidden ? providerHidden.value : '';
			var keyEl = document.getElementById('infinibase_api_key');
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

	// Condition-triggered review banner dismiss/snooze
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

	// 1-Click Copy Debug Info
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
