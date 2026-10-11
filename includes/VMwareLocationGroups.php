<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use CProfile;

class VMwareLocationGroups {
	public const PROFILE_PREFIX = 'web.vmware.location.collapsed.';

	public static function key(array $path): string {
		return hash('sha256', json_encode($path));
	}

	/** Aggregate before pagination; identities prevent counting shared resources twice. */
	public static function build(array $vcenters): array {
		$groups = [];
		foreach ($vcenters as $vcenter) {
			$path = [];
			foreach ($vcenter['location_path'] ?? [] as $part) {
				$path[] = $part;
				$key = self::key($path);
				if (!isset($groups[$key])) {
					$groups[$key] = [
						'collapsed' => (bool) CProfile::get(self::PROFILE_PREFIX.$key, 0),
						'vcenters' => 0, 'hypervisors' => 0, 'vms' => 0,
						'datastore_ids' => [], 'problem_events' => [], 'problem_hostids' => []
					];
				}
				$group = &$groups[$key];
				$group['vcenters']++;
				$group['hypervisors'] += $vcenter['hypervisors'];
				$group['vms'] += $vcenter['vms'];
				$group['datastore_ids'] += array_fill_keys($vcenter['datastore_ids'], true);
				$group['problem_events'] += $vcenter['problem_events'];
				$group['problem_hostids'] += array_fill_keys($vcenter['problem_hostids'], true);
				unset($group);
			}
		}
		foreach ($groups as &$group) {
			$group['datastores'] = count($group['datastore_ids']);
			$group['problems'] = array_count_values($group['problem_events']);
			$group['problem_hostids'] = array_keys($group['problem_hostids']);
			unset($group['datastore_ids'], $group['problem_events']);
		}
		unset($group);
		return $groups;
	}
}
