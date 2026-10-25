<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\VMwareFormatter;
use Modules\VMwareMonitoring\Includes\VMwareLocationGroups;
use Modules\VMwareMonitoring\Includes\VMwareItemMenu as I;
use Widgets\Problems\Includes\WidgetProblems;

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

$tab_url = static function (string $sort, string $sortorder) use ($data): CUrl {
	return (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.list')
		->setArgument('tab', $data['tab'])
		->setArgument('sort', $sort)
		->setArgument('sortorder', $sortorder);
};
$sort_header = static function (string $label, string $field) use ($data, $tab_url): CLink {
	$active = ($data['sort'] ?? 'vcenter') === $field;
	$order = $active && ($data['sortorder'] ?? ZBX_SORT_UP) === ZBX_SORT_UP ? ZBX_SORT_DOWN : ZBX_SORT_UP;
	$arrow = new CSpan();
	if ($active) {
		$arrow->addClass(($data['sortorder'] ?? ZBX_SORT_UP) === ZBX_SORT_UP ? 'arrow-up' : 'arrow-down');
	}

	return (new CLink([$label, $arrow->addClass('vmware-monitoring-sort-arrow')], $tab_url($field, $order)))
		->addClass('vmware-monitoring-sort');
};


if ($data['tab'] === 'problems') {
	$problem_content = [
		(new CDiv(new WidgetProblems($data['problem_widget'])))
			->addClass('dashboard-widget-problems')
	];
	if ($data['problem_widget']['has_more'] ?? false) {
		$problem_content[] = (new CDiv(
			new CLink(
				_('View all problems'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'problem.view')
			)
		))->addClass('vmware-monitoring-pager');
	}
	(new CDiv($problem_content))->addClass('vmware-monitoring-section')->show();
	return;
}

if ($data['tab'] === 'issues') {

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
			(new CLink($row['vcenter'], $vcenter_url))->addClass('vmware-monitoring-issue-link'),
			(new CSpan($row['name']))->addClass('vmware-monitoring-issue-text'),
			(new CSpan(_('Active')))->addClass('vmware-monitoring-issue-status'),
			$severity,
			(int) $row['lastclock'] > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $row['lastclock']) : '-'
		]);
	}

	$issue_content = [$table, $data['issues_paging']];
	if ($data['issues_has_more']) {
		$issue_content[] = (new CDiv(
			new CLink(
				_('View all problems'),
				(new CUrl('zabbix.php'))->setArgument('action', 'problem.view')
			)
		))->addClass('vmware-monitoring-pager');
	}

	(new CDiv($issue_content))->addClass('vmware-monitoring-section')->show();
	return;
}

$table = (new CTableInfo())
	->setId('vmware-monitoring-vcenters-table')
	->setHeader([
		$sort_header(_('vCenter'), 'vcenter'),
		$sort_header(_('Health'), 'health'),
		$sort_header(_('Problems'), 'problems'),
		$sort_header(_('Version'), 'version'),
		$sort_header(_('Hypervisors'), 'hypervisors'),
		$sort_header(_('Virtual machines'), 'vms'),
		$sort_header(_('Unique datastores'), 'datastores'),
		$sort_header(_('Notes'), 'notes')
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
			$key = VMwareLocationGroups::key(array_slice($location_path, 0, $depth + 1));
			$group = $data['location_groups'][$key];
			$ancestors = [];
			for ($ancestor = 1; $ancestor <= $depth; $ancestor++) {
				$ancestors[] = VMwareLocationGroups::key(array_slice($location_path, 0, $ancestor));
			}
			$location_group = (new CDiv([
				(new CButton(null, [
					(new CSpan())->addClass('vmware-monitoring-location-chevron')->setAttribute('aria-hidden', 'true'),
					(new CSpan($location_path[$depth]))->addClass('vmware-monitoring-location-name')
				]))
					->addClass('vmware-monitoring-location-toggle')
					->setAttribute('data-vmware-location-toggle', $key)
					->setAttribute('aria-expanded', $group['collapsed'] ? 'false' : 'true'),
				(new CSpan(sprintf(_('%1$s vCenters'), $group['vcenters'])))
					->addClass('vmware-monitoring-location-count')
			]))
				->addClass('vmware-monitoring-location-group')
				->setAttribute('style', '--vmware-monitoring-location-depth: '.$depth);
			$group_row = (new CRow([
				(new CCol($location_group))->setColSpan(2),
				$problemBadge($group['problems'], $group['problem_hostids']),
				'', (string) $group['hypervisors'], (string) $group['vms'], (string) $group['datastores'], ''
			]))
				->addClass('vmware-monitoring-location-row')
				->setAttribute('data-vmware-location-key', $key)
				->setAttribute('data-vmware-location-ancestors', json_encode($ancestors));
			foreach ($ancestors as $ancestor_key) {
				if ($data['location_groups'][$ancestor_key]['collapsed']) {
					$group_row->setAttribute('hidden', 'hidden');
					break;
				}
			}
			$table->addRow($group_row);
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

	$vcenter_row = new CRow([
		$name_cell,
		I::value((new CSpan($health['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$health['kind']), $vcenter['metrics'], 'health'),
		$problemBadge($vcenter['problems'], $vcenter['problem_hostids']),
		I::value($vcenter['metrics']['version'] ?? '-', $vcenter['metrics'], 'version'),
		(string) $vcenter['hypervisors'],
		(string) $vcenter['vms'],
		(string) $vcenter['datastores'],
		$vcenter['inventory']['notes'] ?? ''
	]);
	$ancestors = [];
	for ($depth = 1; $depth <= count($location_path); $depth++) {
		$ancestors[] = VMwareLocationGroups::key(array_slice($location_path, 0, $depth));
	}
	$vcenter_row->setAttribute('data-vmware-location-ancestors', json_encode($ancestors));
	foreach ($ancestors as $key) {
		if ($data['location_groups'][$key]['collapsed']) {
			$vcenter_row->setAttribute('hidden', 'hidden');
			break;
		}
	}
	$table->addRow($vcenter_row);
}

(new CDiv([
	$table,
	(new CDiv())->setId('vmware-monitoring-location-save-status')
		->setAttribute('role', 'status')->addClass(ZBX_STYLE_RED),
	$data['paging']
]))->addClass('vmware-monitoring-section')->show();
