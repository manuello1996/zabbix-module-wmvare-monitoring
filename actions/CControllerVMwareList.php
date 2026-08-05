<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CSettingsHelper;
use CUrl;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareCollector;

class CControllerVMwareList extends CController {
	public const PROFILE_GROUPIDS = 'web.vmware.monitoring.list.filter.groupids';
	public const PROFILE_HOSTIDS = 'web.vmware.monitoring.list.filter.hostids';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'filter_groupids' => 'array_db hstgrp.groupid',
			'filter_hostids' => 'array_db hosts.hostid',
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'page' => 'ge 1'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		if ($this->hasInput('filter_rst')) {
			CProfile::deleteIdx(self::PROFILE_GROUPIDS);
			CProfile::deleteIdx(self::PROFILE_HOSTIDS);
		}
		elseif ($this->hasInput('filter_set')) {
			CProfile::updateArray(self::PROFILE_GROUPIDS, $this->getInput('filter_groupids', []), PROFILE_TYPE_ID);
			CProfile::updateArray(self::PROFILE_HOSTIDS, $this->getInput('filter_hostids', []), PROFILE_TYPE_ID);
		}

		$groupids = CProfile::getArray(self::PROFILE_GROUPIDS, []);
		$filter_hostids = CProfile::getArray(self::PROFILE_HOSTIDS, []);
		$groups = $groupids
			? API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $groupids,
				'preservekeys' => true
			]) ?: []
			: [];

		$vcenter_hostids = VMwareCollector::findVCenterHostids($groups ? array_keys($groups) : null);
		if ($filter_hostids) {
			$vcenter_hostids = array_values(array_intersect($vcenter_hostids, $filter_hostids));
		}

		// API service access uses a shared wrapper. Resolve settings before API::Host()
		// so the nested settings call cannot switch the pending wrapper to CSettings.
		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$hosts = [];
		if ($vcenter_hostids) {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name', 'status'],
				'selectInventory' => ['notes'],
				'hostids' => $vcenter_hostids,
				'preservekeys' => true,
				'sortfield' => 'name',
				'limit' => $search_limit
			]);

			if ($hosts === false) {
				$hosts = API::Host()->get([
					'output' => ['hostid', 'name', 'status'],
					'hostids' => $vcenter_hostids,
					'preservekeys' => true,
					'sortfield' => 'name'
				]);
			}

			$hosts = $hosts ?: [];
		}

		foreach ($hosts as &$host) {
			$host += ['inventory' => []];
		}
		unset($host);

		$vcenters = VMwareCollector::collectVCenterMetrics($hosts);

		$totals = [
			'vcenters' => count($vcenters), 'hypervisors' => 0, 'vms' => 0,
			'datastores' => 0, 'datastore_attachments' => 0
		];
		foreach ($vcenters as $vcenter) {
			$totals['hypervisors'] += $vcenter['hypervisors'];
			$totals['vms'] += $vcenter['vms'];
			$totals['datastores'] += $vcenter['datastores'];
			$totals['datastore_attachments'] += $vcenter['datastore_attachments'];
		}

		$vcenters = array_values($vcenters);
		$paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $vcenters, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')
		);

		$selected_hosts = $filter_hostids
			? API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $filter_hostids,
				'preservekeys' => true
			]) ?: []
			: [];

		$response = new CControllerResponseData([
			'filter' => [
				'groups' => array_values($groups),
				'hosts' => array_map(
					static fn(array $host): array => ['id' => $host['hostid'], 'name' => $host['name']],
					array_values($selected_hosts)
				)
			],
			'vcenters' => $vcenters,
			'totals' => $totals,
			'paging' => $paging,
			'refresh_interval' => timeUnitToSeconds(CWebUser::getRefresh())
		]);
		$response->setTitle(_('VMware vCenters'));
		$this->setResponse($response);
	}
}
