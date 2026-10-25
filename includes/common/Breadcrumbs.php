<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

class Breadcrumbs {
	/** Items can be native links/tags, text, or ['label' => ..., 'url' => ...]. */
	public static function make(array $items): \CTag {
		$content = [];
		$last = count($items) - 1;
		foreach (array_values($items) as $index => $item) {
			if ($index > 0) {
				$content[] = (new \CSpan('›'))->addClass('vmware-monitoring-breadcrumb-sep')->setAttribute('aria-hidden', 'true');
			}
			if (is_array($item)) {
				$item = isset($item['url']) ? new \CLink($item['label'], $item['url']) : new \CSpan($item['label']);
			}
			elseif (!$item instanceof \CTag) { $item = new \CSpan($item); }
			if ($index === $last) {
				$item->addClass('vmware-monitoring-breadcrumb-current')->setAttribute('aria-current', 'page');
			}
			$content[] = $item;
		}
		return (new \CTag('nav', true, $content))->addClass('vmware-monitoring-breadcrumb')
			->setAttribute('aria-label', _('Breadcrumb'));
	}
}
