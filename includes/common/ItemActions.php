<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

class ItemActions {
	/** Install the parent-page handlers and common native-menu visibility policy. */
	public static function init(\CView $view): void {
		foreach (['multilineinput.js', 'items.js', 'class.tagfilteritem.js'] as $file) {
			if (!in_array($file, $view->getJsFiles(), true)) { $view->addJsFile($file); }
		}
		(new \CScriptTag('window.VMwareCommon.ItemActions.install('.json_encode(self::options(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');'))
			->setOnDocumentReady()->show();
	}

	/** Session-specific configuration must be rendered by PHP, never into a cached asset. */
	public static function options(): array {
		return ['csrfToken' => \CCsrfTokenHelper::get('item'),
			'errorTitle' => _('Unexpected server error.'), 'title' => _('Item actions')];
	}

	public static function link($label, ?string $itemid, string $backurl, array $attributes = []) {
		if ($itemid === null || !ctype_digit($itemid) || (int) $itemid <= 0) { return $label; }
		$link = (new \CLinkAction($label))->setMenuPopup(\CMenuPopupHelper::getItem([
			'itemid' => $itemid, 'context' => 'host', 'backurl' => $backurl
		]))->setTitle(_('Item actions'))->setAttribute('data-module-item-actions', '1');
		foreach ($attributes as $name => $value) { $link->setAttribute($name, $value); }
		return $link;
	}
}
