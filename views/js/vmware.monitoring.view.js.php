<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring = new class {
	init(config) {
		this.hostid = String(config.hostid || '');
		this.panel = document.getElementById('vmware-monitoring-panel');
		this.activeTab = String(config.tab || 'overview');
		this.state = new Map();
		this.searchTimer = null;
		this.request = null;

		this.bindTabs();
		this.bindFilters();
		this.bindPanel();
		this.bindProblemEvents();
		Object.assign(this.getState(this.activeTab), {
			page: Math.max(1, Number(config.page || 1)),
			search: String(config.search || '').trim(),
			sort: String(config.sort || 'name'),
			sortorder: config.sortorder === 'DESC' ? 'DESC' : 'ASC'
		});
		window.addEventListener('popstate', () => this.restoreUrlState());
		this.activateTab(this.activeTab, 'replace');
	}

	bindTabs() {
		document.querySelectorAll('[data-vmware-monitoring-tab]').forEach(tab => {
			const activate = () => this.activateTab(tab.dataset.vmwareMonitoringTab, 'push');
			tab.addEventListener('click', activate);
			tab.addEventListener('keydown', event => {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					activate();
				}
			});
		});
	}

	activateTab(key, historyMode = null) {
		this.activeTab = key;
		document.querySelectorAll('[data-vmware-monitoring-tab]').forEach(tab => {
			const active = tab.dataset.vmwareMonitoringTab === key;
			tab.classList.toggle('vmware-monitoring-tab-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		if (historyMode !== null) {
			this.updateUrl(historyMode);
		}
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
			this.updateUrl('push');
			this.loadTab(this.activeTab);
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
				this.updateUrl('push');
				this.loadTab(this.activeTab);
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
			if (this.activeTab === 'overview') {
				this.loadTab('overview');
			}
		});
		$.subscribe('event.rank_change', () => {
			if (this.activeTab === 'overview') {
				this.loadTab('overview');
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
		this.updateUrl('push');
		this.loadTab(this.activeTab);
	}

	updateUrl(mode) {
		const state = this.getState(this.activeTab);
		const url = new URL(window.location.href);
		url.searchParams.set('tab', this.activeTab);
		this.setUrlArgument(url, 'page', state.page > 1 ? state.page : '');
		this.setUrlArgument(url, 'search', state.search);
		this.setUrlArgument(url, 'sort', state.sort !== 'name' ? state.sort : '');
		this.setUrlArgument(url, 'sortorder', state.sortorder !== 'ASC' ? state.sortorder : '');
		window.history[mode === 'replace' ? 'replaceState' : 'pushState']({}, '', url);
	}

	setUrlArgument(url, name, value) {
		if (value === '') {
			url.searchParams.delete(name);
		}
		else {
			url.searchParams.set(name, String(value));
		}
	}

	restoreUrlState() {
		const params = new URL(window.location.href).searchParams;
		const tab = params.get('tab') || 'overview';
		const element = document.querySelector(`[data-vmware-monitoring-tab="${CSS.escape(tab)}"]`);
		if (element === null) {
			return;
		}

		Object.assign(this.getState(tab), {
			page: Math.max(1, Number(params.get('page') || 1)),
			search: String(params.get('search') || '').trim(),
			sort: String(params.get('sort') || 'name'),
			sortorder: params.get('sortorder') === 'DESC' ? 'DESC' : 'ASC'
		});
		this.activateTab(tab);
	}

	async loadTab(tab) {
		const state = this.getState(tab);
		this.request?.abort();
		const request = new AbortController();
		this.request = request;
		this.panel.replaceChildren(Object.assign(document.createElement('div'), {
			className: 'vmware-monitoring-loading',
			textContent: <?= json_encode(_('Loading...')) ?>
		}));
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
			const response = await fetch(url.getUrl(), {cache: 'no-store', signal: request.signal});
			if (!response.ok) {
				throw new Error(`HTTP ${response.status}`);
			}
			const payload = await response.json();
			if ('error' in payload) {
				throw new Error(payload.error.title || '');
			}
			this.render(tab, payload.html || '');
		}
		catch (error) {
			if (error.name === 'AbortError') {
				return;
			}
			if (this.activeTab === tab) {
				this.panel.replaceChildren(Object.assign(document.createElement('div'), {
					className: 'msg-bad',
					textContent: <?= json_encode(_('Unable to load VMware data for this tab.')) ?>
				}));
			}
		}
		finally {
			if (this.request === request) {
				this.request = null;
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
		svg.classList.add('vmware-monitoring-spark-svg');

		if (history.length === 0) {
			target.setAttribute('aria-label', <?= json_encode(_('No CPU history data')) ?>);
			const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			const y = height - pad;
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
			const max = Math.max(...values);
			const current = values[values.length - 1];
			const label = [
				`${<?= json_encode(_('Minimum')) ?>}: ${min.toFixed(1)}%`,
				`${<?= json_encode(_('Current')) ?>}: ${current.toFixed(1)}%`,
				`${<?= json_encode(_('Maximum')) ?>}: ${max.toFixed(1)}%`
			].join(', ');
			target.setAttribute('aria-label', label);
			const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
			title.textContent = label;
			svg.append(title);

			const coordinates = values.map((value, index) => {
				const x = values.length > 1 ? index / (values.length - 1) * width : width;
				const bounded = Math.max(0, Math.min(100, value));
				const y = height - pad - bounded / 100 * (height - 2 * pad);
				return `${x.toFixed(1)},${y.toFixed(1)}`;
			});

			if (values.length === 1) {
				const point = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
				const [x, y] = coordinates[0].split(',');
				point.setAttribute('cx', x);
				point.setAttribute('cy', y);
				point.setAttribute('r', '2');
				point.classList.add('vmware-monitoring-spark-point');
				svg.append(point);
			}
			else {
				const fill = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
				fill.setAttribute('points',
					`0,${height - pad} ${coordinates.join(' ')} ${width},${height - pad}`);
				fill.classList.add('vmware-monitoring-spark-fill');
				svg.append(fill);

				const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
				line.setAttribute('points', coordinates.join(' '));
				line.setAttribute('fill', 'none');
				line.classList.add('vmware-monitoring-spark-line');
				svg.append(line);
			}
		}

		target.replaceChildren(svg);
	}
};
</script>
