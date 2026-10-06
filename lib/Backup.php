<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Takes a snapshot of every enabled object type.
 */
class Backup {

	public const MODULE_VERSION = '1.4.0';

	private ApiClient $api;
	private Store $store;

	public function __construct(ApiClient $api, Store $store) {
		$this->api = $api;
		$this->store = $store;
	}

	/**
	 * @param array         $types     type keys to include (empty = all)
	 * @param array         $meta      source, user, label, note
	 * @param callable|null $progress  fn(string $message)
	 *
	 * @return array  snapshot meta
	 */
	public function run(array $types = [], array $meta = [], ?callable $progress = null): array {
		$progress ??= static function (string $message): void {};
		$started = microtime(true);

		$problem = $this->store->check();
		if ($problem !== null) {
			throw new \RuntimeException($problem);
		}

		$lock = $this->store->lock();
		if ($lock === null) {
			throw new \RuntimeException('Another backup is already running.');
		}

		$writer = $this->store->newWriter();
		$counts = [];
		$errors = [];

		try {
			$version = null;
			try {
				$version = $this->api->call('apiinfo.version', []);
			}
			catch (ApiException $e) {
				// Frontend wrapper refuses apiinfo.version with a session; not important.
			}

			foreach (Types::all() as $key => $type) {
				if ($types && !in_array($key, $types, true)) {
					continue;
				}

				$progress(sprintf('%s...', $type['label']));
				$writer->beginType($key);

				try {
					switch ($type['mode']) {
						case Types::MODE_API:
							$this->backupApi($writer, $type);
							break;

						case Types::MODE_SINGLETON:
							$writer->add('0', $type['label'], $this->api->call($type['service'].'.get', $type['get']));
							break;

						case Types::MODE_EXPORT:
							$failed = $this->backupExport($writer, $type, $progress);
							if ($failed) {
								$errors[$key] = $failed;
							}
							break;
					}
				}
				catch (ApiException $e) {
					$errors[$key][] = $e->getMessage();
				}

				$counts[$key] = $writer->endType();
			}

			$meta = [
				'id' => $writer->getId(),
				'created' => time(),
				'source' => $meta['source'] ?? 'manual',
				'user' => $meta['user'] ?? '',
				'label' => $meta['label'] ?? '',
				'schedule' => $meta['schedule'] ?? null,
				'note' => $meta['note'] ?? '',
				'pinned' => !empty($meta['pinned']),
				'zabbix_version' => $version,
				'module_version' => self::MODULE_VERSION,
				'counts' => $counts,
				'errors' => $errors,
				'status' => $errors ? 'partial' : 'ok',
				'duration' => round(microtime(true) - $started, 1)
			];

			$writer->commit($meta);
		}
		catch (\Throwable $e) {
			$writer->abort();
			$this->store->unlock($lock);

			throw $e;
		}

		$this->store->unlock($lock);

		return $meta;
	}

	private function backupApi(SnapshotWriter $writer, array $type): void {
		$objects = $this->api->call($type['service'].'.get', $type['get']);

		usort($objects, static fn(array $a, array $b): int => strnatcasecmp($a[$type['name']], $b[$type['name']]));

		foreach ($objects as $object) {
			$writer->add((string) $object[$type['id']], (string) $object[$type['name']], $object);
		}
	}

	/**
	 * One configuration.export call per object so each object can be restored on its own.
	 *
	 * @return array  per-object error messages
	 */
	private function backupExport(SnapshotWriter $writer, array $type, callable $progress): array {
		$list = $this->api->call($type['service'].'.get', $type['list']);
		$errors = [];

		usort($list, static fn(array $a, array $b): int => strnatcasecmp($a[$type['name']], $b[$type['name']]));

		$total = count($list);

		foreach ($list as $i => $object) {
			$id = (string) $object[$type['id']];
			$name = (string) $object[$type['name']];

			if ($i > 0 && $i % 100 == 0) {
				$progress(sprintf('%s %d/%d', $type['label'], $i, $total));
			}

			try {
				$source = $this->api->call('configuration.export', [
					'format' => 'json',
					'prettyprint' => false,
					'options' => [$type['export_key'] => [$id]]
				]);

				$writer->add($id, $name, json_decode($source, true),
					$type['tech'] !== null ? (string) $object[$type['tech']] : null
				);
			}
			catch (ApiException $e) {
				$errors[] = sprintf('%s: %s', $name, $e->getMessage());
			}
		}

		return $errors;
	}
}
