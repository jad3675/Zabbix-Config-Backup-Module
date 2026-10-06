<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ConfigBackup\Lib\Runtime;

class ScheduleList extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$problem = $this->store()->check();

		$this->setResponse(new CControllerResponseData([
			'title' => _('Backup schedules'),
			'settings' => $this->settings(),
			'state' => $problem === null ? Runtime::read($this->storage(), 'schedules') : [],
			'runner' => $this->runnerStatus(),
			'log' => $problem === null ? \Modules\ConfigBackup\Lib\Runner::logTail($this->storage(), 40) : [],
			'storage_problem' => $problem,
			'csrf' => [
				'update' => CCsrfTokenHelper::get('configbackup.schedule.update'),
				'queue' => CCsrfTokenHelper::get('configbackup.queue')
			]
		]));
	}
}
