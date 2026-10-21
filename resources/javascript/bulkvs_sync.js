var bulkvs_sync = (function () {
	var controller = new AbortController();
	var busy_retry_delays_ms = [5000, 20000];

	window.addEventListener('pagehide', function () {
		controller.abort();
	});

	function wait(ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	}

	function run(type, attempt) {
		return fetch('bulkvs_sync.php?type=' + encodeURIComponent(type), {
			credentials: 'same-origin',
			signal: controller.signal
		}).then(function (response) {
			return response.json();
		}).then(function (result) {
			if (result.status === 'busy' && attempt < busy_retry_delays_ms.length) {
				return wait(busy_retry_delays_ms[attempt]).then(function () {
					return run(type, attempt + 1);
				});
			}
			return result;
		});
	}

	function show_notice(message) {
		var notice = document.getElementById('bulkvs_sync_notice');
		if (notice) {
			notice.textContent = message;
		}
	}

	function hide_notice() {
		var notice = document.getElementById('bulkvs_sync_notice');
		if (notice) {
			notice.style.display = 'none';
		}
	}

	return {
		/**
		 * options.types: sync types to run, e.g. ['numbers', 'e911']
		 * options.refresh_type: the type whose cache this page renders
		 * options.reload_when_done: the page rendered an empty cache, so reload once it fills
		 */
		start: function (options) {
			options.types.forEach(function (type) {
				run(type, 0).then(function (result) {
					if (result.refresh_available) {
						var button = document.getElementById('refresh_button_container');
						if (button) {
							button.style.display = '';
						}
					}
					if (result.status === 'failed') {
						show_notice(result.message);
						return;
					}
					if (type !== options.refresh_type || !options.reload_when_done || result.status !== 'completed') {
						return;
					}
					if (result.total > 0) {
						location.reload();
					} else {
						hide_notice();
					}
				}).catch(function () {});
			});
		},

		refresh: function (type) {
			controller.abort();
			fetch('bulkvs_sync.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams({type: type, reset: '1'})
			}).catch(function () {}).then(function () {
				location.reload();
			});
		}
	};
})();
