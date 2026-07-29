<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring_list = new class {
	init(config) {
		this.refresh = Number(config.refresh || 0);
		if (this.refresh > 0) {
			window.setTimeout(() => window.location.reload(), this.refresh * 1000);
		}

		const status = document.getElementById('vmware-monitoring-refresh-status');
		if (status && this.refresh > 0) {
			status.textContent = <?= json_encode(_('Auto-refresh enabled')) ?>;
		}
	}
};
</script>
