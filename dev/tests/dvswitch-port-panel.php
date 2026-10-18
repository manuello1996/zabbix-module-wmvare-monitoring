<?php
require __DIR__.'/dvswitch-host-names.php';

$rows = [];
foreach (['up', 'down', 'blocked', 'conflict', 'unknown', 'unused'] as $state) {
	$port = $ports[1];
	$port['key'] = $state;
	$port['up'] = $state === 'up' ? true : ($state === 'unknown' ? null : false);
	$port['connected'] = $state !== 'unused';
	$port['blocked'] = $state === 'blocked';
	$port['conflict'] = $state === 'conflict';
	$rows[] = $port;
}
$data = ['section' => 'ports', 'vcenter' => ['hostid' => '42'], 'switch' => $detail, 'rows' => $rows];
$html = (string) \Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer::portPanel($data);
$table = (string) \Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer::table($data);
foreach (['up', 'down', 'blocked', 'conflict', 'unknown', 'unused'] as $state) {
	check(str_contains($html, 'vmware-monitoring-dvs-port-'.$state.'"'), 'Port state rendered: '.$state);
}
preg_match_all('/data-vmware-dvs-port-open="([^"]+)"/', $html, $targets);
check(count($targets[1]) === 6 && count(array_unique($targets[1])) === 6, 'Each port has a distinct target');
foreach ($targets[1] as $id) { check(str_contains($table, 'id="'.$id.'"'), 'Panel links to matching detail row'); }
check(str_contains($html, 'Database') && str_contains($html, 'esxi-02'), 'Tooltip uses resolved names');
$data['rows'] = [$ports[1], $ports['uplink'], $ports['unknown']];
$extra = $ports[1];
$extra['key'] = 'unresolved-vm';
unset($extra['entity']['name']);
$data['rows'][] = $extra;
$html = (string) \Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer::portPanel($data);
check(substr_count($html, 'class="vmware-monitoring-dvs-port-group"') === 3,
	'Ports are grouped by ID, including a separate missing-group section');
check(substr_count($html, 'Port group ID: pg-1') === 1, 'Non-adjacent ports in the same group share one section');
check(str_contains($html, '>vm-Database</span>'), 'Resolved VM is used as the visible label');
check(str_contains($html, '>uplink-esxi-02</span>'), 'Resolved attached hypervisor is used as the uplink label');
check(str_contains($html, '>unresolved-vm</span>'), 'Unresolved attachment keeps the port ID despite a resolved proxy');
check(str_contains($html, '>unknown</span>'), 'Unattached port keeps the port ID');
$data['rows'] = [];
check(str_contains((string) \Modules\VMwareMonitoring\Includes\VMwareDvswitchRenderer::portPanel($data),
	'No matching ports.'), 'Empty filtered results have a clear message');
echo "Port panel states, names, detail targets and empty results passed.\n";
