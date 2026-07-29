<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareCollector;

class CControllerVMwareSparkline extends CController {
	private const KEY_PREFIXES = [
		'vmware.hv.cpu.usage.perf[',
		'vmware.vm.cpu.usage.perf['
	];

	private array $items = [];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['itemids' => 'required|array_db items.itemid']);
		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return false;
		}

		$itemids = array_values(array_unique($this->getInput('itemids')));
		$this->items = API::Item()->get([
			'output' => ['itemid', 'key_', 'value_type'],
			'itemids' => $itemids,
			'monitored' => true,
			'webitems' => false,
			'preservekeys' => true
		]) ?: [];

		if (count($this->items) !== count($itemids)) {
			return false;
		}

		foreach ($this->items as $item) {
			$allowed = false;
			foreach (self::KEY_PREFIXES as $prefix) {
				if (str_starts_with($item['key_'], $prefix)) {
					$allowed = true;
					break;
				}
			}
			if (!$allowed) {
				return false;
			}
		}

		return true;
	}

	protected function doAction(): void {
		$items = [];
		foreach ($this->items as $itemid => $item) {
			$items[$itemid] = $item['value_type'];
		}

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode([
				'history' => VMwareCollector::sparklineHistory($items)
			])
		]));
	}
}
