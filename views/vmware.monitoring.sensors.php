<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\VMwareFormatter;

$this->includeJsFile('vmware.monitoring.sensors.js.php');

$page = (new CHtmlPage())
	->setTitle(_('VMware sensors'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$page->addItem(
	(new CDiv(
		(new CDiv([
			new CLink(_('VMware'), (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')),
			(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
			(new CSpan($data['host']['name']))->addClass('vmware-monitoring-breadcrumb-current'),
			(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
			(new CSpan(_('Sensors')))->addClass('vmware-monitoring-breadcrumb-current')
		]))->addClass('vmware-monitoring-breadcrumb')
	))->addClass('vmware-monitoring-topbar')
);

$make_stat = static function (string $modifier, string $label, int $value): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('vmware-monitoring-statseg-label'),
		(new CDiv(
			(new CSpan((string) $value))->addClass('vmware-monitoring-card-value')
		))->addClass('vmware-monitoring-statseg-figure')
	]))
		->addClass('vmware-monitoring-statseg')
		->addClass('vmware-monitoring-card-'.$modifier);
};

$page->addItem(
	(new CDiv([
		$make_stat('nodes', _('Sensors'), $data['counts']['total']),
		$make_stat('running', _('Green'), $data['counts']['1']),
		$make_stat('memory', _('Yellow'), $data['counts']['2']),
		$make_stat('stopped', _('Red'), $data['counts']['3']),
		$make_stat('total', _('Gray / no data'), $data['counts']['0']),
		$make_stat('stopped', _('Unsupported'), $data['counts']['unsupported'])
	]))->addClass('vmware-monitoring-statstrip')
);

$filter = (new CForm('get'))
	->cleanItems()
	->setName('vmware_monitoring_sensor_filter')
	->addVar('action', 'vmware.monitoring.sensors')
	->addVar('hostid', $data['host']['hostid'])
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
			new CSubmitButton(_('Apply'), 'filter_set', 1),
			(new CRedirectButton(_('Reset'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'vmware.monitoring.sensors')
					->setArgument('hostid', $data['host']['hostid'])
			))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('vmware-monitoring-filterbar')
	);
$page->addItem($filter);

$sort_header = static function (string $label, string $field) use ($data): CLink {
	$order = $data['sort'] === $field && $data['sortorder'] === ZBX_SORT_UP
		? ZBX_SORT_DOWN
		: ZBX_SORT_UP;
	$url = (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.sensors')
		->setArgument('hostid', $data['host']['hostid'])
		->setArgument('filter_name', $data['filter']['name'] !== '' ? $data['filter']['name'] : null)
		->setArgument('filter_status', $data['filter']['status'] !== 'all' ? $data['filter']['status'] : null)
		->setArgument('sort', $field)
		->setArgument('sortorder', $order);

	return new CLink($label, $url);
};

$make_sensor_row = static function (array $sensor) use ($data): array {
	$state = VMwareFormatter::hypervisorHealth($sensor['value']);
	$history_url = (new CUrl('history.php'))
		->setArgument('action', HISTORY_VALUES)
		->setArgument('itemids', [$sensor['itemid']]);
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

	return [[
		(new CLink($sensor['name'], $history_url))->addClass('vmware-monitoring-name'),
		(new CSpan($state['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$state['kind']),
		$sensor['lastclock'] > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $sensor['lastclock']) : '-',
		$collection,
		$problems
	], $row_state !== '1' ? 'vmware-monitoring-sensor-state-'.$row_state : null];
};

$groups = [];
foreach ($data['sensors'] as $sensor) {
	$type = trim($sensor['type']) !== '' ? trim($sensor['type']) : _('Other');
	$groups[$type][] = $sensor;
}

$group_content = [];
foreach ($groups as $group_index => $sensors) {
	$summary = $data['group_summaries'][$group_index] ?? ['count' => count($sensors), 'state' => '0'];
	$group_state = match ($summary['state']) {
		'1' => ['text' => _('Green'), 'kind' => 'running'],
		'2' => ['text' => _('Yellow'), 'kind' => 'paused'],
		'3' => ['text' => _('Red'), 'kind' => 'stopped'],
		'unsupported' => ['text' => _('Unsupported'), 'kind' => 'stopped'],
		default => ['text' => _('Gray'), 'kind' => 'unknown']
	};

	$open = $group_state['kind'] !== 'running'
		|| $data['filter']['name'] !== ''
		|| $data['filter']['status'] !== 'all';
	$body_id = 'vmware-monitoring-sensor-group-'.count($group_content);
	$table = (new CTableInfo())
		->addClass('vmware-monitoring-sensors-table')
		->setHeader([
			$sort_header(_('Sensor'), 'name'),
			$sort_header(_('VMware status'), 'status'),
			$sort_header(_('Last update'), 'lastclock'),
			_('Collection'),
			$sort_header(_('Related problems'), 'problems')
		]);
	foreach ($sensors as $sensor) {
		[$row, $row_class] = $make_sensor_row($sensor);
		$table->addRow($row, $row_class);
	}

	$header = (new CTag('button', true, [
		(new CSpan())->addClass('vmware-monitoring-graphgroup-caret'),
		(new CSpan($group_index))->addClass('vmware-monitoring-graphgroup-name'),
		(new CSpan(_n('%1$d sensor', '%1$d sensors', $summary['count'])))
			->addClass('vmware-monitoring-graphgroup-count'),
		(new CSpan($group_state['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$group_state['kind'])
	]))
		->addClass('vmware-monitoring-graphgroup-head')
		->setAttribute('type', 'button')
		->setAttribute('data-vmware-monitoring-sensor-group', '1')
		->setAttribute('aria-controls', $body_id)
		->setAttribute('aria-expanded', $open ? 'true' : 'false');
	$body = (new CDiv($table))
		->setId($body_id)
		->addClass('vmware-monitoring-graphgroup-body');
	if (!$open) {
		$body->setAttribute('hidden', 'hidden');
	}

	$group_content[] = (new CDiv([$header, $body]))
		->addClass('vmware-monitoring-graphgroup')
		->addClass('vmware-monitoring-sensor-group')
		->addClass($open ? 'vmware-monitoring-graphgroup-open' : null);
}

if (!$group_content) {
	$group_content[] = (new CTableInfo())->setNoDataMessage(
		_('No discovered sensors found. Enable {$VMWARE.HV.SENSOR.DISCOVERY} on this hypervisor and wait for discovery.')
	);
}

$page
	->addItem(
		(new CDiv([
			(new CTag('h4', true, _('Discovered hypervisor sensors')))
				->addClass('vmware-monitoring-section-title'),
			...$group_content,
			$data['paging']
		]))->addClass('vmware-monitoring-section')
	)
	->show();

(new CScriptTag('vmware_monitoring_sensors.init();'))->setOnDocumentReady()->show();
