<?php declare(strict_types = 0);

// Generated from zabbix-module-common. Rows must already be permission filtered.
namespace Modules\VMwareMonitoring\Includes\Common;

class LocationHierarchy {
	public static function cleanPath(array $path): array {
		return array_values(array_filter(array_map(static function ($part): string {
			return is_scalar($part) ? trim((string) $part) : '';
		}, $path), static fn(string $part): bool => $part !== ''));
	}

	public static function pathFromMacros(array $macros, string $name): array {
		foreach ($macros as $macro) {
			if (($macro['macro'] ?? '') === $name) {
				// Secret and vault macros must never appear in rendered location labels.
				return (int) ($macro['type'] ?? 0) === 0
					? self::cleanPath(explode(',', (string) ($macro['value'] ?? ''))) : [];
			}
		}
		return [];
	}

	public static function key(array $path): string {
		return hash('sha256', json_encode(array_values($path)));
	}

	public static function ancestors(array $path): array {
		$ancestors = [];
		$prefix = [];
		foreach (self::cleanPath($path) as $part) {
			$prefix[] = $part;
			$ancestors[] = $prefix;
		}
		return $ancestors;
	}

	public static function ancestorKeys(array $path): array {
		return array_map([self::class, 'key'], self::ancestors($path));
	}

	public static function branches(array $path): array {
		$branches = [];
		$ancestors = [];
		foreach (self::ancestors($path) as $prefix) {
			$key = self::key($prefix);
			$branches[$key] = ['key' => $key, 'path' => $prefix, 'label' => end($prefix),
				'depth' => count($prefix) - 1, 'parent_key' => $ancestors ? end($ancestors) : null,
				'ancestors' => $ancestors];
			$ancestors[] = $key;
		}
		return $branches;
	}

	/** Location order is independent of the requested within-group row direction. */
	public static function comparePaths(array $left, array $right): int {
		if ((bool) $left !== (bool) $right) { return $left ? -1 : 1; }
		for ($i = 0, $length = min(count($left), count($right)); $i < $length; $i++) {
			$comparison = strnatcasecmp($left[$i], $right[$i]) ?: strcmp($left[$i], $right[$i]);
			if ($comparison !== 0) { return $comparison; }
		}
		return count($left) <=> count($right);
	}

	public static function sort(array $hosts, string $order = 'ASC'): array {
		$direction = strtoupper($order) === 'DESC' ? -1 : 1;
		uasort($hosts, static function (array $left, array $right) use ($direction): int {
			$location = self::comparePaths(self::cleanPath($left['location_path'] ?? []),
				self::cleanPath($right['location_path'] ?? []));
			if ($location !== 0) { return $location; }
			$a = (string) ($left['name'] ?? $left['host'] ?? '');
			$b = (string) ($right['name'] ?? $right['host'] ?? '');
			return $direction * (strnatcasecmp($a, $b) ?: strcmp($a, $b)
				?: strcmp((string) ($left['hostid'] ?? ''), (string) ($right['hostid'] ?? '')));
		});
		return $hosts;
	}

	/** Fold all rows into every ancestor before pagination, with module-specific totals. */
	public static function aggregate(array $rows, callable $seed, callable $add, callable $finish): array {
		$groups = [];
		foreach ($rows as $id => $row) {
			foreach (self::branches($row['location_path'] ?? []) as $key => $branch) {
				if (!isset($groups[$key])) { $groups[$key] = $seed($branch); }
				$add($groups[$key], $row, $id);
			}
		}
		foreach ($groups as &$group) { $finish($group); }
		unset($group);
		return $groups;
	}

	public static function groups(array $hosts): array {
		return self::aggregate(self::sort($hosts),
			static fn(array $branch): array => $branch + ['hostids' => [], 'count' => 0],
			static function (array &$group, array $host, $id): void {
				$group['hostids'][(string) ($host['hostid'] ?? $id)] = true;
			},
			static function (array &$group): void {
				$group['hostids'] = array_map('strval', array_keys($group['hostids']));
				$group['count'] = count($group['hostids']);
			});
	}

	/** Re-emit ancestors at page start; adjacent sibling rows share their headers. */
	public static function headers(array $rows): array {
		$headers = [];
		$previous = [];
		foreach ($rows as $id => $row) {
			$keys = self::ancestorKeys($row['location_path'] ?? []);
			$common = 0;
			while (isset($keys[$common], $previous[$common]) && $keys[$common] === $previous[$common]) {
				$common++;
			}
			$headers[(string) ($row['hostid'] ?? $id)] = array_slice($keys, $common);
			$previous = $keys;
		}
		return $headers;
	}
}
