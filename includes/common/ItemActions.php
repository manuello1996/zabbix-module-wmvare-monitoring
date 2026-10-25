<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

class ItemActions {
	public static function link($label, ?string $itemid, string $backurl, array $attributes = []) {
		if ($itemid === null || !ctype_digit($itemid) || (int) $itemid <= 0) { return $label; }
		$link = (new \CLinkAction($label))->setMenuPopup(\CMenuPopupHelper::getItem([
			'itemid' => $itemid, 'context' => 'host', 'backurl' => $backurl
		]))->setTitle(_('Item actions'));
		foreach ($attributes as $name => $value) { $link->setAttribute($name, $value); }
		return $link;
	}
}
