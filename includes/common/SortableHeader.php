<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

class SortableHeader {
	public static function render(string $label, string $field, string $sort = '', string $order = 'ASC',
			array $options = []): \CTag {
		$active = $sort === $field;
		$arrow = (new \CSpan())->addClass('vmware-monitoring-sort-arrow')->setAttribute('aria-hidden', 'true');
		if ($active) { $arrow->addClass($order === 'DESC' ? ZBX_STYLE_ARROW_DOWN : ZBX_STYLE_ARROW_UP); }
		$content = [$label, $arrow];
		$header = isset($options['url']) ? new \CLink($content, $options['url']) : new \CSpan($content);
		$header->addClass('vmware-monitoring-sort');
		if (isset($options['attribute'])) { $header->setAttribute($options['attribute'], $field); }
		if (!isset($options['url'])) { $header->setAttribute('role', 'button')->setAttribute('tabindex', '0'); }
		return $header;
	}

	/** Retain Zabbix's native column and URL behavior for server-side tables. */
	public static function native($label, $field, $sort, $order, $url = null) {
		return \make_sorting_header($label, $field, $sort, $order, $url);
	}
}
