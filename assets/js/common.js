/* Generated from zabbix-module-common. Each module owns an isolated runtime. */
(() => {
	class SearchInput {
		constructor(root, {selector, onChange, onCancel = () => {}, delay = 250,
				formSelector = null, clearSelector = '[data-module-search-clear]'}) {
			this.delay = delay;
			const inputFor = target => target?.matches?.(selector)
				&& (!formSelector || target.closest(formSelector)) ? target : null;
			const change = (input, immediate = false) => { if (input) onChange(input, immediate); };
			const clear = input => {
				input.value = '';
				input.focus();
				change(input, true);
			};
			root.addEventListener('input', event => {
				if (!event.isComposing) change(inputFor(event.target));
			});
			root.addEventListener('compositionstart', event => {
				if (inputFor(event.target)) { this.cancel(); onCancel(); }
			});
			root.addEventListener('compositionend', event => change(inputFor(event.target)));
			root.addEventListener('keydown', event => {
				const input = inputFor(event.target);
				if (!input || event.isComposing) return;
				if (event.key === 'Escape' && input.value) {
					event.preventDefault();
					clear(input);
				}
				else if (event.key === 'Enter' && !formSelector) {
					event.preventDefault();
					change(input, true);
				}
			});
			if (clearSelector) root.addEventListener('click', event => {
				const button = event.target.closest?.(clearSelector);
				const scope = button?.closest('[data-module-search]')
					|| (formSelector ? button?.closest(formSelector) : null);
				const input = scope?.querySelector(selector);
				if (inputFor(input)) { event.preventDefault(); clear(input); }
			});
			if (formSelector) root.addEventListener('submit', event => {
				if (!event.target.matches(formSelector)) return;
				const input = event.target.querySelector(selector);
				if (input) { event.preventDefault(); change(input, true); }
			});
		}

		queue(callback, immediate = false, delay = this.delay) {
			this.cancel();
			this.timer = setTimeout(callback, immediate ? 0 : delay);
		}

		cancel() {
			clearTimeout(this.timer);
			this.timer = null;
		}
	}

	const locationData = 'vmware-location'.replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase());
	const LocationGroups = {
		apply(scope, states) {
			const isMap = typeof states.get === 'function';
			scope?.querySelectorAll('[data-vmware-location-toggle]').forEach(button => {
				const key = button.dataset[`${locationData}Toggle`];
				if (isMap && !states.has(key)) states.set(key, button.getAttribute('aria-expanded') === 'false');
				const collapsed = isMap ? states.get(key) === true : states.has(key);
				button.setAttribute('aria-expanded', String(!collapsed));
				button.classList?.toggle('is-collapsed', collapsed);
				const icon = button.querySelector?.('[data-location-icon]');
				if (icon) icon.textContent = collapsed ? '\u25b6' : '\u25bc';
			});
			scope?.querySelectorAll('[data-vmware-location-ancestors]').forEach(row => {
				let keys = [];
				try { keys = JSON.parse(row.dataset[`${locationData}Ancestors`] || '[]'); }
				catch (_) { /* Malformed markup cannot hide unrelated rows. */ }
				row.hidden = Array.isArray(keys) && keys.some(key => isMap ? states.get(key) === true : states.has(key));
			});
		}
	};

	window.VMwareCommon = {SearchInput, LocationGroups};
})();
