/**
 * Bulk Optimize screen.
 *
 * Drives the queue one batch at a time. Each batch is its own request, so a
 * library of any size finishes without ever approaching the PHP time limit,
 * and closing the tab simply stops the driving — the queue itself survives.
 *
 * @package WebberZone\Image_Optimizer
 */
(function () {
	'use strict';

	var config = window.wzioBulk || {};
	var strings = config.strings || {};

	var running = false;
	var startedWith = 0;

	var els = {};

	function byId(id) {
		return document.getElementById(id);
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', config.nonce);

		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});

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

	function setProgress(stats) {
		els.progress.hidden = false;

		var remaining = stats.remaining || 0;
		var total = Math.max(startedWith, remaining);
		var done = total - remaining;
		var percent = total > 0 ? Math.round((done / total) * 100) : 100;

		els.fill.style.width = percent + '%';
		els.progress.setAttribute('aria-valuenow', String(percent));

		els.text.textContent =
			percent +
			'% — ' +
			remaining +
			' ' +
			strings.remaining +
			', ' +
			(stats.saved_human || '0 B') +
			' ' +
			strings.saved;
	}

	function renderStats(stats) {
		if (typeof stats.total !== 'undefined') {
			els.total.textContent = stats.total.toLocaleString();
		}
		if (typeof stats.optimized !== 'undefined') {
			els.optimized.textContent = stats.optimized.toLocaleString();
		}
		if (typeof stats.remaining !== 'undefined') {
			els.remaining.textContent = stats.remaining.toLocaleString();
		}
		if (typeof stats.saved_human !== 'undefined') {
			els.saved.textContent = stats.saved_human;
		}
	}

	function status(message) {
		els.progress.hidden = false;
		els.text.textContent = message;
	}

	function setRunning(state) {
		running = state;
		els.start.hidden = state;
		els.pause.hidden = !state;
		els.reset.disabled = state;
		els.force.disabled = state;
	}

	function step() {
		if (!running) {
			return;
		}

		post('wzio_bulk_step', {})
			.then(function (stats) {
				renderStats(stats);

				// Another worker (the background cron, or a second tab) holds the
				// lock. Waiting is correct: the work is still getting done.
				if (stats.locked) {
					status(strings.busy);
					window.setTimeout(step, 3000);
					return;
				}

				setProgress(stats);

				if (stats.remaining > 0 && stats.processed > 0) {
					window.setTimeout(step, 100);
					return;
				}

				setRunning(false);
				status(strings.done + ' ' + (stats.saved_human || '0 B') + ' ' + strings.saved + '.');
			})
			.catch(function (error) {
				setRunning(false);
				status(error.message || strings.error);
			});
	}

	function start() {
		setRunning(true);
		status(strings.scanning);

		post('wzio_bulk_scan', { force: els.force.checked ? '1' : '0' })
			.then(function (stats) {
				renderStats(stats);
				startedWith = stats.remaining || 0;

				if (startedWith === 0) {
					setRunning(false);
					status(strings.nothing);
					return;
				}

				status(strings.running);
				step();
			})
			.catch(function (error) {
				setRunning(false);
				status(error.message || strings.error);
			});
	}

	function pause() {
		setRunning(false);
		status(strings.paused);
	}

	function reset() {
		if (!window.confirm(strings.confirm)) {
			return;
		}

		post('wzio_bulk_reset', {})
			.then(function (stats) {
				renderStats(stats);
				startedWith = 0;
				status(strings.paused);
			})
			.catch(function (error) {
				status(error.message || strings.error);
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		els = {
			start: byId('wzio-start'),
			pause: byId('wzio-pause'),
			reset: byId('wzio-reset'),
			force: byId('wzio-force'),
			progress: byId('wzio-progress'),
			fill: byId('wzio-progress-fill'),
			text: byId('wzio-progress-text'),
			total: byId('wzio-stat-total'),
			optimized: byId('wzio-stat-optimized'),
			remaining: byId('wzio-stat-remaining'),
			saved: byId('wzio-stat-saved'),
		};

		if (!els.start) {
			return;
		}

		els.start.addEventListener('click', start);
		els.pause.addEventListener('click', pause);
		els.reset.addEventListener('click', reset);
	});
})();
