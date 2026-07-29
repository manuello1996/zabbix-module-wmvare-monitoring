<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring = new class {
	init(config) {
		this.hostid = String(config.hostid || '');
		this.panel = document.getElementById('vmware-monitoring-panel');
		this.activeTab = 'overview';
		this.cache = new Map();
		this.state = new Map();
		this.searchTimer = null;

		this.bindTabs();
		this.bindFilters();
		this.bindPanel();
		this.loadTab('overview');
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
	}

	getState(tab) {
		if (!this.state.has(tab)) {
			this.state.set(tab, {page: 1, search: ''});
		}
		return this.state.get(tab);
	}

	async loadTab(tab, force = false) {
		const state = this.getState(tab);
		const cacheKey = `${tab}|${state.page}|${state.search}`;
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
		if (!Array.isArray(points) || points.length < 2) {
			return;
		}
		const values = points.map(point => Number(point[1])).filter(Number.isFinite);
		if (values.length < 2) {
			return;
		}

		const width = 100;
		const height = 28;
		const min = Math.min(...values);
		const range = Math.max(...values) - min || 1;
		const path = values.map((value, index) => {
			const x = index / (values.length - 1) * width;
			const y = height - 2 - ((value - min) / range) * (height - 4);
			return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
		}).join(' ');

		target.innerHTML = `<svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none"
			aria-hidden="true"><path d="${path}" fill="none" stroke="currentColor"
			stroke-width="2" vector-effect="non-scaling-stroke"/></svg>`;
	}
};
</script>
