<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ConfigBackup\Lib\Dest\Git;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Sealer;
use Modules\ConfigBackup\Lib\Settings;

class DestinationEdit extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['id' => 'string', 'kind' => 'in s3,sftp,git']);
	}

	protected function doAction(): void {
		$id = $this->getInput('id', '');
		$d = $id !== '' ? Settings::destination($this->settings(), $id) : null;
		$is_new = $d === null;
		$d ??= ['kind' => $this->getInput('kind', 's3')] + Settings::DESTINATION_DEFAULTS;

		// The browser only learns whether a secret is set, never the value.
		$set = [];
		foreach (Settings::SECRET_FIELDS as $field) {
			if (array_key_exists($field, $d)) {
				$set[$field] = $d[$field] !== '';
				$d[$field] = '';
			}
		}

		$state = !$is_new && $this->store()->check() === null
			? (Runtime::read($this->storage(), 'destinations')[$d['id']] ?? [])
			: [];

		$this->setResponse(new CControllerResponseData([
			'title' => $is_new ? _('New destination') : _('Edit destination'),
			'd' => $d,
			'is_new' => $is_new,
			'secret_set' => $set,
			'state' => $state,
			'key_fingerprint' => $d['public_key'] !== '' ? @Sealer::fingerprint($d['public_key']) : null,
			'git_available' => Git::available(),
			'process_user' => \Modules\ConfigBackup\Lib\Store::processUser(),
			'csrf' => CCsrfTokenHelper::get('configbackup.destination.update')
		]));
	}
}
