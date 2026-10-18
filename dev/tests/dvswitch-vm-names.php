<?php
require __DIR__.'/dvswitches.php';
use Modules\VMwareMonitoring\Includes\VMwareDvswitch as D;
use Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer as R;
use Modules\VMwareMonitoring\Includes\VMwareCollector as Collector;

$ports = [];
foreach (['vm-1', 'vm-1', 'vm-2', 'vm-404'] as $index => $id) {
	$ports[] = ['key' => (string) $index, 'portgroupKey' => 'pg-1', 'connectee' => [
		'connectedEntity' => ['@type' => 'VirtualMachine', '#text' => $id], 'nicKey' => '4000']];
}
$ports[] = ['key' => '5', 'connectee' => ['connectedEntity' => ['@type' => 'HostSystem', '#text' => 'vm-1']]];
$source = API::$items[0];
$source['lastvalue'] = json_encode(['FetchDVPortsResponse' => ['returnval' => $ports]]);
$source['lastclock'] = time();
API::$items = [$source];
$inventory = ['itemid' => '777', 'key_' => D::VM_INVENTORY_KEY, 'lastclock' => time(), 'state' => '0', 'error' => '',
	'lastvalue' => json_encode([['id' => 'vm-1', 'name' => 'Database'], ['id' => 'vm-2', 'name' => '<script>Application</script>'],
		['id' => 'unrelated', 'name' => 'Foreign VM']])];
API::$vm_inventory = [$inventory]; API::$calls = [];
$detail = Collector::distributedSwitchDetail('42', 'uuid-1');
$snapshot = $detail['snapshot'];
$by_port = array_column($snapshot['ports'], null, 'key');
check(D::vmIds($snapshot) === ['vm-1', 'vm-2', 'vm-404'], 'VM IDs are deduplicated and exclude host references');
$lookups = array_filter(API::$calls, static fn($call) => in_array(D::VM_INVENTORY_KEY, (array) ($call['options']['filter']['key_'] ?? []), true));
check(count($lookups) === 1, 'One inventory API lookup resolves all VMs, not one request per port or VM');
check(count($detail['vm_inventory']['names']) === 2, 'Only requested IDs are resolved');
check($by_port[0]['entity']['name'] === 'Database' && $by_port[1]['entity']['name'] === 'Database',
	'Every port for the same VM gets the same name');
check(!isset($by_port[5]['entity']['name']), 'Host IDs cannot accidentally match a VM ID');
check(!isset($by_port[3]['entity']['name']), 'Unknown VM keeps its original ID');
check(count(D::filterPorts($snapshot['ports'], 'database', 'all', '', '')) === 2, 'VM names are searchable');
foreach (['ports', 'resources'] as $section) {
	$html = (string) R::table(['section' => $section, 'vcenter' => ['hostid' => '42'], 'switch' => $detail,
		'rows' => $snapshot[$section]]);
	check(str_contains($html, 'Database') && str_contains($html, 'vm-1') && str_contains($html, 'vm-404'),
		'Both tables display names alongside IDs and preserve unresolved IDs');
	check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;Application'), 'VM names are HTML escaped');
}
foreach ([['state' => '1', 'error' => 'Inventory unavailable'], ['lastclock' => 0], ['lastvalue' => '{bad'],
	['lastvalue' => '{}'], ['lastvalue' => '[{"id":"vm-1","name":null}]']] as $change) {
	API::$vm_inventory = [array_replace($inventory, $change)];
	$result = Collector::distributedSwitchDetail('42', 'uuid-1');
	$fallback = array_column($result['snapshot']['ports'], null, 'key');
	check($result['vm_inventory']['error'] !== '' && !isset($fallback[0]['entity']['name']),
		'Collection failures, stale values and malformed inventories fall back to VM IDs');
}
API::$vm_inventory = [];
check(Collector::distributedSwitchDetail('42', 'uuid-1')['snapshot']['counts']['ports'] === 5,
	'An older template without inventory still displays all ports');
check(D::vmNames('[{"id":"vm-1","name":"A"},{"id":"vm-1","name":"B"}]', ['vm-1']) === [],
	'Conflicting names for one ID are not guessed');
$source['lastvalue'] = '{"FetchDVPortsResponse":{"returnval":[{"key":"0"}]}}';
API::$items = [$source]; API::$calls = [];
check(Collector::distributedSwitchDetail('42', 'uuid-1')['vm_inventory'] === null, 'No lookup without attached VMs');
check(count(API::$calls) === 1, 'No inventory API request when there are no VM references');
echo "Batch VM-name resolution checks passed: deduplication, vCenter scope, display, search, and fallback.\n";
