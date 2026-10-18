<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

/** Interpret one native FetchDVPorts snapshot without inventing missing state or rates. */
class VMwareDvswitch {
	public const VM_INVENTORY_KEY = 'vmware.vm.discovery["{$VMWARE.URL}"]';
	public const HOST_INVENTORY_KEY = 'vmware.hv.discovery["{$VMWARE.URL}"]';

	public static function vmIds(array $snapshot): array {
		return self::resourceIds($snapshot, 'VirtualMachine');
	}

	public static function hostIds(array $snapshot): array {
		return self::resourceIds($snapshot, 'HostSystem');
	}

	private static function resourceIds(array $snapshot, string $type): array {
		$ids = [];
		foreach ($snapshot['resources'] as $resource) {
			if ($resource['type'] === $type) { $ids[$resource['id']] = $resource['id']; }
		}
		return array_values($ids);
	}

	/** Only return requested IDs; each lookup is scoped to its originating vCenter. */
	public static function vmNames(string $json, array $ids): array {
		return self::inventoryNames($json, $ids);
	}

	public static function inventoryNames(string $json, array $ids): array {
		$rows = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
		if (!is_array($rows)) {
			throw new \UnexpectedValueException('Invalid name inventory.');
		}
		$wanted = array_fill_keys($ids, true); $names = []; $ambiguous = [];
		foreach ($rows as $row) {
			$row = is_object($row) ? (array) $row : $row;
			if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['name'] ?? null)) {
				throw new \UnexpectedValueException('Invalid name entry.');
			}
			$id = $row['id'];
			if (!isset($wanted[$id]) || $row['name'] === '') { continue; }
			if (isset($names[$id]) && $names[$id] !== $row['name']) { $ambiguous[$id] = true; }
			$names[$id] = $row['name'];
		}
		return array_diff_key($names, $ambiguous);
	}

	public static function withVmNames(array $snapshot, array $names): array {
		foreach ($snapshot['ports'] as &$port) {
			if (($port['entity']['type'] ?? '') === 'VirtualMachine' && isset($names[$port['entity']['id']])) {
				$port['entity']['name'] = $names[$port['entity']['id']];
			}
		}
		unset($port);
		foreach ($snapshot['resources'] as &$resource) {
			if ($resource['type'] === 'VirtualMachine' && isset($names[$resource['id']])) {
				$resource['name'] = $names[$resource['id']];
			}
		}
		unset($resource);
		return $snapshot;
	}

	public static function withHostNames(array $snapshot, array $names): array {
		foreach ($snapshot['ports'] as &$port) {
			foreach (['entity', 'proxy'] as $field) {
				if (($port[$field]['type'] ?? '') === 'HostSystem' && isset($names[$port[$field]['id']])) {
					$port[$field]['name'] = $names[$port[$field]['id']];
				}
			}
		}
		unset($port);
		foreach ($snapshot['resources'] as &$resource) {
			if ($resource['type'] === 'HostSystem' && isset($names[$resource['id']])) {
				$resource['name'] = $names[$resource['id']];
			}
		}
		unset($resource);
		return $snapshot;
	}

	public static function flag($value): ?bool {
		if (in_array($value, [true, 'true', 1, '1'], true)) { return true; }
		if (in_array($value, [false, 'false', 0, '0'], true)) { return false; }
		return null;
	}

	public static function text($value): string {
		if ($value === null) { return '-'; }
		if (is_bool($value)) { return $value ? 'true' : 'false'; }
		if (is_scalar($value)) { return (string) $value; }
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	public static function reference($value): ?array {
		if (!is_array($value) || !isset($value['#text']) || !is_scalar($value['#text'])
				|| (string) $value['#text'] === '') { return null; }
		$type = isset($value['@type']) && is_scalar($value['@type']) ? (string) $value['@type'] : 'Unknown';
		return ['id' => (string) $value['#text'], 'type' => $type, 'key' => $type.':'.$value['#text']];
	}

	public static function fields(array $value, string $prefix = ''): array {
		$result = [];
		foreach ($value as $key => $field) {
			$path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
			if (is_array($field) && $field) { $result += self::fields($field, $path); }
			else { $result[$path] = self::text($field); }
		}
		return $result;
	}

	/** Decimal addition keeps unsigned 64-bit counters exact, including their sum. */
	private static function add(string $a, string $b): string {
		$result = ''; $carry = 0;
		for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
			$digit = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
			$result = ($digit % 10).$result;
			$carry = intdiv($digit, 10);
		}
		return ltrim($result, '0') ?: '0';
	}

	private static function vlans($value): array {
		if (!is_array($value)) { return []; }
		$ranges = array_key_exists('start', $value) ? [$value] : $value;
		$result = [];
		foreach ($ranges as $range) {
			if (!is_array($range) || !isset($range['start']) || !is_scalar($range['start'])) { continue; }
			$start = (string) $range['start'];
			$end = isset($range['end']) && is_scalar($range['end']) ? (string) $range['end'] : $start;
			$result[] = $start === $end ? $start : $start.'–'.$end;
		}
		return array_values(array_unique($result));
	}

	public static function parse(string $json): array {
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
		if (!is_array($data) || !array_key_exists('FetchDVPortsResponse', $data)) {
			throw new \UnexpectedValueException('Missing FetchDVPortsResponse.');
		}
		$response = $data['FetchDVPortsResponse'];
		if ($response !== null && !is_array($response)) { throw new \UnexpectedValueException('Invalid port response.'); }
		$raw_ports = $response['returnval'] ?? [];
		if (!is_array($raw_ports)) { throw new \UnexpectedValueException('Invalid port list.'); }
		if (array_key_exists('key', $raw_ports)) { $raw_ports = [$raw_ports]; }
		$ports = []; $groups = []; $resources = []; $statistics = [];
		$counts = array_fill_keys(['ports', 'connected', 'link_up', 'connected_down', 'blocked', 'conflicts', 'unknown'], 0);
		foreach ($raw_ports as $raw) {
			if (!is_array($raw) || !isset($raw['key']) || !is_scalar($raw['key'])) {
				throw new \UnexpectedValueException('Invalid distributed port.');
			}
			$runtime = is_array($raw['state']['runtimeInfo'] ?? null) ? $raw['state']['runtimeInfo'] : [];
			$entity = self::reference($raw['connectee']['connectedEntity'] ?? null);
			$proxy = self::reference($raw['proxyHost'] ?? null);
			$group = isset($raw['portgroupKey']) && is_scalar($raw['portgroupKey']) ? (string) $raw['portgroupKey'] : '';
			$port = [
				'key' => (string) $raw['key'], 'group' => $group, 'entity' => $entity, 'proxy' => $proxy,
				'nic' => self::text($raw['connectee']['nicKey'] ?? null),
				'type' => self::text($raw['connectee']['type'] ?? null),
				'connected' => $entity !== null, 'up' => self::flag($runtime['linkUp'] ?? null),
				'blocked' => self::flag($runtime['blocked'] ?? null), 'conflict' => self::flag($raw['conflict'] ?? null),
				'vlans' => self::vlans($runtime['vlanIds'] ?? null),
				'trunk' => self::flag($runtime['trunkingMode'] ?? null),
				'mac' => self::text($runtime['macAddress'] ?? null), 'peer' => self::text($runtime['linkPeer'] ?? null),
				'changed' => self::text($raw['lastStatusChange'] ?? null),
				'local' => self::flag($raw['hostLocalPort'] ?? null),
				'directpath' => self::flag($runtime['vmDirectPathGen2Active'] ?? null),
				'status' => self::text($runtime['statusDetail'] ?? null),
				'fields' => self::fields($raw), 'statistics' => []
			];
			foreach (is_array($raw['state']['stats'] ?? null) ? $raw['state']['stats'] : [] as $metric => $value) {
				// Other/optional fields remain visible in All reported fields.
				if (!is_scalar($value) || !preg_match('/^\d+$/D', (string) $value)) { continue; }
				$value = (string) $value;
				$port['statistics'][$metric] = $value;
				$statistics[$metric] ??= ['name' => $metric, 'value' => '0', 'reported' => 0];
				$statistics[$metric]['value'] = self::add($statistics[$metric]['value'], $value);
				$statistics[$metric]['reported']++;
			}
			$counts['ports']++;
			$counts['connected'] += (int) $port['connected'];
			$counts['link_up'] += (int) ($port['up'] === true);
			$counts['connected_down'] += (int) ($port['connected'] && $port['up'] === false);
			$counts['blocked'] += (int) ($port['blocked'] === true);
			$counts['conflicts'] += (int) ($port['conflict'] === true);
			$counts['unknown'] += (int) ($port['up'] === null);
			$groups[$group] ??= ['name' => $group, 'ports' => 0, 'connected' => 0, 'up' => 0,
				'down' => 0, 'unknown' => 0, 'blocked' => 0, 'conflicts' => 0, 'vlans' => [], 'resources' => []];
			$g = &$groups[$group];
			$g['ports']++; $g['connected'] += (int) $port['connected'];
			$g['up'] += (int) ($port['up'] === true); $g['down'] += (int) ($port['connected'] && $port['up'] === false);
			$g['unknown'] += (int) ($port['up'] === null); $g['blocked'] += (int) ($port['blocked'] === true);
			$g['conflicts'] += (int) ($port['conflict'] === true);
			foreach ($port['vlans'] as $vlan) { $g['vlans'][$vlan] = $vlan; }
			if ($entity !== null) { $g['resources'][$entity['key']] = true; }
			unset($g);
			foreach (['Attached entity' => $entity, 'Proxy host' => $proxy] as $role => $ref) {
				if ($ref === null) { continue; }
				$resources[$ref['key']] ??= $ref + ['roles' => [], 'ports' => [], 'groups' => [], 'nics' => []];
				$r = &$resources[$ref['key']];
				$r['roles'][$role] = $role;
				$r['ports'][$group.'/'.$port['key']] = true;
				$r['groups'][$group] = true;
				if ($role === 'Attached entity' && $port['nic'] !== '-') { $r['nics'][$port['nic']] = $port['nic']; }
				unset($r);
			}
			$ports[] = $port;
		}
		usort($ports, static fn(array $a, array $b): int => strnatcasecmp($a['group'].'/'.$a['key'], $b['group'].'/'.$b['key']));
		uksort($groups, 'strnatcasecmp'); uksort($resources, 'strnatcasecmp'); ksort($statistics, SORT_NATURAL);
		return ['ports' => $ports, 'portgroups' => array_values($groups), 'resources' => array_values($resources),
			'statistics' => array_values($statistics), 'counts' => $counts];
	}

	public static function filterPorts(array $ports, string $search, string $state, string $group, string $resource): array {
		return array_values(array_filter($ports, static function (array $port) use ($search, $state, $group, $resource): bool {
			if ($group !== '' && $port['group'] !== $group) { return false; }
			if ($resource !== '' && ($port['entity']['key'] ?? '') !== $resource && ($port['proxy']['key'] ?? '') !== $resource) {
				return false;
			}
			$matches = match ($state) {
				'connected' => $port['connected'], 'unconnected' => !$port['connected'],
				'up' => $port['up'] === true, 'down' => $port['connected'] && $port['up'] === false,
				'blocked' => $port['blocked'] === true, 'conflict' => $port['conflict'] === true,
				'unknown' => $port['up'] === null, default => true
			};
			$values = implode(' ', $port['fields']).' '.($port['entity']['name'] ?? '').' '.($port['proxy']['name'] ?? '');
			return $matches && ($search === '' || stripos($values, $search) !== false);
		}));
	}
}
