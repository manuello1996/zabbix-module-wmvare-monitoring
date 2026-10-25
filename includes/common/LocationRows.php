<?php declare(strict_types = 0);

// Generated from zabbix-module-common. Columns and statistics belong to the module.
namespace Modules\VMwareMonitoring\Includes\Common;

class LocationRows {
	public static function header(array $group, string $count, array $columns, int $label_span = 1,
			array $groups = []): \CRow {
		$button = (new \CButton(null, [
			(new \CSpan())->addClass('vmware-monitoring-location-chevron')->setAttribute('aria-hidden', 'true'),
			(new \CSpan($group['label']))->addClass('vmware-monitoring-location-name')
		]))->addClass('vmware-monitoring-location-toggle')
			->setAttribute('data-vmware-location-toggle', $group['key'])
			->setAttribute('aria-expanded', ($group['collapsed'] ?? false) ? 'false' : 'true');
		$label = (new \CDiv([$button, (new \CSpan($count))->addClass('vmware-monitoring-location-count')]))
			->addClass('vmware-monitoring-location-group')
			->setAttribute('style', '--vmware-monitoring-location-depth: '.(int) $group['depth']);
		$row = (new \CRow(array_merge([(new \CCol($label))->setColSpan($label_span)], $columns)))
			->addClass('vmware-monitoring-location-row')
			->setAttribute('data-vmware-location-key', $group['key']);
		return self::withAncestors($row, $group['ancestors'], $groups);
	}

	public static function withAncestors(\CRow $row, array $ancestors, array $groups = []): \CRow {
		$row->setAttribute('data-vmware-location-ancestors', json_encode($ancestors));
		foreach ($ancestors as $key) {
			if ($groups[$key]['collapsed'] ?? false) {
				$row->setAttribute('hidden', 'hidden');
				break;
			}
		}
		return $row;
	}
}
