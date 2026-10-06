<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Settings;
use Modules\ConfigBackup\Lib\Shipper;

/**
 * "Pull and open": fetch a remote-only snapshot right now, from the web server, and open it.
 *
 * If the web server cannot do it (SELinux blocking outbound connections from php-fpm, a proxy only the runner
 * knows about), the pull is handed to the runner instead and shows up within 5 minutes.
 */
class PullRun extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'destination' => 'required|string',
			'name' => 'required|string',
			'back' => 'in list,remote'
		]) && preg_match(Shipper::NAME, $this->getInput('name'));

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Unknown snapshot'));
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction(): void {
		self::unlimited();

		$d = Settings::destination($this->settings(), $this->getInput('destination'));
		$name = $this->getInput('name');
		$snapshotid = Shipper::idFromName($name);
		$back = $this->getInput('back', 'list');

		if ($d === null) {
			CMessageHelper::setErrorTitle(_('Unknown destination'));
			$this->redirectTo('configbackup.list');

			return;
		}

		if ($this->store()->exists($snapshotid)) {
			$this->redirectTo('configbackup.snapshot', ['snapshotid' => $snapshotid]);

			return;
		}

		try {
			(new Shipper($this->store()))->pull($d, $name);
		}
		catch (\Throwable $e) {
			$encrypted = str_contains($e->getMessage(), 'encrypted');

			if (!$encrypted) {
				$this->enqueue(['kind' => 'pull', 'destination' => $d['id'], 'name' => $name]);
			}

			CMessageHelper::setErrorTitle($encrypted
				? _('Cannot decrypt this snapshot here')
				: _('Could not pull from the web server; the runner will try within 5 minutes')
			);
			CMessageHelper::addError($e->getMessage());

			$back === 'remote'
				? $this->redirectTo('configbackup.remote', ['destination' => $d['id']])
				: $this->redirectTo('configbackup.list');

			return;
		}

		CMessageHelper::setSuccessTitle(sprintf(_('Pulled from %1$s'), $d['name']));
		CMessageHelper::addSuccess(_('It is pinned so retention leaves it alone. Unpin it in Snapshot details when you are done.'));

		$this->redirectTo('configbackup.snapshot', ['snapshotid' => $snapshotid]);
	}
}
