<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\VMwareFormatter;

$this->includeJsFile('vmware.monitoring.list.js.php');

$makeStat = static function (string $modifier, string $label, $value): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('vmware-monitoring-statseg-label'),
		(new CDiv((new CSpan((string) $value))->addClass('vmware-monitoring-card-value')))
			->addClass('vmware-monitoring-statseg-figure')
	]))
		->addClass('vmware-monitoring-statseg')
		->addClass('vmware-monitoring-card-'.$modifier);
};

$problemBadge = static function (array $severities, string $hostid) {
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
			->setArgument('hostids', [$hostid])
			->setArgument('filter_set', 1)
	))->addClass(ZBX_STYLE_PROBLEM_ICON_LINK);
};

$page = (new CHtmlPage())
	->setTitle(_('VMware vCenters'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$page->addItem(
	(new CDiv(
		(new CDiv((new CSpan(_('vCenters')))->addClass('vmware-monitoring-breadcrumb-current')))
			->addClass('vmware-monitoring-breadcrumb')
			->setAttribute('aria-label', _('Breadcrumb'))
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
		->addFilterTab(_('Filter'), [$filter_form])
);

$page->addItem(
	(new CDiv([
		(new CTag('h4', true, _('VMware environment overview')))->addClass('vmware-monitoring-section-title'),
		(new CDiv([
			$makeStat('nodes', _('vCenters'), $data['totals']['vcenters']),
			$makeStat('running', _('Hypervisors'), $data['totals']['hypervisors']),
			$makeStat('total', _('Virtual machines'), $data['totals']['vms']),
			$makeStat('memory', _('Unique datastores'), $data['totals']['datastores']),
			$makeStat('memory', _('Datastore attachments'), $data['totals']['datastore_attachments'])
		]))->addClass('vmware-monitoring-statstrip')
	]))->addClass('vmware-monitoring-section')
);

$table = (new CTableInfo())
	->setId('vmware-monitoring-vcenters-table')
	->setHeader([
		_('vCenter'),
		_('Availability'),
		_('Health'),
		_('Problems'),
		_('Version'),
		_('Hypervisors'),
		_('Virtual machines'),
		_('Unique datastores'),
		_('Attachments'),
		_('Notes')
	])
	->setNoDataMessage(_('No vCenter hosts found. Link the official "VMware" template to a monitored host.'));

foreach ($data['vcenters'] as $vcenter) {
	$url = (new CUrl('zabbix.php'))
		->setArgument('action', 'vmware.monitoring.view')
		->setArgument('filter_hostid', [$vcenter['hostid']])
		->setArgument('filter_set', 1);
	$health = VMwareFormatter::vcenterHealth($vcenter['metrics']['health']);

	$table->addRow([
		(new CDiv([
			(new CSpan())->addClass('vmware-monitoring-container-icon'),
			(new CLink($vcenter['name'], $url))->addClass('vmware-monitoring-name')
		]))->addClass('vmware-monitoring-name-cell'),
		(new CHostAvailability())->setInterfaces($vcenter['interfaces']),
		(new CSpan($health['text']))
			->addClass('vmware-monitoring-state')
			->addClass('vmware-monitoring-state-'.$health['kind']),
		$problemBadge($vcenter['problems'], $vcenter['hostid']),
		$vcenter['metrics']['version'] ?? '-',
		(string) $vcenter['hypervisors'],
		(string) $vcenter['vms'],
		(string) $vcenter['datastores'],
		(string) $vcenter['datastore_attachments'],
		$vcenter['inventory']['notes'] ?? ''
	]);
}

$page
	->addItem(
		(new CDiv([
			(new CTag('h4', true, [
				_('vCenters'),
				(new CSpan())->setId('vmware-monitoring-refresh-status')->addClass('vmware-monitoring-refresh-status')
			]))->addClass('vmware-monitoring-section-title'),
			$table,
			$data['paging']
		]))->addClass('vmware-monitoring-section')
	)
	->show();

(new CScriptTag('vmware_monitoring_list.init('.json_encode([
	'refresh' => $data['refresh_interval']
]).');'))->setOnDocumentReady()->show();
