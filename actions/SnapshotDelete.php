<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Store;

class SnapshotDelete extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput(['snapshotids' => 'required|array']);

		if ($ret) {
			foreach ($this->getInput('snapshotids') as $id) {
				if (!is_string($id) || !Store::isValidId($id)) {
					$ret = false;
				}
			}
		}

		if (!$ret) {
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction(): void {
		$store = $this->store();
		$deleted = 0;

		foreach ($this->getInput('snapshotids') as $id) {
			if ($store->exists($id)) {
				$store->delete($id);
				$deleted++;
			}
		}

		CMessageHelper::setSuccessTitle(_n('Snapshot deleted', '%1$d snapshots deleted', $deleted));
		$this->redirectTo('configbackup.list');
	}
}
