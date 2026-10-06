<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Settings;

/**
 * What a destination holds, as of the runner's last listing (the frontend cannot open the credentials itself).
 */
class RemoteList extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['destination' => 'required|string']);

		if (!$ret) {
			$this->redirectTo('configbackup.destinations');
		}

		return $ret;
	}

	protected function doAction(): void {
		$d = Settings::destination($this->settings(), $this->getInput('destination'));

		if ($d === null || $d['kind'] === 'git') {
			$this->redirectTo('configbackup.destinations');

			return;
		}

		$state = Runtime::read($this->storage(), 'destinations')[$d['id']] ?? [];
		$local = $this->store()->listSnapshots();

		$this->setResponse(new CControllerResponseData([
			'title' => sprintf(_('Snapshots on %1$s'), $d['name']),
			'd' => $d,
			'listing' => $state['listing'] ?? [],
			'listing_time' => $state['listing_time'] ?? null,
			'local' => array_fill_keys(array_keys($local), true),
			'can_decrypt' => $d['private_key_path'] !== '',
			'schedules' => $this->settings()['schedules'],
			'pending' => array_values(array_filter(Runtime::pending($this->storage()),
				static fn($r) => ($r['destination'] ?? null) === $d['id']
			)),
			'csrf' => CCsrfTokenHelper::get('configbackup.queue'),
			'csrf_pull' => CCsrfTokenHelper::get('configbackup.pull')
		]));
	}
}
