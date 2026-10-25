<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

/** Keep location branches together while sorting their vCenters before pagination. */
class VMwareVCenterSort {
	public const FIELDS = ['vcenter', 'health', 'problems', 'version', 'hypervisors', 'vms', 'datastores', 'notes'];

	public static function sort(array &$vcenters, string $field, string $direction): void {
		$field = in_array($field, self::FIELDS, true) ? $field : 'vcenter';
		usort($vcenters, static function (array $left, array $right) use ($field, $direction): int {
			$left_path = $left['location_path'] ?? [];
			$right_path = $right['location_path'] ?? [];
			if ((bool) $left_path !== (bool) $right_path) {
				return $left_path ? -1 : 1;
			}
			for ($index = 0, $parts = min(count($left_path), count($right_path)); $index < $parts; $index++) {
				$comparison = strnatcasecmp($left_path[$index], $right_path[$index])
					?: strcmp($left_path[$index], $right_path[$index]);
				if ($comparison !== 0) { return $comparison; }
			}
			if (count($left_path) !== count($right_path)) {
				return count($left_path) <=> count($right_path);
			}

			$a = self::value($left, $field);
			$b = self::value($right, $field);
			// Missing samples remain last in both directions.
			if (($a === null) !== ($b === null)) { return $a === null ? 1 : -1; }
			$comparison = $a === null ? 0 : (in_array($field, ['vcenter', 'version', 'notes'], true)
				? strnatcasecmp((string) $a, (string) $b) : $a <=> $b);
			if ($comparison !== 0) { return $direction === 'DESC' ? -$comparison : $comparison; }
			return strnatcasecmp($left['name'], $right['name'])
				?: (string) $left['hostid'] <=> (string) $right['hostid'];
		});
	}

	private static function value(array $vcenter, string $field) {
		return match ($field) {
			'health' => in_array((string) ($vcenter['metrics']['health'] ?? ''), ['0', '1', '2', '3'], true)
				? (int) $vcenter['metrics']['health'] : null,
			'problems' => array_sum($vcenter['problems'] ?? []),
			'version' => ($vcenter['metrics']['version'] ?? '') !== '' ? $vcenter['metrics']['version'] : null,
			'hypervisors', 'vms', 'datastores' => isset($vcenter[$field]) ? (int) $vcenter[$field] : null,
			'notes' => (string) ($vcenter['inventory']['notes'] ?? ''),
			default => $vcenter['name']
		};
	}
}
