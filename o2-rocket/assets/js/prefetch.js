/* O² Rocket — prefetch de links ao passar o mouse (fallback das speculation rules, sem dependências). */
(function () {
	'use strict';

	var config = window.o2rPrefetch || {};
	var supportsRules = window.HTMLScriptElement && typeof HTMLScriptElement.supports === 'function' && HTMLScriptElement.supports('speculationrules');

	// O núcleo do WordPress já cuida disso neste navegador.
	if (config.core && supportsRules && document.querySelector('script[type="speculationrules"]')) {
		return;
	}

	var probe = document.createElement('link');

	if (!probe.relList || !probe.relList.supports || !probe.relList.supports('prefetch')) {
		return;
	}

	var connection = navigator.connection;

	if (connection && (connection.saveData || /2g/.test(connection.effectiveType || ''))) {
		return;
	}

	var exclude = null;

	try {
		exclude = config.exclude ? new RegExp(config.exclude, 'i') : null;
	} catch (error) {
		exclude = null;
	}

	var delay = parseInt(config.delay, 10) || 100;
	var done = {};
	var timer = null;
	var windowStart = 0;
	var inWindow = 0;

	function candidate(anchor) {
		if (!anchor || !anchor.href || anchor.hasAttribute('download')) {
			return null;
		}

		if (/(?:^|\s)nofollow(?:\s|$)/i.test(anchor.getAttribute('rel') || '') || (anchor.closest && anchor.closest('.no-prefetch'))) {
			return null;
		}

		var url;

		try {
			url = new URL(anchor.href, window.location.href);
		} catch (error) {
			return null;
		}

		if (url.origin !== window.location.origin || url.search || url.pathname === window.location.pathname) {
			return null;
		}

		if (exclude && exclude.test(url.pathname)) {
			return null;
		}

		var key = url.origin + url.pathname;

		return done[key] ? null : key;
	}

	// No máximo 3 por segundo: passar o mouse por um menu não dispara dezenas de requisições.
	function prefetch(key) {
		var now = Date.now();

		if (now - windowStart > 1000) {
			windowStart = now;
			inWindow = 0;
		}

		if (done[key] || inWindow >= 3) {
			return;
		}

		done[key] = true;
		inWindow++;

		var link = document.createElement('link');
		link.rel = 'prefetch';
		link.href = key;
		document.head.appendChild(link);
	}

	function anchorFrom(event) {
		return event.target && event.target.closest ? event.target.closest('a') : null;
	}

	document.addEventListener('mouseover', function (event) {
		var anchor = anchorFrom(event);
		var key = candidate(anchor);

		if (!key) {
			return;
		}

		window.clearTimeout(timer);
		timer = window.setTimeout(function () {
			prefetch(key);
		}, delay);

		anchor.addEventListener('mouseout', function () {
			window.clearTimeout(timer);
		}, { once: true, passive: true });
	}, { passive: true });

	['touchstart', 'mousedown'].forEach(function (type) {
		document.addEventListener(type, function (event) {
			var key = candidate(anchorFrom(event));

			if (key) {
				prefetch(key);
			}
		}, { passive: true });
	});
})();
