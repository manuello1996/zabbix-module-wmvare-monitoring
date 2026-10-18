<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {
	public function onBeforeAction(\CController $action): void {
		// Retire the old payload cache without changing the login session.
		\CSessionHelper::unset(['vmware_monitoring_data_cache']);
	}

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->insertAfter(_('Latest data'),
				(new CMenuItem(_('VMware')))
					->setId('main-menu-vmware')
					->setAction('vmware.monitoring.list')
					->setAliases([
						'vmware.monitoring.view', 'vmware.monitoring.cluster', 'vmware.monitoring.hypervisor'
					])
			);
	}
}
