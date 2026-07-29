<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {
	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->insertAfter(_('Latest data'),
				(new CMenuItem(_('VMware')))->setAction('vmware.monitoring.list')
			);
	}
}
