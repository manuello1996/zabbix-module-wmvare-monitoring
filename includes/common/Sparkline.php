<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

class Sparkline {
	public static function holder(array $attributes = [], string $kind = ''): \CDiv {
		$holder = (new \CDiv())->addClass('vmware-monitoring-sparkline')->setAttribute('aria-label', _('Loading history'));
		if ($kind !== '') { $holder->addClass('vmware-monitoring-spark-'.$kind); }
		foreach ($attributes as $name => $value) { $holder->setAttribute($name, $value); }
		return $holder;
	}
}
