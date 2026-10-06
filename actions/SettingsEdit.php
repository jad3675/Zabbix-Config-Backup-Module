<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ConfigBackup\Lib\Store;
use Modules\ConfigBackup\Lib\Types;

class SettingsEdit extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$settings = $this->settings();
		$sections = [];

		foreach (Types::all() as $key => $type) {
			$sections[$type['section']][$key] = $type['label'];
		}

		$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$here = $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

		// Suggest Zabbix's own default zone until one is chosen.
		$suggested_tz = $settings['timezone'];

		if ($suggested_tz === '') {
			try {
				$zabbix_tz = (string) ($this->api()->call('settings.get', ['output' => ['default_timezone']])['default_timezone'] ?? '');
			}
			catch (\Throwable $e) {
				$zabbix_tz = '';
			}

			$suggested_tz = $zabbix_tz !== '' && $zabbix_tz !== 'system' ? $zabbix_tz : (ini_get('date.timezone') ?: 'UTC');
		}

		$this->setResponse(new CControllerResponseData([
			'timezone' => $suggested_tz,
			'timezone_chosen' => $settings['timezone'] !== '',
			'title' => _('Config backup settings'),
			'storage' => $this->storage(),
			'settings' => $settings,
			'token_set' => $settings['api']['token'] !== '',
			'suggested_url' => $settings['api']['url'] !== '' ? $settings['api']['url'] : $here,
			'storage_problem' => $this->store()->check(),
			'runner' => $this->runnerStatus(),
			'process_user' => Store::processUser(),
			'module_dir' => dirname(__DIR__),
			'sections' => $sections,
			'monitor_host_id' => $this->monitorHostId($settings),
			'csrf' => CCsrfTokenHelper::get('configbackup.settings.update'),
			'csrf_monitor' => CCsrfTokenHelper::get('configbackup.monitor.setup')
		]));
	}

	private function monitorHostId(array $settings): ?string {
		if (!$settings['monitoring']['enabled']) {
			return null;
		}

		try {
			$hosts = $this->api()->call('host.get', ['output' => ['hostid'], 'filter' => ['host' => $settings['monitoring']['host']]]);
		}
		catch (\Throwable $e) {
			return null;
		}

		return $hosts ? $hosts[0]['hostid'] : null;
	}
}
