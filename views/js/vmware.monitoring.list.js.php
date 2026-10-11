<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring_list = new class {
	init(config) {
		this.refresh = Number(config.refresh || 0);
		this.activeTab = String(config.tab || 'vcenters');
		this.content = document.getElementById('vmware-monitoring-tab-content');
		this.summary = document.getElementById('vmware-monitoring-list-summary');
		this.tabs = [...document.querySelectorAll('[data-vmware-monitoring-list-tab]')];
		this.tabContent = new Map();
		this.tabRequests = new Map();
		this.locationStates = new Map();
		this.locationSaveQueue = Promise.resolve();
		this.tabActivation = 0;
		this.locationStorageKey = 'vmware.monitoring.location.pending.'
			+ <?= json_encode((string) CWebUser::$data['userid']) ?>;
		this.pendingLocationStates = new Map();
		try {
			for (const entry of JSON.parse(localStorage.getItem(this.locationStorageKey) || '[]')) {
				if (/^[a-f0-9]{64}$/.test(entry.key) && typeof entry.collapsed === 'boolean') {
					this.pendingLocationStates.set(entry.key, entry);
					this.locationStates.set(entry.key, entry.collapsed);
				}
			}
		}
		catch (error) {
			// The profile still works if browser storage is unavailable.
		}
		if (this.content !== null && this.summary !== null) {
			this.tabContent.set(this.tabCacheKey(window.location.href), {
				content: this.content.innerHTML,
				summary: this.summary.innerHTML
			});
			this.content.addEventListener('contextmenu', event => this.openHostMenu(event));
			this.content.addEventListener('click', event => this.toggleLocation(event));
			this.applyLocationStates();
		}
		for (const entry of this.pendingLocationStates.values()) {
			this.saveLocationState(entry);
		}
		this.bindTabs();
		this.bindRefresh();
		if (this.refresh > 0) {
			PageRefresh.init(this.refresh * 1000);
		}

		const status = document.getElementById('vmware-monitoring-refresh-status');
		if (status && this.refresh > 0) {
			status.textContent = <?= json_encode(_('Auto-refresh enabled')) ?>;
		}

		this.preloadRemainingTabs();
	}

	applyLocationStates() {
		this.content?.querySelectorAll('[data-vmware-location-toggle]').forEach(button => {
			const key = button.dataset.vmwareLocationToggle;
			if (!this.locationStates.has(key)) {
				this.locationStates.set(key, button.getAttribute('aria-expanded') === 'false');
			}
			button.setAttribute('aria-expanded', this.locationStates.get(key) ? 'false' : 'true');
		});
		this.content?.querySelectorAll('[data-vmware-location-ancestors]').forEach(row => {
			row.hidden = JSON.parse(row.dataset.vmwareLocationAncestors)
				.some(key => this.locationStates.get(key) === true);
		});
	}

	toggleLocation(event) {
		const button = event.target.closest('[data-vmware-location-toggle]');
		if (!button || !this.content.contains(button)) {
			return;
		}
		const key = button.dataset.vmwareLocationToggle;
		const collapsed = !this.locationStates.get(key);
		this.locationStates.set(key, collapsed);
		this.applyLocationStates();
		const entry = {key, collapsed};
		this.pendingLocationStates.set(key, entry);
		this.storePendingLocationStates();
		this.saveLocationState(entry);
	}

	storePendingLocationStates() {
		try {
			localStorage.setItem(this.locationStorageKey, JSON.stringify([...this.pendingLocationStates.values()]));
		}
		catch (error) {
			// Keep the in-memory queue when browser storage is unavailable.
		}
	}

	saveLocationState(entry) {
		const {key, collapsed} = entry;
		// Serialize writes so rapid toggles cannot persist an older state last.
		// Pending writes are stored per user and replayed after navigation or reload.
		this.locationSaveQueue = this.locationSaveQueue.then(async () => {
			const response = await fetch('zabbix.php?action=vmware.monitoring.location.state', {
				method: 'POST',
				keepalive: true,
				body: new URLSearchParams({
					key, collapsed: collapsed ? '1' : '0',
					<?= json_encode(CSRF_TOKEN_NAME) ?>: <?= json_encode(CCsrfTokenHelper::get('vmware.monitoring.location.state')) ?>
				})
			});
			const result = await response.json();
			if (!response.ok || result.success !== true) {
				throw new Error('Location state was not saved.');
			}
			if (this.pendingLocationStates.get(key) === entry) {
				this.pendingLocationStates.delete(key);
				this.storePendingLocationStates();
			}
			if (this.pendingLocationStates.size === 0) {
				const status = document.getElementById('vmware-monitoring-location-save-status');
				if (status) {
					status.textContent = '';
				}
			}
		}).catch(() => {
			const status = document.getElementById('vmware-monitoring-location-save-status');
			if (status) {
				status.textContent = <?= json_encode(_('Unable to save location groups. Your changes may be lost on reload.')) ?>;
			}
		});
	}

	bindRefresh() {
		document.getElementById('vmware-monitoring-refresh-vcenters')?.addEventListener('submit', () => {
			this.tabContent.clear();
			this.tabRequests.clear();
		});
	}

	bindTabs() {
		this.tabs.forEach(tab => tab.addEventListener('click', event => {
			if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
				return;
			}
			event.preventDefault();
			this.activateTab(tab.dataset.vmwareMonitoringListTab, 'push');
		}));

		window.addEventListener('popstate', () => {
			const key = new URL(window.location.href).searchParams.get('tab') || 'vcenters';
			this.activateTab(key);
		});
	}

	async activateTab(key, historyMode = null) {
		const tab = this.tabs.find(candidate => candidate.dataset.vmwareMonitoringListTab === key);
		if (tab === undefined || this.content === null || this.summary === null) {
			return;
		}

		this.activeTab = key;
		const activation = ++this.tabActivation;
		this.tabs.forEach(candidate => {
			const active = candidate === tab;
			candidate.classList.toggle('vmware-monitoring-tab-active', active);
			candidate.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		if (historyMode === 'push') {
			window.history.pushState({}, '', tab.href);
		}

		try {
			const tab_data = await this.fetchTab(key, historyMode === null ? window.location.href : tab.href);
			if (this.activeTab !== key || this.tabActivation !== activation) {
				return;
			}
			this.content.innerHTML = tab_data.content;
			this.summary.innerHTML = tab_data.summary;
			this.applyLocationStates();
			document.dispatchEvent(new CustomEvent('zbx_reload', {
				detail: {source: 'vmware-monitoring-list', tab: key}
			}));
			this.setRefreshStatus();
		}
		catch (error) {
			if (this.activeTab === key && this.tabActivation === activation) {
				this.content.replaceChildren(Object.assign(document.createElement('div'), {
					className: 'msg-bad',
					textContent: <?= json_encode(_('Unable to load VMware data for this tab.')) ?>
				}));
			}
		}
	}

	async preloadRemainingTabs() {
		const active_index = this.tabs.findIndex(tab => tab.dataset.vmwareMonitoringListTab === this.activeTab);
		const ordered_tabs = [
			...this.tabs.slice(active_index + 1),
			...this.tabs.slice(0, Math.max(0, active_index))
		];
		for (const tab of ordered_tabs) {
			try {
				await this.fetchTab(tab.dataset.vmwareMonitoringListTab, tab.href);
			}
			catch (error) {
				// A background request must not affect the current tab.
			}
		}
	}

	tabCacheKey(url) {
		const target = new URL(url, window.location.href);
		target.hash = '';
		target.searchParams.sort();
		return target.href;
	}

	fetchTab(key, url) {
		const cacheKey = this.tabCacheKey(url);
		if (this.tabContent.has(cacheKey)) {
			return Promise.resolve(this.tabContent.get(cacheKey));
		}
		if (this.tabRequests.has(cacheKey)) {
			return this.tabRequests.get(cacheKey);
		}

		const request = fetch(url, {cache: 'no-store'})
			.then(response => {
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}`);
				}
				return response.text();
			})
			.then(html => {
				const document_fragment = new DOMParser().parseFromString(html, 'text/html');
				const content = document_fragment.getElementById('vmware-monitoring-tab-content');
				const summary = document_fragment.getElementById('vmware-monitoring-list-summary');
				if (content === null || summary === null) {
					throw new Error('Tab content was not returned.');
				}
				const tab_data = {content: content.innerHTML, summary: summary.innerHTML};
				this.tabContent.set(cacheKey, tab_data);
				return tab_data;
			})
			.finally(() => this.tabRequests.delete(cacheKey));
		this.tabRequests.set(cacheKey, request);
		return request;
	}

	setRefreshStatus() {
		const status = document.getElementById('vmware-monitoring-refresh-status');
		if (status && this.refresh > 0) {
			status.textContent = <?= json_encode(_('Auto-refresh enabled')) ?>;
		}
	}

	openHostMenu(event) {
		const link = event.target.closest('[data-vmware-monitoring-host-menu]');
		if (link === null || this.content === null || !this.content.contains(link)) {
			return;
		}

		event.preventDefault();
		link.setAttribute('data-menu-popup', JSON.stringify({
			type: 'host',
			data: {hostid: link.dataset.vmwareMonitoringHostMenu}
		}));
		jQuery(link).removeData('menu-popup');
		link.dispatchEvent(new MouseEvent('click', {
			bubbles: true,
			cancelable: true,
			view: window,
			detail: 1,
			screenX: event.screenX,
			screenY: event.screenY,
			clientX: event.clientX,
			clientY: event.clientY
		}));
		link.removeAttribute('data-menu-popup');
		jQuery(link).removeData('menu-popup');
	}
};
</script>
