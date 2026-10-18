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
				toggle.setAttribute('aria-expanded', 'true');
				toggle.textContent = <?= json_encode(_('Hide details')) ?>;
				toggle.focus({preventScroll: true});
			}
			row.scrollIntoView({block: 'nearest'});
			return;
		}
		const button = event.target.closest('[data-vmware-dvs-port-toggle]');
		if (button === null) { return; }
		const row = document.getElementById(button.dataset.vmwareDvsPortToggle);
		if (row === null) { return; }
		const expanded = button.getAttribute('aria-expanded') === 'true';
		row.hidden = expanded;
		button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
		button.textContent = expanded ? <?= json_encode(_('Port details')) ?> : <?= json_encode(_('Hide details')) ?>;
	});
})();
</script>
