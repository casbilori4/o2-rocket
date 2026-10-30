/* O² Rocket — lazy load de imagens e iframes (IntersectionObserver, sem dependências). */
(function () {
	'use strict';

	var CLASS_NAME = 'o2-lazyload';
	var SELECTOR = '.' + CLASS_NAME;

	function swap(el, name) {
		var value = el.getAttribute('data-' + name);

		if (value !== null) {
			el.setAttribute(name, value);
			el.removeAttribute('data-' + name);
		}
	}

	// srcset antes de src: o navegador escolhe o candidato certo sem baixar o src à toa.
	function load(el) {
		swap(el, 'srcset');
		swap(el, 'src');
		el.classList.remove(CLASS_NAME);
	}

	if (!('IntersectionObserver' in window)) {
		Array.prototype.forEach.call(document.querySelectorAll(SELECTOR), load);
		return;
	}

	var observer = new IntersectionObserver(function (entries) {
		for (var i = 0; i < entries.length; i++) {
			if (entries[i].isIntersecting) {
				observer.unobserve(entries[i].target);
				load(entries[i].target);
			}
		}
	}, { rootMargin: '200px 0px' });

	function observe(root) {
		if (root.classList && root.classList.contains(CLASS_NAME)) {
			observer.observe(root);
		}

		if (root.querySelectorAll) {
			var nodes = root.querySelectorAll(SELECTOR);

			for (var i = 0; i < nodes.length; i++) {
				observer.observe(nodes[i]);
			}
		}
	}

	observe(document);

	// Conteúdo inserido depois (AJAX, clones de carrossel) também é observado.
	if ('MutationObserver' in window) {
		new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				for (var j = 0; j < mutations[i].addedNodes.length; j++) {
					if (mutations[i].addedNodes[j].nodeType === 1) {
						observe(mutations[i].addedNodes[j]);
					}
				}
			}
		}).observe(document.documentElement, { childList: true, subtree: true });
	}
})();
