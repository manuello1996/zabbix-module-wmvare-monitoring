<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

/** VMware's opt-in refresh control; each module supplies its own refresh request. */
class AutoRefresh {
	public static function interval(int $seconds): int {
		return max(10, $seconds ?: 60);
	}

	public static function control(string $id, int $interval = 60): \CDiv {
		$checkbox = (new \CCheckBox($id))->setId($id)->setLabel(_('Auto-refresh'))->setChecked(false)
			->setAttribute('data-refresh-interval', self::interval($interval))
			->setAttribute('data-updated-at', _('Updated at %1$s'))
			->setAttribute('data-auto-refresh-enabled', _('Auto-refresh enabled'))
			->setAttribute('data-refresh-error', _('Unable to refresh data. Showing the last loaded values.'));
		return (new \CDiv($checkbox))->addClass('vmware-monitoring-auto-refresh');
	}

	public static function status(string $id): \CDiv {
		return (new \CDiv())->setId($id)->addClass('vmware-monitoring-refresh-status')
			->setAttribute('role', 'status')->setAttribute('aria-live', 'polite');
	}
}
