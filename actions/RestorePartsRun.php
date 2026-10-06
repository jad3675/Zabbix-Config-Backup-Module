<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Restorer;
use Modules\ConfigBackup\Lib\Store;

/**
 * Restore chosen items, triggers, graphs, discovery rules, web scenarios, value maps, dashboards and macros of
 * one template or host.
 */
class RestorePartsRun extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'snapshotid' => 'required|string',
			'type' => 'required|in template,host',
			'id' => 'required|string',
			'parts' => 'required|array',
			'mode' => 'required|in missing,overwrite'
		]) && Store::isValidId($this->getInput('snapshotid'));

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Select at least one part to restore'));
			$this->redirectTo('configbackup.object', [
				'snapshotid' => $this->getInput('snapshotid', ''), 'type' => $this->getInput('type', ''),
				'id' => $this->getInput('id', '')
			]);
		}

		return $ret;
	}

	protected function doAction(): void {
		self::unlimited();

		$restorer = new Restorer($this->api(), $this->store(), $this->getInput('snapshotid'));
		$keys = array_values(array_filter($this->getInput('parts'), 'is_string'));
		$result = $restorer->restoreParts($this->getInput('type'), $this->getInput('id'), $keys,
			$this->getInput('mode') === 'overwrite'
		);

		if ($result['ok']) {
			CMessageHelper::setSuccessTitle($result['message']);
		}
		else {
			CMessageHelper::setErrorTitle(_('Restore failed'));
			CMessageHelper::addError($result['message']);
		}

		foreach ($result['notes'] as $note) {
			CMessageHelper::addWarning($note);
		}

		$this->redirectTo('configbackup.object', [
			'snapshotid' => $this->getInput('snapshotid'), 'type' => $this->getInput('type'),
			'id' => $this->getInput('id')
		]);
	}
}
