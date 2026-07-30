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

class CControllerVMwareSensors extends CController {
	private array $host = [];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostid' => 'required|db hosts.hostid',
			'filter_name' => 'string',
			'filter_status' => 'in all,non_green,unsupported,0,1,2,3',
			'sort' => 'in name,type,status,lastclock,problems',
			'sortorder' => 'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP,
			'page' => 'ge 1'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return false;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => [$this->getInput('hostid')]
		]) ?: [];
		if (!$hosts) {
			return false;
		}

		$this->host = reset($hosts);
		return true;
	}

	protected function doAction(): void {
		$hostid = (string) $this->host['hostid'];
		$search = trim((string) $this->getInput('filter_name', ''));
		$status_filter = (string) $this->getInput('filter_status', 'all');
		$sort = (string) $this->getInput('sort', 'name');
		$sortorder = (string) $this->getInput('sortorder', ZBX_SORT_UP);
		$all_sensors = VMwareCollector::sensors($hostid);

		$counts = [
			'total' => count($all_sensors), '0' => 0, '1' => 0, '2' => 0, '3' => 0, 'unsupported' => 0
		];
		foreach ($all_sensors as $sensor) {
			if ($sensor['state'] === ITEM_STATE_NOTSUPPORTED) {
				$counts['unsupported']++;
				continue;
			}
			$key = in_array((string) $sensor['value'], ['0', '1', '2', '3'], true)
				? (string) $sensor['value']
				: '0';
			$counts[$key]++;
		}

		$sensors = array_values(array_filter($all_sensors, static function (array $sensor) use ($search,
				$status_filter): bool {
			if ($search !== '' && stripos($sensor['name'].' '.$sensor['type'], $search) === false) {
				return false;
			}

			$value = $sensor['value'] === null ? '0' : (string) $sensor['value'];
			if ($status_filter === 'non_green') {
				return $sensor['state'] === ITEM_STATE_NOTSUPPORTED || $value !== '1';
			}
			if ($status_filter === 'unsupported') {
				return $sensor['state'] === ITEM_STATE_NOTSUPPORTED;
			}
			return $status_filter === 'all'
				|| ($sensor['state'] !== ITEM_STATE_NOTSUPPORTED && $value === $status_filter);
		}));

		usort($sensors, static function (array $a, array $b) use ($sort, $sortorder): int {
			$left = match ($sort) {
				'type' => mb_strtolower($a['type']),
				'status' => (int) ($a['value'] ?? -1),
				'lastclock' => $a['lastclock'],
				'problems' => count($a['problems']),
				default => mb_strtolower($a['name'])
			};
			$right = match ($sort) {
				'type' => mb_strtolower($b['type']),
				'status' => (int) ($b['value'] ?? -1),
				'lastclock' => $b['lastclock'],
				'problems' => count($b['problems']),
				default => mb_strtolower($b['name'])
			};
			$result = $left <=> $right;
			return $sortorder === ZBX_SORT_DOWN ? -$result : $result;
		});

		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'vmware.monitoring.sensors')
			->setArgument('hostid', $hostid)
			->setArgument('filter_name', $search !== '' ? $search : null)
			->setArgument('filter_status', $status_filter !== 'all' ? $status_filter : null)
			->setArgument('sort', $sort)
			->setArgument('sortorder', $sortorder);
		$paging = CPagerHelper::paginate(
			(int) $this->getInput('page', 1),
			$sensors,
			ZBX_SORT_UP,
			$url
		);

		$response = new CControllerResponseData([
			'host' => $this->host,
			'sensors' => $sensors,
			'counts' => $counts,
			'filter' => ['name' => $search, 'status' => $status_filter],
			'sort' => $sort,
			'sortorder' => $sortorder,
			'paging' => $paging
		]);
		$response->setTitle(_('VMware sensors'));
		$this->setResponse($response);
	}
}
