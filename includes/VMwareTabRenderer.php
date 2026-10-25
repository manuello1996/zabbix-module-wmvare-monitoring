<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use Modules\VMwareMonitoring\Includes\Common\RowDetails;
use Modules\VMwareMonitoring\Includes\Common\ProblemBadges;
use Modules\VMwareMonitoring\Includes\Common\SortableHeader;
use Modules\VMwareMonitoring\Includes\Common\Sparkline;
use Modules\VMwareMonitoring\Includes\Common\Pager;

use Modules\VMwareMonitoring\Includes\Common\SearchControl;

use CDiv;
use CLink;
use CLinkAction;
use CMenuPopupHelper;
use CRow;
use CSpan;
use CTableInfo;
use CUrl;
use CSeverityHelper;
use Widgets\Problems\Includes\WidgetProblems;

require_once __DIR__.'/common/bootstrap.php';

class VMwareTabRenderer {

	public static function render(string $tab, array $data): string {
		$content = match ($tab) {
			'overview' => self::overview($data),
			'hypervisors' => self::hypervisors($data),
			'vms' => self::vms($data),
			'datastores' => self::datastores($data),
			'clusters' => self::clusters($data),
			'dvswitches' => self::distributedSwitches($data),
			'alarms' => self::alarms($data),
			default => new CDiv(_('Unsupported tab.'))
		};

		return (string) $content;
	}

	private static function overview(array $data): CDiv {
		$health = VMwareFormatter::vcenterHealth($data['vcenter_metrics']['health'] ?? null);
		$table = (new CTableInfo())->setHeader([_('Property'), _('Value')]);
		$table->addRow([_('Product'), VMwareItemMenu::value($data['vcenter_metrics']['fullname'] ?? '-', $data['vcenter_metrics'], 'fullname')]);
		$table->addRow([_('Version'), VMwareItemMenu::value($data['vcenter_metrics']['version'] ?? '-', $data['vcenter_metrics'], 'version')]);
		$table->addRow([
			_('Overall health'),
			VMwareItemMenu::value((new CSpan($health['text']))
				->addClass('vmware-monitoring-state')
				->addClass('vmware-monitoring-state-'.$health['kind']), $data['vcenter_metrics'], 'health')
		]);
		$table->addRow([_('Hypervisors'), (string) $data['hypervisors_count']]);
		$table->addRow([_('Virtual machines'), (string) $data['reported_vms_count']]);
		$table->addRow([_('Discovered virtual machines'), (string) $data['vms_count']]);
		$table->addRow([_('Unique datastores'), (string) $data['datastores_count']]);
		$table->addRow([_('Datastore attachments'), (string) $data['datastore_attachments_count']]);

		return new CDiv([
			self::section([$table]),
			self::section([
				(new CDiv(new WidgetProblems($data['problem_widget'])))
					->addClass('dashboard-widget-problems')
			])
		]);
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
				self::hostActionLink($hypervisor['name'], $hypervisor['hostid'], true, true),
				(new CDiv([
					(new CSpan($m['datacenter'] ?: '-'))->addClass('vmware-monitoring-muted'),
					new CSpan($cluster !== '' ? $cluster : _('Standalone (no cluster)'))
				]))->addClass('vmware-monitoring-name-cell'),
				VMwareItemMenu::value(self::state($connection), $m, 'connection'),
				VMwareItemMenu::value(self::state($health), $m, 'health'),
				self::problemBadge($hypervisor['problems'], $hypervisor['hostid']),
				(new CDiv([
					VMwareItemMenu::value((new CSpan(VMwareFormatter::percent($m['cpu'])))->addClass('vmware-monitoring-metric-value'), $m, 'cpu'),
					self::sparkline($m['cpu_itemid'] ?? null)
				]))->addClass('vmware-monitoring-metric-cell'),
				$memory_pct !== null
					? new CSpan([VMwareFormatter::percent($memory_pct).' / ', VMwareItemMenu::value(VMwareFormatter::bytes($m['memory_total']), $m, 'memory_total')])
					: '-',
				VMwareItemMenu::value($m['vm_count'] !== null ? (string) $m['vm_count'] : '-', $m, 'vm_count'),
				VMwareItemMenu::value(VMwareFormatter::duration($m['uptime']), $m, 'uptime'),
				VMwareItemMenu::value($m['version'] ?: '-', $m, 'version')
			]);
		}
		return self::section([
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
				VMwareItemMenu::value(self::state($power), $m, 'power'),
				VMwareItemMenu::value(self::state($state), $m, 'state'),
				self::problemBadge($vm['problems'], $vm['hostid']),
				(new CDiv([
					VMwareItemMenu::value((new CSpan(VMwareFormatter::percent($m['cpu'])))->addClass('vmware-monitoring-metric-value'), $m, 'cpu'),
					self::sparkline($m['cpu_itemid'] ?? null)
				]))->addClass('vmware-monitoring-metric-cell'),
				$m['memory_pct'] !== null
					? new CSpan([VMwareItemMenu::value(VMwareFormatter::percent($m['memory_pct']), $m, 'memory_pct'), ' / ',
						VMwareItemMenu::value(VMwareFormatter::bytes($m['memory_total']), $m, 'memory_total')])
					: VMwareItemMenu::value(VMwareFormatter::bytes($m['memory_total']), $m, 'memory_total'),
				VMwareItemMenu::value(VMwareFormatter::bytes($m['storage']), $m, 'storage'),
				VMwareItemMenu::value(VMwareFormatter::toolsStatus($m['tools']), $m, 'tools'),
				VMwareItemMenu::value($m['snapshots'] !== null ? (string) $m['snapshots'] : '-', $m, 'snapshots'),
				VMwareItemMenu::value(VMwareFormatter::duration($m['uptime']), $m, 'uptime')
			]);
		}

		return self::section([
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
			$details_id = 'vmware-monitoring-datastore-'.substr(hash('sha256', $row['identity']), 0, 16);
			$details_content_id = $details_id.'-content';
			$details_url = (new CUrl('zabbix.php'))
				->setArgument('action', 'vmware.monitoring.datastore.attachments')
				->setArgument('hostid', $row['vcenter_hostid'])
				->setArgument('identity', $row['identity'])
				->getUrl();

			$toggle = RowDetails::toggle(_('Attachments').' ('.$row['attachments'].')', $details_id, false, [
				'data-vmware-monitoring-datastore-toggle' => $details_id,
				'aria-label' => _('Attachment metrics').': '.$row['name']
			]);
			$unique_table->addRow(new CRow([
				RowDetails::name((new CSpan($row['name']))->addClass('vmware-monitoring-name'), $toggle),
				$row['type'] ?: '-', $row['uuid'] ?: '-',
				VMwareItemMenu::value(VMwareFormatter::bytes($row['total']), $row, 'total'),
				VMwareItemMenu::value(VMwareFormatter::percent($row['free_pct']), $row, 'free_pct'),
				self::hypervisorList($row['hypervisors']), (string) $row['attachments']
			]));
			$unique_table->addRow(RowDetails::row($details_id, 7,
				(new CDiv(_('Expand to load attachment metrics.')))->setId($details_content_id), false, [
					'data-vmware-monitoring-datastore-url' => $details_url,
					'data-vmware-monitoring-datastore-content' => $details_content_id
				]));
		}

		return self::section([
			self::search(_('Filter datastores...'), $data['search'] ?? ''),
			RowDetails::table($unique_table),
			self::pager($data['unique'])
		]);
	}

	public static function renderDatastoreAttachments(array $attachments): string {
		$groups = [];
		foreach ($attachments as $attachment) {
			$table = (new CTableInfo())->setHeader([_('Metric'), _('Latest value')]);
			$table->addRow([_('Type'), $attachment['type'] ?: '-']);
			$table->addRow([_('Capacity'), VMwareItemMenu::value(VMwareFormatter::bytes($attachment['total']), $attachment, 'total')]);
			$table->addRow([_('Free'), VMwareItemMenu::value(VMwareFormatter::percent($attachment['free_pct']), $attachment, 'free_pct')]);
			foreach (['read_latency' => _('Read latency'), 'write_latency' => _('Write latency'),
				'read_iops' => _('Read IOPS'), 'write_iops' => _('Write IOPS'), 'multipath' => _('Multipaths')] as $key => $label) {
				$value = VMwareItemMenu::value($attachment[$key] ?? '-', $attachment, $key);
				$table->addRow([$label, str_ends_with($key, '_latency') ? new CSpan([$value, ' ms']) : $value]);
			}
			$groups[] = RowDetails::group(_('Hypervisor').': '.($attachment['hypervisor'] ?: '-'), $table);
		}
		if (!$groups) {
			$groups[] = RowDetails::group(_('Attachments'), (new CTableInfo())->setNoDataMessage(_('No datastore attachments found.')));
		}
		return (string) RowDetails::grid($groups);
	}

	private static function distributedSwitches(array $data): CDiv {
		if (!$data['enabled']) {
			return self::section([new CDiv(_(
				'Optional monitoring: import VMware Distributed Switches and link it to this vCenter host alongside VMware.'
			))]);
		}
		$table = (new CTableInfo())->setHeader([
			_('Switch'), _('UUID'), _('Ports'), _('Connected'), _('Link up'),
			_('Connected, link down'), _('Blocked'), _('Conflicts'), _('Last collection'), _('Collection')
		])->setNoDataMessage(_('No distributed switches found. Wait for discovery and the first port samples.'));
		foreach ($data['rows'] as $row) {
			$url = (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.dvswitch')
				->setArgument('hostid', $row['vcenter_hostid'])->setArgument('uuid', $row['uuid']);
			$cells = [new CLink($row['name'], $url), $row['uuid']];
			foreach (['ports', 'connected', 'link_up', 'connected_down', 'blocked', 'conflicts'] as $field) {
				$cells[] = VMwareItemMenu::value($row[$field] ?? '-', $row, $field);
			}
			$cells[] = VMwareItemMenu::value(self::clock($row['lastclock']), $row, 'raw');
			$cells[] = $row['errors']
				? (new CSpan(_('Collection error')))->addClass(ZBX_STYLE_RED)
					->setTitle(implode("\n", array_unique($row['errors'])))
				: ($row['ports'] === null ? _('Awaiting data') : _('Collected'));
			$table->addRow($cells);
		}
		return self::section([
			new CDiv(_('Port states are snapshots. Disconnected ports are not counted as connected links down.')),
			...array_map(static fn(string $error) => (new CDiv($error))->addClass(ZBX_STYLE_RED),
				$data['discovery_errors']),
			self::search(_('Filter switches...'), $data['search']), $table, self::pager($data)
		]);
	}

	private static function clusters(array $rows): CDiv {
		$table = (new CTableInfo())
			->setHeader([
				_('Cluster'), _('Status'), _('Hypervisors'), _('Virtual machines'), _('Discovered VMs'),
				_('Memory used'), _('Capacity')
			])
			->setNoDataMessage(_('No clusters or standalone hypervisors discovered.'));
		foreach ($rows as $row) {
			$status = $row['status'] === null
				? ['text' => $row['name'] === _('Standalone (no cluster)') ? _('Not applicable') : _('Unknown'),
					'kind' => 'unknown']
				: VMwareFormatter::hypervisorHealth($row['status']);
			$detail_url = (new CUrl('zabbix.php'))
				->setArgument('action', 'vmware.monitoring.cluster')
				->setArgument('hostid', $row['vcenter_hostid'])
				->setArgument('cluster', $row['name'])
				->getUrl();
			$cluster_link = (new CLink($row['name'], $detail_url))->addClass('vmware-monitoring-name');
			$table->addRow([
				$cluster_link,
				VMwareItemMenu::value(self::state($status), $row, 'status'),
				(string) $row['hypervisors'],
				(string) $row['virtual_machines'],
				(string) $row['discovered_vms'],
				VMwareFormatter::bytes($row['memory_used']),
				VMwareFormatter::bytes($row['memory_total'])
			]);
		}
		return self::section([$table]);
	}

	private static function alarms(array $rows): CDiv {
		$table = (new CTableInfo())
			->setHeader([_('Issue'), _('Status'), _('Severity'), _('Last update')])
			->setNoDataMessage(_('No active vCenter issues.'));
		foreach ($rows as $row) {
			$severity = $row['severity'] === null
				? '-'
				: (new CSpan(CSeverityHelper::getName((int) $row['severity'])))
					->addClass(CSeverityHelper::getStatusStyle((int) $row['severity']));
			$table->addRow([
				(new CSpan($row['name']))->addClass('vmware-monitoring-issue-text'),
				VMwareItemMenu::link((new CSpan(_('Active')))->addClass('vmware-monitoring-issue-status'), $row['itemid'] ?? null),
				$severity,
				self::clock($row['lastclock'])
			]);
		}
		return self::section([$table]);
	}

	private static function section(array $content): CDiv {
		return (new CDiv($content))->addClass('vmware-monitoring-section');
	}

	private static function state(array $state): CSpan {
		return (new CSpan($state['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$state['kind']);
	}

	public static function hostActionLink(string $name, string $hostid, bool $show_sensors = false,
			bool $open_detail_on_click = false): CLink {
		$detail_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'vmware.monitoring.hypervisor')
			->setArgument('hostid', $hostid)->getUrl();
		$link = ($open_detail_on_click ? new CLink($name, $detail_url) : new CLinkAction($name))
			->addClass('vmware-monitoring-name')
			->setMenuPopup(CMenuPopupHelper::getHost($hostid));

		if ($show_sensors) {
			$sensors_url = (new CUrl('zabbix.php'))
				->setArgument('action', 'vmware.monitoring.hypervisor')
				->setArgument('hostid', $hostid)
				->setArgument('tab', 'sensors')
				->getUrl();
			$link->setAttribute('data-vmware-monitoring-sensors-url', $sensors_url);
			if ($open_detail_on_click) {
				$link->setAttribute('data-vmware-monitoring-host-detail-url', $detail_url);
			}
		}

		return $link;
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
		return Sparkline::holder($itemid !== null ? ['data-vmware-monitoring-spark-itemid' => $itemid] : []);
	}

	public static function problemBadge(array $severities, string $hostid) {
		return ProblemBadges::link($severities, [$hostid]);
	}

	private static function search(string $placeholder, string $value): CDiv {
		return SearchControl::bar($placeholder, $value, ['data-vmware-monitoring-server-search' => '1']);
	}

	private static function sortHeader(string $label, string $field, array $data): CSpan {
		return SortableHeader::render($label, $field, $data['sort'] ?? 'name', $data['sortorder'] ?? ZBX_SORT_UP,
			['attribute' => 'data-vmware-monitoring-sort']);
	}

	private static function pager(array $data): CDiv {
		return Pager::ajax($data, 'data-vmware-monitoring-page');
	}

	private static function clock($clock): string {
		return (int) $clock > 0 ? date('Y-m-d H:i:s', (int) $clock) : '-';
	}
}
