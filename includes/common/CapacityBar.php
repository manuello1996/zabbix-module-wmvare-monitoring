<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

class CapacityBar {
	/** Bound the visual width without changing the caller's actual measurement. */
	public static function render($percent, array $options = []): \CDiv {
		$known = is_numeric($percent) && is_finite((float) $percent);
		$value = $known ? max(0, min(100, (float) $percent)) : 0;
		$tone = $options['tone'] ?? 'normal';
		if ($known && $value >= ($options['threshold'] ?? 90)) { $tone = 'critical'; }
		if ($options['stale'] ?? false) { $tone = 'stale'; }
		if (!in_array($tone, ['normal', 'warning', 'critical', 'stale'], true)) { $tone = 'normal'; }
		$fill = (new \CDiv())->addClass('vmware-monitoring-progress-fill')
			->setAttribute('style', 'width: '.number_format($value, 2, '.', '').'%');
		if (isset($options['fill_id'])) { $fill->setId($options['fill_id']); }
		$bar = (new \CDiv($fill))->addClass('vmware-monitoring-progress')->setAttribute('data-tone', $tone)
			->setAttribute('role', 'progressbar')->setAttribute('aria-valuemin', '0')->setAttribute('aria-valuemax', '100')
			->setAttribute('aria-label', $options['label'] ?? _('Utilization'));
		if ($known) { $bar->setAttribute('aria-valuenow', (string) $value); }
		else { $bar->setAttribute('aria-valuetext', _('Unavailable')); }
		return $bar;
	}
}
