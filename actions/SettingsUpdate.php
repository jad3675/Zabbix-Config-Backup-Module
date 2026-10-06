<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\ApiException;
use Modules\ConfigBackup\Lib\Runner;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Schedule;
use Modules\ConfigBackup\Lib\Settings;
use Modules\ConfigBackup\Lib\Store;
use Modules\ConfigBackup\Lib\Types;

class SettingsUpdate extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'storage' => 'required|string|not_empty',
			'keep_count' => 'required|int32|ge 1|le 100000',
			'keep_days' => 'required|int32|ge 0|le 36500',
			'expect_hours' => 'required|int32|ge 0|le 8760',
			'types' => 'array',
			'api_url' => 'string',
			'api_token' => 'string',
			'api_token_clear' => 'in 0,1',
			'api_verify_tls' => 'in 0,1',
			'api_ca_file' => 'string',
			'timezone' => 'string'
		]);

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Cannot update settings'));
			$this->redirectTo('configbackup.settings');
		}

		return $ret;
	}

	protected function doAction(): void {
		$storage = rtrim(trim($this->getInput('storage')), '/');

		if ($storage === '' || $storage[0] !== '/' || str_contains($storage, '..')) {
			CMessageHelper::setErrorTitle(_('Cannot update settings'));
			CMessageHelper::addError(_('Storage path must be an absolute path.'));
			$this->redirectTo('configbackup.settings');

			return;
		}

		$problem = (new Store($storage))->check();

		if ($problem !== null) {
			CMessageHelper::setErrorTitle(_('Cannot update settings'));
			CMessageHelper::addError($problem);
			$this->redirectTo('configbackup.settings');

			return;
		}

		// A new storage path starts with the current settings.
		$settings = $this->settings();

		try {
			if ($storage !== $this->storage()) {
				$config = $this->moduleConfig();
				$this->api()->call('module.update', [
					'moduleid' => $config['moduleid'],
					'config' => ['storage' => $storage]
				]);
			}

			$types = array_values(array_filter($this->getInput('types', []), [Types::class, 'exists']));

			$settings['keep_count'] = $this->getInput('keep_count');
			$settings['keep_days'] = $this->getInput('keep_days');
			$settings['expect_hours'] = $this->getInput('expect_hours');

			$tz = $this->getInput('timezone', $settings['timezone']);
			if ($tz !== $settings['timezone'] && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
				$settings['timezone'] = $tz;
				// Slots move with the zone: start each schedule from its next slot in the new zone.
				foreach ($settings['schedules'] as $s) {
					Runtime::update($storage, 'schedules', $s['id'], [
						'last_slot' => Schedule::lastSlot($s, time(), $tz), 'armed' => time()
					]);
				}
			}
			$settings['types'] = count($types) == count(Types::all()) ? [] : $types;
			// Store the frontend base address even if a browser URL was pasted.
			$url = preg_replace('~[?#].*$~', '', trim($this->getInput('api_url', '')));
			$settings['api']['url'] = rtrim(preg_replace('~/(zabbix|index|api_jsonrpc)\.php$~', '', $url), '/');

			$settings['api']['verify_tls'] = $this->getInput('api_verify_tls', 0) == 1;
			$settings['api']['ca_file'] = trim($this->getInput('api_ca_file', ''));

			if ($settings['api']['ca_file'] !== '' && $settings['api']['ca_file'][0] !== '/') {
				throw new \RuntimeException(_('CA certificate file must be an absolute path.'));
			}

			$token = trim($this->getInput('api_token', ''));
			if ($this->getInput('api_token_clear', 0) == 1) {
				$settings['api']['token'] = '';
			}
			elseif ($token !== '') {
				$settings['api']['token'] = Runtime::seal($storage, $token);
			}

			// Secrets are sealed to the key pair under the storage path; moving storage means re-sealing.
			if ($storage !== $this->storage()) {
				foreach (glob($this->storage().'/.state/runner.{key,pub}', GLOB_BRACE) ?: [] as $file) {
					Runtime::stateDir($storage);
					@copy($file, $storage.'/.state/'.basename($file));
				}
			}

			Settings::save($storage, $settings);
		}
		catch (ApiException|\RuntimeException $e) {
			CMessageHelper::setErrorTitle(_('Cannot update settings'));
			CMessageHelper::addError($e->getMessage());
			$this->redirectTo('configbackup.settings');

			return;
		}

		// Check the URL and token now, from this server, instead of waiting for the runner's next pass.
		$api = $settings['api'];

		if ($api['url'] === '' || $api['token'] === '') {
			CMessageHelper::setSuccessTitle(_('Settings updated'));
			CMessageHelper::addWarning(_('Enter the Zabbix URL and an API token: the runner cannot back up anything without them.'));
		}
		else {
			try {
				$message = Runner::testApi($api['url'], Runtime::reveal($storage, $api['token']), $api['verify_tls'],
					$api['ca_file']
				);

				if (!$api['verify_tls'] && preg_match('~^https://~i', $api['url'])) {
					$message .= ' (certificate not verified)';
				}
				$ok = true;
			}
			catch (\Throwable $e) {
				$message = $e->getMessage();
				$ok = false;
			}

			// Show the fresh result on the status line until the runner checks again.
			Runtime::write($storage, 'runner', ['api_ok' => $ok, 'api_message' => $message, 'api_checked' => time()]
				+ Runtime::read($storage, 'runner')
			);

			if ($ok) {
				CMessageHelper::setSuccessTitle(_('Settings updated'));
				CMessageHelper::addSuccess(_('API connection').': '.$message);
			}
			else {
				CMessageHelper::setErrorTitle(_('Settings saved, but the runner cannot use the Zabbix API'));
				CMessageHelper::addError($message);
			}
		}

		$this->redirectTo('configbackup.settings');
	}
}
