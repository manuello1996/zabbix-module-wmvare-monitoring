<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\VMwareFormatter;
use Modules\VMwareMonitoring\Includes\VMwareBreadcrumb;
use Widgets\Problems\Includes\WidgetProblems;

$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');
$this->includeJsFile('vmware.monitoring.list.js.php');
$this->includeJsFile('vmware.monitoring.hostmenu.js.php');

$makeStat = static function (string $modifier, string $label, $value): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('vmware-monitoring-statseg-label'),
		(new CDiv((new CSpan((string) $value))->addClass('vmware-monitoring-card-value')))
			->addClass('vmware-monitoring-statseg-figure')
	]))
		->addClass('vmware-monitoring-statseg')
		->addClass('vmware-monitoring-card-'.$modifier);
};

$problemBadge = static function (array $severities, array $hostids) {
	if (!$severities) {
		return (new CSpan(_('None')))->addClass(ZBX_STYLE_GREEN);
	}

	$content = [];
	krsort($severities);
	foreach ($severities as $severity => $count) {
		$content[] = (new CSpan((string) $count))
			->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
			->addClass(CSeverityHelper::getStatusStyle((int) $severity))
			->setTitle(CSeverityHelper::getName((int) $severity));
	}

	return (new CLink(
		(new CDiv($content))->addClass(ZBX_STYLE_PROBLEM_ICON_LIST),
		(new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('hostids', $hostids)
			->setArgument('filter_set', 1)
	))->addClass(ZBX_STYLE_PROBLEM_ICON_LINK);
};

$page = (new CHtmlPage())
	->setTitle(_('VMware vCenters'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$page->setControls(
	(new CForm('get'))
		->cleanItems()
		->setId('vmware-monitoring-refresh-vcenters')
		->addVar('action', 'vmware.monitoring.list')
		->addVar('tab', $data['tab'])
		->addVar('sort', $data['sort'])
		->addVar('sortorder', $data['sortorder'])
		->addItem(new CSubmitButton(_('Refresh vCenters'), 'force_vcenter_refresh', 1))
);

$page->addItem(
	(new CDiv(
		VMwareBreadcrumb::make([
			(new CSpan(_('vCenters')))->addClass('vmware-monitoring-breadcrumb-current')
		])
	))->addClass('vmware-monitoring-topbar')
);

$filter_form = (new CFormGrid())
	->addClass('vmware-monitoring-filter-row')
	->addItem([
		new CLabel(_('Host groups'), 'filter_groupids__ms'),
		new CFormField(
			(new CMultiSelect([
				'name' => 'filter_groupids[]',
				'object_name' => 'hostGroup',
				'data' => $data['filter']['groups'],
				'popup' => ['parameters' => [
					'srctbl' => 'host_groups',
					'srcfld1' => 'groupid',
					'dstfrm' => 'zbx_filter',
					'dstfld1' => 'filter_groupids_',
					'with_monitored_items' => true
				]]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('vCenters'), 'filter_hostids__ms'),
		new CFormField(
			(new CMultiSelect([
				'name' => 'filter_hostids[]',
				'object_name' => 'hosts',
				'data' => $data['filter']['hosts'],
				'popup' => ['parameters' => [
					'srctbl' => 'hosts',
					'srcfld1' => 'hostid',
					'dstfrm' => 'zbx_filter',
					'dstfld1' => 'filter_hostids_',
					'with_monitored_items' => true
				]]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
		)
	]);

$page->addItem(
	(new CFilter())
		->setResetUrl(new CUrl('zabbix.php?action=vmware.monitoring.list'))
		->setProfile('web.vmware.monitoring.list.filter')
		->addVar('action', 'vmware.monitoring.list')
		->addVar('tab', $data['tab'])
		->addVar('sort', $data['sort'])
		->addVar('sortorder', $data['sortorder'])
		->addFilterTab(_('Filter'), [$filter_form])
);

$stats = (new CDiv([
	(new CDiv([
		$makeStat('nodes', _('vCenters'), $data['totals']['vcenters']),
		$makeStat('total', _('Hypervisors'), $data['totals']['hypervisors']),
		$makeStat('total', _('Virtual machines'), $data['totals']['vms']),
		$makeStat('memory', _('Unique datastores'), $data['totals']['datastores'])
	]))->addClass('vmware-monitoring-statstrip')
]))->addClass('vmware-monitoring-section');
$summary = (new CDiv([$stats]))
	->setId('vmware-monitoring-list-summary');
$page->addItem($summary);

$tab_url = static function (string $tab): CUrl {
	return (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.list')
		->setArgument('tab', $tab === 'vcenters' ? null : $tab);
};
$tabs = [];
foreach ([['vcenters', _('vCenters')], ['issues', _('Issues')], ['problems', _('Problems')]] as [$key, $label]) {
	$active = $key === $data['tab'];
	$tabs[] = (new CLink($label, $tab_url($key)))
		->addClass('vmware-monitoring-tab')
		->addClass($active ? 'vmware-monitoring-tab-active' : null)
		->setAttribute('data-vmware-monitoring-list-tab', $key)
		->setAttribute('role', 'tab')
		->setAttribute('aria-selected', $active ? 'true' : 'false');
}
$page->addItem((new CDiv($tabs))->addClass('vmware-monitoring-tabs')->setAttribute('role', 'tablist'));

if ($data['tab'] === 'problems') {
	$page
		->addItem(
			(new CDiv(
				(new CDiv([
				(new CDiv(new WidgetProblems($data['problem_widget'])))
					->addClass('dashboard-widget-problems')
				]))->addClass('vmware-monitoring-section')
			))->setId('vmware-monitoring-tab-content')
		)
		->show();

	(new CScriptTag('vmware_monitoring_list.init('.json_encode([
		'refresh' => $data['refresh_interval'],
		'tab' => $data['tab']
	]).');'))->setOnDocumentReady()->show();
	return;
}

if ($data['tab'] === 'issues') {
	$tab_url = static function (string $sort, string $sortorder) use ($data): CUrl {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'vmware.monitoring.list')
			->setArgument('tab', $data['tab'])
			->setArgument('sort', $sort)
			->setArgument('sortorder', $sortorder);
	};
	$sort_header = static function (string $label, string $field) use ($data, $tab_url): CLink {
		$active = $data['sort'] === $field;
		$order = $active && $data['sortorder'] === ZBX_SORT_UP ? ZBX_SORT_DOWN : ZBX_SORT_UP;
		$arrow = new CSpan();
		if ($active) {
			$arrow->addClass($data['sortorder'] === ZBX_SORT_UP ? 'arrow-up' : 'arrow-down');
		}

		return (new CLink([$label, $arrow->addClass('vmware-monitoring-sort-arrow')], $tab_url($field, $order)))
			->addClass('vmware-monitoring-sort');
	};

	$rows = $data['issues'];
	$table = (new CTableInfo())
		->setId('vmware-monitoring-issues-table')
		->setHeader([
			$sort_header(_('vCenter'), 'vcenter'),
			$sort_header(_('Issue'), 'issue'),
			$sort_header(_('Status'), 'status'),
			$sort_header(_('Severity'), 'severity'),
			$sort_header(_('Last update'), 'lastupdate')
		])
		->setNoDataMessage(_('No active vCenter issues found.'));
	foreach ($rows as $row) {
		$severity = $row['severity'] === null
			? '-'
			: (new CSpan(CSeverityHelper::getName((int) $row['severity'])))
				->addClass(CSeverityHelper::getStatusStyle((int) $row['severity']));
		$vcenter_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'vmware.monitoring.view')
			->setArgument('filter_hostid', [$row['hostid']])
			->setArgument('filter_set', 1);
		$vcenter_url->setArgument('tab', 'alarms');
		$table->addRow([
			(new CLink($row['vcenter'], $vcenter_url))->addClass('vmware-monitoring-name'),
			(new CSpan($row['name']))->addClass('vmware-monitoring-name'),
			(new CSpan(_('Active')))->addClass('vmware-monitoring-state-stopped'),
			$severity,
			(int) $row['lastclock'] > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $row['lastclock']) : '-'
		]);
	}

	$page
		->addItem(
			(new CDiv([
				(new CDiv([
					$table,
					$data['issues_paging']
				]))->addClass('vmware-monitoring-section')
			]))->setId('vmware-monitoring-tab-content')
		)
		->show();

	(new CScriptTag('vmware_monitoring_list.init('.json_encode([
		'refresh' => $data['refresh_interval'],
		'tab' => $data['tab']
	]).');'))->setOnDocumentReady()->show();
	return;
}

$table = (new CTableInfo())
	->setId('vmware-monitoring-vcenters-table')
	->setHeader([
		_('vCenter'),
		_('Health'),
		_('Problems'),
		_('Version'),
		_('Hypervisors'),
		_('Virtual machines'),
		_('Unique datastores'),
		_('Notes')
	])
	->setNoDataMessage(_('No vCenter hosts found. Link the official "VMware" template to a monitored host.'));

$previous_location_path = [];
foreach ($data['vcenters'] as $vcenter) {
	$location_path = $vcenter['location_path'] ?? [];
	if ($location_path) {
		$common_parts = 0;
		$maximum_common_parts = min(count($previous_location_path), count($location_path));
		while ($common_parts < $maximum_common_parts
				&& $previous_location_path[$common_parts] === $location_path[$common_parts]) {
			$common_parts++;
		}

		for ($depth = $common_parts; $depth < count($location_path); $depth++) {
			$location_group = (new CDiv(
				(new CSpan($location_path[$depth]))->addClass('vmware-monitoring-location-name')
			))
				->addClass('vmware-monitoring-location-group')
				->setAttribute('style', '--vmware-monitoring-location-depth: '.$depth);
			$table->addRow([
				(new CCol($location_group))->setColSpan(8)
			], 'vmware-monitoring-location-row');
		}
		$previous_location_path = $location_path;
	}
	else {
		$previous_location_path = [];
	}

	$url = (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.view')
		->setArgument('filter_hostid', [$vcenter['hostid']])
		->setArgument('filter_set', 1);
	$health = VMwareFormatter::vcenterHealth($vcenter['metrics']['health']);

	$name_cell = (new CDiv([
			(new CSpan())->addClass('vmware-monitoring-container-icon'),
			(new CLink($vcenter['name'], $url))
				->addClass('vmware-monitoring-name')
				->setAttribute('data-vmware-monitoring-host-menu', $vcenter['hostid'])
		]))->addClass('vmware-monitoring-name-cell');
	if ($location_path) {
		$name_cell
			->addClass('vmware-monitoring-location-vcenter')
			->setAttribute('style', '--vmware-monitoring-location-depth: '.count($location_path));
	}

	$table->addRow([
		$name_cell,
		(new CSpan($health['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$health['kind']),
		$problemBadge($vcenter['problems'], $vcenter['problem_hostids']),
		$vcenter['metrics']['version'] ?? '-',
		(string) $vcenter['hypervisors'],
		(string) $vcenter['vms'],
		(string) $vcenter['datastores'],
		$vcenter['inventory']['notes'] ?? ''
	]);
}

$page
	->addItem(
		(new CDiv([
			(new CDiv([
				(new CTag('h4', true, [
					(new CSpan())->setId('vmware-monitoring-refresh-status')->addClass('vmware-monitoring-refresh-status')
				]))->addClass('vmware-monitoring-section-title'),
				$table,
				$data['paging']
			]))->addClass('vmware-monitoring-section')
		]))->setId('vmware-monitoring-tab-content')
	)
	->show();

(new CScriptTag('vmware_monitoring_list.init('.json_encode([
	'refresh' => $data['refresh_interval'],
	'tab' => $data['tab']
]).');'))->setOnDocumentReady()->show();
