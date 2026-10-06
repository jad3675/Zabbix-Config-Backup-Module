<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Settings;
use Modules\ConfigBackup\Lib\Shipper;
use Modules\ConfigBackup\Lib\Store;

/**
 * Hands work to the runner: send a snapshot, pull one back, test or list a destination, run a schedule now.
 */
class QueueAdd extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'kind' => 'required|in upload,pull,test,list,run',
			'snapshotid' => 'string',
			'destination' => 'string',
			'name' => 'string',
			'schedule' => 'string',
			'back' => 'in list,snapshot,destinations,remote,schedules'
		]);

		if (!$ret) {
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction() {
		$settings = $this->settings();
		$kind = $this->getInput('kind');
		$request = ['kind' => $kind];
		$back = $this->getInput('back', 'list');

		if ($kind === 'run') {
			$schedule = Settings::schedule($settings, $this->getInput('schedule', ''));

			if ($schedule === null) {
				return $this->done(false, _('Unknown schedule'), $back);
			}

			$request['schedule'] = $schedule['id'];
		}
		else {
			$d = Settings::destination($settings, $this->getInput('destination', ''));

			if ($d === null) {
				return $this->done(false, _('Unknown destination'), $back);
			}

			$request['destination'] = $d['id'];

			if ($kind === 'upload') {
				$snapshotid = $this->getInput('snapshotid', '');

				if (!Store::isValidId($snapshotid) || !$this->store()->exists($snapshotid)) {
					return $this->done(false, _('Unknown snapshot'), $back);
				}

				$request['snapshotid'] = $snapshotid;
			}

			if ($kind === 'pull') {
				$name = $this->getInput('name', '');

				if (!preg_match(Shipper::NAME, $name)) {
					return $this->done(false, _('Unknown snapshot'), $back);
				}

				$request['name'] = $name;
			}

			if (in_array($kind, ['upload', 'pull', 'list'], true) && $d['kind'] === 'git' && $kind !== 'upload') {
				return $this->done(false, _('Git destinations keep history, not snapshot files'), $back);
			}
		}

		$this->enqueue($request);

		return $this->done(true, _('Queued. The runner picks it up within 5 minutes; the result shows on the Config backup page.'),
			$back
		);
	}

	private function done(bool $ok, string $message, string $back) {
		$ok ? CMessageHelper::setSuccessTitle($message) : CMessageHelper::setErrorTitle($message);

		switch ($back) {
			case 'snapshot':
				$this->redirectTo('configbackup.snapshot', ['snapshotid' => $this->getInput('snapshotid', '')]);
				break;

			case 'remote':
				$this->redirectTo('configbackup.remote', ['destination' => $this->getInput('destination', '')]);
				break;

			case 'destinations':
			case 'schedules':
				$this->redirectTo('configbackup.'.$back);
				break;

			default:
				$this->redirectTo('configbackup.list');
		}
	}
}
