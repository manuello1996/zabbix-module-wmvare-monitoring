<?php declare(strict_types = 0);
use Modules\VMwareMonitoring\Includes\Common\ProblemBadges;
use Modules\VMwareMonitoring\Includes\Common\SortableHeader;

use Modules\VMwareMonitoring\Includes\VMwareFormatter;
use Modules\VMwareMonitoring\Includes\VMwareLocationGroups;
use Modules\VMwareMonitoring\Includes\Common\LocationRows;
use Modules\VMwareMonitoring\Includes\VMwareItemMenu as I;
use Widgets\Problems\Includes\WidgetProblems;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

$problemBadge = static function (array $severities, array $hostids) {
	return ProblemBadges::link($severities, $hostids);
};

$tab_url = static function (string $sort, string $sortorder) use ($data): CUrl {
	return (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.list')
		->setArgument('tab', $data['tab'])
		->setArgument('sort', $sort)
		->setArgument('sortorder', $sortorder);
};
$sort_header = static function (string $label, string $field) use ($data, $tab_url): CLink {
	$field_sort = $data['sort'] ?? 'vcenter';
	$order = $data['sortorder'] ?? ZBX_SORT_UP;
	$next = $field_sort === $field && $order === ZBX_SORT_UP ? ZBX_SORT_DOWN : ZBX_SORT_UP;
	return SortableHeader::render($label, $field, $field_sort, $order, ['url' => $tab_url($field, $next)]);
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
		))->addClass('vmware-monitoring-view-more');
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
		))->addClass('vmware-monitoring-view-more');
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

$headers = VMwareLocationGroups::headers($data['vcenters']);
foreach ($data['vcenters'] as $vcenter) {
	$location_path = $vcenter['location_path'] ?? [];
	$branches = VMwareLocationGroups::branches($location_path);
	foreach ($headers[(string) $vcenter['hostid']] ?? [] as $key) {
		$group = $data['location_groups'][$key];
		$table->addRow(LocationRows::header($group + $branches[$key],
			sprintf(_('%1$s vCenters'), $group['vcenters']), [
				$problemBadge($group['problems'], $group['problem_hostids']), '',
				(string) $group['hypervisors'], (string) $group['vms'], (string) $group['datastores'], ''
			], 2, $data['location_groups']));
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
	$table->addRow(LocationRows::withAncestors($vcenter_row,
		VMwareLocationGroups::ancestorKeys($location_path), $data['location_groups']));
}

(new CDiv([
	$table,
	(new CDiv())->setId('vmware-monitoring-location-save-status')
		->setAttribute('role', 'status')->addClass(ZBX_STYLE_RED),
	$data['paging']
]))->addClass('vmware-monitoring-section')->show();
