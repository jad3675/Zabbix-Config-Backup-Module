<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Backup;
use Modules\ConfigBackup\Lib\Settings;

/**
 * Back up now runs in the request (you want it done before the risky change, not in 5 minutes). Copying it to
 * destinations is handed to the runner, which holds the credentials.
 */
class BackupRun extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput(['label' => 'string', 'note' => 'string', 'send' => 'array']);

		if (!$ret) {
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction(): void {
		self::unlimited();

		$settings = $this->settings();
		$store = $this->store();
		$started = time();

		try {
			$meta = (new Backup($this->api(), $store))->run(Settings::enabledTypes($settings), [
				'source' => 'manual',
				'user' => $this->username(),
				'label' => trim($this->getInput('label', '')),
				'note' => trim($this->getInput('note', ''))
			]);
		}
		catch (\Throwable $e) {
			CMessageHelper::setErrorTitle(_('Backup failed'));
			CMessageHelper::addError($e->getMessage());
			$this->redirectTo('configbackup.list');

			return;
		}

		$deleted = $store->prune($settings['keep_count'], $settings['keep_days'],
			static fn(array $m) => empty($m['schedule'])
		);

		$store->writeLastRun([
			'source' => 'manual', 'time' => $started, 'status' => $meta['status'], 'snapshot' => $meta['id'],
			'objects' => array_sum($meta['counts']), 'duration' => $meta['duration'], 'message' => ''
		]);

		if ($meta['status'] === 'ok') {
			CMessageHelper::setSuccessTitle(sprintf(_('Snapshot taken: %1$d objects in %2$ss'),
				array_sum($meta['counts']), $meta['duration']
			));
		}
		else {
			CMessageHelper::setErrorTitle(_('Snapshot taken, but some objects could not be read'));

			foreach ($meta['errors'] as $messages) {
				foreach (array_slice($messages, 0, 20) as $message) {
					CMessageHelper::addError($message);
				}
			}
		}

		foreach (array_values($this->getInput('send', [])) as $destid) {
			$d = Settings::destination($settings, (string) $destid);

			if ($d !== null) {
				$this->enqueue(['kind' => 'upload', 'snapshotid' => $meta['id'], 'destination' => $d['id']]);
				CMessageHelper::addSuccess(sprintf(_('Queued for %1$s; the runner sends it within 5 minutes.'), $d['name']));
			}
		}

		if ($deleted) {
			CMessageHelper::addSuccess(sprintf(_('Retention removed %1$d old snapshot(s).'), count($deleted)));
		}

		$this->redirectTo('configbackup.snapshot', ['snapshotid' => $meta['id']]);
	}
}
