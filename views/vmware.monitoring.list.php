<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\Common\AutoRefresh;

use Modules\VMwareMonitoring\Includes\VMwareBreadcrumb;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');
$this->addJsFile('class.tagfilteritem.js');
$this->includeJsFile('vmware.monitoring.list.js.php');
$this->includeJsFile('vmware.monitoring.hostmenu.js.php');

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
		->addItem((new CSubmitButton(_('Refresh vCenters'), 'force_vcenter_refresh', 1))
			->setId('vmware-monitoring-refresh-vcenters-button'))
		->addItem(AutoRefresh::control('vmware-monitoring-auto-refresh', $data['refresh_interval']))
);

$page->addItem(
	(new CDiv(
		VMwareBreadcrumb::make([
			(new CSpan(_('vCenters')))
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
$page->addItem((new CDiv(new CObject((new CView('vmware.monitoring.list.summary', [
	'totals' => array_fill_keys(['vcenters', 'hypervisors', 'vms', 'datastores'], '—')
]))->getOutput())))->setId('vmware-monitoring-list-summary')->setAttribute('aria-busy', 'true'));

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
$page->addItem(AutoRefresh::status('vmware-monitoring-refresh-status'));
$page->addItem((new CDiv(
	(new CDiv(_('Loading VMware data…')))->addClass('vmware-monitoring-section')
))->setId('vmware-monitoring-tab-content')->setAttribute('aria-busy', 'true'));
$page->addItem(new CTag('noscript', true, _('Enable JavaScript to load VMware monitoring data.')));
$page->show();

(new CScriptTag('vmware_monitoring_list.init('.json_encode([
	'refresh_interval' => $data['refresh_interval'],
	'tab' => $data['tab'],
	'force_refresh' => $data['force_refresh'],
	'filter_groupids' => $data['filter_groupids'],
	'filter_hostids' => $data['filter_hostids']
]).');'))->setOnDocumentReady()->show();
