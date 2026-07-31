<?php declare(strict_types = 0); ?>
<script>
window.vmware_monitoring_sensors = new class {
	init() {
		document.querySelectorAll('[data-vmware-monitoring-sensor-group]').forEach(button => {
			const toggle = () => {
				const group = button.closest('.vmware-monitoring-graphgroup');
				const body = document.getElementById(button.getAttribute('aria-controls'));
				const open = !group.classList.contains('vmware-monitoring-graphgroup-open');

				group.classList.toggle('vmware-monitoring-graphgroup-open', open);
				button.setAttribute('aria-expanded', open ? 'true' : 'false');
				body.hidden = !open;
			};

			button.addEventListener('click', toggle);
		});
	}
};
</script>
