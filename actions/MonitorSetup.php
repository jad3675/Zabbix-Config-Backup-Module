<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Monitor;

/**
 * Settings > Notify through Zabbix: create/update the template and host, or stop reporting.
 */
class MonitorSetup extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'op' => 'required|in enable,disable',
			'monitor_host' => 'string',
			'monitor_group' => 'string'
		]);

		if (!$ret) {
			$this->redirectTo('configbackup.settings');
		}

		return $ret;
	}

	protected function doAction(): void {
		$settings = $this->settings();

		if ($this->getInput('op') === 'disable') {
			$settings['monitoring']['enabled'] = false;
			$this->saveSettings($settings);
			CMessageHelper::setSuccessTitle(_('Reporting to Zabbix turned off'));
			CMessageHelper::addSuccess(sprintf(_('Host "%1$s" was left in place; delete it yourself if you no longer want it. Its "no word from the runner" problem will fire unless you disable or delete the host.'),
				$settings['monitoring']['host']
			));
			$this->redirectTo('configbackup.settings');

			return;
		}

		$host = trim($this->getInput('monitor_host', '')) ?: 'Config backup';
		$group = trim($this->getInput('monitor_group', '')) ?: 'Zabbix servers';

		try {
			$message = Monitor::ensure($this->api(), $host, $group,
				Monitor::runnerAddresses($settings['api']['url'])
			);
		}
		catch (\Throwable $e) {
			CMessageHelper::setErrorTitle(_('Cannot set up the monitoring host'));
			CMessageHelper::addError($e->getMessage());
			$this->redirectTo('configbackup.settings');

			return;
		}

		$settings['monitoring'] = ['enabled' => true, 'host' => $host, 'group' => $group];
		$this->saveSettings($settings);

		CMessageHelper::setSuccessTitle(_('Reporting to Zabbix is on'));
		CMessageHelper::addSuccess($message);
		CMessageHelper::addSuccess(_('The runner reports on its next pass. The server picks up new items within its configuration cache interval (a minute or so), so the first pass may only create them.'));
		$this->redirectTo('configbackup.settings');
	}
}
