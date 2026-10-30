/* O² Rocket — mostra as animações de entrada do Elementor visíveis na tela sem esperar o JavaScript atrasado. */
(function (window, document) {
	'use strict';

	var timers = [];

	// Mesmos pontos de quebra padrão do Elementor (celular até 767 px, tablet até 1024 px).
	function suffixes() {
		var width = window.innerWidth;

		if (width <= 767) {
			return ['_mobile', '_tablet', ''];
		}

		if (width <= 1024) {
			return ['_tablet', ''];
		}

		return [''];
	}

	function pick(settings, names) {
		var list = suffixes();

		for (var i = 0; i < list.length; i++) {
			for (var j = 0; j < names.length; j++) {
				var value = settings[names[j] + list[i]];

				if (value !== undefined && value !== '') {
					return value;
				}
			}
		}

		return '';
	}

	function reveal(element) {
		var settings;

		try {
			settings = JSON.parse(element.getAttribute('data-settings') || '{}');
		} catch (error) {
			element.classList.remove('elementor-invisible');
			return;
		}

		var animation = pick(settings, ['_animation', 'animation']);
		var delay = parseInt(pick(settings, ['_animation_delay', 'animation_delay']), 10) || 0;

		if (!animation || animation === 'none') {
			element.classList.remove('elementor-invisible');
			return;
		}

		var show = function () {
			element.classList.remove('elementor-invisible');
			element.classList.add('animated', animation);
		};

		timers.push([window.setTimeout(show, delay), show]);

		// O Elementor não anima de novo quando os scripts rodarem.
		Object.keys(settings).forEach(function (key) {
			if (/^_?animation(_delay)?(_mobile|_tablet)?$/.test(key)) {
				delete settings[key];
			}
		});

		element.setAttribute('data-settings', JSON.stringify(settings));
	}

	function run() {
		var height = window.innerHeight || document.documentElement.clientHeight;

		Array.prototype.forEach.call(document.querySelectorAll('.elementor-invisible[data-settings]'), function (element) {
			var box = element.getBoundingClientRect();

			if (box.bottom >= 0 && box.top <= height) {
				reveal(element);
			}
		});
	}

	// Os scripts atrasados começaram: mostra já o que ainda esperava o atraso da animação
	// (as configurações de animação desses elementos já foram removidas, o Elementor não os mostraria).
	window.addEventListener('o2r-delay-start', function () {
		timers.forEach(function (timer) {
			window.clearTimeout(timer[0]);
			timer[1]();
		});

		timers = [];
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			window.requestAnimationFrame(run);
		});
	} else {
		window.requestAnimationFrame(run);
	}
})(window, document);
