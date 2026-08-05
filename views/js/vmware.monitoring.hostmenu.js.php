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
	const refreshAfterEdit = event => {
		const response = event.detail ?? {};

		if (window.vmware_monitoring !== undefined) {
			if (response.success !== undefined) {
				clearMessages();
				addMessage(makeMessageBox('good', response.success.messages ?? [], response.success.title));
			}
			window.vmware_monitoring.cache.clear();
			window.vmware_monitoring.loadTab(window.vmware_monitoring.activeTab, true);
		}
		else {
			if (response.success !== undefined) {
				postMessageOk(response.success.title);
				if (response.success.messages !== undefined) {
					postMessageDetails('success', response.success.messages);
				}
			}
			window.location.reload();
		}
	};

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

			dialogue.addEventListener('dialogue.submit', refreshAfterEdit, {once: true});
			dialogue.addEventListener('dialogue.close', () => {
				history.replaceState({}, '', originalUrl);
			}, {once: true});
		};
	}

	if (typeof hostView.editTrigger !== 'function') {
		hostView.editTrigger = triggerData => {
			clearMessages();
			const overlay = PopUp('trigger.edit', triggerData, {
				dialogueid: 'trigger-edit',
				dialogue_class: 'modal-popup-large',
				prevent_navigation: true
			});
			overlay.$dialogue?.[0]?.addEventListener('dialogue.submit', refreshAfterEdit, {once: true});
		};
	}

	if (typeof hostView.editItem !== 'function') {
		hostView.editItem = (target, itemData) => {
			clearMessages();
			const overlay = PopUp('item.edit', itemData, {
				dialogueid: 'item-edit',
				dialogue_class: 'modal-popup-large',
				trigger_element: target,
				prevent_navigation: true
			});
			overlay.$dialogue?.[0]?.addEventListener('dialogue.submit', refreshAfterEdit, {once: true});
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
