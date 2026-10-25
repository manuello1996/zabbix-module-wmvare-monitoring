<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

/** NetApp-standard expandable rows and grouped detail tables. */
class RowDetails {
	public static function toggle(string $label, string $panel_id, bool $expanded = false, array $attributes = []): \CTag {
		$button = (new \CTag('button', true, $label))->setAttribute('type', 'button')
			->setId($panel_id.'-toggle')->addClass('vmware-monitoring-metrics-toggle')
			->setAttribute('data-module-details-toggle', $panel_id)
			->setAttribute('aria-expanded', $expanded ? 'true' : 'false')->setAttribute('aria-controls', $panel_id);
		foreach ($attributes as $name => $value) { $button->setAttribute($name, $value); }
		return $button;
	}

	public static function name($name, $toggle = null): \CDiv {
		return (new \CDiv([$name, $toggle]))->addClass('vmware-monitoring-resource-name');
	}

	public static function row(string $panel_id, int $columns, $content, bool $expanded = false, array $attributes = []): \CRow {
		$panel = (new \CDiv($content))->setAttribute('role', 'region')->setAttribute('aria-labelledby', $panel_id.'-toggle');
		$row = (new \CRow([(new \CCol($panel))->setColSpan($columns)]))->setId($panel_id)
			->addClass('vmware-monitoring-metrics-row');
		if (!$expanded) { $row->setAttribute('hidden', 'hidden'); }
		foreach ($attributes as $name => $value) { $row->setAttribute($name, $value); }
		return $row;
	}

	public static function group(string $title, $content, bool $records = false): \CDiv {
		$group = (new \CDiv([new \CTag('h5', true, $title), $content]))->addClass('vmware-monitoring-metric-group');
		if ($records) { $group->setAttribute('data-module-detail-records', '1'); }
		return $group;
	}

	public static function grid(array $groups): \CDiv {
		return (new \CDiv($groups))->addClass('vmware-monitoring-metric-grid')->setAttribute('data-module-detail-grid', '1');
	}

	public static function table($table): \CDiv {
		return (new \CDiv($table))->addClass('vmware-monitoring-table-wrap');
	}
}
