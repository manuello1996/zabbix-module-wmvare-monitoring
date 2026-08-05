<?php declare(strict_types = 0); ?>
<script>
(() => {
	const selector = '[data-vmware-monitoring-sensors-url]';
	const menuSelector = '.menu-popup.menu-popup-top';
	const sensorsLabel = <?= json_encode(_('Sensors')) ?>;
	const viewLabel = <?= json_encode(_('View')) ?>;
	let activeObserver = null;

	const prepareSensorsEntry = event => {
		if (event.type === 'keydown' && event.key !== 'Enter') {
			return;
		}

		const opener = event.target.closest(selector);
		if (opener === null) {
			return;
		}

		const sensorsUrl = opener.dataset.vmwareMonitoringSensorsUrl;
		if (!sensorsUrl) {
			return;
		}

		activeObserver?.disconnect();
		activeObserver = new MutationObserver(() => {
			const menus = document.querySelectorAll(menuSelector);
			const menu = menus.length > 0 ? menus[menus.length - 1] : null;
			if (menu === null || menu.querySelector('[data-vmware-monitoring-sensors-entry]') !== null) {
				return;
			}

			const heading = [...menu.querySelectorAll(':scope > li > h3')]
				.find(element => element.textContent.trim() === viewLabel);
			if (heading === undefined) {
				return;
			}

			let boundary = heading.parentElement.nextElementSibling;
			while (boundary !== null
					&& boundary.querySelector(':scope > h3') === null
					&& boundary.querySelector(':scope > div') === null) {
				boundary = boundary.nextElementSibling;
			}

			const item = document.createElement('li');
			const link = document.createElement('a');
			link.href = sensorsUrl;
			link.className = 'menu-popup-item';
			link.setAttribute('role', 'menuitem');
			link.setAttribute('tabindex', '-1');
			link.setAttribute('aria-label', `${viewLabel}, ${sensorsLabel}`);
			link.setAttribute('data-vmware-monitoring-sensors-entry', '1');
			link.textContent = sensorsLabel;
			item.append(link);
			menu.insertBefore(item, boundary);
			activeObserver?.disconnect();
			activeObserver = null;
		});

		activeObserver.observe(document.querySelector('.wrapper') ?? document.body, {
			childList: true,
			subtree: true
		});
		window.setTimeout(() => {
			activeObserver?.disconnect();
			activeObserver = null;
		}, 5000);
	};

	document.addEventListener('click', prepareSensorsEntry, true);
	document.addEventListener('keydown', prepareSensorsEntry, true);
})();
</script>
