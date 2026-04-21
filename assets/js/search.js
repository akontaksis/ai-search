/* AI Search — Frontend JS v1.1 */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', init);

	// Example queries shown as chips — overridable via aisData.examples
	var DEFAULT_EXAMPLES = [
		'Πώς μπορώ να υποβάλω αίτηση;',
		'Ποιες υπηρεσίες παρέχονται;',
		'Πού απευθύνομαι για πληροφορίες;',
	];

	function init() {
		var wrapper = document.querySelector('.ais-wrapper');
		if (!wrapper) return;

		if (typeof aisData === 'undefined') {
			console.error('AI Search: aisData not defined.');
			return;
		}

		var form      = wrapper.querySelector('.ais-form');
		var input     = wrapper.querySelector('.ais-input');
		var btn       = wrapper.querySelector('.ais-btn');
		var loading   = wrapper.querySelector('.ais-loading');
		var errorBox  = wrapper.querySelector('.ais-error');
		var results   = wrapper.querySelector('.ais-results');
		var noResults = wrapper.querySelector('.ais-no-results');
		var examples  = wrapper.querySelector('.ais-examples');

		if (aisData.placeholder) {
			input.placeholder = aisData.placeholder;
		}

		// Animated placeholder cycling
		var placeholders = aisData.placeholders || [];
		if (placeholders.length > 1) {
			var pi = 0;
			setInterval(function () {
				if (document.activeElement !== input) {
					pi = (pi + 1) % placeholders.length;
					input.placeholder = placeholders[pi];
				}
			}, 3000);
		}

		// Example chips
		var chips = aisData.examples || DEFAULT_EXAMPLES;
		if (examples && chips.length) {
			chips.forEach(function (text) {
				var chip = document.createElement('button');
				chip.type = 'button';
				chip.className = 'ais-chip';
				chip.innerHTML =
					'<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L9.09 9.09 2 12l7.09 2.91L12 22l2.91-7.09L22 12l-7.09-2.91z"/></svg>' +
					esc(text);
				chip.addEventListener('click', function () {
					input.value = text;
					input.focus();
					doSearch(text);
				});
				examples.appendChild(chip);
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var query = input.value.trim();
			if (!query) return;
			doSearch(query);
		});

		function doSearch(query) {
			reset();
			setLoading(true);

			var body = new FormData();
			body.append('action', 'ais_search');
			body.append('nonce', aisData.nonce);
			body.append('query', query);

			fetch(aisData.ajaxUrl, { method: 'POST', body: body })
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

					var items = response.data;

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
			var card = document.createElement('div');
			card.className = 'ais-card';

			// Top row: category + service type
			var top = document.createElement('div');
			top.className = 'ais-card-top';

			if (item.category) {
				var cat = document.createElement('span');
				cat.className = 'ais-category';
				cat.textContent = item.category;
				top.appendChild(cat);
			} else {
				top.appendChild(document.createElement('span'));
			}

			if (item.service_type) {
				var typeMap = { info: 'Πληροφορία', action: 'Αίτηση', contact: 'Επικοινωνία', payment: 'Πληρωμή' };
				var st = document.createElement('span');
				st.className = 'ais-service-type';
				st.textContent = typeMap[item.service_type] || item.service_type;
				top.appendChild(st);
			}

			card.appendChild(top);

			// Title
			var h3 = document.createElement('h3');
			h3.textContent = item.title;
			card.appendChild(h3);

			// Description
			if (item.description) {
				var p = document.createElement('p');
				p.textContent = item.description;
				card.appendChild(p);
			}

			// Link
			var a = document.createElement('a');
			a.className = 'ais-card-link';
			a.href = safeUrl(item.url);
			a.innerHTML =
				'Μεταβείτε' +
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
			card.appendChild(a);

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
