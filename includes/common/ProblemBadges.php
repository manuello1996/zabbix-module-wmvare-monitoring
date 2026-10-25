<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

class ProblemBadges {
	public static function metadata(array $severities): array {
		krsort($severities, SORT_NUMERIC);
		$result = [];
		foreach ($severities as $severity => $count) {
			if ((int) $count <= 0) { continue; }
			$result[] = ['count' => (int) $count, 'style' => \CSeverityHelper::getStatusStyle((int) $severity),
				'title' => \CSeverityHelper::getName((int) $severity)];
		}
		return $result;
	}

	public static function counts(array $severities, array $attributes = []): \CDiv {
		$badges = [];
		foreach (self::metadata($severities) as $badge) {
			$badges[] = (new \CSpan((string) $badge['count']))->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
				->addClass($badge['style'])->setTitle($badge['title']);
		}
		$list = (new \CDiv($badges))->addClass(ZBX_STYLE_PROBLEM_ICON_LIST);
		foreach ($attributes as $name => $value) { $list->setAttribute($name, $value); }
		return $list;
	}

	public static function link(array $severities, array $hostids, array $options = []): \CTag {
		$url = (new \CUrl('zabbix.php'))->setArgument('action', 'problem.view')
			->setArgument('hostids', $hostids ?: ['0'])->setArgument('filter_set', 1);
		if (!($options['available'] ?? true)) { return new \CLink(_('Unavailable'), $url); }
		if (!self::metadata($severities)) {
			return (($options['empty_link'] ?? false) ? new \CLink(_('None'), $url) : new \CSpan(_('None')))
				->addClass(ZBX_STYLE_GREEN);
		}
		return (new \CLink(self::counts($severities), $url))->addClass(ZBX_STYLE_PROBLEM_ICON_LINK);
	}
}
