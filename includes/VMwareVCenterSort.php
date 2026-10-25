<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use Modules\VMwareMonitoring\Includes\Common\LocationHierarchy;

require_once __DIR__.'/common/LocationHierarchy.php';

/** Keep location branches together while sorting their vCenters before pagination. */
class VMwareVCenterSort {
	public const FIELDS = ['vcenter', 'health', 'problems', 'version', 'hypervisors', 'vms', 'datastores', 'notes'];

	public static function sort(array &$vcenters, string $field, string $direction): void {
		$field = in_array($field, self::FIELDS, true) ? $field : 'vcenter';
		usort($vcenters, static function (array $left, array $right) use ($field, $direction): int {
			$location = LocationHierarchy::comparePaths($left['location_path'] ?? [], $right['location_path'] ?? []);
			if ($location !== 0) { return $location; }

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
