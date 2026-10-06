<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ConfigBackup\Lib\Settings;
use Modules\ConfigBackup\Lib\Types;

class ScheduleEdit extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['id' => 'string']);
	}

	protected function doAction(): void {
		$settings = $this->settings();
		$id = $this->getInput('id', '');
		$schedule = $id !== '' ? Settings::schedule($settings, $id) : null;
		$sections = [];

		foreach (Types::all() as $key => $type) {
			$sections[$type['section']][$key] = $type['label'];
		}

		$this->setResponse(new CControllerResponseData([
			'title' => $schedule !== null ? _('Edit schedule') : _('New schedule'),
			'schedule' => $schedule ?? Settings::SCHEDULE_DEFAULTS + ['name' => _('Nightly')],
			'is_new' => $schedule === null,
			'destinations' => $settings['destinations'],
			'timezone' => Settings::timezone($settings),
			'timezone_chosen' => $settings['timezone'] !== '',
			'sections' => $sections,
			'csrf' => CCsrfTokenHelper::get('configbackup.schedule.update')
		]));
	}
}
