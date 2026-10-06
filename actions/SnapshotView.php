<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use CPagerHelper;
use Modules\ConfigBackup\Lib\ApiException;
use Modules\ConfigBackup\Lib\Restorer;
use Modules\ConfigBackup\Lib\Store;
use Modules\ConfigBackup\Lib\Types;

class SnapshotView extends Base {

	public const STATES = ['missing', 'recreated', 'changed', 'same', 'present'];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'snapshotid' => 'required|string',
			'filter_type' => 'string',
			'filter_name' => 'string',
			'filter_state' => 'string',
			'page' => 'ge 1'
		]) && Store::isValidId($this->getInput('snapshotid'));

		if (!$ret) {
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction(): void {
		$snapshotid = $this->getInput('snapshotid');
		$store = $this->store();

		if (!$store->exists($snapshotid)) {
			\CMessageHelper::setErrorTitle(_('Snapshot not found'));
			$this->redirectTo('configbackup.list');

			return;
		}

		$meta = $store->getMeta($snapshotid);

		$filter = [
			'type' => $this->getInput('filter_type', ''),
			'name' => trim($this->getInput('filter_name', '')),
			'state' => $this->getInput('filter_state', '')
		];

		if ($filter['type'] !== '' && !Types::exists($filter['type'])) {
			$filter['type'] = '';
		}
		if ($filter['state'] !== '' && !in_array($filter['state'], self::STATES, true)) {
			$filter['state'] = '';
		}

		// One get per type (export types only list IDs and names), so comparing everything is affordable.
		$compare = true;

		$restorer = new Restorer($this->api(), $store, $snapshotid);
		$rows = [];
		$errors = [];
		$summary = [];

		foreach (Types::all() as $key => $type) {
			if (empty($meta['counts'][$key]) || ($filter['type'] !== '' && $filter['type'] !== $key)) {
				continue;
			}

			$states = [];
			if ($compare) {
				try {
					$states = $restorer->states($key);
				}
				catch (ApiException $e) {
					$errors[] = sprintf('%s: %s', $type['label'], $e->getMessage());
				}
			}

			foreach ($store->getIndex($snapshotid, $key) as $entry) {
				$state = $states[$entry['id']] ?? null;

				if ($state !== null) {
					$summary[$state['state']] = ($summary[$state['state']] ?? 0) + 1;
				}

				if ($filter['name'] !== '' && mb_stripos($entry['name'].' '.($entry['tech'] ?? ''), $filter['name']) === false) {
					continue;
				}

				if ($filter['state'] !== '' && ($state === null || $state['state'] !== $filter['state'])) {
					continue;
				}

				$rows[] = [
					'type' => $key,
					'id' => $entry['id'],
					'name' => $entry['name'],
					'tech' => $entry['tech'] ?? null,
					'state' => $state['state'] ?? null,
					'live_id' => $state['live_id'] ?? null
				];
			}
		}

		$page = (int) $this->getInput('page', 1);
		$url = self::url('configbackup.snapshot', [
			'snapshotid' => $snapshotid,
			'filter_type' => $filter['type'],
			'filter_name' => $filter['name'],
			'filter_state' => $filter['state']
		]);
		$total = count($rows);
		$paging = CPagerHelper::paginate($page, $rows, ZBX_SORT_UP, $url);

		$this->setResponse(new CControllerResponseData([
			'title' => _('Config backup'),
			'meta' => $meta,
			'size' => $store->size($snapshotid),
			'filter' => $filter,
			'compare' => $compare,
			'rows' => $rows,
			'total' => $total,
			'summary' => $summary,
			'paging' => $paging,
			'errors' => $errors,
			'remap' => $store->getRemap($snapshotid),
			'destinations_all' => array_column($this->settings()['destinations'], 'name', 'id'),
			'copies' => $this->copies($snapshotid, $meta),
			'destinations' => array_column(array_filter($this->settings()['destinations'], static fn($d) => $d['enabled']),
				'name', 'id'
			),
			'csrf' => [
				'restore' => CCsrfTokenHelper::get('configbackup.restore'),
				'update' => CCsrfTokenHelper::get('configbackup.snapshot.update'),
				'delete' => CCsrfTokenHelper::get('configbackup.snapshot.delete'),
				'queue' => CCsrfTokenHelper::get('configbackup.queue')
			]
		]));
	}

	/**
	 * Where copies of this snapshot are now: what each destination last listed, plus Git commits.
	 */
	private function copies(string $snapshotid, array $meta): array {
		$out = [];
		$state = \Modules\ConfigBackup\Lib\Runtime::read($this->storage(), 'destinations');

		foreach ($this->settings()['destinations'] as $d) {
			if ($d['kind'] === 'git') {
				if (isset($meta['uploads'][$d['id']])) {
					$out[$d['id']] = ['time' => $meta['uploads'][$d['id']]['time'], 'name' => 'git'];
				}

				continue;
			}

			foreach ($state[$d['id']]['listing'] ?? [] as $name => $info) {
				if (\Modules\ConfigBackup\Lib\Shipper::idFromName((string) $name) === $snapshotid) {
					$out[$d['id']] = ['time' => $info['time'], 'name' => $name];
				}
			}
		}

		return $out;
	}
}
