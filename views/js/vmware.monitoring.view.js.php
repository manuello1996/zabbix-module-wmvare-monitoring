<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring = new class {
	init(config) {
		this.hostid = String(config.hostid || '');
		this.panel = document.getElementById('vmware-monitoring-panel');
		this.activeTab = String(config.tab || 'overview');
		this.cache = new Map();
		this.state = new Map();
		this.searchTimer = null;

		this.bindTabs();
		this.bindFilters();
		this.bindPanel();
		this.bindProblemEvents();
		if (this.activeTab === 'hypervisors') {
			this.getState('hypervisors').search = String(config.search || '').trim();
		}
		this.activateTab(this.activeTab);
	}

	bindTabs() {
		document.querySelectorAll('[data-vmware-monitoring-tab]').forEach(tab => {
			const activate = () => this.activateTab(tab.dataset.vmwareMonitoringTab);
			tab.addEventListener('click', activate);
			tab.addEventListener('keydown', event => {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					activate();
				}
			});
		});
	}

	activateTab(key) {
		this.activeTab = key;
		document.querySelectorAll('[data-vmware-monitoring-tab]').forEach(tab => {
			const active = tab.dataset.vmwareMonitoringTab === key;
			tab.classList.toggle('vmware-monitoring-tab-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		this.loadTab(key);
	}

	bindFilters() {
		const button = document.getElementById('vmware-monitoring-btn-filter');
		const filters = document.getElementById('vmware-monitoring-filters');
		button?.addEventListener('click', () => {
			filters.hidden = !filters.hidden;
		});
	}

	bindPanel() {
		this.panel.addEventListener('click', event => {
			const datastoreToggle = event.target.closest('[data-vmware-monitoring-datastore-toggle]');
			if (datastoreToggle) {
				const interactive = event.target.closest('a, button, input, select, textarea, [data-hintbox]');
				if (interactive !== null && interactive !== datastoreToggle) {
					return;
				}
				event.preventDefault();
				this.toggleDatastore(datastoreToggle);
				return;
			}

			const sort = event.target.closest('[data-vmware-monitoring-sort]');
			if (sort) {
				this.sortTab(sort.dataset.vmwareMonitoringSort);
				return;
			}

			const button = event.target.closest('[data-vmware-monitoring-page]');
			if (!button) {
				return;
			}
			event.preventDefault();
			const state = this.getState(this.activeTab);
			const page = Number(button.dataset.vmwareMonitoringPage || 1);
			if (page === state.page) {
				return;
			}
			state.page = page;
			this.loadTab(this.activeTab, true);
		});

		this.panel.addEventListener('input', event => {
			if (!event.target.matches('[data-vmware-monitoring-server-search]')) {
				return;
			}
			window.clearTimeout(this.searchTimer);
			this.searchTimer = window.setTimeout(() => {
				const state = this.getState(this.activeTab);
				state.search = event.target.value.trim();
				state.page = 1;
				this.loadTab(this.activeTab, true);
			}, 350);
		});

		this.panel.addEventListener('keydown', event => {
			const datastoreToggle = event.target.closest('[data-vmware-monitoring-datastore-toggle]');
			if (datastoreToggle && event.target === datastoreToggle
					&& (event.key === 'Enter' || event.key === ' ')) {
				event.preventDefault();
				this.toggleDatastore(datastoreToggle);
				return;
			}

			const sort = event.target.closest('[data-vmware-monitoring-sort]');
			if (sort && (event.key === 'Enter' || event.key === ' ')) {
				event.preventDefault();
				this.sortTab(sort.dataset.vmwareMonitoringSort);
			}
		});
	}

	toggleDatastore(toggle) {
		const details = document.getElementById(toggle.dataset.vmwareMonitoringDatastoreToggle);
		if (details === null) {
			return;
		}

		const expanded = toggle.getAttribute('aria-expanded') !== 'true';
		toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		toggle.setAttribute('title', expanded
			? <?= json_encode(_('Hide attachment details')) ?>
			: <?= json_encode(_('Show attachment details')) ?>
		);
		details.hidden = !expanded;
	}

	bindProblemEvents() {
		if (typeof $ === 'undefined' || typeof $.subscribe !== 'function') {
			return;
		}

		$.subscribe('acknowledge.create', (event, response) => {
			clearMessages();
			addMessage(makeMessageBox('good', [], response.success.title));
			this.cache.clear();
			if (this.activeTab === 'overview') {
				this.loadTab('overview', true);
			}
		});
		$.subscribe('event.rank_change', () => {
			this.cache.clear();
			if (this.activeTab === 'overview') {
				this.loadTab('overview', true);
			}
		});
	}

	getState(tab) {
		if (!this.state.has(tab)) {
			this.state.set(tab, {page: 1, search: '', sort: 'name', sortorder: 'ASC'});
		}
		return this.state.get(tab);
	}

	sortTab(field) {
		const state = this.getState(this.activeTab);
		state.sortorder = state.sort === field && state.sortorder === 'ASC' ? 'DESC' : 'ASC';
		state.sort = field;
		state.page = 1;
		this.loadTab(this.activeTab, true);
	}

	async loadTab(tab, force = false) {
		const state = this.getState(tab);
		const cacheKey = `${tab}|${state.page}|${state.search}|${state.sort}|${state.sortorder}`;
		if (!force && this.cache.has(cacheKey)) {
			this.render(tab, this.cache.get(cacheKey));
			return;
		}

		this.panel.innerHTML = `<div class="vmware-monitoring-loading"><?= _('Loading...') ?></div>`;
		const url = new Curl('zabbix.php');
		url.setArgument('action', 'vmware.monitoring.tab');
		url.setArgument('hostid', this.hostid);
		url.setArgument('tab', tab);
		url.setArgument('page', state.page);
		url.setArgument('sort', state.sort);
		url.setArgument('sortorder', state.sortorder);
		if (state.search !== '') {
			url.setArgument('search', state.search);
		}

		try {
			const response = await fetch(url.getUrl(), {cache: 'no-store'});
			const payload = await response.json();
			if ('error' in payload) {
				throw new Error(payload.error.title || '');
			}
			this.cache.set(cacheKey, payload.html || '');
			this.render(tab, payload.html || '');
		}
		catch (error) {
			if (this.activeTab === tab) {
				this.panel.innerHTML =
					`<div class="msg-bad"><?= _('Unable to load VMware data for this tab.') ?></div>`;
			}
		}
	}

	render(tab, html) {
		if (this.activeTab !== tab) {
			return;
		}
		this.panel.innerHTML = html;
		document.dispatchEvent(new CustomEvent('zbx_reload', {
			detail: {source: 'vmware-monitoring', tab}
		}));
		this.loadSparklines(this.panel);
	}

	async loadSparklines(root) {
		const targets = [...root.querySelectorAll('[data-vmware-monitoring-spark-itemid]')];
		for (let offset = 0; offset < targets.length; offset += 40) {
			const batch = targets.slice(offset, offset + 40);
			const itemids = [...new Set(batch.map(target => target.dataset.vmwareMonitoringSparkItemid))];
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'vmware.monitoring.sparkline');
			url.setArgument('itemids', itemids);

			try {
				const response = await fetch(url.getUrl(), {cache: 'no-store'});
				const payload = await response.json();
				const histories = payload.history || {};
				batch.forEach(target => {
					this.drawSparkline(target, histories[target.dataset.vmwareMonitoringSparkItemid] || []);
				});
			}
			catch (error) {
				batch.forEach(target => target.setAttribute('aria-label',
					<?= json_encode(_('History unavailable')) ?>));
			}
		}
	}

	drawSparkline(target, points) {
		const width = 100;
		const height = 24;
		const pad = 3;
		const history = Array.isArray(points)
			? points.filter(point => Number.isFinite(Number(point[1])))
			: [];
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');

		svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
		svg.setAttribute('preserveAspectRatio', 'none');
		svg.setAttribute('aria-hidden', 'true');
		svg.classList.add('vmware-monitoring-spark-svg');

		if (history.length < 2) {
			const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			const y = height - pad - 2;
			line.setAttribute('x1', '0');
			line.setAttribute('y1', String(y));
			line.setAttribute('x2', String(width));
			line.setAttribute('y2', String(y));
			line.classList.add('vmware-monitoring-spark-flat');
			svg.append(line);
		}
		else {
			const values = history.map(point => Number(point[1]));
			const min = Math.min(...values);
			const range = Math.max(...values) - min;
			const coordinates = values.map((value, index) => {
				const x = index / (values.length - 1) * width;
				const y = range > 0
					? height - pad - ((value - min) / range) * (height - 2 * pad)
					: height / 2;
				return `${x.toFixed(1)},${y.toFixed(1)}`;
			});

			const fill = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
			fill.setAttribute('points',
				`0,${height - 1} ${coordinates.join(' ')} ${width},${height - 1}`);
			fill.classList.add('vmware-monitoring-spark-fill');
			svg.append(fill);

			const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
			line.setAttribute('points', coordinates.join(' '));
			line.setAttribute('fill', 'none');
			line.classList.add('vmware-monitoring-spark-line');
			svg.append(line);
		}

		target.replaceChildren(svg);
	}
};
</script>
