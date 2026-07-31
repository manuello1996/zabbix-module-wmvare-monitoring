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
	private array $vcenter = [];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostid' => 'required|db hosts.hostid',
			'filter_name' => 'string',
			'filter_status' => 'in all,non_green,unsupported,0,1,2,3',
			'filter_types' => 'array',
			'filter_types_present' => 'in 1',
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
		$vcenter = VMwareCollector::vcenterForDiscoveredHost((string) $this->host['hostid']);
		if ($vcenter === null) {
			return false;
		}
		$this->vcenter = $vcenter;
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
		$type_counts = [];
		foreach ($all_sensors as $sensor) {
			$type = trim($sensor['type']) !== '' ? trim($sensor['type']) : _('Other');
			$type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
			if ($sensor['state'] === ITEM_STATE_NOTSUPPORTED) {
				$counts['unsupported']++;
				continue;
			}
			$key = in_array((string) $sensor['value'], ['0', '1', '2', '3'], true)
				? (string) $sensor['value']
				: '0';
			$counts[$key]++;
		}
		uksort($type_counts, 'strnatcasecmp');
		$available_types = array_keys($type_counts);
		$selected_types = array_values(array_intersect(
			$available_types,
			array_map('strval', $this->getInput('filter_types', []))
		));
		if (!$this->hasInput('filter_types_present')) {
			$selected_types = $available_types;
		}

		$sensors = array_values(array_filter($all_sensors, static function (array $sensor) use ($search,
				$status_filter, $selected_types): bool {
			$type = trim($sensor['type']) !== '' ? trim($sensor['type']) : _('Other');
			if (!in_array($type, $selected_types, true)) {
				return false;
			}
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
			$type_result = strcasecmp(
				trim($a['type']) !== '' ? $a['type'] : _('Other'),
				trim($b['type']) !== '' ? $b['type'] : _('Other')
			);
			if ($sort === 'type') {
				$type_result = $sortorder === ZBX_SORT_DOWN ? -$type_result : $type_result;
				return $type_result !== 0 ? $type_result : strcasecmp($a['name'], $b['name']);
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
			if ($result === 0) {
				$result = strcasecmp($a['name'], $b['name']) ?: $type_result;
			}
			return $sortorder === ZBX_SORT_DOWN ? -$result : $result;
		});

		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'vmware.monitoring.sensors')
			->setArgument('hostid', $hostid)
			->setArgument('filter_name', $search !== '' ? $search : null)
			->setArgument('filter_status', $status_filter !== 'all' ? $status_filter : null)
			->setArgument('filter_types', $selected_types)
			->setArgument('filter_types_present', 1)
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
			'vcenter' => $this->vcenter,
			'sensors' => $sensors,
			'counts' => $counts,
			'type_counts' => $type_counts,
			'filter' => ['name' => $search, 'status' => $status_filter, 'types' => $selected_types],
			'sort' => $sort,
			'sortorder' => $sortorder,
			'paging' => $paging
		]);
		$response->setTitle(_('VMware sensors'));
		$this->setResponse($response);
	}
}
