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
		this.tabCache = new Map();
		this.sparklineCache = new Map();
		this.cacheTtl = 10 * 60 * 1000;

		this.bindTabs();
		this.bindFilters();
		this.bindPanel();
		this.bindProblemEvents();
		this.bindRefresh();
		Object.assign(this.getState(this.activeTab), {
			page: Math.max(1, Number(config.page || 1)),
			search: String(config.search || '').trim(),
			sort: String(config.sort || 'name'),
			sortorder: config.sortorder === 'DESC' ? 'DESC' : 'ASC'
		});
		window.addEventListener('popstate', () => this.restoreUrlState());
		this.activateTab(this.activeTab, 'replace');
	}

	bindRefresh() {
		document.getElementById('vmware-monitoring-refresh-vcenter')?.addEventListener('submit', () => {
			this.tabCache.clear();
			this.sparklineCache.clear();
		});
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

	async toggleDatastore(toggle) {
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
		if (!expanded || details.dataset.vmwareMonitoringDatastoreLoaded === '1'
				|| details.dataset.vmwareMonitoringDatastoreLoading === '1') {
			return;
		}

		const content = document.getElementById(details.dataset.vmwareMonitoringDatastoreContent);
		const url = details.dataset.vmwareMonitoringDatastoreUrl;
		if (content === null || !url) {
			return;
		}
		details.dataset.vmwareMonitoringDatastoreLoading = '1';
		content.textContent = <?= json_encode(_('Loading attachment metrics...')) ?>;
		try {
			const response = await fetch(url, {cache: 'no-store'});
			if (!response.ok) {
				throw new Error(`HTTP ${response.status}`);
			}
			const payload = await response.json();
			if ('error' in payload) {
				throw new Error(payload.error.title || '');
			}
			content.innerHTML = payload.html || '';
			details.dataset.vmwareMonitoringDatastoreLoaded = '1';
		}
		catch (error) {
			content.textContent = <?= json_encode(_('Unable to load attachment metrics.')) ?>;
		}
		finally {
			delete details.dataset.vmwareMonitoringDatastoreLoading;
		}
	}

	bindProblemEvents() {
		if (typeof $ === 'undefined' || typeof $.subscribe !== 'function') {
			return;
		}

		$.subscribe('acknowledge.create', (event, response) => {
			clearMessages();
			addMessage(makeMessageBox('good', [], response.success.title));
			if (this.activeTab === 'overview') {
				this.loadTab('overview', true);
			}
		});
		$.subscribe('event.rank_change', () => {
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

	cacheKey(tab, state) {
		return `${tab}:${state.page}:${state.search}:${state.sort}:${state.sortorder}`;
	}

	async loadTab(tab, force = false) {
		const state = this.getState(tab);
		const cacheKey = this.cacheKey(tab, state);
		const cached = this.tabCache.get(cacheKey);
		if (!force && cached && cached.createdAt + this.cacheTtl > Date.now()) {
			this.updateStats(cached.stats);
			this.render(tab, cached.html);
			return;
		}
		this.tabCache.delete(cacheKey);
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
			const html = payload.html || '';
			this.tabCache.set(cacheKey, {html, stats: payload.stats, createdAt: Date.now()});
			this.updateStats(payload.stats);
			this.render(tab, html);
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

	updateStats(stats) {
		if (!stats) {
			return;
		}
		const values = {
			'hypervisors': stats.hypervisors,
			'discovered-vms': stats.discovered_vms,
			'total-vms': stats.total_vms
		};
		Object.entries(values).forEach(([key, value]) => {
			const element = document.getElementById(`vmware-monitoring-stat-${key}`);
			if (element !== null) {
				element.textContent = value == null ? '-' : String(value);
			}
		});
	}

	async loadSparklines(root) {
		const targets = [...root.querySelectorAll('[data-vmware-monitoring-spark-itemid]')];
		const uncached = [];
		targets.forEach(target => {
			const itemid = target.dataset.vmwareMonitoringSparkItemid;
			const cached = this.sparklineCache.get(itemid);
			if (cached && cached.createdAt + this.cacheTtl > Date.now()) {
				this.drawSparkline(target, cached.history);
			}
			else {
				this.sparklineCache.delete(itemid);
				uncached.push(target);
			}
		});

		for (let offset = 0; offset < uncached.length; offset += 40) {
			const batch = uncached.slice(offset, offset + 40);
			const itemids = [...new Set(batch.map(target => target.dataset.vmwareMonitoringSparkItemid))];
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'vmware.monitoring.sparkline');
			url.setArgument('itemids', itemids);

			try {
				const response = await fetch(url.getUrl(), {cache: 'no-store'});
				const payload = await response.json();
				const histories = payload.history || {};
				itemids.forEach(itemid => this.sparklineCache.set(itemid, {
					history: histories[itemid] || [], createdAt: Date.now()
				}));
				batch.forEach(target => {
					this.drawSparkline(target,
						this.sparklineCache.get(target.dataset.vmwareMonitoringSparkItemid).history);
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
