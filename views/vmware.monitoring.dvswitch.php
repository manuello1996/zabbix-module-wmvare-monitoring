<?php declare(strict_types = 0);

use Modules\VMwareMonitoring\Includes\VMwareBreadcrumb;
use Modules\VMwareMonitoring\Includes\VMwareDvswitch;
use Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer as R;
use Modules\VMwareMonitoring\Includes\VMwareItemMenu as I;

$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');
$this->addJsFile('class.tagfilteritem.js');
$this->includeJsFile('vmware.monitoring.hostmenu.js.php');
$this->includeJsFile('vmware.monitoring.dvswitch.js.php');
$switch = $data['switch'];
$hostid = (string) $data['vcenter']['hostid']; $uuid = $switch['uuid'];
$snapshot = $switch['snapshot']; $filters = $data['filters'];
$vcenter_url = (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.view')
	->setArgument('filter_set', 1)->setArgument('filter_hostid', [$hostid]);
$switches_url = (clone $vcenter_url)->setArgument('tab', 'dvswitches');
$page = (new CHtmlPage())->setTitle(_('VMware distributed switch'))->setWebLayoutMode(CViewHelper::loadLayoutMode());
$page->addItem((new CDiv(VMwareBreadcrumb::make([
	new CLink(_('vCenters'), (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')),
	(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
	new CLink($data['vcenter']['name'], $vcenter_url),
	(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
	new CLink(_('Distributed switches'), $switches_url),
	(new CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep'),
	(new CSpan($switch['name']))->addClass('vmware-monitoring-breadcrumb-current')->setAttribute('aria-current', 'page')
])))->addClass('vmware-monitoring-topbar'));

$summary = (new CTableInfo())->setHeader([_('Property'), _('Value')]);
$summary->addRow([_('Switch'), $switch['name']]);
$summary->addRow([_('UUID'), $uuid]);
$summary->addRow([_('Last successful snapshot'), (int) $switch['source']['lastclock'] > 0
	? date('Y-m-d H:i:s', (int) $switch['source']['lastclock']) : _('Awaiting data')]);
$summary->addRow([_('Source'), I::link(_('Port snapshot: item actions and history'), $switch['source']['itemid'])]);
$vm_inventory = $switch['vm_inventory'] ?? null;
$host_inventory = $switch['host_inventory'] ?? null;
if ($vm_inventory !== null) {
	$summary->addRow([_('VM names'), count($vm_inventory['names']).' / '
		.count(VMwareDvswitch::vmIds($snapshot)).' '._('resolved')]);
	if ($vm_inventory['itemid'] !== null) {
		$summary->addRow([_('VM name inventory'), I::link($vm_inventory['lastclock'] > 0
			? date('Y-m-d H:i:s', $vm_inventory['lastclock']) : _('Awaiting data'), $vm_inventory['itemid'])]);
	}
}
if ($host_inventory !== null) {
	$summary->addRow([_('Hypervisor names'), count($host_inventory['names']).' / '
		.count(VMwareDvswitch::hostIds($snapshot)).' '._('resolved')]);
	if ($host_inventory['itemid'] !== null) {
		$summary->addRow([_('Hypervisor name inventory'), I::link($host_inventory['lastclock'] > 0
			? date('Y-m-d H:i:s', $host_inventory['lastclock']) : _('Awaiting data'), $host_inventory['itemid'])]);
	}
}
$page->addItem((new CDiv($summary))->addClass('vmware-monitoring-section'));
if ($vm_inventory !== null && $vm_inventory['error'] !== '') {
	$page->addItem((new CDiv([_('VM name lookup'), ': ', $vm_inventory['error'], ' ',
		_('Unresolved VMs are shown by ID.')]))->addClass('vmware-monitoring-muted'));
}
if ($host_inventory !== null && $host_inventory['error'] !== '') {
	$page->addItem((new CDiv([_('Hypervisor name lookup'), ': ', $host_inventory['error'], ' ',
		_('Unresolved hypervisors are shown by ID.')]))->addClass('vmware-monitoring-muted'));
}
if ($switch['error'] !== '') {
	$page->addItem((new CDiv([_('Collection error'), ': ', $switch['error'], ' ',
		$snapshot === null ? '' : _('The tables show the last successfully stored snapshot.')]))->addClass(ZBX_STYLE_RED));
}
if (!$switch['recent'] && (int) $switch['source']['lastclock'] > 0) {
	$page->addItem((new CDiv(_('The stored snapshot is outside the configured recent-history period.')))->addClass(ZBX_STYLE_RED));
}
$sections = ['ports' => _('Ports'), 'portgroups' => _('Port groups'), 'resources' => _('Connected resources'),
	'statistics' => _('Statistics'), 'problems' => _('Problems')];
$tabs = [];
foreach ($sections as $section => $label) {
	$tabs[] = (new CLink($label, R::url($hostid, $uuid, $section)))->addClass('vmware-monitoring-tab')
		->addClass($section === $data['section'] ? 'vmware-monitoring-tab-active' : null)
		->setAttribute('aria-current', $section === $data['section'] ? 'page' : 'false');
}
$page->addItem((new CTag('nav', true, $tabs))->addClass('vmware-monitoring-tabs')->setAttribute('aria-label', _('Switch details')));

$form = (new CTag('form', true))->setAttribute('method', 'get')->setAttribute('action', 'zabbix.php')
	->addClass('vmware-monitoring-dvs-filters');
foreach (['action' => 'vmware.monitoring.dvswitch', 'hostid' => $hostid, 'uuid' => $uuid,
	'section' => $data['section']] as $key => $value) { $form->addItem(new CVar($key, $value)); }
$form->addItem([new CLabel(_('Search'), 'dvs-search'),
	(new CTextBox('search', $filters['search']))->setId('dvs-search')]);
$select = static function (string $name, string $label, array $options, string $selected): array {
	$input = (new CTag('select', true))->setAttribute('name', $name)->setAttribute('id', 'dvs-'.$name);
	foreach ($options as $value => $text) {
		$option = (new CTag('option', true, $text))->setAttribute('value', (string) $value);
		if ((string) $value === $selected) { $option->setAttribute('selected', 'selected'); }
		$input->addItem($option);
	}
	return [new CLabel($label, 'dvs-'.$name), $input];
};
if ($data['section'] === 'ports') {
	$form->addItem($select('state', _('State'), [
		'all' => _('All'), 'connected' => _('Attached'), 'unconnected' => _('No attachment reported'),
		'up' => _('Link up'), 'down' => _('Attached, link down'), 'unknown' => _('Link unknown'),
		'blocked' => _('Blocked'), 'conflict' => _('Conflict')
	], $filters['state']));
	$groups = ['' => _('All port groups')]; $resources = ['' => _('All resources')];
	foreach ($snapshot['portgroups'] ?? [] as $group) { if ($group['name'] !== '') { $groups[$group['name']] = $group['name']; } }
	foreach ($snapshot['resources'] ?? [] as $resource) {
		$resources[$resource['key']] = isset($resource['name']) ? $resource['name']
			: $resource['type'].': '.$resource['id'];
	}
	$form->addItem($select('portgroup', _('Port group'), $groups, $filters['portgroup']));
	$form->addItem($select('resource', _('Resource'), $resources, $filters['resource']));
}
$form->addItem([new CSubmit('apply', _('Apply')), new CLink(_('Reset'), R::url($hostid, $uuid, $data['section']))]);
$page->addItem($form);
$note = match ($data['section']) {
	'ports' => _('Unknown means the field was absent or unavailable in the snapshot. Expand Port details for runtime state, status changes, DirectPath and counters. All reported fields includes the complete port record.'),
	'portgroups' => _('Groups are derived from reported port-group IDs. Names, configured capacity and policies are not supplied by this state snapshot.'),
	'resources' => _('VM and hypervisor names are resolved by managed object ID using the vCenter inventories. Unresolved resources retain their IDs. Proxy hosts and attached entities have separate roles.'),
	'statistics' => _('Cumulative counter snapshots, not traffic rates. Sums include only reporting ports; missing counters are not zero. Traffic traversing multiple ports can be counted more than once. Per-port counters are available under Ports → Port details.'),
	default => _('Active Zabbix problems scoped to this switch UUID, including acknowledged or suppressed events.')
};
$page->addItem((new CDiv([
	(new CTag('h4', true, $sections[$data['section']]))->addClass('vmware-monitoring-section-title'),
	new CDiv($note), (new CDiv(R::table($data)))->addClass('vmware-monitoring-dvs-table'), $data['paging']
]))->addClass('vmware-monitoring-section'));
$page->show();
