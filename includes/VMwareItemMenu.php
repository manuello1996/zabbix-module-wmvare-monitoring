<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;
use Modules\VMwareMonitoring\Includes\Common\ItemActions;

require_once __DIR__.'/common/bootstrap.php';

/** Native, permission-aware item actions for values backed by a single item. */
class VMwareItemMenu {
	public static function value($label, array $metrics, string $field) {
		return self::link($label, $metrics[$field.'_itemid'] ?? null);
	}

	public static function link($label, ?string $itemid) {
		if ($itemid === null || !ctype_digit($itemid) || (int) $itemid <= 0) { return $label; }
		$url = (new \CUrl('zabbix.php'))->formatGetArguments();
		parse_str(parse_url($url->getUrl(), PHP_URL_QUERY) ?? '', $args);
		if (($args['action'] ?? '') === 'vmware.monitoring.list.data') {
			$url = (new \CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')
				->setArgument('filter_set', 1);
			foreach (['filter_groupids', 'filter_hostids', 'tab', 'page', 'sort', 'sortorder'] as $key) {
				if (isset($args[$key])) { $url->setArgument($key, $args[$key]); }
			}
		}
		if (in_array($args['action'] ?? '', ['vmware.monitoring.tab', 'vmware.monitoring.datastore.attachments'], true)) {
			$url = (new \CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.view')
				->setArgument('filter_hostid', [(string) ($args['hostid'] ?? '')])->setArgument('filter_set', 1);
			foreach (['tab', 'page', 'search', 'sort', 'sortorder'] as $key) {
				if (isset($args[$key])) { $url->setArgument($key, $args[$key]); }
			}
			if (($args['action'] ?? '') === 'vmware.monitoring.datastore.attachments') {
				$url->setArgument('tab', 'datastores');
			}
		}
		return ItemActions::link($label, $itemid, $url->getUrl(), ['class' => 'vmware-monitoring-item-value']);
	}
}
