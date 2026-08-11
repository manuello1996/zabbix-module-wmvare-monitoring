<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring_list = new class {
	init(config) {
		this.refresh = Number(config.refresh || 0);
		this.table = document.getElementById('vmware-monitoring-vcenters-table');
		this.table?.addEventListener('contextmenu', event => this.openHostMenu(event));
		if (this.refresh > 0) {
			window.setTimeout(() => window.location.reload(), this.refresh * 1000);
		}

		const status = document.getElementById('vmware-monitoring-refresh-status');
		if (status && this.refresh > 0) {
			status.textContent = <?= json_encode(_('Auto-refresh enabled')) ?>;
		}
	}

	openHostMenu(event) {
		const link = event.target.closest('[data-vmware-monitoring-host-menu]');
		if (link === null || !this.table.contains(link)) {
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
