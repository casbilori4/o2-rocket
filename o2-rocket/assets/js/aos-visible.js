/* O² Rocket — com o JavaScript atrasado, marca como já animados os elementos do AOS visíveis na primeira tela: quando o AOS iniciar, eles continuam como estão (sem sumir e reaparecer). */
(function (window, document) {
	'use strict';

	function markVisible() {
		var height = window.innerHeight || document.documentElement.clientHeight;

		Array.prototype.forEach.call(document.querySelectorAll('[data-aos]:not(.aos-init)'), function (element) {
			var box = element.getBoundingClientRect();

			if (box.bottom > 0 && box.top < height) {
				element.classList.add('aos-animate');
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', markVisible);
	} else {
		markVisible();
	}
})(window, document);
