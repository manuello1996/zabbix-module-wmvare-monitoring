<?php declare(strict_types = 0);
use Modules\VMwareMonitoring\Includes\Common\Summary;
use Modules\VMwareMonitoring\Includes\Common\CapacityBar;

use Modules\VMwareMonitoring\Includes\VMwareFormatter;
use Modules\VMwareMonitoring\Includes\VMwareBreadcrumb;
use Modules\VMwareMonitoring\Includes\VMwareItemMenu as I;
use Widgets\Problems\Includes\WidgetProblems;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');
$this->addJsFile('class.tagfilteritem.js');
$this->includeJsFile('vmware.monitoring.hostmenu.js.php');

$page = (new CHtmlPage())
	->setTitle(_('VMware hypervisor'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());
$latest_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'latest.view')
	->setArgument('hostids', [$data['host']['hostid']])
	->setArgument('filter_set', 1);
$problems_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'problem.view')
	->setArgument('hostids', [$data['host']['hostid']])
	->setArgument('filter_set', 1);
$page->setControls(
	(new CTag('nav', true, (new CList())
		->addItem(new CRedirectButton(_('Latest data'), $latest_url))
		->addItem(new CRedirectButton(_('Problems'), $problems_url))
		->addItem((new CSimpleButton(_('Host configuration')))
			->onClick('view.editHost('.json_encode((string) $data['host']['hostid']).')'))
	))->setAttribute('aria-label', _('Hypervisor actions'))
);
$vcenter_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'vmware.monitoring.view')
	->setArgument('filter_hostid', [$data['vcenter']['hostid']])
	->setArgument('filter_set', 1);
$hypervisors_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'vmware.monitoring.view')
	->setArgument('filter_hostid', [$data['vcenter']['hostid']])
	->setArgument('filter_set', 1)
	->setArgument('tab', 'hypervisors');

$page->addItem(
	(new CDiv(
		VMwareBreadcrumb::make([
			new CLink(_('vCenters'), (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')),
			new CLink($data['vcenter']['name'], $vcenter_url),
			new CLink(_('Hypervisors'), $hypervisors_url),
			(new CSpan($data['host']['name']))
		])
	))->addClass('vmware-monitoring-topbar')
);

$tab_url = static function (string $tab) use ($data): CUrl {
	return (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.hypervisor')
		->setArgument('hostid', $data['host']['hostid'])
		->setArgument('tab', $tab === 'overview' ? null : $tab);
};
$tabs = [];
foreach ([
	['overview', _('Overview')],
	['datastores', _('Datastores')],
	['vms', _('Discovered VMs')],
	['problems', _('Problems')],
	['sensors', _('Sensors')]
] as [$key, $label]) {
	$active = $data['tab'] === $key;
	$tabs[] = (new CLink($label, $tab_url($key)))
		->addClass('vmware-monitoring-tab')
		->addClass($active ? 'vmware-monitoring-tab-active' : null)
		->setAttribute('role', 'tab')
		->setAttribute('aria-selected', $active ? 'true' : 'false');
}
$page->addItem((new CDiv($tabs))->addClass('vmware-monitoring-tabs')->setAttribute('role', 'tablist'));

$make_stat = static function (string $modifier, string $label, int $value): CDiv {
	return Summary::card($label, (string) $value, ['modifier' => $modifier]);
};

if ($data['tab'] === 'overview') {
	$m = $data['detail']['metrics'];
	$connection = VMwareFormatter::connectionState($m['connection']);
	$health = VMwareFormatter::hypervisorHealth($m['health']);
	$problem_value = $data['detail']['problem_count'] > 0
		? new CLink(
			(string) $data['detail']['problem_count'],
			(new CUrl('zabbix.php'))
				->setArgument('action', 'problem.view')
				->setArgument('hostids', [$data['host']['hostid']])
				->setArgument('filter_set', 1)
		)
		: new CSpan('0');

	$page->addItem(
		Summary::strip([
			$make_stat('total', _('Virtual machines'), (int) ($m['vm_count'] ?? 0)),
			$make_stat('total', _('Datastores'), $data['detail']['datastore_totals']['count']),
			Summary::card(_('Active problems'), $problem_value, [
				'modifier' => $data['detail']['problem_count'] > 0 ? 'stopped' : 'total'
			])
		])
	);

	$information = (new CTableInfo())->setHeader([_('Property'), _('Value')]);
	$information->addRow([_('Connection'),
		I::value((new CSpan($connection['text']))->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$connection['kind']), $m, 'connection')]);
	$information->addRow([_('Overall health'),
		I::value((new CSpan($health['text']))->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$health['kind']), $m, 'health')]);
	$information->addRow([_('vCenter'), $data['vcenter']['name']]);
	$information->addRow([_('Datacenter'), I::value($m['datacenter'] ?: '-', $m, 'datacenter')]);
	$information->addRow([_('Cluster'), trim((string) $m['cluster']) !== ''
		? I::value($m['cluster'], $m, 'cluster') : _('Standalone (no cluster)')]);
	$information->addRow([_('Vendor / model'), new CSpan([I::value($m['vendor'] ?: '-', $m, 'vendor'), ' / ', I::value($m['model'] ?: '-', $m, 'model')])]);
	$information->addRow([_('Hardware serial number'), I::value($m['serial_number'] ?? '-', $m, 'serial_number')]);
	$information->addRow([_('BIOS UUID'), I::value($m['bios_uuid'] ?? '-', $m, 'bios_uuid')]);
	$information->addRow([_('CPU model'), I::value($m['cpu_model'] ?: '-', $m, 'cpu_model')]);
	$information->addRow([_('ESXi version'), I::value($m['version'] ?: '-', $m, 'version')]);
	$information->addRow([_('Uptime'), I::value(VMwareFormatter::duration($m['uptime']), $m, 'uptime')]);
	$information->addRow([_('Notes'), trim((string) ($data['host']['inventory']['notes'] ?? '')) ?: '-']);
	$page->addItem((new CDiv([
		(new CTag('h4', true, _('Hypervisor information')))->addClass('vmware-monitoring-section-title'),
		$information
	]))->addClass('vmware-monitoring-section'));

	$utilization = static function ($value, string $field) use ($m) {
		if ($value === null) {
			return '-';
		}
		return (new CDiv([
			I::value(VMwareFormatter::percent($value), $m, $field),
			CapacityBar::render($value)
		]))->addClass('vmware-monitoring-capacity-cell');
	};
	$capacity = (new CTableInfo())->setHeader([
		_('Resource'), _('Total capacity'), _('Current use'), _('Utilization')
	]);
	$capacity->addRow([_('CPU'), VMwareFormatter::hertz($m['cpu_capacity']),
		I::value(VMwareFormatter::hertz($m['cpu_used']), $m, 'cpu_used'), $utilization($m['cpu_pct'], 'cpu_pct')]);
	$capacity->addRow([_('Memory'), I::value(VMwareFormatter::bytes($m['memory_total']), $m, 'memory_total'),
		I::value(VMwareFormatter::bytes($m['memory_used']), $m, 'memory_used'), $utilization($m['memory_pct'], 'memory_pct')]);
	$page->addItem((new CDiv([
		(new CTag('h4', true, _('Hypervisor capacity')))->addClass('vmware-monitoring-section-title'),
		$capacity
	]))->addClass('vmware-monitoring-section'));

	$runtime = (new CTableInfo())->setHeader([_('Runtime information'), _('Value')]);
	foreach ([
		[_('CPU cores / threads'), ($m['cpu_cores'] !== null && $m['cpu_threads'] !== null)
			? new CSpan([I::value($m['cpu_cores'], $m, 'cpu_cores'), ' / ', I::value($m['cpu_threads'], $m, 'cpu_threads')]) : '-'],
		[_('Memory ballooned'), I::value(VMwareFormatter::bytes($m['memory_ballooned']), $m, 'memory_ballooned')],
		[_('Network received / sent'), ($m['network_in'] !== null && $m['network_out'] !== null)
			? new CSpan([I::value(VMwareFormatter::bytes($m['network_in']).'/s', $m, 'network_in'), ' / ',
				I::value(VMwareFormatter::bytes($m['network_out']).'/s', $m, 'network_out')]) : '-'],
		[_('Network packets dropped (in / out)'), ($m['network_dropped_in'] !== null
			&& $m['network_dropped_out'] !== null) ? new CSpan([I::value($m['network_dropped_in'], $m, 'network_dropped_in'),
				' / ', I::value($m['network_dropped_out'], $m, 'network_dropped_out')]) : '-'],
		[_('Network errors (in / out)'), ($m['network_errors_in'] !== null
			&& $m['network_errors_out'] !== null) ? new CSpan([I::value($m['network_errors_in'], $m, 'network_errors_in'),
				' / ', I::value($m['network_errors_out'], $m, 'network_errors_out')]) : '-'],
		[_('Current / maximum power'), ($m['power'] !== null && $m['power_max'] !== null)
			? new CSpan([I::value(number_format((float) $m['power'], 1).' W', $m, 'power'), ' / ',
				I::value(number_format((float) $m['power_max'], 1).' W', $m, 'power_max')]) : '-'],
		[_('Datastore capacity / calculated free'),
			VMwareFormatter::bytes($data['detail']['datastore_totals']['capacity']).' / '.
			VMwareFormatter::bytes($data['detail']['datastore_totals']['free'])],
		[_('Lowest datastore free space'),
			VMwareFormatter::percent($data['detail']['datastore_totals']['lowest_free_pct'])]
	] as $row) {
		$runtime->addRow($row);
	}
	$page->addItem((new CDiv([
		(new CTag('h4', true, _('Hypervisor runtime')))->addClass('vmware-monitoring-section-title'),
		$runtime
	]))->addClass('vmware-monitoring-section'));

	$page->show();
	return;
}

if ($data['tab'] === 'datastores') {
	$datastores = (new CTableInfo())
		->setHeader([_('Datastore'), _('Type'), _('Capacity'), _('Free'), _('Read / write latency'),
			_('Read / write IOPS'), _('Multipaths')])
		->setNoDataMessage(_('No datastores are attached to this hypervisor.'));
	foreach ($data['detail']['datastores'] as $datastore) {
		$datastores->addRow([
			$datastore['name'], $datastore['type'] ?: '-', I::value(VMwareFormatter::bytes($datastore['total']), $datastore, 'total'),
			I::value(VMwareFormatter::percent($datastore['free_pct']), $datastore, 'free_pct'),
			new CSpan([I::value($datastore['read_latency'] ?? '-', $datastore, 'read_latency'), ' / ',
				I::value($datastore['write_latency'] ?? '-', $datastore, 'write_latency'), ' ms']),
			new CSpan([I::value($datastore['read_iops'] ?? '-', $datastore, 'read_iops'), ' / ',
				I::value($datastore['write_iops'] ?? '-', $datastore, 'write_iops')]),
			I::value($datastore['multipath'] ?? '-', $datastore, 'multipath')
		]);
	}
	$page->addItem((new CDiv([
		$datastores
	]))->addClass('vmware-monitoring-section'));
	$page->show();
	return;
}

if ($data['tab'] === 'vms') {
	$vms = $data['virtual_machines'];
	$table = (new CTableInfo())
		->setHeader([_('Virtual machine'), _('Notes'), _('Power'), _('State'), _('CPU'), _('Memory'),
			_('Storage'), _('VMware Tools'), _('Snapshots'), _('Uptime')])
		->setNoDataMessage(_('No discovered virtual machines are assigned to this hypervisor.'));
	foreach ($vms['rows'] as $vm) {
		$m = $vm['metrics'];
		$power = VMwareFormatter::vmPowerState($m['power']);
		$state = VMwareFormatter::vmState($m['state']);
		$table->addRow([
			(new CLinkAction($vm['name']))->setMenuPopup(CMenuPopupHelper::getHost($vm['hostid'])),
			trim((string) ($vm['inventory']['notes'] ?? '')) ?: '-',
			I::value((new CSpan($power['text']))->addClass('vmware-monitoring-state')
				->addClass('vmware-monitoring-state-'.$power['kind']), $m, 'power'),
			I::value((new CSpan($state['text']))->addClass('vmware-monitoring-state')
				->addClass('vmware-monitoring-state-'.$state['kind']), $m, 'state'),
			I::value(VMwareFormatter::percent($m['cpu']), $m, 'cpu'),
			new CSpan([$m['memory_pct'] !== null ? I::value(VMwareFormatter::percent($m['memory_pct']), $m, 'memory_pct') : '',
				$m['memory_pct'] !== null ? ' / ' : '', I::value(VMwareFormatter::bytes($m['memory_total']), $m, 'memory_total')]),
			I::value(VMwareFormatter::bytes($m['storage']), $m, 'storage'),
			I::value(VMwareFormatter::toolsStatus($m['tools']), $m, 'tools'),
			I::value($m['snapshots'] !== null ? (string) $m['snapshots'] : '-', $m, 'snapshots'),
			I::value(VMwareFormatter::duration($m['uptime']), $m, 'uptime')
		]);
	}
	$navigation = [];
	if ($vms['page'] > 1) {
		$navigation[] = new CLink(_('Previous'), $tab_url('vms')->setArgument('page', $vms['page'] - 1));
	}
	$navigation[] = new CSpan(_s('Page %1$d of %2$d — %3$d virtual machines',
		$vms['page'], $vms['pages'], $vms['total']));
	if ($vms['page'] < $vms['pages']) {
		$navigation[] = new CLink(_('Next'), $tab_url('vms')->setArgument('page', $vms['page'] + 1));
	}
	$page->addItem((new CDiv([
		$table,
		(new CDiv($navigation))->addClass('vmware-monitoring-view-more')
	]))->addClass('vmware-monitoring-section'));
	$page->show();
	return;
}

if ($data['tab'] === 'problems') {
	$page->addItem((new CDiv([
		(new CDiv(new WidgetProblems($data['problem_widget'])))->addClass('dashboard-widget-problems')
	]))->addClass('vmware-monitoring-section'));
	$page->show();
	return;
}

$page->addItem(
	Summary::strip([
		$make_stat('nodes', _('Sensors'), $data['counts']['total']),
		$make_stat('running', _('Green'), $data['counts']['1']),
		$make_stat('memory', _('Yellow'), $data['counts']['2']),
		$make_stat('stopped', _('Red'), $data['counts']['3']),
		$make_stat('total', _('Gray / no data'), $data['counts']['0']),
		$make_stat('stopped', _('Unsupported'), $data['counts']['unsupported'])
	])
);

$type_options = [];
foreach ($data['type_counts'] as $type => $count) {
	$type_options[] = [
		'label' => $type.' ('.$count.')',
		'value' => $type,
		'checked' => in_array($type, $data['filter']['types'], true)
	];
}

$filter = (new CForm('get'))
	->cleanItems()
	->setName('vmware_monitoring_sensor_filter')
	->addVar('action', 'vmware.monitoring.hypervisor')
	->addVar('hostid', $data['host']['hostid'])
	->addVar('tab', 'sensors')
	->addVar('filter_types_present', 1)
	->addItem(
		(new CDiv([
			new CLabel(_('Sensor or type'), 'filter_name'),
			(new CTextBox('filter_name', $data['filter']['name']))
				->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				->setAttribute('placeholder', _('Filter sensors...')),
			new CLabel(_('Status'), 'filter_status'),
			(new CSelect('filter_status'))
				->setValue($data['filter']['status'])
				->addOptions(CSelect::createOptionsFromArray([
					'all' => _('All'),
					'non_green' => _('Not green'),
					'1' => _('Green'),
					'2' => _('Yellow'),
					'3' => _('Red'),
					'0' => _('Gray / no data'),
					'unsupported' => _('Unsupported')
				])),
			new CLabel(_('Sensor types'), 'filter_types'),
			(new CCheckBoxList('filter_types'))
				->setOptions($type_options)
				->setColumns(min(4, max(1, count($type_options))))
				->setVertical(),
			new CSubmitButton(_('Apply'), 'filter_set', 1),
			(new CRedirectButton(_('Reset'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'vmware.monitoring.hypervisor')
					->setArgument('hostid', $data['host']['hostid'])
					->setArgument('tab', 'sensors')
			))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('vmware-monitoring-filterbar')
	);
$page->addItem($filter);

$sort_header = static function (string $label, string $field) use ($data): CLink {
	$order = $data['sort'] === $field && $data['sortorder'] === ZBX_SORT_UP
		? ZBX_SORT_DOWN
		: ZBX_SORT_UP;
	$url = (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.hypervisor')
		->setArgument('hostid', $data['host']['hostid'])
		->setArgument('tab', 'sensors')
		->setArgument('filter_name', $data['filter']['name'] !== '' ? $data['filter']['name'] : null)
		->setArgument('filter_status', $data['filter']['status'] !== 'all' ? $data['filter']['status'] : null)
		->setArgument('filter_types', $data['filter']['types'])
		->setArgument('filter_types_present', 1)
		->setArgument('sort', $field)
		->setArgument('sortorder', $order);

	return new CLink($label, $url);
};

$make_sensor_row = static function (array $sensor) use ($data): array {
	$state = VMwareFormatter::hypervisorHealth($sensor['value']);
	$status = (new CSpan($state['text']))
		->addClass('vmware-monitoring-state')
		->addClass('vmware-monitoring-state-'.$state['kind']);
	if ($sensor['status_summary'] !== '') {
		$status->setTitle($sensor['status_summary']);
	}
	$problem_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'problem.view')
		->setArgument('hostids', [$data['host']['hostid']])
		->setArgument('filter_set', 1);

	$problem_items = [];
	foreach (array_slice($sensor['problems'], 0, 2) as $problem) {
		$problem_items[] = (new CSpan($problem['name']))
			->addClass(CSeverityHelper::getStatusStyle($problem['severity']));
	}
	if (count($sensor['problems']) > 2) {
		$problem_items[] = new CSpan(_s('+%1$d more', count($sensor['problems']) - 2));
	}
	$problems = $problem_items
		? (new CLink(
			(new CDiv($problem_items))->addClass('vmware-monitoring-sensor-problems'),
			$problem_url
		))->setTitle(implode("\n", array_column($sensor['problems'], 'name')))
		: (new CSpan(_('None')))->addClass(ZBX_STYLE_GREEN);

	$collection = $sensor['state'] === ITEM_STATE_NOTSUPPORTED
		? (new CSpan(_('Unsupported')))
			->addClass(ZBX_STYLE_RED)
			->setTitle($sensor['error'] !== '' ? $sensor['error'] : _('Item is not supported.'))
		: (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN);

	$row_state = $sensor['state'] === ITEM_STATE_NOTSUPPORTED
		? 'unsupported'
		: ($sensor['value'] === null ? '0' : (string) $sensor['value']);
	$reading = new CSpan(VMwareFormatter::sensorReading($sensor['reading']));
	if (VMwareFormatter::sensorReadingUsesDiscreteUnit($sensor['reading'])) {
		$reading
			->addClass(ZBX_STYLE_GREY)
			->setTitle(_('Discrete ESXi sensor value; no physical unit is provided.'));
	}

	return [[
		I::link($sensor['name'], $sensor['itemid']),
		trim($sensor['type']) !== '' ? trim($sensor['type']) : _('Other'),
		$reading,
		I::link($status, $sensor['itemid']),
		$sensor['lastclock'] > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $sensor['lastclock']) : '-',
		$collection,
		$problems
	], $row_state !== '1' ? 'vmware-monitoring-sensor-state-'.$row_state : null];
};

$table = (new CTableInfo())
	->setHeader([
		$sort_header(_('Sensor'), 'name'),
		$sort_header(_('Type'), 'type'),
		_('Current reading'),
		$sort_header(_('VMware status'), 'status'),
		$sort_header(_('Last update'), 'lastclock'),
		_('Collection'),
		$sort_header(_('Related problems'), 'problems')
	])
	->setNoDataMessage(_(
		'No sensors match the selected filters. Enable {$VMWARE.HV.SENSOR.DISCOVERY} if no sensors were discovered.'
	));
foreach ($data['sensors'] as $sensor) {
	[$row, $row_class] = $make_sensor_row($sensor);
	$table->addRow($row, $row_class);
}

$page
	->addItem(
		(new CDiv([
			$table,
			$data['paging']
		]))->addClass('vmware-monitoring-section')
	)
	->show();
