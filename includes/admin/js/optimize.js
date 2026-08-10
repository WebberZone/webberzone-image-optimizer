/**
 * Progressively enhances the "Optimize" link so it runs over AJAX.
 *
 * @package WebberZone\Image_Optimizer
 */
(function () {
	'use strict';

	var config = window.wzioOptimize || {};
	var strings = config.strings || {};

	function post(id) {
		var body = new URLSearchParams();
		body.append('action', 'wzio_optimize_attachment');
		body.append('nonce', config.nonce);
		body.append('id', id);

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		})
			.then(function (response) {
				return response.text();
			})
			.then(function (text) {
				var payload;

				try {
					payload = JSON.parse(text);
				} catch (e) {
					// Connection dropped before the server could reply.
					throw new Error(strings.timeout);
				}

				if (!payload || !payload.success) {
					throw new Error((payload && payload.data && payload.data.message) || strings.error);
				}

				return payload.data;
			});
	}

	function step(link, id, original) {
		post(id)
			.then(function (data) {
				if (!data.done) {
					link.textContent = strings.optimizing + ' ' + data.index + '/' + data.total;
					step(link, id, original);
					return;
				}

				window.location.reload();
			})
			.catch(function (error) {
				link.classList.remove('wzio-busy');
				link.textContent = original;

				var note = document.createElement('span');
				note.className = 'wzio-optimize-error';
				note.textContent = ' ' + (error.message || strings.error);
				link.insertAdjacentElement('afterend', note);
			});
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest('.wzio-optimize-attachment');

		if (!link || link.classList.contains('wzio-busy')) {
			return;
		}

		event.preventDefault();

		var next = link.nextElementSibling;
		if (next && next.classList.contains('wzio-optimize-error')) {
			next.remove();
		}

		var original = link.textContent;

		link.classList.add('wzio-busy');
		link.textContent = strings.optimizing;

		step(link, link.getAttribute('data-id'), original);
	});
})();
