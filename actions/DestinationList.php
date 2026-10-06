<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ConfigBackup\Lib\Runtime;

class DestinationList extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$problem = $this->store()->check();

		$this->setResponse(new CControllerResponseData([
			'title' => _('Backup destinations'),
			'settings' => $this->settings(),
			'state' => $problem === null ? Runtime::read($this->storage(), 'destinations') : [],
			'pending' => $problem === null ? Runtime::pending($this->storage()) : [],
			'runner' => $this->runnerStatus(),
			'storage_problem' => $problem,
			'csrf' => [
				'update' => CCsrfTokenHelper::get('configbackup.destination.update'),
				'queue' => CCsrfTokenHelper::get('configbackup.queue')
			]
		]));
	}
}
