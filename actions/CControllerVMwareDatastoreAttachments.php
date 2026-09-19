<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\VMwareMonitoring\Includes\VMwareCollector;
use Modules\VMwareMonitoring\Includes\VMwareTabRenderer;

class CControllerVMwareDatastoreAttachments extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostid' => 'required|db hosts.hostid',
			'identity' => 'required|string'
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
		$attachments = VMwareCollector::datastoreAttachmentRows(
			(string) $this->getInput('hostid'), (string) $this->getInput('identity')
		);
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode([
				'html' => VMwareTabRenderer::renderDatastoreAttachments($attachments)
			])
		]));
	}
}
