<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareCollector;
use Modules\VMwareMonitoring\Includes\VMwareTabRenderer;

class CControllerVMwareTab extends CController {
	private const TABS = [
		'overview', 'hypervisors', 'vms', 'datastores', 'clusters', 'alarms'
	];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostid' => 'required|db hosts.hostid',
			'tab' => 'required|in '.implode(',', self::TABS),
			'page' => 'ge 1',
			'search' => 'string',
			'sort' => 'in name,cluster,cpu,memory,vms,uptime,version,total,free,attachments',
			'sortorder' => 'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN
		]);
		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)
			&& in_array((string) $this->getInput('hostid'), VMwareCollector::findVCenterHostids(), true);
	}

	protected function doAction(): void {
		$hostid = (string) $this->getInput('hostid');
		$tab = (string) $this->getInput('tab');
		$page = (int) $this->getInput('page', 1);
		$search = trim((string) $this->getInput('search', ''));
		$sort = (string) $this->getInput('sort', 'name');
		$sortorder = (string) $this->getInput('sortorder', ZBX_SORT_UP);
		$rows_per_page = max(1, (int) (CWebUser::$data['rows_per_page'] ?? 25));

		$data = match ($tab) {
			'overview' => VMwareCollector::summary($hostid),
			'hypervisors' => VMwareCollector::hypervisorsPage(
				$hostid, $page, $rows_per_page, $search, $sort, $sortorder
			),
			'vms' => VMwareCollector::virtualMachinesPage($hostid, $page, $rows_per_page, $search)
				+ ['search' => $search],
			'datastores' => VMwareCollector::datastorePages(
				$hostid, $page, $rows_per_page, $search, $sort, $sortorder
			),
			'clusters' => VMwareCollector::clusters($hostid),
			'alarms' => VMwareCollector::alarms($hostid)
		};

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode([
				'html' => VMwareTabRenderer::render($tab, $data)
			])
		]));
	}
}
