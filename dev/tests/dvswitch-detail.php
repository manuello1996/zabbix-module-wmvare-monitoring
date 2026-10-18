<?php
/** Uses real Zabbix HTML classes and a permission-scoped fake API. */
require __DIR__.'/dvswitches.php';
use Modules\VMwareMonitoring\Includes\VMwareDvswitch as D;
use Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer as R;
use Modules\VMwareMonitoring\Includes\VMwareCollector as Collector;

$entity = ['@type' => 'VirtualMachine', '#text' => 'vm-1'];
$proxy = ['@type' => 'HostSystem', '#text' => 'host-1'];
$ports = [
	['key' => '1', 'dvsUuid' => 'uuid-1', 'portgroupKey' => 'pg-1', 'proxyHost' => $proxy,
		'connectee' => ['connectedEntity' => $entity, 'nicKey' => '4000', 'type' => 'vmVnic'],
		'conflict' => 'false', 'lastStatusChange' => '2026-10-18T09:00:00Z',
		'state' => ['runtimeInfo' => ['linkUp' => 'true', 'blocked' => 'false', 'trunkingMode' => 'true',
			'vlanIds' => [['start' => '0', 'end' => '99'], ['start' => '200', 'end' => '200']],
			'macAddress' => '00:01:02:03:04:05', 'linkPeer' => 'vmnic0', 'vmDirectPathGen2Active' => 'false',
			'statusDetail' => '<script>unsafe</script>'],
			'stats' => ['bytesInUnicast' => '18446744073709551615', 'packetsInDropped' => '0']]],
	['key' => '2', 'portgroupKey' => 'pg-1', 'connectee' => ['connectedEntity' => $entity], 'conflict' => true,
		'state' => ['runtimeInfo' => ['linkUp' => false, 'blocked' => true, 'vlanIds' => ['start' => 42, 'end' => 42]],
			'stats' => ['bytesInUnicast' => '18446744073709551615', 'packetsInDropped' => '3']]],
	['key' => '3', 'portgroupKey' => 'pg-2'],
	['key' => '4']
];
$json = json_encode(['FetchDVPortsResponse' => ['returnval' => $ports]]);
$snapshot = D::parse($json);
check($snapshot['counts'] === ['ports' => 4, 'connected' => 2, 'link_up' => 1, 'connected_down' => 1,
	'blocked' => 1, 'conflicts' => 1, 'unknown' => 2], 'Exact state counts including unknowns');
$group = array_values(array_filter($snapshot['portgroups'], static fn($g) => $g['name'] === 'pg-1'))[0];
check($group['ports'] === 2 && count($group['resources']) === 1, 'Groups deduplicate attached resources');
check($group['vlans'] === ['0–99' => '0–99', 200 => '200', 42 => '42'], 'Singleton and array VLAN ranges are retained');
$stat = $snapshot['statistics'][0];
check($stat['value'] === '36893488147419103230' && $stat['reported'] === 2, 'Unsigned counters summed without precision loss');
check(count($snapshot['resources']) === 2, 'Connected entity and proxy host remain separate');
check(count($snapshot['resources'][1]['ports']) === 2, 'Repeated VM reference is deduplicated by group/port');
check(count(D::filterPorts($snapshot['ports'], 'vmnic0', 'all', '', '')) === 1, 'Searches nested reported values');
check(count(D::filterPorts($snapshot['ports'], '', 'unknown', '', '')) === 2, 'Unknown is not down');
check(count(D::filterPorts($snapshot['ports'], '', 'down', '', '')) === 1, 'Only attached explicit down is a failure');
check(count(D::filterPorts($snapshot['ports'], '', 'all', 'pg-1', 'VirtualMachine:vm-1')) === 2, 'Combined group/resource filters');
check(count(D::filterPorts($snapshot['ports'], '', 'all', '', 'HostSystem:host-1')) === 1, 'Proxy host filtering');
check(D::parse('{"FetchDVPortsResponse":null}')['counts']['ports'] === 0, 'Empty native response');
check(D::parse(json_encode(['FetchDVPortsResponse' => ['returnval' => $ports[0]]]))['counts']['ports'] === 1,
	'Singleton port response');
foreach (['{}', '{"FetchDVPortsResponse":"bad"}', '{"FetchDVPortsResponse":{"returnval":[null]}}', '{broken'] as $invalid) {
	try { D::parse($invalid); throw new RuntimeException('Malformed payload accepted'); }
	catch (JsonException | UnexpectedValueException $expected) {}
}
$source = API::$items[0];
$source['lastvalue'] = $json; $source['lastclock'] = time();
API::$items = [$source];
$detail = Collector::distributedSwitchDetail('42', 'uuid-1');
check($detail['snapshot']['counts']['ports'] === 4, 'Detail reads the raw snapshot only');
check(Collector::distributedSwitchDetail('42', 'foreign-uuid') === null, 'Cannot select a different switch through source tags');
API::$items[0]['lastvalue'] = '{broken';
check(Collector::distributedSwitchDetail('42', 'uuid-1')['snapshot'] === null, 'Malformed JSON produces unavailable details');
API::$items[0] = $source;
API::$items[0]['state'] = '1'; API::$items[0]['error'] = 'Collector unavailable';
check(Collector::distributedSwitchDetail('42', 'uuid-1')['error'] === 'Collector unavailable', 'Retains errors beside historical snapshot');
check(Collector::distributedSwitchProblems('42', 'uuid-1') === [], 'Empty trigger scope never returns all problems');
API::$triggers = [['triggerid' => '900']];
API::$problems = [['eventid' => '901', 'objectid' => '900', 'name' => 'Port conflict', 'clock' => time(),
	'severity' => '2', 'acknowledged' => '0', 'suppressed' => '0']];
check(count(Collector::distributedSwitchProblems('42', 'uuid-1')) === 1, 'Problems are scoped to matching trigger IDs');
foreach (['ports', 'portgroups', 'resources', 'statistics', 'problems'] as $section) {
	$data = ['section' => $section, 'vcenter' => ['hostid' => '42'], 'switch' => $detail,
		'rows' => $section === 'problems' ? API::$problems : $snapshot[$section]];
	$html = (string) R::table($data);
	check(!str_contains($html, '<script>'), 'All detail sections escape VMware-provided text');
	if ($section === 'ports') { check(str_contains($html, '&lt;script&gt;unsafe'), 'Reported fields preserve safely escaped content'); }
	if ($section === 'statistics') { check(str_contains($html, '36 893 488 147 419 103 230'), 'Renderer preserves exact counter sum'); }
}
echo "Distributed-switch detail, payload, filtering and problem-scope checks passed.\n";
