<?php declare(strict_types = 0); ?>
<script>
(() => {
	document.addEventListener('click', event => {
		const port = event.target.closest('[data-vmware-dvs-port-open]');
		if (port !== null && port.dataset.vmwareDvsPortOpen) {
			const row = document.getElementById(port.dataset.vmwareDvsPortOpen);
			if (row === null) { return; }
			row.hidden = false;
			const toggle = document.querySelector('[data-vmware-dvs-port-toggle="' + row.id + '"]');
			if (toggle !== null) {
				window.VMwareCommon.RowDetails.setExpanded(toggle, row, true);
				toggle.focus({preventScroll: true});
			}
			row.scrollIntoView({block: 'nearest'});
			return;
		}
		const button = event.target.closest('[data-vmware-dvs-port-toggle]');
		if (button === null) { return; }
		const row = document.getElementById(button.dataset.vmwareDvsPortToggle);
		if (row === null) { return; }
		window.VMwareCommon.RowDetails.toggle(button, row);
	});
})();
</script>
