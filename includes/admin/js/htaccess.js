/**
 * One-click .htaccess install/remove on the Delivery settings tab.
 *
 * @package WebberZone\Image_Optimizer
 */
(function () {
	'use strict';

	var config = window.wzioHtaccess || {};
	var strings = config.strings || {};

	function post(action, nonce) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce);

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error((payload && payload.data && payload.data.message) || strings.error);
				}

				return payload.data;
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var wrap = document.querySelector('.wzio-htaccess-controls');

		if (!wrap) {
			return;
		}

		var nonce = wrap.getAttribute('data-nonce');
		var install = document.getElementById('wzio-htaccess-install');
		var remove = document.getElementById('wzio-htaccess-remove');
		var status = document.getElementById('wzio-htaccess-status');

		function toggle(installed) {
			install.hidden = installed;
			remove.hidden = !installed;
			status.textContent = installed ? strings.installed : '';
		}

		if (install) {
			install.addEventListener('click', function () {
				install.disabled = true;
				status.textContent = strings.working;

				post('wzio_install_htaccess', nonce)
					.then(function () {
						toggle(true);
					})
					.catch(function (error) {
						status.textContent = error.message || strings.error;
					})
					.finally(function () {
						install.disabled = false;
					});
			});
		}

		if (remove) {
			remove.addEventListener('click', function () {
				remove.disabled = true;
				status.textContent = strings.working;

				post('wzio_remove_htaccess', nonce)
					.then(function () {
						toggle(false);
					})
					.catch(function (error) {
						status.textContent = error.message || strings.error;
					})
					.finally(function () {
						remove.disabled = false;
					});
			});
		}
	});
})();
