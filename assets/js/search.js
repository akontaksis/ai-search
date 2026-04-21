/* AI Search — Frontend JS (vanilla, no jQuery) */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', init);

	function init() {
		const wrapper   = document.querySelector('.ais-wrapper');
		if (!wrapper) return;

		const form      = wrapper.querySelector('.ais-form');
		const input     = wrapper.querySelector('.ais-input');
		const btn       = wrapper.querySelector('.ais-btn');
		const loading   = wrapper.querySelector('.ais-loading');
		const errorBox  = wrapper.querySelector('.ais-error');
		const results   = wrapper.querySelector('.ais-results');
		const noResults = wrapper.querySelector('.ais-no-results');

		if (input && aisData.placeholder) {
			input.placeholder = aisData.placeholder;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const query = input.value.trim();
			if (!query) return;
			doSearch(query);
		});

		function doSearch(query) {
			reset();
			setLoading(true);

			const body = new FormData();
			body.append('action', 'ais_search');
			body.append('nonce', aisData.nonce);
			body.append('query', query);

			fetch(aisData.ajaxUrl, { method: 'POST', body })
				.then(function (r) {
					if (!r.ok) throw new Error('HTTP ' + r.status);
					return r.json();
				})
				.then(function (response) {
					setLoading(false);

					if (!response.success) {
						showError(response.data || 'Σφάλμα αναζήτησης.');
						return;
					}

					const items = response.data;

					if (!items || items.length === 0) {
						noResults.classList.add('visible');
						return;
					}

					items.forEach(function (item) {
						results.appendChild(buildCard(item));
					});
				})
				.catch(function () {
					setLoading(false);
					showError('Σφάλμα σύνδεσης. Δοκιμάστε ξανά.');
				});
		}

		function reset() {
			results.innerHTML = '';
			errorBox.classList.remove('visible');
			noResults.classList.remove('visible');
		}

		function setLoading(on) {
			btn.disabled = on;
			loading.classList.toggle('visible', on);
		}

		function showError(msg) {
			errorBox.textContent = msg;
			errorBox.classList.add('visible');
		}

		function buildCard(item) {
			const card = document.createElement('div');
			card.className = 'ais-card';

			const categoryHtml = item.category
				? '<span class="ais-category">' + esc(item.category) + '</span>'
				: '';

			const descHtml = item.description
				? '<p>' + esc(item.description) + '</p>'
				: '';

			card.innerHTML =
				categoryHtml +
				'<h3>' + esc(item.title) + '</h3>' +
				descHtml +
				'<a class="ais-card-link" href="' + safeUrl(item.url) + '">Μεταβείτε →</a>';

			return card;
		}

		function esc(str) {
			var d = document.createElement('div');
			d.appendChild(document.createTextNode(String(str || '')));
			return d.innerHTML;
		}

		function safeUrl(url) {
			try {
				var u = new URL(url);
				if (u.protocol === 'https:' || u.protocol === 'http:') return u.href;
			} catch (_) {}
			return '#';
		}
	}
})();
