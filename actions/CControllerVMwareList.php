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
	private const LOCATION_MACRO = '{$VMWARE.LOCATION}';
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
			'sort' => 'in vcenter,issue,status,severity,lastupdate',
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

		$force_refresh = $this->hasInput('force_vcenter_refresh');
		$vcenter_hostids = VMwareCollector::findVCenterHostids(
			$groups ? array_keys($groups) : null,
			$force_refresh
		);
		if ($force_refresh) {
			VMwareCollector::clearTopologyCaches($vcenter_hostids);
		}
		if ($filter_hostids) {
			$vcenter_hostids = array_values(array_intersect($vcenter_hostids, $filter_hostids));
		}

		// API service access uses a shared wrapper. Resolve settings before API::Host()
		// so the nested settings call cannot switch the pending wrapper to CSettings.
		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$hosts = [];
		if ($vcenter_hostids) {
			$host_options = [
				'output' => ['hostid', 'name', 'status'],
				'hostids' => $vcenter_hostids,
				'preservekeys' => true,
				'sortfield' => 'name',
				'limit' => $search_limit,
				'selectInventory' => ['notes'],
				'selectMacros' => ['macro', 'value', 'type']
			];
			$hosts = API::Host()->get($host_options);

			if ($hosts === false) {
				$hosts = API::Host()->get([
					'output' => ['hostid', 'name', 'status'],
					'selectMacros' => ['macro', 'value', 'type'],
					'hostids' => $vcenter_hostids,
					'preservekeys' => true,
					'sortfield' => 'name'
				]);
			}

			$hosts = $hosts ?: [];
		}

		foreach ($hosts as &$host) {
			$host += ['inventory' => []];
			$host['location_path'] = [];
			foreach ($host['macros'] ?? [] as $macro) {
				if (($macro['macro'] ?? '') !== self::LOCATION_MACRO
						|| (int) ($macro['type'] ?? ZBX_MACRO_TYPE_TEXT) !== ZBX_MACRO_TYPE_TEXT) {
					continue;
				}

				$host['location_path'] = array_values(array_filter(
					array_map('trim', explode(',', (string) ($macro['value'] ?? ''))),
					static fn(string $part): bool => $part !== ''
				));
				break;
			}
			unset($host['macros']);
		}
		unset($host);

		$vcenters = array_values(VMwareCollector::collectVCenterMetrics($hosts));
		$issues = [];
		$problem_widget = [];
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

		usort($vcenters, static function (array $left, array $right): int {
			$left_path = $left['location_path'] ?? [];
			$right_path = $right['location_path'] ?? [];

			if (!$left_path || !$right_path) {
				if (!$left_path && !$right_path) {
					return strnatcasecmp($left['name'], $right['name'])
						?: (string) $left['hostid'] <=> (string) $right['hostid'];
				}
				return !$left_path ? 1 : -1;
			}

			$parts = min(count($left_path), count($right_path));
			for ($index = 0; $index < $parts; $index++) {
				$comparison = strnatcasecmp($left_path[$index], $right_path[$index]);
				if ($comparison !== 0) {
					return $comparison;
				}
			}

			return count($left_path) <=> count($right_path)
				?: strnatcasecmp($left['name'], $right['name'])
				?: (string) $left['hostid'] <=> (string) $right['hostid'];
		});

		$paging = null;
		$issues_paging = null;

		if ($tab === 'issues') {
			$vcenter_names = array_column($vcenters, 'name', 'hostid');
			$issues = VMwareCollector::alarmsForVCenters($vcenter_names);

			usort($issues, static function (array $left, array $right) use ($sort, $sortorder): int {
				$left_value = match ($sort) {
					'issue' => mb_strtolower($left['name']),
					'status' => _('Active'),
					'severity' => $left['severity'] ?? -1,
					'lastupdate' => (int) $left['lastclock'],
					default => mb_strtolower($left['vcenter'])
				};
				$right_value = match ($sort) {
					'issue' => mb_strtolower($right['name']),
					'status' => _('Active'),
					'severity' => $right['severity'] ?? -1,
					'lastupdate' => (int) $right['lastclock'],
					default => mb_strtolower($right['vcenter'])
				};
				$result = $left_value <=> $right_value;
				if ($result === 0) {
					$result = strnatcasecmp($left['vcenter'], $right['vcenter'])
						?: strnatcasecmp($left['name'], $right['name'])
						?: (string) $left['itemid'] <=> (string) $right['itemid'];
				}
				return $sortorder === ZBX_SORT_DOWN ? -$result : $result;
			});

			$issues_paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $issues, ZBX_SORT_UP,
				(new CUrl('zabbix.php'))
					->setArgument('action', 'vmware.monitoring.list')
					->setArgument('tab', 'issues')
					->setArgument('sort', $sort)
					->setArgument('sortorder', $sortorder)
			);
		}
		elseif ($tab === 'problems') {
			$problem_hostids = [];
			foreach ($vcenters as $vcenter) {
				$problem_hostids = array_merge($problem_hostids, $vcenter['problem_hostids']);
			}
			$problem_widget = VMwareCollector::problemWidgetDataForHosts($problem_hostids);
		}
		else {
			$paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $vcenters, ZBX_SORT_UP,
				(new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')
			);
		}

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
			'issues' => $issues,
			'problem_widget' => $problem_widget,
			'totals' => $totals,
			'paging' => $paging,
			'issues_paging' => $issues_paging,
			'tab' => $tab,
			'sort' => $sort,
			'sortorder' => $sortorder,
			'refresh_interval' => timeUnitToSeconds(CWebUser::getRefresh())
		]);
		$response->setTitle(_('VMware vCenters'));
		$this->setResponse($response);
	}
}
