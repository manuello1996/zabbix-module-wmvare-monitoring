<?php declare(strict_types = 0);
use Modules\VMwareMonitoring\Includes\Common\Summary;

use Modules\VMwareMonitoring\Includes\VMwareFormatter;
use Modules\VMwareMonitoring\Includes\VMwareBreadcrumb;
use Modules\VMwareMonitoring\Includes\VMwareItemMenu as I;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');
$this->addJsFile('class.tagfilteritem.js');
$this->includeJsFile('vmware.monitoring.view.js.php');
$this->includeJsFile('vmware.monitoring.hostmenu.js.php');

$page = (new CHtmlPage())
	->setTitle(_('VMware'))
	->setWebLayoutMode(CViewHelper::loadLayoutMode());

$page->setControls(
	(new CForm('get'))
		->cleanItems()
		->setId('vmware-monitoring-refresh-vcenter')
		->addVar('action', 'vmware.monitoring.view')
		->addVar('tab', $data['initial_tab'])
		->addVar('page', $data['initial_page'])
		->addItem(new CSubmitButton(_('Refresh vCenter'), 'force_vcenter_refresh', 1))
);

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

$makeStat = static function (string $id, string $modifier, string $label, $value): CDiv {
	return Summary::card($label, $value === null ? '-' : (string) $value,
		['modifier' => $modifier, 'value_id' => 'vmware-monitoring-stat-'.$id]);
};

$health = VMwareFormatter::vcenterHealth($data['vcenter_metrics']['health']);
$host_enabled = (int) $data['host']['status'] === HOST_STATUS_MONITORED;

$page->addItem(
	(new CDiv([
		VMwareBreadcrumb::make([
			new CLink(_('vCenters'), (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')),
			(new CSpan($data['host']['name']))
		]),
		(new CDiv([
			(new CDiv([
				(new CSpan($host_enabled ? _('Enabled') : _('Disabled')))
					->addClass($host_enabled ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
				(new CHostAvailability())->setInterfaces($data['host']['interfaces']),
				I::value((new CSpan($health['text']))
					->addClass('vmware-monitoring-state')
					->addClass('vmware-monitoring-state-'.$health['kind']), $data['vcenter_metrics'], 'health')
			]))->addClass('vmware-monitoring-topbar-chips'),
			(new CDiv([
				new CSpan([_('Version').': ', I::value($data['vcenter_metrics']['version'] ?? '-', $data['vcenter_metrics'], 'version')]),
				new CSpan([_('Product').': ', I::value($data['vcenter_metrics']['fullname'] ?? '-', $data['vcenter_metrics'], 'fullname')])
			]))->addClass('vmware-monitoring-topbar-meta'),
			(new CDiv([$makeIconButton('filter', _('Toggle filters'))]))
				->addClass('vmware-monitoring-topbar-actions')
		]))->addClass('vmware-monitoring-topbar-context')
	]))->addClass('vmware-monitoring-topbar')
);

$page->addItem(
	(new CDiv($filterbar))->setId('vmware-monitoring-filters')->setAttribute('hidden', 'hidden')
);

$page->addItem(
	Summary::strip([
		$makeStat('hypervisors', 'nodes', _('Hypervisors'), $data['hypervisors_count']),
		$makeStat('discovered-vms', 'total', _('Discovered VMs'), $data['vms_count']),
		$makeStat('total-vms', 'total', _('Total VMs'), $data['reported_vms_count'])
	])
);

$tabs = [];
foreach ([
	['overview', _('Overview')],
	['clusters', _('Clusters')],
	['hypervisors', _('Hypervisors')],
	['vms', _('Discovered VMs')],
	['datastores', _('Datastores')],
	['dvswitches', _('Distributed switches')],
	['alarms', _('vCenter Issues')]
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
		->setAttribute('role', 'tabpanel')
		->setAttribute('aria-live', 'polite')
);

$page->show();

(new CScriptTag('vmware_monitoring.init('.json_encode([
	'hostid' => $data['host']['hostid'],
	'tab' => $data['initial_tab'],
	'page' => $data['initial_page'],
	'search' => $data['initial_search'],
	'sort' => $data['initial_sort'],
	'sortorder' => $data['initial_sortorder']
]).');'))->setOnDocumentReady()->show();
