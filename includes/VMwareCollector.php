<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use API;
use CSettingsHelper;
use DB;
use Manager;

class VMwareCollector {
	public const VCENTER_KEYS = [
		'vmware.fullname[{$VMWARE.URL}]' => 'fullname',
		'vmware.version[{$VMWARE.URL}]' => 'version',
		'vmware.health.state' => 'health'
	];

	public const HYPERVISOR_KEYS = [
		'vmware.hv.cluster.name[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cluster',
		'vmware.hv.datacenter.name[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'datacenter',
		'vmware.hv.connectionstate[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'connection',
		'vmware.hv.status[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'health',
		'vmware.hv.cpu.usage.perf[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cpu',
		'vmware.hv.memory.used[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_used',
		'vmware.hv.hw.memory[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_total',
		'vmware.hv.vm.num[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'vm_count',
		'vmware.hv.uptime[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'uptime',
		'vmware.hv.version[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'version',
		'vmware.hv.hw.vendor[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'vendor',
		'vmware.hv.hw.model[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'model'
	];

	public const VM_KEYS = [
		'vmware.vm.cluster.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'cluster',
		'vmware.vm.datacenter.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'datacenter',
		'vmware.vm.hv.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'hypervisor',
		'vmware.vm.powerstate[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'power',
		'vmware.vm.state[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'state',
		'vmware.vm.cpu.usage.perf[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'cpu',
		'vmware.vm.memory.usage[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'memory_pct',
		'vmware.vm.memory.size[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'memory_total',
		'vmware.vm.storage.committed[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'storage',
		'vmware.vm.tools[{$VMWARE.URL},{$VMWARE.VM.UUID},status]' => 'tools',
		'vmware.vm.snapshot.count' => 'snapshots',
		'vmware.vm.uptime[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'uptime'
	];

	public const SPARKLINE_PERIOD = 86400;
	private const SPARKLINE_POINTS = 60;

	public static function hasRecentValue(array $item): bool {
		static $minimum_clock = null;

		if ($minimum_clock === null) {
			$minimum_clock = time() - timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));
		}

		return (int) ($item['lastclock'] ?? 0) >= $minimum_clock;
	}

	public static function findVCenterHostids(?array $groupids = null): array {
		$items = API::Item()->get([
			'output' => ['hostid'],
			'groupids' => $groupids,
			'filter' => ['key_' => 'vmware.version[{$VMWARE.URL}]'],
			'monitored' => true
		]) ?: [];

		if (!$items) {
			return [];
		}

		$item_hostids = array_values(array_unique(array_column($items, 'hostid')));
		$accessible_hosts = API::Host()->get([
			'output' => ['hostid'],
			'groupids' => $groupids,
			'preservekeys' => true
		]) ?: [];

		return array_values(array_intersect($item_hostids, array_keys($accessible_hosts)));
	}

	public static function collectVCenterMetrics(array $hosts): array {
		$result = [];
		$empty = array_fill_keys(array_values(self::VCENTER_KEYS), null);

		foreach ($hosts as $hostid => $host) {
			$result[$hostid] = $host + [
				'metrics' => $empty,
				'hypervisors' => 0,
				'vms' => 0,
				'datastores' => 0,
				'datastore_attachments' => 0
			];
		}

		if (!$hosts) {
			return $result;
		}

		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => array_keys($hosts),
			'filter' => ['key_' => array_keys(self::VCENTER_KEYS)],
			'monitored' => true
		]) ?: [];

		foreach ($items as $item) {
			if (isset(self::VCENTER_KEYS[$item['key_']]) && self::hasRecentValue($item)) {
				$result[$item['hostid']]['metrics'][self::VCENTER_KEYS[$item['key_']]] = $item['lastvalue'];
			}
		}

		foreach (array_keys($hosts) as $hostid) {
			$topology = self::discoveredTopology($hostid);
			$attachments = self::datastores(array_keys($topology['hypervisors']), false);
			$result[$hostid]['hypervisors'] = count($topology['hypervisors']);
			$result[$hostid]['vms'] = self::reportedVmCount(array_keys($topology['hypervisors']));
			$result[$hostid]['datastores'] = count(self::uniqueDatastores($attachments));
			$result[$hostid]['datastore_attachments'] = count($attachments);
		}

		return $result;
	}

	public static function summary(string $vcenter_hostid, bool $include_datastores = true): array {
		$topology = self::discoveredTopology($vcenter_hostid);
		$vcenter = self::itemsByKeys([$vcenter_hostid], self::VCENTER_KEYS)[$vcenter_hostid]
			?? array_fill_keys(array_values(self::VCENTER_KEYS), null);
		$attachments = $include_datastores
			? self::datastores(array_keys($topology['hypervisors']), false)
			: [];
		$reported_vms = self::reportedVmCount(array_keys($topology['hypervisors']));

		return [
			'vcenter_metrics' => $vcenter,
			'hypervisors_count' => count($topology['hypervisors']),
			'vms_count' => count($topology['vms']),
			'reported_vms_count' => $reported_vms,
			'datastores_count' => count(self::uniqueDatastores($attachments)),
			'datastore_attachments_count' => count($attachments)
		];
	}

	public static function discoveredTopology(string $vcenter_hostid, bool $include_hypervisors = true,
			bool $include_vms = true): array {
		$rules = API::DiscoveryRule()->get([
			'output' => ['itemid', 'key_'],
			'hostids' => [$vcenter_hostid],
			'filter' => ['key_' => [
				'vmware.hv.discovery[{$VMWARE.URL}]',
				'vmware.vm.discovery[{$VMWARE.URL}]'
			]],
			'preservekeys' => true
		]) ?: [];

		$rule_types = [];
		foreach ($rules as $rule) {
			$type = str_starts_with($rule['key_'], 'vmware.hv.')
				? 'hypervisors'
				: 'vms';
			if (($type === 'hypervisors' && $include_hypervisors) || ($type === 'vms' && $include_vms)) {
				$rule_types[(string) $rule['itemid']] = $type;
			}
		}

		$result = ['hypervisors' => [], 'vms' => []];
		if (!$rule_types) {
			return $result;
		}

		$prototypes = DB::select('host_discovery', [
			'output' => ['hostid', 'parent_itemid'],
			'filter' => ['parent_itemid' => array_keys($rule_types)]
		]);
		$prototype_types = [];
		foreach ($prototypes as $prototype) {
			$ruleid = (string) $prototype['parent_itemid'];
			$prototype_types[(string) $prototype['hostid']] = $rule_types[$ruleid];
		}
		if (!$prototype_types) {
			return $result;
		}

		$discovered = DB::select('host_discovery', [
			'output' => ['hostid', 'parent_hostid'],
			'filter' => ['parent_hostid' => array_keys($prototype_types)]
		]);
		$host_types = [];
		foreach ($discovered as $host) {
			$prototypeid = (string) $host['parent_hostid'];
			$host_types[(string) $host['hostid']] = $prototype_types[$prototypeid];
		}
		if (!$host_types) {
			return $result;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'],
			'hostids' => array_keys($host_types),
			'preservekeys' => true
		]) ?: [];

		foreach ($hosts as $hostid => $host) {
			if (!isset($host_types[$hostid])) {
				continue;
			}

			$result[$host_types[$hostid]][$hostid] = $host;
		}

		return $result;
	}

	public static function vcenterForDiscoveredHost(string $hostid): ?array {
		$discovered = DB::select('host_discovery', [
			'output' => ['parent_hostid'],
			'filter' => ['hostid' => [$hostid]]
		]);
		if (!$discovered) {
			return null;
		}

		$prototypeid = (string) reset($discovered)['parent_hostid'];
		$prototypes = DB::select('host_discovery', [
			'output' => ['parent_itemid'],
			'filter' => ['hostid' => [$prototypeid]]
		]);
		if (!$prototypes) {
			return null;
		}

		$ruleid = (string) reset($prototypes)['parent_itemid'];
		$rules = API::DiscoveryRule()->get([
			'output' => ['hostid', 'key_'],
			'itemids' => [$ruleid]
		]) ?: [];
		if (!$rules || (string) reset($rules)['key_'] !== 'vmware.hv.discovery[{$VMWARE.URL}]') {
			return null;
		}

		$vcenter_hostid = (string) reset($rules)['hostid'];
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => [$vcenter_hostid]
		]) ?: [];

		return $hosts ? reset($hosts) : null;
	}

	public static function hypervisorsPage(string $vcenter_hostid, int $page, int $per_page,
			string $search = '', string $sort = 'name', string $sortorder = ZBX_SORT_UP): array {
		$topology = self::discoveredTopology($vcenter_hostid, true, false);
		$hypervisors = $topology['hypervisors'];
		$index_keys = [
			'vmware.hv.cluster.name[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cluster'
		];
		foreach (match ($sort) {
			'cpu' => ['cpu'],
			'memory' => ['memory_used', 'memory_total'],
			'vms' => ['vm_count'],
			'uptime' => ['uptime'],
			'version' => ['version'],
			default => []
		} as $field) {
			$key = array_search($field, self::HYPERVISOR_KEYS, true);
			if ($key !== false) {
				$index_keys[$key] = $field;
			}
		}
		$index_metrics = self::itemsByKeys(array_keys($hypervisors), $index_keys);

		if ($search !== '') {
			$needle = mb_strtolower($search);
			$hypervisors = array_filter($hypervisors, static function (array $host) use (
					$needle, $index_metrics): bool {
				$cluster = trim((string) ($index_metrics[$host['hostid']]['cluster'] ?? ''));
				$cluster = $cluster !== '' ? $cluster : _('Standalone (no cluster)');
				return str_contains(mb_strtolower($host['name'].' '.$cluster), $needle);
			});
		}

		uasort($hypervisors, static function (array $left, array $right) use (
				$sort, $sortorder, $index_metrics): int {
			$left_value = self::hypervisorSortValue($left, $index_metrics[$left['hostid']] ?? [], $sort);
			$right_value = self::hypervisorSortValue($right, $index_metrics[$right['hostid']] ?? [], $sort);

			if ($left_value === null || $right_value === null) {
				if ($left_value === $right_value) {
					$comparison = 0;
				}
				else {
					return $left_value === null ? 1 : -1;
				}
			}
			elseif (is_float($left_value) || is_int($left_value)) {
				$comparison = $left_value <=> $right_value;
			}
			else {
				$comparison = strnatcasecmp((string) $left_value, (string) $right_value);
			}

			if ($comparison === 0) {
				$comparison = strnatcasecmp($left['name'], $right['name']);
			}
			return $sortorder === ZBX_SORT_DOWN ? -$comparison : $comparison;
		});

		$paged = self::paginateArray($hypervisors, $page, $per_page);
		$metrics = self::itemsByKeys(array_keys($paged['rows']), self::HYPERVISOR_KEYS);
		$problems = self::problemsByHosts(array_keys($paged['rows']));

		foreach ($paged['rows'] as $hostid => &$host) {
			$host['metrics'] = $metrics[$hostid]
				?? array_fill_keys(array_values(self::HYPERVISOR_KEYS), null);
			$host['problems'] = $problems[$hostid] ?? [];
		}
		unset($host);

		$paged['rows'] = array_values($paged['rows']);
		$paged['search'] = $search;
		$paged['sort'] = $sort;
		$paged['sortorder'] = $sortorder;
		return $paged;
	}

	public static function virtualMachinesPage(string $vcenter_hostid, int $page, int $per_page,
			string $search = ''): array {
		$topology = self::discoveredTopology($vcenter_hostid, false, true);
		$vms = $topology['vms'];
		uasort($vms, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		if ($search !== '') {
			$needle = mb_strtolower($search);
			$placement = self::itemsByKeys(array_keys($vms), [
				'vmware.vm.cluster.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'cluster',
				'vmware.vm.datacenter.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'datacenter',
				'vmware.vm.hv.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'hypervisor'
			]);
			$vms = array_filter($vms, static function (array $vm) use ($needle, $placement): bool {
				if (str_contains(mb_strtolower($vm['name']), $needle)) {
					return true;
				}
				$metrics = $placement[$vm['hostid']] ?? [];
				return str_contains(mb_strtolower(implode(' ', [
					(string) ($metrics['cluster'] ?? ''), (string) ($metrics['datacenter'] ?? ''),
					(string) ($metrics['hypervisor'] ?? '')
				])), $needle);
			});
		}

		$paged = self::paginateArray($vms, $page, $per_page);
		$metrics = self::itemsByKeys(array_keys($paged['rows']), self::VM_KEYS);
		$problems = self::problemsByHosts(array_keys($paged['rows']));
		$inventory = API::Host()->get([
			'output' => ['hostid'],
			'selectInventory' => ['notes'],
			'hostids' => array_keys($paged['rows']),
			'preservekeys' => true
		]) ?: [];

		foreach ($paged['rows'] as $hostid => &$host) {
			$host['metrics'] = $metrics[$hostid] ?? array_fill_keys(array_values(self::VM_KEYS), null);
			$host['problems'] = $problems[$hostid] ?? [];
			$host['inventory'] = $inventory[$hostid]['inventory'] ?? [];
		}
		unset($host);
		$paged['rows'] = array_values($paged['rows']);
		return $paged;
	}

	public static function datastorePages(string $vcenter_hostid, int $page, int $per_page,
			string $search = '', string $sort = 'name', string $sortorder = ZBX_SORT_UP): array {
		$topology = self::discoveredTopology($vcenter_hostid, true, false);
		$host_names = array_column($topology['hypervisors'], 'name', 'hostid');
		$attachments = self::datastores(array_keys($topology['hypervisors']), true);

		foreach ($attachments as &$attachment) {
			$attachment['hypervisor'] = $host_names[$attachment['hostid']] ?? '';
		}
		unset($attachment);

		$unique = self::uniqueDatastores($attachments);
		if ($search !== '') {
			$needle = mb_strtolower($search);
			$unique = array_filter($unique, static function (array $row) use ($needle): bool {
				$haystack = $row['name'].' '.$row['type'].' '.implode(' ', $row['hypervisors']);
				return str_contains(mb_strtolower($haystack), $needle);
			});
		}

		self::sortDatastoreRows($unique, $sort, $sortorder);
		$unique_page = self::paginateArray($unique, $page, $per_page);
		$unique_page['rows'] = array_values($unique_page['rows']);

		$attachments_by_datastore = [];
		foreach ($attachments as $attachment) {
			$attachments_by_datastore[self::datastoreIdentity($attachment)][] = $attachment;
		}
		foreach ($attachments_by_datastore as &$datastore_attachments) {
			usort($datastore_attachments,
				static fn(array $a, array $b): int => strnatcasecmp($a['hypervisor'], $b['hypervisor'])
			);
		}
		unset($datastore_attachments);

		foreach ($unique_page['rows'] as &$datastore) {
			$datastore['attachment_rows'] = $attachments_by_datastore[$datastore['identity']] ?? [];
		}
		unset($datastore);

		return [
			'search' => $search,
			'sort' => $sort,
			'sortorder' => $sortorder,
			'unique' => $unique_page
		];
	}

	public static function clusters(string $vcenter_hostid): array {
		$topology = self::discoveredTopology($vcenter_hostid, true, true);
		$hv_metrics = self::itemsByKeys(array_keys($topology['hypervisors']), [
			'vmware.hv.cluster.name[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cluster',
			'vmware.hv.hw.memory[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_total',
			'vmware.hv.memory.used[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_used',
			'vmware.hv.vm.num[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'vm_count'
		]);
		$vm_metrics = self::itemsByKeys(array_keys($topology['vms']), [
			'vmware.vm.cluster.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'cluster'
		]);
		$status_items = API::Item()->get([
			'output' => ['name', 'lastvalue', 'lastclock'],
			'selectTags' => ['tag', 'value'],
			'hostids' => [$vcenter_hostid],
			'search' => ['key_' => 'vmware.cluster.status['],
			'startSearch' => true,
			'monitored' => true
		]) ?: [];

		$result = [];
		foreach ($status_items as $item) {
			$tags = array_column($item['tags'] ?? [], 'value', 'tag');
			$name = trim((string) ($tags['cluster'] ?? ''));
			if ($name !== '') {
				$result[$name] = [
					'name' => $name, 'vcenter_hostid' => $vcenter_hostid,
					'status' => self::hasRecentValue($item) ? $item['lastvalue'] : null,
					'hypervisors' => 0, 'virtual_machines' => 0, 'discovered_vms' => 0,
					'memory_total' => 0.0, 'memory_used' => 0.0
				];
			}
		}

		foreach ($topology['hypervisors'] as $hostid => $host) {
			$m = $hv_metrics[$hostid] ?? [];
			if (($m['cluster'] ?? null) === null) {
				continue;
			}
			$name = trim((string) ($m['cluster'] ?? ''));
			$name = $name !== '' ? $name : _('Standalone (no cluster)');
			$result[$name] ??= [
				'name' => $name, 'vcenter_hostid' => $vcenter_hostid, 'status' => null, 'hypervisors' => 0,
				'virtual_machines' => 0, 'discovered_vms' => 0,
				'memory_total' => 0.0, 'memory_used' => 0.0
			];
			$result[$name]['hypervisors']++;
			$result[$name]['virtual_machines'] += (int) ($m['vm_count'] ?? 0);
			$result[$name]['memory_total'] += (float) ($m['memory_total'] ?? 0);
			$result[$name]['memory_used'] += (float) ($m['memory_used'] ?? 0);
		}

		foreach ($topology['vms'] as $hostid => $vm) {
			$cluster = $vm_metrics[$hostid]['cluster'] ?? null;
			if ($cluster === null) {
				continue;
			}
			$name = trim((string) $cluster);
			$name = $name !== '' ? $name : _('Standalone (no cluster)');
			if (isset($result[$name])) {
				$result[$name]['discovered_vms']++;
			}
		}
		uksort($result, 'strnatcasecmp');
		return array_values($result);
	}

	public static function clusterDetail(string $vcenter_hostid, string $cluster_name): ?array {
		$topology = self::discoveredTopology($vcenter_hostid, true, true);
		$key_map = [
			'vmware.hv.cluster.name[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cluster',
			'vmware.hv.connectionstate[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'connection',
			'vmware.hv.status[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'health',
			'vmware.hv.cpu.usage.perf[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cpu_pct',
			'vmware.hv.cpu.usage[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cpu_used',
			'vmware.hv.hw.cpu.num[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cpu_cores',
			'vmware.hv.hw.cpu.threads[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cpu_threads',
			'vmware.hv.hw.cpu.freq[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cpu_frequency',
			'vmware.hv.hw.cpu.model[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cpu_model',
			'vmware.hv.hw.vendor[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'vendor',
			'vmware.hv.hw.model[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'model',
			'vmware.hv.memory.used[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_used',
			'vmware.hv.hw.memory[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_total',
			'vmware.hv.memory.size.ballooned[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_ballooned',
			'vmware.hv.network.in[{$VMWARE.URL},{$VMWARE.HV.UUID},bps]' => 'network_in',
			'vmware.hv.network.out[{$VMWARE.URL},{$VMWARE.HV.UUID},bps]' => 'network_out',
			'vmware.hv.network.in[{$VMWARE.URL},{$VMWARE.HV.UUID},dropped]' => 'network_dropped_in',
			'vmware.hv.network.out[{$VMWARE.URL},{$VMWARE.HV.UUID},dropped]' => 'network_dropped_out',
			'vmware.hv.network.in[{$VMWARE.URL},{$VMWARE.HV.UUID},errors]' => 'network_errors_in',
			'vmware.hv.network.out[{$VMWARE.URL},{$VMWARE.HV.UUID},errors]' => 'network_errors_out',
			'vmware.hv.power[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'power',
			'vmware.hv.power[{$VMWARE.URL},{$VMWARE.HV.UUID},max]' => 'power_max',
			'vmware.hv.vm.num[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'vm_count',
			'vmware.hv.uptime[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'uptime',
			'vmware.hv.version[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'version'
		];
		$metrics = self::itemsByKeys(array_keys($topology['hypervisors']), $key_map);
		$standalone_name = _('Standalone (no cluster)');
		$is_standalone = $cluster_name === $standalone_name;
		$hypervisors = [];

		foreach ($topology['hypervisors'] as $hostid => $host) {
			$host_metrics = $metrics[$hostid] ?? array_fill_keys(array_values($key_map), null);
			$actual_cluster = trim((string) ($host_metrics['cluster'] ?? ''));
			$matches = $is_standalone
				? $host_metrics['cluster'] !== null && $actual_cluster === ''
				: $actual_cluster === $cluster_name;
			if (!$matches) {
				continue;
			}

			$host_metrics['cpu_capacity'] = $host_metrics['cpu_cores'] !== null
					&& $host_metrics['cpu_frequency'] !== null
				? (float) $host_metrics['cpu_cores'] * (float) $host_metrics['cpu_frequency']
				: null;
			$host['metrics'] = $host_metrics;
			$hypervisors[$hostid] = $host;
		}

		$cluster_status = null;
		$cluster_triggerids = [];
		$cluster_exists = $is_standalone && (bool) $hypervisors;
		if (!$is_standalone) {
			$status_items = API::Item()->get([
				'output' => ['lastvalue', 'lastclock'],
				'selectTags' => ['tag', 'value'],
				'selectTriggers' => ['triggerid'],
				'hostids' => [$vcenter_hostid],
				'search' => ['key_' => 'vmware.cluster.status['],
				'startSearch' => true,
				'monitored' => true
			]) ?: [];
			foreach ($status_items as $item) {
				$tags = array_column($item['tags'] ?? [], 'value', 'tag');
				if (trim((string) ($tags['cluster'] ?? '')) === $cluster_name) {
					$cluster_exists = true;
					$cluster_status = self::hasRecentValue($item) ? $item['lastvalue'] : null;
					$cluster_triggerids = array_column($item['triggers'] ?? [], 'triggerid');
					break;
				}
			}
		}

		if (!$cluster_exists && !$hypervisors) {
			return null;
		}

		$problems = self::problemsByHosts(array_keys($hypervisors));
		$cluster_problems = $cluster_triggerids
			? API::Problem()->get([
				'output' => ['eventid', 'severity'],
				'objectids' => $cluster_triggerids,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'suppressed' => false
			]) ?: []
			: [];
		$totals = [
			'cpu_capacity' => 0.0, 'cpu_used' => 0.0, 'memory_total' => 0.0, 'memory_used' => 0.0,
			'vm_count' => 0, 'problems' => count($cluster_problems), 'problem_severities' => [],
			'connected' => 0, 'connection_known' => 0, 'cpu_cores' => 0, 'cpu_threads' => 0,
			'memory_ballooned' => 0.0, 'network_in' => 0.0, 'network_out' => 0.0,
			'network_dropped_in' => 0.0, 'network_dropped_out' => 0.0,
			'network_errors_in' => 0.0, 'network_errors_out' => 0.0,
			'power' => 0.0, 'power_max' => 0.0,
			'minimum_uptime' => null, 'versions' => [], 'vendors' => [], 'models' => [], 'cpu_models' => [],
			'known' => []
		];
		foreach ($cluster_problems as $problem) {
			$severity = (int) $problem['severity'];
			$totals['problem_severities'][$severity] = ($totals['problem_severities'][$severity] ?? 0) + 1;
		}
		$complete = ['cpu_capacity' => (bool) $hypervisors, 'cpu_used' => (bool) $hypervisors,
			'memory_total' => (bool) $hypervisors, 'memory_used' => (bool) $hypervisors];

		foreach ($hypervisors as $hostid => &$host) {
			$m = $host['metrics'];
			$host['problems'] = $problems[$hostid] ?? [];
			$totals['vm_count'] += (int) ($m['vm_count'] ?? 0);
			$totals['problems'] += array_sum($host['problems']);
			foreach ($host['problems'] as $severity => $count) {
				$totals['problem_severities'][$severity] =
					($totals['problem_severities'][$severity] ?? 0) + $count;
			}
			if ($m['connection'] !== null) {
				$totals['connection_known']++;
				$totals['connected'] += (string) $m['connection'] === '0' ? 1 : 0;
			}
			foreach (['cpu_cores', 'cpu_threads', 'memory_ballooned', 'network_in', 'network_out',
				'network_dropped_in', 'network_dropped_out', 'network_errors_in', 'network_errors_out',
				'power', 'power_max'] as $field) {
				if ($m[$field] !== null) {
					$totals[$field] += (float) $m[$field];
					$totals['known'][$field] = ($totals['known'][$field] ?? 0) + 1;
				}
			}
			if ($m['uptime'] !== null) {
				$totals['minimum_uptime'] = $totals['minimum_uptime'] === null
					? (float) $m['uptime'] : min($totals['minimum_uptime'], (float) $m['uptime']);
			}
			foreach (['version' => 'versions', 'vendor' => 'vendors', 'model' => 'models',
				'cpu_model' => 'cpu_models'] as $field => $distribution) {
				$value = trim((string) ($m[$field] ?? ''));
				if ($value !== '') {
					$totals[$distribution][$value] = ($totals[$distribution][$value] ?? 0) + 1;
				}
			}

			foreach (['cpu_capacity', 'cpu_used', 'memory_total', 'memory_used'] as $field) {
				if ($m[$field] === null) {
					$complete[$field] = false;
				}
				else {
					$totals[$field] += (float) $m[$field];
				}
			}
		}
		unset($host);
		uasort($hypervisors, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		$vm_metrics = self::itemsByKeys(array_keys($topology['vms']), [
			'vmware.vm.cluster.name[{$VMWARE.URL},{$VMWARE.VM.UUID}]' => 'cluster'
		]);
		$discovered_vms = 0;
		foreach ($topology['vms'] as $hostid => $vm) {
			$vm_cluster_value = $vm_metrics[$hostid]['cluster'] ?? null;
			$vm_cluster = trim((string) ($vm_cluster_value ?? ''));
			if (($is_standalone && $vm_cluster_value !== null && $vm_cluster === '')
					|| (!$is_standalone && $vm_cluster === $cluster_name)) {
				$discovered_vms++;
			}
		}

		$attachments = self::datastores(array_keys($hypervisors), true);
		foreach ($attachments as &$attachment) {
			$hostid = (string) $attachment['hostid'];
			$attachment['hypervisor'] = $hypervisors[$hostid]['name'] ?? '';
		}
		unset($attachment);
		$datastores = self::uniqueDatastores($attachments);
		$datastore_totals = ['count' => count($datastores), 'attachments' => count($attachments),
			'capacity' => 0.0, 'free' => 0.0, 'lowest_free_pct' => null];
		foreach ($datastores as $datastore) {
			if ($datastore['total'] !== null) {
				$datastore_totals['capacity'] += (float) $datastore['total'];
				if ($datastore['free_pct'] !== null) {
					$datastore_totals['free'] += (float) $datastore['total'] * (float) $datastore['free_pct'] / 100;
				}
			}
			if ($datastore['free_pct'] !== null) {
				$datastore_totals['lowest_free_pct'] = $datastore_totals['lowest_free_pct'] === null
					? (float) $datastore['free_pct']
					: min($datastore_totals['lowest_free_pct'], (float) $datastore['free_pct']);
			}
		}

		$sensor_summary = ['total' => 0, 'states' => [], 'types' => []];
		if ($hypervisors) {
			$sensor_items = API::Item()->get([
				'output' => ['key_', 'lastvalue', 'lastclock'],
				'selectTags' => ['tag', 'value'],
				'hostids' => array_keys($hypervisors),
				'search' => ['key_' => 'vmware.hv.sensor.state['],
				'startSearch' => true,
				'monitored' => true,
				'webitems' => false
			]) ?: [];
			foreach ($sensor_items as $item) {
				if (!str_starts_with((string) $item['key_'], 'vmware.hv.sensor.state[')) {
					continue;
				}
				$tags = array_column($item['tags'] ?? [], 'value', 'tag');
				$type = trim((string) ($tags['type'] ?? '')) ?: _('Other');
				$state = self::hasRecentValue($item) ? (string) $item['lastvalue'] : 'unknown';
				$sensor_summary['total']++;
				$sensor_summary['states'][$state] = ($sensor_summary['states'][$state] ?? 0) + 1;
				$sensor_summary['types'][$type] = ($sensor_summary['types'][$type] ?? 0) + 1;
			}
			uksort($sensor_summary['types'], 'strnatcasecmp');
		}

		$capacity = [
			'cpu_utilization' => $complete['cpu_capacity'] && $complete['cpu_used']
					&& $totals['cpu_capacity'] > 0
				? $totals['cpu_used'] / $totals['cpu_capacity'] * 100
				: null,
			'memory_utilization' => $complete['memory_total'] && $complete['memory_used']
					&& $totals['memory_total'] > 0
				? $totals['memory_used'] / $totals['memory_total'] * 100
				: null
		];

		$native = [];
		if (!$is_standalone) {
			$allowed_native_fields = [
				'tags', 'summary.numHosts', 'summary.numEffectiveHosts',
				'summary.numCpuCores', 'summary.numCpuThreads', 'summary.totalCpu',
				'summary.effectiveCpu', 'summary.totalMemory', 'summary.effectiveMemory',
				'summary.usageSummary.cpuReservationMhz',
				'perf:"clusterServices/effectivecpu[average]"',
				'perf:"clusterServices/effectivemem[average]"'
			];
			$native_items = API::Item()->get([
				'output' => ['name', 'key_', 'lastvalue', 'lastclock', 'state', 'error', 'units'],
				'selectTags' => ['tag', 'value'],
				'hostids' => [$vcenter_hostid],
				'search' => ['key_' => 'vmware.cl'],
				'startSearch' => true,
				'monitored' => true,
				'webitems' => false
			]) ?: [];
			foreach ($native_items as $item) {
				$tags = array_column($item['tags'] ?? [], 'value', 'tag');
				if (trim((string) ($tags['cluster'] ?? '')) !== $cluster_name
						|| !self::hasRecentValue($item)
						|| (int) ($item['state'] ?? ITEM_STATE_NOTSUPPORTED) !== ITEM_STATE_NORMAL) {
					continue;
				}
				$key = (string) $item['key_'];
				$field = null;
				if (preg_match('/vmware\.cluster\.property\[.*?,[^,\]]+,([^,\]]+)\]$/', $key, $match)) {
					$field = $match[1];
				}
				elseif (preg_match('/vmware\.cl\.perfcounter\[.*?,[^,\]]+,(.*)\]$/', $key, $match)) {
					$field = 'perf:'.$match[1];
				}
				elseif (str_starts_with($key, 'vmware.cluster.tags.get[')) {
					$field = 'tags';
				}
				if ($field !== null && in_array($field, $allowed_native_fields, true)) {
					$native[$field] = [
						'name' => (string) $item['name'], 'value' => (string) $item['lastvalue'],
						'units' => (string) ($item['units'] ?? ''), 'lastclock' => (int) $item['lastclock']
					];
				}
			}
		}

		return [
			'name' => $cluster_name,
			'status' => $cluster_status,
			'is_standalone' => $is_standalone,
			'hypervisors' => array_values($hypervisors),
			'totals' => $totals,
			'complete' => $complete,
			'capacity' => $capacity,
			'native' => $native,
			'discovered_vms' => $discovered_vms,
			'datastores' => $datastores,
			'datastore_totals' => $datastore_totals,
			'sensors' => $sensor_summary
		];
	}

	public static function alarms(string $vcenter_hostid): array {
		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'lastvalue', 'lastclock'],
			'selectTriggers' => ['triggerid', 'priority'],
			'hostids' => [$vcenter_hostid],
			'search' => ['key_' => 'vmware.alarms.status['],
			'startSearch' => true,
			'monitored' => true,
			'sortfield' => 'name'
		]) ?: [];

		$items = array_values(array_filter($items,
			static fn(array $item): bool => self::hasRecentValue($item) && (string) $item['lastvalue'] !== '-1'
		));
		foreach ($items as &$item) {
			$item['severity'] = null;
			foreach ($item['triggers'] ?? [] as $trigger) {
				$item['severity'] = max((int) ($item['severity'] ?? 0), (int) $trigger['priority']);
			}
		}
		unset($item);
		return $items;
	}

	public static function sensors(string $hostid): array {
		$items = API::Item()->get([
			'output' => [
				'itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'status', 'state', 'error'
			],
			'selectTags' => ['tag', 'value'],
			'selectTriggers' => ['triggerid', 'description', 'priority', 'value', 'status', 'lastchange'],
			'hostids' => [$hostid],
			'search' => ['key_' => 'vmware.hv.sensor.state['],
			'startSearch' => true,
			'monitored' => true,
			'webitems' => false
		]) ?: [];

		$result = [];
		foreach ($items as $item) {
			// Do not include the separate sensor-health rollup item.
			if (!str_starts_with((string) $item['key_'], 'vmware.hv.sensor.state[')) {
				continue;
			}

			$name = (string) $item['name'];
			if (preg_match('/^Sensor \[(.*)\] health state$/u', $name, $matches)) {
				$name = $matches[1];
			}

			$type = '';
			foreach ($item['tags'] ?? [] as $tag) {
				if (($tag['tag'] ?? '') === 'type') {
					$type = (string) ($tag['value'] ?? '');
					break;
				}
			}

			$problems = [];
			foreach ($item['triggers'] ?? [] as $trigger) {
				if ((int) ($trigger['value'] ?? TRIGGER_VALUE_FALSE) === TRIGGER_VALUE_TRUE
						&& (int) ($trigger['status'] ?? TRIGGER_STATUS_DISABLED) === TRIGGER_STATUS_ENABLED) {
					$problems[] = [
						'triggerid' => (string) $trigger['triggerid'],
						'name' => (string) $trigger['description'],
						'severity' => (int) $trigger['priority'],
						'lastchange' => (int) $trigger['lastchange']
					];
				}
			}
			usort($problems, static fn(array $a, array $b): int =>
				$b['severity'] <=> $a['severity'] ?: $b['lastchange'] <=> $a['lastchange']
			);

			$has_value = (int) ($item['lastclock'] ?? 0) > 0;
			$result[] = [
				'itemid' => (string) $item['itemid'],
				'name' => $name,
				'type' => $type,
				'value' => $has_value ? (string) $item['lastvalue'] : null,
				'lastclock' => (int) ($item['lastclock'] ?? 0),
				'state' => (int) ($item['state'] ?? ITEM_STATE_NORMAL),
				'error' => (string) ($item['error'] ?? ''),
				'problems' => $problems
			];
		}

		return $result;
	}

	private static function itemsByKeys(array $hostids, array $key_map): array {
		if (!$hostids) {
			return [];
		}

		$result = [];
		$empty = array_fill_keys(array_values($key_map), null);
		foreach ($hostids as $hostid) {
			$result[$hostid] = $empty;
		}

		$items = API::Item()->get([
			'output' => ['hostid', 'itemid', 'key_', 'lastvalue', 'lastclock', 'value_type'],
			'hostids' => $hostids,
			'filter' => ['key_' => array_keys($key_map)],
			'monitored' => true
		]) ?: [];

		foreach ($items as $item) {
			if (!isset($key_map[$item['key_']]) || !self::hasRecentValue($item)) {
				continue;
			}

			$field = $key_map[$item['key_']];
			$result[$item['hostid']][$field] = $item['lastvalue'];
			if (in_array($field, ['cpu', 'memory_pct'], true)) {
				$result[$item['hostid']][$field.'_itemid'] = $item['itemid'];
				$result[$item['hostid']][$field.'_value_type'] = $item['value_type'];
			}
		}

		return $result;
	}

	private static function datastores(array $hypervisor_hostids, bool $with_metrics): array {
		if (!$hypervisor_hostids) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['hostid', 'itemid', 'name', 'key_', 'lastvalue', 'lastclock'],
			'selectTags' => ['tag', 'value'],
			'hostids' => $hypervisor_hostids,
			'search' => [
				'key_' => $with_metrics ? 'vmware.hv.datastore.' : 'vmware.hv.datastore.size['
			],
			'startSearch' => true,
			'monitored' => true
		]) ?: [];

		$result = [];
		foreach ($items as $item) {
			$tags = [];
			foreach ($item['tags'] ?? [] as $tag) {
				$tags[$tag['tag']] = $tag['value'];
			}

			$name = $tags['datastore'] ?? self::datastoreNameFromItem($item['name']);
			if ($name === '') {
				continue;
			}

			$uuid = self::datastoreUuidFromKey($item['key_']);
			$key = $item['hostid'].'|'.($uuid !== '' ? $uuid : $name);
			if (!isset($result[$key])) {
				$result[$key] = [
					'name' => $name,
					'uuid' => $uuid,
					'type' => $tags['type'] ?? '',
					'hostid' => $item['hostid'],
					'total' => null,
					'free_pct' => null,
					'read_latency' => null,
					'write_latency' => null,
					'read_iops' => null,
					'write_iops' => null,
					'multipath' => null
				];
			}

			if (!$with_metrics || !self::hasRecentValue($item)) {
				continue;
			}

			$key_ = $item['key_'];
			$field = null;
			if (str_contains($key_, '.size[') && str_ends_with($key_, ',pfree]')) {
				$field = 'free_pct';
			}
			elseif (str_contains($key_, '.size[')) {
				$field = 'total';
			}
			elseif (str_contains($key_, '.read[') && str_ends_with($key_, ',latency]')) {
				$field = 'read_latency';
			}
			elseif (str_contains($key_, '.write[') && str_ends_with($key_, ',latency]')) {
				$field = 'write_latency';
			}
			elseif (str_contains($key_, '.read[') && str_ends_with($key_, ',rps]')) {
				$field = 'read_iops';
			}
			elseif (str_contains($key_, '.write[') && str_ends_with($key_, ',rps]')) {
				$field = 'write_iops';
			}
			elseif (str_contains($key_, '.multipath[')) {
				$field = 'multipath';
			}

			if ($field !== null) {
				$result[$key][$field] = $item['lastvalue'];
			}
		}

		uasort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		return array_values($result);
	}

	private static function uniqueDatastores(array $attachments): array {
		$result = [];
		foreach ($attachments as $attachment) {
			$identity = self::datastoreIdentity($attachment);
			if (!isset($result[$identity])) {
				$result[$identity] = $attachment + [
					'identity' => $identity,
					'hypervisors' => [],
					'attachments' => 0
				];
				unset($result[$identity]['hostid'], $result[$identity]['hypervisor']);
			}
			elseif ($result[$identity]['total'] === null && $attachment['total'] !== null) {
				$result[$identity]['total'] = $attachment['total'];
			}

			if ($attachment['free_pct'] !== null && (
					$result[$identity]['free_pct'] === null
					|| (float) $attachment['free_pct'] < (float) $result[$identity]['free_pct'])) {
				$result[$identity]['free_pct'] = $attachment['free_pct'];
			}

			$hypervisor = trim((string) ($attachment['hypervisor'] ?? ''));
			if ($hypervisor !== '') {
				$result[$identity]['hypervisors'][(string) $attachment['hostid']] = $hypervisor;
			}
			$result[$identity]['attachments']++;
		}

		foreach ($result as &$datastore) {
			natcasesort($datastore['hypervisors']);
		}
		unset($datastore);
		uasort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		return array_values($result);
	}

	private static function datastoreIdentity(array $datastore): string {
		return $datastore['uuid'] !== ''
			? $datastore['uuid']
			: mb_strtolower($datastore['name'].'|'.$datastore['type']);
	}

	private static function datastoreNameFromItem(string $name): string {
		return preg_match('/\[([^\]]+)\]/', $name, $matches) === 1 ? $matches[1] : '';
	}

	private static function datastoreUuidFromKey(string $key): string {
		return preg_match('/^vmware\.hv\.datastore\.[^\[]+\[[^,]+,[^,]+,([^,\]]+)/', $key, $matches) === 1
			? trim($matches[1], " \t\n\r\0\x0B\"")
			: '';
	}

	private static function hypervisorSortValue(array $host, array $metrics, string $sort) {
		return match ($sort) {
			'cluster' => ($cluster = trim((string) ($metrics['cluster'] ?? ''))) !== ''
				? $cluster
				: _('Standalone (no cluster)'),
			'cpu' => $metrics['cpu'] !== null ? (float) $metrics['cpu'] : null,
			'memory' => (float) ($metrics['memory_total'] ?? 0) > 0
				? (float) $metrics['memory_used'] / (float) $metrics['memory_total']
				: null,
			'vms' => $metrics['vm_count'] !== null ? (float) $metrics['vm_count'] : null,
			'uptime' => $metrics['uptime'] !== null ? (float) $metrics['uptime'] : null,
			'version' => ($metrics['version'] ?? '') !== '' ? (string) $metrics['version'] : null,
			default => $host['name']
		};
	}

	private static function reportedVmCount(array $hypervisor_hostids): int {
		$total = 0;
		$vm_counts = self::itemsByKeys($hypervisor_hostids, [
			'vmware.hv.vm.num[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'vm_count'
		]);
		foreach ($vm_counts as $metrics) {
			$total += (int) ($metrics['vm_count'] ?? 0);
		}
		return $total;
	}

	private static function sortDatastoreRows(array &$rows, string $sort, string $sortorder): void {
		$field = $sort === 'free' ? 'free_pct' : $sort;
		$numeric = in_array($field, ['total', 'free_pct', 'attachments'], true);

		uasort($rows, static function (array $left, array $right) use ($field, $numeric, $sortorder): int {
			$left_value = $left[$field] ?? null;
			$right_value = $right[$field] ?? null;

			if ($left_value === null || $right_value === null) {
				if ($left_value === $right_value) {
					$comparison = 0;
				}
				else {
					return $left_value === null ? 1 : -1;
				}
			}
			elseif ($numeric) {
				$comparison = (float) $left_value <=> (float) $right_value;
			}
			else {
				$comparison = strnatcasecmp((string) $left_value, (string) $right_value);
			}

			if ($comparison === 0) {
				$comparison = strnatcasecmp((string) $left['name'], (string) $right['name']);
			}

			return $sortorder === ZBX_SORT_DOWN ? -$comparison : $comparison;
		});
	}

	private static function paginateArray(array $rows, int $page, int $per_page): array {
		$total = count($rows);
		$pages = max(1, (int) ceil($total / $per_page));
		$page = max(1, min($page, $pages));
		return [
			'rows' => array_slice($rows, ($page - 1) * $per_page, $per_page, true),
			'page' => $page,
			'pages' => $pages,
			'total' => $total,
			'per_page' => $per_page
		];
	}

	public static function problemsByHosts(array $hostids): array {
		return self::problemCountsByHosts(self::problemEventsByHosts($hostids));
	}

	private static function problemEventsByHosts(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$triggers = API::Trigger()->get([
			'output' => [],
			'selectHosts' => ['hostid'],
			'hostids' => $hostids,
			'skipDependent' => true,
			'monitored' => true,
			'preservekeys' => true
		]) ?: [];
		if (!$triggers) {
			return [];
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'severity'],
			'objectids' => array_keys($triggers),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'suppressed' => false,
			'symptom' => false
		]) ?: [];

		$wanted = array_flip($hostids);
		$result = [];
		foreach ($problems as $problem) {
			foreach ($triggers[$problem['objectid']]['hosts'] as $host) {
				if (isset($wanted[$host['hostid']])) {
					$result[$host['hostid']][$problem['eventid']] = (int) $problem['severity'];
				}
			}
		}

		return $result;
	}

	private static function problemCountsByHosts(array $events_by_host): array {
		$result = [];
		foreach ($events_by_host as $hostid => $events) {
			foreach ($events as $severity) {
				$result[$hostid][$severity] = ($result[$hostid][$severity] ?? 0) + 1;
			}
			krsort($result[$hostid]);
		}
		return $result;
	}

	public static function sparklineHistory(array $itemids): array {
		if (!$itemids) {
			return [];
		}

		$items = [];
		foreach ($itemids as $itemid => $value_type) {
			$items[] = ['itemid' => $itemid, 'value_type' => $value_type, 'source' => 'history'];
		}

		$aggregation = Manager::History()->getGraphAggregationByWidth($items,
			time() - self::SPARKLINE_PERIOD, time(), self::SPARKLINE_POINTS - 1
		) ?: [];
		$result = [];
		foreach ($aggregation as $itemid => $item_data) {
			foreach ($item_data['data'] ?? [] as $point) {
				$result[$itemid][] = [(int) $point['clock'], $point['avg']];
			}
		}
		return $result;
	}
}
