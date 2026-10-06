<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CMessageHelper;
use Modules\ConfigBackup\Lib\Restorer;
use Modules\ConfigBackup\Lib\Store;
use Modules\ConfigBackup\Lib\Types;

class RestoreRun extends Base {

	private const MODES = [Restorer::MODE_CREATE, Restorer::MODE_OVERWRITE, Restorer::MODE_MISSING,
		Restorer::MODE_EXACT, Restorer::MODE_AUTO, Restorer::MODE_AUTO_PARTS
	];

	private array $items = [];

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'snapshotid' => 'required|string',
			'items' => 'required|array',
			'mode' => 'required|in '.implode(',', self::MODES),
			'new_name' => 'string',
			'back' => 'in snapshot,object',
			'filter_type' => 'string',
			'filter_name' => 'string',
			'filter_state' => 'string'
		]) && Store::isValidId($this->getInput('snapshotid'));

		if ($ret) {
			foreach ($this->getInput('items') as $item) {
				if (!is_string($item) || !preg_match('/^([a-z]+):(\d+)$/', $item, $m) || !Types::exists($m[1])) {
					$ret = false;
					break;
				}

				$this->items[] = ['type' => $m[1], 'id' => $m[2]];
			}
		}

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Nothing to restore'));
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction(): void {
		self::unlimited();

		$snapshotid = $this->getInput('snapshotid');
		$mode = $this->getInput('mode');
		$restorer = new Restorer($this->api(), $this->store(), $snapshotid);

		if (count($this->items) == 1) {
			$results = [$restorer->restore($this->items[0]['type'], $this->items[0]['id'], $mode,
				$this->getInput('new_name', '')
			)];
		}
		else {
			$results = $restorer->restoreMany($this->items, $mode);
		}

		$ok = count(array_filter($results, static fn($r) => $r['ok'] && !$r['skipped']));
		$skipped = count(array_filter($results, static fn($r) => $r['skipped']));
		$failed = count(array_filter($results, static fn($r) => !$r['ok']));

		$title = $failed
			? sprintf(_('Restore finished: %1$d restored, %2$d failed'), $ok, $failed)
			: sprintf(_('Restored %1$d object(s)'), $ok);

		if ($skipped) {
			$title .= sprintf(_(', %1$d skipped because they exist'), $skipped);
		}

		$failed ? CMessageHelper::setErrorTitle($title) : CMessageHelper::setSuccessTitle($title);

		$labels = Types::labels();
		$notes_seen = [];

		foreach ($results as $result) {
			if ($result['skipped'] && count($results) > 20) {
				continue;
			}

			$line = sprintf('%s "%s": %s', $labels[$result['type']], $result['name'], $result['message']);
			$result['ok'] ? CMessageHelper::addSuccess($line) : CMessageHelper::addError($line);

			foreach ($result['notes'] as $note) {
				// Type-wide caveats once per run, per-object notes (passwords) always.
				if (str_starts_with($note, 'Temporary password') || !isset($notes_seen[$note])) {
					CMessageHelper::addWarning($note);
					$notes_seen[$note] = true;
				}
			}
		}

		if ($this->getInput('back', 'snapshot') === 'object' && count($results) == 1) {
			$result = $results[0];
			$this->redirectTo('configbackup.object', [
				'snapshotid' => $snapshotid, 'type' => $result['type'], 'id' => $result['id']
			]);

			return;
		}

		$this->redirectTo('configbackup.snapshot', [
			'snapshotid' => $snapshotid,
			'filter_type' => $this->getInput('filter_type', ''),
			'filter_name' => $this->getInput('filter_name', ''),
			'filter_state' => $this->getInput('filter_state', '')
		]);
	}
}
