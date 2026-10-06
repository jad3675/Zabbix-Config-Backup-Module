<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Sealer;
use Modules\ConfigBackup\Lib\Settings;

class DestinationUpdate extends Base {

	private const TEXT = ['name', 'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'sse', 'host', 'username',
		'host_key', 'path', 'url', 'branch', 'git_auth', 'git_user', 'known_hosts', 'author_name', 'author_email',
		'public_key', 'private_key_path'
	];

	protected function checkInput(): bool {
		$rules = [
			'op' => 'required|in save,delete',
			'id' => 'string',
			'kind' => 'in s3,sftp,git',
			'enabled' => 'in 0,1',
			'manual' => 'in 0,1',
			'path_style' => 'in 0,1',
			'git_exclude_people' => 'in 0,1',
			'port' => 'int32|ge 1|le 65535',
			'keep_count' => 'int32|ge 1|le 100000',
			'keep_days' => 'int32|ge 0|le 36500',
			'clear' => 'array'
		];

		foreach (array_merge(self::TEXT, Settings::SECRET_FIELDS) as $field) {
			$rules[$field] = 'string';
		}

		$ret = $this->validateInput($rules);

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Cannot save destination'));
			$this->redirectTo('configbackup.destinations');
		}

		return $ret;
	}

	protected function doAction(): void {
		$settings = $this->settings();
		$id = $this->getInput('id', '');
		$index = null;

		foreach ($settings['destinations'] as $i => $d) {
			if ($d['id'] === $id) {
				$index = $i;
			}
		}

		if ($this->getInput('op') === 'delete') {
			if ($index !== null) {
				$name = $settings['destinations'][$index]['name'];
				array_splice($settings['destinations'], $index, 1);

				foreach ($settings['schedules'] as &$s) {
					$s['destinations'] = array_values(array_diff($s['destinations'], [$id]));
				}
				unset($s);

				$this->saveSettings($settings);
				CMessageHelper::setSuccessTitle(sprintf(_('Destination "%1$s" deleted. Files already there were left alone.'), $name));
			}

			$this->redirectTo('configbackup.destinations');

			return;
		}

		$d = $index !== null ? $settings['destinations'][$index] : Settings::DESTINATION_DEFAULTS;
		$d['id'] = $index !== null ? $id : Settings::newId();
		$d['kind'] = $index !== null ? $d['kind'] : $this->getInput('kind', 's3');

		foreach (self::TEXT as $field) {
			if ($this->hasInput($field)) {
				$d[$field] = trim($this->getInput($field));
			}
		}

		foreach (['enabled', 'manual', 'path_style', 'git_exclude_people'] as $field) {
			$d[$field] = $this->getInput($field, 0) == 1;
		}

		foreach (['port', 'keep_count', 'keep_days'] as $field) {
			if ($this->hasInput($field)) {
				$d[$field] = (int) $this->getInput($field);
			}
		}

		$errors = [];

		if ($d['name'] === '') {
			$errors[] = _('Name cannot be empty.');
		}

		if ($d['public_key'] !== '') {
			try {
				Sealer::checkPublicKey($d['public_key']);
			}
			catch (\InvalidArgumentException $e) {
				$errors[] = _('Encryption public key').': '.$e->getMessage();
			}
		}

		if ($d['private_key_path'] !== '' && $d['private_key_path'][0] !== '/') {
			$errors[] = _('Private key path must be absolute.');
		}

		$required = [
			's3' => ['bucket' => _('Bucket'), 'access_key' => _('Access key')],
			'sftp' => ['host' => _('Host'), 'username' => _('User name')],
			'git' => ['url' => _('Repository URL'), 'branch' => _('Branch')]
		][$d['kind']];

		foreach ($required as $field => $label) {
			if ($d[$field] === '') {
				$errors[] = sprintf(_('%1$s cannot be empty.'), $label);
			}
		}

		if ($errors) {
			CMessageHelper::setErrorTitle(_('Cannot save destination'));
			foreach ($errors as $error) {
				CMessageHelper::addError($error);
			}
			$this->redirectTo('configbackup.destination.edit', $index !== null ? ['id' => $id] : ['kind' => $d['kind']]);

			return;
		}

		// Secrets: blank keeps the stored one, "clear" removes it, anything else is sealed.
		$clear = $this->getInput('clear', []);

		foreach (Settings::SECRET_FIELDS as $field) {
			$value = $this->getInput($field, '');

			if (in_array($field, $clear, true)) {
				$d[$field] = '';
			}
			elseif ($value !== '') {
				$d[$field] = Runtime::seal($this->storage(), $value);
			}
		}

		if ($index !== null) {
			$settings['destinations'][$index] = $d;
		}
		else {
			$settings['destinations'][] = $d;
		}

		$this->saveSettings($settings);
		$this->enqueue(['kind' => 'test', 'destination' => $d['id']]);

		CMessageHelper::setSuccessTitle(sprintf(_('Destination "%1$s" saved. A connection test is queued for the runner.'), $d['name']));
		$this->redirectTo('configbackup.destinations');
	}
}
