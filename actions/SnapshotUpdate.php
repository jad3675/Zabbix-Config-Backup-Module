<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Store;

class SnapshotUpdate extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'snapshotid' => 'required|string',
			'label' => 'string',
			'note' => 'string',
			'pinned' => 'in 0,1'
		]) && Store::isValidId($this->getInput('snapshotid'));

		if (!$ret) {
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction(): void {
		$snapshotid = $this->getInput('snapshotid');

		try {
			$this->store()->updateMeta($snapshotid, [
				'label' => mb_substr(trim($this->getInput('label', '')), 0, 255),
				'note' => mb_substr(trim($this->getInput('note', '')), 0, 4096),
				'pinned' => $this->getInput('pinned', 0) == 1
			]);
			CMessageHelper::setSuccessTitle(_('Snapshot updated'));
		}
		catch (\Throwable $e) {
			CMessageHelper::setErrorTitle(_('Cannot update snapshot'));
			CMessageHelper::addError($e->getMessage());
		}

		$this->redirectTo('configbackup.snapshot', ['snapshotid' => $snapshotid]);
	}
}
