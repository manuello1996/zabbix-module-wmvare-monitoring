<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\VMwareFormatter;

$this->includeJsFile('vmware.monitoring.view.js.php');

$page = (new CHtmlPage())
	->setTitle(_('VMware'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$filter_button = new CSubmitButton(_('Filter'), 'filter_set', 1);
if ($data['host'] === null) {
	$filter_button->setAttribute('disabled', 'disabled');
}

$filterbar = (new CForm('get'))
	->cleanItems()
	->setName('vmware_monitoring_filterbar')
	->addVar('action', 'vmware.monitoring.view')
	->addItem(
		(new CDiv([
			new CLabel(_('Host groups'), 'filter_groupids__ms'),
			(new CMultiSelect([
				'name' => 'filter_groupids[]',
				'object_name' => 'hostGroup',
				'data' => $data['filter']['groups'],
				'popup' => ['parameters' => [
					'srctbl' => 'host_groups',
					'srcfld1' => 'groupid',
					'dstfrm' => 'vmware_monitoring_filterbar',
					'dstfld1' => 'filter_groupids_',
					'with_monitored_items' => true
				]]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
			new CLabel(_('vCenter'), 'filter_hostid__ms'),
			(new CMultiSelect([
				'name' => 'filter_hostid[]',
				'object_name' => 'hosts',
				'multiple' => false,
				'data' => $data['host'] !== null
					? [['id' => $data['host']['hostid'], 'name' => $data['host']['name']]]
					: [],
				'popup' => ['parameters' => [
					'srctbl' => 'hosts',
					'srcfld1' => 'hostid',
					'dstfrm' => 'vmware_monitoring_filterbar',
					'dstfld1' => 'filter_hostid_',
					'with_monitored_items' => true
				]]
			]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
			$filter_button,
			(new CRedirectButton(_('Reset'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'vmware.monitoring.view')
					->setArgument('filter_rst', 1)
			))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('vmware-monitoring-filterbar')
	);

if ($data['host'] === null) {
	$page
		->addItem($filterbar)
		->addItem(
			(new CTableInfo())->setNoDataMessage(
				$data['hosts']
					? _('Select a vCenter to view its discovered VMware environment.')
					: _('No vCenter hosts found. Link the official "VMware" template to a monitored host.')
			)
		)
		->show();
	return;
}

$makeIconButton = static function (string $modifier, string $label): CTag {
	return (new CTag('button', true))
		->setAttribute('type', 'button')
		->addClass('vmware-monitoring-iconbtn')
		->addClass('vmware-monitoring-iconbtn-'.$modifier)
		->setId('vmware-monitoring-btn-'.$modifier)
		->setAttribute('aria-label', $label)
		->setTitle($label);
};

$makeStat = static function (string $modifier, string $label, $value): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('vmware-monitoring-statseg-label'),
		(new CDiv(
			(new CSpan((string) $value))->addClass('vmware-monitoring-card-value')
		))->addClass('vmware-monitoring-statseg-figure')
	]))
		->addClass('vmware-monitoring-statseg')
		->addClass('vmware-monitoring-card-'.$modifier);
};

$health = VMwareFormatter::vcenterHealth($data['vcenter_metrics']['health']);
$host_enabled = (int) $data['host']['status'] === HOST_STATUS_MONITORED;

$page->addItem(
	(new CDiv([
		(new CDiv([
			(new CDiv([
				new CLink(_('vCenters'), (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')),
				(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
				(new CSpan($data['host']['name']))->addClass('vmware-monitoring-breadcrumb-current')
			]))->addClass('vmware-monitoring-breadcrumb'),
			(new CDiv([
				(new CSpan($host_enabled ? _('Enabled') : _('Disabled')))
					->addClass($host_enabled ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
				(new CHostAvailability())->setInterfaces($data['host']['interfaces']),
				(new CSpan($health['text']))
					->addClass('vmware-monitoring-state')
					->addClass('vmware-monitoring-state-'.$health['kind'])
			]))->addClass('vmware-monitoring-topbar-chips'),
			(new CDiv([
				new CSpan([_('Version').': ', $data['vcenter_metrics']['version'] ?? '-']),
				new CSpan([_('Product').': ', $data['vcenter_metrics']['fullname'] ?? '-'])
			]))->addClass('vmware-monitoring-topbar-meta')
		]))->addClass('vmware-monitoring-topbar-info'),
		(new CDiv([$makeIconButton('filter', _('Toggle filters'))]))->addClass('vmware-monitoring-topbar-actions')
	]))->addClass('vmware-monitoring-topbar')
);

$page->addItem(
	(new CDiv($filterbar))->setId('vmware-monitoring-filters')->setAttribute('hidden', 'hidden')
);

$page->addItem(
	(new CDiv([
		$makeStat('nodes', _('Hypervisors'), $data['hypervisors_count']),
		$makeStat('total', _('Discovered VMs'), $data['vms_count']),
		$makeStat('total', _('Total VMs'), $data['reported_vms_count'])
	]))->addClass('vmware-monitoring-statstrip')
);

$tabs = [];
foreach ([
	['overview', _('Overview')],
	['hypervisors', _('Hypervisors')],
	['vms', _('Discovered VMs')],
	['datastores', _('Datastores')],
	['clusters', _('Clusters')],
	['alarms', _('Alarms')]
] as $index => [$key, $label]) {
	$active = $key === $data['initial_tab'];
	$tabs[] = (new CSpan($label))
		->addClass('vmware-monitoring-tab')
		->addClass($active ? 'vmware-monitoring-tab-active' : null)
		->setAttribute('data-vmware-monitoring-tab', $key)
		->setAttribute('role', 'tab')
		->setAttribute('tabindex', '0')
		->setAttribute('aria-selected', $active ? 'true' : 'false');
}
$page->addItem((new CDiv($tabs))->addClass('vmware-monitoring-tabs')->setAttribute('role', 'tablist'));

$page->addItem(
	(new CDiv(
		(new CDiv(_('Loading...')))->addClass('vmware-monitoring-loading')
	))
		->setId('vmware-monitoring-panel')
		->addClass('vmware-monitoring-panel')
		->setAttribute('aria-live', 'polite')
);

$page->show();

(new CScriptTag('vmware_monitoring.init('.json_encode([
	'hostid' => $data['host']['hostid'],
	'tab' => $data['initial_tab'],
	'search' => $data['initial_search']
]).');'))->setOnDocumentReady()->show();
