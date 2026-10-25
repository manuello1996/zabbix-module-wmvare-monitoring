<?php
require __DIR__.'/dvswitches.php';
use Modules\VMwareMonitoring\Includes\VMwareDvswitch as D;
use Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer as R;
use Modules\VMwareMonitoring\Includes\VMwareCollector as Collector;

$source = API::$items[0];
$source['lastvalue'] = json_encode(['FetchDVPortsResponse' => ['returnval' => [
	['key' => '1', 'portgroupKey' => 'pg-1', 'proxyHost' => ['@type' => 'HostSystem', '#text' => 'host-34'],
		'connectee' => ['connectedEntity' => ['@type' => 'VirtualMachine', '#text' => 'vm-41']]],
	['key' => 'uplink', 'portgroupKey' => 'pg-2', 'proxyHost' => ['@type' => 'HostSystem', '#text' => 'host-34'],
		'connectee' => ['connectedEntity' => ['@type' => 'HostSystem', '#text' => 'host-34']]],
	['key' => 'unknown', 'proxyHost' => ['@type' => 'HostSystem', '#text' => 'host-99']]
]]]);
$source['lastclock'] = time();
API::$items = [$source];
API::$vm_inventory = [['itemid' => '777', 'key_' => D::VM_INVENTORY_KEY, 'lastclock' => time(),
	'state' => '0', 'error' => '', 'lastvalue' => '[{"id":"vm-41","name":"Database"}]']];
$inventory = ['itemid' => '778', 'key_' => D::HOST_INVENTORY_KEY, 'lastclock' => time(), 'state' => '0', 'error' => '',
	'lastvalue' => '[{"id":"host-34","name":"esxi-02"},{"id":"vm-41","name":"Wrong type"}]'];
API::$host_inventory = [$inventory]; API::$calls = [];
$detail = Collector::distributedSwitchDetail('42', 'uuid-1');
$snapshot = $detail['snapshot'];
check(D::hostIds($snapshot) === ['host-34', 'host-99'], 'Host IDs include proxy and attached hosts, deduplicated');
$lookups = array_filter(API::$calls, static fn($call) => isset($call['options']['filter']['key_']));
check(count($lookups) === 1, 'Both name inventories are requested in one API batch');
check(array_values($lookups)[0]['options']['filter']['key_'] === [D::VM_INVENTORY_KEY, D::HOST_INVENTORY_KEY],
	'Both inventory keys are in the same scoped lookup');
$ports = array_column($snapshot['ports'], null, 'key');
check($ports[1]['proxy']['name'] === 'esxi-02', 'Resolves proxy hypervisor name');
check($ports['uplink']['entity']['name'] === 'esxi-02', 'Resolves attached uplink hypervisor name');
check($ports[1]['entity']['name'] === 'Database', 'A host mapping cannot override a VM with a different type');
check(!isset($ports['unknown']['proxy']['name']), 'Unknown hosts keep IDs');
check(count(D::filterPorts($snapshot['ports'], 'ESXI-02', 'all', '', '')) === 2, 'Search includes proxy host names');
$html = (string) R::table(['section' => 'ports', 'vcenter' => ['hostid' => '42'], 'switch' => $detail,
	'rows' => $snapshot['ports']]);
check(str_contains($html, 'title="VM ID: vm-41">Database</span>'), 'Resolved VM shows its name with ID available on hover');
check(!str_contains($html, 'Database (VM ID:'), 'Resolved VM has no visible ID suffix');
check(!str_contains($html, 'VirtualMachine: vm-41') && !str_contains($html, 'HostSystem: host-34'), 'No internal type prefixes in reference labels');
check(str_contains($html, 'esxi-02') && str_contains($html, 'Host ID: host-34'), 'Name is shown, host ID remains in tooltip');
check(substr_count($html, 'colspan="9"') === 3, 'Each port detail panel spans all nine columns; its control sits beside the port name');
check(substr_count($html, 'data-vmware-dvs-port-toggle=') === 3, 'Each summary has its own accessible toggle');
check(substr_count($html, 'aria-expanded="false"') === 3, 'Panels initially collapsed');
check(str_contains($html, 'vmware-monitoring-metric-grid'), 'Runtime and counters use the shared detail grid');
API::$host_inventory = [array_replace($inventory, ['lastvalue' => '[{"id":"host-34","name":"<script>unsafe</script>"}]'])];
$unsafe = Collector::distributedSwitchDetail('42', 'uuid-1');
$html = (string) R::table(['section' => 'ports', 'vcenter' => ['hostid' => '42'], 'switch' => $unsafe,
	'rows' => $unsafe['snapshot']['ports']]);
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;unsafe'), 'Hypervisor names are escaped');
API::$host_inventory = [];
$missing = Collector::distributedSwitchDetail('42', 'uuid-1');
check($missing['host_inventory']['error'] !== '' && $missing['vm_inventory']['names']['vm-41'] === 'Database',
	'Missing host inventory leaves VM resolution intact');
echo "Hypervisor name, batched inventories, reference labels and full-width row checks passed.\n";
