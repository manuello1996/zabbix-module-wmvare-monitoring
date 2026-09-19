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
		if (this.content !== null && this.summary !== null) {
			this.tabContent.set(this.activeTab, {
				content: this.content.innerHTML,
				summary: this.summary.innerHTML
			});
			this.content.addEventListener('contextmenu', event => this.openHostMenu(event));
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
			if (this.activeTab !== key) {
				return;
			}
			this.content.innerHTML = tab_data.content;
			this.summary.innerHTML = tab_data.summary;
			document.dispatchEvent(new CustomEvent('zbx_reload', {
				detail: {source: 'vmware-monitoring-list', tab: key}
			}));
			this.setRefreshStatus();
		}
		catch (error) {
			if (this.activeTab === key) {
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

	fetchTab(key, url) {
		if (this.tabContent.has(key)) {
			return Promise.resolve(this.tabContent.get(key));
		}
		if (this.tabRequests.has(key)) {
			return this.tabRequests.get(key);
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
				this.tabContent.set(key, tab_data);
				return tab_data;
			})
			.finally(() => this.tabRequests.delete(key));
		this.tabRequests.set(key, request);
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
