<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\VMwareFormatter;
use Modules\VMwareMonitoring\Includes\VMwareTabRenderer;

$this->includeJsFile('vmware.monitoring.hostmenu.js.php');

$cluster = $data['cluster'];
$vcenter_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'vmware.monitoring.view')
	->setArgument('filter_hostid', [$data['vcenter']['hostid']])
	->setArgument('filter_set', 1);
$clusters_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'vmware.monitoring.view')
	->setArgument('filter_hostid', [$data['vcenter']['hostid']])
	->setArgument('filter_set', 1)
	->setArgument('tab', 'clusters');
$hypervisors_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'vmware.monitoring.view')
	->setArgument('filter_hostid', [$data['vcenter']['hostid']])
	->setArgument('filter_set', 1)
	->setArgument('tab', 'hypervisors')
	->setArgument('search', $cluster['name']);
$vms_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'vmware.monitoring.view')
	->setArgument('filter_hostid', [$data['vcenter']['hostid']])
	->setArgument('filter_set', 1)
	->setArgument('tab', 'vms')
	->setArgument('search', $cluster['name']);

$page = (new CHtmlPage())
	->setTitle(_('VMware cluster'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$cluster_state = $cluster['is_standalone']
	? ['text' => _('Not applicable'), 'kind' => 'unknown']
	: VMwareFormatter::hypervisorHealth($cluster['status']);
$page->addItem(
	(new CDiv([
		(new CDiv([
			new CLink(_('vCenters'), (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')),
			(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
			new CLink($data['vcenter']['name'], $vcenter_url),
			(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
			new CLink(_('Clusters'), $clusters_url),
			(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
			(new CSpan($cluster['name']))->addClass('vmware-monitoring-breadcrumb-current')
		]))->addClass('vmware-monitoring-breadcrumb'),
		(new CSpan($cluster_state['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$cluster_state['kind'])
	]))->addClass('vmware-monitoring-topbar')
);

$make_stat = static function (string $modifier, string $label, $value): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('vmware-monitoring-statseg-label'),
		(new CDiv($value))->addClass('vmware-monitoring-statseg-figure')
	]))
		->addClass('vmware-monitoring-statseg')
		->addClass('vmware-monitoring-card-'.$modifier);
};

$problem_value = (string) $cluster['totals']['problems'];
if ($cluster['totals']['problems'] > 0) {
	$problem_value = new CLink(
		(string) $cluster['totals']['problems'],
		(new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('hostids', $cluster['problem_hostids'])
			->setArgument('filter_set', 1)
	);
}

$page->addItem(
	(new CDiv([
		$make_stat('nodes', _('Hypervisors'),
			(new CLink((string) $cluster['hypervisor_count'], $hypervisors_url))
				->addClass('vmware-monitoring-card-value')
		),
		$make_stat('total', _('Connected'),
			(new CSpan($cluster['totals']['connection_known'] > 0
				? $cluster['totals']['connected'].' / '.$cluster['hypervisor_count']
				: '-'))->addClass('vmware-monitoring-card-value')
		),
		$make_stat('total', _('Virtual machines'),
			(new CSpan((string) $cluster['totals']['vm_count']))->addClass('vmware-monitoring-card-value')
		),
		$make_stat('total', _('Discovered VMs'),
			(new CLink((string) $cluster['discovered_vms'], $vms_url))->addClass('vmware-monitoring-card-value')
		),
		$make_stat($cluster['totals']['problems'] > 0 ? 'stopped' : 'total', _('Active problems'),
			(new CSpan($problem_value))->addClass('vmware-monitoring-card-value')
		)
	]))->addClass('vmware-monitoring-statstrip')
);

$utilization = static function ($value) {
	if ($value === null) {
		return '-';
	}
	$percent = max(0, min(100, (float) $value));
	return (new CDiv([
		new CSpan(VMwareFormatter::percent($value)),
		(new CDiv(
			(new CDiv())->addClass('vmware-monitoring-progress-fill')
				->addClass($percent >= 90 ? 'vmware-monitoring-progress-crit' : null)
				->setAttribute('style', 'width: '.number_format($percent, 2, '.', '').'%')
		))->addClass('vmware-monitoring-progress')
	]))->addClass('vmware-monitoring-capacity-cell');
};

$capacity_table = (new CTableInfo())->setHeader([
	_('Resource'), _('Total capacity'), _('Current use'), _('Utilization')
]);
$capacity_table->addRow([
	_('CPU'),
	$cluster['complete']['cpu_capacity'] ? VMwareFormatter::hertz($cluster['totals']['cpu_capacity']) : '-',
	$cluster['complete']['cpu_used'] ? VMwareFormatter::hertz($cluster['totals']['cpu_used']) : '-',
	$utilization($cluster['capacity']['cpu_utilization'])
]);
$capacity_table->addRow([
	_('Memory'),
	$cluster['complete']['memory_total'] ? VMwareFormatter::bytes($cluster['totals']['memory_total']) : '-',
	$cluster['complete']['memory_used'] ? VMwareFormatter::bytes($cluster['totals']['memory_used']) : '-',
	$utilization($cluster['capacity']['memory_utilization'])
]);

$page->addItem(
	(new CDiv([
		(new CTag('h4', true, _('Cluster capacity')))->addClass('vmware-monitoring-section-title'),
		$capacity_table
	]))->addClass('vmware-monitoring-section')
);

$native_value = static function (string $field, array $item): string {
	$value = $item['value'];
	if (in_array($field, [
		'summary.totalCpu',
		'summary.effectiveCpu',
		'perf:"clusterServices/effectivecpu[average]"'
	], true)) {
		return number_format((float) $value / 1000, 2).' GHz';
	}
	if ($field === 'tags') {
		$decoded = json_decode($value, true);
		if (is_array($decoded)) {
			$labels = [];
			foreach ($decoded as $tag) {
				if (is_array($tag)) {
					$name = (string) ($tag['name'] ?? $tag['tag'] ?? $tag['value'] ?? '');
					$category = (string) ($tag['category'] ?? '');
					if ($name !== '') {
						$labels[] = $category !== '' ? $category.': '.$name : $name;
					}
				}
			}
			return $labels ? implode(', ', $labels) : ($decoded ? json_encode($decoded) : '-');
		}
	}
	if (($item['units'] ?? '') === 'B') {
		return VMwareFormatter::bytes($value);
	}
	if (($item['units'] ?? '') === '%') {
		return VMwareFormatter::percent($value);
	}
	return $value.($item['units'] !== '' ? ' '.$item['units'] : '');
};

$native_table = (new CTableInfo())
	->setHeader([_('VMware cluster property'), _('Value'), _('Last update')]);
foreach ($cluster['native'] as $field => $item) {
	$label = preg_replace('/^Cluster \[[^\]]+\] /', '', $item['name']);
	$native_table->addRow([
		ucfirst((string) $label),
		$native_value((string) $field, $item),
		$item['lastclock'] > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $item['lastclock']) : '-'
	]);
}
if ($cluster['native']) {
	$page->addItem(
		(new CDiv([
			(new CTag('h4', true, _('VMware cluster configuration and counters')))
				->addClass('vmware-monitoring-section-title'),
			$native_table
		]))->addClass('vmware-monitoring-section')
	);
}

if (!$cluster['native']) {
	$runtime_table = (new CTableInfo())->setHeader([_('Calculated information'), _('Value')]);
	$known = static fn(string $field): bool => ($cluster['totals']['known'][$field] ?? 0) > 0;
	$runtime_rows = [
		[_('CPU cores / threads'), $known('cpu_cores') && $known('cpu_threads')
			? number_format((float) $cluster['totals']['cpu_cores']).' / '.
				number_format((float) $cluster['totals']['cpu_threads']) : '-'],
		[_('Memory ballooned'), $known('memory_ballooned')
			? VMwareFormatter::bytes($cluster['totals']['memory_ballooned']) : '-'],
		[_('Network received / sent'), $known('network_in') && $known('network_out')
			? VMwareFormatter::bytes($cluster['totals']['network_in']).'/s / '.
				VMwareFormatter::bytes($cluster['totals']['network_out']).'/s' : '-'],
		[_('Network packets dropped (in / out)'), $known('network_dropped_in') && $known('network_dropped_out')
			? number_format((float) $cluster['totals']['network_dropped_in']).' / '.
				number_format((float) $cluster['totals']['network_dropped_out']) : '-'],
		[_('Network errors (in / out)'), $known('network_errors_in') && $known('network_errors_out')
			? number_format((float) $cluster['totals']['network_errors_in']).' / '.
				number_format((float) $cluster['totals']['network_errors_out']) : '-'],
		[_('Current / maximum power'), $known('power') && $known('power_max')
			? number_format((float) $cluster['totals']['power'], 1).' W / '.
				number_format((float) $cluster['totals']['power_max'], 1).' W' : '-'],
		[_('Lowest host uptime'), VMwareFormatter::duration($cluster['totals']['minimum_uptime'])],
		[_('Unique datastores / attachments'), $cluster['datastore_totals']['count'].' / '.
			$cluster['datastore_totals']['attachments']],
		[_('Datastore capacity / calculated free'), VMwareFormatter::bytes($cluster['datastore_totals']['capacity']).
			' / '.VMwareFormatter::bytes($cluster['datastore_totals']['free'])],
		[_('Lowest datastore free space'), VMwareFormatter::percent($cluster['datastore_totals']['lowest_free_pct'])],
		[_('Discovered hardware sensors'), (string) $cluster['sensors']['total']]
	];
	foreach ($runtime_rows as $row) {
		$runtime_table->addRow($row);
	}
	$page->addItem(
		(new CDiv([
			(new CTag('h4', true, _('Calculated cluster information')))
				->addClass('vmware-monitoring-section-title'),
			$runtime_table
		]))->addClass('vmware-monitoring-section')
	);
}

$distribution_table = (new CTableInfo())->setHeader([
	(new CColHeader(_('Distribution')))->setWidth('240px'),
	_('Detected values')
]);
$add_distribution = static function (CTableInfo $table, string $label, array $values): void {
	if (!$values) {
		$table->addRow([$label, '-']);
		return;
	}

	uksort($values, 'strnatcasecmp');
	$detected_values = [];
	foreach ($values as $value => $count) {
		$detected_values[] = new CDiv([
			$value,
			(new CSpan(' × '.(string) $count))->addClass(ZBX_STYLE_GREY)
		]);
	}
	$table->addRow([$label, $detected_values]);
};
foreach ([
	[_('ESXi versions'), $cluster['totals']['versions']],
	[_('Hardware vendors'), $cluster['totals']['vendors']],
	[_('Hardware models'), $cluster['totals']['models']],
	[_('CPU models'), $cluster['totals']['cpu_models']],
	[_('Sensor types'), $cluster['sensors']['types']]
] as [$label, $values]) {
	$add_distribution($distribution_table, $label, $values);
}
$sensor_states = [];
foreach ($cluster['sensors']['states'] as $state => $count) {
	$mapped = $state === 'unknown' ? ['text' => _('Unknown')] : VMwareFormatter::hypervisorHealth($state);
	$sensor_states[$mapped['text']] = ($sensor_states[$mapped['text']] ?? 0) + $count;
}
$add_distribution($distribution_table, _('Sensor states'), $sensor_states);
$problem_severities = [];
foreach ($cluster['totals']['problem_severities'] as $severity => $count) {
	$problem_severities[CSeverityHelper::getName((int) $severity)] = $count;
}
$add_distribution($distribution_table, _('Active problem severities'), $problem_severities);
$page->addItem(
	(new CDiv([
		(new CTag('h4', true, _('Cluster composition')))->addClass('vmware-monitoring-section-title'),
		$distribution_table
	]))->addClass('vmware-monitoring-section')
);

$datastore_table = (new CTableInfo())
	->setHeader([
		_('Datastore'), _('Type'), _('Capacity'), _('Free'), _('Hypervisor attachments'),
		_('Read latency'), _('Write latency'), _('Read IOPS'), _('Write IOPS'), _('Multipath')
	])
	->setNoDataMessage(_('No datastores are attached to hypervisors in this cluster.'));
foreach ($cluster['datastores'] as $datastore) {
	$free = $datastore['total'] !== null && $datastore['free_pct'] !== null
		? (float) $datastore['total'] * (float) $datastore['free_pct'] / 100
		: null;
	$datastore_table->addRow([
		$datastore['name'],
		$datastore['type'] ?: '-',
		VMwareFormatter::bytes($datastore['total']),
		$free === null ? '-' : VMwareFormatter::bytes($free).' ('.VMwareFormatter::percent($datastore['free_pct']).')',
		(string) $datastore['attachments'],
		$datastore['read_latency'] === null ? '-' : $datastore['read_latency'].' ms',
		$datastore['write_latency'] === null ? '-' : $datastore['write_latency'].' ms',
		$datastore['read_iops'] ?? '-',
		$datastore['write_iops'] ?? '-',
		$datastore['multipath'] ?? '-'
	]);
}
$page->addItem(
	(new CDiv([
		(new CTag('h4', true, _('Cluster datastores')))->addClass('vmware-monitoring-section-title'),
		$datastore_table
	]))->addClass('vmware-monitoring-section')
);

$host_table = (new CTableInfo())
	->setHeader([
		_('Hypervisor'), _('Connection'), _('Health'), _('CPU usage'), _('CPU capacity'),
		_('Memory used'), _('Memory capacity'), _('VMs'), _('Uptime'), _('Version'), _('Problems')
	])
	->setNoDataMessage(_('No discovered hypervisors are assigned to this cluster.'));
foreach ($cluster['hypervisors'] as $hypervisor) {
	$m = $hypervisor['metrics'];
	$connection = VMwareFormatter::connectionState($m['connection']);
	$health = VMwareFormatter::hypervisorHealth($m['health']);
	$host_table->addRow([
		VMwareTabRenderer::hostActionLink($hypervisor['name'], $hypervisor['hostid'], true),
		(new CSpan($connection['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$connection['kind']),
		(new CSpan($health['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$health['kind']),
		VMwareFormatter::percent($m['cpu_pct']),
		VMwareFormatter::hertz($m['cpu_capacity']),
		VMwareFormatter::bytes($m['memory_used']),
		VMwareFormatter::bytes($m['memory_total']),
		$m['vm_count'] !== null ? (string) $m['vm_count'] : '-',
		VMwareFormatter::duration($m['uptime']),
		$m['version'] ?: '-',
		VMwareTabRenderer::problemBadge($hypervisor['problems'], $hypervisor['hostid'])
	]);
}

$page
	->addItem(
		(new CDiv([
			(new CTag('h4', true, _('Cluster hypervisors')))->addClass('vmware-monitoring-section-title'),
			$host_table,
			$data['paging']
		]))->addClass('vmware-monitoring-section')
	)
	->show();
