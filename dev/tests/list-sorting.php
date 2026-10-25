<?php
require __DIR__.'/../../includes/VMwareVCenterSort.php';
use Modules\VMwareMonitoring\Includes\VMwareVCenterSort as Sort;

function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function row(string $name, $health, array $problems, string $version, int $hv, int $vms, int $ds, string $notes): array {
	return ['hostid' => $name, 'name' => $name, 'metrics' => ['health' => $health, 'version' => $version],
		'problems' => $problems, 'hypervisors' => $hv, 'vms' => $vms, 'datastores' => $ds,
		'inventory' => ['notes' => $notes], 'location_path' => []];
}
$rows = [
	row('vc2', 3, [4 => 5], '8.0.10', 2, 50, 12, 'Zebra'),
	row('vc10', 0, [1 => 1, 5 => 1], '8.0.2', 10, 100, 2, 'Alpha'),
	row('vc1', 1, [], '7.0', 3, 20, 7, 'beta')
];
$expected = [
	'vcenter' => ['vc1', 'vc2', 'vc10'], 'health' => ['vc10', 'vc1', 'vc2'],
	'problems' => ['vc1', 'vc10', 'vc2'], 'version' => ['vc1', 'vc10', 'vc2'],
	'hypervisors' => ['vc2', 'vc1', 'vc10'], 'vms' => ['vc1', 'vc2', 'vc10'],
	'datastores' => ['vc10', 'vc1', 'vc2'], 'notes' => ['vc10', 'vc1', 'vc2']
];
foreach ($expected as $field => $names) {
	foreach (['ASC' => $names, 'DESC' => array_reverse($names)] as $direction => $order) {
		$sorted = $rows;
		Sort::sort($sorted, $field, $direction);
		check(array_column($sorted, 'name') === $order, "$field $direction sorts before pagination");
	}
}
foreach ([null, '', 4, 5, 6, 'invalid'] as $health) {
	foreach (['ASC', 'DESC'] as $direction) {
		$sorted = [...$rows, row('unknown', $health, [], '', 0, 0, 0, '')];
		Sort::sort($sorted, 'health', $direction);
		check(end($sorted)['name'] === 'unknown', 'Unknown health does not outrank a known state');
	}
}
$grouped = [];
foreach ([['Zurich'], [], ['Amsterdam'], ['Zurich', 'Rack 2'], ['Amsterdam']] as $index => $path) {
	$entry = $rows[$index % 3];
	$entry['hostid'] = (string) $index;
	$entry['location_path'] = $path;
	$grouped[] = $entry;
}
Sort::sort($grouped, 'hypervisors', 'DESC');
check(array_column($grouped, 'hostid') === ['4', '2', '0', '3', '1'],
	'Branches remain contiguous, parent rows precede nested groups and ungrouped vCenters remain last');
$ties = [$rows[1], $rows[0]];
$ties[0]['hypervisors'] = $ties[1]['hypervisors'] = 2;
Sort::sort($ties, 'hypervisors', 'DESC');
check(array_column($ties, 'name') === ['vc2', 'vc10'], 'Equal values have a stable natural name order');
Sort::sort($rows, 'invalid', 'ASC');
check(array_column($rows, 'name') === ['vc1', 'vc2', 'vc10'], 'Unknown sort fields fall back to vCenter name');
echo "Eight-column ascending/descending sorting, numeric counts, versions, unknown samples and nested locations passed.\n";
