<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CProfile;
use CRoleHelper;
use CSettingsHelper;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareCollector;

class CControllerVMwareView extends CController {
	public const PROFILE_GROUPIDS = 'web.vmware.monitoring.filter.groupids';
	public const PROFILE_HOSTID = 'web.vmware.monitoring.filter.hostid';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
				'filter_groupids' => 'array_db hstgrp.groupid',
				'filter_hostid' => 'array_db hosts.hostid',
				'filter_set' => 'in 1',
				'filter_rst' => 'in 1'
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
			CProfile::delete(self::PROFILE_HOSTID);
		}
		elseif ($this->hasInput('filter_set')) {
			CProfile::updateArray(self::PROFILE_GROUPIDS, $this->getInput('filter_groupids', []), PROFILE_TYPE_ID);
			$hostids = $this->getInput('filter_hostid', []);
			CProfile::update(self::PROFILE_HOSTID, $hostids ? (string) reset($hostids) : '', PROFILE_TYPE_STR);
		}

		$groupids = CProfile::getArray(self::PROFILE_GROUPIDS, []);
		$hostid = (string) CProfile::get(self::PROFILE_HOSTID, '');
		$groups = $groupids
			? API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $groupids,
				'preservekeys' => true
			]) ?: []
			: [];

		$vcenter_hostids = VMwareCollector::findVCenterHostids($groups ? array_keys($groups) : null);
		// Resolve settings before obtaining the shared API::Host() wrapper.
		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$hosts = [];
		if ($vcenter_hostids) {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $vcenter_hostids,
				'preservekeys' => true,
				'sortfield' => 'name',
				'limit' => $search_limit
			]) ?: [];
		}

		if ($hostid !== '' && !isset($hosts[$hostid])) {
			$hostid = '';
		}

		$host = null;
		if ($hostid !== '') {
			$db_hosts = API::Host()->get([
				'output' => ['hostid', 'name', 'status'],
				'selectInterfaces' => ['interfaceid', 'type', 'available', 'useip', 'ip', 'dns', 'port', 'error',
					'details'
				],
				'selectInventory' => ['notes'],
				'hostids' => [$hostid]
			]);

			if ($db_hosts === false) {
				$db_hosts = API::Host()->get([
					'output' => ['hostid', 'name', 'status'],
					'hostids' => [$hostid]
				]);
			}

			$db_hosts = $db_hosts ?: [];
			$host = $db_hosts ? reset($db_hosts) : null;
			if ($host === null) {
				$hostid = '';
			}
			else {
				$host += ['interfaces' => [], 'inventory' => []];
				foreach ($host['interfaces'] as &$interface) {
					$interface['interface'] = getHostInterface($interface);
					$interface['description'] = '';
					$interface['has_enabled_items'] = true;
				}
				unset($interface);
			}
		}

		$data = [
			'filter' => [
				'groups' => array_values($groups),
				'hostid' => $hostid
			],
			'hosts' => array_values($hosts),
			'host' => $host,
			'vcenter_metrics' => array_fill_keys(array_values(VMwareCollector::VCENTER_KEYS), null),
			'hypervisors_count' => 0,
			'vms_count' => 0,
			'datastores_count' => 0,
			'datastore_attachments_count' => 0
		];

		if ($hostid !== '') {
			$data = array_replace($data, VMwareCollector::summary($hostid, false));
		}

		$response = new CControllerResponseData($data);
		$response->setTitle(_('VMware'));
		$this->setResponse($response);
	}
}
