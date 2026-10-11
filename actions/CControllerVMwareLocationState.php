<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use CController;
use CControllerResponseData;
use CProfile;
use CRoleHelper;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareLocationGroups;

class CControllerVMwareLocationState extends CController {
	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'key' => 'required|string',
			'collapsed' => 'required|in 0,1'
		]);
		$valid = $valid && preg_match('/^[a-f0-9]{64}$/D', $this->getInput('key')) === 1;
		if (!$valid) {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode(['error' => _('Invalid location state.')])
			]));
		}
		return $valid;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		DBstart();
		$idx = VMwareLocationGroups::PROFILE_PREFIX.$this->getInput('key');
		if ((int) $this->getInput('collapsed') === 1) {
			CProfile::update($idx, 1, PROFILE_TYPE_INT);
		}
		else {
			CProfile::delete($idx);
		}
		$saved = !CProfile::isModified() || CProfile::flush();
		$saved = DBend($saved);
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($saved
				? ['success' => true]
				: ['error' => _('Unable to save location state.')])
		]));
	}
}
