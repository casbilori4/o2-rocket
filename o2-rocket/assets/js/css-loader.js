/* O² Rocket — carrega o CSS completo (rede de segurança da remoção de CSS não utilizado) na primeira interação. */
(function (window, document) {
	'use strict';

	var TRIGGERS = ['keydown', 'mousedown', 'mousemove', 'touchmove', 'touchstart', 'touchend', 'wheel'];
	var loaded = false;

	function load() {
		if (loaded) {
			return;
		}

		loaded = true;

		TRIGGERS.forEach(function (type) {
			window.removeEventListener(type, load, { passive: true });
		});

		var template = document.getElementById('o2r-full-css');

		if (template && template.content) {
			document.head.appendChild(document.importNode(template.content, true));
		}
	}

	TRIGGERS.forEach(function (type) {
		window.addEventListener(type, load, { passive: true });
	});

	window.addEventListener('o2r-delay-start', load);
})(window, document);
