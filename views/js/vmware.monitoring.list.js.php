<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring_list = new class {
	init(config) {
		// Polling is an explicit choice for this page, independent of Zabbix's user default.
		this.refresh = 0;
		this.refreshInterval = Math.max(10, Number(config.refresh_interval) || 60);
		this.lastUpdated = '';
		this.activeTab = String(config.tab || 'vcenters');
		this.content = document.getElementById('vmware-monitoring-tab-content');
		this.summary = document.getElementById('vmware-monitoring-list-summary');
		this.tabs = [...document.querySelectorAll('[data-vmware-monitoring-list-tab]')];
		this.tabContent = new Map();
		this.tabRequests = new Map();
		this.filters = {groupids: config.filter_groupids || [], hostids: config.filter_hostids || []};
		this.refreshTimer = null;
		this.hasData = false;
		this.cacheLifetime = 60 * 1000;
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
			this.content.addEventListener('contextmenu', event => this.openHostMenu(event));
			this.content.addEventListener('click', event => {
				this.toggleLocation(event);
				this.navigateContent(event);
			});
		}
		for (const entry of this.pendingLocationStates.values()) {
			this.saveLocationState(entry);
		}
		this.bindTabs();
		this.bindRefresh();
		this.bindAutoRefresh();
		this.activateTab(this.activeTab, {force: Boolean(config.force_refresh)});
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
		this.refreshButton = document.getElementById('vmware-monitoring-refresh-vcenters-button');
		const refresh = event => {
			event.preventDefault();
			if (this.manualRefreshPending) {
				return;
			}
			this.manualRefreshPending = true;
			if (this.refreshButton) {
				this.refreshButton.disabled = true;
			}
			this.tabContent.clear();
			this.activateTab(this.activeTab, {reload: true, force: true}).finally(() => {
				this.manualRefreshPending = false;
				if (this.refreshButton) {
					this.refreshButton.disabled = false;
				}
			});
		};
		// Handle the actual control as well as keyboard/form submissions. Cancelling
		// the click prevents the browser from submitting the form a second time.
		this.refreshButton?.addEventListener('click', refresh);
		document.getElementById('vmware-monitoring-refresh-vcenters')?.addEventListener('submit', refresh);
	}

	bindAutoRefresh() {
		const toggle = document.getElementById('vmware-monitoring-auto-refresh');
		if (!toggle) {
			return;
		}
		toggle.checked = false;
		toggle.addEventListener('change', () => {
			clearTimeout(this.refreshTimer);
			this.refresh = toggle.checked ? this.refreshInterval : 0;
			this.cacheLifetime = Math.min(this.refresh || 60, 60) * 1000;
			if (this.tabRequests.size === 0) {
				this.setRefreshStatus(this.completedStatus());
				this.scheduleRefresh();
			}
		});
	}

	bindTabs() {
		this.tabs.forEach(tab => tab.addEventListener('click', event => {
			if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
				return;
			}
			event.preventDefault();
			this.activateTab(tab.dataset.vmwareMonitoringListTab, {url: tab.href, historyMode: 'push'});
		}));

		window.addEventListener('popstate', () => {
			const key = new URL(window.location.href).searchParams.get('tab') || 'vcenters';
			this.activateTab(key);
		});
	}

	navigateContent(event) {
		if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey
				|| event.shiftKey || event.altKey) {
			return;
		}
		const link = event.target.closest('a[href]');
		if (!link || !this.content.contains(link)) {
			return;
		}
		const url = new URL(link.href, window.location.href);
		if (url.origin !== new URL(window.location.href).origin
				|| url.searchParams.get('action') !== 'vmware.monitoring.list') {
			return;
		}
		event.preventDefault();
		this.activateTab(url.searchParams.get('tab') || 'vcenters', {url: url.href, historyMode: 'push'});
	}

	async activateTab(key, {url = window.location.href, historyMode = null, reload = false, force = false} = {}) {
		const tab = this.tabs.find(candidate => candidate.dataset.vmwareMonitoringListTab === key);
		if (tab === undefined || this.content === null || this.summary === null) {
			return;
		}

		this.activeTab = key;
		const activation = ++this.tabActivation;
		clearTimeout(this.refreshTimer);
		const target = new URL(url, window.location.href);
		['force_vcenter_refresh', 'filter_set', 'filter_rst'].forEach(name => target.searchParams.delete(name));
		const cacheKey = this.tabCacheKey(target.href);
		for (const [pendingKey, pending] of this.tabRequests) {
			if (pendingKey !== cacheKey || reload || force) {
				pending.controller.abort();
				this.tabRequests.delete(pendingKey);
			}
		}
		this.tabs.forEach(candidate => {
			const active = candidate === tab;
			candidate.classList.toggle('vmware-monitoring-tab-active', active);
			candidate.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		if (historyMode === 'push') {
			window.history.pushState({}, '', target.href);
		}
		else if (target.href !== window.location.href) {
			window.history.replaceState({}, '', target.href);
		}
		this.content.setAttribute('aria-busy', 'true');
		this.summary.setAttribute('aria-busy', 'true');
		this.setRefreshStatus(<?= json_encode(_('Loading VMware data…')) ?>);
		const keepContent = reload && this.hasData;
		if (!keepContent) {
			this.hasData = false;
			this.content.replaceChildren(Object.assign(document.createElement('div'), {
				className: 'vmware-monitoring-section',
				textContent: <?= json_encode(_('Loading VMware data…')) ?>
			}));
		}

		try {
			const tab_data = await this.fetchTab(key, target.href, {reload, force});
			if (this.activeTab !== key || this.tabActivation !== activation) {
				return;
			}
			this.content.innerHTML = tab_data.content;
			this.summary.innerHTML = tab_data.summary;
			this.hasData = true;
			this.lastUpdated = new Date(tab_data.loadedAt).toLocaleTimeString();
			this.applyLocationStates();
			document.dispatchEvent(new CustomEvent('zbx_reload', {
				detail: {source: 'vmware-monitoring-list', tab: key}
			}));
			this.setRefreshStatus(this.completedStatus());
		}
		catch (error) {
			if (this.activeTab === key && this.tabActivation === activation && error.name !== 'AbortError') {
				this.setRefreshStatus(keepContent
					? <?= json_encode(_('Unable to refresh VMware data. Showing the last loaded values.')) ?>
					: <?= json_encode(_('Unable to load VMware data. Use Refresh vCenters to retry.')) ?>);
				if (!keepContent) {
					this.content.replaceChildren(Object.assign(document.createElement('div'), {
						className: 'msg-bad',
						textContent: <?= json_encode(_('Unable to load VMware data for this tab.')) ?>
					}));
				}
			}
		}
		finally {
			if (this.activeTab === key && this.tabActivation === activation) {
				this.content.setAttribute('aria-busy', 'false');
				this.summary.setAttribute('aria-busy', 'false');
				this.scheduleRefresh();
			}
		}
	}

	scheduleRefresh() {
		if (this.refresh <= 0) {
			return;
		}
		this.refreshTimer = setTimeout(() => {
			if (document.hidden || (typeof overlays_stack !== 'undefined' && overlays_stack.length)) {
				this.scheduleRefresh();
				return;
			}
			this.activateTab(this.activeTab, {reload: true});
		}, this.refresh * 1000);
	}

	tabCacheKey(url) {
		const target = new URL(url, window.location.href);
		target.hash = '';
		target.searchParams.sort();
		return target.href;
	}

	fetchTab(key, url, {reload = false, force = false} = {}) {
		const cacheKey = this.tabCacheKey(url);
		if (!reload && !force && this.tabContent.has(cacheKey)
				&& Date.now() - this.tabContent.get(cacheKey).loadedAt < this.cacheLifetime) {
			return Promise.resolve(this.tabContent.get(cacheKey));
		}
		if (this.tabRequests.has(cacheKey)) {
			return this.tabRequests.get(cacheKey).promise;
		}

		const target = new URL('zabbix.php', window.location.href);
		target.searchParams.set('action', 'vmware.monitoring.list.data');
		target.searchParams.set('tab', key);
		const page = new URL(url, window.location.href);
		for (const name of ['page', 'sort', 'sortorder']) {
			if (page.searchParams.has(name)) {
				target.searchParams.set(name, page.searchParams.get(name));
			}
		}
		for (const [name, values] of Object.entries(this.filters)) {
			values.forEach(value => target.searchParams.append(`filter_${name}[]`, value));
		}
		if (force) {
			target.searchParams.set('force_vcenter_refresh', '1');
		}
		const controller = new AbortController();
		const request = fetch(target.href, {cache: 'no-store', signal: controller.signal})
			.then(response => {
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}`);
				}
				return response.json();
			})
			.then(tab_data => {
				if (typeof tab_data.content !== 'string' || typeof tab_data.summary !== 'string') {
					throw new Error('Tab content was not returned.');
				}
				if (controller.signal.aborted) {
					throw new DOMException('Request superseded.', 'AbortError');
				}
				tab_data.loadedAt = Date.now();
				this.tabContent.set(cacheKey, tab_data);
				while (this.tabContent.size > 12) {
					this.tabContent.delete(this.tabContent.keys().next().value);
				}
				return tab_data;
			})
			.finally(() => {
				if (this.tabRequests.get(cacheKey)?.controller === controller) {
					this.tabRequests.delete(cacheKey);
				}
			});
		this.tabRequests.set(cacheKey, {promise: request, controller});
		return request;
	}

	setRefreshStatus(message) {
		const status = document.getElementById('vmware-monitoring-refresh-status');
		if (status) {
			status.textContent = message;
		}
	}

	completedStatus() {
		const updated = this.lastUpdated
			? <?= json_encode(_('Updated at %1$s')) ?>.replace('%1$s', this.lastUpdated) : '';
		return this.refresh > 0
			? [updated, <?= json_encode(_('Auto-refresh enabled')) ?>].filter(Boolean).join(' · ')
			: updated;
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
