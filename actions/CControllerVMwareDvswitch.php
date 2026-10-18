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
use Modules\VMwareMonitoring\Includes\VMwareDvswitch;

class CControllerVMwareDvswitch extends CController {
	private array $vcenter = [];

	protected function init(): void { $this->disableCsrfValidation(); }

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'required|db hosts.hostid', 'uuid' => 'required|string',
			'section' => 'in ports,portgroups,resources,statistics,problems',
			'page' => 'ge 1', 'search' => 'string', 'portgroup' => 'string', 'resource' => 'string',
			'state' => 'in all,connected,unconnected,up,down,blocked,conflict,unknown'
		]);
		if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
		return $valid;
	}

	protected function checkPermissions(): bool {
		$hostid = (string) $this->getInput('hostid');
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)
				|| !in_array($hostid, VMwareCollector::findVCenterHostids(), true)) { return false; }
		$hosts = API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => [$hostid]]) ?: [];
		if (!$hosts) { return false; }
		$this->vcenter = reset($hosts);
		return true;
	}

	protected function doAction(): void {
		$uuid = (string) $this->getInput('uuid');
		$hostid = (string) $this->vcenter['hostid'];
		$switch = VMwareCollector::distributedSwitchDetail($hostid, $uuid);
		if ($switch === null) { $this->setResponse(new CControllerResponseFatal()); return; }
		$section = (string) $this->getInput('section', 'ports');
		$filters = [
			'search' => trim((string) $this->getInput('search', '')),
			'state' => (string) $this->getInput('state', 'all'),
			'portgroup' => (string) $this->getInput('portgroup', ''),
			'resource' => (string) $this->getInput('resource', '')
		];
		$rows = $section === 'problems' ? VMwareCollector::distributedSwitchProblems($hostid, $uuid)
			: ($switch['snapshot'][$section] ?? []);
		if ($section === 'ports') {
			$rows = VMwareDvswitch::filterPorts($rows, ...array_values($filters));
		}
		elseif ($filters['search'] !== '') {
			$rows = array_values(array_filter($rows, static fn(array $row): bool =>
				stripos(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $filters['search']) !== false));
		}
		$total = count($rows);
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.dvswitch')
			->setArgument('hostid', $hostid)->setArgument('uuid', $uuid)->setArgument('section', $section);
		foreach ($filters as $key => $value) { $url->setArgument($key, $value); }
		$paging = CPagerHelper::paginate((int) $this->getInput('page', 1), $rows, ZBX_SORT_UP, $url);
		$response = new CControllerResponseData([
			'vcenter' => $this->vcenter, 'switch' => $switch, 'section' => $section,
			'filters' => $filters, 'rows' => $rows, 'total' => $total, 'paging' => $paging
		]);
		$response->setTitle(_('VMware distributed switch'));
		$this->setResponse($response);
	}
}
