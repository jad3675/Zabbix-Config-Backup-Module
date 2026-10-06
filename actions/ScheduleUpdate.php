<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Schedule;
use Modules\ConfigBackup\Lib\Settings;
use Modules\ConfigBackup\Lib\Types;

class ScheduleUpdate extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'op' => 'required|in save,delete,toggle',
			'id' => 'string',
			'name' => 'string',
			'enabled' => 'in 0,1',
			'every' => 'in hours,daily,weekly',
			'hours' => 'in 1,2,3,4,6,8,12',
			'at' => 'string',
			'weekday' => 'in 0,1,2,3,4,5,6',
			'types' => 'array',
			'all_types' => 'in 0,1',
			'destinations' => 'array',
			'keep_count' => 'int32|ge 1|le 100000',
			'keep_days' => 'int32|ge 0|le 36500'
		]);

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Cannot save schedule'));
			$this->redirectTo('configbackup.schedules');
		}

		return $ret;
	}

	protected function doAction(): void {
		$settings = $this->settings();
		$id = $this->getInput('id', '');
		$op = $this->getInput('op');
		$index = null;

		foreach ($settings['schedules'] as $i => $s) {
			if ($s['id'] === $id) {
				$index = $i;
			}
		}

		if ($op !== 'save' && $index === null) {
			CMessageHelper::setErrorTitle(_('Unknown schedule'));
			$this->redirectTo('configbackup.schedules');

			return;
		}

		if ($op === 'delete') {
			$name = $settings['schedules'][$index]['name'];
			array_splice($settings['schedules'], $index, 1);
			$this->saveSettings($settings);
			CMessageHelper::setSuccessTitle(sprintf(_('Schedule "%1$s" deleted. Its snapshots stay until you delete them.'), $name));
			$this->redirectTo('configbackup.schedules');

			return;
		}

		if ($op === 'toggle') {
			$settings['schedules'][$index]['enabled'] = !$settings['schedules'][$index]['enabled'];
			$this->saveSettings($settings);
			$this->redirectTo('configbackup.schedules');

			return;
		}

		$name = trim($this->getInput('name', ''));
		$at = trim($this->getInput('at', '02:30'));

		if ($name === '' || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $at)) {
			CMessageHelper::setErrorTitle(_('Cannot save schedule'));
			CMessageHelper::addError($name === '' ? _('Name cannot be empty.') : _('Time must be HH:MM.'));
			$this->redirectTo('configbackup.schedule.edit', ['id' => $id]);

			return;
		}

		$types = array_values(array_filter($this->getInput('types', []), [Types::class, 'exists']));
		$dest_ids = array_column($settings['destinations'], 'id');

		$schedule = [
			'id' => $index !== null ? $id : Settings::newId(),
			'name' => $name,
			'enabled' => $this->getInput('enabled', 0) == 1,
			'every' => $this->getInput('every', 'daily'),
			'hours' => (int) $this->getInput('hours', 6),
			'at' => sprintf('%05s', $at),
			'weekday' => (int) $this->getInput('weekday', 0),
			'types' => $this->getInput('all_types', 0) == 1 || count($types) == count(Types::all()) ? [] : $types,
			'destinations' => array_values(array_intersect(array_map('strval', $this->getInput('destinations', [])), $dest_ids)),
			'keep_count' => (int) $this->getInput('keep_count', 30),
			'keep_days' => (int) $this->getInput('keep_days', 0)
		];

		if ($index !== null) {
			$settings['schedules'][$index] = $schedule;
		}
		else {
			$settings['schedules'][] = $schedule;
		}

		$this->saveSettings($settings);

		// Arm it now: every slot after this moment runs. (Leaving this to the runner's next pass, up to 5 minutes
		// later, let a slot falling inside that gap be skipped.) A slot already in the past is not caught up.
		Runtime::update($this->storage(), 'schedules', $schedule['id'], [
			'last_slot' => Schedule::lastSlot($schedule, time(), Settings::timezone($settings)),
			'armed' => time()
		]);

		CMessageHelper::setSuccessTitle(sprintf(_('Schedule "%1$s" saved'), $name));
		$this->redirectTo('configbackup.schedules');
	}
}
