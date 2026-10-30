/* O² Rocket — mede a imagem LCP e as imagens acima da dobra desta página e envia ao próprio site (uma vez por página). */
(function (window, document) {
	'use strict';

	var config = window.o2rBeacon;
	var MAX_ABOVE = 20;

	if (!config || !('PerformanceObserver' in window) || !window.fetch || /bot|crawl|spider|slurp|preview/i.test(navigator.userAgent)) {
		return;
	}

	var lcpEntry = null;

	try {
		new PerformanceObserver(function (list) {
			var entries = list.getEntries();
			lcpEntry = entries[entries.length - 1] || lcpEntry;
		}).observe({ type: 'largest-contentful-paint', buffered: true });
	} catch (error) {
		return;
	}

	function absolute(url) {
		try {
			return url ? new URL(url, window.location.href).href : '';
		} catch (error) {
			return '';
		}
	}

	function usable(url) {
		return url && !/^data:/i.test(url) && /^https?:/i.test(url);
	}

	function inViewport(element) {
		var box = element.getBoundingClientRect();
		var height = window.innerHeight || document.documentElement.clientHeight;
		var width = window.innerWidth || document.documentElement.clientWidth;

		return box.width > 0 && box.height > 0 && box.bottom > 0 && box.right > 0 && box.top < height && box.left < width;
	}

	function backgrounds(element) {
		var urls = [];
		var value = window.getComputedStyle(element).backgroundImage || '';
		var pattern = /url\(\s*(['"]?)(.*?)\1\s*\)/g;
		var match;

		while ((match = pattern.exec(value)) !== null) {
			var url = absolute(match[2]);

			if (usable(url)) {
				urls.push(url);
			}
		}

		return urls;
	}

	function describe(entry) {
		var element = entry && entry.element;

		if (!element) {
			return { type: 'none' };
		}

		if (element.tagName === 'IMG') {
			var src = absolute(element.getAttribute('src'));
			var picture = element.parentNode && element.parentNode.tagName === 'PICTURE';

			if (picture) {
				return { type: 'picture', src: absolute(element.currentSrc) || src };
			}

			if (element.getAttribute('srcset')) {
				return { type: 'img-srcset', src: src, current: absolute(element.currentSrc), srcset: element.getAttribute('srcset'), sizes: element.getAttribute('sizes') || '' };
			}

			return usable(src) ? { type: 'img', src: src } : { type: 'none' };
		}

		var url = absolute(entry.url);

		if (usable(url)) {
			return { type: 'bg-img', src: url };
		}

		return { type: 'text' };
	}

	// Tags, classes, ids e atributos dos elementos visíveis no topo: base do CSS crítico gerado no servidor.
	var tokens = { tags: {}, words: {} };
	var wordCount = 0;

	function addWord(word) {
		if (word && !tokens.words[word] && wordCount < 4000) {
			tokens.words[word] = 1;
			wordCount++;
		}
	}

	function collectTokens(element) {
		tokens.tags[element.tagName.toLowerCase()] = 1;

		Array.prototype.forEach.call(element.classList || [], function (name) {
			(name.match(/[A-Za-z_][A-Za-z0-9_-]*/g) || []).forEach(addWord);
		});

		if (element.id) {
			(element.id.match(/[A-Za-z_][A-Za-z0-9_-]*/g) || []).forEach(addWord);
		}

		Array.prototype.forEach.call(element.attributes || [], function (attribute) {
			if (/^(?:data-|aria-|type$|role$)/.test(attribute.name)) {
				addWord(attribute.name);
			}
		});
	}

	function aboveTheFold() {
		var urls = [];

		collectTokens(document.documentElement);

		Array.prototype.forEach.call(document.querySelectorAll('img'), function (img) {
			var src = absolute(img.currentSrc || img.getAttribute('src'));

			if (urls.length < MAX_ABOVE && usable(src) && inViewport(img)) {
				urls.push(src);
			}
		});

		Array.prototype.forEach.call(document.querySelectorAll('body *'), function (element) {
			if (element.tagName === 'SCRIPT' || element.tagName === 'STYLE' || element.tagName === 'NOSCRIPT') {
				return;
			}

			if (element.tagName === 'IMG') {
				if (inViewport(element)) {
					collectTokens(element);
				}

				return;
			}

			// Posição antes do estilo: getComputedStyle em todos os elementos da página seria caro.
			if (inViewport(element)) {
				collectTokens(element);

				if (urls.length < MAX_ABOVE) {
					urls = urls.concat(backgrounds(element));
				}
			}
		});

		return urls.filter(function (url, index) {
			return urls.indexOf(url) === index;
		}).slice(0, MAX_ABOVE);
	}

	// Fontes e domínios externos pedidos antes da LCP (+1 s): os que valem preload e preconnect.
	function resources() {
		var limit = (lcpEntry ? lcpEntry.startTime : 0) + 1000;
		var fonts = [];
		var origins = [];
		var cors = [];

		if (!window.performance || !performance.getEntriesByType) {
			return { fonts: fonts, origins: origins, cors: cors };
		}

		Array.prototype.forEach.call(performance.getEntriesByType('resource'), function (entry) {
			if (entry.startTime > limit || !/^https?:/i.test(entry.name)) {
				return;
			}

			var origin;

			try {
				origin = new URL(entry.name).origin;
			} catch (error) {
				return;
			}

			var isFont = /\.(?:woff2?|ttf|otf)(?:[?#]|$)/i.test(entry.name);

			if (isFont && fonts.length < 8 && fonts.indexOf(entry.name) === -1) {
				fonts.push(entry.name);
			}

			if (origin !== window.location.origin && origins.indexOf(origin) === -1 && origins.length < 10) {
				origins.push(origin);
			}

			if (origin !== window.location.origin && isFont && cors.indexOf(origin) === -1) {
				cors.push(origin);
			}
		});

		return { fonts: fonts, origins: origins, cors: cors };
	}

	function send() {
		// Página rolada ou em segundo plano: a medição não representa o topo da página.
		if (window.pageYOffset > 0 || document.visibilityState === 'hidden') {
			return;
		}

		var body = new FormData();
		body.append('action', 'o2_rocket_beacon');
		body.append('url', config.url);
		body.append('mode', config.mode);
		body.append('key', config.key);
		body.append('device', (window.innerWidth || document.documentElement.clientWidth) < 768 ? 'mobile' : 'desktop');
		var above = aboveTheFold();

		body.append('data', JSON.stringify({ lcp: describe(lcpEntry), above: above, resources: resources(), tokens: { tags: Object.keys(tokens.tags), words: Object.keys(tokens.words) } }));

		if (!navigator.sendBeacon || !navigator.sendBeacon(config.ajax, body)) {
			window.fetch(config.ajax, { method: 'POST', body: body, credentials: 'omit', keepalive: true }).catch(function () {});
		}
	}

	function schedule() {
		window.setTimeout(send, 1500);
	}

	if (document.readyState === 'complete') {
		schedule();
	} else {
		window.addEventListener('load', schedule);
	}
})(window, document);
