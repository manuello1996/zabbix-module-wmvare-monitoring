<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use API;
use CSettingsHelper;
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
			$result[$hostid]['vms'] = count($topology['vms']);
			$result[$hostid]['datastores'] = count(self::uniqueDatastores($attachments));
			$result[$hostid]['datastore_attachments'] = count($attachments);
		}

		return $result;
	}

	public static function summary(string $vcenter_hostid): array {
		$topology = self::discoveredTopology($vcenter_hostid);
		$vcenter = self::itemsByKeys([$vcenter_hostid], self::VCENTER_KEYS)[$vcenter_hostid]
			?? array_fill_keys(array_values(self::VCENTER_KEYS), null);
		$attachments = self::datastores(array_keys($topology['hypervisors']), false);

		return [
			'vcenter_metrics' => $vcenter,
			'hypervisors_count' => count($topology['hypervisors']),
			'vms_count' => count($topology['vms']),
			'datastores_count' => count(self::uniqueDatastores($attachments)),
			'datastore_attachments_count' => count($attachments)
		];
	}

	public static function discoveredTopology(string $vcenter_hostid): array {
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
			$rule_types[(string) $rule['itemid']] = str_starts_with($rule['key_'], 'vmware.hv.')
				? 'hypervisors'
				: 'vms';
		}

		$result = ['hypervisors' => [], 'vms' => []];
		if (!$rule_types) {
			return $result;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'],
			'selectDiscoveryRule' => ['itemid'],
			'selectInterfaces' => ['interfaceid', 'type', 'available', 'useip', 'ip', 'dns', 'port', 'error',
				'details'
			],
			'selectInventory' => ['notes'],
			'preservekeys' => true
		]) ?: [];

		foreach ($hosts as $hostid => $host) {
			$ruleid = (string) ($host['discoveryRule']['itemid'] ?? '');
			if (!isset($rule_types[$ruleid])) {
				continue;
			}

			$host += ['interfaces' => [], 'inventory' => []];
			foreach ($host['interfaces'] as &$interface) {
				$interface['interface'] = getHostInterface($interface);
				$interface['description'] = '';
				$interface['has_enabled_items'] = true;
			}
			unset($interface);

			$result[$rule_types[$ruleid]][$hostid] = $host;
		}

		return $result;
	}

	public static function hypervisors(string $vcenter_hostid): array {
		$topology = self::discoveredTopology($vcenter_hostid);
		$metrics = self::itemsByKeys(array_keys($topology['hypervisors']), self::HYPERVISOR_KEYS);
		$problems = self::problemsByHosts(array_keys($topology['hypervisors']));

		foreach ($topology['hypervisors'] as $hostid => &$host) {
			$host['metrics'] = $metrics[$hostid]
				?? array_fill_keys(array_values(self::HYPERVISOR_KEYS), null);
			$host['problems'] = $problems[$hostid] ?? [];
		}
		unset($host);

		uasort($topology['hypervisors'],
			static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name'])
		);
		return array_values($topology['hypervisors']);
	}

	public static function virtualMachinesPage(string $vcenter_hostid, int $page, int $per_page,
			string $search = ''): array {
		$topology = self::discoveredTopology($vcenter_hostid);
		$vms = $topology['vms'];
		uasort($vms, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		if ($search !== '') {
			$needle = mb_strtolower($search);
			$vms = array_filter($vms,
				static fn(array $vm): bool => str_contains(mb_strtolower($vm['name']), $needle)
			);
		}

		$paged = self::paginateArray($vms, $page, $per_page);
		$metrics = self::itemsByKeys(array_keys($paged['rows']), self::VM_KEYS);
		$problems = self::problemsByHosts(array_keys($paged['rows']));

		foreach ($paged['rows'] as $hostid => &$host) {
			$host['metrics'] = $metrics[$hostid] ?? array_fill_keys(array_values(self::VM_KEYS), null);
			$host['problems'] = $problems[$hostid] ?? [];
		}
		unset($host);
		$paged['rows'] = array_values($paged['rows']);
		return $paged;
	}

	public static function datastorePages(string $vcenter_hostid, int $page, int $per_page,
			string $search = ''): array {
		$topology = self::discoveredTopology($vcenter_hostid);
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
			$attachments = array_filter($attachments, static function (array $row) use ($needle): bool {
				$haystack = $row['name'].' '.$row['type'].' '.$row['hypervisor'];
				return str_contains(mb_strtolower($haystack), $needle);
			});
		}

		$unique_page = self::paginateArray($unique, $page, $per_page);
		$unique_page['rows'] = array_values($unique_page['rows']);
		$attachment_page = self::paginateArray($attachments, $page, $per_page);
		$attachment_page['rows'] = array_values($attachment_page['rows']);

		return [
			'search' => $search,
			'unique' => $unique_page,
			'attachments' => $attachment_page
		];
	}

	public static function clusters(string $vcenter_hostid): array {
		$topology = self::discoveredTopology($vcenter_hostid);
		$hv_metrics = self::itemsByKeys(array_keys($topology['hypervisors']), [
			'vmware.hv.cluster.name[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'cluster',
			'vmware.hv.hw.memory[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_total',
			'vmware.hv.memory.used[{$VMWARE.URL},{$VMWARE.HV.UUID}]' => 'memory_used'
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
					'name' => $name, 'status' => self::hasRecentValue($item) ? $item['lastvalue'] : null,
					'hypervisors' => 0, 'vms' => 0, 'memory_total' => 0.0, 'memory_used' => 0.0
				];
			}
		}

		foreach ($topology['hypervisors'] as $hostid => $host) {
			$m = $hv_metrics[$hostid] ?? [];
			$name = trim((string) ($m['cluster'] ?? ''));
			$name = $name !== '' ? $name : _('Standalone (no cluster)');
			$result[$name] ??= [
				'name' => $name, 'status' => null, 'hypervisors' => 0, 'vms' => 0,
				'memory_total' => 0.0, 'memory_used' => 0.0
			];
			$result[$name]['hypervisors']++;
			$result[$name]['memory_total'] += (float) ($m['memory_total'] ?? 0);
			$result[$name]['memory_used'] += (float) ($m['memory_used'] ?? 0);
		}
		foreach ($topology['vms'] as $hostid => $host) {
			$name = trim((string) ($vm_metrics[$hostid]['cluster'] ?? ''));
			$name = $name !== '' ? $name : _('Standalone (no cluster)');
			$result[$name] ??= [
				'name' => $name, 'status' => null, 'hypervisors' => 0, 'vms' => 0,
				'memory_total' => 0.0, 'memory_used' => 0.0
			];
			$result[$name]['vms']++;
		}

		uksort($result, 'strnatcasecmp');
		return array_values($result);
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
			'search' => ['key_' => 'vmware.hv.datastore.'],
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
			$identity = $attachment['uuid'] !== ''
				? $attachment['uuid']
				: mb_strtolower($attachment['name'].'|'.$attachment['type']);
			if (!isset($result[$identity])) {
				$result[$identity] = $attachment + [
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
				$result[$identity]['hypervisors'][$hypervisor] = $hypervisor;
			}
			$result[$identity]['attachments']++;
		}

		foreach ($result as &$datastore) {
			natcasesort($datastore['hypervisors']);
			$datastore['hypervisors'] = array_values($datastore['hypervisors']);
		}
		unset($datastore);
		uasort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		return array_values($result);
	}

	private static function datastoreNameFromItem(string $name): string {
		return preg_match('/\[([^\]]+)\]/', $name, $matches) === 1 ? $matches[1] : '';
	}

	private static function datastoreUuidFromKey(string $key): string {
		return preg_match('/^vmware\.hv\.datastore\.[^\[]+\[[^,]+,[^,]+,([^,\]]+)/', $key, $matches) === 1
			? trim($matches[1], " \t\n\r\0\x0B\"")
			: '';
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
