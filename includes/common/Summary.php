<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

/** Shared presentation; labels, values and source-item links belong to the caller. */
class Summary {
	public static function card(string $label, $value, array $options = []): \CDiv {
		$figure = (new \CDiv($value))->addClass('vmware-monitoring-card-value');
		if (isset($options['value_id'])) { $figure->setId($options['value_id']); }
		$content = [$figure];
		if (($options['unit'] ?? '') !== '') {
			$content[] = (new \CSpan($options['unit']))->addClass('vmware-monitoring-card-unit');
		}
		if (isset($options['aside'])) {
			$content[] = (new \CDiv($options['aside']))->addClass('vmware-monitoring-statseg-aside');
		}
		$card = (new \CDiv([
			(new \CDiv($label))->addClass('vmware-monitoring-statseg-label'),
			(new \CDiv($content))->addClass('vmware-monitoring-statseg-figure'),
			isset($options['footer']) ? (new \CDiv($options['footer']))->addClass('vmware-monitoring-statseg-footer') : null
		]))->addClass('vmware-monitoring-statseg')->addClass('vmware-monitoring-card-'.($options['modifier'] ?? 'total'));
		foreach ($options['attributes'] ?? [] as $name => $value) { $card->setAttribute($name, $value); }
		return $card;
	}

	public static function strip(array $cards, array $attributes = []): \CDiv {
		$strip = (new \CDiv($cards))->addClass('vmware-monitoring-statstrip');
		foreach ($attributes as $name => $value) { $strip->setAttribute($name, $value); }
		return $strip;
	}

	/** Independent cards for detail-page health metrics, without a wrapping strip. */
	public static function grid(array $cards, array $attributes = []): \CDiv {
		$grid = (new \CDiv($cards))->addClass('vmware-monitoring-summary-grid');
		foreach ($attributes as $name => $value) { $grid->setAttribute($name, $value); }
		return $grid;
	}
}
