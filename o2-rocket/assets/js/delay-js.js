/* O² Rocket — executa os scripts atrasados na primeira interação do visitante (sem dependências). */
(function (window, document) {
	'use strict';

	var TYPE = 'o2rocket/delayed';
	var TRIGGERS = ['keydown', 'mousedown', 'mousemove', 'touchmove', 'touchstart', 'touchend', 'wheel'];
	var SCRIPT_TIMEOUT = 10000;
	var config = window.o2rDelay || {};
	var started = false;
	var finished = false;
	var clicks = [];
	var queued = { document: [], window: [] };
	var readyState = 'loading';
	var originals = {};

	function each(list, callback) {
		Array.prototype.forEach.call(list, callback);
	}

	function listen() {
		each(TRIGGERS, function (type) {
			window.addEventListener(type, start, { passive: true });
		});

		var timeout = parseInt(config.timeout, 10) || 0;

		if (timeout > 0) {
			var arm = function () {
				window.setTimeout(start, timeout * 1000);
			};

			if (document.readyState === 'complete') {
				arm();
			} else {
				window.addEventListener('load', arm);
			}
		}
	}

	function unlisten() {
		each(TRIGGERS, function (type) {
			window.removeEventListener(type, start, { passive: true });
		});
	}

	// Clique feito antes de os scripts rodarem: segura e repete no fim (menus, abas, botões).
	function holdClick(event) {
		if (finished || !event.isTrusted) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		clicks.push(event.target);
	}

	function replayClicks() {
		window.removeEventListener('click', holdClick, true);

		each(clicks, function (target) {
			if (target && target.isConnected) {
				target.dispatchEvent(new MouseEvent('click', { view: window, bubbles: true, cancelable: true }));
			}
		});

		clicks = [];
	}

	// Ouvintes de DOMContentLoaded/load registrados pelos scripts atrasados ficam na fila e disparam no fim, na ordem certa.
	function patchEvents() {
		originals.documentAdd = document.addEventListener;
		originals.windowAdd = window.addEventListener;
		originals.write = document.write;
		originals.writeln = document.writeln;
		originals.onload = window.onload;

		document.addEventListener = function (type, listener, options) {
			if (type === 'DOMContentLoaded' || type === 'readystatechange') {
				queued.document.push([type, listener]);
				return;
			}

			return originals.documentAdd.call(this, type, listener, options);
		};

		window.addEventListener = function (type, listener, options) {
			if (type === 'DOMContentLoaded' || type === 'load' || type === 'pageshow') {
				queued.window.push([type, listener]);
				return;
			}

			return originals.windowAdd.call(this, type, listener, options);
		};

		// Scripts que testam document.readyState veem a página "carregando", como num carregamento normal.
		try {
			Object.defineProperty(document, 'readyState', {
				configurable: true,
				get: function () {
					return readyState;
				}
			});
		} catch (error) {
			// Navegador antigo: segue sem a emulação.
		}

		// document.write depois do carregamento apagaria a página: insere o HTML logo depois do script.
		var write = function () {
			var html = Array.prototype.join.call(arguments, '');
			var current = document.currentScript;

			if (current && current.parentNode) {
				current.insertAdjacentHTML('afterend', html);
			}
		};

		document.write = write;
		document.writeln = function () {
			write(Array.prototype.join.call(arguments, '') + '\n');
		};
	}

	function restoreEvents() {
		document.addEventListener = originals.documentAdd;
		window.addEventListener = originals.windowAdd;
		document.write = originals.write;
		document.writeln = originals.writeln;

		try {
			delete document.readyState;
		} catch (error) {
			// Mantém a emulação em navegadores que não permitem remover.
		}
	}

	function fire(target, type) {
		var event = new Event(type, { bubbles: type === 'DOMContentLoaded' });
		var list = queued[target === document ? 'document' : 'window'];

		each(list.slice(), function (item) {
			if (item[0] !== type) {
				return;
			}

			try {
				if (typeof item[1] === 'function') {
					item[1].call(target, event);
				} else if (item[1] && typeof item[1].handleEvent === 'function') {
					item[1].handleEvent(event);
				}
			} catch (error) {
				window.console && window.console.error(error);
			}
		});

		if (type === 'load' && typeof window.onload === 'function' && window.onload !== originals.onload) {
			try {
				window.onload(event);
			} catch (error) {
				window.console && window.console.error(error);
			}
		}
	}

	// Recria o script no mesmo lugar: o navegador só executa scripts inseridos como elementos novos.
	function run(original, done) {
		var src = original.getAttribute('data-o2r-src');
		var script = document.createElement('script');
		var finishedOne = false;

		var complete = function () {
			if (!finishedOne) {
				finishedOne = true;
				done();
			}
		};

		each(original.attributes, function (attribute) {
			if (attribute.name !== 'type' && attribute.name !== 'data-o2r-src' && attribute.name !== 'data-o2r-type') {
				script.setAttribute(attribute.name, attribute.value);
			}
		});

		if (original.getAttribute('data-o2r-type')) {
			script.setAttribute('type', original.getAttribute('data-o2r-type'));
		}

		if (original.nonce) {
			script.nonce = original.nonce;
		}

		// nomodule em navegador moderno nunca dispara onload/onerror.
		if (script.hasAttribute('nomodule') && 'noModule' in script) {
			original.parentNode && original.parentNode.removeChild(original);
			complete();
			return;
		}

		if (src) {
			script.async = false;
			script.onload = complete;
			script.onerror = complete;
			window.setTimeout(complete, SCRIPT_TIMEOUT);
			script.src = src;
		} else {
			script.text = original.text;
		}

		if (original.parentNode) {
			original.parentNode.replaceChild(script, original);
		} else {
			document.head.appendChild(script);
		}

		if (!src) {
			complete();
		}
	}

	function runInOrder(list, callback) {
		var index = 0;

		var next = function () {
			if (index >= list.length) {
				callback();
				return;
			}

			run(list[index++], next);
		};

		next();
	}

	function preload(list) {
		each(list, function (script) {
			var src = script.getAttribute('data-o2r-src');

			if (!src) {
				return;
			}

			var link = document.createElement('link');
			link.rel = 'preload';
			link.as = 'script';
			link.href = src;

			if (script.hasAttribute('crossorigin')) {
				link.crossOrigin = script.getAttribute('crossorigin');
			}

			document.head.appendChild(link);
		});
	}

	function finish() {
		readyState = 'interactive';
		fire(document, 'readystatechange');
		fire(document, 'DOMContentLoaded');
		fire(window, 'DOMContentLoaded');

		readyState = 'complete';
		fire(document, 'readystatechange');
		fire(window, 'load');
		fire(window, 'pageshow');

		finished = true;
		restoreEvents();
		replayClicks();
		window.dispatchEvent(new Event('o2r-delay-done'));
	}

	function start() {
		if (started) {
			return;
		}

		started = true;
		unlisten();
		window.addEventListener('click', holdClick, true);
		window.dispatchEvent(new Event('o2r-delay-start'));

		var scripts = document.querySelectorAll('script[type="' + TYPE + '"]');
		var ordered = [];
		var deferred = [];
		var independent = [];

		each(scripts, function (script) {
			var external = script.hasAttribute('data-o2r-src');

			if (external && script.hasAttribute('async')) {
				independent.push(script);
			} else if (external && script.hasAttribute('defer')) {
				deferred.push(script);
			} else {
				ordered.push(script);
			}
		});

		preload(ordered.concat(deferred));
		patchEvents();

		each(independent, function (script) {
			run(script, function () {});
		});

		runInOrder(ordered.concat(deferred), finish);
	}

	listen();
})(window, document);
