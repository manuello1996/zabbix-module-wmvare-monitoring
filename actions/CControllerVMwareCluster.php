<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CRoleHelper;
use CUrl;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareCollector;

class CControllerVMwareCluster extends CController {
	private array $vcenter = [];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostid' => 'required|db hosts.hostid',
			'cluster' => 'required|string',
			'page' => 'ge 1'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		$hostid = (string) $this->getInput('hostid');
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)
				|| !in_array($hostid, VMwareCollector::findVCenterHostids(), true)) {
			return false;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => [$hostid]
		]) ?: [];
		if (!$hosts) {
			return false;
		}

		$this->vcenter = reset($hosts);
		return true;
	}

	protected function doAction(): void {
		$hostid = (string) $this->vcenter['hostid'];
		$cluster_name = trim((string) $this->getInput('cluster'));
		$cluster = VMwareCollector::clusterDetail($hostid, $cluster_name);
		if ($cluster === null) {
			$this->setResponse(new CControllerResponseFatal());
			return;
		}

		$cluster['hostids'] = array_column($cluster['hypervisors'], 'hostid');
		$cluster['problem_hostids'] = array_merge([$hostid], $cluster['hostids']);
		$cluster['hypervisor_count'] = count($cluster['hypervisors']);
		$paging = CPagerHelper::paginate(
			(int) $this->getInput('page', 1),
			$cluster['hypervisors'],
			ZBX_SORT_UP,
			(new CUrl('zabbix.php'))
				->setArgument('action', 'vmware.monitoring.cluster')
				->setArgument('hostid', $hostid)
				->setArgument('cluster', $cluster_name)
		);

		$response = new CControllerResponseData([
			'vcenter' => $this->vcenter,
			'cluster' => $cluster,
			'paging' => $paging
		]);
		$response->setTitle(_('VMware cluster'));
		$this->setResponse($response);
	}
}
