<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use CDiv;
use CLink;
use CLinkAction;
use CSpan;
use CTableInfo;
use CTag;
use CUrl;
use CSeverityHelper;

class VMwareTabRenderer {
	private const PAGER_RANGE = 11;

	public static function render(string $tab, array $data): string {
		$content = match ($tab) {
			'overview' => self::overview($data),
			'hypervisors' => self::hypervisors($data),
			'vms' => self::vms($data),
			'datastores' => self::datastores($data),
			'clusters' => self::clusters($data),
			'alarms' => self::alarms($data),
			default => new CDiv(_('Unsupported tab.'))
		};

		return (string) $content;
	}

	private static function overview(array $data): CDiv {
		$health = VMwareFormatter::vcenterHealth($data['vcenter_metrics']['health'] ?? null);
		$table = (new CTableInfo())->setHeader([_('Property'), _('Value')]);
		$table->addRow([_('Product'), $data['vcenter_metrics']['fullname'] ?? '-']);
		$table->addRow([_('Version'), $data['vcenter_metrics']['version'] ?? '-']);
		$table->addRow([
			_('Overall health'),
			(new CSpan($health['text']))
				->addClass('vmware-monitoring-state')
				->addClass('vmware-monitoring-state-'.$health['kind'])
		]);
		$table->addRow([_('Hypervisors'), (string) $data['hypervisors_count']]);
		$table->addRow([_('Virtual machines'), (string) $data['reported_vms_count']]);
		$table->addRow([_('Discovered virtual machines'), (string) $data['vms_count']]);
		$table->addRow([_('Unique datastores'), (string) $data['datastores_count']]);
		$table->addRow([_('Datastore attachments'), (string) $data['datastore_attachments_count']]);
		return self::section(_('vCenter overview'), [$table]);
	}

	private static function hypervisors(array $data): CDiv {
		$table = (new CTableInfo())
			->setHeader([
				self::sortHeader(_('Hypervisor'), 'name', $data),
				self::sortHeader(_('Datacenter / cluster'), 'cluster', $data),
				_('Connection'),
				_('Health'),
				_('Problems'),
				self::sortHeader(_('CPU (24h)'), 'cpu', $data),
				self::sortHeader(_('Memory'), 'memory', $data),
				self::sortHeader(_('VMs'), 'vms', $data),
				self::sortHeader(_('Uptime'), 'uptime', $data),
				self::sortHeader(_('Version'), 'version', $data)
			])
			->setNoDataMessage(_('No hypervisors discovered yet.'));

		foreach ($data['rows'] as $hypervisor) {
			$m = $hypervisor['metrics'];
			$connection = VMwareFormatter::connectionState($m['connection']);
			$health = VMwareFormatter::hypervisorHealth($m['health']);
			$memory_pct = (float) $m['memory_total'] > 0
				? ((float) $m['memory_used'] / (float) $m['memory_total']) * 100
				: null;
			$cluster = trim((string) $m['cluster']);

			$table->addRow([
				self::hostActionLink($hypervisor['name'], $hypervisor['hostid'], true),
				(new CDiv([
					(new CSpan($m['datacenter'] ?: '-'))->addClass('vmware-monitoring-muted'),
					new CSpan($cluster !== '' ? $cluster : _('Standalone (no cluster)'))
				]))->addClass('vmware-monitoring-name-cell'),
				self::state($connection),
				self::state($health),
				self::problemBadge($hypervisor['problems'], $hypervisor['hostid']),
				(new CDiv([
					(new CSpan(VMwareFormatter::percent($m['cpu'])))->addClass('vmware-monitoring-metric-value'),
					self::sparkline($m['cpu_itemid'] ?? null)
				]))->addClass('vmware-monitoring-metric-cell'),
				$memory_pct !== null
					? VMwareFormatter::percent($memory_pct).' / '.VMwareFormatter::bytes($m['memory_total'])
					: '-',
				$m['vm_count'] !== null ? (string) $m['vm_count'] : '-',
				VMwareFormatter::duration($m['uptime']),
				$m['version'] ?: '-'
			]);
		}
		return self::section(_('Discovered hypervisors'), [
			self::search(_('Filter by hypervisor or cluster...'), $data['search'] ?? ''),
			$table,
			self::pager($data)
		]);
	}

	private static function vms(array $data): CDiv {
		$table = (new CTableInfo())
			->setHeader([
				_('Virtual machine'), _('Notes'), _('Placement'), _('Power'), _('State'), _('Problems'),
				_('CPU (24h)'), _('Memory'), _('Storage'), _('VMware Tools'), _('Snapshots'), _('Uptime')
			])
			->setNoDataMessage(_('No virtual machines found.'));

		foreach ($data['rows'] as $vm) {
			$m = $vm['metrics'];
			$power = VMwareFormatter::vmPowerState($m['power']);
			$state = VMwareFormatter::vmState($m['state']);
			$cluster = trim((string) $m['cluster']);
			$placement = (new CSpan(
				($m['datacenter'] ?: '-').' / '.
				($cluster !== '' ? $cluster : _('Standalone (no cluster)'))
			))->addClass('vmware-monitoring-muted');

			$table->addRow([
				self::hostActionLink($vm['name'], $vm['hostid']),
				(new CSpan(trim((string) ($vm['inventory']['notes'] ?? '')) ?: '-'))
					->addClass('vmware-monitoring-vm-notes'),
				(new CDiv([
					new CSpan($m['hypervisor'] ?: '-'),
					$placement
				]))->addClass('vmware-monitoring-name-cell'),
				self::state($power),
				self::state($state),
				self::problemBadge($vm['problems'], $vm['hostid']),
				(new CDiv([
					(new CSpan(VMwareFormatter::percent($m['cpu'])))->addClass('vmware-monitoring-metric-value'),
					self::sparkline($m['cpu_itemid'] ?? null)
				]))->addClass('vmware-monitoring-metric-cell'),
				$m['memory_pct'] !== null
					? VMwareFormatter::percent($m['memory_pct']).' / '.VMwareFormatter::bytes($m['memory_total'])
					: VMwareFormatter::bytes($m['memory_total']),
				VMwareFormatter::bytes($m['storage']),
				VMwareFormatter::toolsStatus($m['tools']),
				$m['snapshots'] !== null ? (string) $m['snapshots'] : '-',
				VMwareFormatter::duration($m['uptime'])
			]);
		}

		return self::section(_('Discovered virtual machines'), [
			self::search(_('Filter virtual machines...'), $data['search'] ?? ''),
			$table,
			self::pager($data)
		]);
	}

	private static function datastores(array $data): CDiv {
		$unique_table = (new CTableInfo())
			->setHeader([
				self::sortHeader(_('Datastore'), 'name', $data),
				_('Type'),
				_('UUID'),
				self::sortHeader(_('Capacity'), 'total', $data),
				self::sortHeader(_('Free'), 'free', $data),
				_('Hypervisors'),
				self::sortHeader(_('Attachments'), 'attachments', $data)
			])
			->setNoDataMessage(_('No datastores discovered yet.'));
		foreach ($data['unique']['rows'] as $row) {
			$unique_table->addRow([
				(new CSpan($row['name']))->addClass('vmware-monitoring-name'),
				$row['type'] ?: '-',
				$row['uuid'] ?: '-',
				VMwareFormatter::bytes($row['total']),
				VMwareFormatter::percent($row['free_pct']),
				self::hypervisorList($row['hypervisors']),
				(string) $row['attachments']
			]);
		}

		$attachment_table = (new CTableInfo())
			->setHeader([
				self::sortHeader(_('Datastore'), 'name', $data),
				_('Hypervisor'),
				_('Type'),
				self::sortHeader(_('Capacity'), 'total', $data),
				self::sortHeader(_('Free'), 'free', $data),
				_('Read / write latency'), _('Read / write IOPS'), _('Multipaths')
			])
			->setNoDataMessage(_('No datastore attachments found.'));
		foreach ($data['attachments']['rows'] as $row) {
			$attachment_table->addRow([
				(new CSpan($row['name']))->addClass('vmware-monitoring-name'),
				$row['hypervisor'] ?: '-',
				$row['type'] ?: '-',
				VMwareFormatter::bytes($row['total']),
				VMwareFormatter::percent($row['free_pct']),
				($row['read_latency'] ?? '-').' / '.($row['write_latency'] ?? '-').' ms',
				($row['read_iops'] ?? '-').' / '.($row['write_iops'] ?? '-'),
				$row['multipath'] ?? '-'
			]);
		}

		return new CDiv([
			self::search(_('Filter datastores...'), $data['search'] ?? ''),
			self::section(_('Unique datastores'), [
				$unique_table,
				self::pager($data['unique'])
			]),
			self::section(_('Datastore attachments'), [
				$attachment_table,
				self::pager($data['attachments'])
			])
		]);
	}

	private static function clusters(array $rows): CDiv {
		$table = (new CTableInfo())
			->setHeader([
				_('Cluster'), _('Status'), _('Hypervisors'), _('Memory used'), _('Capacity')
			])
			->setNoDataMessage(_('No clusters or standalone hypervisors discovered.'));
		foreach ($rows as $row) {
			$status = $row['status'] === null
				? ['text' => $row['name'] === _('Standalone (no cluster)') ? _('Not applicable') : _('Unknown'),
					'kind' => 'unknown']
				: VMwareFormatter::hypervisorHealth($row['status']);
			$table->addRow([
				(new CLink($row['name'], '#'))
					->addClass('vmware-monitoring-name')
					->setAttribute('data-vmware-monitoring-cluster', $row['name']),
				self::state($status),
				(string) $row['hypervisors'],
				VMwareFormatter::bytes($row['memory_used']),
				VMwareFormatter::bytes($row['memory_total'])
			]);
		}
		return self::section(_('Clusters and standalone resources'), [$table]);
	}

	private static function alarms(array $rows): CDiv {
		$table = (new CTableInfo())
			->setHeader([_('Alarm'), _('Status'), _('Severity'), _('Last update')])
			->setNoDataMessage(_('No active VMware alarms.'));
		foreach ($rows as $row) {
			$severity = $row['severity'] === null
				? '-'
				: (new CSpan(CSeverityHelper::getName((int) $row['severity'])))
					->addClass(CSeverityHelper::getStatusStyle((int) $row['severity']));
			$table->addRow([
				(new CSpan($row['name']))->addClass('vmware-monitoring-name'),
				(new CSpan(_('Active')))->addClass('vmware-monitoring-state-stopped'),
				$severity,
				self::clock($row['lastclock'])
			]);
		}
		return self::section(_('Active VMware alarms'), [$table]);
	}

	private static function section(string $title, array $content): CDiv {
		return (new CDiv([
			(new CTag('h4', true, $title))->addClass('vmware-monitoring-section-title'),
			...$content
		]))->addClass('vmware-monitoring-section');
	}

	private static function state(array $state): CSpan {
		return (new CSpan($state['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$state['kind']);
	}

	private static function hostActionLink(string $name, string $hostid, bool $show_sensors = false): CLinkAction {
		$latest_data = (new CUrl('zabbix.php'))
			->setArgument('action', 'latest.view')
			->setArgument('hostids', [$hostid])
			->setArgument('filter_set', 1)
			->getUrl();
		$dashboard = (new CUrl('zabbix.php'))
			->setArgument('action', 'host.dashboard.view')
			->setArgument('hostid', $hostid)
			->getUrl();
		$items = [
			$latest_data => _('Latest data'),
			$dashboard => _('Host dashboard')
		];
		if ($show_sensors) {
			$sensors = (new CUrl('zabbix.php'))
				->setArgument('action', 'vmware.monitoring.sensors')
				->setArgument('hostid', $hostid)
				->getUrl();
			$items[$sensors] = _('Sensors');
		}

		return (new CLinkAction($name))
			->addClass('vmware-monitoring-name')
			->setMenuPopup([
				'type' => 'submenu',
				'data' => [
					'submenu' => [
						'view' => [
							'label' => _('View'),
							'items' => $items
						]
					]
				]
			]);
	}

	private static function hypervisorList(array $hypervisors) {
		if (!$hypervisors) {
			return '-';
		}

		$items = [];
		foreach (array_slice($hypervisors, 0, 3, true) as $hypervisor) {
			$items[] = (new CSpan($hypervisor))->addClass('vmware-monitoring-datastore-hypervisor');
		}
		if (count($hypervisors) > 3) {
			$hidden_items = [];
			foreach (array_slice($hypervisors, 3, null, true) as $hypervisor) {
				$hidden_items[] = (new CDiv($hypervisor))
					->addClass('vmware-monitoring-hidden-hypervisor');
			}

			$items[] = (new CLinkAction(sprintf(_('+%1$d more'), count($hypervisors) - 3)))
				->addClass('vmware-monitoring-datastore-hypervisor')
				->addClass('vmware-monitoring-datastore-hypervisor-more')
				->setHint(
					(new CDiv($hidden_items))->addClass('vmware-monitoring-hidden-hypervisors'),
					ZBX_STYLE_HINTBOX_WRAP,
					true
				);
		}

		return (new CDiv($items))
			->addClass('vmware-monitoring-datastore-hypervisors')
			->setTitle(implode(', ', $hypervisors));
	}

	private static function sparkline(?string $itemid): CDiv {
		$div = (new CDiv())->addClass('vmware-monitoring-sparkline');
		if ($itemid !== null) {
			$div->setAttribute('data-vmware-monitoring-spark-itemid', $itemid);
		}
		return $div;
	}

	private static function problemBadge(array $severities, string $hostid) {
		if (!$severities) {
			return (new CSpan(_('None')))->addClass(ZBX_STYLE_GREEN);
		}
		$items = [];
		foreach ($severities as $severity => $count) {
			$items[] = (new CSpan((string) $count))
				->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
				->addClass(CSeverityHelper::getStatusStyle((int) $severity))
				->setTitle(CSeverityHelper::getName((int) $severity));
		}
		return (new CLink(
			(new CDiv($items))->addClass(ZBX_STYLE_PROBLEM_ICON_LIST),
			(new CUrl('zabbix.php'))
				->setArgument('action', 'problem.view')
				->setArgument('hostids', [$hostid])
				->setArgument('filter_set', 1)
		))->addClass(ZBX_STYLE_PROBLEM_ICON_LINK);
	}

	private static function search(string $placeholder, string $value): CTag {
		return (new CTag('input', false))
			->setAttribute('type', 'search')
			->setAttribute('value', $value)
			->setAttribute('placeholder', $placeholder)
			->setAttribute('data-vmware-monitoring-server-search', '1')
			->addClass('vmware-monitoring-search');
	}

	private static function sortHeader(string $label, string $field, array $data): CSpan {
		$active = ($data['sort'] ?? 'name') === $field;
		$arrow = new CSpan();
		if ($active) {
			$arrow->addItem(
				(new CSpan())->addClass(
					($data['sortorder'] ?? ZBX_SORT_UP) === ZBX_SORT_UP ? 'arrow-up' : 'arrow-down'
				)
			);
		}

		return (new CSpan([
			$label,
			$arrow->addClass('vmware-monitoring-sort-arrow')
		]))
			->addClass('vmware-monitoring-sort')
			->setAttribute('data-vmware-monitoring-sort', $field)
			->setAttribute('role', 'button')
			->setAttribute('tabindex', '0');
	}

	private static function pager(array $data): CDiv {
		$page = (int) $data['page'];
		$pages = (int) $data['pages'];
		$total = (int) $data['total'];
		$per_page = (int) $data['per_page'];
		$start = ($page - 1) * $per_page;
		$end = min($total, $start + $per_page);
		$links = [];

		if ($pages > 1) {
			$end_page = min($pages, max(self::PAGER_RANGE, $page + intdiv(self::PAGER_RANGE, 2)));
			$start_page = max(1, $end_page - self::PAGER_RANGE + 1);

			if ($start_page > 1) {
				$links[] = self::pageLink(_x('First', 'page navigation'), 1, _('Go to first page'));
			}
			if ($page > 1) {
				$links[] = self::pageLink(
					(new CSpan())->addClass(ZBX_STYLE_ARROW_LEFT),
					$page - 1,
					_s('Go to previous page, %1$s', $page - 1)
				);
			}
			for ($number = $start_page; $number <= $end_page; $number++) {
				$link = self::pageLink(
					(string) $number,
					$number,
					$number === $page
						? _s('Go to page %1$s, current page', $number)
						: _s('Go to page %1$s', $number)
				);
				if ($number === $page) {
					$link
						->addClass(ZBX_STYLE_PAGING_SELECTED)
						->setAttribute('aria-current', 'true');
				}
				$links[] = $link;
			}
			if ($page < $pages) {
				$links[] = self::pageLink(
					(new CSpan())->addClass(ZBX_STYLE_ARROW_RIGHT),
					$page + 1,
					_s('Go to next page, %1$s', $page + 1)
				);
			}
			if ($end_page < $pages) {
				$links[] = self::pageLink(_x('Last', 'page navigation'), $pages,
					_s('Go to last page, %1$s', $pages)
				);
			}
		}

		$stats = $pages === 1
			? _s('Displaying %1$s of %2$s found', $total, $total)
			: _s('Displaying %1$s to %2$s of %3$s found', $start + 1, $end, $total);

		return (new CDiv())
			->addClass(ZBX_STYLE_TABLE_PAGING)
			->addItem(
				(new CTag('nav', true))
					->addClass(ZBX_STYLE_PAGING_BTN_CONTAINER)
					->setAttribute('role', 'navigation')
					->setAttribute('aria-label', _x('Pager', 'page navigation'))
					->addItem($links)
					->addItem(
						(new CDiv($stats))->addClass(ZBX_STYLE_TABLE_STATS)
					)
			);
	}

	private static function pageLink($content, int $page, string $label): CLink {
		return (new CLink($content, '#'))
			->setAttribute('data-vmware-monitoring-page', (string) $page)
			->setAttribute('aria-label', $label);
	}

	private static function clock($clock): string {
		return (int) $clock > 0 ? date('Y-m-d H:i:s', (int) $clock) : '-';
	}
}
