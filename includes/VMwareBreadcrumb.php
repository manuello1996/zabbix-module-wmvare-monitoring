<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use CTag;

class VMwareBreadcrumb {
	public static function make(array $items): CTag {
		return (new CTag('nav', true, $items))
			->addClass('vmware-monitoring-breadcrumb')
			->setAttribute('aria-label', _('Breadcrumb'));
	}
}
