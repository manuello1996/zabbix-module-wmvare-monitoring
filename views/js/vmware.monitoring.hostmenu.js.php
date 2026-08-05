<?php declare(strict_types = 0); ?>
<script>
(() => {
	const selector = '[data-vmware-monitoring-sensors-url]';
	const menuSelector = '.menu-popup.menu-popup-top';
	const sensorsLabel = <?= json_encode(_('Sensors')) ?>;
	const viewLabel = <?= json_encode(_('View')) ?>;
	let activeObserver = null;
	const hostView = typeof view === 'object' && view !== null
		? view
		: (window.view = {});

	if (typeof hostView.editHost !== 'function') {
		hostView.editHost = hostid => {
			const originalUrl = location.href;
			const overlay = PopUp('popup.host.edit', {hostid: String(hostid)}, {
				dialogueid: 'host_edit',
				dialogue_class: 'modal-popup-large',
				prevent_navigation: true
			});
			const dialogue = overlay.$dialogue?.[0];

			if (dialogue === undefined) {
				return;
			}

			dialogue.addEventListener('dialogue.submit', () => {
				if (window.vmware_monitoring !== undefined) {
					window.vmware_monitoring.cache.clear();
					window.vmware_monitoring.loadTab(window.vmware_monitoring.activeTab, true);
				}
				else {
					window.location.reload();
				}
			}, {once: true});
			dialogue.addEventListener('dialogue.close', () => {
				history.replaceState({}, '', originalUrl);
			}, {once: true});
		};
	}

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
