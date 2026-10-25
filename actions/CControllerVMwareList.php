<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CProfile;
use CRoleHelper;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareVCenterSort;

class CControllerVMwareList extends CController {
	public const PROFILE_GROUPIDS = 'web.vmware.monitoring.list.filter.groupids';
	public const PROFILE_HOSTIDS = 'web.vmware.monitoring.list.filter.hostids';
	private const TABS = ['vcenters', 'issues', 'problems'];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'filter_groupids' => 'array_db hstgrp.groupid',
			'filter_hostids' => 'array_db hosts.hostid',
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'force_vcenter_refresh' => 'in 1',
			'tab' => 'in '.implode(',', self::TABS),
			'search' => 'string',
			'sort' => 'in '.implode(',', array_merge(VMwareVCenterSort::FIELDS, ['issue', 'status', 'severity', 'lastupdate'])),
			'sortorder' => 'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
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
		$tab = (string) $this->getInput('tab', 'vcenters');
		$sort = (string) $this->getInput('sort', 'vcenter');
		$sortorder = (string) $this->getInput('sortorder', ZBX_SORT_UP);
		$groups = $groupids
			? API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $groupids,
				'preservekeys' => true
			]) ?: []
			: [];

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
			'filter_groupids' => $groupids,
			'filter_hostids' => $filter_hostids,
			'force_refresh' => $this->hasInput('force_vcenter_refresh'),
			'tab' => $tab,
			'sort' => $sort,
			'sortorder' => $sortorder,
			// The user's general Zabbix refresh preference must not enable polling here.
			'refresh_interval' => max(10, timeUnitToSeconds(CWebUser::getRefresh()) ?: 60)
		]);
		$response->setTitle(_('VMware vCenters'));
		$this->setResponse($response);
	}
}
