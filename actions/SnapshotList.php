<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Schedule;
use Modules\ConfigBackup\Lib\Settings;
use Modules\ConfigBackup\Lib\Shipper;
use Modules\ConfigBackup\Lib\Types;

class SnapshotList extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['remote' => 'in 0,1']);
	}

	protected function doAction(): void {
		$settings = $this->settings();
		$store = $this->store();
		$problem = $store->check();
		$snapshots = [];
		$warnings = [];
		$runner = $this->runnerStatus();
		$queue = [];
		$recent = [];

		$remote_count = 0;
		$show_remote = $this->getInput('remote', 1) == 1;

		if ($problem === null) {
			foreach ($store->listSnapshots() as $id => $meta) {
				$meta['size'] = $store->size($id);
				$meta['local'] = true;
				$meta['copies'] = [];
				$snapshots[$id] = $meta;
			}

			// One history: what each destination holds (as of its last listing) merged with local snapshots.
			$dstate = Runtime::read($this->storage(), 'destinations');

			foreach ($settings['destinations'] as $d) {
				if ($d['kind'] === 'git') {
					foreach ($snapshots as $id => $meta) {
						if (isset($meta['uploads'][$d['id']])) {
							$snapshots[$id]['copies'][$d['id']] = ['git' => true];
						}
					}

					continue;
				}

				$st = $dstate[$d['id']] ?? [];

				foreach ($st['listing'] ?? [] as $name => $info) {
					$id = Shipper::idFromName((string) $name);

					if ($id === null) {
						continue;
					}

					$encrypted = substr($name, -4) === '.cbk';
					$copy = [
						'name' => $name,
						'size' => (int) $info['size'],
						'encrypted' => $encrypted,
						'pullable' => !$encrypted || $d['private_key_path'] !== '',
						'listed' => $st['listing_time'] ?? null
					];

					if (!isset($snapshots[$id])) {
						if (!$show_remote) {
							$remote_count++;
							continue;
						}

						$cat = $st['catalog'][$name] ?? [];
						$snapshots[$id] = [
							'id' => $id,
							'local' => false,
							'created' => $cat['created'] ?? strtotime(substr($id, 0, 8).'T'.substr($id, 9, 6).'Z'),
							'label' => $cat['label'] ?? '',
							'source' => $cat['source'] ?? '',
							'schedule' => $cat['schedule'] ?? (preg_match(Shipper::NAME, $name, $m) && ($m[2] ?? 'manual') !== 'manual' ? $m[2] : null),
							'objects' => $cat['objects'] ?? null,
							'status' => $cat['status'] ?? null,
							'size' => (int) $info['size'],
							'copies' => []
						];
						$remote_count++;
					}

					$snapshots[$id]['copies'][$d['id']] = $copy;
				}
			}

			uasort($snapshots, static fn($a, $b) => $b['created'] <=> $a['created']);

			$queue = Runtime::pending($this->storage());
			$recent = Runtime::recent($this->storage(), 5);
			$sstate = Runtime::read($this->storage(), 'schedules');
			$enabled = array_filter($settings['schedules'], static fn($s) => $s['enabled']);

			if ($enabled && !$runner['alive']) {
				$warnings[] = isset($runner['last_seen'])
					? sprintf(_('The runner was last seen %1$s ago, so schedules and queued requests are not being processed. Check the zabbix-configbackup.timer.'), zbx_date2age($runner['last_seen']))
					: _('The runner has never run, so schedules and queued requests are not being processed. See Settings for the timer to install.');
			}
			elseif ($runner['alive'] && empty($runner['api_ok'])) {
				$warnings[] = sprintf(_('The runner cannot use the Zabbix API: %1$s'), $runner['api_message'] ?? '?');
			}

			foreach ($enabled as $s) {
				$st = $sstate[$s['id']] ?? [];

				if (isset($st['last_ok']) && !$st['last_ok']) {
					$warnings[] = sprintf(_('Schedule "%1$s" failed %2$s ago: %3$s'), $s['name'],
						zbx_date2age($st['last_run']), $st['last_message']
					);
				}
			}

			$dstate = Runtime::read($this->storage(), 'destinations');
			foreach ($settings['destinations'] as $d) {
				$st = $dstate[$d['id']] ?? [];

				if ($d['enabled'] && isset($st['last_ok']) && !$st['last_ok']) {
					$warnings[] = sprintf(_('Last send to "%1$s" failed: %2$s'), $d['name'], $st['last_message']);
				}
			}

			if (!$enabled && $settings['expect_hours'] > 0) {
				$warnings[] = _('No schedule is enabled, so nothing is backed up unless someone presses Back up now.');
			}
		}

		$next = null;
		foreach ($settings['schedules'] as $s) {
			if ($s['enabled']) {
				$slot = Schedule::nextSlot($s, time(), Settings::timezone($settings));
				$next = $next === null || $slot < $next[0] ? [$slot, $s['name']] : $next;
			}
		}

		$this->setResponse(new CControllerResponseData([
			'title' => _('Config backup'),
			'settings' => $settings,
			'storage_problem' => $problem,
			'snapshots' => $snapshots,
			'show_remote' => $show_remote,
			'process_user' => \Modules\ConfigBackup\Lib\Store::processUser(),
			'remote_count' => $remote_count,
			'warnings' => $warnings,
			'runner' => $runner,
			'next' => $next,
			'timezone' => Settings::timezone($settings),
			'queue' => array_map(static fn($r) => Runtime::describe($r, $settings), $queue),
			'recent' => array_map(static fn($r) => $r + ['text' => Runtime::describe($r, $settings)], $recent),
			'destinations' => array_column($settings['destinations'], 'name', 'id'),
			'manual_destinations' => array_column(array_filter($settings['destinations'],
				static fn($d) => $d['enabled'] && $d['manual']), 'id'
			),
			'type_count' => count(Settings::enabledTypes($settings)),
			'type_total' => count(Types::all()),
			'csrf' => [
				'backup' => CCsrfTokenHelper::get('configbackup.backup'),
				'delete' => CCsrfTokenHelper::get('configbackup.snapshot.delete'),
				'pull' => CCsrfTokenHelper::get('configbackup.pull')
			]
		]));
	}
}
