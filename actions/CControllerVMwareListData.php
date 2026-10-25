<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\VMwareMonitoring\Includes\Common\Pager as CPagerHelper;
use CView;
use CRoleHelper;
use CSettingsHelper;
use CUrl;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareCollector;
use Modules\VMwareMonitoring\Includes\VMwareLocationGroups;
use Modules\VMwareMonitoring\Includes\VMwareVCenterSort;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

class CControllerVMwareListData extends CController {
	private const TABS = ['vcenters', 'issues', 'problems'];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'filter_groupids' => 'array_db hstgrp.groupid',
			'filter_hostids' => 'array_db hosts.hostid',
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
		$groupids = $this->getInput('filter_groupids', []);
		$filter_hostids = $this->getInput('filter_hostids', []);
		$tab = (string) $this->getInput('tab', 'vcenters');
		$sort = (string) $this->getInput('sort', 'vcenter');
		$sortorder = (string) $this->getInput('sortorder', ZBX_SORT_UP);
		$force_refresh = $this->hasInput('force_vcenter_refresh');
		$vcenter_hostids = VMwareCollector::findVCenterHostids(
			$groupids ?: null,
			$force_refresh
		);
		if ($force_refresh) {
			VMwareCollector::clearTopologyCaches($vcenter_hostids);
			VMwareCollector::clearSessionDataCache();
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
			$host['location_path'] = VMwareLocationGroups::path($host['macros'] ?? []);
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
		$datastore_ids = [];
		foreach ($vcenters as $vcenter) {
			$totals['hypervisors'] += $vcenter['hypervisors'];
			$totals['vms'] += $vcenter['vms'];
			$datastore_ids += array_fill_keys($vcenter['datastore_ids'], true);
			$totals['datastore_attachments'] += $vcenter['datastore_attachments'];
		}
		$totals['datastores'] = count($datastore_ids);

		VMwareVCenterSort::sort($vcenters, $sort, $sortorder);

		$location_groups = VMwareLocationGroups::build($vcenters);
		$paging = null;
		$issues_paging = null;
		$issues_has_more = false;

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
			$issues_has_more = count($issues)
				> max(1, (int) (CWebUser::$data['rows_per_page'] ?? ZBX_DEFAULT_WIDGET_LINES));

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
			$problem_hostids = array_values(array_unique(array_map('strval', $problem_hostids)));
			$problem_widget = VMwareCollector::problemWidgetDataForHosts(
				$problem_hostids,
				max(1, (int) (CWebUser::$data['rows_per_page'] ?? ZBX_DEFAULT_WIDGET_LINES))
			);
		}
		else {
			$paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $vcenters, ZBX_SORT_UP,
				(new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.list')
					->setArgument('sort', $sort)->setArgument('sortorder', $sortorder)
			);
		}

		$data = [
			'vcenters' => $vcenters,
			'location_groups' => $location_groups,
			'issues' => $issues,
			'problem_widget' => $problem_widget,
			'totals' => $totals,
			'paging' => $paging,
			'issues_paging' => $issues_paging,
			'issues_has_more' => $issues_has_more,
			'tab' => $tab,
			'sort' => $sort,
			'sortorder' => $sortorder
		];
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode([
				'summary' => (new CView('vmware.monitoring.list.summary', $data))->getOutput(),
				'content' => (new CView('vmware.monitoring.list.content', $data))->getOutput()
			])
		]));
	}
}
