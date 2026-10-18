<?php declare(strict_types = 0); ?>
<script>
(() => {
	document.addEventListener('click', event => {
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
