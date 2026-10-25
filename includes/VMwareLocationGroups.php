<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use CProfile;
use Modules\VMwareMonitoring\Includes\Common\LocationHierarchy;

require_once __DIR__.'/common/LocationHierarchy.php';

class VMwareLocationGroups extends LocationHierarchy {
	public const PROFILE_PREFIX = 'web.vmware.location.collapsed.';
	public const MACRO = '{$VMWARE.LOCATION}';

	public static function path(array $macros): array {
		return parent::pathFromMacros($macros, self::MACRO);
	}

	/** Aggregate before pagination; identities prevent counting shared resources twice. */
	public static function build(array $vcenters): array {
		return parent::aggregate($vcenters,
			static fn(array $branch): array => [
				'collapsed' => (bool) CProfile::get(self::PROFILE_PREFIX.$branch['key'], 0),
				'vcenters' => 0, 'hypervisors' => 0, 'vms' => 0,
				'datastore_ids' => [], 'problem_events' => [], 'problem_hostids' => []
			],
			static function (array &$group, array $vcenter): void {
				$group['vcenters']++;
				$group['hypervisors'] += $vcenter['hypervisors'];
				$group['vms'] += $vcenter['vms'];
				$group['datastore_ids'] += array_fill_keys($vcenter['datastore_ids'], true);
				$group['problem_events'] += $vcenter['problem_events'];
				$group['problem_hostids'] += array_fill_keys($vcenter['problem_hostids'], true);
			},
			static function (array &$group): void {
				$group['datastores'] = count($group['datastore_ids']);
				$group['problems'] = array_count_values($group['problem_events']);
				$group['problem_hostids'] = array_keys($group['problem_hostids']);
				unset($group['datastore_ids'], $group['problem_events']);
			});
	}
}
