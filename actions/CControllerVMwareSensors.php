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

		$group_summaries = [];
		$state_priorities = ['1' => 0, '0' => 1, '2' => 2, 'unsupported' => 3, '3' => 4];
		foreach ($sensors as $sensor) {
			$type = trim($sensor['type']) !== '' ? trim($sensor['type']) : _('Other');
			$state = $sensor['state'] === ITEM_STATE_NOTSUPPORTED
				? 'unsupported'
				: (in_array((string) $sensor['value'], ['0', '1', '2', '3'], true)
					? (string) $sensor['value']
					: '0');
			if (!isset($group_summaries[$type])) {
				$group_summaries[$type] = ['count' => 0, 'state' => '1'];
			}
			$group_summaries[$type]['count']++;
			if ($state_priorities[$state] > $state_priorities[$group_summaries[$type]['state']]) {
				$group_summaries[$type]['state'] = $state;
			}
		}

		usort($sensors, static function (array $a, array $b) use ($sort, $sortorder): int {
			$type_result = strcasecmp(
				trim($a['type']) !== '' ? $a['type'] : _('Other'),
				trim($b['type']) !== '' ? $b['type'] : _('Other')
			);
			if ($sort === 'type') {
				$type_result = $sortorder === ZBX_SORT_DOWN ? -$type_result : $type_result;
				return $type_result !== 0 ? $type_result : strcasecmp($a['name'], $b['name']);
			}
			if ($type_result !== 0) {
				return $type_result;
			}

			$left = match ($sort) {
				'status' => (int) ($a['value'] ?? -1),
				'lastclock' => $a['lastclock'],
				'problems' => count($a['problems']),
				default => mb_strtolower($a['name'])
			};
			$right = match ($sort) {
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
			'group_summaries' => $group_summaries,
			'filter' => ['name' => $search, 'status' => $status_filter],
			'sort' => $sort,
			'sortorder' => $sortorder,
			'paging' => $paging
		]);
		$response->setTitle(_('VMware sensors'));
		$this->setResponse($response);
	}
}
